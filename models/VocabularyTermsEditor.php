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
        $itemValues = json_decode($_POST['itemValues'], true);
        $kind = isset($_POST['kind']) ? $_POST['kind'] : 0;
        $localTerm = trim($itemValues['localTerm']);
        $commonTerm = $itemValues['commonTerm'];

        // Check to see if the term already exists.
        $term = $localTerm ? $localTerm : $commonTerm;
        $localTermRecord = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecord($kind, $term);
        if ($localTermRecord)
        {
            return json_encode(array('success'=>false, 'itemId'=>0));
        }

        $commonTermId = $this->getIdForCommonTerm($kind, $commonTerm);

        $localTermRecord = new VocabularyLocalTerms();
        $localTermRecord['id'] = 0;
        $localTermRecord['order'] = 0;
        $localTermRecord['kind'] = $kind;
        $localTermRecord['local_term'] = $localTerm;
        $localTermRecord['common_term_id'] = $commonTermId;

        // Add the new term by updating the new record to insert it into the database.
        if (!$localTermRecord->save())
            throw new Exception($this->reportError(__FUNCTION__, ' save failed'));

        // Reorder all of the terms so that this new term is the first.
        $kind = isset($_POST['kind']) ? $_POST['kind'] : 0;
        $localTermRecords = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecordsInOrder($kind);
        foreach ($localTermRecords as $index => $localTermRecord)
        {
            $localTermRecord['order'] = $index + 1;
            if (!$localTermRecord->save())
                throw new Exception($this->reportError(__FUNCTION__, ' save failed'));
        }

        return json_encode(array('success'=>true, 'itemId'=>$localTermRecord->id));
    }

    protected function getIdForCommonTerm($kind, $commonTerm)
    {
        $commonTermId = 0;
        if ($commonTerm)
        {
            // Get the Id for the common term.
            $commonTermRecord = $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTerm($kind, $commonTerm);
            if (!$commonTermRecord)
                throw new Exception($this->reportError(__FUNCTION__, ' get common term record failed'));
            $commonTermId = $commonTermRecord->common_term_id;
        }
        return $commonTermId;
    }

    public function getLocalTermUsageCount($elementId, $localTerm)
    {
        try
        {
            $table = "{$this->db->prefix}element_texts";

            $sql = "
                SELECT
                  COUNT(*)
                FROM
                  $table
                WHERE
                  element_id = $elementId AND text = '$localTerm'
            ";

            $count = $this->db->fetchOne($sql);
        }
        catch (Exception $e)
        {
            $count = -1;
        }

        return $count;
    }

    public function performAction($action)
    {
        $error = '';

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
                    $error = 'Unexpected action: ' . $action;
            }
        }
        catch (Exception $e)
        {
            $error = $e->getMessage();
        }

        return json_encode(array('success'=>false, 'error'=>$error));
    }

    protected function removeTerm()
    {
        $itemValues = json_decode($_POST['itemValues'], true);

        $localTermRecord = $this->db->getTable('VocabularyLocalTerms')->find($itemValues['id']);
        if (!$localTermRecord)
            throw new Exception($this->reportError(__FUNCTION__, 'find failed'));

        $success = false;
        $elementId = $itemValues['elementId'];

        // Verify that the term is not in use just in case another user saved an Omeka item using
        // the term while our user was attempting to remove it.
        $term = $itemValues['localTerm'] ? $itemValues['localTerm'] : $itemValues['commonTerm'];
        if ($this->getLocalTermUsageCount($elementId, $term) == 0)
        {
            $localTermRecord->delete();
            $success = true;
        }

        return json_encode(array('success' => $success));
    }

    protected function reportError($methodName, $error)
    {
        return "Exception in method $methodName(): $error";
    }

    protected function updateTerm()
    {
        // This method is called via AJAX. Get the posted data.
        $itemValues = json_decode($_POST['itemValues'], true);
        $id = intval($itemValues['id']);

        // Get the local term record and update it with the posted local and common terms.
        $localTermRecord = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecordById($id);
        $localTermRecord['local_term'] = $itemValues['localTerm'];

        $commonTermId = $this->getIdForCommonTerm($itemValues['kind'], $itemValues['commonTerm']);

        $localTermRecord['common_term_id'] = $commonTermId;

        try
        {
            if (!$localTermRecord->save())
                throw new Exception($this->reportError(__FUNCTION__, ' save failed'));
            $success = true;
            $error = '';
        }
        catch (Exception $e)
        {
            $success = false;
            $error = $e->getMessage();
        }

        return json_encode(array('success'=>$success, 'error'=>$error));
    }

    protected function updateTermOrder()
    {
        $order = isset($_POST['order']) ? $_POST['order'] : '';
        foreach ($order as $index => $id)
        {
            $localTermRecord = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecordById($id);
            $localTermRecord['order'] = $index + 1;
            if (!$localTermRecord->save())
                throw new Exception($this->reportError(__FUNCTION__, ' save failed'));
        }

        return json_encode(array('success'=>true));
    }
}
