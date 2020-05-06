<?php

define('CONFIG_LABEL_DELETE_VOCABULARY_TABLE', __('Delete Vocabulary Table'));

class VocabularyConfig extends ConfigOptions
{
    const OPTION_DELETE_VOCABULARY_TABLE = 'avantvocabulary_delete_table';

    public static function removeConfiguration()
    {
        delete_option(self::OPTION_DELETE_VOCABULARY_TABLE);
    }

    public static function saveConfiguration()
    {
        set_option(self::OPTION_DELETE_VOCABULARY_TABLE, (int)(boolean)$_POST[self::OPTION_DELETE_VOCABULARY_TABLE]);
    }
}
