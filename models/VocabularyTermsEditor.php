<?php

class VocabularyTermsEditor
{
    const ADD_VOCABULARY_TERM = 1;
    const REMOVE_VOCABULARY_TERM = 2;
    const UPDATE_VOCABULARY_TERM = 3;
    const UPDATE_VOCABULARY_LOCAL_TERMS_ORDER = 4;

    protected $db;

    public function __construct()
    {
        $this->db = get_db();
    }

    protected function addTerm()
    {
        // This method is called via AJAX. Get the posed data.
        $itemValues = json_decode($_POST['mapping'], true);

        $localTermRecord = new VocabularyLocalTerms();
        $localTermRecord['id'] = 0;
        $localTermRecord['order'] = 0;
        $localTermRecord['kind'] = isset($_POST['kind']) ? $_POST['kind'] : 0;;
        $localTermRecord['local_term'] = $itemValues['localTerm'];
        $localTermRecord['common_term'] = $itemValues['commonTerm'];

        $newLocalTermRecord = $this->validateCommonTerm($localTermRecord);

        // Add the new term by updating the new record to insert it into the database.
        if (!$newLocalTermRecord->save())
            throw new Exception(__FUNCTION__ . ' save failed');

        // Reorder all of the terms so that this new term is the first.
        $kind = isset($_POST['kind']) ? $_POST['kind'] : 0;
        $localTermRecords = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecordsInOrder($kind);
        foreach ($localTermRecords as $index => $localTermRecord)
        {
            $localTermRecord['order'] = $index + 1;
            if (!$localTermRecord->save())
                throw new Exception(__FUNCTION__ . ' save failed');
        }

        return json_encode(array('success'=>true, 'itemId'=>$newLocalTermRecord->id));
    }

    public static function getUsageCount($vocabularyTermId)
    {
        $count = get_db()->getTable('VocabularyTypes')->getVocabularyTypeCountByTerm($vocabularyTermId);
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

                case VocabularyTermsEditor::UPDATE_VOCABULARY_LOCAL_TERMS_ORDER:
                    return $this->updateTermOrder();

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

        $vocabularyTerms = $this->db->getTable('VocabularyLocalTerms')->find($vocabularyTermId);
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
        // This method is called via AJAX. Get the posted data.
        $itemValues = json_decode($_POST['mapping'], true);
        $id = intval($itemValues['id']);

        // Get the local term record and update it with the posted local and common terms.
        $localTermRecord = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecordById($id);
        $localTermRecord['local_term'] = $itemValues['localTerm'];
        $localTermRecord['common_term'] = $itemValues['commonTerm'];

        try
        {
            $validatedLocalTermRecord = $this->validateCommonTerm($localTermRecord);
            if (!$validatedLocalTermRecord->save())
                throw new Exception(__FUNCTION__ . ' save failed');
            $commonTerm = $validatedLocalTermRecord->common_term;
            $commonTermId = $validatedLocalTermRecord->common_term_id;
            $success = true;
            $error = '';
        }
        catch (Exception $e)
        {
            $commonTermId = 0;
            $commonTerm = '';
            $success = false;
            $error = $e->getMessage();
        }

        return json_encode(array('success'=>$success, 'common_term'=>$commonTerm, 'common_term_id'=>$commonTermId, 'error'=>$error));
    }

    protected function updateTermOrder()
    {
        $order = isset($_POST['order']) ? $_POST['order'] : '';
        foreach ($order as $index => $id)
        {
            $localTermRecord = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecordById($id);
            $localTermRecord['order'] = $index + 1;
            if (!$localTermRecord->save())
                throw new Exception(__FUNCTION__ . ' save failed');
        }

        return json_encode(array('success'=>true));
    }

    public function validateCommonTerm($localTermRecord)
    {
        $kind = $localTermRecord->kind;
        $commonTerm = $localTermRecord->common_term;

        $oldCommonTermId = $localTermRecord->common_term_id;
        $newCommonTermId = $oldCommonTermId;

        if ($commonTerm)
        {
            $commonTermRecord = $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTerm($kind, $commonTerm);

            if ($commonTermRecord)
            {
                $newCommonTermId = $commonTermRecord->common_term_id;
            }
            else
            {
                // The text for this common term must have been changed in the Common Term Vocabulary.
                // Get the current text based on the common term's Id.
                $commonTermRecord = $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTermId($oldCommonTermId);

                if ($commonTermRecord)
                {
                    $localTermRecord['common_term'] = $commonTermRecord->common_term;
                }
                else
                {
                    // This should never happen because the common term can only come from the common term chooser.
                    throw new Exception("\"$commonTerm\" is not a Common Term");
                }
            }
        }
        else
        {
            $newCommonTermId = 0;
        }

        $localTermRecord['common_term_id'] = $newCommonTermId;

        return $localTermRecord;
    }
}
