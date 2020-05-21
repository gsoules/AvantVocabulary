<?php

$avantVocabularyTableBuilder = new AvantVocabularyTableBuilder();

if (AvantCommon::isAjaxRequest())
{
    // This page just got called to handle an asynchronous Ajax request. Execute the request synchronously,
    // waiting here until it completes (when handleAjaxRequest returns). When ths page returns,  the request's
    // success function will execute in the browser (or its error function if something went wrong).
    // Give the request plenty of time to execute since it can take several minutes.
    ini_set('max_execution_time', 10 * 60);
    $avantVocabularyTableBuilder->handleAjaxRequest();
    return;
}

$pageTitle = __('Vocabulary Mapping');
echo head(array('title' => $pageTitle, 'bodyclass' => 'mapping'));

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

?>

<ul id="relationship-items-list" class="ui-sortable">
    <?php
    foreach ($localTermRecords as $localTermRecord)
    {
        $removeClass = '';
        $identifier = $localTermRecord->common_term_id;
        if ($identifier < AvantVocabulary::VOCABULARY_FIRST_NON_NOMENCLATURE_COMMON_TERM_ID)
        {
            // Create a link to the Nomenclature 4.0 specification for this term.
            $nomenclatureUrl = "https://www.nomenclature.info/parcourir-browse.app?lang=en&id=$identifier&wo=N&ws=INT";
            $altText = __('View the Nomenclature 4.0 specification for term %1$s', $identifier);
            $commonTerm = "<a href='$nomenclatureUrl' target='_blank' title='$altText'>$localTermRecord->common_term</a>";
        }
        else
        {
            // This is not a Nomenclature 4.0 term.
            $commonTerm = $localTermRecord->common_term;
        }
        $mapping = $localTermRecord->mapping;
        if ($mapping == 2)
            $mappingText = __('synonymous with');
        elseif ($mapping == 1)
            $mappingText = __('identical to');
        else
            $mappingText = __('NOT MAPPED');
        ?>
        <li id="<?php echo $localTermRecord->id; ?>">
            <div class="main_link ui-sortable-handle">
                <div class="sortable-item not-sortable">
                    <div class="vocabulary-term-local"><?php echo $localTermRecord->local_term; ?></div>
                    <div class="vocabulary-term-mapping"><?php echo $mappingText; ?></div>
                    <span class="vocabulary-term-common"><?php echo $commonTerm; ?></span>
                    <span class="drawer"></span>
                </div>
                <div class="drawer-contents" style="display:none;">
                    <label><?php echo __('Description'); ?></label><input class="description" type="text" value="<?php echo $localTermRecord->local_term; ?>">
                    <label><?php echo __('Rule'); ?></label><input class="rule" type="text" value="<?php echo $localTermRecord->common_term; ?>">
                    <button type="button" class="action-button update-item-button"><?php echo __('Update'); ?></button>
                    <button type="button" class="action-button remove-item-button red button<?php echo $removeClass; ?>"><?php echo __('Remove'); ?></button>
                </div>
            </div>
        </li>
        <?php
    }
    ?>
</ul>

<!---->
<!--<table class="relationships-editor-table">-->
<!--    <thead>-->
<!--    <tr>-->
<!--        <th class="relationship-table-relationship">--><?php //echo __('Kind'); ?><!--</th>-->
<!--        <th class="relarelationship-table-related-item-title">--><?php //echo __('Local Term'); ?><!--</th>-->
<!--        <th class="relationship-table-related-item">--><?php //echo __('Mapping'); ?><!--</th>-->
<!--        <th class="relationship-table-related-item-title">--><?php //echo __('Common Term'); ?><!--</th>-->
<!--    </tr>-->
<!--    </thead>-->
<!--    <tbody>-->
<!--    --><?php
//    foreach ($localTermRecords as $localTermRecord)
//    {
//        ?>
<!--        <tr>-->
<!--            <td>--><?php //echo $localTermRecord->kind; ?><!--</td>-->
<!--            <td>--><?php //echo $localTermRecord->local_term; ?><!--</td>-->
<!--            <td>--><?php //echo $localTermRecord->mapping; ?><!--</td>-->
<!--            <td>--><?php //echo $localTermRecord->common_term; ?><!--</td>-->
<!--        </tr>-->
<!--    --><?php //}; ?>
<!--    </tbody>-->
<!--</table>-->

<!--<table class="relationships-editor-table">-->
<!--    <thead>-->
<!--    <tr>-->
<!--        <th class="relationship-table-relationship">--><?php //echo __('Kind'); ?><!--</th>-->
<!--        <th class="relationship-table-relationship">--><?php //echo __('Identifier'); ?><!--</th>-->
<!--        <th class="relationship-table-related-item-title">--><?php //echo __('Common Term'); ?><!--</th>-->
<!--        <th class="relationship-table-related-item-title">--><?php //echo __('Leaf Term'); ?><!--</th>-->
<!--    </tr>-->
<!--    </thead>-->
<!--    <tbody>-->
<!--    --><?php
//    foreach ($commonTermRecords as $commonTermRecord)
//    {
//        ?>
<!--        <tr>-->
<!--            <td>--><?php //echo $commonTermRecord->kind; ?><!--</td>-->
<!--            <td>--><?php //echo $commonTermRecord->identifier; ?><!--</td>-->
<!--            <td>--><?php //echo $commonTermRecord->common_term; ?><!--</td>-->
<!--            <td>--><?php //echo $commonTermRecord->leaf_term; ?><!--</td>-->
<!--        </tr>-->
<!--    --><?php //}; ?>
<!--    </tbody>-->
<!--</table>-->

<?php

echo get_view()->partial('/edit-vocabulary-mapping-script.php');

echo foot();

// Form the URL for this page which is the same page that satisfies the Ajax requests.
$url = WEB_ROOT . '/admin/vocabulary/mapping';
?>

<script type="text/javascript">
    jQuery(document).ready(function ()
    {
        var actionButtons = jQuery("input[name='action']");
        var indexNameFields = jQuery("#index-name-fields");
        var startButton = jQuery("#start-button").button();
        var statusArea = jQuery("#status-area");

        var actionInProgress = false;
        var indexingId = '';
        var indexingName = '';
        var indexingOperation = '';
        var progressCount = 0;
        var progressTimer;
        var selectedAction = 'rebuild';
        var url = '<?php echo $url; ?>';

        initialize();

        function enableStartButton(enable)
        {
            startButton.button("option", {disabled: !enable});
        }

        function initialize()
        {
            startButton.on("click", function()
            {
                if (selectedAction === 'rebuild')
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

            // Call back to the server (this page) to get the status of the indexing action.
            // The server returns the complete status since the action began, not just what has since transpired.
            jQuery.ajax(
                url,
                {
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'progress',
                        indexing_id: indexingId,
                        operation: indexingOperation
                    },
                    success: function (data)
                    {
                        showStatus(data);
                        if (actionInProgress)
                        {
                            progressTimer = setTimeout(reportProgress, 2000);
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
            //status = status.replace(/(\r\n|\n|\r)/gm, '<BR/>');
            statusArea.html(statusArea.html() + '<BR/>' + status);
        }

        function startMapping()
        {
            actionInProgress = true;
            statusArea.html('');

            enableStartButton(false);

            // Initiate periodic calls back to the server to get the status of the indexing action.
            progressCount = 0;
            progressTimer = setTimeout(reportProgress, 1000);

            // Call back to the server (this page) to initiate the indexing action which can take several minutes.
            // While waiting, the reportProgress function is called on a timer to get the status of the action.
            jQuery.ajax(
                url,
                {
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: selectedAction,
                        index_name: indexingName,
                        indexing_id: indexingId,
                        operation: indexingOperation
                    },
                    success: function (data)
                    {
                        actionInProgress = false;
                        showStatus(data);
                        enableStartButton(true);
                    },
                    error: function (request, status, error)
                    {
                        clearTimeout(progressTimer);
                        alert('AJAX ERROR on ' + selectedAction + ' >>> ' +  JSON.stringify(request));
                    }
                }
            );
        }
    });
</script>
