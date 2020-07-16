<?php

class VocabularyTableFactory
{
    public static function createVocabularyCommonTermsTable()
    {
        $db = get_db();

        $sql = "
        CREATE TABLE IF NOT EXISTS `{$db->prefix}vocabulary_common_terms` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `kind` int(10) unsigned NOT NULL,
            `common_term` varchar(512) DEFAULT NULL,
            `leaf` varchar(128) DEFAULT NULL,
            `common_term_id` int(10) unsigned NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

        $db->query($sql);
    }

    public static function createVocabularySiteTermsTable()
    {
        $db = get_db();

        $sql = "
        CREATE TABLE IF NOT EXISTS `{$db->prefix}vocabulary_site_terms` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `kind` int(10) unsigned NOT NULL,
            `site_term` varchar(512) DEFAULT NULL,
            `common_term_id` int(10) unsigned NOT NULL,
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

    public static function dropVocabularySiteTermsTable()
    {
        $db = get_db();
        $sql = "DROP TABLE IF EXISTS `{$db->prefix}vocabulary_site_terms`";
        $db->query($sql);
    }
}