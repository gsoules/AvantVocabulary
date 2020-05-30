<?php

class Table_VocabularyCommonTerms extends Omeka_Db_Table
{
    public function commonTermCount($kind)
    {
        $whereKind = AvantVocabulary::getWhereKind($kind);

        $select = $this->getSelect();
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->columns('COUNT(*) AS count');
        $select->where($whereKind);
        $result = $this->fetchObject($select);
        return $result->count;
    }

    public function getAllCommonTermRecordsForKind($kind)
    {
        $whereKind = AvantVocabulary::getWhereKind($kind);

        try
        {
            $select = $this->getSelect();
            $select->where($whereKind);
            $result = $this->fetchObjects($select);
        }
        catch (Exception $e)
        {
            $result = null;
        }

        return $result;
    }

    public function getCommonTermRecordByCommonTerm($kind, $commonTerm)
    {
        $whereKind = AvantVocabulary::getWhereKind($kind);

        try
        {
            $select = $this->getSelect();
            $select->where("$whereKind AND common_term = '$commonTerm'");
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
        $whereKind = AvantVocabulary::getWhereKind($kind);
        $query = $this->getQueryForLike($term);

        try
        {
            $select = $this->getSelect();
            $select->where("$whereKind AND $query");
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