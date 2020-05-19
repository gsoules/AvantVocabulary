<?php

class AvantVocabulary
{
    # These values represent a bit map of flags which can be combined e.g. type & subject.
    const VOCABULARY_TERM_KIND_TYPE = 1;             # 0001
    const VOCABULARY_TERM_KIND_SUBJECT = 2;          # 0010
    const VOCABULARY_TERM_KIND_PLACE = 4;            # 0100
    const VOCABULARY_TERM_KIND_TYPE_AND_SUBJECT = 3; # 0011

    protected $db;

    public function __construct()
    {
        $this->db = get_db();
    }

    public function getCommonTermForLocalTerm($kind, $localTerm)
    {
        $mappedTerm = $this->db->getTable('VocabularyLocalTerms')->getCommonTermForLocalTerm($kind, $localTerm);
        if (!$mappedTerm)
            $mappedTerm = 'UNMAPPED';
        return $mappedTerm;
    }
}