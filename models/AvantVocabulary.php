<?php

class AvantVocabulary
{
    # These values represent a bit map of flags which can be combined e.g. type & subject.
    const VOCABULARY_TERM_KIND_TYPE = 1;             # 0001
    const VOCABULARY_TERM_KIND_SUBJECT = 2;          # 0010
    const VOCABULARY_TERM_KIND_PLACE = 4;            # 0100
    const VOCABULARY_TERM_KIND_TYPE_AND_SUBJECT = 3; # 0011

    const VOCABULARY_MAPPING_NONE = 0;
    const VOCABULARY_MAPPING_IDENTICAL = 1;
    const VOCABULARY_MAPPING_SYNONYMOUS = 2;

    const VOCABULARY_MAPPING_NONE_LABEL = 'UNMAPPED';
    const VOCABULARY_MAPPING_IDENTICAL_LABEL = 'IDENTICAL';
    const VOCABULARY_MAPPING_SYNONYMOUS_LABEL = 'SYNONYMOUS';

    // Common terms with an Id higher than this do not come from Nomenclature 4.0.
    const VOCABULARY_FIRST_NON_NOMENCLATURE_COMMON_TERM_ID = 20000;

    protected $db;

    public function __construct()
    {
        $this->db = get_db();
    }

    public function getCommonTermForLocalTerm($kind, $localTerm)
    {
        $commonTerm = $this->db->getTable('VocabularyLocalTerms')->getCommonTermForLocalTerm($kind, $localTerm);
        if (!$commonTerm)
            $commonTerm = 'UNMAPPED';
        return $commonTerm;
    }

    public static function getWhereKind($kind)
    {
        // This method treats kind as a bit mask. If either the Type or the Subject bit is set, it creates
        // part of a SQL Where clause that tests kind against the single bit passed in (0001 or 0010) and
        // and also tests against both bits being set (0011). This somewhat cumbersome approach addresses
        // the fact that the Common Facets vocabulary contains thousands of terms that apply to both
        // Type and Subject elements. Rather than duplicate them in the common terms table so that each has
        // its own kind, they only appear once, but their kind is VOCABULARY_TERM_KIND_TYPE_AND_SUBJECT.

        if ($kind == AvantVocabulary::VOCABULARY_TERM_KIND_TYPE || $kind == AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT)
        {
            $typeOrSubject = AvantVocabulary::VOCABULARY_TERM_KIND_TYPE_AND_SUBJECT;
            $whereKind = "(kind = $kind OR kind = $typeOrSubject)";
        }
        else
        {
            $whereKind = "kind = $kind";
        }
        return $whereKind;
    }
}