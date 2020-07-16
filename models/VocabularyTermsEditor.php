<?php

class VocabularyTermsEditor
{
    const ADD_VOCABULARY_TERM = 1;
    const REMOVE_VOCABULARY_TERM = 2;
    const UPDATE_VOCABULARY_TERM = 3;

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
        $siteTerm = AvantVocabulary::normalizeSiteTerm($kind, $itemValues['siteTerm']);
        $commonTerm = $itemValues['commonTerm'];

        // Check to see if the term already exists.
        $term = $siteTerm ? $siteTerm : $commonTerm;
        if ($this->db->getTable('VocabularySiteTerms')->siteTermExists($kind, $term))
        {
            return json_encode(array('success'=>false, 'id'=>0, 'error'=>'site-term-exists'));
        }

        $commonTermId = $this->getIdForCommonTerm($kind, $commonTerm);

        // Determine if the site term is a common term.
        $commonTermIdForSiteTerm = $this->getIdForCommonTerm($kind, $siteTerm);
        if ($commonTermIdForSiteTerm)
        {
            if ($commonTermId)
            {
                // Report an error that the site term is a common term and there is already a common term.
                return json_encode(array('success'=>false, 'error'=>'site-term-is-common-term'));
            }
            else
            {
                // Use the site term for the common term.
                $commonTerm = $siteTerm;
                $siteTerm = '';
                $commonTermId = $commonTermIdForSiteTerm;
            }
        }

        $newSiteTermRecord = new VocabularySiteTerms();
        $newSiteTermRecord['order'] = 0;
        $newSiteTermRecord['kind'] = $kind;
        $newSiteTermRecord['site_term'] = $siteTerm;
        $newSiteTermRecord['common_term_id'] = $commonTermId;

        $suggestion = '';
        if ($commonTermId == 0)
        {
            // There's no common term. See if the we can offer a suggestion.
            $suggestion = AvantVocabulary::getCommonTermSuggestionFromSiteTerm($kind, $siteTerm);
        }

        // Determine if the site term now exactly matches another. This can happen if the site term
        // is a common term and the record has no common term, but another record has that same common
        // term and no site term.
        $duplicateSiteTermRecord = $this->db->getTable('VocabularySiteTerms')->getDuplicateSiteTermRecord($newSiteTermRecord);
        if ($duplicateSiteTermRecord)
        {
            return json_encode(array(
                'success'=>false,
                'id'=>0,
                'error'=>'site-term-exists'
            ));
        }

        // Add the new term by updating the new record to insert it into the database.
        if (!$newSiteTermRecord->save())
            throw new Exception($this->reportError(__FUNCTION__, ' save failed'));

        return json_encode(array(
            'success'=>true,
            'id'=>$newSiteTermRecord->id,
            'siteTerm'=>$siteTerm,
            'commonTerm'=>$commonTerm,
            'commonTermId'=>$commonTermId,
            'suggestion'=>$suggestion
        ));
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

    public function getSiteTermUsageCount($elementId, $siteTerm)
    {
        $siteTerm = AvantCommon::escapeQuotes($siteTerm);

        try
        {
            $table = "{$this->db->prefix}element_texts";

            $sql = "
                SELECT
                  COUNT(*)
                FROM
                  $table
                WHERE
                  element_id = $elementId AND text = '$siteTerm'
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

        $siteTermRecord = $this->db->getTable('VocabularySiteTerms')->find($itemValues['id']);
        if (!$siteTermRecord)
            throw new Exception($this->reportError(__FUNCTION__, 'find failed'));

        $success = false;
        $elementId = $itemValues['elementId'];

        // Verify that the term is not in use just in case another user saved an Omeka item using
        // the term while our user was attempting to remove it.
        $term = $itemValues['siteTerm'] ? $itemValues['siteTerm'] : $itemValues['commonTerm'];
        if ($this->getSiteTermUsageCount($elementId, $term) == 0)
        {
            $siteTermRecord->delete();
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

        // Get the site term record and update it with the posted local and common terms.
        $siteTermRecord = $this->db->getTable('VocabularySiteTerms')->getSiteTermRecordById($id);
        if (!$siteTermRecord)
            throw new Exception($this->reportError(__FUNCTION__, ' get site term record failed'));

        $oldCommonTermId = $siteTermRecord->common_term_id;
        $newCommonTerm = $itemValues['commonTerm'];
        $newCommonTermId = $newCommonTerm ? $this->getIdForCommonTerm($kind, $newCommonTerm) : 0;

        $oldSiteTerm = $siteTermRecord->site_term;
        $newSiteTermRaw = $itemValues['siteTerm'];
        $newSiteTerm = AvantVocabulary::normalizeSiteTerm($kind, $newSiteTermRaw);

        if ($oldSiteTerm != $newSiteTermRaw && $oldSiteTerm == $newSiteTerm && $oldCommonTermId == $newCommonTermId)
        {
            // The user edited the site term in a way that did not alter it's normalized form e.g. they changed
            // letter casing, or added/removed spaces or commas. As such, the old and new term are identical so
            // simply return the term with no further analysis.
            return json_encode(array('success'=>true, 'duplicateId'=>0, 'siteTerm'=>$newSiteTerm, 'commonTermId'=>$newCommonTermId, 'commonTerm'=>$newCommonTerm));
        }

        // Check if the site term has changed.
        $newSiteTermAlreadyExists = false;
        $siteTermChanged = false;
        if ($newSiteTerm && $newSiteTerm != $oldSiteTerm)
        {
            // The site term has changed. Check if the new site term already exists.
            $siteTermChanged = true;
            if ($this->db->getTable('VocabularySiteTerms')->siteTermExists($kind, $newSiteTerm))
            {
                $newSiteTermAlreadyExists = true;
            }
        }

        // Determine if the site term is a common term.
        $commonTermIdForSiteTerm = $this->getIdForCommonTerm($kind, $newSiteTerm);
        if ($commonTermIdForSiteTerm)
        {
            if ($newCommonTermId)
            {
                // Report an error that the site term is a common term and there is already a common term.
                return json_encode(array('success'=>false, 'error'=>'site-term-is-common-term'));
            }
            else
            {
                // Use the site term for the common term.
                $newCommonTerm = $itemValues['siteTerm'];
                $newSiteTerm = '';
                $newCommonTermId = $commonTermIdForSiteTerm;
            }
        }

        // Determine the old term, before the update.
        if ($oldSiteTerm)
        {
            $oldElementText = $oldSiteTerm;
        }
        else
        {
            $oldCommonTermRecord = $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTermId($oldCommonTermId);
            if (!$oldCommonTermRecord)
                throw new Exception($this->reportError(__FUNCTION__, ' get old common term record failed'));
            $oldElementText = $oldCommonTermRecord->common_term;
        }

        // Determine the new term, after the update.
        if ($newSiteTerm)
        {
            $newElementText = $newSiteTerm;
        }
        else
        {
            $newElementText = $newCommonTerm;
        }

        // Update the site term record with the new data.
        $siteTermRecord['site_term'] = $newSiteTerm;
        $siteTermRecord['common_term_id'] = $newCommonTermId;

        $suggestion = '';
        if ($siteTermChanged && $newCommonTermId == 0)
        {
            // There's no common term. See if the we can offer a suggestion.
            $suggestion = AvantVocabulary::getCommonTermSuggestionFromSiteTerm($kind, $newSiteTerm);
        }

        // Determine if the site term now exactly matches another.
        $duplicateSiteTermRecord = $this->db->getTable('VocabularySiteTerms')->getDuplicateSiteTermRecord($siteTermRecord);
        if ($duplicateSiteTermRecord)
        {
            // Delete the duplicate term. Return its Id so the Vocabulary Editor Javascript knows to merge the two terms.
            $duplicateId = $duplicateSiteTermRecord->id;
            $duplicateSiteTermRecord->delete();
        }
        elseif ($newSiteTermAlreadyExists)
        {
            // The site term has the same name as an existing site term, but the terms don't
            // match (the common term is different). Report that using the same term is not allowed.
            return json_encode(array('success'=>false, 'error'=>'site-term-exists'));
        }
        else
        {
            $duplicateId = 0;
        }

        // Update the local record with the new information.
        if (!$siteTermRecord->save())
            throw new Exception($this->reportError(__FUNCTION__, ' save failed'));

        // Update the Elasticsearch indexes with the new data.
        $this->updateAndReindexItems($itemValues, $oldElementText, $newElementText);

        return json_encode(array(
            'success'=>true,
            'duplicateId'=>$duplicateId,
            'siteTerm'=>$newSiteTerm,
            'commonTermId'=>$newCommonTermId,
            'commonTerm'=>$newCommonTerm,
            'suggestion'=>$suggestion
        ));
    }
}
