<?php

class Table_VocabularyLocalTerms extends Omeka_Db_Table
{
    public function getCommonTermForLocalTerm($kind, $localTerm)
    {
        // This method returns a record from the local terms table joined with one from the common terms table
        // so that the result includes the text of the common term for the local term's common term Id.
        $db = get_db();
        $select = $this->getSelect();
        $select->where("vocabulary_local_terms.kind = $kind AND vocabulary_local_terms.local_term = '$localTerm'");

        // Join with the Common Terms table where the common_term_id is the same.
        $select->joinLeft(
            array('vocabulary_common_terms' => "{$db->prefix}vocabulary_common_terms"),
            'vocabulary_local_terms.common_term_id = vocabulary_common_terms.common_term_id',
            array('common_term')
        );

        $result = $this->fetchObject($select);
        return $result->common_term;
    }

    public function getLocalTermRecord($kind, $localTerm)
    {
        $select = $this->getSelect();
        $select->where("kind = $kind AND LOWER(`local_term`) = LOWER('$localTerm')");
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

    public function getLocalTermItemsInOrder($kind)
    {
        // This method returns the records from the local terms table joined with the common terms table
        // so that the results include the text of the common term for the local term's common term Id.

        $db = get_db();
        $select = $this->getSelect();
        $select->where("vocabulary_local_terms.kind = $kind");

        // Join with the Common Terms table where the common_term_id is the same.
        $select->joinLeft(
            array('vocabulary_common_terms' => "{$db->prefix}vocabulary_common_terms"),
            'vocabulary_local_terms.common_term_id = vocabulary_common_terms.common_term_id',
            array('common_term')
        );

        $select->order('order');

        $results = $this->fetchObjects($select);
        return $results;
    }

    public function getLocalTermRecordsInOrder($kind)
    {
        $select = $this->getSelect();
        $select->where("kind = $kind");
        $select->order('order');
        $results = $this->fetchObjects($select);
        return $results;
    }
}