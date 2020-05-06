<?php

class AvantVocabulary
{
    protected $db;

    public function __construct()
    {
        $this->db = get_db();
    }

    public function getCommonTerm($text)
    {
        $term = $this->db->getTable('VocabularyMappings')->getVocabularyMapping($text);
        if ($term)
            return $term->common_term;
        else
            return $text;
    }
}