<?php

function emitPageJavaScript($kind, $kindName, $elementId, $commonTermCount)
{
    $url = WEB_ROOT . '/admin/vocabulary';
    $args = array('kind'=>$kind, 'kindName'=>$kindName, 'elementId'=>$elementId, 'commonTermCount'=>$commonTermCount, 'url'=>$url);
    echo get_view()->partial('/edit-vocabulary-terms-script.php', $args);
}

if (AvantCommon::isAjaxRequest())
{
    // This page just got called to handle an asynchronous Ajax request. Execute the request synchronously,
    // waiting here until it completes (when handleAjaxRequest returns). When ths page returns,  the request's
    // success function will execute in the browser (or its error function if something went wrong).

    $term = isset($_GET['term']) ? $_GET['term'] : '';
    $kind = isset($_GET['kind']) ? $_GET['kind'] : 0;
    if ($term)
    {
        // The user choose a Common Vocabulary term from the autocomplete selector.
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

$pageTitle = __('Vocabulary Editor');
echo head(array('title' => $pageTitle, 'bodyclass' => 'vocabulary-terms-page'));

// Get the vocabulary kind from the URL.
$kind = isset($_GET['kind']) ? intval($_GET['kind']) : 0;
$isValidKind =
    $kind == AvantVocabulary::VOCABULARY_TERM_KIND_TYPE ||
    $kind == AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT ||
    $kind == AvantVocabulary::VOCABULARY_TERM_KIND_PLACE;

$kindName = '';
if ($isValidKind)
{
    if ($kind == AvantVocabulary::VOCABULARY_TERM_KIND_TYPE)
        $kindName = AvantVocabulary::VOCABULARY_TERM_KIND_TYPE_LABEL;
    elseif ($kind == AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT)
        $kindName = AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT_LABEL;
    elseif ($kind == AvantVocabulary::VOCABULARY_TERM_KIND_PLACE)
        $kindName = AvantVocabulary::VOCABULARY_TERM_KIND_PLACE_LABEL;
}

$elementId = ItemMetadata::getElementIdForElementName($kindName);

echo "<div class='vocabulary-controls'>";

echo "<div>";
echo "<label class='vocabulary-chooser-label'>Vocabulary: </label>";
echo "<SELECT required id='vocabulary-chooser' class='vocabulary-chooser'>";
echo "<OPTION value='0' selected disabled hidden>Select a vocabulary</OPTION>";
echo "<OPTION value='" . AvantVocabulary::VOCABULARY_TERM_KIND_TYPE . "'>" . AvantVocabulary::VOCABULARY_TERM_KIND_TYPE_LABEL . "</OPTION>";
echo "<OPTION value='" . AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT . "''>" . AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT_LABEL . "</OPTION>";
echo "<OPTION value='" . AvantVocabulary::VOCABULARY_TERM_KIND_PLACE . "'>" . AvantVocabulary::VOCABULARY_TERM_KIND_PLACE_LABEL . "</OPTION>";
echo "</SELECT>";
echo "</div>";

if ($isValidKind)
{
    echo "<div>";
    echo "<button id='add-vocabulary-term-button' type='button' class='action-button'>" . __('Add a new %s term', $kindName) . "</button>";
    echo "</div>";
}
echo "</div>";

if (!$isValidKind)
{
    if ($kind == 0 && current_user()->role == 'super')
    {
        // When the kind is 0, show the Build button to a super user.
        echo "<hr/>";
        if (isset($_COOKIE['XDEBUG_SESSION']))
        {
            echo '<div class="health-report-error">XDEBUG_SESSION in progress. Build status will not be reported in real-time.<br/>';
            echo '<a href="http://localhost/omeka-2.6/?XDEBUG_SESSION_STOP" target="_blank">Click here to stop debugging</a>';
            echo '</div>';
        }
        echo "<button id='rebuild-common-terms-button'>Rebuild Common Terms</button>";
        echo "&nbsp;&nbsp;";
        echo "<button id='rebuild-local-terms-button'>Rebuild Local Terms</button>";
        echo "<div id='status-area'></div>";
    }

    // Don't show anything else until the user chooses a vocabulary.
    emitPageJavaScript($kind, $kindName, $elementId, 0);
    echo foot();
    return;
}

$commonTermCount = get_db()->getTable('VocabularyCommonTerms')->commonTermCount($kind);
$commonTermCount = number_format($commonTermCount, 0, '.', ',');

$localTermItemRecords = get_db()->getTable('VocabularyLocalTerms')->getLocalTermItemsInOrder($kind);
$localTermCount = count($localTermItemRecords);
$verb = $localTermCount == 1 ? __('term is defined') : __('terms are defined');

// The HTML that follows displays the choose vocabulary.
?>
<div id="dialog-confirm-remove-term" title="<?php echo __('Remove term'); ?>">
    <h2><?php echo __('Are you sure?'); ?></h2>
    <p></p>
</div>

<div id="dialog-confirm-update-term" title="<?php echo __('Update term'); ?>">
    <h2><?php echo __('Are you sure?'); ?></h2>
    <p></p>
</div>

<div id="vocabulary-term-editor-message-area"></div>

<div id="vocabulary-modal" class="modal">
    <div id="vocabulary-modal-dialog" class="modal-dialog">
        <div class="modal-header">
            <div class="modal-header-title"><?php echo __('Search for a %s in the Common Vocabulary', $kindName); ?></div>
            <button type="button" class="action-button close-chooser-dialog-button"><?php echo __('Close'); ?></button>
        </div>
        <section class="modal-content">
            <div id="vocabulary-term-message"></div>
            <input id="vocabulary-term-input" />
        </section>
    </div>
</div>

<div id="vocabulary-terms-list-header">
    <div class="vocabulary-term-left">Local Term</div>
    <div class="vocabulary-term-mapping">Mapping</div>
    <div class="vocabulary-term-right">Common Term</div>
    <div class="vocabulary-term-count">Uses</div>
</div>


<ul id="vocabulary-terms-list" class="ui-sortable">
    <?php
    $vocabularyTermsEditor = new VocabularyTermsEditor();
    $elementId = ItemMetadata::getElementIdForElementName($kindName);

    foreach ($localTermItemRecords as $localTermRecord)
    {
        $hideClass = '';
        $identifier = $localTermRecord->common_term_id;

        $localTerm = $localTermRecord->local_term;
        $commonTermId = $localTermRecord->common_term_id;
        $commonTerm = $localTermRecord->common_term;

        $term = $localTerm ? $localTerm : $commonTerm;
        $usageCount = $vocabularyTermsEditor->getLocalTermUsageCount($elementId, $term);
        $hideRemoveItemButton = $usageCount != 0 ? ' hide' : '';

        // The HTML below provides the structure for each term. The drawer area provides the local and common term
        // values. The header is filled in and formatted in JavaScript. It's done there because the JavaScript is also
        // responsible for creating and modifying the header when the user adds or edits a term. This way, this PHP
        // code does not need to know how the header is supposed to be formatted.
        ?>
        <li id="item-<?php echo $localTermRecord->id; ?>" class="vocabulary-term-item" >
            <div class="main_link ui-sortable-handle">
                <div class="sortable-item sortable-item vocabulary-term-header">
                    <div class="vocabulary-term-left"></div>
                    <div class="vocabulary-term-mapping"></div>
                    <div class="vocabulary-term-right"></div>
                    <span class="vocabulary-term-edit-icon"></span>
                    <div class="vocabulary-term-count"><?php echo $usageCount; ?></div>
                </div>
                <div class="drawer-contents" style="display:none;">
                    <div class="drawer-message"></div>
                    <label><?php echo __('Local Term'); ?></label><input class="vocabulary-drawer-local-term" type="text" value="<?php echo $localTerm; ?>">
                    <label><?php echo __('Common Term'); ?></label><div data-common-term-id="<?php echo $commonTermId;?>" class="vocabulary-drawer-common-term"><?php echo $commonTerm; ?></div>
                    <div class="vocabulary-drawer-buttons" >
                        <div class="vocabulary-drawer-buttons-left">
                            <button type="button" class="action-button choose-common-term-button"><?php echo __('Choose Common Term'); ?></button>
                            <button type="button" class="action-button erase-common-term-button"><?php echo __('Erase Common Term'); ?></button>
                        </div>
                        <div class="vocabulary-drawer-buttons-right">
                            <button type="button" class="action-button update-item-button"><?php echo __('Update'); ?></button>
                            <button type="button" class="action-button remove-item-button red<?php echo $hideRemoveItemButton; ?>"><?php echo __('Remove Term'); ?></button>
                            <button type="button" class="action-button cancel-update-button"><?php echo __('Cancel'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </li>
        <?php
    }
    ?>
</ul>

<?php emitPageJavaScript($kind, $kindName, $elementId, $commonTermCount); ?>
<?php echo foot(); ?>
