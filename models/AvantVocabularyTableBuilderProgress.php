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
            return "$count records written";
        }
        else
        {
            return 'Working on ' . $tableName;
        }
    }
}