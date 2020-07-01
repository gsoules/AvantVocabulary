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
        $localTerm = AvantVocabulary::normalizeLocalTerm($itemValues['localTerm']);
        $commonTerm = $itemValues['commonTerm'];

        // Check to see if the term already exists.
        $term = $localTerm ? $localTerm : $commonTerm;
        if ($this->db->getTable('VocabularyLocalTerms')->localTermExists($kind, $term))
        {
            return json_encode(array('success'=>false, 'id'=>0, 'error'=>'local-term-exists'));
        }

        $commonTermId = $this->getIdForCommonTerm($kind, $commonTerm);

        // Determine if the local term is a common term.
        $commonTermIdForLocalTerm = $this->getIdForCommonTerm($kind, $localTerm);
        if ($commonTermIdForLocalTerm)
        {
            if ($commonTermId)
            {
                // Report an error that the local term is a common term and there is already a common term.
                return json_encode(array('success'=>false, 'error'=>'local-term-is-common-term'));
            }
            else
            {
                // Use the local term for the common term.
                $commonTerm = $localTerm;
                $localTerm = '';
                $commonTermId = $commonTermIdForLocalTerm;
            }
        }

        $newLocalTermRecord = new VocabularyLocalTerms();
        $newLocalTermRecord['order'] = 0;
        $newLocalTermRecord['kind'] = $kind;
        $newLocalTermRecord['local_term'] = $localTerm;
        $newLocalTermRecord['common_term_id'] = $commonTermId;

        // Determine if the local term now exactly matches another. This can happen if the local term
        // is a common term and the record has no common term, but another record has that same common
        // term and no local term.
        $duplicateLocalTermRecord = $this->db->getTable('VocabularyLocalTerms')->getDuplicateLocalTermRecord($newLocalTermRecord);
        if ($duplicateLocalTermRecord)
        {
            return json_encode(array('success'=>false, 'id'=>0, 'error'=>'local-term-exists'));
        }

        // Add the new term by updating the new record to insert it into the database.
        if (!$newLocalTermRecord->save())
            throw new Exception($this->reportError(__FUNCTION__, ' save failed'));

        return json_encode(array('success'=>true, 'id'=>$newLocalTermRecord->id, 'localTerm'=>$localTerm, 'commonTerm'=>$commonTerm, 'commonTermId'=>$commonTermId));
    }

    protected function getElementTextsThatUseTerm($elementId, $oldTerm)
    {
        $oldTerm = AvantCommon::escapeQuotes($oldTerm);

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
            if ($commonTermRecord)
                $commonTermId = $commonTermRecord->common_term_id;
        }
        return $commonTermId;
    }

    public function getLocalTermUsageCount($elementId, $localTerm)
    {
        $localTerm = AvantCommon::escapeQuotes($localTerm);

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
        $newLocalTerm = AvantVocabulary::normalizeLocalTerm($itemValues['localTerm']);

        // Check if the local term has changed.
        $newLocalTermAlreadyExists = false;
        if ($newLocalTerm && $newLocalTerm != $oldLocalTerm)
        {
            // Check if the new local term already exists.
            if ($this->db->getTable('VocabularyLocalTerms')->localTermExists($kind, $newLocalTerm))
            {
                $newLocalTermAlreadyExists = true;
            }
        }

        $oldCommonTermId = $localTermRecord->common_term_id;
        $newCommonTerm = $itemValues['commonTerm'];
        $newCommonTermId = $newCommonTerm ? $this->getIdForCommonTerm($kind, $newCommonTerm) : 0;

        // Determine if the local term is a common term.
        $commonTermIdForLocalTerm = $this->getIdForCommonTerm($kind, $newLocalTerm);
        if ($commonTermIdForLocalTerm)
        {
            if ($newCommonTermId)
            {
                // Report an error that the local term is a common term and there is already a common term.
                return json_encode(array('success'=>false, 'error'=>'local-term-is-common-term'));
            }
            else
            {
                // Use the local term for the common term.
                $newCommonTerm = $itemValues['localTerm'];
                $newLocalTerm = '';
                $newCommonTermId = $commonTermIdForLocalTerm;
            }
        }

        // Determine the old term, before the update.
        if ($oldLocalTerm)
        {
            $oldElementText = $oldLocalTerm;
        }
        else
        {
            $oldCommonTermRecord = $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTermId($oldCommonTermId);
            if (!$oldCommonTermRecord)
                throw new Exception($this->reportError(__FUNCTION__, ' get old common term record failed'));
            $oldElementText = $oldCommonTermRecord->common_term;
        }

        // Determine the new term, after the update.
        if ($newLocalTerm)
        {
            $newElementText = $newLocalTerm;
        }
        else
        {
            $newElementText = $newCommonTerm;
        }

        // Update the local term record with the new data.
        $localTermRecord['local_term'] = $newLocalTerm;
        $localTermRecord['common_term_id'] = $newCommonTermId;

        // Determine if the local term now exactly matches another.
        $duplicateLocalTermRecord = $this->db->getTable('VocabularyLocalTerms')->getDuplicateLocalTermRecord($localTermRecord);
        if ($duplicateLocalTermRecord)
        {
            // Delete the duplicate term. Return its Id so the Vocabulary Editor Javascript knows to merge the two terms.
            $duplicateId = $duplicateLocalTermRecord->id;
            $duplicateLocalTermRecord->delete();
        }
        elseif ($newLocalTermAlreadyExists)
        {
            // The local term has the same name as an existing local term, but the terms don't
            // match (the common term is different). Report that using the same term is not allowed.
            return json_encode(array('success'=>false, 'error'=>'local-term-exists'));
        }
        else
        {
            $duplicateId = 0;
        }

        // Update the local record with the new information.
        if (!$localTermRecord->save())
            throw new Exception($this->reportError(__FUNCTION__, ' save failed'));

        // Update the Elasticsearch indexes with the new data.
        $this->updateAndReindexItems($itemValues, $oldElementText, $newElementText);

        return json_encode(array('success'=>true, 'duplicateId'=>$duplicateId, 'localTerm'=>$newLocalTerm, 'commonTermId'=>$newCommonTermId, 'commonTerm'=>$newCommonTerm));
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
