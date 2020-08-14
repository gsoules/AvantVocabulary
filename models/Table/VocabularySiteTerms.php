<?php

class Table_VocabularySiteTerms extends Omeka_Db_Table
{
    public function getDuplicateSiteTermRecord($siteTermRecord)
    {
        // This method looks for a site term record that matches the one passed as a parameter.
        $select = $this->getSelect();
        $select->where("kind = $siteTermRecord->kind AND LOWER(`site_term`) = LOWER('$siteTermRecord->site_term') AND common_term_id = $siteTermRecord->common_term_id");
        $result = $this->fetchObject($select);
        return $result;
    }

    public function getSiteTermItems($kind)
    {
        // A site term "item" is an array containing all the information about a site term.
        // This method merges data from the site terms table and the common terms table so
        // the results include the text of the common term for the site term's common term Id.

        $db = get_db();
        $select = $this->getSelect();
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->columns(array(
            'vocabulary_site_terms.id',
            'vocabulary_site_terms.site_term',
            'vocabulary_site_terms.common_term_id'
        ));
        $select->where("vocabulary_site_terms.kind = $kind");

        // Join with the Common Terms table where the common_term_id is the same.
        $select->joinLeft(
            array('vocabulary_common_terms' => "{$db->prefix}vocabulary_common_terms"),
            "vocabulary_site_terms.common_term_id = vocabulary_common_terms.common_term_id AND vocabulary_common_terms.kind =  $kind",
            array('common_term')
        );

        try
        {
            // Use fetchAll instead of fetchObjects to get only the values of the site_term and common_term columns.
            $results = $db->query($select)->fetchAll();
        }
        catch (Exception $e)
        {
            return array();
        }

        // Find site terms that are the same as common terms and set the default term to the common term.
        // This is necessary because the site terms table does not contain a site_term value when the
        // site term is the same as the common term.
        foreach ($results as $index => $result)
        {
            $results[$index]['default_term'] = $result['site_term'] ? $result['site_term'] : $result['common_term'];
        }

        // Sort the results by the default term.
        usort($results, function($a, $b){ return strcmp(strtolower($a['default_term']), strtolower($b['default_term'])); });

        return $results;
    }

    public function getSiteTermRecordsByCommonTermId($commonTermId)
    {
        $select = $this->getSelect();
        $select->where("common_term_id = $commonTermId");
        $results = $this->fetchObjects($select);
        return $results;
    }

    public function getSiteTermRecordById($id)
    {
        $select = $this->getSelect();
        $select->where("id = $id");
        $result = $this->fetchObject($select);
        return $result;
    }

    public function getSiteTermRecordByKindAndSiteTerm($kind, $siteTerm)
    {
        $siteTerm = AvantCommon::escapeQuotes($siteTerm);
        $select = $this->getSelect();
        $select->where("kind = $kind AND site_term = '$siteTerm'");
        $result = $this->fetchObject($select);
        return $result;
    }

    public function getSiteTerms($kind)
    {
        $terms = array();
        $mappings = $this->getSiteTermItems($kind);

        // Return just the default term which is the site term if it exists, otherwise it's the common term.
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

    public function siteTermExists($kind, $siteTerm)
    {
        $siteTerm = AvantCommon::escapeQuotes($siteTerm);
        $select = $this->getSelect();
        $select->columns('COUNT(*) AS count');
        $select->where("kind = $kind AND LOWER(`site_term`) = LOWER('$siteTerm')");
        $result = $this->fetchObject($select);
        return $result->count >= 1;
    }
}