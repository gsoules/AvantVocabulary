<?php

class Table_VocabularyLocalTerms extends Omeka_Db_Table
{
    public function getMappedTerm($kind, $localTerm)
    {
        $select = $this->getSelect();
        $select->where("vocabulary_local_terms.kind = $kind AND vocabulary_local_terms.local_term = '$localTerm'");
        $mapping = $this->fetchObject($select);
        if ($mapping)
            return $mapping->mapped_term;
        else
            return '';
    }
}