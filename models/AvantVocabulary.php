<?php

class AvantVocabulary
{
    protected $db;

    public function __construct()
    {
        $this->db = get_db();
    }

    public function getMappedTermForLocalTerm($kind, $localTerm)
    {
        $mappedTerm = $this->db->getTable('VocabularyLocalTerms')->getMappedTerm($kind, $localTerm);
        if (!$mappedTerm)
            $mappedTerm = 'UNMAPPED';
        return $mappedTerm;
    }
}