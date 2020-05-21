<?php

class VocabularyTermsEditor
{
    const ADD_VOCABULARY_TERM = 1;
    const REMOVE_VOCABULARY_TERM = 2;
    const UPDATE_VOCABULARY_TERM = 3;

    public static function addDefaultTerm($description, $term)
    {
        $vocabularyTerms = new VocabularyTerms();
        $vocabularyTerms['description'] = $description;
        $vocabularyTerms['term'] = $term;
        $vocabularyTerms->save();
        return $vocabularyTerms['id'];
    }

    protected function addTerm()
    {
        $vocabularyTerms = $this->getVocabularyTerms();
        $success = $vocabularyTerms->save();
        $termId = $success ? $vocabularyTerms->id : 0;
        return json_encode(array('success' => $success, 'itemId' => $termId));
    }

    public function getVocabularyTerms()
    {
        $term = isset($_POST['term']) ? $_POST['term'] : '';
        $object = json_decode($term, true);

        $vocabularyTerms = new VocabularyTerms();
        $vocabularyTerms['id'] = isset($object['id']) ? intval($object['id']) : null;
        $vocabularyTerms['description'] = $object['description'];
        $vocabularyTerms['term'] = $object['term'];

        return $vocabularyTerms;
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
        $vocabularyTerms = $this->getVocabularyTerms();
        $success = $vocabularyTerms->save();
        return json_encode(array('success' => $success));
    }
}
