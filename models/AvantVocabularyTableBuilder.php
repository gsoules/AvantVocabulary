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

        $nomenclatureFileName = VOCABULARY_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'nomenclature.csv';

        $handle = fopen($nomenclatureFileName, 'r');
        $rowNumber = 0;
        while (($row = fgetcsv($handle, 0, ',')) !== FALSE)
        {
            // Skip the header row;
            $rowNumber += 1;
            if ($rowNumber == 1 || empty($row[0]))
                continue;

            $this->databaseInsertRecordForCommonTerm($row);
        }
    }

    protected function buildLocalTermsTable()
    {
        VocabularyTableFactory::dropVocabularyLocalTermsTable();
        VocabularyTableFactory::createVocabularyLocalTermsTable();

        $this->createLocalTerms('Type', AvantVocabulary::VOCABULARY_TERM_KIND_TYPE);
        $this->createLocalTerms('Subject', AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT);
        $this->createLocalTerms('Place', AvantVocabulary::VOCABULARY_TERM_KIND_PLACE);
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

    protected function databaseInsertRecordForCommonTerm($csvFileRow)
    {
        $commonTermRecord = new VocabularyCommonTerms();
        $commonTermRecord['kind'] = $csvFileRow[0];
        $commonTermRecord['leaf_term'] = $csvFileRow[3];
        $commonTermRecord['common_term'] = $csvFileRow[2];
        $commonTermRecord['common_term_id'] = intval($csvFileRow[1]);

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
            $localTermRecord['common_term'] = $localTerm;
            $localTermRecord['common_term_id'] = $commonTermRecord->common_term_id;
            $localTermRecord['mapping'] = AvantVocabulary::VOCABULARY_MAPPING_IDENTICAL;
        }
        else
        {
            // The local term is not the same as any common term.
            $localTermRecord['common_term_id'] = 0;
            $localTermRecord['mapping'] = AvantVocabulary::VOCABULARY_MAPPING_NONE;
        }

        if (!$localTermRecord->save())
            throw new Exception($this->reportError('Save failed', __FUNCTION__, __LINE__));
    }

    protected function databaseUpdateRecordForLocalTerm($localTermRecord)
    {
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

    private function reportError($message, $function, $line)
    {
        return ("$message: $function on line $line");
    }
}