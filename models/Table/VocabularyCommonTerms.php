<?php

class Table_VocabularyCommonTerms extends Omeka_Db_Table
{
    public function commonTermCount($kind)
    {
        $whereKind = $this->getWhereKind($kind);

        $select = $this->getSelect();
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->columns('COUNT(*) AS count');
        $select->where($whereKind);
        $result = $this->fetchObject($select);
        return $result->count;
    }

    public function getAllCommonTermRecordsForKind($kind)
    {
        $whereKind = $this->getWhereKind($kind);

        try
        {
            $select = $this->getSelect();
            $select->where($whereKind);
            $select->order('common_term');
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
        $commonTerm = AvantCommon::escapeQuotes($commonTerm);

        $whereKind = $this->getWhereKind($kind);

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

    public function getCommonTermSuggestions($kind, $term)
    {
        $whereKind = $this->getWhereKind($kind);
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

    protected function getWhereKind($kind)
    {
        // This method treats kind as a bit mask. If either the Type or the Subject bit is set, it creates
        // part of a SQL Where clause that tests kind against the single bit passed in (0001 or 0010) and
        // and also tests against both bits being set (0011). This somewhat cumbersome approach addresses
        // the fact that the Common Facets vocabulary contains thousands of terms that apply to both
        // Type and Subject elements. Rather than duplicate them in the common terms table so that each has
        // its own kind, they only appear once, but their kind is VOCABULARY_TERM_KIND_TYPE_AND_SUBJECT.

        if (AvantVocabulary::kindIsTypeOrSubject($kind))
        {
            $typeOrSubject = AvantVocabulary::VOCABULARY_TERM_KIND_TYPE_AND_SUBJECT;
            $whereKind = "(kind = $kind OR kind = $typeOrSubject)";
        }
        else
        {
            $whereKind = "kind = $kind";
        }
        return $whereKind;
    }
}