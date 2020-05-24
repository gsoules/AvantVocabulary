<?php

// This code is executed when an admin clicks the Add, Remove, or Update button on the Vocabulary page.

$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action == 0)
    return;

$vocabularyTermsEditor = new VocabularyTermsEditor();
echo $vocabularyTermsEditor->performAction($action);