<?php

class AvantVocabularyTableBuilderProgress
{
    protected $db;

    public function __construct()
    {
        $this->db = get_db();
    }

    public function handleAjaxRequest($tableName)
    {
        $response = $this->getProgress($tableName);
        echo json_encode($response);
    }

    protected function getProgress($tableName)
    {
        if ($tableName == 'common')
        {
            $count = $this->db->getTable('VocabularyCommonTerms')->getRowCount();
            return "$count records written to common terms table";
        }
        else
        {
            $count = $this->db->getTable('VocabularyLocalTerms')->getRowCount();
            return "$count records written to local terms table";
        }
    }
}