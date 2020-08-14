<?php

class Table_VocabularyCommonTerms extends Omeka_Db_Table
{
    public function commonTermCount($kind)
    {
        $select = $this->getSelect();
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->columns('COUNT(*) AS count');
        $select->where("kind = $kind");

        try
        {
            $result = $this->fetchObject($select);
            return $result->count;
        }
        catch (Exception $e)
        {
            return 0;
        }
    }

    public function getAllCommonTermRecordsForKind($kind)
    {
        try
        {
            $select = $this->getSelect();
            $select->where("kind = $kind");
            $select->order('common_term');
            $result = $this->fetchObjects($select);
        }
        catch (Exception $e)
        {
            $result = null;
        }

        return $result;
    }

    public function getAllCommonTermsForKind($kind)
    {
        $terms = array();
        $db = get_db();
        $select = $this->getSelect();
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->columns(array('vocabulary_common_terms.common_term'));
        $select->where("vocabulary_common_terms.kind = $kind");

        try
        {
            // Use fetchAll instead of fetchObjects to get only the values of the site_term and common_term columns.
            $results = $db->query($select)->fetchAll();
        }
        catch (Exception $e)
        {
            return $terms();
        }

        foreach ($results as $result)
            $terms[] = $result['common_term'];

        return $terms;
    }

    public function getCommonTermRecordByCommonTerm($kind, $commonTerm)
    {
        $commonTerm = AvantCommon::escapeQuotes($commonTerm);

        try
        {
            // Use BINARY in the WHERE clause to force case-sensitive comparison.
            $select = $this->getSelect();
            $select->where("kind = $kind AND BINARY common_term = '$commonTerm'");
            $result = $this->fetchObject($select);
        }
        catch (Exception $e)
        {
            $result = null;
        }

        return $result;
    }

    public function getCommonTermRecordByCommonTermId($commonTermId)
    {
        try
        {
            $select = $this->getSelect();
            $select->where("common_term_id = '$commonTermId'");
            $result = $this->fetchObject($select);
        }
        catch (Exception $e)
        {
            $result = null;
        }

        return $result;
    }

    public function getCommonTermRecordByKindAndCommonTermId($kind, $commonTermId)
    {
        try
        {
            $select = $this->getSelect();
            $select->where("common_term_id = '$commonTermId' AND kind = '$kind'");
            $result = $this->fetchObject($select);
        }
        catch (Exception $e)
        {
            $result = null;
        }

        return $result;
    }

    public function getCommonTermRecordByLeaf($kind, $leaf)
    {
        try
        {
            $select = $this->getSelect();
            $select->where("leaf = '$leaf' AND kind = '$kind'");
            $result = $this->fetchObject($select);
        }
        catch (Exception $e)
        {
            $result = null;
        }

        return $result;
    }

    public function getCommonTermSuggestions($kind, $term)
    {
        $query = $this->getQueryForLike($term);

        try
        {
            $select = $this->getSelect();
            $select->where("kind = $kind AND $query");
            $result = $this->fetchObjects($select);
        }
        catch (Exception $e)
        {
            $result = null;
        }

        return $result;
    }

    protected function getKeywords($term)
    {
        return array_map('trim', explode(' ', strtolower($term)));
    }

    protected function getQueryForLike($term)
    {
        $term = AvantCommon::escapeQuotes($term);

        $keywords = $this->getKeywords($term);
        $query = '';
        foreach ($keywords as $word)
        {
            if (!empty($query))
            {
                $query .= ' AND ';
            }
            $query .= "common_term LIKE '%$word%'";
        }
        return $query;
    }

    public function getRowCount()
    {
        $select = $this->getSelect();
        $select->columns('COUNT(*) AS count');
        $result = $this->fetchObject($select);
        return $result->count;
    }
}