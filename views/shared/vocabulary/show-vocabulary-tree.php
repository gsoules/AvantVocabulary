<?php

// The tree functions were adapted from https://kvz.io/convert-anything-to-tree-structures-in-php.html
function explodeTree($array, $delimiter = '_', $baseval = false)
{
    if(!is_array($array)) return false;
    $splitRE   = '/' . preg_quote($delimiter, '/') . '/';
    $returnArr = array();
    foreach ($array as $key => $val) {
        // Get parent parts and the current leaf
        $parts	= preg_split($splitRE, $key, -1, PREG_SPLIT_NO_EMPTY);
        $leafPart = array_pop($parts);

        // Build parent structure
        // Might be slow for really deep and large structures
        $parentArr = &$returnArr;
        foreach ($parts as $part) {
            if (!isset($parentArr[$part])) {
                $parentArr[$part] = array();
            } elseif (!is_array($parentArr[$part])) {
                if ($baseval) {
                    $parentArr[$part] = array('__base_val' => $parentArr[$part]);
                } else {
                    $parentArr[$part] = array();
                }
            }
            $parentArr = &$parentArr[$part];
        }

        // Add the final part to the structure
        if (empty($parentArr[$leafPart])) {
            $parentArr[$leafPart] = $val;
        } elseif ($baseval && is_array($parentArr[$leafPart])) {
            $parentArr[$leafPart]['__base_val'] = $val;
        }
    }
    return $returnArr;
}

function plotNode($level, $name, $identifier)
{
    if ($identifier >= 20000)
    {
        // Not a Nomenclature 4.0 identifier.
        $identifier = 0;
    }

    if ($identifier)
    {
        $href = 'https://www.nomenclature.info/parcourir-browse.app?lang=en&id=' . $identifier . '&wo=N&ws=INT';
        $link = "$name <a href='$href' target='_blank'>$identifier</a>";
    }
    else
    {
        $link = $name;
    }

    echo "<div class='vocabulary-node node-level-{$level}'>$link</div>";
}

function plotTree($tree, $indent= 0, $parents = '')
{
    foreach ($tree as $name => $kids)
    {
        if ($name == '__base_val')
            continue;

        $identifier = 0;
        if (array_key_exists($name, $tree))
        {
            $term = $tree[$name];
            if (is_array($term))
            {
                $identifier = isset($term['__base_val']) ? $term['__base_val'] : 0;
            }
            else
            {
                $identifier = $tree[$name];
            }
        }
        $ancestors = $parents ? "$parents, $name" : $name;
        plotNode($indent + 1, $ancestors, $identifier);
        if (is_array($kids))
        {
            plotTree($kids, $indent + 1, $ancestors);
        }
    }
}

$pageTitle = __('Vocabulary Hierarchy');
echo head(array('title' => $pageTitle, 'bodyclass' => 'vocabulary-tree-page'));

// Get the vocabulary kind from the URL.
$kind = isset($_GET['kind']) ? intval($_GET['kind']) : 0;
$isValidKind =
    $kind == AvantVocabulary::VOCABULARY_TERM_KIND_TYPE ||
    $kind == AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT ||
    $kind == AvantVocabulary::VOCABULARY_TERM_KIND_PLACE;

$kindName = '';
if ($isValidKind)
{
    if ($kind == AvantVocabulary::VOCABULARY_TERM_KIND_TYPE)
        $kindName = AvantVocabulary::VOCABULARY_TERM_KIND_TYPE_LABEL;
    elseif ($kind == AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT)
        $kindName = AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT_LABEL;
    elseif ($kind == AvantVocabulary::VOCABULARY_TERM_KIND_PLACE)
        $kindName = AvantVocabulary::VOCABULARY_TERM_KIND_PLACE_LABEL;
}

$referrer = $_SERVER['HTTP_REFERER'];
$onAdminPage = strpos($referrer, '/admin');
$parent = $onAdminPage ? '/admin' : '';

$url = WEB_ROOT . $parent . '/vocabulary';
$terms = array();
$commonTermRecords = get_db()->getTable('VocabularyCommonTerms')->getAllCommonTermRecordsForKind($kind);

foreach ($commonTermRecords as $commonTermRecord)
{
    $commonTerm = $commonTermRecord->common_term;
    $terms[$commonTerm] = $commonTermRecord->common_term_id;
}

$learnMoreLink = '<a href="https://digitalarchive.avantlogic.net/docs/archivist/vocabularies/" target="_blank">Learn about vocabularies</a>';
$instructions = '<div>' . __('This page displays the entire %s vocabulary hierarchy. ', $kindName) . $learnMoreLink . '</div>';
$instructions .= '<div>' . __('Nomenclature 4.0 terms are followed by their identifier number (click it for more information about the term).') . '</div>';

echo "<div class='vocabulary-controls'>";

echo "<div>";
echo "<div class='vocabulary-chooser-label'>Vocabulary: </div>";
echo "<SELECT required id='vocabulary-chooser' class='vocabulary-chooser'>";
echo "<OPTION value='0' selected disabled hidden>Select a vocabulary</OPTION>";
echo "<OPTION value='" . AvantVocabulary::VOCABULARY_TERM_KIND_TYPE . "'>" . AvantVocabulary::VOCABULARY_TERM_KIND_TYPE_LABEL . "</OPTION>";
echo "<OPTION value='" . AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT . "''>" . AvantVocabulary::VOCABULARY_TERM_KIND_SUBJECT_LABEL . "</OPTION>";
echo "<OPTION value='" . AvantVocabulary::VOCABULARY_TERM_KIND_PLACE . "'>" . AvantVocabulary::VOCABULARY_TERM_KIND_PLACE_LABEL . "</OPTION>";
echo "</SELECT>";
echo "</div>";

$referrer = $_SERVER['HTTP_REFERER'];
$onAdminPage = strpos($referrer, '/admin');
if ($isValidKind && $onAdminPage)
{
    echo "<div class='vocabulary-view-toggle'>";
    echo "<a href='../vocabulary/terms?kind=$kind'>" . __('Return to Vocabulary Editor') . "</a>";
    echo "</div>";
}
echo "</div>";

echo "<div id='vocabulary-tree-instructions'>$instructions</div>";

$tree = explodeTree($terms, ',', true);
echo "<div class='vocabulary-tree'>";
plotTree($tree);
echo "</div>";

echo foot();

?>

<script type="text/javascript">
    let vocabularyChooser = jQuery('#vocabulary-chooser');
    vocabularyChooser.val(<?php echo $kind; ?>);
    vocabularyChooser.change(function()
    {
        let selection = jQuery(this).children("option:selected").val();
        window.location.href = '<?php echo $url; ?>/tree' + '?kind=' + selection;
    });
</script>