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

        return json_encode(array('success'=>true, 'id'=>$localTermRecord->id, 'commonTermId'=>$commonTermId));
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

    protected function updateAndReindexItems($itemValues, $oldTerm, $newTerm)
    {
        // Update every Omeka item that uses the old.
        $elementId = $itemValues['elementId'];
        $elementTexts = $this->getElementTextsThatUseTerm($elementId, $oldTerm);

        if (empty($elementTexts))
            return;

        // The index builder gets created here so that the expense it incurs to create vocabulary tables in only
        // incurred once for all of the items that will get reindexed.
        $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
        $sharedIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_SHARE) == true;
        $localIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_LOCAL) == true;

        // Keep track of how many items have been updated.
        $total = count($elementTexts);
        $completed = 0;
        $progressFileName = AvantVocabulary::progressFileName();

        foreach ($elementTexts as $elementText)
        {
            $elementTextId = $elementText['id'];

            // Get the ElementText record for the term.
            $select = $this->db->select()
                ->from($this->db->ElementText)
                ->where("id = $elementTextId");
            $elementTextRecord = $this->db->getTable('ElementText')->fetchObject($select);
            if (!$elementTextRecord)
                throw new Exception($this->reportError(__FUNCTION__, ' get element text record failed'));

            // Update the ElementText record with the new term.
            $elementTextRecord['text'] = $newTerm;
            if (!$elementTextRecord->save())
                throw new Exception($this->reportError(__FUNCTION__, ' save element text record failed'));

            // Reindex the item by saving it as though the user had just edited the item and clicked the Save button.
            $itemId = $elementText['record_id'];
            $item = ItemMetadata::getItemFromId($itemId);

            $avantElasticsearch = new AvantElasticsearch();
            $avantElasticsearch->updateIndexForItem($item, $avantElasticsearchIndexBuilder, $sharedIndexIsEnabled, $localIndexIsEnabled);

            // Write the progress to a file that can be read by the Ajax progress reporting logic.
            $completed += 1;
            $progress = round($completed / $total * 100, 0);
            file_put_contents($progressFileName, "$progress%");
        }

        // Delete the progress file.
        unlink($progressFileName);
    }

    protected function updateTerm()
    {
        // This method is called via AJAX. Get the posted data.
        $itemValues = json_decode($_POST['itemValues'], true);
        $id = intval($itemValues['id']);
        $kind = $itemValues['kind'];

        // Get the local term record and update it with the posted local and common terms.
        $localTermRecord = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecordById($id);
        if (!$localTermRecord)
            throw new Exception($this->reportError(__FUNCTION__, ' get local term record failed'));

        $oldLocalTerm = $localTermRecord->local_term;
        $newLocalTerm = $itemValues['localTerm'];

        if (strtolower($newLocalTerm) != strtolower($oldLocalTerm))
        {
            // Check to see if the new local term already exists.
            $localTermRecord = $this->db->getTable('VocabularyLocalTerms')->getLocalTermRecord($kind, $newLocalTerm);
            if ($localTermRecord)
            {
                return json_encode(array('success'=>false, 'error'=>'local-term-exists'));
            }
        }

        $oldCommonTermId = $localTermRecord->common_term_id;
        $newCommonTerm = $itemValues['commonTerm'];
        $newCommonTermId = $newCommonTerm ? $this->getIdForCommonTerm($kind, $newCommonTerm) : 0;

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
            $newTerm = $newCommonTerm;
        }

        // Update the local term record with the new data.
        $localTermRecord['local_term'] = $newLocalTerm;
        $localTermRecord['common_term_id'] = $newCommonTermId;
        if (!$localTermRecord->save())
            throw new Exception($this->reportError(__FUNCTION__, ' save failed'));

        // Update the Elasticsearch indexes with the new data.
        $this->updateAndReindexItems($itemValues, $oldTerm, $newTerm);

        return json_encode(array('success'=>true, 'commonTermId'=>$newCommonTermId));
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
