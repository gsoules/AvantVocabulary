<?php

class VocabularyTableFactory
{
    public static function createVocabularyMappingsTable()
    {
        $db = get_db();

        $sql = "
        CREATE TABLE IF NOT EXISTS `{$db->prefix}vocabulary_mappings` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `nomenclature_id` int(10) unsigned NOT NULL,
            `common_term` varchar(512) DEFAULT NULL,
            `local_term` varchar(512) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

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
        $mapping['nomenclature_id'] = 3456;
        $mapping['common_term'] = 'foo';
        $mapping['local_term'] = 'bar';
        $mapping->save();
    }
}