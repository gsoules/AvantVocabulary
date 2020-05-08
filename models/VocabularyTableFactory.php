<?php

class VocabularyTableFactory
{
    public static function createVocabularyCommonTermsTable()
    {
        $db = get_db();

        $sql = "
        CREATE TABLE IF NOT EXISTS `{$db->prefix}vocabulary_common_terms` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `nomenclature_id` int(10) unsigned NOT NULL,
            `common_term` varchar(512) DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

        $db->query($sql);
    }

    public static function createVocabularyLocalTermsTable()
    {
        $db = get_db();

        $sql = "
        CREATE TABLE IF NOT EXISTS `{$db->prefix}vocabulary_local_terms` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `element_id` int(10) unsigned NOT NULL,
            `local_term` varchar(512) DEFAULT NULL,
            `mapped_term` varchar(512) DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

        $db->query($sql);
    }

    public static function createVocabularyMappingsTable()
    {
        $db = get_db();

        $sql = "
        CREATE TABLE IF NOT EXISTS `{$db->prefix}vocabulary_mappings` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `element_id` int(10) unsigned NOT NULL,
            `local_term` varchar(512) NOT NULL,
            `mapping` int(10) unsigned NOT NULL,
            `common_term` varchar(512) DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

        $db->query($sql);
    }

    public static function dropVocabularyCommonTermsTable()
    {
        $db = get_db();
        $sql = "DROP TABLE IF EXISTS `{$db->prefix}vocabulary_common_terms`";
        $db->query($sql);
    }

    public static function dropVocabularyLocalTermsTable()
    {
        $db = get_db();
        $sql = "DROP TABLE IF EXISTS `{$db->prefix}vocabulary_local_terms`";
        $db->query($sql);
    }

    public static function dropVocabularyMappingsTable()
    {
        $db = get_db();
        $sql = "DROP TABLE IF EXISTS `{$db->prefix}vocabulary_mappings`";
        $db->query($sql);
    }

    public static function initializeVocabularyMappingsTable()
    {
        $mapping = new VocabularyMappings();
        $mapping['element_id'] = 3456;
        $mapping['local_term'] = 'fubar';
        $mapping['mapping'] = 0;
        $mapping['common_term'] = 'foo bar';
        $mapping->save();
    }
}