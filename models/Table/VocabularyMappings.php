<?php

class Table_VocabularyMappings extends Omeka_Db_Table
{
    public function getMapping($localTerm)
    {
        $select = $this->getSelect();
        $select->where("vocabulary_mappings.local_term = '$localTerm'");
        $mapping = $this->fetchObject($select);
        return $mapping;
    }

    public function getAllMappings()
    {
        $select = $this->getSelect();
        $mappings = $this->fetchObjects($select);
        return $mappings;
    }
}