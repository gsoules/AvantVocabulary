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

    const VOCABULARY_MAPPING_NONE = 0;
    const VOCABULARY_MAPPING_IDENTICAL = 1;
    const VOCABULARY_MAPPING_SYNONYMOUS = 2;

    // Common terms with an Id higher than this do not come from Nomenclature 4.0.
    const VOCABULARY_FIRST_NON_NOMENCLATURE_COMMON_TERM_ID = 20000;

    public static function getVocabularyFields()
    {
        return array(
            'Type'=>AvantVocabulary::VOCABULARY_TERM_KIND_TYPE,
            'Subject'=>AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT,
            'Place'=>AvantVocabulary::VOCABULARY_TERM_KIND_PLACE
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
            $response = $tableBuilder->refreshCommonTerms();
        }

        return $response;
    }
}