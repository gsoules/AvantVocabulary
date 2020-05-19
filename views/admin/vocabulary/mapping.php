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
