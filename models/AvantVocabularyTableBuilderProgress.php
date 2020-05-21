<?php

class AvantVocabularyTableBuilderProgress
{
    protected $db;

    public function __construct()
    {
        $this->db = get_db();
    }

    public function handleAjaxRequest()
    {
        $response = $this->getProgress();
        echo json_encode($response);
    }

    protected function getProgress()
    {
        $count = $this->db->getTable('VocabularyCommonTerms')->getRowCount();
        return "Progress: $count records written";
    }
}