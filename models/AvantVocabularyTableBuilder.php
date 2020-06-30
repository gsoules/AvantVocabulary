<?php

class AvantVocabularyTableBuilder
{
    protected $db;

    public function __construct()
    {
        $this->db = get_db();
    }

    protected function buildCommonTermsTable()
    {
        VocabularyTableFactory::dropVocabularyCommonTermsTable();
        VocabularyTableFactory::createVocabularyCommonTermsTable();

        $rows = $this->readDataRowsFromRemoteCsvFile(AvantVocabulary::vocabulary_terms_url());

        foreach ($rows as $row)
        {
            $kind = $row[0];
            $id = intval($row[1]);
            $term = $row[2];

            $this->databaseInsertRecordForCommonTerm($kind, $id, $term);
        }
    }

    protected function buildLocalTermsTable()
    {
        VocabularyTableFactory::dropVocabularyLocalTermsTable();
        VocabularyTableFactory::createVocabularyLocalTermsTable();

        $fields = AvantVocabulary::getVocabularyFields();
        foreach ($fields as $elementName => $kind)
        {
            $this->createLocalTerms($elementName, $kind);
        }
    }

    protected function createLocalTerms($elementName, $kind)
    {
        // Get the set of unique text values for this element.
        $elementId = ItemMetadata::getElementIdForElementName($elementName);
        $localTerms = $this->fetchUniqueLocalTerms($elementId);

        foreach ($localTerms as $index => $term)
        {
            // Add the term to the local terms table.
            $localTerm = $term['text'];
            $this->databaseInsertRecordForLocalTerm($kind, $localTerm, $index + 1);
        }
    }

    protected function databaseInsertRecordForCommonTerm($kind, $id, $term)
    {
        $commonTermRecord = new VocabularyCommonTerms();
        $commonTermRecord['kind'] = $kind;
        $commonTermRecord['common_term_id'] = $id;
        $commonTermRecord['common_term'] = $term;

        if (!$commonTermRecord->save())
            throw new Exception($this->reportError('Save failed', __FUNCTION__, __LINE__));

        return $commonTermRecord;
    }

    protected function databaseInsertRecordForLocalTerm($kind, $localTerm, $order)
    {
        $localTermRecord = new VocabularyLocalTerms();
        $localTermRecord['order'] = $order;
        $localTermRecord['kind'] = $kind;

        $commonTermRecord = $this->getCommonTermRecord($kind, $localTerm);
        if ($commonTermRecord)
        {
            $localTermRecord['local_term'] = '';
            $localTermRecord['common_term_id'] = $commonTermRecord->common_term_id;
        }
        else
        {
            $localTermRecord['local_term'] = $localTerm;
            $localTermRecord['common_term_id'] = 0;
        }

        if (!$localTermRecord->save())
            throw new Exception($this->reportError('Save failed', __FUNCTION__, __LINE__));
    }

    protected function databaseRemoveCommonTerm($commonTermRecord)
    {
        try
        {
            $commonTermRecord->delete();
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();
            throw new Exception($this->reportError('Delete failed: ' . $message, __FUNCTION__, __LINE__));
        }
    }

    protected function fetchUniqueLocalTerms($elementId)
    {
        try
        {
            $table = "{$this->db->prefix}element_texts";

            $sql = "
                SELECT
                  DISTINCT text
                FROM
                  $table
                WHERE
                  record_type = 'Item' AND element_id = $elementId
                ORDER BY
                  text  
            ";

            $results = $this->db->query($sql)->fetchAll();
        }
        catch (Exception $e)
        {
            $results = null;
        }

        return $results;
    }

    protected function getCommonTermRecord($kind, $commonTerm)
    {
        return $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTerm($kind, $commonTerm);
    }

    protected function getCommonTermRecordByKindAndCommonTermId($kind, $commonTermId)
    {
        return $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByKindAndCommonTermId($kind, $commonTermId);
    }

    public function handleAjaxRequest($tableName)
    {
        // This method is called in response to Ajax requests from the client. For more information, see the comments
        // for this same method in AvantElasticSearchIndexBuilder.

        $success = true;
        $error = '';

        try
        {
            switch ($tableName)
            {
                case 'common':
                    $this->buildCommonTermsTable();
                    break;

                 case 'local':
                    $this->buildLocalTermsTable();
                    break;

                default:
                    $success = false;
                    $error = 'Unexpected table name: ' . $tableName;
            }
        }
        catch (Exception $e)
        {
            $success = false;
            $error = $e->getMessage();
        }

        $response = json_encode(array('success'=>$success, 'error'=>$error));
        echo $response;
    }

    protected function convertLocalTermToNewCommonTerm($commonTermKind, $term, $commonTermId)
    {
        // See if a local term exists that has the same name as a new common term.
        $localTermRecords = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecordsByLocalTerm($term);

        foreach ($localTermRecords as $localTermRecord)
        {
            $localTermKind = $localTermRecord->kind;

            // See if the local term's kind is compatible with the common term's kind.
            $compatible = (
                $commonTermKind == $localTermKind ||
                AvantVocabulary::kindIsTypeOrSubject($localTermKind) && $commonTermKind == AvantVocabulary::KIND_TYPE_OR_SUBJECT);
            if (!$compatible)
                continue;

            // A local term with the common term's text exists. If it is mapped to a common term, leave the mapping
            // alone even though that violates the rule that a common term cannot be used as a local term. That's better
            // however, than trashing the existing mapping without the site administrator being made aware of the change.
            $mapped = $localTermRecord['common_term_id'] > 0;
            if ($mapped)
                continue;

            // Update the local term record to use the common term.
            $localTermRecord['common_term_id'] = $commonTermId;
            $localTermRecord['local_term'] = '';
            if (!$localTermRecord->save())
                throw new Exception($this->reportError('Save local term failed', __FUNCTION__, __LINE__));
        }
    }

    protected function convertLocalTermsToUnmapped($commonTermId)
    {
        // Get all the local records that use the common term.
        $localTermRecords = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecordsByCommonTermId($commonTermId);

        if ($localTermRecords)
        {
            // Get the common term text.
            $commonTermRecord = $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTermId($commonTermId);
            $commonTerm = $commonTermRecord->common_term;

            // Change each local term record to be unmapped (has a local term that is not mapped to a common term).
            foreach ($localTermRecords as $localTermRecord)
            {
                $localTermRecord['local_term'] = $commonTerm;
                $localTermRecord['common_term_id'] = 0;
                if (!$localTermRecord->save())
                    throw new Exception($this->reportError('Save local term failed', __FUNCTION__, __LINE__));
            }
        }

    }

    protected function fetchElementTextsHavingTerm($elementId, $term)
    {
        $term = AvantCommon::escapeQuotes($term);

        try
        {
            $table = "{$this->db->prefix}element_texts";

            $sql = "
                SELECT
                  id,
                  record_id
                FROM
                  $table
                WHERE
                  element_id = $elementId AND text = '$term'
            ";

            $results = $this->db->query($sql)->fetchAll();
        }
        catch (Exception $e)
        {
            $results = array();
        }

        return $results;
    }

    public function refreshCommonTerms()
    {
        $rows = $this->readDataRowsFromRemoteCsvFile(AvantVocabulary::vocabulary_diff_url());

        foreach ($rows as $row)
        {
            $action = $row[0];
            $kind = $row[1];
            $id = intval($row[2]);
            $oldTerm = $row[3];
            $newTerm = $row[4];

            $this->refreshCommonTerm($action, $kind, $id, $oldTerm, $newTerm);
        }

        $count = count($rows);
        return ($count == 1 ? '1 term' : "$count terms") . ' refreshed';
    }

    protected function readDataRowsFromRemoteCsvFile($url)
    {
        $response = AvantAdmin::requestRemoteAsset($url);
        if ($response['response-code'] != 200)
            throw new Exception("Could not read $url");

        // Break the response into individual rows from the CSV file.
        $rawRows = explode("\n", $response['result']);

        $rows = array();
        foreach ($rawRows as $index => $rawRow)
        {
            // Skip the header row;
            if ($index == 0)
                continue;

            $row = str_getcsv($rawRow);
            if (!empty($row[0]))
                $rows[] = $row;
        }

        return $rows;
    }

    protected function refreshCommonTerm($action, $termKind, $commonTermId, $oldTerm, $newTerm)
    {
        // This method gets called when a common term has been added, deleted, or updated. It is called once for each
        // action in a diff file. If the same diff file gets processed more than once, or if a previous action was
        // equivalent to a subsequent action (e.g. the action was for both a Type and Subject), some parts of some
        // actions will get skipped (e.g. a common term that was added for Type won't get added again for Subject).
        switch ($action)
        {
            case 'ADD':
                $commonTermRecord = $this->getCommonTermRecordByKindAndCommonTermId($termKind, $commonTermId);
                if (!$commonTermRecord)
                    $commonTermRecord = $this->databaseInsertRecordForCommonTerm($termKind, $commonTermId, $newTerm);
                break;

            case 'DELETE':
                $commonTermRecord = $this->getCommonTermRecordByKindAndCommonTermId($termKind, $commonTermId);
                if ($commonTermRecord)
                {
                    $this->convertLocalTermsToUnmapped($commonTermId);
                    $this->databaseRemoveCommonTerm($commonTermRecord);
                }
                break;

            case 'UPDATE':
                // The common term's text has changed. Get the common term's record from the database.
                $commonTermRecord = $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTermId($commonTermId);
                if (!$commonTermRecord)
                    throw new Exception($this->reportError('Get common term record failed', __FUNCTION__, __LINE__));

                if ($commonTermRecord->common_term != $newTerm)
                {
                    // Update the common term's record with the new text.
                    $commonTermRecord['common_term'] = $newTerm;
                    if (!$commonTermRecord->save())
                        throw new Exception($this->reportError('Save common term failed',  __FUNCTION__, __LINE__));
                }
                break;

            default:
                throw new Exception($this->reportError('Unsupported action ' . $action, __FUNCTION__, __LINE__));
        }

        if ($action == 'ADD' || $action == 'UPDATE')
        {
            // See if the added or updated common term is the same as a local term, and if so, make the local term common.
            if ($action == 'ADD' && $termKind == AvantVocabulary::KIND_TYPE_OR_SUBJECT)
            {
                $this->convertLocalTermToNewCommonTerm(AvantVocabulary::KIND_TYPE, $newTerm, $commonTermRecord->common_term_id);
                $this->convertLocalTermToNewCommonTerm(AvantVocabulary::KIND_SUBJECT, $newTerm, $commonTermRecord->common_term_id);
            }
            else
            {
                $this->convertLocalTermToNewCommonTerm($termKind, $newTerm, $commonTermRecord->common_term_id);
            }
        }

        // Update items affect by the actions above.
        if ($action == 'ADD' && $termKind == AvantVocabulary::KIND_TYPE_OR_SUBJECT)
        {
            $this->refreshItems($action, AvantVocabulary::KIND_TYPE, $commonTermId, $oldTerm, $newTerm);
            $this->refreshItems($action, AvantVocabulary::KIND_SUBJECT, $commonTermId, $oldTerm, $newTerm);
        }
        else
        {
            $this->refreshItems($action, $termKind, $commonTermId, $oldTerm, $newTerm);
        }
    }

    protected function refreshItems($action, $kind, $commonTermId, $oldTerm, $newTerm)
    {
        $itemIds = array();

        // Get the element Id corresponding to the term's kind.
        $elementId = array_search($kind, AvantVocabulary::getVocabularyKinds());

        // Query the database to get all element texts that use the term.
        $elementTexts = $this->fetchElementTextsHavingTerm($elementId, $action == 'ADD' ? $newTerm : $oldTerm);

        // Examine the results. Each contains the element text's Id and the Id of the element's item.
        foreach ($elementTexts as $elementText)
        {
            if ($action == 'UPDATE')
            {
                // Update the element texts to replace the old term with the new term. There's no need to do
                // this when a term is added or deleted since those changes don't alter the element text.
                $this->updateElementTexts($elementText['id'], $newTerm);
            }

            // Remember the Id of the item that the element text belongs to.
            $itemIds[] = $elementText['record_id'];
        }

        if ($action == 'UPDATE')
        {
            // Get all local terms that are mapped to the common term. For example, the local terms "Birds, Songbirds"
            // and "Birds, Raptors" could be mapped to the common term "Nature, Animals, Birds".
            $localTermRecords = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecordsByCommonTermId($commonTermId);

            foreach ($localTermRecords as $localTermRecord)
            {
                // Query the database to get all elements that use this local mapped term.
                $elementTexts = $this->fetchElementTextsHavingTerm($elementId, $localTermRecord->local_term);

                // Examine the results. Each contains the element text's Id and the Id of the element's item.
                foreach ($elementTexts as $elementText)
                {
                    // Remember the Id of the item that the element text belongs to. The item will need to get updated
                    // in the local and shared indexes to reflect the change to the common term. For example, if the
                    // common term changed from "Nature, Animals, Birds" to "Nature, Animals, Tweeters", every item's
                    // local/common mapping needs to be updated to reflect the change.
                    $itemIds[] = $elementText['record_id'];
                }
            }
        }

        // Refresh every unique item that is affected by the addition, deletion, or update of the common term.
        $itemIds = array_unique($itemIds);
        foreach ($itemIds as $itemId)
        {
            $this->updateItemIndexes($itemId);
        }
    }

    private function reportError($message, $function, $line)
    {
        return ("$message: $function on line $line");
    }

    protected function updateElementTexts($elementTextsId, $text)
    {
        $sql = "UPDATE `{$this->db->ElementTexts}` SET text = '$text' WHERE id = $elementTextsId";
        $this->db->query($sql);
    }

    protected function updateItemIndexes($itemId)
    {
        $item = ItemMetadata::getItemFromId($itemId);

        $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
        $sharedIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_SHARE) == true;
        $localIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_LOCAL) == true;

        $avantElasticsearch = new AvantElasticsearch();
        $avantElasticsearch->updateIndexForItem($item, $avantElasticsearchIndexBuilder, $sharedIndexIsEnabled, $localIndexIsEnabled);
    }
}