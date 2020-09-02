<?php
$view = get_view();

$deleteTable = intval(get_option(VocabularyConfig::OPTION_DELETE_VOCABULARY_TABLE)) != 0;
?>

<div class="plugin-help learn-more">
    <a href="https://digitalarchive.us/plugins/avantvocabulary/" target="_blank">Learn about this plugin</a>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_DELETE_VOCABULARY_TABLE; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __(" WARNING: Checking this box will cause all common vocabulary data to be
        permanently deleted if you uninstall this plugin. 
        Click <a href=\"https://digitalarchive.us/plugins/avantvocabulary/\" target=\"_blank\" style=\"color:red;\">
        here</a> to read the documentation for the Delete Tables option before unchecking the box."); ?></p>
        <?php echo $view->formCheckbox(VocabularyConfig::OPTION_DELETE_VOCABULARY_TABLE, true, array('checked' => $deleteTable)); ?>
    </div>
</div>

