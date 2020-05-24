<?php

class VocabularyTermsEditor
{
    const ADD_VOCABULARY_TERM = 1;
    const REMOVE_VOCABULARY_TERM = 2;
    const UPDATE_VOCABULARY_TERM = 3;

    public static function addDefaultTerm($description, $term)
    {
        $localTermRecord = new VocabularyLocalTerms();
        $localTermRecord['description'] = $description;
        $localTermRecord['term'] = $term;
        $localTermRecord->save();
        return $localTermRecord['id'];
    }

    protected function addTerm()
    {
        $vocabularyTerms = $this->getVocabularyTermMapping(self::ADD_VOCABULARY_TERM);
        if (!$vocabularyTerms->save())
            throw new Exception(__FUNCTION__ . ' save failed');
        $termId = $vocabularyTerms->id;
        return json_encode(array('success' => true, 'itemId' => $termId));
    }

    public function getVocabularyTermMapping($action)
    {
        $data = isset($_POST['mapping']) ? $_POST['mapping'] : '';
        $object = json_decode($data, true);
        $id = isset($object['id']) ? intval($object['id']) : 0;

        $localTerm = $object['localTerm'];
        $commonTerm = $object['commonTerm'];

        if ($action == self::ADD_VOCABULARY_TERM)
        {
            $kind = isset($_POST['kind']) ? $_POST['kind'] : 0;
            $localTermRecord = new VocabularyLocalTerms();
            $localTermRecord['local_term'] = $localTerm;
            $localTermRecord['common_term'] = $commonTerm;
        }
        else
        {
            $localTermRecord = get_db()->getTable('VocabularyLocalTerms')->getLocalTermRecordById($id);
            $kind = $localTermRecord->kind;
        }

        // The common term has changed. Verify that it's valid.
        if (empty(trim($commonTerm)))
        {
            $commonTermId = 0;
        }
        else
        {
            $commonTermRecord = get_db()->getTable('VocabularyCommonTerms')->getCommonTermRecord($kind, $commonTerm);
            if ($commonTermRecord)
                $commonTermId = $commonTermRecord->common_term_id;
            else
                throw new Exception("\"$commonTerm\" is not a Common Term");
        }

        if ($commonTerm == $localTerm)
            $mapping = AvantVocabulary::VOCABULARY_MAPPING_IDENTICAL;
        elseif ($commonTermId && $commonTerm != $localTerm)
            $mapping = AvantVocabulary::VOCABULARY_MAPPING_SYNONYMOUS;
        else
            $mapping = AvantVocabulary::VOCABULARY_MAPPING_NONE;

        $updatedLocalTermRecord = new VocabularyLocalTerms();
        $updatedLocalTermRecord['id'] = $id;
        $updatedLocalTermRecord['kind'] = $kind;
        $updatedLocalTermRecord['local_term'] = $localTerm;
        $updatedLocalTermRecord['mapping'] = $mapping;
        $updatedLocalTermRecord['common_term'] = $commonTerm;
        $updatedLocalTermRecord['common_term_id'] = $commonTermId;

        return $updatedLocalTermRecord;
    }

    public static function getUsageCount($vocabularyTermId)
    {
        $db = get_db();
        $count = $db->getTable('VocabularyTypes')->getVocabularyTypeCountByTerm($vocabularyTermId);
        return $count;
    }

    public function performAction($action)
    {
        try
        {
            switch ($action)
            {
                case VocabularyTermsEditor::ADD_VOCABULARY_TERM:
                    return $this->addTerm();

                case VocabularyTermsEditor::REMOVE_VOCABULARY_TERM:
                    return $this->removeTerm();

                case VocabularyTermsEditor::UPDATE_VOCABULARY_TERM:
                    return $this->updateTerm();

                default:
                    return false;
            }
        }
        catch (Exception $e)
        {
            return false;
        }
    }

    protected function removeTerm()
    {
        $vocabularyTermId = isset($_POST['id']) ? $_POST['id'] : '';

        $db = get_db();
        $vocabularyTerms = $db->getTable('VocabularyTerms')->find($vocabularyTermId);
        $success = false;
        if (self::getUsageCount($vocabularyTermId) == 0 && $vocabularyTerms)
        {
            $vocabularyTerms->delete();
            $success = true;
        }

        return json_encode(array('success' => $success));
    }

    protected function updateTerm()
    {
        $error = '';
        $mapping = '';
        $commonTermId = 0;
        try
        {
            $vocabularyTerms = $this->getVocabularyTermMapping(self::UPDATE_VOCABULARY_TERM);
            $success = $vocabularyTerms->save();
            $mapping = $vocabularyTerms->mapping;
            $commonTermId = $vocabularyTerms->common_term_id;
        }
        catch (Exception $e)
        {
            $error = $e->getMessage();
            $success = false;
        }

        return json_encode(array('success'=>$success, 'mapping'=>$mapping, 'common_term_id'=>$commonTermId, 'error'=>$error));
    }
}
