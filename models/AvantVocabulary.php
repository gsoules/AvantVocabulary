<?php

class AvantVocabulary
{
    protected $db;

    public function __construct()
    {
        $this->db = get_db();
    }

    public function getMappedTermForLocalTerm($elementId, $localTerm)
    {
        $mappedTerm = $this->db->getTable('VocabularyLocalTerms')->getMappedTerm($elementId, $localTerm);
        if (!$mappedTerm)
            $mappedTerm = 'UNMAPPED';
        return $mappedTerm;
    }
}