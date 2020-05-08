<?php

class Table_VocabularyLocalTerms extends Omeka_Db_Table
{
    public function getMappedTerm($elementId, $localTerm)
    {
        $select = $this->getSelect();
        $select->where("vocabulary_local_terms.element_id = $elementId AND vocabulary_local_terms.local_term = '$localTerm'");
        $mapping = $this->fetchObject($select);
        if ($mapping)
            return $mapping->mapped_term;
        else
            return '';
    }
}