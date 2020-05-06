<?php

class AvantVocabularyPlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_hooks = array(
        'admin_head',
        'config',
        'config_form',
        'install',
        'uninstall',
        'upgrade'
 );

    public function hookAdminHead($args)
    {
        queue_css_file('avantvocabulary');
    }

    public function hookConfig()
    {
        VocabularyConfig::saveConfiguration();
    }

    public function hookConfigForm()
    {
        require dirname(__FILE__) . '/config_form.php';
    }

    public function hookInstall()
    {
        VocabularyTableFactory::createVocabularyMappingsTable();
        VocabularyTableFactory::initializeVocabularyMappingsTable();
    }

    public function hookUninstall()
    {
        $deleteTables = intval(get_option(VocabularyConfig::OPTION_DELETE_VOCABULARY_TABLE))== 1;
        if (!$deleteTables)
            return;

        VocabularyTableFactory::dropVocabularyMappingsTable();
        VocabularyConfig::removeConfiguration();
    }

    public function hookUpgrade($args)
    {
        return;
    }
}
