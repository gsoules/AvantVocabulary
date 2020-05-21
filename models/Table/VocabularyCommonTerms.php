<?php

class Table_VocabularyCommonTerms extends Omeka_Db_Table
{
    public function commonTermExists($kind, $commonTerm)
    {
        $whereKind = AvantVocabulary::getWhereKind($kind);

        $select = $this->getSelect();
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->columns('COUNT(*) AS count');
        $select->where("$whereKind AND common_term = '$commonTerm'");
        $result = $this->fetchObject($select);
        return $result->count == 1;
    }

    public function getCommonTermRecord($kind, $commonTerm)
    {
        $whereKind = AvantVocabulary::getWhereKind($kind);

        $select = $this->getSelect();
        $select->where("$whereKind AND common_term = '$commonTerm'");
        $result = $this->fetchObject($select);
        return $result;
    }

    public function getCommonTermRecords($kind)
    {
        $whereKind = AvantVocabulary::getWhereKind($kind);
        $select = $this->getSelect();
        $select->where($whereKind);
        $results = $this->fetchObjects($select);
        return $results;
    }

    public function getRowCount()
    {
        $select = $this->getSelect();
        $select->columns('COUNT(*) AS count');
        $result = $this->fetchObject($select);
        return $result->count;
    }
}