<?php

class Table_VocabularyLocalTerms extends Omeka_Db_Table
{
    public function getCommonTermForLocalTerm($kind, $localTerm)
    {
        $select = $this->getSelect();
        $select->where("kind = $kind AND local_term = '$localTerm'");
        $mapping = $this->fetchObject($select);
        if ($mapping)
            return $mapping->common_term;
        else
            return '';
    }
}