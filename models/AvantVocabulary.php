<?php

class AvantVocabulary
{
    # These values represent a bit map of flags which can be combined e.g. type & subject.
    const KIND_TYPE = 1;            # 0001
    const KIND_SUBJECT = 2;         # 0010
    const KIND_RESERVED = 3;        # 0011 Used by Common Vocabulary generator for terms that can be types or subjects.
    const KIND_PLACE = 4;           # 0100

    const VOCABULARY_TERM_COOKIE = 'VOCAB-KIND';

    // Common terms with an Id higher than this do not come from Nomenclature 4.0.
    const VOCABULARY_FIRST_NON_NOMENCLATURE_COMMON_TERM_ID = 20000;

    public static function addNewSiteTerm($kind, $term, $commonTermId = 0)
    {
        if (!($kind == self::KIND_TYPE || $kind == self::KIND_SUBJECT))
            return;
        $siteTermRecord = new VocabularySiteTerms();
        $siteTermRecord['kind'] = $kind;
        $siteTermRecord['site_term'] = $term;
        $siteTermRecord['common_term_id'] = $commonTermId;
        if (!$siteTermRecord->save())
            throw new Exception('addNewUnmappedSiteTerm save failed');
    }

    public static function emitVocabularyKindChooser()
    {
        $html = '';
        $html .= "<div class='vocabulary-chooser-area'>";
        $html .= "<label class='vocabulary-chooser-label'>Vocabulary: </label>";
        $html .= "<SELECT required id='vocabulary-chooser'>";
        $html .= "<OPTION value='0' selected disabled hidden>Select a vocabulary to edit</OPTION>";

        $kindFields = self::getVocabularyFields();
        foreach ($kindFields as $kindName => $kind)
            $html .= "<OPTION value='" . $kind . "'>" . $kindName . "</OPTION>";

        $html .= "</SELECT>";
        $html .= "</div>";
        return $html;
    }

    public static function getCommonTermSuggestionFromSiteTerm($kind, $siteTerm)
    {
        $siteLeafTerm = AvantVocabulary::getLeafFromTerm($siteTerm);
        $commonTermRecord = get_db()->getTable('VocabularyCommonTerms')->getCommonTermRecordByLeaf($kind, $siteLeafTerm);
        return $commonTermRecord ? $commonTermRecord->common_term : '';
    }

    public static function getDefaultKindFromQueryOrCookie()
    {
        $kinds = self::getVocabularyKinds();

        // Get the vocabulary kind from the URL.
        $kind = isset($_GET['kind']) ? intval($_GET['kind']) : 0;
        $isValidKind = in_array($kind, $kinds);

        if (!$isValidKind)
        {
            // The kind is not on the query string. Get it from the cookie.
            $kind = isset($_COOKIE[self::VOCABULARY_TERM_COOKIE]) ? $_COOKIE[self::VOCABULARY_TERM_COOKIE] : 0;
            $isValidKind = in_array($kind, $kinds);
            if (!$isValidKind)
            {
                // There's no cookie (or it has a bad value). Default to Type.
                $kind = self::KIND_TYPE;
            }
        }

        $kindFields = self::getVocabularyFields();
        $kindName = array_search($kind, $kindFields);
        return array($kind, $kindName);
    }

    public static function getLeafFromTerm($term)
    {
        $parts = array_map('trim', explode(',', $term));
        return $parts[count($parts) - 1];
    }

    public static function getVocabularyFields()
    {
        return array(
            'Type'=>self::KIND_TYPE,
            'Subject'=>self::KIND_SUBJECT,
            'Place'=>self::KIND_PLACE
        );
    }

    public static function getVocabularyKinds()
    {
        // Return a table that associates element Ids with their vocabulary kind. The element Id
        // cannot be used as the kind since it could vary on different Digital Archive installations.
        $kindTable = [];
        $vocabularyFields = self::getVocabularyFields();
        foreach ($vocabularyFields as $fieldName => $kind)
        {
            $elementId = ItemMetadata::getElementIdForElementName($fieldName);
            $kindTable[$elementId] = $kind;
        }

        return $kindTable;
    }

    public static function handleRebuildCommonTermsTable()
    {
        $tableBuilder = new AvantVocabularyTableBuilder();

        try
        {
            $tableBuilder->buildCommonTermsTable();
            $response = 'Common Terms table rebuilt';
        }
        catch (Exception $e)
        {
            $response = 'Request failed: ' . $e->getMessage();
        }

        return $response;
    }

    public static function handleRebuildSiteTermsTable()
    {
        $tableBuilder = new AvantVocabularyTableBuilder();

        try
        {
            $tableBuilder->buildSiteTermsTable();
            $response = 'Site Terms table rebuilt';
        }
        catch (Exception $e)
        {
            $response = 'Request failed: ' . $e->getMessage();
        }

        return $response;
    }

    public static function handleRefreshCommonVocabulary()
    {
        $tableBuilder = new AvantVocabularyTableBuilder();

        try
        {
            $response = $tableBuilder->refreshCommonTerms();
        }
        catch (Exception $e)
        {
            $response = 'Request failed: ' . $e->getMessage();
        }

        return $response;
    }

    public static function handleRemoteRequest($action, $siteId, $password)
    {
        if (AvantElasticsearch::remoteRequestIsValid($siteId, $password))
        {
            switch ($action)
            {
                case 'vocab-update':
                    $response = AvantVocabulary::handleRefreshCommonVocabulary();
                    break;

                case 'vocab-rebuild':
                    $response = AvantVocabulary::handleRebuildCommonTermsTable();
                    break;

                case 'vocab-report':
                    $response = AvantVocabulary::handleReportSiteTermsTable();
                    break;

                default:
                    $response = 'Unsupported AvantVocabulary action: ' . $action;
                    break;
            }
        }
        else
        {
            $response = '';
        }

        return $response;
    }

    public static function handleReportSiteTermsTable()
    {
        $tableBuilder = new AvantVocabularyTableBuilder();

        try
        {
            $response = self::reportSiteTermsTable();
        }
        catch (Exception $e)
        {
            $response = 'Request failed: ' . $e->getMessage();
        }

        return $response;
    }

    public static function kindIsTypeOrSubject($kind)
    {
        return $kind == self::KIND_TYPE || $kind == self::KIND_SUBJECT;
    }

    public static function normalizeSiteTerm($kind, $siteTerm)
    {
        $normalizedSiteTerm = '';
        $parts = array_map('trim', explode(',', $siteTerm));
        foreach ($parts as $part)
        {
            if (!$part)
                continue;

            // Remove multiple spaces within the part.
            $part = preg_replace('/\s+/', ' ', $part);

            // Remove disallowed characters leaving only A-Z, hyphen, ampersand, comma, and space.
            $part = preg_replace('/[^a-zA-Z0-9 \/\-\&]/', '', $part);

            if ($normalizedSiteTerm)
                $normalizedSiteTerm .= ', ';

            // Make each word have an uppercase first letter and all the rest lowercase, with some exceptions.
            $termParts = explode(' ', $part);
            $normalizedTermPart = '';
            $alwaysLowercase = array('and', 'or', 'of', 'a', 'the');
            foreach ($termParts as $index => $termPart)
            {
                if ($normalizedTermPart)
                    $normalizedTermPart .= ' ';
                if (ctype_upper($termPart) || !ctype_alpha($termPart) || $kind == AvantVocabulary::KIND_PLACE)
                {
                    // The term is all uppercase, or contains non-alpha characters (e.g. B&B) or is a place,
                    // so assume that's how it's supposed to be.
                    $normalizedTermPart .= $termPart;
                }
                else
                {
                    // Make all the text lower case.
                    $termPart = strtolower($termPart);

                    // Make the first letter uppercase unless it's a word that should always
                    // be lower case except if it's the first word.
                    if ($index == 0 || !in_array($termPart, $alwaysLowercase))
                        $termPart = ucwords($termPart);

                    $normalizedTermPart .= $termPart;
                }
            }
            $normalizedSiteTerm .= $normalizedTermPart;
        }
        return $normalizedSiteTerm;
    }

    public static function progressFileName()
    {
        return VOCABULARY_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'progress-' . current_user()->id . '.txt';
    }

    public static function reportSiteTermsTable()
    {
        $results = array();
        $kindFields = self::getVocabularyFields();
        foreach ($kindFields as $kindName => $kind)
        {
            $kindName = array_search($kind, $kindFields);
            $elementId = ItemMetadata::getElementIdForElementName($kindName);

            $siteTermItems = get_db()->getTable('VocabularySiteTerms')->getSiteTermItems($kind);
            $vocabularyTermsEditor = new VocabularyTermsEditor();

            foreach ($siteTermItems as $siteTermItem)
            {
                $siteTerm = $siteTermItem['site_term'];
                $commonTerm = $siteTermItem['common_term'];
                $commonTermId = $siteTermItem['common_term_id'];

                // Don't show the special term 'none'.
                if ($commonTerm == ElementValidator::VALIDATION_NONE)
                    continue;

                if ($commonTermId)
                    $mapping = $siteTerm ? 'mapped' : 'common';
                else
                    $mapping = 'unmapped';
                $term = $siteTerm ? $siteTerm : $commonTerm;
                $usageCount = $vocabularyTermsEditor->getSiteTermUsageCount($elementId, $term);
                if ($siteTerm && $commonTerm)
                    $term .= " ($commonTerm)";

                $result['kind'] = $kindName;
                $result['count'] = $usageCount;
                $result['term'] = $term;
                $result['mapping'] = $mapping;
                $results[] = $result;
            }
        }

        return $results;
    }

    public static function vocabulary_diff_url()
    {
        return self::vocabulary_url('digital-archive-diff.csv');
    }

    public static function vocabulary_terms_url()
    {
        return self::vocabulary_url('digital-archive-vocabulary.csv');
    }

    public static function vocabulary_url($fileName)
    {
        return 'https://digitalarchive.us/vocabulary/' . $fileName;
    }
}