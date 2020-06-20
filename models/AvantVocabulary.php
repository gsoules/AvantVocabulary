<?php

class AvantVocabulary
{
    # These values represent a bit map of flags which can be combined e.g. type & subject.
    const VOCABULARY_TERM_KIND_TYPE = 1;             # 0001
    const VOCABULARY_TERM_KIND_SUBJECT = 2;          # 0010
    const VOCABULARY_TERM_KIND_PLACE = 4;            # 0100
    const VOCABULARY_TERM_KIND_TYPE_AND_SUBJECT = 3; # 0011

    const VOCABULARY_TERM_KIND_TYPE_LABEL = 'Type';
    const VOCABULARY_TERM_KIND_SUBJECT_LABEL = 'Subject';
    const VOCABULARY_TERM_KIND_PLACE_LABEL = 'Place';

    const VOCABULARY_TERM_COOKIE = 'VOCAB-KIND';

    // Common terms with an Id higher than this do not come from Nomenclature 4.0.
    const VOCABULARY_FIRST_NON_NOMENCLATURE_COMMON_TERM_ID = 20000;

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
                $kind = self::VOCABULARY_TERM_KIND_TYPE;
            }
        }

        $kindFields = self::getVocabularyFields();
        $kindName = array_search($kind, $kindFields);
        return array($kind, $kindName);
    }

    public static function getVocabularyFields()
    {
        return array(
            'Type'=>self::VOCABULARY_TERM_KIND_TYPE,
            'Subject'=>self::VOCABULARY_TERM_KIND_SUBJECT,
            'Place'=>self::VOCABULARY_TERM_KIND_PLACE
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

    public static function kindIsTypeOrSubject($kind)
    {
        return $kind == self::VOCABULARY_TERM_KIND_TYPE || $kind == self::VOCABULARY_TERM_KIND_SUBJECT;
    }

    public static function progressFileName()
    {
        return VOCABULARY_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'progress-' . current_user()->id . '.txt';
    }

    public static function refreshCommonVocabulary($password)
    {
        $response = 'Request denied';

        // Use the last six characters of the Elasticsearch key as the password for remote access to AvantVocabulary.
        // This is simpler/safer than the remote caller having to know an Omeka user name and password. Though the
        // key is public anyway, using just the tail end of it means the caller does not know the entire key.
        $key = ElasticsearchConfig::getOptionValueForKey();
        $tail = substr($key, strlen($key) - 6);
        if ($password == $tail)
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
        }

        return $response;
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