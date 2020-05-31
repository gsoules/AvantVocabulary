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
            return json_encode(array('success'=>false, 'id'=>0));
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

        return json_encode(array('success'=>true, 'id'=>$localTermRecord->id));
    }

    protected function getElementTextsThatUseTerm($elementId, $oldTerm)
    {
        $oldTerm = addslashes($oldTerm);

        try
        {
            $table = "{$this->db->prefix}element_texts";

            $sql = "
                SELECT
                  id, record_id
                FROM
                  $table
                WHERE
                  record_type = 'Item' AND element_id = $elementId AND text = '$oldTerm'
            ";

            $results = $this->db->query($sql)->fetchAll();
        }
        catch (Exception $e)
        {
            throw $e;
        }

        return $results;
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
        $localTerm = addslashes($localTerm);

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

    protected function updateAndReindexItemUsingNewTerm($elementText, $newTerm)
    {
        $elementTextId = $elementText['id'];

        $select = $this->db->select()
            ->from($this->db->ElementText)
            ->where("id = $elementTextId");

        $elementTextRecord = $this->db->getTable('ElementText')->fetchObject($select);
        if (!$elementTextRecord)
            throw new Exception($this->reportError(__FUNCTION__, ' get element text record failed'));

        // Update the element text record with the new term.
        $elementTextRecord['text'] = $newTerm;
        if (!$elementTextRecord->save())
            throw new Exception($this->reportError(__FUNCTION__, ' save element text record failed'));

        // Reindex the item by saving it as though the user had just edited the item and clicked the Save button.
        $itemId = $elementText['record_id'];
        $item = ItemMetadata::getItemFromId($itemId);
        $args['record'] = $item;
        $args['insert'] = false;
        $avantElasticsearch = new AvantElasticsearch();
        $avantElasticsearch->afterSaveItem($args);
    }

    protected function updateTerm()
    {
        // This method is called via AJAX. Get the posted data.
        $itemValues = json_decode($_POST['itemValues'], true);
        $id = intval($itemValues['id']);

        // Get the local term record and update it with the posted local and common terms.
        $localTermRecord = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecordById($id);
        if (!$localTermRecord)
            throw new Exception($this->reportError(__FUNCTION__, ' get local term record failed'));

        $newLocalTerm = $itemValues['localTerm'];
        $newCommonTermId = $itemValues['commonTermId'];
        $oldLocalTerm = $localTermRecord->local_term;
        $oldCommonTermId = $localTermRecord->common_term_id;

        // Determine the old term, before the update.
        if ($oldLocalTerm)
        {
            $oldTerm = $oldLocalTerm;
        }
        else
        {
            $oldCommonTermRecord = $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTermId($oldCommonTermId);
            if (!$oldCommonTermRecord)
                throw new Exception($this->reportError(__FUNCTION__, ' get old common term record failed'));
            $oldTerm = $oldCommonTermRecord->common_term;
        }

        // Determine the new term, after the update.
        if ($newLocalTerm)
        {
            $newTerm = $newLocalTerm;
        }
        else
        {
            $newCommonTermRecord = $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTermId($newCommonTermId);
            if (!$newCommonTermRecord)
                throw new Exception($this->reportError(__FUNCTION__, ' get new common term record failed'));
            $newTerm = $newCommonTermRecord->common_term;
        }

        $localTermRecord['local_term'] = $newLocalTerm;
        $localTermRecord['common_term_id'] = $newCommonTermId;

        try
        {
            if (!$localTermRecord->save())
                throw new Exception($this->reportError(__FUNCTION__, ' save failed'));

            // Update every Omeka item that uses this term.
            $elementId = $itemValues['elementId'];
            $elementTexts = $this->getElementTextsThatUseTerm($elementId, $oldTerm);
            foreach ($elementTexts as $elementText)
            {
                $this->updateAndReindexItemUsingNewTerm($elementText, $newTerm);
            }

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
