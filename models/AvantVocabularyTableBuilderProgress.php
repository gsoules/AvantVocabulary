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
            return "$count " . __('records written to common terms table');
        }
        else if ($tableName == 'local')
        {
            $count = $this->db->getTable('VocabularyLocalTerms')->getRowCount();
            return "$count " . __('ecords written to local terms table');
        }
        else
        {
            $progressFileName = AvantVocabulary::progressFileName();
            if (file_exists($progressFileName))
                $message = file_get_contents($progressFileName);
            else
                $message = '';
            return $message;
        }
    }
}