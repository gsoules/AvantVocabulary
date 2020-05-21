<?php

class AvantVocabularyTableBuilderProgress
{
    protected $db;

    public function __construct()
    {
        $this->db = get_db();
    }

    public function handleAjaxRequest($flavor)
    {
        $flavor = isset($_POST['flavor']) ? $_POST['flavor'] : '';
        $response = $this->getProgress($flavor);
        echo json_encode($response);
    }

    protected function getProgress($flavor)
    {
        if ($flavor == 'build-common')
        {
            $count = $this->db->getTable('VocabularyCommonTerms')->getRowCount();
            return "Progress: $count records written";
        }
        else
        {
            return 'Working on ' . $flavor;
        }
    }
}