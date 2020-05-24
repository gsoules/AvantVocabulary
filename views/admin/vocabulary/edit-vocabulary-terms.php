<?php

$avantVocabularyTableBuilder = new AvantVocabularyTableBuilder();
$avantVocabularyTableBuilderProgress = new AvantVocabularyTableBuilderProgress();

if (AvantCommon::isAjaxRequest())
{
    // This page just got called to handle an asynchronous Ajax request. Execute the request synchronously,
    // waiting here until it completes (when handleAjaxRequest returns). When ths page returns,  the request's
    // success function will execute in the browser (or its error function if something went wrong).

    $term = isset($_GET['term']) ? $_GET['term'] : '';
    if ($term)
    {
        $kind = AvantVocabulary::VOCABULARY_TERM_KIND_TYPE;
        $commonTermRecords = get_db()->getTable('VocabularyCommonTerms')->getCommonTermSuggestions($kind, $term);
        $result = array();
        foreach ($commonTermRecords as $commonTermRecord)
        {
            $commonTerm = $commonTermRecord->common_term;
            $result[] = $commonTerm;
        }
        echo json_encode($result);
        return;
    }

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $tableName = isset($_POST['table_name']) ? $_POST['table_name'] : '';

    if ($action == 'progress')
    {
        $avantVocabularyTableBuilderProgress->handleAjaxRequest($tableName);
    }
    else
    {
        // Give the request plenty of time to execute since it can take several minutes.
        ini_set('max_execution_time', 10 * 60);
        $avantVocabularyTableBuilder->handleAjaxRequest($tableName);
    }

    // Leave so that the code to display the page won't get executed.
    return;
}

$pageTitle = __('Vocabulary Terms');
echo head(array('title' => $pageTitle, 'bodyclass' => 'vocabulary-terms-page'));

// Warn if this session is running in the debugger because simultaneous Ajax requests won't work while debugging.
if (isset($_COOKIE['XDEBUG_SESSION']))
{
    echo '<div class="health-report-error">XDEBUG_SESSION in progress. Build status will not be reported in real-time.<br/>';
    echo '<a href="http://localhost/omeka-2.6/?XDEBUG_SESSION_STOP" target="_blank">Click here to stop debugging</a>';
    echo '</div>';
}

$kind = AvantVocabulary::VOCABULARY_TERM_KIND_TYPE;
$commonTermRecords = get_db()->getTable('VocabularyCommonTerms')->getCommonTermRecords($kind);
$localTermRecords = get_db()->getTable('VocabularyLocalTerms')->getLocalTermRecords($kind);

$suggestions = '';
foreach ($commonTermRecords as $commonTermRecord)
{
    $term = str_replace("'", "\'", $commonTermRecord->common_term);
    if (!empty($suggestions))
        $suggestions .= ',';
    $suggestions .= "'$term'";
}
$suggestions = "[$suggestions]";

// Form the URL for this page which is the same page that satisfies the Ajax requests.
$vocabularyTermsPageUrl = WEB_ROOT . '/admin/vocabulary/terms';
?>

<button type="button" class="action-button add-item-button"><?php echo __('Add a New Term'); ?></button>

<div id="vocabulary-term-selector-panel" class="modal-popup">
    <div class="modal-content">
        <p id="vocabulary-term-selector-message">Go for it</p>
        <input id="vocabulary-term-selector" placeholder="Type a term" />
        <div>
            <button type="button" class="action-button accept-term-button"><?php echo __('OK'); ?></button>
            <button type="button" class="action-button cancel-term-button"><?php echo __('Cancel'); ?></button>
        </div>

    </div>
</div>

<ul id="vocabulary-terms-list" class="ui-sortable">
    <?php
    foreach ($localTermRecords as $localTermRecord)
    {
        $removeClass = '';
        $identifier = $localTermRecord->common_term_id;
        if ($identifier > 0 && $identifier < AvantVocabulary::VOCABULARY_FIRST_NON_NOMENCLATURE_COMMON_TERM_ID)
        {
            // Display the common term as a link to the Nomenclature website page for the term.
            $link = AvantVocabulary::getNomenclatureLink();
            $link = str_replace('{ID}', $identifier, $link);
            $commonTerm = $localTermRecord->common_term;
            $maxTermLen = 60;
            if (strlen($commonTerm) > $maxTermLen)
            {
                $commonTerm = substr($commonTerm, 0, $maxTermLen) . '...';
            }
            $link = str_replace('{TERM}', $commonTerm, $link);
            $commonTerm = $link;
        }
        else
        {
            // This is not a Nomenclature 4.0 term.
            $commonTerm = $localTermRecord->common_term;
        }

        $localTerm = $localTermRecord->local_term;
        $mapping = $localTermRecord->mapping;
        $mappingText ='';

        if ($mapping == AvantVocabulary::VOCABULARY_MAPPING_SYNONYMOUS)
        {
            $mappingText = "&rarr;";
            $leftTerm = $localTerm;
            $rightTerm = $commonTerm;
        }
        elseif ($mapping == AvantVocabulary::VOCABULARY_MAPPING_IDENTICAL)
        {
            $leftTerm = $commonTerm;
            $rightTerm = '';
        }
        else
        {
            $leftTerm = $localTerm;
            $rightTerm = '';
        }

        $commonTermInDrawer = $commonTerm ? $commonTerm : __('This local term is not mapped to a common term');
        ?>
        <li id="<?php echo $localTermRecord->id; ?>">
            <div class="main_link ui-sortable-handle">
                <div class="sortable-item not-sortable vocabulary-term">
                    <div class="vocabulary-term-left"><?php echo $leftTerm; ?></div>
                    <div class="vocabulary-term-mapping"><?php echo $mappingText; ?></div>
                    <span class="vocabulary-term-right"><?php echo $rightTerm; ?></span>
                    <span class="drawer"></span>
                </div>
                <div class="drawer-contents" style="display:none;">
                    <label><?php echo __('Local Term'); ?></label><input class="local-term" type="text" value="<?php echo $localTerm; ?>">
                    <label><?php echo __('Common Term'); ?></label><div id="term-<?php echo $localTermRecord->id;?>" class="common-term"><?php echo $commonTermInDrawer; ?></div>
                    <div class="vocabulary-drawer-buttons" >
                        <div class="vocabulary-drawer-buttons-left">
                            <button type="button" class="action-button choose-term-button"><?php echo __('Choose Common Term'); ?></button>
                        </div>
                        <div class="vocabulary-drawer-buttons-right">
                            <button type="button" class="action-button update-item-button"><?php echo __('Update'); ?></button>
                            <button type="button" class="action-button remove-item-button red<?php echo $removeClass; ?>"><?php echo __('Remove'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </li>
        <?php
    }
    ?>
</ul>

<hr/>
<button id='start-button'>Rebuild</button>
<div id="status-area"></div>

<?php echo get_view()->partial('/edit-vocabulary-terms-script.php', array('url' => $vocabularyTermsPageUrl)); ?>

<?php echo foot(); ?>
