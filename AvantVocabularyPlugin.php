<?php

class AvantVocabularyPlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_hooks = array(
        'admin_head',
        'config',
        'config_form',
        'define_routes',
        'install',
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
                'label' => __('Vocabulary'),
                'uri' => url('vocabulary/terms')
            );
        }
        return $nav;
    }

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

    public function hookDefineRoutes($args)
    {
        $args['router']->addConfig(new Zend_Config_Ini(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'routes.ini', 'routes'));
    }

    public function hookInstall()
    {
        VocabularyTableFactory::createVocabularyCommonTermsTable();
        VocabularyTableFactory::createVocabularyLocalTermsTable();
    }

    public function hookUninstall()
    {
        $deleteTables = intval(get_option(VocabularyConfig::OPTION_DELETE_VOCABULARY_TABLE))== 1;
        if (!$deleteTables)
            return;

        VocabularyTableFactory::dropVocabularyCommonTermsTable();
        VocabularyTableFactory::dropVocabularyLocalTermsTable();

        VocabularyConfig::removeConfiguration();
    }

    public function hookUpgrade($args)
    {
        return;
    }
}
