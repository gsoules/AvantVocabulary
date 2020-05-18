<?php

class AvantVocabularyTableBuilder
{
    public function createCommonTerm($csvFileRow)
    {
        $commonTerm = new VocabularyCommonTerms();
        $commonTerm['kind'] = $csvFileRow[0];
        $commonTerm['identifier'] = intval($csvFileRow[1]);
        $commonTerm['common_term'] = $csvFileRow[2];
        $commonTerm['leaf_term'] = $csvFileRow[3];

        return $commonTerm;
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
                    $this->rebuildTables();
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
        $db = get_db();
        $count = $db->getTable('VocabularyCommonTerms')->getRowCount();
        return "$count records written";
    }

    protected function rebuildTables()
    {
        // Delete the rows in the tables
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
}