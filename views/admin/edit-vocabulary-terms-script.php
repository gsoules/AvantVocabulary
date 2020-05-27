<script type="text/javascript">
    var activeItemId = 0;
    var actionInProgress = false;
    var addTermButton = jQuery('#add-vocabulary-term-button');
    var commonTermCount = <?php echo $commonTermCount; ?>;
    var itemEditorUrl = '<?php echo url('/vocabulary/update'); ?>';
    var kind = <?php echo $kind; ?>;
    var kindName = '<?php echo $kindName; ?>';
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
        termChooserDialogClose();
    }

    function addNewItem()
    {
        closeAllDrawers();
        enableAllItems(false);

        // Create new item's header and drawer from a copy of the first item.
        var firstItem = jQuery('ul#vocabulary-terms-list > li:first-child');
        var newItem = firstItem.clone();

        // Set the item's Id to 'item-0' so that we can find it later. Hide the header and show only the drawer.
        newItem.attr('id', 'item-0');
        newItem.find('.vocabulary-term-header').hide();
        newItem.find('.drawer-contents').show();

        // Set the new item's local and common terms to nothing.
        newItem.find('.vocabulary-drawer-local-term').val('');
        newItem.find('.vocabulary-drawer-common-term').text('');
        newItem.find('.vocabulary-drawer-common-term').attr('data-common-term-id', 0);

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
            jQuery('#item-0').remove();
            enableAddTermButton(true);
            enableAllItems(true);
        });

        // Prepend the new item to the beginning of the list.
        firstItem.before(newItem);

        initializeItemControls();
    }

    function afterRemoveItem(itemId)
    {
        jQuery('#' + itemId).remove();
    }

    function afterSaveNewItem(data, itemValues)
    {
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

            // Convert the Cancel button back into the Remove button.
            var removeButton = newItem.find('.cancel-add-button');
            removeButton.text('<?php echo __('Remove'); ?>');
            removeButton.removeClass('cancel-add-button');
            removeButton.addClass('remove-item-button');

            // Show the header for the newly added item.
            newItem.find('.vocabulary-term-header').show();
            setItemTitle(newItem, itemValues.localTerm, itemValues.commonTerm, 999);

            // Allow the user to add another item.
            enableAddTermButton(true);

            enableAllItems(true);
        }
        else
        {
            showDrawerMessage(newItem, '<?php echo __('That local term is already defined'); ?>');
        }
    }

    function afterUpdateItem(id, data)
    {
        var item = jQuery('#' + id);
        if (data['success'])
        {
            // Update the item's title with the updated local and/or common terms.
            setItemTitle(item);

            // Close the drawer.
            item.find('.drawer-contents').slideUp();
            item.find('.drawer').removeClass('opened');
            item.find('.vocabulary-term-header').removeClass('selected');

            enableAllItems(true);
        }
        else
        {
            alert(data['error']);
        }
        item.find('.update-item-button').fadeTo(0, 1.0);
    }

    function afterUpdateItemOrder()
    {
        setEditorMessage('<?php echo __('Order updated'); ?>')
    }

    function cancelItemUpdate()
    {
        closeAllDrawers();
        enableAllItems(true);
    }

    function closeAllDrawers()
    {
        var drawerButtons = jQuery('.drawer-contents');
        drawerButtons.each(function(i)
        {
            jQuery(this).hide();
        });

        var drawerHeaders = jQuery('.drawer');
        drawerHeaders.each(function(i)
        {
            jQuery(this).parent().removeClass('selected');
            jQuery(this).removeClass('opened');
        });
    }

    function enableAddTermButton(enable)
    {
        addTermButton.prop('disabled', !enable);
    }

    function enableAllItems(enable)
    {
        let cursor = '';
        var headers = jQuery('.drawer');
        headers.each(function(i)
        {
            if (enable)
            {
                jQuery(this).show();
                cursor = 'grab';
            }
            else
            {
                jQuery(this).hide();
                cursor = 'default';
            }

            // Enable or disable dragging of an item to change its order.
            jQuery('#vocabulary-terms-list').sortable('option', 'disabled', !enable);
            jQuery('.sortable-item').css('cursor', cursor);

            enableAddTermButton(enable);
        });
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
        var localTerm = item.find('.vocabulary-drawer-local-term');
        var commonTerm = item.find('.vocabulary-drawer-common-term');

        // Get the Id minus the "item-" prefix.
        var id = item.attr('id');
        id = id.substr(5);

        return {
            id: id,
            kind: kind,
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
        initializeItemControls();
        enableAllItems(true);
    }

    function initializeItemControls()
    {
        // Set up the button and sortable handlers for all the items. This method is also called when adding
        // a new item rather than having separate logic to set the handlers for just one item.

        var drawerButtons = jQuery('.drawer');
        var updateButtons = jQuery('.update-item-button');
        var cancelButtons = jQuery('.cancel-update-button');
        var removeButtons = jQuery('.remove-item-button');
        var chooseButtons = jQuery('.choose-term-button');
        var closeButton = jQuery('.close-chooser-dialog-button');

        drawerButtons.off('click');
        updateButtons.off('click');
        cancelButtons.off('click');
        removeButtons.off('click');
        chooseButtons.off('click');
        closeButton.off('click');

        cancelButtons.click(function (event)
        {
            cancelItemUpdate();
        });

        chooseButtons.click(function (event)
        {
            termChooserDialogOpen(jQuery(this).parents('li'));
        });

        closeButton.click(function (event)
        {
            termChooserDialogClose();
        });

        drawerButtons.click(function (event)
        {
            // Remember if this drawer is open or closed.
            let isOpen = jQuery(this).hasClass('opened');

            if (!isOpen)
            {
                // Only one drawer is allowed to be open at a time.
                // Before opening this drawer, make sure no other drawer is open.
                closeAllDrawers();
                enableAllItems(false);
            }

            // Toggle the state of the drawer's open indicator (arrow at far right)
            // Toggle the open/close state of the drawer itself.
            let openIndicator = jQuery(this);
            let drawer = openIndicator.parent().next();
            let header = openIndicator.parent();
            if (isOpen)
            {
                header.removeClass('selected');
                openIndicator.removeClass('opened');
                drawer.hide();
            }
            else
            {
                header.addClass('selected');
                openIndicator.addClass('opened');
                drawer.show();
            }
        });

        removeButtons.click(function (event)
        {
            removeItem(jQuery(this).parents('li').attr('id'));
        });

        updateButtons.click(function (event)
        {
            updateItem(jQuery(this).parents('li').attr('id'));
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
                setEditorMessage('<?php echo __('Moving an item'); ?>');
            },
            stop: function(event, ui)
            {
                moveItem(ui.item);
            }
        });

        jQuery('.no-remove').hide();
    }

    function moveItem(item)
    {
        var startIndex = item.data("startindex") + 1;
        var newIndex = item.index() + 1;
        if (newIndex !== startIndex)
        {
            updateItemOrder();
        }
        else
        {
            setEditorMessage('<?php echo __('Item not moved'); ?>');
        }
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

        addTermButton.click(function (event)
        {
            addNewItem();
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
                    mapping:JSON.stringify(itemValues)
                },
                success: function (data) {
                    afterSaveNewItem(data, itemValues);
                },
                error: function (request, status, error) {
                    alert('AJAX ERROR on Save ' +  JSON.stringify(request));
                }
            }
        );
    }

    function setEditorMessage(message)
    {
        jQuery('#vocablary-term-editor-message-area').text(message);
    }

    function setItemTitle(item)
    {
        var itemValues = getItemValues(item);

        let localTerm = itemValues.localTerm;
        let commonTerm = itemValues.commonTerm;
        let commonTermId = itemValues.commonTermId;

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
            commonTerm = "<a href='" + href + "' target='_blank' title='" + altText + "'>" + commonTerm + "</a>";
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

    function showDrawerMessage(item, message)
    {
        item.find('.drawer-message').text(message);
    }

    function showStatus(status)
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
                    showStatus(status);
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
        activeItemId = item.attr('id');
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

    function updateItem(id)
    {
        var item = jQuery('#' + id);
        var itemValues = getItemValues(item);

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

    function updateItemOrder()
    {
        setEditorMessage('<?php echo __('Updating database...'); ?>')

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
            showDrawerMessage(item, '<?php echo __('Either a Local Term or a Common Term or both must be specified'); ?>');
            return false;
        }
        return true;
    }
</script>
