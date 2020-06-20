<script type="text/javascript">
    const addTermButton = jQuery('#add-vocabulary-term-button');
    const busyIndicator = jQuery('#vocabulary-editor-busy');
    const commonTermCount = '<?php echo $commonTermCount; ?>';
    const defaultMessage = '<?php echo __('To edit a term, click its pencil icon.&nbsp;&nbsp;Drag terms up or down to reorder them.&nbsp;&nbsp;<a href="https://digitalarchive.avantlogic.net/docs/#archivist/vocabulary-editor/" target="_blank">Learn more</a>'); ?>';
    const elementId = <?php echo $elementId; ?>;
    const kind = <?php echo $kind; ?>;
    const kindName = '<?php echo $kindName; ?>';
    const rebuildCommonTermsButton = jQuery("#rebuild-common-terms-button").button();
    const rebuildLocalTermsButton = jQuery("#rebuild-local-terms-button").button();
    const termChooserDialogInput = jQuery("#vocabulary-term-input");
    const termChooserDialogMessage = jQuery('#vocabulary-term-message');
    const urlForEditorPage = '<?php echo $url; ?>/terms';
    const urlForTermEditor = '<?php echo $url; ?>/update';


    let activeItemId = 0;
    let actionInProgress = false;
    let originalItemValues = null;
    let progressCount = 0;
    let progressTimer;
    let tableName = '';
    let termChooserDialogTimer;
    let termChooserResultsCount = 0;
    let updateTimer;

    jQuery(document).ready(function ()
    {
        initialize();
    });

    function acceptTerm(term)
    {
        let item = jQuery('#' + activeItemId);
        let commonTerm = item.find('.vocabulary-drawer-common-term');
        commonTerm.text(term);
        termChooserDialogClose();
    }

    function addNewItem()
    {
        console.log('addNewItem');

        // Create new item's header and drawer from a copy of the first item.
        let firstItem = jQuery('ul#vocabulary-terms-list > li:first-child');
        let newItem = firstItem.clone();

        // Get rid of any leftover message.
        showDrawerErrorMessage(newItem, '');

        // Set the item's Id to 'item-0' so that we can find it later. Hide the header and show only the drawer.
        activeItemId = 'item-0';
        newItem.attr('id', activeItemId);
        newItem.find('.vocabulary-term-header').hide();
        newItem.find('.drawer-contents').show();

        // Set the new item's values nothing.
        newItem.find('.vocabulary-drawer-local-term').val('');
        newItem.find('.vocabulary-term-count').text('0');
        newItem.find('.vocabulary-drawer-common-term').text('');
        newItem.find('.vocabulary-drawer-common-term').attr('data-common-term-id', 0);

        // Hide buttons that are not needed when adding an item.
        newItem.find('.remove-item-button').hide();

        // Prepend the new item to the beginning of the list.
        firstItem.before(newItem);

        // Initialize the buttons for the drawer. This call initializes the drawers for all items, even though
        // only this one needs it, but it's simpler doing it this way than having logic for a single drawer.
        initializeDrawerControls();

        // Convert the Update button into the Save button.
        let saveButton = newItem.find('.update-item-button');
        saveButton.text('<?php echo __('Save'); ?>');
        saveButton.off('click');
        saveButton.click(function ()
        {
            saveNewItem();
        });

        // Disallow editing of another item while adding a new item.
        enableAllItems(false, '<?php echo __('Add a new term by providing a Local and/or Common Term name'); ?>');

        // Start watching for updates.
        updateTimer = setTimeout(checkForItemUpdates, 100);
    }

    function afterNewItemSaved(data, itemValues)
    {
        console.log('afterNewItemSaved');

        // Stop watching for updates.
        clearTimeout(updateTimer);

        let newItem = jQuery('#' + activeItemId);

        if (data['success'])
        {
            // Set the item's Id from the Id of the newly inserted database record.
            newItem.attr('id', 'item-' + data['id']);
            newItem.find('.drawer-contents').hide();

            // Set the common term Id for the new item.
            let commonTermId = data['commonTermId'];
            newItem.find('.vocabulary-drawer-common-term').attr('data-common-term-id', commonTermId);

            // Convert the Save button back into the Update button.
            let updateButton = newItem.find('.update-item-button');
            updateButton.text('<?php echo __('Update'); ?>');
            updateButton.off('click');
            updateButton.click(function ()
            {
                let item = getItemForButton(this);
                updateItemConfirmation(item);
            });

            // Allow the new item to be removed.
            newItem.find('.remove-item-button').show();

            // Show the header for the newly added item.
            newItem.find('.vocabulary-term-header').show();
            setItemTitle(newItem, itemValues.localTerm, itemValues.commonTerm, 999);

            enableAllItems(true);
            showEditorMessage('<?php echo __('New term added'); ?>');
        }
        else
        {
            // Report that the add was not accepted which because the new term is the same as an existing term.
            let term = itemValues.localTerm ? itemValues.localTerm : itemValues.commonTerm;
            showErrorTermAlreadyExists(newItem, term, kindName);

            reportAddOrUpdateError(data['error'], newItem, term)
        }
    }

    function afterRemoveItem(item, data)
    {
        console.log('afterRemoveItem');

        // Stop watching for updates.
        clearTimeout(updateTimer);

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
            // Set the common term Id for the update item.
            let commonTermId = data['commonTermId'];
            item.find('.vocabulary-drawer-common-term').attr('data-common-term-id', commonTermId);

            // Update the item's title with the updated local and/or common terms.
            setItemTitle(item);

            // Close the drawer.
            openDrawer(item, false);
        }
        else
        {
            let itemValues = getItemValues(item);
            reportAddOrUpdateError(data['error'], item, itemValues.localTerm);
        }
    }

    function afterUpdateItemOrder()
    {
        showEditorMessage('')
    }

    function cancelItemUpdate(item)
    {
        if (originalItemValues)
        {
            item.find('.vocabulary-drawer-local-term').val(originalItemValues['localTerm']);
            item.find('.vocabulary-drawer-common-term').text(originalItemValues['commonTerm']);
            item.find('.vocabulary-drawer-common-term').attr('data-common-term-id', originalItemValues['commonTermId']);
            originalItemValues = null;
        }

        // Close the drawer.
        openDrawer(item, false);
    }

    function checkForItemUpdates()
    {
        let item = jQuery('#' + activeItemId);
        let itemValues = getItemValues(item);

        let originalLocalTerm = originalItemValues ? originalItemValues['localTerm'] : '';
        let originalCommonTerm = originalItemValues ? originalItemValues['commonTerm'] : '';

        // Determine whether any values have changed and enable/disable the Update or Save button accordingly.
        let updateButton = item.find('.update-item-button');
        let termChanged = false;
        if (itemValues['localTerm'] !== originalLocalTerm)
            termChanged = true;
        else if (itemValues['commonTerm'] !== originalCommonTerm)
            termChanged = true;

        console.log('checkForItemUpdates: [' + itemValues['localTerm'] + '] [' + originalLocalTerm + '] [' + itemValues['commonTerm'] + '] [' + originalCommonTerm + ']' + termChanged);
        updateButton.prop('disabled', !termChanged);

        // Determine whether to show and enable/disable the Erase button.
        let disableEraseButton = itemValues['commonTerm'].length === 0;
        let eraseButton = item.find('.erase-common-term-button');
        eraseButton.prop('disabled', disableEraseButton);

        updateTimer = setTimeout(checkForItemUpdates, 500);
    }

    function enableAddTermButton(enable)
    {
        console.log('enableAddTermButton: ' + enable);
        addTermButton.prop('disabled', !enable);
    }

    function enableAllItems(enable, action)
    {
        console.log('enableAllItems: ' + enable);

        // Enable or disable dragging of an item to change its order.
        jQuery('#vocabulary-terms-list').sortable('option', 'disabled', !enable);
        let cursor = enable ? 'grab' : 'default';
        jQuery('.sortable-item').css('cursor', cursor);

        let editIcons = jQuery('.vocabulary-term-edit-icon');
        if (enable)
        {
            editIcons.css('visibility', 'visible');

            // Erase any message from a previous action.
            showEditorMessage('');
        }
        else
        {
            editIcons.css('visibility', 'hidden');
            showEditorMessage(action);
        }

        enableAddTermButton(enable);
    }

    function enableSuggestions()
    {
        // Set up the autocomplete control.
        jQuery(termChooserDialogInput).autocomplete(
        {
            source: urlForEditorPage + '?kind=' + kind,
            delay: 250,
            minLength: 2,
            appendTo: '#vocabulary-modal-dialog',
            search: function()
            {
                let term = termChooserDialogInput.val();
                let message = 'Searching for "'  + term + '" among ' + commonTermCount + ' ' + kindName + ' terms. Please wait...';
                termChooserDialogSetMessage(message);
            },
            response: function(event, ui)
            {
                termChooserResultsCount = ui.content.length;
                if (termChooserResultsCount === 0)
                {
                    let term = termChooserDialogInput.val();
                    termChooserDialogSetMessage('No ' + kindName + ' contains "' + term + '"');
                }
                else
                {
                    let resultMessage = '1 result';
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

    function eraseCommonTerm(item)
    {
        let commonTermField = item.find('.vocabulary-drawer-common-term');
        commonTermField.fadeOut('slow', function() {
            commonTermField.text('');
            commonTermField.attr('data-common-term-id', 0);
            commonTermField.show();
        });
    }

    function getItemValues(item)
    {
        let localTerm = item.find('.vocabulary-drawer-local-term').val();
        let commonTerm = item.find('.vocabulary-drawer-common-term').text();
        let commonTermId = item.find('.vocabulary-drawer-common-term').attr('data-common-term-id');
        let usageCount = item.find('.vocabulary-term-count').text();
        usageCount = parseInt(usageCount, 10);

        // Get the Id minus the "item-" prefix.
        let id = item.attr('id');
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
        showEditorMessage('Get started');
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

        let drawerButtons = jQuery('.vocabulary-term-edit-icon');
        let updateButtons = jQuery('.update-item-button');
        let cancelButtons = jQuery('.cancel-update-button');
        let removeButtons = jQuery('.remove-item-button');
        let chooseButtons = jQuery('.choose-common-term-button');
        let eraseButtons = jQuery('.erase-common-term-button');
        let closeButton = jQuery('.close-chooser-dialog-button');

        // Remove all the click event handlers.
        drawerButtons.off('click');
        updateButtons.off('click');
        cancelButtons.off('click');
        removeButtons.off('click');
        chooseButtons.off('click');
        eraseButtons.off('click');
        closeButton.off('click');

        // Add click event handlers.

        cancelButtons.click(function(event)
        {
            let item = getItemForButton(this);
            cancelItemUpdate(item);

            // Remove a cancelled new item if it exists.
            jQuery('#item-0').remove();

        });

        chooseButtons.click(function(event)
        {
            let item = getItemForButton(this);
            termChooserDialogOpen(item);
        });

        closeButton.click(function(event)
        {
            termChooserDialogClose();
        });

        drawerButtons.click(function(event)
        {
            let item = getItemForButton(this);
            openDrawer(item, true);
        });

        removeButtons.click(function(event)
        {
            let item = getItemForButton(this);
            removeItemConfirmation(item);
        });

        eraseButtons.click(function(event)
        {
            let item = getItemForButton(this);
            eraseCommonTerm(item);
        });

        updateButtons.click(function(event)
        {
            let item = getItemForButton(this);
            updateItemConfirmation(item);
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
                showEditorMessage('<?php echo __('Moving a term...'); ?>');
            },
            stop: function(event, ui)
            {
                moveItem(ui.item);
                showEditorMessage('');
            }
        });

        // Disable the Update buttons and Erase buttons. They will get enabled by checkForItemUpdates when appropriate.
        updateButtons.prop('disabled', true);
        eraseButtons.prop('disabled', true);

        // Hide buttons that don't apply to an item.
        jQuery('.hide').hide();
    }

    function initializePageControls()
    {
        console.log('initializePageControls');

        let vocabularyChooser = jQuery('#vocabulary-chooser');
        vocabularyChooser.val(kind);
        vocabularyChooser.change(function()
        {
            let selection = jQuery(this).children("option:selected").val();
            window.location.href = urlForEditorPage + '?kind=' + selection;
        });

        addTermButton.click(function(event)
        {
            addNewItem();
        });

        rebuildCommonTermsButton.on("click", function(event)
        {
            if (!confirm('Are you sure you want to rebuild the Common Terms table?'))
                return;
            tableName = 'common';
            startRebuild();
        });

        rebuildLocalTermsButton.on("click", function(event)
        {
            if (!confirm('Are you sure you want to rebuild the Local Terms table?\n\nALL MAPPED AND UNUSED TERMS WILL BE LOST.'))
                return;
            tableName = 'local';
            startRebuild();
        });
    }

    function moveItem(item)
    {
        let startIndex = item.data("startindex") + 1;
        let newIndex = item.index() + 1;
        if (newIndex !== startIndex)
        {
            updateItemOrder();
        }
    }

    function openDrawer(item, open)
    {
        console.log('open drawer: ' + open);

        activeItemId = open ? item.attr('id') : 0;

        let header = item.find('.vocabulary-term-header');
        let drawerContents = item.find('.drawer-contents');

        if (open)
        {
            // Prevent the user from editing or dragging any items.
            enableAllItems(false, '<?php echo __('Editing a term'); ?>');

            // Set the header to its open appearance.
            header.addClass('selected');

            // Get rid of any leftover message.
            showDrawerErrorMessage(item, '');

            // Open the drawer.
            drawerContents.show();

            // Record the drawer's contents in case the user cancels and we have to restore them.
            rememberOriginalValues(item);

            // Start watching for updates.
            updateTimer = setTimeout(checkForItemUpdates, 100);
        }
        else
        {
            // Allow the user to edit or drag items.
            enableAllItems(true);

            // Set the header back to its normal appearance.
            header.removeClass('selected');

            // Close the drawer.
            drawerContents.slideUp();

            // Stop watching for updates.
            clearTimeout(updateTimer);
        }
    }

    function rememberOriginalValues(item)
    {
        originalItemValues = getItemValues(item);
    }

    function removeItem(item, itemValues)
    {
        item.fadeTo(750, 0.20);

        jQuery.ajax(
            urlForTermEditor,
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

    function removeItemConfirmation(item)
    {
        let itemValues = getItemValues(item);

        let term = itemValues['localTerm'] ? itemValues['localTerm'] : itemValues['commonTerm'];
        let message = '<?php echo __('This will remove "{1}" from the {2} vocabulary. This action is harmless since no items are using this term.'); ?>';
        message = message.replace('{1}', term);
        message = message.replace('{2}', kindName);

        jQuery("#dialog-confirm-remove-term p").text(message);

        jQuery("#dialog-confirm-remove-term").dialog({
            autoOpen: true,
            resizable: false,
            height: "auto",
            width: 400,
            modal: true,
            buttons: {
                '<?php echo __('Remove'); ?>': function() {
                    jQuery(this).dialog( "close" );
                    removeItem(item, itemValues);
                }
            }
        });
    }

    function reportAddOrUpdateError(error, item, term)
    {
        if (error === 'local-term-exists')
        {
            showErrorTermAlreadyExists(item, term, kindName);
        }
        else if (error === 'local-term-is-common-term')
        {
            showErrorLocalTermIsCommonTerm(item, term, kindName);
        }
        else
        {
            alert(error);
        }
    }

    function reportAjaxError(request, action)
    {
        let message = JSON.stringify(request);
        message = message.replace(/(<([^>]+)>)/ig,"");
        message = message.replace(/\\n/g, '\n');
        alert('AJAX ERROR on ' + action + ' >>> ' + message);
    }

    function reportProgress()
    {
        if (!actionInProgress)
            return;

        // Call back to the server (this page) to get the status of the action.
        // The server returns the complete status since the action began, not just what has since transpired.
        jQuery.ajax(
            urlForEditorPage,
            {
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'progress',
                    table_name: tableName
                },
                success: function (data)
                {
                    if (actionInProgress)
                    {
                        showBusyIndicator(data);
                        progressTimer = setTimeout(reportProgress, 200);
                    }
                },
                error: function(request, status, error)
                {
                    reportAjaxError(request, 'reportProgress');
                }
            }
        );
    }

    function saveNewItem()
    {
        console.log('saveNewItem');

        showBusyIndicator('<?php echo __('Adding new term to the database...'); ?>');

        let newItem = jQuery('#item-0');
        let itemValues = getItemValues(newItem);
        if (!validateItemValues(newItem, itemValues))
            return;

        jQuery.ajax(
            urlForTermEditor,
            {
                method: 'POST',
                dataType: 'json',
                data: {
                    action: <?php echo VocabularyTermsEditor::ADD_VOCABULARY_TERM; ?>,
                    kind: kind,
                    itemValues:JSON.stringify(itemValues)
                },
                success: function (data) {
                    showBusyIndicator('');
                    afterNewItemSaved(data, itemValues);
                },
                error: function (request, status, error)
                {
                    reportAjaxError(request, 'Save');
                }
            }
        );
    }

    function setItemTitle(item)
    {
        let itemValues = getItemValues(item);

        let localTerm = itemValues.localTerm;
        let commonTerm = itemValues.commonTerm;
        let commonTermId = itemValues.commonTermId;
        let commonTermLink = commonTerm;

        let isNomenclatureTerm = commonTermId < <?php echo AvantVocabulary::VOCABULARY_FIRST_NON_NOMENCLATURE_COMMON_TERM_ID; ?>;
        if (commonTerm && isNomenclatureTerm)
        {
            // The common term is from Nomenclature. Display it as a link to that term on the Nomenclature website.
            // If it's really long, truncate it so that it won't wrap. The full term can be seen in the drawer.
            // const maxTermLen = 60;
            // if (commonTerm.length > maxTermLen)
            //     commonTerm = commonTerm.substr(0, maxTermLen) + '...';

            let href = 'https://www.nomenclature.info/parcourir-browse.app?lang=en&id=' + commonTermId +'&wo=N&ws=INT';
            let altText = '<?php echo __('View the Nomenclature 4.0 specification for term '); ?>' + commonTermId;
            commonTermLink = "<a href='" + href + "' target='_blank' title='" + altText + "'>" + commonTerm + "</a>";
        }

        let leftTerm;
        let rightTerm
        let mappingIndicator;
        let usageTerm;

        if (localTerm && commonTerm && localTerm !== commonTerm)
        {
            leftTerm = localTerm + '<span class="mapped">(' + commonTermLink + ')<span>';
            mappingIndicator = '<?php echo __('mapped'); ?>';
            usageTerm = localTerm;
        }
        else if (localTerm && !commonTerm)
        {
            leftTerm = localTerm;
            leftTerm = '<span class="unmapped">' + leftTerm + '<span>';
            mappingIndicator = '<span><?php echo __('unmapped'); ?><span>';
            usageTerm = localTerm;
        }
        else
        {
            leftTerm = commonTermLink;
            mappingIndicator = '<?php echo __('common'); ?>';
            usageTerm = commonTerm;
        }

        item.find('.vocabulary-term-left').html(leftTerm);
        item.find('.vocabulary-term-mapping').html(mappingIndicator);

        let usageCount = itemValues.usageCount;
        if (usageCount !== 0)
        {
            // The term is in use. Display it as a link to search results of the items that use it.
            let href = '../../find?advanced[0][element_id]=' + kindName + '&advanced[0][type]=is+exactly&advanced[0][terms]=' + usageTerm;

            // Make the link search only the local site since that's what the usages are for.
            href += '&site=0'

            let altText = '<?php echo __('View the items that use this term'); ?>';
            let usageCountLink = "<a href='" + href + "' target='_blank' title='" + altText + "'>" + usageCount + "</a>";
            item.find('.vocabulary-term-count').html(usageCountLink);
        }
        else
        {
            item.find('.vocabulary-term-count').html('');
        }
    }

    function setItemTitles()
    {
        jQuery('.vocabulary-term-item').each(function(i)
        {
            let item = jQuery(this);
            setItemTitle(item)
        });
    }

    function showBusyIndicator(message)
    {
        if (message.length > 0)
        {
            busyIndicator.text(message);
            busyIndicator.show();
        }
        else
        {
            busyIndicator.hide();
        }
    }

    function showDrawerErrorMessage(item, message)
    {
        item.find('.drawer-message').html(message);
    }

    function showEditorMessage(message)
    {
        if (message.length === 0)
            message = defaultMessage;
        jQuery('#vocabulary-term-editor-message-area').html(message);
    }

    function showErrorTermAlreadyExists(item, term, kindName)
    {
        let message = '<?php echo __('The {1} vocabulary already contains "{2}"'); ?>';
        message = message.replace('{1}', kindName);
        message = message.replace('{2}', term);
        showDrawerErrorMessage(item, message);
    }

    function showErrorLocalTermIsCommonTerm(item, term, kindName)
    {
        let message = '<?php echo __('"{1}" is a common term and cannot be used as a local term.</br>Remove it from the Local Term field and then choose it for the Common Term.'); ?>';
        message = message.replace('{1}', term);
        showDrawerErrorMessage(item, message);
    }

    function startRebuild()
    {
        actionInProgress = true;
        showBusyIndicator('');

        enableRebuildButtons(false);

        // Initiate periodic calls back to the server to get the status of the action.
        progressCount = 0;
        progressTimer = setTimeout(reportProgress, 200);

        // Call back to the server (this page) to initiate the action which can take several minutes.
        // While waiting, the reportProgress function is called on a timer to get the status of the action.
        jQuery.ajax(
            urlForEditorPage,
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
                    clearTimeout(progressTimer);
                    if (data['success'])
                    {
                        status = 'Build completed';
                        window.setTimeout(function () { busyIndicator.fadeOut(); }, 2000)
                    }
                    else
                    {
                        status = 'Build failed: ' + data['error'];
                    }
                    showBusyIndicator(status);
                    enableRebuildButtons(true);
                },
                error: function (request, status, error)
                {
                    clearTimeout(progressTimer);
                    reportAjaxError(request, 'build ' + tableName);
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

    function updateItem(item, itemValues)
    {
        console.log('updateItem');

        showBusyIndicator('<?php echo __('Updating items in database...'); ?>');

        actionInProgress = true;
        progressTimer = setTimeout(reportProgress, 200);

        // Disable the Update button so that the user can't click it again during the update.
        item.find('.update-item-button').prop('disabled', true);

        jQuery.ajax(
            urlForTermEditor,
            {
                method: 'POST',
                dataType: 'json',
                data: {
                    action: <?php echo VocabularyTermsEditor::UPDATE_VOCABULARY_TERM; ?>,
                    itemValues: JSON.stringify(itemValues)
                },
                success: function (data) {
                    actionInProgress = false;
                    showBusyIndicator('');
                    afterUpdateItem(item, data);
                },
                error: function (data) {
                    alert('AJAX Error on Update: ' + data.statusText);
                }
            }
        );
    }

    function updateItemConfirmation(item)
    {
        let itemValues = getItemValues(item);
        let usageCount = item.find('.vocabulary-term-count').text();

        if (!validateItemValues(item, itemValues))
            return;

        console.log('updateItemConfirmation: ' + usageCount);

        if (usageCount === '0')
        {
            updateItem(item, itemValues);
        }
        else
        {
            // Form the update confirmation message. There are multiple cases:
            // 1 : Mapped local term changed -> update all items to use changed term
            // 2 : Mapped local term erased -> update all items to use common term
            // 3 : Mapped local term added -> update all items to use local term
            // 4 : Unmapped common term change -> update all items to use changed term
            // 5 : Mapped common term changed -> reindex all items to use changed term
            // 6 : Mapped common term removed -> reindex all items to remove common term

            let affected = usageCount === '1' ? '<?php echo __('1 item'); ?>' : usageCount + ' <?php echo __('items'); ?>';
            let term = itemValues['localTerm'] ? itemValues['localTerm'] : itemValues['commonTerm'];
            let message1 = '<?php echo __('{1} will be updated'); ?>';
            let message2 = '<?php echo __('The {2} will be set to "{3}"'); ?>';
            message1 = message1.replace('{1}', affected);
            message2 = message2.replace('{3}', term);
            message2 = message2.replace('{2}', kindName);

            jQuery("#dialog-confirm-update-term h2").text(message1);
            jQuery("#dialog-confirm-update-term p").text(message2);

            jQuery("#dialog-confirm-update-term").dialog({
                autoOpen: true,
                resizable: false,
                height: "auto",
                width: 600,
                modal: true,
                buttons: {
                    '<?php echo __('Update'); ?>': function() {
                        jQuery(this).dialog( "close" );
                        updateItem(item, itemValues);
                    }
                }
            });
        }
    }

    function updateItemOrder()
    {
        showBusyIndicator('<?php echo __('Updating vocabulary term order in database...'); ?>');

        let order = jQuery('ul#vocabulary-terms-list > li')
            .map(function(i, e)
            {
                // Get the Id minus the "item-" prefix.
                let id = e.id;
                id = id.substr(5);
                return id;
            })
            .get();

        jQuery.ajax(
            urlForTermEditor,
            {
                method: 'POST',
                dataType: 'json',
                data: {
                    action: <?php echo VocabularyTermsEditor::UPDATE_VOCABULARY_LOCAL_TERMS_ORDER; ?>,
                    order: order
                },
                success: function (data)
                {
                    showBusyIndicator('');
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
            showDrawerErrorMessage(item, '<?php echo __('The Local Term and Common Term cannot both be blank'); ?>');
            return false;
        }

        if (itemValues.localTerm.trim() === itemValues.commonTerm)
        {
            showDrawerErrorMessage(item, '<?php echo __('The Local Term and Common Term cannot be the same'); ?>');
            return false;
        }
        return true;
    }
</script>
