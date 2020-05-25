<?php

function emitPageJavaScript($kind)
{
    $url = WEB_ROOT . '/admin/vocabulary/terms';
    echo get_view()->partial('/edit-vocabulary-terms-script.php', array('kind' => $kind, 'url' => $url));
    echo foot();
}

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

    $avantVocabularyTableBuilder = new AvantVocabularyTableBuilder();
    $avantVocabularyTableBuilderProgress = new AvantVocabularyTableBuilderProgress();

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

echo "<SELECT id='vocabulary-chooser'>";
echo "<OPTION value='0'>Select a vocabulary</OPTION>";
echo "<OPTION value='" . AvantVocabulary::VOCABULARY_TERM_KIND_TYPE . "'>Type</OPTION>";
echo "<OPTION value='" . AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT . "'>Subject</OPTION>";
echo "<OPTION value='" . AvantVocabulary::VOCABULARY_TERM_KIND_PLACE . "'>Place</OPTION>";
echo "</SELECT>";

$kind = isset($_GET['kind']) ? intval($_GET['kind']) : 0;

$isValidKind =
    $kind == AvantVocabulary::VOCABULARY_TERM_KIND_TYPE ||
    $kind == AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT ||
    $kind == AvantVocabulary::VOCABULARY_TERM_KIND_PLACE;

if (!$isValidKind)
{
    if ($kind == 0 && current_user()->role == 'super')
    {
        echo "<hr/>";
        if (isset($_COOKIE['XDEBUG_SESSION']))
        {
            echo '<div class="health-report-error">XDEBUG_SESSION in progress. Build status will not be reported in real-time.<br/>';
            echo '<a href="http://localhost/omeka-2.6/?XDEBUG_SESSION_STOP" target="_blank">Click here to stop debugging</a>';
            echo '</div>';
        }
        echo "<button id='start-button'>Rebuild</button>";
        echo "<div id='status-area'></div>";
    }

    emitPageJavaScript($kind);
    echo foot();

    // Don't show anything else until the user chooses a vocabulary.
    return;
}

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

// The HTML that follows displays the choose vocabulary.
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

        $localTerm = $localTermRecord->local_term;
        $commonTerm = $localTermRecord->common_term;
        $commonTermId = $localTermRecord->common_term_id;
        $mapping = $localTermRecord->mapping;

        $commonTermInDrawer = $commonTerm ? $commonTerm : '';
        $localTermInDrawer = $mapping != AvantVocabulary::VOCABULARY_MAPPING_IDENTICAL ? $localTerm : '';

        // The HTML below provides the structure for each term. The drawer area provides the local and common term
        // values. The header is filled in and formatted in JavaScript. It's done there because the JavaScript is also
        // responsible for creating and modifying the header when the user adds or edits a term. This way, this PHP
        // code does not need to know how the header is supposed to be formatted.
        ?>
        <li id="item-<?php echo $localTermRecord->id; ?>" class="vocabulary-term-item" >
            <div class="ui-sortable-handle">
                <div class="sortable-item not-sortable vocabulary-term-header">
                    <div class="vocabulary-term-left"></div>
                    <div class="vocabulary-term-mapping"></div>
                    <div class="vocabulary-term-right"></div>
                    <span class="drawer"></span>
                </div>
                <div class="drawer-contents" style="display:none;">
                    <label><?php echo __('Local Term'); ?></label><input class="vocabulary-drawer-local-term" type="text" value="<?php echo $localTermInDrawer; ?>">
                    <label><?php echo __('Common Term'); ?></label><div data-common-term-id="<?php echo $commonTermId;?>" class="vocabulary-drawer-common-term"><?php echo $commonTermInDrawer; ?></div>
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

<?php emitPageJavaScript($kind); ?>
<?php echo foot(); ?>
