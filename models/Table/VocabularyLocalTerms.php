<?php

class Table_VocabularyLocalTerms extends Omeka_Db_Table
{
    public function getCommonTermForLocalTerm($kind, $localTerm)
    {
        $select = $this->getSelect();
        $select->where("kind = $kind AND local_term = '$localTerm'");
        $result = $this->fetchObject($select);
        if ($result)
            return $result->common_term;
        else
            return '';
    }

    public function getLocalTermRecord($kind, $localTerm)
    {
        $select = $this->getSelect();
        $select->where("kind = $kind AND local_term = '$localTerm'");
        $result = $this->fetchObject($select);
        return $result;
    }

    public function getLocalTermRecordById($id)
    {
        $select = $this->getSelect();
        $select->where("id = $id");
        $result = $this->fetchObject($select);
        return $result;
    }

    public function getLocalTermRecords($kind)
    {
        $select = $this->getSelect();
        $select->where("kind = $kind");
        $select->order('local_term');
        $results = $this->fetchObjects($select);
        return $results;
    }
}