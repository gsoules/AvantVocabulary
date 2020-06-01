<?php

class Table_VocabularyLocalTerms extends Omeka_Db_Table
{
    public function getLocalTermRecord($kind, $localTerm)
    {
        $localTerm = addslashes($localTerm);

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

    public function getLocalToCommonTermMap($kind)
    {
        // This method joins the local and common terms table to return just the local and common term from each.        // so that the results include the text of the common term for the local term's common term Id.
        $db = get_db();
        $select = $this->getSelect();
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->columns(array('local_term', 'vocabulary_common_terms.common_term'));
        $select->where("vocabulary_local_terms.kind = $kind");

        // Join with the Common Terms table where the common_term_id is the same.
        $select->joinLeft(
            array('vocabulary_common_terms' => "{$db->prefix}vocabulary_common_terms"),
            'vocabulary_local_terms.common_term_id = vocabulary_common_terms.common_term_id',
            array('common_term')
        );

        // Use fetchAll instead of fetchObjects to get only the values of the local_term and common_term columns.
        $results = $db->query($select)->fetchAll();
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