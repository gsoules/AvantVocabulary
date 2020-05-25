<script type="text/javascript">
    //var tableName = 'common';
    var tableName = 'local';

    var startButton = jQuery("#start-button").button();
    var statusArea = jQuery("#status-area");

    var actionInProgress = false;
    var progressCount = 0;
    var progressTimer;

    var url = '<?php echo $url; ?>';

    var activeItemId = 0;
    var itemEditorUrl = '<?php echo url('/vocabulary/update'); ?>';
    var nomenclatureLink = "<?php echo AvantVocabulary::getNomenclatureLink(); ?>";
    var kind = <?php echo $kind; ?>;

    jQuery(document).ready(function ()
    {
        initialize();
    });

    function addNewItem()
    {
        var firstItem = jQuery('ul#vocabulary-terms-list > li:first-child');
        var newItem = firstItem.clone();

        // Initialize the header.
        newItem.attr('id', 'new-item');
        newItem.find('.vocabulary-term-header').hide();
        newItem.find('.drawer-contents').show();

        // Empty the new item's controls and append the new item to the beginning of the list.
        newItem.find('input').val('');
        newItem.find('.vocabulary-drawer-common-term').text('');

        // Convert the Update button into the Save button.
        var saveButton = newItem.find('.update-item-button');
        saveButton.text('<?php echo __('Save'); ?>');
        saveButton.removeClass('update-item-button');
        saveButton.addClass('save-item-button');
        saveButton.click(function (event)
        {
            saveNewItem();
        });

        // Convert the Remove button into the Cancel button.
        var cancelButton = newItem.find('.remove-item-button');
        cancelButton.text('<?php echo __('Cancel'); ?>');
        cancelButton.removeClass('remove-item-button');
        cancelButton.removeClass('no-remove');
        cancelButton.addClass('cancel-add-button');
        cancelButton.show();
        cancelButton.click(function (event)
        {
            jQuery('#new-item').remove();
            jQuery('#add-vocabulary-term-button').prop('disabled', false);
        });

        // Append the new item to the beginning of the list.
        firstItem.before(newItem);

        // Disable the Add button while the user is adding a new item.
        jQuery('#add-vocabulary-term-button').prop('disabled', true);

        initializeItems();
    }

    function afterRemoveItem(itemId)
    {
        jQuery('#' + itemId).remove();
    }

    function afterSaveNewItem(id, itemValues)
    {
        var newItem = jQuery('#new-item');
        newItem.attr('id', id);
        newItem.find('.drawer-contents').hide();

        // Convert the Save button back into the Update button.
        var updateButton = newItem.find('.save-item-button');
        updateButton.text('<?php echo __('Update'); ?>');
        updateButton.removeClass('save-item-button');
        updateButton.addClass('update-item-button');

        // Convert the Cancel button back into the Remove button.
        var removeButton = newItem.find('.cancel-add-button');
        removeButton.text('<?php echo __('Remove'); ?>');
        removeButton.removeClass('cancel-add-button');
        removeButton.addClass('remove-item-button');

        // Show the header for the newly added item.
        newItem.find('.vocabulary-term-header').show();
        setItemTitle(newItem, itemValues.localTerm, itemValues.commonTerm, 999);

        // Allow the user to add another item.
        jQuery('#add-vocabulary-term-button').prop('disabled', false);

        initializeItems();
    }

    function afterUpdateItem(id, data)
    {
        var item = jQuery('#' + id);
        if (data['success'])
        {
            setItemTitle(item);
            item.find('.drawer-contents').slideUp();
            item.find('.drawer').removeClass('opened');
        }
        else
        {
            alert(data['error']);
        }
        item.find('.update-item-button').fadeTo(0, 1.0);
    }

    function closeTermChooserDialog()
    {
        document.getElementById('vocabulary-modal').classList.remove('is-visible')
    }

    function chooseTerm(itemId)
    {
        activeItemId = itemId;
        var termSelector = jQuery("#vocabulary-term-selector");
        termSelector.val('');
        termSelector.attr('placeholder', '<?php echo __('Type the words of a term you want to find'); ?>');
        document.getElementById('vocabulary-modal').classList.add('is-visible')
        termSelector.focus();
    }

    function enableSuggestions()
    {
        var termSelector = jQuery("#vocabulary-term-selector");
        var messageArea = jQuery('#vocabulary-term-selector-message');

        jQuery(termSelector).autocomplete(
        {
            source: url + '?kind=' + kind,
            delay: 250,
            minLength: 2,
            search: function(event, ui)
            {
                var term = termSelector.val();
                if (term.length <= 1)
                    messageArea.html('down to 1');
                else
                    messageArea.html('Searching for "' + term + '"');
            },
            response: function(event, ui)
            {
                if (ui.content.length === 0)
                {
                    var term = termSelector.val();
                    messageArea.html('No term was found for "' + term + '"');
                }
                else
                {
                    var howMany = '1 result';
                    if (ui.content.length > 1)
                        howMany = ui.content.length + ' results';

                    messageArea.html(howMany + '<br/>Choose from the list or keep typing to narrow down the results');
                }
            }
        });
    }

    function enableStartButton(enable)
    {
        startButton.button("option", {disabled: !enable});
    }

    function getItemValues(item)
    {
        var localTerm = item.find('.vocabulary-drawer-local-term');
        var commonTerm = item.find('.vocabulary-drawer-common-term');

        // Get the Id minus the "item-" prefix.
        var id = item.attr('id');
        id = id.substr(5);

        return {
            id: id,
            localTerm: localTerm.val(),
            commonTerm: commonTerm.text(),
            commonTermId: commonTerm.attr('data-common-term-id')
        };
    }

    function initialize()
    {
        setItemTitles();
        enableSuggestions();
        initializePageControls();
        initializeItems();
    }

    function initializeItems()
    {
        removeEventListeners();

        var drawerButtons = jQuery('.drawer');
        var updateButtons = jQuery('.update-item-button');
        var removeButtons = jQuery('.remove-item-button');
        var chooseButtons = jQuery('.choose-term-button');

        var acceptButton = jQuery('.accept-term-button');
        var cancelButton = jQuery('.cancel-term-button');

        acceptButton.click(function (event)
        {
            var selection = jQuery("#vocabulary-term-selector").val();
            console.log('OK ' + selection);
            var item = jQuery('#' + activeItemId);
            var commonTerm = item.find('.vocabulary-drawer-common-term');
            commonTerm.text(selection);
            closeTermChooserDialog();
        });

        cancelButton.click(function (event)
        {
            closeTermChooserDialog();
        });

        chooseButtons.click(function (event)
        {
            chooseTerm(jQuery(this).parents('li').attr('id'));
        });

        drawerButtons.click(function (event)
        {
            event.preventDefault();
            jQuery(this).parent().next().toggle();
            jQuery(this).toggleClass('opened');
        });

        removeButtons.click(function (event)
        {
            removeItem(jQuery(this).parents('li').attr('id'));
        });

        updateButtons.click(function (event)
        {
            updateItem(jQuery(this).parents('li').attr('id'));
        });

        jQuery('.no-remove').hide();
    }

    function initializePageControls()
    {
        voabularyChooser = jQuery('#vocabulary-chooser');
        voabularyChooser.val(kind);
        voabularyChooser.change(function()
        {
            var selection = jQuery(this).children("option:selected").val();
            window.location.href = url + '?kind=' + selection;
        });

        jQuery('#add-vocabulary-term-button').click(function (event)
        {
            addNewItem();
        });

        startButton.on("click", function ()
        {
            if (tableName === 'common') {
                if (!confirm('Are you sure you want to rebuild the tables?\n\nThe current tables will be DELETED.'))
                    return;
            }
            startMapping();
        });

        jQuery(window).resize(function()
        {
            var termSelector = jQuery("#vocabulary-term-selector");
            termSelector.autocomplete('close');
        });

        window.addEventListener('scroll', function(e)
        {
            var termSelector = jQuery("#vocabulary-term-selector");
            termSelector.autocomplete('close');
        });
    }

    function removeItem(itemId)
    {
        if (!confirm('<?php echo __('Remove this term?'); ?>'))
            return;

        jQuery('#' + itemId).fadeTo(750, 0.20);

        jQuery.ajax(
            itemEditorUrl,
            {
                method: 'POST',
                dataType: 'json',
                data: {
                    action: <?php echo VocabularyTermsEditor::REMOVE_VOCABULARY_TERM; ?>,
                    id: itemId
                },
                success: function (data)
                {
                    afterRemoveItem(itemId);
                },
                error: function (data)
                {
                    alert('AJAX Error on Remove: ' + data.statusText);
                }
            }
        );
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

    function saveNewItem()
    {
        var position = jQuery('ul#vocabulary-terms-list > li').length;
        var newItem = jQuery('#new-item');

        var itemValues = getItemValues(newItem);

        if (!validateItemValues(itemValues))
            return;

        jQuery.ajax(
            itemEditorUrl,
            {
                method: 'POST',
                dataType: 'json',
                data: {
                    action: <?php echo VocabularyTermsEditor::ADD_VOCABULARY_TERM; ?>,
                    kind: kind,
                    mapping:JSON.stringify(itemValues)
                },
                success: function (data) {
                    afterSaveNewItem(data.itemId, itemValues);
                },
                error: function (request, status, error) {
                    alert('AJAX ERROR on Save ' +  JSON.stringify(request));
                }
            }
        );
    }

    function setItemTitle(item)
    {
        var itemValues = getItemValues(item);

        localTerm = itemValues.localTerm;
        commonTerm = itemValues.commonTerm;
        commonTermId = itemValues.commonTermId;

        if (commonTerm && commonTermId > 0 && commonTermId < <?php echo AvantVocabulary::VOCABULARY_FIRST_NON_NOMENCLATURE_COMMON_TERM_ID; ?>)
        {
            maxTermLen = 60;
            if (commonTerm.length > maxTermLen)
            {
                commonTerm = commonTerm.substr(0, maxTermLen) + '...';
            }
            var link = nomenclatureLink.replace('{ID}', commonTermId);
            commonTerm = link.replace('{TERM}', commonTerm);
        }

        mappingIndicator = '';

        if (localTerm && commonTerm && localTerm !== commonTerm)
        {
            mappingIndicator = "&rarr;";
            leftTerm = localTerm;
            rightTerm = commonTerm;
        }
        else if (localTerm)
        {
            leftTerm = localTerm;
            rightTerm = '';
        }
        else
        {
            leftTerm = commonTerm;
            rightTerm = '';
        }

        item.find('.vocabulary-term-left').html(leftTerm);
        item.find('.vocabulary-term-mapping').html(mappingIndicator);
        item.find('.vocabulary-term-right').html(rightTerm);
    }

    function setItemTitles()
    {
        jQuery('.vocabulary-term-item').each(function(i)
        {
            var item = jQuery(this);
            setItemTitle(item)
        });
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

    function removeEventListeners()
    {
        var drawerButtons = jQuery('.drawer');
        var updateButtons = jQuery('.update-item-button');
        var removeButtons = jQuery('.remove-item-button');
        var chooseButtons = jQuery('.choose-term-button');

        drawerButtons.off('click');
        updateButtons.off('click');
        removeButtons.off('click');
        chooseButtons.off('click');
    }

    function updateItem(id)
    {
        var item = jQuery('#' + id);
        var itemValues = getItemValues(item);

        if (!validateItemValues(itemValues))
            return;

        item.find('.update-item-button').fadeTo(500, 0.20);

        jQuery.ajax(
            itemEditorUrl,
            {
                method: 'POST',
                dataType: 'json',
                data: {
                    action: <?php echo VocabularyTermsEditor::UPDATE_VOCABULARY_TERM; ?>,
                    mapping: JSON.stringify(itemValues)
                },
                success: function (data) {
                    afterUpdateItem(id, data);
                },
                error: function (data) {
                    alert('AJAX Error on Update: ' + data.statusText);
                }
            }
        );
    }

    function validateItemValues(itemValues)
    {
        if (itemValues.localTerm.trim().length === 0)
        {
            alert('<?php echo __('Local Term must be specified'); ?>');
            return false;
        }
        return true;
    }
</script>
