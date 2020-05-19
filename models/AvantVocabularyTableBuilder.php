<?php

class AvantVocabularyTableBuilder
{
    protected $db;

    public function __construct()
    {
        $this->db = get_db();
    }

    protected function addRecordForLocalTerm($kind, $localTerm)
    {
        $localTermRecord = new VocabularyLocalTerms();
        $localTermRecord['kind'] = $kind;
        $localTermRecord['local_term'] = $localTerm;

        $commonTerm = $this->db->getTable('VocabularyCommonTerms')->commonTermExists($kind, $localTerm);
        if ($commonTerm)
        {
            $localTermRecord['common_term'] = $localTerm;
            $localTermRecord['mapping'] = AvantVocabulary::VOCABULARY_MAPPING_LOCAL_SAME_AS_COMMON;
        }
        else
        {
            $localTermRecord['mapping'] = AvantVocabulary::VOCABULARY_MAPPING_NONE;
        }

        return $localTermRecord;
    }

    protected function createCommonTerm($csvFileRow)
    {
        $commonTerm = new VocabularyCommonTerms();
        $commonTerm['kind'] = $csvFileRow[0];
        $commonTerm['identifier'] = intval($csvFileRow[1]);
        $commonTerm['common_term'] = $csvFileRow[2];
        $commonTerm['leaf_term'] = $csvFileRow[3];

        return $commonTerm;
    }

    protected function createLocalTerms($elementName, $kind)
    {
        $elementId = ItemMetadata::getElementIdForElementName($elementName);

        // Get the set of unique text values for this elements.
        $results = $this->fetchUniqueLocalTerms($elementId);

        // Add each value as a local term.
        foreach ($results as $result)
        {
            $text = $result['text'];
            $localTerm = $this->addRecordForLocalTerm($kind, $text);
            $success = $localTerm->save();

            if (!$success)
            {
                break;
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
            ";

            $results = $this->db->query($sql)->fetchAll();
        }
        catch (Exception $e)
        {
            $itemFieldTexts = array();
        }

        return $results;
    }

    public function handleAjaxRequest()
    {
        // This method is called in response to Ajax requests from the client. For more information, see the comments
        // for this same method in AvantElasticSearchIndexBuilder.

        $action = isset($_POST['action']) ? $_POST['action'] : 'NO ACTION PROVIDED';
        $buildAction = false;

        try
        {
            switch ($action)
            {
                case 'rebuild':
                    //$this->rebuildCommonTermsTable();
                    $this->rebuildLocalTermsTable();
                    $buildAction = true;
                    break;

                case 'progress':
                    $response = $this->getProgress();
                    break;

                default:
                    $response = 'Unexpected action: ' . $action;
            }
        }
        catch (Exception $e)
        {
            $buildAction = false;
            $response = $e->getMessage();
        }

        if ($buildAction)
        {
            $response = "Rebuild completed.<br/>" . $this->getProgress();
        }

        $response = json_encode($response);
        echo $response;
    }

    protected function getProgress()
    {
        $count = $this->db->getTable('VocabularyCommonTerms')->getRowCount();
        return "$count records written";
    }

    protected function rebuildCommonTermsTable()
    {
        VocabularyTableFactory::dropVocabularyCommonTermsTable();
        VocabularyTableFactory::createVocabularyCommonTermsTable();

        // Read the vocabulary CSV file
        $handle = fopen("C:\Users\gsoules\Dropbox\Python\Python-Common-Facets\data\output-nomenclature-sortEn_2020-05-18.csv", 'r');
        $rowNumber = 0;
        while (($row = fgetcsv($handle, 0, ',')) !== FALSE)
        {
            // Skip the header row;
            $rowNumber += 1;
            if ($rowNumber == 1 || empty($row[0]))
                continue;

            $commonTerm = $this->createCommonTerm($row);
            $success = $commonTerm->save();

            if (!$success)
            {
                break;
            }
        }
    }

    protected function rebuildLocalTermsTable()
    {
        VocabularyTableFactory::dropVocabularyLocalTermsTable();
        VocabularyTableFactory::createVocabularyLocalTermsTable();

        $this->createLocalTerms('Type', AvantVocabulary::VOCABULARY_TERM_KIND_TYPE);
        $this->createLocalTerms('Subject', AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT);
        $this->createLocalTerms('Place', AvantVocabulary::VOCABULARY_TERM_KIND_PLACE);
    }
}