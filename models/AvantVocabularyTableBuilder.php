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

        // Check if the local term is identical to a common term.
        $commonTermRecord = $this->getCommonTermRecord($kind, $localTerm);
        if ($commonTermRecord)
        {
            // Add the common term info to the local term record.
            $localTermRecord['common_term'] = $localTerm;
            $localTermRecord['common_term_id'] = $commonTermRecord->common_term_id;
            $localTermRecord['mapping'] = AvantVocabulary::VOCABULARY_MAPPING_LOCAL_IDENTICAL_TO_COMMON;
        }
        else
        {
            // The local term is not the same as any common term.
            $localTermRecord['common_term_id'] = 0;
            $localTermRecord['mapping'] = AvantVocabulary::VOCABULARY_MAPPING_NONE;
        }

        $success = $localTermRecord->save();
        return $success;
    }

    protected function createCommonTerm($csvFileRow)
    {
        $commonTerm = new VocabularyCommonTerms();
        $commonTerm['kind'] = $csvFileRow[0];
        $commonTerm['leaf_term'] = $csvFileRow[3];
        $commonTerm['common_term'] = $csvFileRow[2];
        $commonTerm['common_term_id'] = intval($csvFileRow[1]);

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
            // Check if this local term is already in the local terms table.
            $localTerm = $result['text'];
            $localTermRecord = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecord($kind, $localTerm);

            $commonTermRecord = null;

            if ($localTermRecord)
            {
                // The local term is already in the local terms table. Assess its common term mapping.
                $mappingChanged = false;

                if ($localTermRecord->mapping == AvantVocabulary::VOCABULARY_MAPPING_NONE)
                {
                    // The local term is not mapped to a common term. See if a common term now exists.
                    $commonTermRecord = $this->getCommonTermRecord($kind, $localTerm);
                    if ($commonTermRecord)
                    {
                        // There is now a common term that is identical to this local term.
                        $localTermRecord->common_term = $localTerm;
                        $localTermRecord->common_term_id = $commonTermRecord->common_term_id;
                        $localTermRecord->mapping = AvantVocabulary::VOCABULARY_MAPPING_LOCAL_IDENTICAL_TO_COMMON;
                        $mappingChanged = true;
                    }
                }
                else
                {
                    // The local term is mapped to a common term. Verify that the common term still exists.
                    $commonTermRecord = $this->getCommonTermRecord($kind, $localTermRecord->common_term);
                    if (!$commonTermRecord)
                    {
                        // There is no longer a common term that matches this local term's common term.
                        // See if the there is now a common term that matches the local term.
                        $commonTermRecord = $this->getCommonTermRecord($kind, $localTerm);
                        if ($commonTermRecord)
                        {
                            // There is now a common term that is identical to this local term.
                            $localTermRecord->common_term = $localTerm;
                            $localTermRecord->common_term_id = $commonTermRecord->common_term_id;
                            $localTermRecord->mapping = AvantVocabulary::VOCABULARY_MAPPING_LOCAL_IDENTICAL_TO_COMMON;
                            $mappingChanged = true;
                        }
                        else
                        {
                            // Neither the local or common term match a common term. Set this local term to unmapped.
                            $localTermRecord->common_term = null;
                            $localTermRecord->common_term_id = 0;
                            $localTermRecord->mapping = AvantVocabulary::VOCABULARY_MAPPING_NONE;
                            $mappingChanged = true;
                        }
                    }
                }

                if ($mappingChanged)
                {
                    // The local term's mapping has changed. Update its record in the local terms table.
                    // This can happen when the common terms table gets updated and some of the terms have changed such
                    // that they now match a local term, or no longer match a local term's mapping to a common term.
                    $success = $localTermRecord->save();
                    if (!$success)
                        break;
                }
                continue;
            }
            else
            {
                // The local term does not exist in the local terms table. Add it.
                $success = $this->addRecordForLocalTerm($kind, $localTerm);
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

    protected function getCommonTermRecord($kind, $commonTerm)
    {
        return $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecord($kind, $commonTerm);
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