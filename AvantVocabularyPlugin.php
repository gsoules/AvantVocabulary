<?php

define('VOCABULARY_PLUGIN_DIR', dirname(__FILE__));

class AvantVocabularyPlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_hooks = array(
        'admin_head',
        'config',
        'config_form',
        'define_routes',
        'install',
        'public_head',
        'uninstall',
        'upgrade'
    );

    protected $_filters = array(
        'admin_navigation_main'
    );

    public function filterAdminNavigationMain($nav)
    {
        $user = current_user();
        if ($user->role == 'admin' || $user->role == 'super')
        {
            $nav[] = array(
                'label' => __('Vocabulary Editor'),
                'uri' => url('vocabulary/terms')
            );
        }
        return $nav;
    }

    public function hookAdminHead($args)
    {
        queue_css_file('avantvocabulary');
        queue_css_file('avantvocabulary-tree');
    }

    public function hookConfig()
    {
        VocabularyConfig::saveConfiguration();
    }

    public function hookConfigForm()
    {
        require dirname(__FILE__) . '/config_form.php';
    }

    public function hookDefineRoutes($args)
    {
        $args['router']->addConfig(new Zend_Config_Ini(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'routes.ini', 'routes'));
    }

    public function hookInstall()
    {
        VocabularyTableFactory::createVocabularyCommonTermsTable();
        VocabularyTableFactory::createVocabularySiteTermsTable();
    }

    public function hookPublicHead($args)
    {
        queue_css_file('avantvocabulary-tree');
    }

    public function hookUninstall()
    {
        $deleteTables = intval(get_option(VocabularyConfig::OPTION_DELETE_VOCABULARY_TABLE))== 1;
        if (!$deleteTables)
            return;

        VocabularyTableFactory::dropVocabularyCommonTermsTable();
        VocabularyTableFactory::dropVocabularySiteTermsTable();

        VocabularyConfig::removeConfiguration();
    }

    public function hookUpgrade($args)
    {
        return;
    }
}
