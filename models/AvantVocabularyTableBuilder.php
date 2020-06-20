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

    protected function databaseRemoveCommonTerm($commonTermId)
    {
        $commonTermRecord = $this->getCommonTermRecordByCommonTermId($commonTermId);
        if (!$commonTermRecord)
        {
            // This should not happen in practice, but it could if the same change CSV file gets submitted
            // more than once such that the common term got deleted on a previous submission.
            return;
        }
        else
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
    }

    protected function fetchUniqueLocalTerms($elementId)
    {
        $results = array();

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

    protected function getCommonTermRecordByCommonTermId($commonTermId)
    {
        return $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTermId($commonTermId);
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
                AvantVocabulary::kindIsTypeOrSubject($localTermKind) && $commonTermKind == AvantVocabulary::VOCABULARY_TERM_KIND_TYPE_AND_SUBJECT);
            if (!$compatible)
                continue;

            // A local term with the common term's text exists. If it is mapped to a common term, we'll leave the
            // mapping alone even though that violates the rule that a common term cannot be used as a local term.
            // That's better however, than trashing the existing mapping without the site administrator being made
            // aware of the change. The violation will be highlighted in the Vocabulary Editor.
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

    protected function convertLocalTermToUnmapped($commonTermId)
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

        return count($rows) . " terms refreshed";
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
        // This method gets called when a common term has been added, deleted, or updated.
        // It is called once for each action in a diff file.
        switch ($action)
        {
            case 'ADD':
                // Add the new term to the common terms table, but first see if it's already in the table. This can
                // happen if the term kind is for both Type and Subject in which case, the diff file will contain an
                // ADD action for each kind. It can also happen if the diff file is processed more than once.
                $commonTermRecord = $this->getCommonTermRecordByCommonTermId($commonTermId);
                if (!$commonTermRecord)
                    $commonTermRecord = $this->databaseInsertRecordForCommonTerm($termKind, $commonTermId, $newTerm);
                break;

            case 'DELETE':
                // A common term has been deleted. Unmap any local terms that are mapped to it.
                $this->convertLocalTermToUnmapped($commonTermId);

                // Remove the term from the common terms table.
                $this->databaseRemoveCommonTerm($commonTermId);
                break;

            case 'UPDATE':
                // The common term's text has changed. Get the common term's record from the database.
                $commonTermRecord = $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTermId($commonTermId);
                if (!$commonTermRecord)
                    throw new Exception($this->reportError('Get common term record failed', __FUNCTION__, __LINE__));

                // Update the common term's record with the new text unless it has already been changed.
                // This can happen for the UPDATE action the same as explained for the ADD action in the comment above.
                if ($commonTermRecord['common_term'] != $newTerm)
                {
                    $commonTermRecord['common_term'] = $newTerm;
                    if (!$commonTermRecord->save())
                        throw new Exception($this->reportError('Save common term failed',  __FUNCTION__, __LINE__));
                }
                break;
        }

        if ($action == 'ADD' || $action == 'UPDATE')
        {
            // See if the added or updated common term is the same as a local term, and if so, make the local term common.
            $this->convertLocalTermToNewCommonTerm($termKind, $newTerm, $commonTermRecord->common_term_id);
        }

        // Update items affect by the actions above.
        $this->refreshItems($action, $termKind, $oldTerm, $newTerm);
    }

    protected function refreshItems($action, $kind, $oldTerm, $newTerm)
    {
        // This method updates all element texts and items that are affected by a change to a common term.

        // These arrays keep track of element texts and items that are affected by the change.
        $elementTextsIds = array();
        $itemIds = array();

        // Get the element Id corresponding to the term's kind.
        $kinds = AvantVocabulary::getVocabularyKinds();
        $elementId = array_search($kind, $kinds);

        // Query the database to get all element texts that use the term.
        $term = $action == 'ADD' ? $newTerm : $oldTerm;
        $results = $this->fetchElementTextsHavingTerm($elementId, $term);

        // Examine the results. Each contains the element text's Id and the Id of the element's item.
        foreach ($results as $result)
        {
            // Add this element text's Id to the list of affected element texts.
            $elementTextsIds[] = $result['id'];

            // Add the element text's item to the list of affected items.
            $itemId = $result['record_id'];
            if (!in_array($itemId, $itemIds))
            {
                $itemIds[] = $result['record_id'];
            }
        }

        // Update the element texts to replace the old term with the new term. There's no need to do
        // this when a term is added or deleted since those changes don't alter the element text.
        if ($action == 'UPDATE')
        {
            foreach ($elementTextsIds as $elementTextsId)
            {
                $this->updateElementTexts($elementTextsId, $newTerm);
            }
        }

        // Update the local and shared indexes for affected items so that the indexes will contain the new term.
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