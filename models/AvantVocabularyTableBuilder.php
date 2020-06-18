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

        $url = 'https://digitalarchive.us/vocabulary/nomenclature.csv';
        $rows = $this->readDataRowsFromRemoteCsvFile($url);

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
        $localTermRecord['local_term'] = $localTerm;

        // Check if the local term is identical to a common term.
        $commonTermRecord = $this->getCommonTermRecord($localTerm);
        if ($commonTermRecord)
        {
            // Add the common term info to the local term record.
            $localTermRecord['common_term_id'] = $commonTermRecord->common_term_id;
        }
        else
        {
            // The local term is not the same as any common term.
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

    protected function getCommonTermRecord($commonTerm)
    {
        return $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTerm($commonTerm);
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

    protected function convertLocalTermToCommonTerm($termKind, $term, $commonTermRecord)
    {
        // See if the local term is mapped to a common term.
        $localTermRecord = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecord($termKind, $term);

        if ($localTermRecord)
        {
            // A local term with the common term's text exists. If it is mapped to a common term, we'll leave the
            // mapping alone even though that violates the rule that a common term cannot be used as a local term.
            // That's better however, than trashing the existing mapping without the site administrator being made
            // aware of the change. The violation will be highlighted in the Vocabulary Editor.
            $mapped = $localTermRecord['common_term_id'] > 0;

            if (!$mapped)
            {
                // Set the local term record's common term Id to indicate that the local and common terms are the same.
                $localTermRecord['common_term_id'] = $commonTermRecord->common_term_id;
                if (!$localTermRecord->save())
                {
                    throw new Exception($this->reportError('Save local term failed', __FUNCTION__, __LINE__));
                }
            }
        }
    }

    protected function convertLocalTermToUnmapped($commonTermId)
    {
        // Get all the local records that use the common term Id.
        $localTermRecords = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecordsByCommonTermId($commonTermId);

        foreach ($localTermRecords as $localTermRecord)
        {
            $localTermRecord['common_term_id'] = 0;
            if (!$localTermRecord->save())
            {
                throw new Exception($this->reportError('Save local term failed', __FUNCTION__, __LINE__));
            }
        }
    }

    protected function fetchElementTextsHavingTerm($elementId, $commonTerm)
    {
        $commonTerm = addslashes($commonTerm);

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
                  element_id = $elementId AND text = '$commonTerm'
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
        $url = 'https://digitalarchive.us/vocabulary/changes.csv';
        $rows = $this->readDataRowsFromRemoteCsvFile($url);

        foreach ($rows as $row)
        {
            $kind = $row[0];
            $id = intval($row[1]);
            $oldTerm = $row[2];
            $newTerm = $row[3];

            $this->refreshCommonTerm($kind, $id, $oldTerm, $newTerm);
        }

        return count($rows) . " terms refreshed";
    }

    protected function readDataRowsFromRemoteCsvFile($url)
    {
        $response = AvantAdmin::requestRemoteAsset($url);
        if ($response['response-code'] != 200)
            throw new Exception("Could not read $url");

        // Break the response into individual rows from the CSV file.
        $rawRows = explode("\r\n", $response['result']);

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

    protected function refreshCommonTerm($termKind, $commonTermId, $oldTerm, $newTerm)
    {
        // This method gets called when the definition of a common term has been changed, deleted, or added.

        if ($newTerm == 'DELETED')
        {
            $change = 'DELETE';
            // A common term has been deleted. This has no affect on element texts that use the term because they
            // will retain the term's text. However, local terms that are mapped to the deleted term must be unmapped
            $this->convertLocalTermToUnmapped($commonTermId);

            // Remove the term from the common terms table.
            $this->databaseRemoveCommonTerm($commonTermId);
        }
        elseif ($oldTerm == 'ADDED')
        {
            $change = 'ADD';
            // A common term has been added. This has no affect on element texts because no text has changed.
            // Add the new term to the common terms table so that it's available to use, but first see if it
            // already exists. This should not happen in practice, but it could if the same change CSV file
            // gets submitted more than once such that the common term got added on a previous submission.
            $commonTermRecord = $this->getCommonTermRecordByCommonTermId($commonTermId);
            if (!$commonTermRecord)
                $commonTermRecord = $this->databaseInsertRecordForCommonTerm($termKind, $commonTermId, $newTerm);

            // See if the new common term is the same as a local term, and if so, make the local term common.
            $this->convertLocalTermToCommonTerm($termKind, $newTerm, $commonTermRecord);
        }
        else
        {
            $change = 'EDIT';
            // The text of an existing term changed. Get the common term's record from the database.
            $commonTermRecord = $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTermId($commonTermId);
            if (!$commonTermRecord)
                throw new Exception($this->reportError('Get common term record failed', __FUNCTION__, __LINE__));

            // Update the common term's record with the new text.
            $commonTermRecord['common_term'] = $newTerm;
            if (!$commonTermRecord->save())
                throw new Exception($this->reportError('Save common term failed',  __FUNCTION__, __LINE__));

            // See if the new common term is the same as a local term, and if so, make the local term common.
            $this->convertLocalTermToCommonTerm($termKind, $newTerm, $commonTermRecord);
        }

        // Update items affect by the change.
        $this->refreshItemsUsingCommonTerm($termKind, $oldTerm, $newTerm, $change);
    }

    protected function refreshItemsUsingCommonTerm($termKind, $oldTerm, $newTerm, $change)
    {
        // This method updates all element texts and items that are affected by a change to a common term.
        // Since the same term can be used for multiple kinds (e.g. both Type and Subject), refresh each kind.
        $kinds = AvantVocabulary::getVocabularyKinds();
        foreach ($kinds as $elementId => $kind)
        {
            // Determine if the term's kind matches the loop kind. For example, if the term kind is Place and
            // the loop kind is the Place, that's a match. If the term kind matches the Type and Subject kind,
            // and the loop kind is either of those, then that's a match.
            $loopKindIsTypeOrSubject = AvantVocabulary::kindIsTypeOrSubject($kind);
            $termKindMatchesTypeAndSubject = $termKind == AvantVocabulary::VOCABULARY_TERM_KIND_TYPE_AND_SUBJECT;
            $matchingKind = $kind == $termKind || $loopKindIsTypeOrSubject && $termKindMatchesTypeAndSubject;

            // These arrays keep track of element texts and items that are affected by the change.
            $elementTextsIds = array();
            $itemIds = array();

            if ($matchingKind)
            {
                // Query the database to get all element texts of the term's kind that use the term.
                $term = $change == 'ADD' ? $newTerm : $oldTerm;
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
            }
        }

        // Update the element texts to replace the old term with the new term. There's no need to do
        // this when a term is added or deleted since those changes don't alter the element text.
        if ($change = 'EDIT')
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