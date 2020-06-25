<?php

class Table_VocabularyLocalTerms extends Omeka_Db_Table
{
    public function getLocalTermRecordsByCommonTermId($commonTermId)
    {
        $select = $this->getSelect();
        $select->where("common_term_id = $commonTermId");
        $results = $this->fetchObjects($select);
        return $results;
    }

    public function getLocalTermRecordsByLocalTerm($localTerm)
    {
        // This method gets all local terms of any kind that match a term.
        $localTerm = AvantCommon::escapeQuotes($localTerm);
        $select = $this->getSelect();
        $select->where("local_term = '$localTerm'");
        $results = $this->fetchObjects($select);
        return $results;
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
        // This method returns data from the local terms table joined with the common terms table
        // so that the results include the text of the common term for the local term's common term Id.

        $db = get_db();
        $select = $this->getSelect();
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->columns(array(
            'vocabulary_local_terms.id',
            'vocabulary_local_terms.local_term',
            'vocabulary_local_terms.common_term_id',
            'vocabulary_common_terms.common_term'
        ));
        $select->where("vocabulary_local_terms.kind = $kind");

        // Join with the Common Terms table where the common_term_id is the same.
        $select->joinLeft(
            array('vocabulary_common_terms' => "{$db->prefix}vocabulary_common_terms"),
            'vocabulary_local_terms.common_term_id = vocabulary_common_terms.common_term_id',
            array('common_term')
        );

        $select->order('order');

        try
        {
            // Use fetchAll instead of fetchObjects to get only the values of the local_term and common_term columns.
            $results = $db->query($select)->fetchAll();
        }
        catch (Exception $e)
        {
            return array();
        }

        return $results;
    }

    public function getLocalTerms($kind)
    {
        $terms = array();
        $mappings = $this->getLocalToCommonTermMap($kind);

        // Return just the local terms.
        foreach ($mappings as $mapping)
        {
            $term = $mapping['local_term'];
            if (empty($term))
                $term = $mapping['common_term'];
            $terms[] = $term;
        }
        return $terms;
    }

    public function getLocalToCommonTermMap($kind)
    {
        // This method joins the local and common terms table to return just the local and common term from each.
        // so that the results include the text of the common term for the local term's common term Id.
        $db = get_db();
        $select = $this->getSelect();
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->columns(array('local_term', 'vocabulary_common_terms.common_term'));
        $select->where("vocabulary_local_terms.kind = $kind");

        // Join with the Common Terms table where its common_term_id is the same as the local term common_term_id.
        $select->joinLeft(
            array('vocabulary_common_terms' => "{$db->prefix}vocabulary_common_terms"),
            'vocabulary_local_terms.common_term_id = vocabulary_common_terms.common_term_id',
            array('common_term')
        );

        $select->order('order');

        // Use fetchAll instead of fetchObjects to get only the values of the local_term and common_term columns.
        $results = $db->query($select)->fetchAll();


        // Find local terms that are the same as common terms and set their text to the common term.
        // This is necessary because the local terms table only contains local term text for mapped
        // local terms where the local term text is different than the common term text.
        foreach ($results as $index => $result)
        {
            if (empty($result['local_term']))
                $results[$index]['local_term'] = $result['common_term'];
        }

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

    public function getRowCount()
    {
        $select = $this->getSelect();
        $select->columns('COUNT(*) AS count');
        $result = $this->fetchObject($select);
        return $result->count;
    }

    public function localTermExists($kind, $localTerm)
    {
        $localTerm = AvantCommon::escapeQuotes($localTerm);
        $select = $this->getSelect();
        $select->columns('COUNT(*) AS count');
        $select->where("kind = $kind AND LOWER(`local_term`) = LOWER('$localTerm')");
        $result = $this->fetchObject($select);
        return $result->count >= 1;
    }
}