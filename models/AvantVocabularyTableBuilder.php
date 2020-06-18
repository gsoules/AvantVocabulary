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
    }

    protected function databaseInsertRecordForLocalTerm($kind, $localTerm, $order)
    {
        $localTermRecord = new VocabularyLocalTerms();
        $localTermRecord['order'] = $order;
        $localTermRecord['kind'] = $kind;
        $localTermRecord['local_term'] = $localTerm;

        // Check if the local term is identical to a common term.
        $commonTermRecord = $this->getCommonTermRecord($kind, $localTerm);
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

    protected function refreshCommonTerm($termKind, $termId, $oldTerm, $newTerm)
    {
        // Update the common terms table with updated terms.
        $commonTermRecord = $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTermId($termId);
        if (!$commonTermRecord)
            throw new Exception($this->reportError('Get record failed', __FUNCTION__, __LINE__));
        $commonTermRecord['common_term'] = $newTerm;
        if (!$commonTermRecord->save())
            throw new Exception($this->reportError('Save common term failed',  __FUNCTION__, __LINE__));

        // If the common term is now the same as a local term, add the common term Id to the local term table.
        $localTermRecord = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecord($termKind, $newTerm);
        if ($localTermRecord)
        {
            $localTermRecord['common_term_id'] = $commonTermRecord->common_term_id;
            if (!$localTermRecord->save())
                throw new Exception($this->reportError('Save local term failed',  __FUNCTION__, __LINE__));
        }

        // Fetch all element texts that use one of the changed terms as a Type, Subject, or Place.
        // From the element texts we also get a list of the items that those texts belong to.
        $elementTextsIds = array();
        $itemIds = array();
        $kinds = AvantVocabulary::getVocabularyKinds();
        foreach ($kinds as $elementId => $kind)
        {
            $isTypeOrSubject = AvantVocabulary::kindIsTypeOrSubject($kind);
            if ($kind == $termKind || ($isTypeOrSubject && $termKind == AvantVocabulary::VOCABULARY_TERM_KIND_TYPE_AND_SUBJECT))
            {
                $results = $this->fetchElementTextsHavingTerm($elementId, $oldTerm);
                foreach ($results as $result)
                {
                    $elementTextsIds[] = $result['id'];
                    $itemId = $result['record_id'];
                    if (!in_array($itemId, $itemIds))
                        $itemIds[] = $result['record_id'];
                }
            }
        }

        // Update the element texts for those items.
        foreach ($elementTextsIds as $elementTextsId)
        {
            $this->updateElementTexts($elementTextsId, $newTerm);
        }

        // Update the local and shared indexes for just those items.
        foreach ($itemIds as $itemId)
        {
            $this->updateItemIndexes($itemId);
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

        return count($rows) . " terms updated";
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