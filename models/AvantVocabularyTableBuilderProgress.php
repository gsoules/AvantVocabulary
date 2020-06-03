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
            $count = number_format($count, 0, '.', ',');
            $message = $count . __(' records written to the common terms table');
        }
        else if ($tableName == 'local')
        {
            $count = $this->db->getTable('VocabularyLocalTerms')->getRowCount();
            $count = number_format($count, 0, '.', ',');
            $message = $count . __(' records written to the local terms table');
        }
        else
        {
            $progressFileName = AvantVocabulary::progressFileName();
            $message = 'Updating items in database: ';
            if (file_exists($progressFileName))
                $progress = file_get_contents($progressFileName);
            else
                $progress = '0%';
            $message .= $progress;
        }
        return $message;
    }
}