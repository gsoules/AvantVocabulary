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
        $vocabularyTerms = $this->getVocabularyTermMapping();
        $success = $vocabularyTerms->save();
        $termId = $success ? $vocabularyTerms->id : 0;
        return json_encode(array('success' => $success, 'itemId' => $termId));
    }

    public function getVocabularyTermMapping()
    {
        $data = isset($_POST['mapping']) ? $_POST['mapping'] : '';
        $object = json_decode($data, true);
        $id = isset($object['id']) ? intval($object['id']) : 0;

        $originalLocalTermRecord = get_db()->getTable('VocabularyLocalTerms')->getLocalTermRecordById($id);

        $kind = $originalLocalTermRecord->kind;
        $localTerm = $object['localTerm'];
        $commonTerm = $object['commonTerm'];
        $commonTermId = $originalLocalTermRecord->common_term_id;

        if ($originalLocalTermRecord->common_term != $commonTerm)
        {
            $commonTermRecord = get_db()->getTable('VocabularyCommonTerms')->getCommonTermRecord($kind, $commonTerm);
            if ($commonTermRecord)
                $commonTermId = $commonTermRecord->common_term_id;
            else
            {
                // How to report this error?
            }
        }

        if ($commonTerm == $localTerm)
            $mapping = AvantVocabulary::VOCABULARY_MAPPING_IDENTICAL;
        elseif ($commonTerm && $commonTerm != $localTerm)
            $mapping = AvantVocabulary::VOCABULARY_MAPPING_SYNONYMOUS;
        else
        {
            $mapping = AvantVocabulary::VOCABULARY_MAPPING_NONE;
            $commonTermId = 0;
        }

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
        $vocabularyTerms = $this->getVocabularyTermMapping();
        $success = $vocabularyTerms->save();
        return json_encode(array('success' => $success, 'mapping' => $vocabularyTerms->mapping));
    }
}
