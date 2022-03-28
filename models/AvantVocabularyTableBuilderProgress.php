<?php

class AvantVocabularyTableBuilderProgress
{
    protected $db;

    public function __construct()
    {
        $this->db = get_db();
    }

    public function handleAjaxProgressRequest($tableName)
    {
        $response = $this->getProgress($tableName);
        echo json_encode($response);
    }

    protected function getProgress($tableName)
    {
        // Get the flag that indicates if it's okay to report how many records have been written.
        // This is necessary to ensure that the record count is not fetched until after the table
        // that's being rebuilt has been emptied. A database option is used for this purpose instead
        // of a session variable because this method is invoked via AJAX and so the session is not
        // the same as the session that sets and clears the flag.
        $rebuildInProgress = (bool)get_option(AvantVocabularyTableBuilder::OPTION_REPORT_PROGRESS) == true;

        try
        {
            if ($tableName == 'common')
            {
                if ($rebuildInProgress)
                {
                    $count = $this->db->getTable('VocabularyCommonTerms')->getRowCount();
                    $count = number_format($count, 0, '.', ',');
                    $message = $count . __(' records written to the common terms table');
                }
                else
                {
                    $message = __('Preparing to rebuild the common terms table');
                }
            }
            else
            {
                if ($tableName == 'local')
                {
                    if ($rebuildInProgress)
                    {
                        $count = $this->db->getTable('VocabularySiteTerms')->getRowCount();
                        $count = number_format($count, 0, '.', ',');
                        $message = $count . __(' records written to the site terms table');
                    }
                    else
                    {
                        $message = __('Preparing to rebuild the site terms table');
                    }
                }
                else
                {
                    $progressFileName = AvantVocabulary::progressFileName();
                    $message = 'Updating items in database: ';
                    if (file_exists($progressFileName))
                    {
                        $progress = file_get_contents($progressFileName);
                    }
                    else
                    {
                        $progress = '0%';
                    }
                    $message .= $progress;
                }
            }
        }
        catch (Exception $e)
        {
            // This should not happen under normal circumstances, but it's possible for this method to get called
            // in between the time when a table is dropped and when it gets created again. In that situation,
            // an exception would occur when attempting to read the table.
            $message = "Unable to report progress: " . $e->getMessage();
        }

        return $message;
    }
}