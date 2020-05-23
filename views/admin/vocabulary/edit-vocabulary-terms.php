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
            $result[] = array("value"=>$commonTermRecord->common_term_id,"label"=>$commonTerm);
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

echo "<hr/>";
echo "<button id='start-button'>Start</button>";
echo '<div id="status-area"></div>';

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
?>

<p id="autocomplete-suggestion">Go for it</p>
<input id="vocabulary-term-selector" placeholder="Type a term" />

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
            $link = str_replace('{TERM}', $localTermRecord->common_term, $link);
            $commonTerm = $link;
        }
        else
        {
            // This is not a Nomenclature 4.0 term.
            $commonTerm = $localTermRecord->common_term;
        }
        $mapping = $localTermRecord->mapping;
        if ($mapping == AvantVocabulary::VOCABULARY_MAPPING_SYNONYMOUS)
            $mappingText = AvantVocabulary::VOCABULARY_MAPPING_SYNONYMOUS_LABEL;
        elseif ($mapping == AvantVocabulary::VOCABULARY_MAPPING_IDENTICAL)
            $mappingText = AvantVocabulary::VOCABULARY_MAPPING_IDENTICAL_LABEL;
        else
            $mappingText = AvantVocabulary::VOCABULARY_MAPPING_NONE_LABEL;
        ?>
        <li id="<?php echo $localTermRecord->id; ?>">
            <div class="main_link ui-sortable-handle">
                <div class="sortable-item not-sortable vocabulary-term">
                    <div class="vocabulary-term-local"><?php echo $localTermRecord->local_term; ?></div>
                    <div class="vocabulary-term-mapping"><?php echo $mappingText; ?></div>
                    <span class="vocabulary-term-common"><?php echo $commonTerm; ?></span>
                    <span class="drawer"></span>
                </div>
                <div class="drawer-contents" style="display:none;">
                    <label><?php echo __('Local Term'); ?></label><input class="local-term" type="text" value="<?php echo $localTermRecord->local_term; ?>">
                    <label><?php echo __('Common Term'); ?></label><input id="term-<?php echo $localTermRecord->id;?>" class="common-term" type="text" value="<?php echo $localTermRecord->common_term; ?>">
                    <div>
                        <button type="button" class="action-button update-item-button"><?php echo __('Update'); ?></button>
                        <button type="button" class="action-button remove-item-button red button<?php echo $removeClass; ?>"><?php echo __('Remove'); ?></button>
                    </div>
                </div>
            </div>
        </li>
        <?php
    }
    ?>
</ul>
<?php

echo get_view()->partial('/edit-vocabulary-mapping-script.php');

echo foot();

// Form the URL for this page which is the same page that satisfies the Ajax requests.
$vocabularyTermsPageUrl = WEB_ROOT . '/admin/vocabulary/terms';
?>

<script type="text/javascript">
    //var tableName = 'common';
    var tableName = 'local';

    var startButton = jQuery("#start-button").button();
    var statusArea = jQuery("#status-area");

    var actionInProgress = false;
    var progressCount = 0;
    var progressTimer;

    var url = '<?php echo $vocabularyTermsPageUrl; ?>';


    jQuery(document).ready(function ()
    {
        initialize();
    });

    function enableSuggestions()
    {
        var termSelector = jQuery("#vocabulary-term-selector");
        var messageArea = jQuery('#autocomplete-suggestion');
        var suggestions = <?php echo $suggestions; ?>;

        jQuery(termSelector).autocomplete(
        {
            source: url,
            delay: 250,
            minLength: 1,
            search: function(event, ui)
            {
                var term = jQuery(termSelector).val();
                jQuery(messageArea).html('Am searching for "' + term + '"');
            },
            response: function(event, ui)
            {
                if (ui.content.length === 0)
                {
                    var term = jQuery(termSelector).val();
                    jQuery(messageArea).html('No match was found for "' + term + '"');
                }
                else
                {
                    jQuery(messageArea).html('Type something');
                }
            }
        });
    }

    function enableStartButton(enable)
    {
        startButton.button("option", {disabled: !enable});
    }

    function initialize()
    {
        enableSuggestions();

        startButton.on("click", function()
        {
            if (tableName === 'common')
            {
                if (!confirm('Are you sure you want to rebuild the tables?\n\nThe current tables will be DELETED.'))
                    return;
            }
            startMapping();
        });
    }

    function reportProgress()
    {
        if (!actionInProgress)
            return;

        console.log('reportProgress ' + ++progressCount);

        // Call back to the server (this page) to get the status of the action.
        // The server returns the complete status since the action began, not just what has since transpired.
        jQuery.ajax(
            url,
            {
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'progress',
                    table_name: tableName
                },
                success: function (data)
                {
                    showStatus(data);
                    if (actionInProgress)
                    {
                        progressTimer = setTimeout(reportProgress, 1000);
                    }
                },
                error: function (request, status, error)
                {
                    alert('AJAX ERROR on reportProgress' + ' >>> ' + JSON.stringify(request));
                }
            }
        );
    }

    function showStatus(status)
    {
        statusArea.html(statusArea.html() + status + '<BR/>');
    }

    function startMapping()
    {
        actionInProgress = true;
        statusArea.html('');

        enableStartButton(false);

        // Initiate periodic calls back to the server to get the status of the action.
        progressCount = 0;
        progressTimer = setTimeout(reportProgress, 1000);

        // Call back to the server (this page) to initiate the action which can take several minutes.
        // While waiting, the reportProgress function is called on a timer to get the status of the action.
        jQuery.ajax(
            url,
            {
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'build',
                    table_name: tableName
                },
                success: function (data)
                {
                    actionInProgress = false;
                    console.log("DONE");
                    showStatus(data);
                    enableStartButton(true);
                },
                error: function (request, status, error)
                {
                    clearTimeout(progressTimer);
                    alert('AJAX ERROR on build ' + tableName + ' >>> ' +  JSON.stringify(request));
                }
            }
        );
    }
</script>
