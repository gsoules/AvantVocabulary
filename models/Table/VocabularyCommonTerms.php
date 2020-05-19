<?php

class Table_VocabularyCommonTerms extends Omeka_Db_Table
{
    public function commonTermExists($kind, $commonTerm)
    {
        $select = $this->getSelect();
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->columns('COUNT(*) AS count');
        $select->where("vocabulary_common_terms.kind = $kind AND vocabulary_common_terms.common_term = '$commonTerm'");
        $result = $this->fetchObject($select);
        return $result->count == 1;
    }

    public function getRowCount()
    {
        $select = $this->getSelect();
        $select->columns('COUNT(*) AS count');
        $result = $this->fetchObject($select);
        return $result->count;
    }
}