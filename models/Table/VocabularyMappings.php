<?php

class Table_VocabularyMappings extends Omeka_Db_Table
{
    public function getVocabularyMapping($localText)
    {
        $select = $this->getSelect();
        $select->where("vocabulary_mappings.local_term = '$localText'");
        $mapping = $this->fetchObject($select);
        return $mapping;
    }
}