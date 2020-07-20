<?php

class AvantVocabularyTableBuilder
{
    protected $db;

    public function __construct()
    {
        $this->db = get_db();
    }

    public function buildCommonTermsTable()
    {
        VocabularyTableFactory::dropVocabularyCommonTermsTable();
        VocabularyTableFactory::createVocabularyCommonTermsTable();

        $rows = $this->readDataRowsFromRemoteCsvFile(AvantVocabulary::vocabulary_terms_url());

        foreach ($rows as $row)
        {
            $kind = $row[0];
            $id = intval($row[1]);
            $term = $row[2];

            $this->databaseInsertRecordForCommonTerm($kind, $id, $term);
        }
    }

    public function buildSiteTermsTable()
    {
        $fields = AvantVocabulary::getVocabularyFields();

        // Get the current terms from the site terms table in order to save terms that are unused and/or mapped.
        $oldTermItems = array();
        foreach ($fields as $elementName => $kind)
        {
            $oldTermItems[$kind] = $this->getSiteTermsForKind($kind);
        }

        // Create a new, empty site terms table. It will only contain terms that are in use and not mapped.
        VocabularyTableFactory::dropVocabularySiteTermsTable();
        VocabularyTableFactory::createVocabularySiteTermsTable();

        // Create new terms for each kind.
        foreach ($fields as $elementName => $kind)
        {
            $this->createSiteTerms($elementName, $kind, $oldTermItems[$kind]);
        }
    }

    protected function convertSiteTermsToUnmapped($commonTermId)
    {
        // Get all the local records that use the common term.
        $siteTermRecords = $this->db->getTable('VocabularySiteTerms')->getSiteTermRecordsByCommonTermId($commonTermId);

        if ($siteTermRecords)
        {
            // Get the common term text.
            $commonTermRecord = $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTermId($commonTermId);
            $commonTerm = $commonTermRecord->common_term;

            // Change each site term record to be unmapped (has a site term that is not mapped to a common term).
            foreach ($siteTermRecords as $siteTermRecord)
            {
                $siteTermRecord['site_term'] = $commonTerm;
                $siteTermRecord['common_term_id'] = 0;
                if (!$siteTermRecord->save())
                    throw new Exception($this->reportError('Save site term failed', __FUNCTION__, __LINE__));
            }
        }
    }

    protected function createSiteTerms($elementName, $kind, $oldTermItems)
    {
        // Get the set of unique text values for this element.
        $elementId = ItemMetadata::getElementIdForElementName($elementName);
        $siteTerms = $this->fetchUniqueSiteTerms($elementId);

        if ($kind == AvantVocabulary::KIND_PLACE)
        {
            // Find any place terms that are not in use and add them to the site table.
            $placeTerms = $this->db->getTable('VocabularyCommonTerms')->getAllCommonTermRecordsForKind($kind);
            foreach ($placeTerms as $index => $placeTerm)
            {
                if (!in_array($placeTerm->common_term, $siteTerms))
                    $siteTerms[] = $placeTerm->common_term;
            }
        }

        // Add the terms to the table.
        $newTermRecords = array();
        foreach ($siteTerms as $siteTerm)
        {
            $newTermRecords[] = $this->databaseInsertRecordForSiteTerm($kind, $siteTerm);
        }

        // Add any unused and/or mapped terms from the old table to the new table.
        // Unused terms will be missing because they will not have been returned by fetchUniqueSiteTerms().
        // Mapped terms won't match new terms because all new terms are unmapped.
        foreach ($oldTermItems as $oldTermItem)
        {
            $oldTermFoundInNewTable = false;
            $newTermUpdated = false;

            $oldSiteTerm = $oldTermItem['site_term'];
            $oldCommonTermId = $oldTermItem['common_term_id'];

            foreach ($newTermRecords as $newTermRecord)
            {
                $newSiteTerm = $newTermRecord->site_term;
                if (empty($newSiteTerm))
                {
                    // The new record has no site term which means it's a common term.
                    if (empty($oldSiteTerm) && $newTermRecord->common_term_id == $oldCommonTermId)
                    {
                        // The old term is also common and has the same common term Id as the same as the new term.
                        $oldTermFoundInNewTable = true;
                        break;
                    }
                }
                elseif (
                    $newSiteTerm == $oldSiteTerm ||
                    AvantVocabulary::normalizeSiteTerm($kind, $newSiteTerm) == AvantVocabulary::normalizeSiteTerm($kind, $oldSiteTerm))
                {
                    // The new and old site terms are the same. The new term is unmapped by virtue of being new.
                    if ($oldTermItem['common_term_id'] == 0)
                    {
                        // The old term is also unmapped.
                        $oldTermFoundInNewTable = true;
                        break;
                    }
                    else
                    {
                        // The old term is mapped. Add the mapping to the term in the new table.
                        $newTermRecord['common_term_id'] = $oldCommonTermId;
                        if (!$newTermRecord->save())
                            throw new Exception($this->reportError('Save failed', __FUNCTION__, __LINE__));
                        $newTermUpdated = true;
                        break;
                    }
                }
            }

            if (!($newTermUpdated || $oldTermFoundInNewTable))
            {
                // This term existed in the old table, but was not in use. See if it's normalized form matches an
                // existing term. If it does, then don't add it to the table.
                $normalizedSiteTerm = AvantVocabulary::normalizeSiteTerm($kind, $oldSiteTerm);
                $exists =  $this->db->getTable('VocabularySiteTerms')->siteTermExists($kind, $normalizedSiteTerm);
                if (!$exists)
                {
                    // Add this term to the site terms table.
                    $this->databaseInsertRecordForOldSiteTerm($kind, $oldSiteTerm, $oldTermItem['common_term_id']);
                }
            }
        }
    }

    protected function databaseInsertRecordForCommonTerm($kind, $id, $term)
    {
        $commonTermRecord = new VocabularyCommonTerms();
        $commonTermRecord['kind'] = $kind;
        $commonTermRecord['common_term_id'] = $id;
        $commonTermRecord['common_term'] = $term;
        $commonTermRecord['leaf'] = AvantVocabulary::getLeafFromTerm($term);

        if (!$commonTermRecord->save())
            throw new Exception($this->reportError('Save failed', __FUNCTION__, __LINE__));

        return $commonTermRecord;
    }

    protected function databaseInsertRecordForSiteTerm($kind, $siteTerm)
    {
        $siteTermRecord = new VocabularySiteTerms();
        $siteTermRecord['kind'] = $kind;

        $commonTermRecord = $this->getCommonTermRecord($kind, $siteTerm);
        if ($commonTermRecord)
        {
            $siteTermRecord['site_term'] = '';
            $siteTermRecord['common_term_id'] = $commonTermRecord->common_term_id;
        }
        else
        {
            $siteTermRecord['site_term'] = $siteTerm;
            $siteTermRecord['common_term_id'] = 0;
        }

        if (!$siteTermRecord->save())
            throw new Exception($this->reportError('Save failed', __FUNCTION__, __LINE__));

        return $siteTermRecord;
    }

    protected function databaseInsertRecordForOldSiteTerm($kind, $siteTerm, $commonTermId)
    {
        $siteTermRecord = new VocabularySiteTerms();
        $siteTermRecord['kind'] = $kind;
        $siteTermRecord['site_term'] = $siteTerm;
        $siteTermRecord['common_term_id'] = $commonTermId;

        if (!$siteTermRecord->save())
            throw new Exception($this->reportError('Save failed', __FUNCTION__, __LINE__));
    }

    protected function databaseRemoveCommonTerm($commonTermRecord)
    {
        try
        {
            $commonTermRecord->delete();
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();
            throw new Exception($this->reportError('Delete failed: ' . $message, __FUNCTION__, __LINE__));
        }
    }

    protected function fetchElementTextsHavingTerm($elementId, $term)
    {
        $term = AvantCommon::escapeQuotes($term);

        try
        {
            $table = "{$this->db->prefix}element_texts";

            $sql = "
                SELECT
                  id,
                  record_id
                FROM
                  $table
                WHERE
                  element_id = $elementId AND text = '$term'
            ";

            $results = $this->db->query($sql)->fetchAll();
        }
        catch (Exception $e)
        {
            $results = array();
        }

        return $results;
    }

    protected function fetchUniqueSiteTerms($elementId)
    {
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
                ORDER BY
                  text  
            ";

            $results = $this->db->query($sql)->fetchAll();
        }
        catch (Exception $e)
        {
            $results = null;
        }

        $siteTerms  = array();
        foreach ($results as $result)
            $siteTerms[] = $result['text'];
        return $siteTerms;
    }

    protected function getCommonTermRecord($kind, $commonTerm)
    {
        return $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTerm($kind, $commonTerm);
    }

    protected function getCommonTermRecordByKindAndCommonTermId($kind, $commonTermId)
    {
        return $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByKindAndCommonTermId($kind, $commonTermId);
    }

    protected function getSiteTermsForKind($kind)
    {
        // This method gets terms from the site terms table and filters out any that are no longer valid.
        $siteTermsItems = $this->db->getTable('VocabularySiteTerms')->getSiteTermItems($kind);
        $hashList = array();
        foreach ($siteTermsItems as $index => $siteTermsItem)
        {
            $skip = false;
            $commonTermId = $siteTermsItem['common_term_id'];
            $siteTerm = $siteTermsItem['site_term'];
            if (empty($siteTerm) && $commonTermId == 0)
            {
                // Both the site term and common term Id are missing. This should never happen, but if it does, clean it up.
                $skip = true;
            }
            elseif ($commonTermId)
            {
                // The site term has a common term Id. Verify that the common term exists in the common terms table.
                if (!$this->getCommonTermRecordByKindAndCommonTermId($kind, $commonTermId))
                {
                    // The common term Id does not exist. This could happen if the term was removed from the common
                    // vocabulary or it's Id was changed. Keep the site term, but change it to unmapped.
                    $siteTermsItems[$index]['common_term_id'] = 0;
                }
            }

            if (!$skip && $commonTermId == 0)
            {
                // See if this unmapped site term is a common term. This could happen if a common term got renamed
                // and somehow the site term did not get updated when the vocabulary was refreshed.
                $commonTermRecord = $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByCommonTerm($kind, $siteTerm);
                if ($commonTermRecord)
                    $skip = true;
            }

            if (!$skip)
            {
                // Check if this term is a duplicate of another.
                $hash = "$commonTermId-$siteTerm";
                if (in_array($hash, $hashList))
                    $skip = true;
                else
                    $hashList[] = $hash;
            }

            if ($skip)
            {
                // Remove this term from the list.
                unset($siteTermsItems[$index]);
            }
        }

        return $siteTermsItems;
    }

    public function handleAjaxRequest($tableName)
    {
        // This method is called in response to Ajax requests from the client. For more information, see the comments
        // for this same method in AvantElasticSearchIndexBuilder.

        $success = true;
        $error = '';

        try
        {
            switch ($tableName)
            {
                case 'common':
                    $this->buildCommonTermsTable();
                    break;

                case 'local':
                    $this->buildSiteTermsTable();
                    break;

                default:
                    $success = false;
                    $error = 'Unexpected table name: ' . $tableName;
            }
        }
        catch (Exception $e)
        {
            $success = false;
            $error = $e->getMessage();
        }

        $response = json_encode(array('success'=>$success, 'error'=>$error));
        echo $response;
    }

    public function refreshCommonTerms()
    {
        $rows = $this->readDataRowsFromRemoteCsvFile(AvantVocabulary::vocabulary_diff_url());
        $itemsRefreshed = 0;

        foreach ($rows as $row)
        {
            $action = $row[0];
            $kind = $row[1];
            $id = intval($row[2]);
            $oldTerm = $row[3];
            $newTerm = $row[4];

            $itemsRefreshed += $this->refreshCommonTerm($action, $kind, $id, $oldTerm, $newTerm);
        }

        return "Commands processed: " . count($rows) . ".  Items refreshed: $itemsRefreshed";
    }

    protected function readDataRowsFromRemoteCsvFile($url)
    {
        $response = AvantCommon::requestRemoteAsset($url);
        if ($response['response-code'] != 200)
            throw new Exception("Could not read $url");

        // Break the response into individual rows from the CSV file.
        $rawRows = explode("\n", $response['result']);

        $rows = array();
        foreach ($rawRows as $index => $rawRow)
        {
            // Skip the header row;
            if ($index == 0)
                continue;

            $row = str_getcsv($rawRow);
            if (!empty($row[0]))
                $rows[] = $row;
        }

        return $rows;
    }

    protected function refreshCommonTerm($action, $kind, $commonTermId, $oldTerm, $newTerm)
    {
        // This method gets called when a common term has been added, deleted, or updated. It is called once for each
        // action in a diff file. If the same diff file gets processed more than once, or if a previous action was
        // equivalent to a subsequent action (e.g. the action was for both a Type and Subject), some parts of some
        // actions will get skipped (e.g. a common term that was added for Type won't get added again for Subject).

        $refreshItems = false;
        switch ($action)
        {
            case 'ADD':
                $commonTermRecord = $this->getCommonTermRecordByKindAndCommonTermId($kind, $commonTermId);

                // Make sure the common term does not already exist. It will exist
                // if the refresh is requested multiple times using the same diff file.
                if (!$commonTermRecord)
                {
                    // Add the new common term to the common terms table.
                    $commonTermRecord = $this->databaseInsertRecordForCommonTerm($kind, $commonTermId, $newTerm);

                    // Determine if the added term turns an unmapped site term into a common term.
                    $siteTermRecord = $this->db->getTable('VocabularySiteTerms')->getSiteTermRecordByKindAndSiteTerm($kind, $newTerm);
                    if ($siteTermRecord && $siteTermRecord->common_term_id == 0)
                    {
                        // The common term matches and unmapped site term. Change the site term to the common term.
                        $this->updateSiteTermToBecomeCommonTerm($siteTermRecord, $commonTermId);
                        $refreshItems = true;
                    }
                }
                break;

            case 'DELETE':
                // Delete the common term from the common terms table.
                $commonTermRecord = $this->getCommonTermRecordByKindAndCommonTermId($kind, $commonTermId);

                // Make sure the common term exists. It won't exist if the
                // refresh is requested multiple times using the same diff file.
                if ($commonTermRecord)
                {
                    $this->convertSiteTermsToUnmapped($commonTermId);
                    $this->databaseRemoveCommonTerm($commonTermRecord);
                    $refreshItems = true;
                }
                break;

            case 'UPDATE':
                // The common term's text has changed. Get the common term's record from the database.
                $commonTermRecord = $this->db->getTable('VocabularyCommonTerms')->getCommonTermRecordByKindAndCommonTermId($kind, $commonTermId);
                if (!$commonTermRecord)
                    throw new Exception($this->reportError('Get common term record failed', __FUNCTION__, __LINE__));

                // Make sure the common term text has not already been updated. It will have been
                // updated already if the refresh is requested multiple times using the same diff file.
                if ($commonTermRecord->common_term != $newTerm)
                {
                    // Update the common term's text in the common term table.
                    $commonTermRecord['common_term'] = $newTerm;
                    if (!$commonTermRecord->save())
                        throw new Exception($this->reportError('Save common term failed',  __FUNCTION__, __LINE__));
                    $refreshItems = true;
                }

                // See if the updated common term is the same as a site term.
                $siteTermRecord = $this->db->getTable('VocabularySiteTerms')->getSiteTermRecordByKindAndSiteTerm($kind, $newTerm);
                if ($siteTermRecord)
                {
                    $updateSiteTerm = false;
                    // A site term now has the same text as the updated common term.
                    if ($siteTermRecord['common_term_id'] == 0)
                    {
                        // The site term is unmapped. Change it be the common term.
                        $updateSiteTerm = true;
                    }
                    elseif ($siteTermRecord['common_term_id'] == $commonTermId)
                    {
                        // This site term is mapped to the updated common term which means the site term's text
                        // is the same as the common term text. Change the mapped local to be the common term.
                        $updateSiteTerm = true;
                    }
                    if ($updateSiteTerm)
                        $this->updateSiteTermToBecomeCommonTerm($siteTermRecord, $commonTermId);
                }
                break;

            default:
                throw new Exception($this->reportError('Unsupported action ' . $action, __FUNCTION__, __LINE__));
        }

        // Update items affect by the actions above.
        $itemsRefreshed = 0;
        if ($refreshItems)
            $itemsRefreshed = $this->refreshItems($action, $kind, $commonTermId, $oldTerm, $newTerm);
        return $itemsRefreshed;
    }

    protected function refreshItems($action, $kind, $commonTermId, $oldTerm, $newTerm)
    {
        $itemIds = array();

        // Get the element Id corresponding to the term's kind.
        $elementId = array_search($kind, AvantVocabulary::getVocabularyKinds());

        // Query the database to get all element texts that use the term.
        $elementTexts = $this->fetchElementTextsHavingTerm($elementId, $action == 'ADD' ? $newTerm : $oldTerm);

        // Examine the results. Each contains the element text's Id and the Id of the element's item.
        foreach ($elementTexts as $elementText)
        {
            if ($action == 'UPDATE')
            {
                // Update the element texts to replace the old term with the new term. There's no need to do
                // this when a term is added or deleted since those changes don't alter the element text.
                $this->updateElementTexts($elementText['id'], $newTerm);
            }

            // Remember the Id of the item that the element text belongs to.
            $itemIds[] = $elementText['record_id'];
        }

        if ($action == 'UPDATE')
        {
            // Get all site terms that are mapped to the common term. For example, the site terms "Birds, Songbirds"
            // and "Birds, Raptors" could be mapped to the common term "Nature, Animals, Birds".
            $siteTermRecords = $this->db->getTable('VocabularySiteTerms')->getSiteTermRecordsByCommonTermId($commonTermId);

            foreach ($siteTermRecords as $siteTermRecord)
            {
                // Query the database to get all elements that use this local mapped term.
                $elementTexts = $this->fetchElementTextsHavingTerm($elementId, $siteTermRecord->site_term);

                // Examine the results. Each contains the element text's Id and the Id of the element's item.
                foreach ($elementTexts as $elementText)
                {
                    // Remember the Id of the item that the element text belongs to. The item will need to get updated
                    // in the local and shared indexes to reflect the change to the common term. For example, if the
                    // common term changed from "Nature, Animals, Birds" to "Nature, Animals, Tweeters", every item's
                    // local/common mapping needs to be updated to reflect the change.
                    $itemIds[] = $elementText['record_id'];
                }
            }
        }

        // Refresh every unique item that is affected by the addition, deletion, or update of the common term.
        $itemIds = array_unique($itemIds);
        foreach ($itemIds as $itemId)
        {
            $this->updateItemIndexes($itemId);
        }

        return count($itemIds);
    }

    private function reportError($message, $function, $line)
    {
        return ("$message: $function on line $line");
    }

    protected function updateElementTexts($elementTextsId, $text)
    {
        $sql = "UPDATE `{$this->db->ElementTexts}` SET text = '$text' WHERE id = $elementTextsId";
        $this->db->query($sql);
    }

    protected function updateItemIndexes($itemId)
    {
        $item = AvantCommon::fetchItemForRemoteRequest($itemId);
        if (!$item)
            throw new Exception($this->reportError('Item Id not found: ' . $itemId, __FUNCTION__, __LINE__));

        $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
        $sharedIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_SHARE) == true;
        $localIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_LOCAL) == true;

        $avantElasticsearch = new AvantElasticsearch();
        $avantElasticsearch->updateIndexForItem($item, $avantElasticsearchIndexBuilder, $sharedIndexIsEnabled, $localIndexIsEnabled);
    }

    protected function updateSiteTermToBecomeCommonTerm($siteTermRecord, $commonTermId)
    {
        $siteTermRecord['site_term'] = '';
        $siteTermRecord['common_term_id'] = $commonTermId;
        if (!$siteTermRecord->save())
        {
            throw new Exception($this->reportError('Save site term failed', __FUNCTION__, __LINE__));
        }
    }
}