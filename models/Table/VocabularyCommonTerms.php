<?php

class Table_VocabularyCommonTerms extends Omeka_Db_Table
{
    public function getNomenclatureId($commonTerm)
    {
        $select = $this->getSelect();
        $select->where("vocabulary_common_terms.common_term = '$commonTerm'");
        $mapping = $this->fetchObject($select);
        return $mapping;
    }

    public function getRowCount()
    {
        $select = $this->getSelect();
        $select->columns('COUNT(*) AS count');
        $result = $this->fetchObject($select);
        return $result->count;
    }
}