<script type="text/javascript">
    var activeItemId = 0;
    var actionInProgress = false;
    var addTermButton = jQuery('#add-vocabulary-term-button');
    var commonTermCount = <?php echo $commonTermCount; ?>;
    var elementId = <?php echo $elementId; ?>;
    var itemEditorUrl = '<?php echo url('/vocabulary/update'); ?>';
    var kind = <?php echo $kind; ?>;
    var kindName = '<?php echo $kindName; ?>';
    var originalItemValues;
    var progressCount = 0;
    var progressTimer;
    var rebuildCommonTermsButton = jQuery("#rebuild-common-terms-button").button();
    var rebuildLocalTermsButton = jQuery("#rebuild-local-terms-button").button();
    var statusArea = jQuery("#status-area");
    var tableName = '';
    var termChooserDialogInput = jQuery("#vocabulary-term-input");
    var termChooserDialogMessage = jQuery('#vocabulary-term-message');
    var termChooserDialogTimer;
    var termChooserResultsCount = 0;
    var updateTimer;
    var url = '<?php echo $url; ?>';

    jQuery(document).ready(function ()
    {
        initialize();
    });

    function acceptTerm(term)
    {
        var item = jQuery('#' + activeItemId);
        var commonTerm = item.find('.vocabulary-drawer-common-term');
        commonTerm.text(term);
        item.find('.erase-common-term-button').prop('disabled', false);
        termChooserDialogClose();
    }

    function afterAddNewItem(data, itemValues)
    {
        console.log('afterAddNewItem');

        var newItem = jQuery('#item-0');

        if (data['success'])
        {
            newItem.attr('id', 'item-' + data['id']);
            newItem.find('.drawer-contents').hide();

            // Convert the Save button back into the Update button.
            var updateButton = newItem.find('.save-item-button');
            updateButton.text('<?php echo __('Update'); ?>');
            updateButton.removeClass('save-item-button');
            updateButton.addClass('update-item-button');

            // Show the header for the newly added item.
            newItem.find('.vocabulary-term-header').show();
            setItemTitle(newItem, itemValues.localTerm, itemValues.commonTerm, 999);

            enableAllItems(true);
            showEditorMessage('<?php echo __('New term added'); ?>');
        }
        else
        {
            let term = itemValues.localTerm ? itemValues.localTerm : itemValues.commonTerm;
            let message = '<?php echo __('The {1} vocabulary already contains "{2}"'); ?>';
            message = message.replace('{1}', kindName);
            message = message.replace('{2}', term);
            showDrawerMessage(newItem, message);
        }
    }

    function afterRemoveItem(item, data)
    {
        if (data['success'])
        {
            let itemValues = getItemValues(item);
            jQuery('#item-' + itemValues['id']).remove();
            enableAllItems(true);
        }
        else
        {
            alert(data['error']);
        }
    }

    function afterUpdateItem(item, data)
    {
        console.log('afterUpdateItem');

        if (data['success'])
        {
            // Update the item's title with the updated local and/or common terms.
            setItemTitle(item);

            // Close the drawer.
            openDrawer(item, false);
        }
        else
        {
            alert(data['error']);
        }
        item.find('.update-item-button').fadeTo(0, 1.0);
    }

    function afterUpdateItemOrder()
    {
        showEditorMessage('<?php echo __('Order updated'); ?>')
    }

    function cancelItemUpdate(item)
    {
        if (originalItemValues)
        {
            item.find('.vocabulary-drawer-local-term').val(originalItemValues['localTerm']);
            item.find('.vocabulary-drawer-common-term').text(originalItemValues['commonTerm']);
            item.find('.vocabulary-drawer-common-term').attr('data-common-term-id', originalItemValues['commonTermId']);
        }

        // Close the drawer.
        openDrawer(item, false);
    }

    function checkForItemUpdates()
    {
        console.log('checking for updates');
        updateTimer = setTimeout(checkForItemUpdates, 500);
    }

    function enableAddTermButton(enable)
    {
        console.log('enableAddTermButton: ' + enable);
        addTermButton.prop('disabled', !enable);
    }

    function enableAllItems(enable)
    {
        console.log('enableAllItems');

        let cursor = '';
        let headers = jQuery('.vocabulary-term-edit-icon');
        headers.each(function(i)
        {
            if (enable)
            {
                cursor = 'grab';
            }
            else
            {
                cursor = 'default';
            }

            // Enable or disable dragging of an item to change its order.
            jQuery('#vocabulary-terms-list').sortable('option', 'disabled', !enable);
            jQuery('.sortable-item').css('cursor', cursor);

        });

        enableAddTermButton(enable);

        if (!enable)
        {
            // Erase any message that is currently displayed.
            showEditorMessage('');
        }
    }

    function enableSuggestions()
    {
        // Show the term count with commas as a thousands separator.
        commonTermCount = commonTermCount.toLocaleString();

        // Set up the autocomplete control.
        jQuery(termChooserDialogInput).autocomplete(
        {
            source: url + '?kind=' + kind,
            delay: 250,
            minLength: 2,
            appendTo: '#vocabulary-modal-dialog',
            search: function(event, ui)
            {
                var term = termChooserDialogInput.val();
                let message = 'Searching for "'  + term + '" among ' + commonTermCount + ' ' + kindName + ' terms. Please wait...';
                termChooserDialogSetMessage(message);
            },
            response: function(event, ui)
            {
                termChooserResultsCount = ui.content.length;
                if (termChooserResultsCount === 0)
                {
                    var term = termChooserDialogInput.val();
                    termChooserDialogSetMessage('No ' + kindName + ' contains "' + term + '"');
                }
                else
                {
                    var resultMessage = '1 result';
                    if (termChooserResultsCount > 1)
                        resultMessage = termChooserResultsCount.toLocaleString() + ' results';

                    if (termChooserResultsCount <= 10)
                        termChooserDialogSetMessage(resultMessage);
                    else
                        termChooserDialogSetMessage(resultMessage + '. To narrow down the list, type more letters or words.');
                }
            },
            select: function(event, ui)
            {
                acceptTerm(ui.item.value);
            },
            close: function(event, ui)
            {
                // The menu of suggestions closes automatically when the user clicks outside the input
                // box or presses the escape key. Show the default message only when there are results.
                // If there are no results, leave the messages saying no results found for the input.
                if (termChooserResultsCount > 0)
                {
                    termChooserDialogShowDefaultMessage();
                }
            }
        });
    }

    function enableRebuildButtons(enable)
    {
        rebuildCommonTermsButton.button("option", {disabled: !enable});
        rebuildLocalTermsButton.button("option", {disabled: !enable});
    }

    function getItemValues(item)
    {
        console.log('getItemValues');

        let localTerm = item.find('.vocabulary-drawer-local-term').val();
        let commonTerm = item.find('.vocabulary-drawer-common-term').text();
        let commonTermId = item.find('.vocabulary-drawer-common-term').attr('data-common-term-id');
        let usageCount = item.find('.vocabulary-term-count').text();
        usageCount = parseInt(usageCount, 10);

        // Get the Id minus the "item-" prefix.
        var id = item.attr('id');
        id = id.substr(5);

        return {
            id: id,
            kind: kind,
            elementId: elementId,
            localTerm: localTerm,
            commonTerm: commonTerm,
            commonTermId: commonTermId,
            usageCount: usageCount
        };
    }

    function getItemForButton(button)
    {
        return jQuery(button).parents('li');
    }

    function initialize()
    {
        setItemTitles();
        enableSuggestions();
        initializePageControls();
        initializeDrawerControls();
        enableAllItems(true);
    }

    function initializeDrawerControls()
    {
        console.log('initializeItemControls');

        // Set up the button and sortable handlers for all the items.

        var drawerButtons = jQuery('.vocabulary-term-edit-icon');
        var updateButtons = jQuery('.update-item-button');
        var cancelButtons = jQuery('.cancel-update-button');
        var removeButtons = jQuery('.remove-item-button');
        var chooseCommonTermButtons = jQuery('.choose-common-term-button');
        var removeCommonTermButtons = jQuery('.erase-common-term-button');
        var closeButton = jQuery('.close-chooser-dialog-button');

        // Remove all the click event handlers.
        drawerButtons.off('click');
        updateButtons.off('click');
        cancelButtons.off('click');
        removeButtons.off('click');
        chooseCommonTermButtons.off('click');
        removeCommonTermButtons.off('click');
        closeButton.off('click');

        // Add click event handlers.

        cancelButtons.click(function (event)
        {
            let item = getItemForButton(this);
            cancelItemUpdate(item);

            // Remove a cancelled new item if it exists.
            jQuery('#item-0').remove();

        });

        chooseCommonTermButtons.click(function (event)
        {
            let item = getItemForButton(this);
            termChooserDialogOpen(item);
        });

        closeButton.click(function (event)
        {
            termChooserDialogClose();
        });

        drawerButtons.click(function (event)
        {
            let item = getItemForButton(this);
            openDrawer(item, true);
        });

        removeButtons.click(function (event)
        {
            let item = getItemForButton(this);
            removeItem(item);
        });

        removeCommonTermButtons.click(function (event)
        {
            let item = getItemForButton(this);
            removeCommonTerm(item);
            jQuery(this).prop('disabled', true);
        });

        updateButtons.click(function (event)
        {
            let item = getItemForButton(this);
            updateItem(item);
        });

        jQuery('#vocabulary-terms-list').sortable({
            listType: 'ul',
            handle: '.main_link',
            items: 'li',
            revert: 200,
            toleranceElement: '> div',
            placeholder: 'ui-sortable-highlight',
            forcePlaceholderSize: true,
            containment: 'document',

            start: function(event, ui)
            {
                jQuery(ui.item).data("startindex", ui.item.index());
                showEditorMessage('<?php echo __('Moving an item'); ?>');
            },
            stop: function(event, ui)
            {
                moveItem(ui.item);
            }
        });

        // Hide buttons that don't apply to an item.
        jQuery('.hide').hide();
    }

    function initializePageControls()
    {
        console.log('initializePageControls');

        voabularyChooser = jQuery('#vocabulary-chooser');
        voabularyChooser.val(kind);
        voabularyChooser.change(function()
        {
            var selection = jQuery(this).children("option:selected").val();
            window.location.href = url + '?kind=' + selection;
        });

        addTermButton.click(function (event)
        {
            openDrawerForNewItem();
        });

        rebuildCommonTermsButton.on("click", function ()
        {
            if (!confirm('Are you sure you want to rebuild the Common Terms table?'))
                return;
            tableName = 'common';
            startRebuild();
        });

        rebuildLocalTermsButton.on("click", function ()
        {
            if (!confirm('Are you sure you want to rebuild the Local Terms table?'))
                return;
            tableName = 'local';
            startRebuild();
        });
    }

    function moveItem(item)
    {
        var startIndex = item.data("startindex") + 1;
        var newIndex = item.index() + 1;
        if (newIndex !== startIndex)
        {
            updateItemOrder();
        }
    }

    function openDrawer(item, open)
    {
        console.log('open drawer: ' + open);

        activeItemId = open ? item.attr('id') : 0;

        let editIcons = jQuery('.vocabulary-term-edit-icon');

        let header = item.find('.vocabulary-term-header');
        let drawerContents = item.find('.drawer-contents');

        if (open)
        {
            // Prevent the user from editing or dragging any items.
            editIcons.hide();
            enableAllItems(false);

            // Set the header to its open appearance.
            header.addClass('selected');

            // Open the drawer.
            drawerContents.show();

            // Record the drawer's contents in case the user cancels and we have to restore them.
            rememberOriginalValues(item);

            // Start watching for updates.
            updateTimer = setTimeout(checkForItemUpdates, 500);
        }
        else
        {
            // Allow the user to edit or drag items.
            editIcons.show();
            enableAllItems(true);

            // Set the header back to its normal appearance.
            header.removeClass('selected');

            // Close the drawer.
            drawerContents.slideUp();

            // Stop watching for updates.
            clearTimeout(updateTimer);
        }
    }

    function openDrawerForNewItem()
    {
        console.log('addNewItem');

        // Disallow editing of another item while adding a new item.
        enableAllItems(false);

        // Create new item's header and drawer from a copy of the first item.
        var firstItem = jQuery('ul#vocabulary-terms-list > li:first-child');
        var newItem = firstItem.clone();

        // Set the item's Id to 'item-0' so that we can find it later. Hide the header and show only the drawer.
        newItem.attr('id', 'item-0');
        newItem.find('.vocabulary-term-header').hide();
        newItem.find('.drawer-contents').show();

        // Set the new item's values nothing.
        newItem.find('.vocabulary-drawer-local-term').val('');
        newItem.find('.vocabulary-term-count').text('0');
        newItem.find('.vocabulary-drawer-common-term').text('');
        newItem.find('.vocabulary-drawer-common-term').attr('data-common-term-id', 0);

        // Hide buttons that are not needed when adding an item.
        newItem.find('.remove-item-button').hide();
        newItem.find('.erase-common-term-button').hide();

        // Convert the Update button into the Save button.
        var saveButton = newItem.find('.update-item-button');
        saveButton.text('<?php echo __('Save'); ?>');
        saveButton.removeClass('update-item-button');
        saveButton.addClass('save-item-button');
        saveButton.click(function (event)
        {
            saveNewItem();
        });

        // Prepend the new item to the beginning of the list.
        firstItem.before(newItem);

        // Initialize the buttons for the drawer. This call initializes the drawers for all items, even though
        // only this one needs it, but it's simpler doing it this way than having logic for a single drawer.
        initializeDrawerControls();

        showDrawerMessage(newItem, '<?php echo __('Specify a Local and/or Common term'); ?>');
    }

    function rememberOriginalValues(item)
    {
        originalItemValues = getItemValues(item);
    }

    function removeCommonTerm(item)
    {
        let commonTermField = item.find('.vocabulary-drawer-common-term');
        commonTermField.fadeOut('slow', function() {
            commonTermField.text('');
            commonTermField.attr('data-common-term-id', 0);
            commonTermField.show();
        });
    }

    function removeItem(item)
    {
        let itemValues = getItemValues(item);

        let term = itemValues['localTerm'] ? itemValues['localTerm'] : itemValues['commonTerm'];
        let message = '<?php echo __('Remove "{1}" from the {2} vocabulary?'); ?>';
        message = message.replace('{1}', term);
        message = message.replace('{2}', kindName);

        if (!confirm(message))
            return;

        item.fadeTo(750, 0.20);

        jQuery.ajax(
            itemEditorUrl,
            {
                method: 'POST',
                dataType: 'json',
                data: {
                    action: <?php echo VocabularyTermsEditor::REMOVE_VOCABULARY_TERM; ?>,
                    itemValues:JSON.stringify(itemValues)
                },
                success: function (data)
                {
                    afterRemoveItem(item, data);
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
                    showRebuildStatus(data);
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
        console.log('saveNewItem');

        var newItem = jQuery('#item-0');
        var itemValues = getItemValues(newItem);
        if (!validateItemValues(newItem, itemValues))
            return;

        jQuery.ajax(
            itemEditorUrl,
            {
                method: 'POST',
                dataType: 'json',
                data: {
                    action: <?php echo VocabularyTermsEditor::ADD_VOCABULARY_TERM; ?>,
                    kind: kind,
                    itemValues:JSON.stringify(itemValues)
                },
                success: function (data) {
                    afterAddNewItem(data, itemValues);
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

        let localTerm = itemValues.localTerm;
        let commonTerm = itemValues.commonTerm;
        let commonTermId = itemValues.commonTermId;
        let commonTermLink = commonTerm;

        let isNomenclatureTerm = commonTermId < <?php echo AvantVocabulary::VOCABULARY_FIRST_NON_NOMENCLATURE_COMMON_TERM_ID; ?>;
        if (commonTerm && isNomenclatureTerm)
        {
            // The common term is from Nomenclature. Display it as a link to that term on the Nomenclature website.
            // If it's really long, truncate it so that it won't wrap. The full term can be seen in the drawer.
            maxTermLen = 60;
            if (commonTerm.length > maxTermLen)
                commonTerm = commonTerm.substr(0, maxTermLen) + '...';

            let href = 'https://www.nomenclature.info/parcourir-browse.app?lang=en&id=' + commonTermId +'&wo=N&ws=INT';
            let altText = '<?php echo __('View the Nomenclature 4.0 specification for term '); ?>' + commonTermId;
            commonTermLink = "<a href='" + href + "' target='_blank' title='" + altText + "'>" + commonTerm + "</a>";
        }

        mappingIndicator = '';

        if (localTerm && commonTerm && localTerm !== commonTerm)
        {
            leftTerm = localTerm;
            rightTerm = commonTermLink;
            mappingIndicator = '<?php echo __('replaces &nbsp; &rarr;'); ?>';
        }
        else if (localTerm && !commonTerm)
        {
            leftTerm = localTerm;
            rightTerm = '';
            mappingIndicator = '<?php echo __('is not mapped'); ?>';
        }
        else
        {
            leftTerm = commonTermLink;
            rightTerm = '';
            mappingIndicator = '<?php echo __('is a common term'); ?>';
        }

        item.find('.vocabulary-term-left').html(leftTerm);
        item.find('.vocabulary-term-mapping').html(mappingIndicator);
        item.find('.vocabulary-term-right').html(rightTerm);

        let usageCount = itemValues.usageCount;
        if (usageCount !== 0)
        {
            // The term is in use. Display it as a link to search results of the items that use it.
            let href = '../../find?advanced[0][element_id]=' + kindName + '&advanced[0][type]=is+exactly&advanced[0][terms]=' + localTerm;
            let altText = '<?php echo __('View the items that use this term'); ?>';
            usageCountLink = "<a href='" + href + "' target='_blank' title='" + altText + "'>" + usageCount + "</a>";
            item.find('.vocabulary-term-count').html(usageCountLink);
        }
    }

    function setItemTitles()
    {
        jQuery('.vocabulary-term-item').each(function(i)
        {
            var item = jQuery(this);
            setItemTitle(item)
        });
    }

    function showDrawerMessage(item, message)
    {
        item.find('.drawer-message').text(message);
    }

    function showEditorMessage(message)
    {
        jQuery('#vocablary-term-editor-message-area').text(message);
    }

    function showRebuildStatus(status)
    {
        statusArea.html(statusArea.html() + status + '<BR/>');
    }

    function startRebuild()
    {
        actionInProgress = true;
        statusArea.html('');

        enableRebuildButtons(false);

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
                    let status = 'Build completed';
                    if (!data['success'])
                        status = 'Build failed: ' + data['error'];
                    console.log(status);
                    showRebuildStatus(status);
                    enableRebuildButtons(true);
                },
                error: function (request, status, error)
                {
                    clearTimeout(progressTimer);
                    alert('AJAX ERROR on build ' + tableName + ' >>> ' +  JSON.stringify(request));
                }
            }
        );
    }

    function termChooserDialogCheckInput()
    {
        termChooserDialogTimer = setTimeout(termChooserDialogCheckInput, 500);
        if (termChooserDialogInput.val().length <= 1)
            termChooserDialogShowDefaultMessage();
    }

    function termChooserDialogClose()
    {
        clearTimeout(termChooserDialogTimer);
        document.getElementById('vocabulary-modal').classList.remove('is-visible')
    }

    function termChooserDialogOpen(item)
    {
        let itemValues = getItemValues(item);

        if (itemValues.commonTerm.length)
            termChooserDialogSetMessage('<?php echo __('Search for other terms by editing the text in the box below'); ?>')

        termChooserDialogInput.val(itemValues.commonTerm);
        termChooserDialogInput.attr('placeholder', '<?php echo __('Enter words here'); ?>');

        document.getElementById('vocabulary-modal').classList.add('is-visible')

        // Give the dialog time to display before attempting to set the focus to the input field.
        window.setTimeout(function () { document.getElementById('vocabulary-term-input').focus(); }, 100);

        // Start a timer that will assess and act on the content of the input box periodically. This is necessary to
        // determine when the box has less than the minimum characters since the autocomplete control has no event to tell us.
        termChooserDialogTimer = setTimeout(termChooserDialogCheckInput, 500);
    }

    function termChooserDialogShowDefaultMessage()
    {
        termChooserDialogSetMessage('<?php echo __('Search for a term by typing in the box below'); ?>');
    }

    function termChooserDialogSetMessage(message)
    {
        termChooserDialogMessage.text(message);
    }

    function updateItem(item)
    {
        console.log('updateItem');

        var itemValues = getItemValues(item);

        let usageCount = item.find('.vocabulary-term-count').text();
        if (usageCount !== '0')
        {
            let warning = '<?php echo __('Are you sure you want to update this term and all the items that use it?'); ?>';
            if (!confirm(warning))
                return;
        }

        if (!validateItemValues(item, itemValues))
            return;

        item.find('.update-item-button').fadeTo(500, 0.20);

        jQuery.ajax(
            itemEditorUrl,
            {
                method: 'POST',
                dataType: 'json',
                data: {
                    action: <?php echo VocabularyTermsEditor::UPDATE_VOCABULARY_TERM; ?>,
                    itemValues: JSON.stringify(itemValues)
                },
                success: function (data) {
                    afterUpdateItem(item, data);
                },
                error: function (data) {
                    alert('AJAX Error on Update: ' + data.statusText);
                }
            }
        );
    }

    function updateItemOrder()
    {
        showEditorMessage('<?php echo __('Updating database...'); ?>')

        var order = jQuery('ul#vocabulary-terms-list > li')
            .map(function(i, e)
            {
                // Get the Id minus the "item-" prefix.
                let id = e.id;
                id = id.substr(5);
                return id;
            })
            .get();

        jQuery.ajax(
            itemEditorUrl,
            {
                method: 'POST',
                dataType: 'json',
                data: {
                    action: <?php echo VocabularyTermsEditor::UPDATE_VOCABULARY_LOCAL_TERMS_ORDER; ?>,
                    order: order
                },
                success: function (data)
                {
                    afterUpdateItemOrder();
                },
                error: function (data)
                {
                    alert('AJAX Error on Update Order: ' + data.statusText);
                }
            }
        );
    }

    function validateItemValues(item, itemValues)
    {
        if (itemValues.localTerm.trim().length === 0 && itemValues.commonTerm.length === 0)
        {
            showDrawerMessage(item, '<?php echo __('Please type a Local Term, or choose a Common Term, or both'); ?>');
            return false;
        }
        return true;
    }
</script>
