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
    // waiting here until it completes (when handleAjaxRequest returns). When ths page returns, the request's
    // 'success' handler function will execute or its 'error' handler function if something went wrong).

    // Determine if this request is for common term suggestions.
    $term = isset($_GET['term']) ? $_GET['term'] : '';
    $kind = isset($_GET['kind']) ? $_GET['kind'] : 0;
    if ($term)
    {
        // The user typed a term into the chooser dialog. Get the suggestions for that term.
        $suggestedCommonTermRecords = get_db()->getTable('VocabularyCommonTerms')->getCommonTermSuggestions($kind, $term);
        $result = array();
        foreach ($suggestedCommonTermRecords as $commonTermRecord)
        {
            $commonTerm = $commonTermRecord->common_term;
            $result[] = $commonTerm;
        }
        // Return the results and then leave so that the code to display the Vocabulary Editor will not execute.
        echo json_encode($result);
        return;
    }

    // Determine if this request is to rebuild a vocabulary table.
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $tableName = isset($_POST['table_name']) ? $_POST['table_name'] : '';
    if ($action == 'progress')
    {
        $avantVocabularyTableBuilderProgress = new AvantVocabularyTableBuilderProgress();
        $avantVocabularyTableBuilderProgress->handleAjaxProgressRequest($tableName);
    }
    else
    {
        // Give the request plenty of time to execute since it can take several minutes.
        ini_set('max_execution_time', 10 * 60);

        $avantVocabularyTableBuilder = new AvantVocabularyTableBuilder();
        $avantVocabularyTableBuilder->handleAjaxBuildRequest($tableName);
    }
    // Leave so that the code to display the Vocabulary Editor will not execute.
    return;
}

// If execution reaches here, the page is not responding to an Ajax request. Display the Vocabulary Editor.
$pageTitle = __('Vocabulary Editor');
echo head(array('title' => $pageTitle, 'bodyclass' => 'vocabulary-terms-page'));

list($kind, $kindName) = AvantVocabulary::getDefaultKindFromQueryOrCookie();

$elementId = ItemMetadata::getElementIdForElementName($kindName);

echo "<div class='vocabulary-controls'>";
echo AvantVocabulary::emitVocabularyKindChooser();

echo "<div>";
echo "<button id='add-vocabulary-term-button' type='button' class='action-button'>" . __('Add %s', $kindName) . "</button>";
echo "</div>";
echo "<a class='vocabulary-view-toggle' href='../vocabulary/tree?kind=$kind'>" . __('View %s hierarchy', $kindName) . "</a>";

echo "</div>";

$commonTermCount = get_db()->getTable('VocabularyCommonTerms')->commonTermCount($kind);
$commonTermCount = number_format($commonTermCount, 0, '.', ',');

$siteTermItems = get_db()->getTable('VocabularySiteTerms')->getSiteTermItems($kind);
$siteTermCount = count($siteTermItems);
$verb = $siteTermCount == 1 ? __('term is defined') : __('terms are defined');

// Look for common terms that have the same leaf as the unmapped site terms.
foreach ($siteTermItems as $index => $siteTermItem)
{
    if ($siteTermItem['common_term_id'])
        continue;

    $suggestion = AvantVocabulary::getCommonTermSuggestionFromSiteTerm($kind, $siteTermItem['site_term']);
    if ($suggestion)
        $siteTermItems[$index]['suggestion'] = $suggestion;
}

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

<div id="vocabulary-editor-busy"></div>
<div id="vocabulary-term-editor-message-area"></div>

<div id="vocabulary-modal" class="modal">
    <div id="vocabulary-modal-dialog" class="modal-dialog">
        <div class="modal-header">
            <div class="modal-header-title"><?php echo __('Search for a %s in the Common Vocabulary', $kindName); ?></div>
            <button class="action-button close-chooser-dialog-button"><?php echo __('Close'); ?></button>
        </div>
        <section class="modal-content">
            <div id="vocabulary-term-message"></div>
            <input id="vocabulary-term-input" />
        </section>
    </div>
</div>

<div id="vocabulary-terms-list-header">
    <div class="vocabulary-term-left"><?php echo __('Term') ?></div>
    <div class="vocabulary-term-count">Uses</div>
    <div class="vocabulary-term-mapping">Mapping</div>
    <div class="vocabulary-term-edit">Edit</div>
</div>


<ul id="vocabulary-terms-list">
    <?php
    $vocabularyTermsEditor = new VocabularyTermsEditor();
    $elementId = ItemMetadata::getElementIdForElementName($kindName);

    foreach ($siteTermItems as $siteTermItem)
    {
        $hideClass = '';

        $siteTerm = $siteTermItem['site_term'];
        $commonTermId = $siteTermItem['common_term_id'];
        $commonTerm = $siteTermItem['common_term'];

        if ($commonTerm == ElementValidator::VALIDATION_NONE)
        {
            // Don't show the special term 'none'.
            continue;
        }

        $suggestion = isset($siteTermItem['suggestion']) ? $siteTermItem['suggestion'] : '';

        $term = $siteTerm ? $siteTerm : $commonTerm;
        $usageCount = $vocabularyTermsEditor->getSiteTermUsageCount($elementId, $term);
        $hideRemoveItemButton = $usageCount != 0 ? ' hide' : '';

        // The HTML below provides the structure for each term. The drawer area provides the local and common term
        // values. The header is filled in and formatted in JavaScript. It's done there because the JavaScript is also
        // responsible for creating and modifying the header when the user adds or edits a term. This way, this PHP
        // code does not need to know how the header is supposed to be formatted.
        ?>
        <li id="item-<?php echo $siteTermItem['id']; ?>" class="vocabulary-term-item" >
            <div class="main_link">
                <div class="vocabulary-term-header">
                    <div class="vocabulary-term-left"></div>
                    <div class="vocabulary-term-count"><?php echo $usageCount; ?></div>
                    <div class="vocabulary-term-mapping"></div>
                    <span class="vocabulary-term-edit-icon"></span>
                </div>
                <div class="drawer-contents" style="display:none;">
                    <div class="drawer-message"></div>
                    <label><?php echo __('Site Term'); ?></label>
                    <input class="vocabulary-drawer-site-term" type="text" value="<?php echo $siteTerm; ?>">
                    <label><?php echo __('Common Term'); ?></label>
                    <div class="vocabulary-term-suggestion"><?php echo $suggestion; ?></div>
                    <div data-common-term-id="<?php echo $commonTermId; ?>" class="vocabulary-drawer-common-term"><?php echo $commonTerm; ?></div>
                    <div class="vocabulary-drawer-buttons" >
                        <div class="vocabulary-drawer-buttons-left">
                            <button class="action-button choose-common-term-button"><?php echo __('Choose Common Term'); ?></button>
                            <button class="action-button erase-common-term-button"><?php echo __('Erase Common Term'); ?></button>
                        </div>
                        <div class="vocabulary-drawer-buttons-right">
                            <button class="action-button update-item-button"><?php echo __('Update'); ?></button>
                            <button class="action-button remove-item-button red<?php echo $hideRemoveItemButton; ?>"><?php echo __('Remove Term'); ?></button>
                            <button class="action-button cancel-update-button"><?php echo __('Cancel'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </li>
        <?php
    }
    ?>
</ul>

<?php
if (AvantCommon::userIsSuper())
{
    echo "<div class='vocabulary-build-buttons'>";
    if (isset($_COOKIE['XDEBUG_SESSION']))
    {
        echo '<div class="health-report-error">';
        echo '<a href="http://localhost/omeka/?XDEBUG_SESSION_STOP" target="_blank">Click here to stop debugging</a>';
        echo '</div>';
    }
    echo "<div>" . __('These are super user options. Do not use them unless you understand what they are for.') . "</div><br/>";
    echo "<button id='rebuild-common-terms-button'>Rebuild Common Terms table</button>";
    echo "&nbsp;&nbsp;";
    echo "<button id='rebuild-site-terms-button'>Rebuild Site Terms table</button>";
    echo "</div>";
    echo "<div id='vocabulary-editor-busy'></div>";
}

emitPageJavaScript($kind, $kindName, $elementId, $commonTermCount);
echo foot();
