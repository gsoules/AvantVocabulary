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

    var mappingLabel = [];
    mappingLabel[<?php echo AvantVocabulary::VOCABULARY_MAPPING_NONE; ?>] = '<?php echo AvantVocabulary::VOCABULARY_MAPPING_NONE_LABEL; ?>';
    mappingLabel[<?php echo AvantVocabulary::VOCABULARY_MAPPING_IDENTICAL; ?>] = '<?php echo AvantVocabulary::VOCABULARY_MAPPING_IDENTICAL_LABEL; ?>';
    mappingLabel[<?php echo AvantVocabulary::VOCABULARY_MAPPING_SYNONYMOUS; ?>] = '<?php echo AvantVocabulary::VOCABULARY_MAPPING_SYNONYMOUS_LABEL; ?>';

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
        newItem.find('.vocabulary-term-left').first().text('Add a New Term');
        newItem.find('.vocabulary-term-mapping').first().text('');
        newItem.find('.vocabulary-term-right').text('');
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
            jQuery('.add-item-button').prop('disabled', false);
        });

        // Append the new item to the beginning of the list.
        firstItem.before(newItem);

        // Disable the Add button while the user is adding a new item.
        jQuery('.add-item-button').prop('disabled', true);

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
        formatItemTitle(newItem, itemValues.localTerm, itemValues.commonTerm, 999);

        // Allow the user to add another item.
        jQuery('.add-item-button').prop('disabled', false);

        initializeItems();
    }

    function afterUpdateItem(id, data)
    {
        var item = jQuery('#' + id);
        if (data['success'])
        {
            var itemValues = getItemValues(item);
            var mapping = data['mapping'];

            formatItemTitle(item, itemValues.localTerm, itemValues.commonTerm, 888);

            item.find('.drawer-contents').slideUp();
            item.find('.drawer').removeClass('opened');
        }
        else
        {
            alert(data['error']);
        }
        item.find('.update-item-button').fadeTo(0, 1.0);
    }

    function chooseTerm(itemId)
    {
        activeItemId = itemId;
        jQuery("#vocabulary-term-selector-panel").show();
    }

    function enableSuggestions()
    {
        var termSelector = jQuery("#vocabulary-term-selector");
        var messageArea = jQuery('#vocabulary-term-selector-message');

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

    function formatItemTitle(item, localTerm, commonTerm, commonTermId)
    {
        console.log('formatItemTitle ' + '[' +localTerm + '] [ ' + commonTerm + '] [ ' + commonTermId + ']');

        var commonTermLink = nomenclatureLink.replace('{ID}', commonTermId);
        commonTermLink = commonTermLink.replace('{TERM}', commonTerm);

        item.find('.vocabulary-term-left').first().text(localTerm);
        item.find('.vocabulary-term-mapping').first().text('<>');
        item.find('.vocabulary-term-right').first().html(commonTermLink);
    }

    function getItemValues(item)
    {
        var itemValues =
            {
                localTerm:item.find('.vocabulary-drawer-local-term').val(),
                commonTerm:item.find('.vocabulary-drawer-common-term').text()
            };

        return itemValues;
    }

    function initialize() {
        enableSuggestions();

        startButton.on("click", function () {
            if (tableName === 'common') {
                if (!confirm('Are you sure you want to rebuild the tables?\n\nThe current tables will be DELETED.'))
                    return;
            }
            startMapping();
        });

        jQuery('.add-item-button').click(function (event)
        {
            addNewItem();
        });

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
            jQuery('#term-' + activeItemId).text(selection);
            jQuery('.modal-popup').hide();
        });

        cancelButton.click(function (event)
        {
            jQuery('.modal-popup').hide();
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
                    kind: <?php echo $kind; ?>,
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
        itemValues.id = id;

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
