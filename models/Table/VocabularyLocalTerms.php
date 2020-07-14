<?php

class Table_VocabularyLocalTerms extends Omeka_Db_Table
{
    public function getLocalTermItems($kind)
    {
        // A local term "item" is an array containing all the information about a local term.
        // This method merges data from the local terms table and the common terms table so
        // the results include the text of the common term for the local term's common term Id.

        $db = get_db();
        $select = $this->getSelect();
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->columns(array(
            'vocabulary_local_terms.id',
            'vocabulary_local_terms.local_term',
            'vocabulary_local_terms.common_term_id'
        ));
        $select->where("vocabulary_local_terms.kind = $kind");

        // Join with the Common Terms table where the common_term_id is the same.
        $select->joinLeft(
            array('vocabulary_common_terms' => "{$db->prefix}vocabulary_common_terms"),
            "vocabulary_local_terms.common_term_id = vocabulary_common_terms.common_term_id AND vocabulary_common_terms.kind =  $kind",
            array('common_term')
        );

        try
        {
            // Use fetchAll instead of fetchObjects to get only the values of the local_term and common_term columns.
            $results = $db->query($select)->fetchAll();
        }
        catch (Exception $e)
        {
            return array();
        }

        // Find local terms that are the same as common terms and set the default term to the common term.
        // This is necessary because the local terms table does not contain a local_term value when the
        // local term is the same as the common term.
        foreach ($results as $index => $result)
        {
            $results[$index]['default_term'] = $result['local_term'] ? $result['local_term'] : $result['common_term'];
        }

        // Sort the results by the default term.
        usort($results, function($a, $b){ return strcmp($a['default_term'], $b['default_term']); });

        return $results;
    }

    public function getDuplicateLocalTermRecord($localTermRecord)
    {
        // This method looks for a local term record that matches the one passed as a parameter.
        $select = $this->getSelect();
        $select->where("kind = $localTermRecord->kind AND LOWER(`local_term`) = LOWER('$localTermRecord->local_term') AND common_term_id = $localTermRecord->common_term_id");
        $result = $this->fetchObject($select);
        return $result;
    }

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

    public function getLocalTerms($kind)
    {
        $terms = array();
        $mappings = $this->getLocalTermItems($kind);

        // Return just the default term which is the local term if it exists, otherwise it's the common term.
        foreach ($mappings as $mapping)
        {
            $terms[] = $mapping['default_term'];
        }

        return $terms;
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