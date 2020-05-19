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

        // Check if there is a common term that's the sames as the local term.
        $commonTermExists = $this->db->getTable('VocabularyCommonTerms')->commonTermExists($kind, $localTerm);
        if ($commonTermExists)
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
        $success = true;
        $elementId = ItemMetadata::getElementIdForElementName($elementName);

        // Get the set of unique text values for this elements.
        $results = $this->fetchUniqueLocalTerms($elementId);

        foreach ($results as $result)
        {
            $localTerm = $result['text'];

            // Check if the value is already in the table.
            $localTermRecord = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecord($kind, $localTerm);

            if ($localTermRecord)
            {
                // Then make sure it matches the existing common value.
                $commonTerm = $localTermRecord->common_term;
                if ($commonTerm)
                {
                    $commonTermExists = $this->db->getTable('VocabularyCommonTerms')->commonTermExists($kind, $commonTerm);
                    if (!$commonTermExists)
                    {
                        // The table is out of date. Change this local term to not mapped.
                        $localTermRecord->mapping = AvantVocabulary::VOCABULARY_MAPPING_NONE;
                        $localTermRecord->common_term = null;
                        $success = $localTermRecord->save();
                        if (!$success)
                            break;
                    }
                }
            }
            else
            {
                // Add the value as a local term.
                $localTermRecord = $this->addRecordForLocalTerm($kind, $localTerm);
                $success = $localTermRecord->save();
                if (!$success)
                    break;
            }
        }
        return $success;
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
                    //$success = $this->rebuildCommonTermsTable();
                    $success = $this->rebuildLocalTermsTable();
                    if (!$success)
                        $response = 'REBUILD FAILED';
                    else
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
        $success = true;

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
                break;
        }

        return $success;
    }

    protected function rebuildLocalTermsTable()
    {
//        VocabularyTableFactory::dropVocabularyLocalTermsTable();
//        VocabularyTableFactory::createVocabularyLocalTermsTable();

        $success = $this->createLocalTerms('Type', AvantVocabulary::VOCABULARY_TERM_KIND_TYPE);
        if (!$success)
            return false;

        $success = $this->createLocalTerms('Subject', AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT);
        if (!$success)
            return false;

        $success = $this->createLocalTerms('Place', AvantVocabulary::VOCABULARY_TERM_KIND_PLACE);
        if (!$success)
            return false;

        return false;
    }
}