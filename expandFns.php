<?php
/*
 * expandFns.php sets up most of the page expansion. HTTP handing takes place using an instance 
 * of the Snoopy class. Most of the page expansion depends on the classes in objects.php, 
 * particularly Template and Page.
 */

ini_set("user_agent", "Citation_bot; citations@tools.wmflabs.org");

if (!defined("HTML_OUTPUT")) {
  define("HTML_OUTPUT", -1);
}  

function html_echo($text, $alternate_text='') {
  echo (HTML_OUTPUT >= 0) ? $text : $alternate_text;
}

function quiet_echo($text, $alternate_text = '') {
  if (defined('VERBOSE') || HTML_OUTPUT >= 0) {
    echo $text;
  } else {
    echo $alternate_text;
  }
}

require_once("constants.php");
# Snoopy's ini files should be modified so the host name is en.wikipedia.org.
require_once("Snoopy.class.php");
require_once("DOItools.php");
require_once("Page.php");
require_once("Item.php");
require_once("Template.php");
require_once("Parameter.php");
require_once("Comment.php");
require_once("wikiFunctions.php");

//require_once(HOME . "credentials/mysql.login");
/* mysql.login is a php file containing:
  define('MYSQL_DBNAME', ...);
  define('MYSQL_SERVER', ...);
  define('MYSQL_PREFIX', ...);
  define('MYSQL_USERNAME', ...);
  define('MYSQL_PASSWORD', ...);
*/

require_once(HOME . "credentials/crossref.login");
/* crossref.login is a PHP file containing:
  <?php
  define('CROSSREFUSERNAME','martins@gmail.com');
  define('JSTORPASSWORD', ...);
  define('GLOBALPASSWORD', ...);
  define('JSTORUSERNAME', 'citation_bot');
  define('NYTUSERNAME', 'citation_bot');
*/

$crossRefId = CROSSREFUSERNAME;
mb_internal_encoding('UTF-8'); // Avoid ??s

//Optimisation
#ob_start(); //Faster, but output is saved until page finshed.
ini_set("memory_limit", "256M");

define("FAST_MODE", isset($_REQUEST["fast"]) ? $_REQUEST["fast"] : FALSE);
if (!isset($SLOW_MODE)) $SLOW_MODE = isset($_REQUEST["slow"]) ? $_REQUEST["slow"] : FALSE;

if (isset($_REQUEST["crossrefonly"])) {
  $crossRefOnly = TRUE;
} elseif (isset($_REQUEST["turbo"])) {
  $crossRefOnly = $_REQUEST["turbo"];
} else {
  $crossRefOnly = FALSE;
}
$edit = isset($_REQUEST["edit"]) ? $_REQUEST["edit"] : NULL;

if ($edit || isset($_GET["doi"]) || isset($_GET["pmid"])) {
  $ON = TRUE;
}

################ Functions ##############
/**
 * @codeCoverageIgnore
 */
function udbconnect($dbName = MYSQL_DBNAME, $server = MYSQL_SERVER) {
  // if the bot is trying to connect to the defunct toolserver
  if ($dbName == 'yarrow') {
    return ('\r\n # The maintainers have disabled database support.  This action will not be logged.');
  }

  // fix redundant error-reporting
  $errorlevel = ini_set('error_reporting','0');
  // connect
  $db = mysql_connect($server, MYSQL_USERNAME, MYSQL_PASSWORD) or die("\n!!! * Database server login failed.\n This is probably a temporary problem with the server and will hopefully be fixed soon.  The server returned: \"" . mysql_error() . "\"  \nError message generated by /res/mysql_connect.php\n");
  // select database
  if ($db && $server == "sql") {
     mysql_select_db(str_replace('-','_',MYSQL_PREFIX . $dbName)) or print "\nDatabase connection failed: " . mysql_error() . "";
  } elseif ($db) {
     mysql_select_db($dbName) or die(mysql_error());
  } else {
    die ("\nNo DB selected!\n");
  }
  // restore error-reporting
  ini_set('error-reporting',$errorlevel);
  return ($db);
}

function sanitize_doi($doi) {
  return str_replace(HTML_ENCODE, HTML_DECODE, trim(urldecode($doi)));
}

/* extract_doi
 * Returns an array containing:
 * 0 => text containing a DOI, possibly encoded, possibly with additional text
 * 1 => the decoded DOI
 */
function extract_doi($text) {
  if (preg_match(
        "~(10\.\d{4}\d?(/|%2[fF])..([^\s\|\"\?&>]|&l?g?t;|<[^\s\|\"\?&]*>)+)~",
        $text, $match)) {
    $doi = $match[1];
    if (preg_match(
          "~^(.*?)(/abstract|/e?pdf|/full|</span>|[\s\|\"\?]|</).*+$~",
          $doi, $new_match)
        ) {
      $doi = $new_match[1];
    }
    return array($match[0], sanitize_doi($doi));
  }
  return NULL;
}

function format_title_text($title) {
  $replacement = [];
  if (preg_match_all("~<(?:mml:)?math[^>]*>(.*?)</(?:mml:)?math>~", $title, $matches)) {
    $placeholder = [];
    for ($i = 0; $i < count($matches[0]); $i++) {
      $replacement[$i] = '<math>' . 
        str_replace(array_keys(MML_TAGS), array_values(MML_TAGS), 
          str_replace(['<mml:', '</mml:'], ['<', '</'], $matches[1][$i]))
        . '</math>';
      $placeholder[$i] = sprintf(TEMP_PLACEHOLDER, $i); 
      // Need to use a placeholder to protect contents from URL-safening
      $title = str_replace($matches[0][$i], $placeholder[$i], $title);
    }
  }
  $title = html_entity_decode($title, NULL, "UTF-8");
  $title = preg_replace("/\s+/"," ", $title);  // Remove all white spaces before
  $title = (mb_substr($title, -1) == ".")
            ? mb_substr($title, 0, -1)
            :(
              (mb_substr($title, -6) == "&nbsp;")
              ? mb_substr($title, 0, -6)
              : $title
            );
  $title = preg_replace('~[\*]$~', '', $title);
  $title = title_capitalization($title);
  
  $originalTags = array("<i>","</i>", '<title>', '</title>',"From the Cover: ");
  $wikiTags = array("''","''",'','',"");
  $htmlBraces  = array("&lt;", "&gt;");
  $angleBraces = array("<", ">");
  $title = sanitize_string(// order of functions here IS important!
             str_ireplace($originalTags, $wikiTags, 
               str_ireplace($htmlBraces, $angleBraces, $title)
             )
           );
  
  for ($i = 0; $i < count($replacement); $i++) {
    $title = str_replace($placeholder[$i], $replacement[$i], $title);
  }
  return($title); 
}

function under_two_authors($text) {
  return !(strpos($text, ';') !== FALSE  //if there is a semicolon
          || substr_count($text, ',') > 1  //if there is more than one comma
          || substr_count($text, ',') < substr_count(trim($text), ' ')  //if the number of commas is less than the number of spaces in the trimmed string
          );
}

/* split_authors
 * Assumes that there is more than one author to start with; 
 * check this using under_two_authors()
 */
function split_authors($str) {
  if (stripos($str, ';')) return explode(';', $str);
  return explode(',', $str);
}

function title_case($text) {
  return mb_convert_case($text, MB_CASE_TITLE, "UTF-8");
}

function restore_italics ($text) {
  // <em> tags often go missing around species names in CrossRef
  return preg_replace('~([a-z]+)([A-Z][a-z]+\b)~', "$1 ''$2''", $text);
}

/** Returns a properly capitalised title.
 *      If $caps_after_punctuation is TRUE (or there is an abundance of periods), it allows the 
 *      letter after colons and other punctuation marks to remain capitalized.
 *      If not, it won't capitalise after : etc.
 */
function title_capitalization($in, $caps_after_punctuation = TRUE) {
  // Use 'straight quotes' per WP:MOS
  $new_case = straighten_quotes($in);
  
  if ( $new_case == mb_strtoupper($new_case) 
     && mb_strlen(str_replace(array("[", "]"), "", trim($in))) > 6
     ) {
    // ALL CAPS to Title Case
    $new_case = mb_convert_case($new_case, MB_CASE_TITLE, "UTF-8");
  }
  $new_case = substr(str_replace(UC_SMALL_WORDS, LC_SMALL_WORDS, $new_case . " "), 0, -1);
    
  if ($caps_after_punctuation || (substr_count($in, '.') / strlen($in)) > .07) {
    // When there are lots of periods, then they probably mark abbrev.s, not sentance ends
    // We should therefore capitalize after each punctuation character.
    $new_case = preg_replace_callback("~[?.:!]\s+[a-z]~u" /* Capitalise after punctuation */,
      create_function('$matches', 'return mb_strtoupper($matches[0]);'),
      $new_case);
  }
  
  $new_case = preg_replace_callback(
    "~\w{2}'[A-Z]\b~u" /* Lowercase after apostrophes */, 
    create_function('$matches', 'return mb_strtolower($matches[0]);'),
    trim($new_case)
  );
  // Solitary 'a' should be lowercase
  $new_case = preg_replace("~(\w\s+)A(\s+\w)~u", "$1a$2", $new_case);
  
  // Catch some specific epithets, which should be lowercase
  $new_case = preg_replace_callback(
    "~(?:'')?(?P<taxon>\p{L}+\s+\p{L}+)(?:'')?\s+(?P<nova>(?:(?:gen\.? no?v?|sp\.? no?v?|no?v?\.? sp|no?v?\.? gen)\b[\.,\s]*)+)~ui" /* Species names to lowercase */,
    create_function('$matches', 'return "\'\'" . ucfirst(strtolower($matches[\'taxon\'])) . "\'\' " . strtolower($matches["nova"]);'),
    $new_case);
  
  // Capitalization exceptions, e.g. Elife -> eLife
  $new_case = str_replace(UCFIRST_JOURNAL_ACRONYMS, JOURNAL_ACRONYMS, " " .  $new_case . " ");
  $new_case = substr($new_case, 1, strlen($new_case) - 2); // remove spaces, needed for matching in LC_SMALL_WORDS
    
  /* I believe we can do without this now
  if (preg_match("~^(the|into|at?|of)\b~", $new_case)) {
    // If first word is a little word, it should still be capitalized
    $new_case = ucfirst($new_case);
  }
  */
  return $new_case;
}

function tag($long = FALSE) {
  $dbg = array_reverse(debug_backtrace());
  array_pop($dbg);
  array_shift($dbg);
  foreach ($dbg as $d) {
    if ($long) {
      $output = '> ' . $d['function'];
    } else {
      $output = '> ' . substr(preg_replace('~_(\w)~', strtoupper("$1"), $d['function']), -7);
    }
  }
  echo ' [..' . htmlspecialchars($output) . ']';
}

function sanitize_string($str) {
  // ought only be applied to newly-found data.
  if (trim($str) == 'Science (New York, N.Y.)') return 'Science';
  $math_templates_present = preg_match_all("~<\s*math\s*>.*<\s*/\s*math\s*>~", $str, $math_hits);
  if ($math_templates_present) {
    $replacement = [];
    $placeholder = [];
    for ($i = 0; $i < count($math_hits[0]); $i++) {
      $replacement[$i] = $math_hits[0][$i];
      $placeholder[$i] = sprintf(TEMP_PLACEHOLDER, $i);
    }
    $str = str_replace($replacement, $placeholder, $str);
  }
  $dirty = array ('[', ']', '|', '{', '}');
  $clean = array ('&#91;', '&#93;', '&#124;', '&#123;', '&#125;');
  $str = trim(str_replace($dirty, $clean, preg_replace('~[;.,]+$~', '', $str)));
  if ($math_templates_present) {
    $str = str_replace($placeholder, $replacement, $str);
  }
  return $str;
}

function prior_parameters($par, $list=array()) {
  array_unshift($list, $par);
  if (preg_match('~(\D+)(\d+)~', $par, $match)) {
    switch ($match[1]) {
      case 'first': case 'initials': case 'forename':
        return array('last' . $match[2], 'surname' . $match[2]);
      case 'last': case 'surname': 
        return array('first' . ($match[2]-1), 'forename' . ($match[2]-1), 'initials' . ($match[2]-1));
      default: return array($match[1] . ($match[2]-1));
    }
  }
  switch ($par) {
    case 'title':       return prior_parameters('author', array_merge(array('author', 'authors', 'author1', 'first1', 'initials1'), $list) );
    case 'journal':       return prior_parameters('title', $list);
    case 'volume':       return prior_parameters('journal', $list);
    case 'issue': case 'number':       return prior_parameters('volume', $list);
    case 'page' : case 'pages':       return prior_parameters('issue', $list);

    case 'pmid':       return prior_parameters('doi', $list);
    case 'pmc':       return prior_parameters('pmid', $list);
    default: return $list;
  }
}
?>
