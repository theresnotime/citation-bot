<?php
declare(strict_types=1);

require_once 'constants.php';     // @codeCoverageIgnore
require_once 'Template.php';      // @codeCoverageIgnore

// ============================================= DOI functions ======================================
function doi_active(string $doi) : ?bool {
  // Greatly speed-up by having one array of each kind and only look for hash keys, not values
  static $cache_good = [];
  static $cache_bad  = [];
  $doi = trim($doi);
  if (isset($cache_good[$doi])) return TRUE;
  if (isset($cache_bad[$doi]))  return FALSE;
  // For really long category runs
  if (count($cache_bad) > 2500) $cache_bad = [];
  if (count($cache_good) > 100000) $cache_good = [];
  $works = doi_works($doi);
  if ($works === NULL) {
    return NULL; // @codeCoverageIgnore
  }
  if ($works === FALSE) {
    // $cache_bad[$doi] = TRUE; do not store to save memory
    return FALSE;
  }
  // DX.DOI.ORG works, but does crossref?
  $works = is_doi_active($doi);
  if ($works === NULL) {
    return NULL; // @codeCoverageIgnore
  }
  if ($works === FALSE) {
    $cache_bad[$doi] = TRUE;
    return FALSE;
  }
  $cache_good[$doi] = TRUE;
  return TRUE;
}

function doi_works(string $doi) : ?bool {
  // Greatly speed-up by having one array of each kind and only look for hash keys, not values
  static $cache_good = [];
  static $cache_bad  = BAD_DOI_ARRAY;
  $doi = trim($doi);
  if (isset($cache_good[$doi])) return TRUE;
  if (isset($cache_bad[$doi]))  return FALSE;
  // For really long category runs
  if (count($cache_bad) > 2500) $cache_bad = BAD_DOI_ARRAY;
  if (count($cache_good) > 100000) $cache_good = [];
  $works = is_doi_works($doi);
  if ($works === NULL) {
    // file_put_contents('CodeCoverage', $doi . " returns NULL from dx.doi.org \n", FILE_APPEND);
    return NULL; // @codeCoverageIgnore
  }
  if ($works === FALSE) {
    $cache_bad[$doi] = TRUE;
    return FALSE;
  }
  $cache_good[$doi] = TRUE;
  return TRUE;
}

function is_doi_active(string $doi) : ?bool {
  $doi = trim($doi);
  $headers_test = @get_headers("https://api.crossref.org/works/" . doi_encode($doi), GET_THE_HEADERS);
  if ($headers_test === FALSE) {
    sleep(2);                                                                                            // @codeCoverageIgnore
    report_inline(' .');                                                                                 // @codeCoverageIgnore
    $headers_test = @get_headers("https://api.crossref.org/works/" . doi_encode($doi), GET_THE_HEADERS); // @codeCoverageIgnore
  }
  if ($headers_test === FALSE) return NULL; // most likely bad, but will recheck again an again
  $response = $headers_test[0];
  if (stripos($response, '200 OK'       ) !== FALSE || stripos($response, 'HTTP/1.1 200') !== FALSE) return TRUE;
  if (stripos($response, '404 Not Found') !== FALSE || stripos($response, 'HTTP/1.1 404') !== FALSE) return FALSE;
  report_warning("CrossRef server error loading headers for DOI " . echoable($doi . " : " . $response));  // @codeCoverageIgnore
  return NULL;                                                                                            // @codeCoverageIgnore
}

function throttle_dx () : void {
  static $last = 0.0;
  $min_time = 40000.0;
  $now = microtime(TRUE);
  $left = (int) ($min_time - ($now - $last));
  if ($left > 0 && $left < $min_time) usleep($left); // less than min_time is paranoia, but do not want an inifinite delay
  $last = $now;
}

function is_doi_works(string $doi) : ?bool {
  set_time_limit(120);
  $doi = trim($doi);
  // And now some obvious fails
  if (strpos($doi, '/') === FALSE) return FALSE;
  if (strpos($doi, 'CITATION_BOT_PLACEHOLDER') !== FALSE) return FALSE;
  if (!preg_match('~^([^\/]+)\/~', $doi, $matches)) return FALSE;
  $registrant = $matches[1];
  // TODO this might need updated over time.  See registrant_err_patterns on https://en.wikipedia.org/wiki/Module:Citation/CS1/Identifiers
  if (strpos($registrant, '10.') === 0) { // We have to deal with valid handles in the DOI field - very rare, so only check actual DOIs
    $registrant = substr($registrant,3);
    if (preg_match('~^[^1-3]\d\d\d\d\.\d\d*$~', $registrant)) return FALSE; // 5 digits with subcode (0xxxx, 40000+); accepts: 10000–39999
    if (preg_match('~^[^1-5]\d\d\d\d$~', $registrant)) return FALSE;        // 5 digits without subcode (0xxxx, 60000+); accepts: 10000–59999
    if (preg_match('~^[^1-9]\d\d\d\.\d\d*$~', $registrant)) return FALSE;   // 4 digits with subcode (0xxx); accepts: 1000–9999
    if (preg_match('~^[^1-9]\d\d\d$~', $registrant)) return FALSE;          // 4 digits without subcode (0xxx); accepts: 1000–9999
    if (preg_match('~^\d\d\d\d\d\d+~', $registrant)) return FALSE;          // 6 or more digits
    if (preg_match('~^\d\d?\d?$~', $registrant)) return FALSE;              // less than 4 digits without subcode (with subcode is legitimate)
    if ($registrant === '5555') return FALSE;                               // test registrant will never resolve
    if (preg_match('~[^\d\.]~', $registrant)) return FALSE;                 // any character that isn't a digit or a dot
  }
  throttle_dx();

  $context = stream_context_create(CONTEXT_INSECURE);
  set_time_limit(120);
  $headers_test = @get_headers("https://doi.org/" . doi_encode($doi), GET_THE_HEADERS, $context);
  $context = stream_context_create(CONTEXT_INSECURE_11);
  if ($headers_test === FALSE) {
     sleep(2);                                                                                        // @codeCoverageIgnore
     report_inline(' .');                                                                             // @codeCoverageIgnore
     set_time_limit(120);                                                                             // @codeCoverageIgnore
     $headers_test = @get_headers("https://doi.org/" . doi_encode($doi), GET_THE_HEADERS, $context);  // @codeCoverageIgnore
  }
  if ($headers_test === FALSE) {
     sleep(5);                                                                                        // @codeCoverageIgnore
     set_time_limit(120);                                                                             // @codeCoverageIgnore
     report_inline(' .');                                                                             // @codeCoverageIgnore
     $headers_test = @get_headers("https://doi.org/" . doi_encode($doi), GET_THE_HEADERS, $context);  // @codeCoverageIgnore
  } elseif ((empty($headers_test['Location']) && empty($headers_test['location'])) || stripos($headers_test[0], '404 Not Found') !== FALSE || stripos($headers_test[0], 'HTTP/1.1 404') !== FALSE) {
     sleep(5);                                                                                        // @codeCoverageIgnore
     set_time_limit(120);                                                                             // @codeCoverageIgnore
     report_inline(' .');                                                                             // @codeCoverageIgnore
     $headers_test = @get_headers("https://doi.org/" . doi_encode($doi), GET_THE_HEADERS, $context);  // @codeCoverageIgnore
     if ($headers_test === FALSE) return FALSE; /** We trust previous failure **/                     // @codeCoverageIgnore
  }
  if (preg_match('~^10\.1038/nature\d{5}$~i', $doi) && $headers_test === FALSE) return FALSE; // Nature dropped the ball for now TODO - https://dx.doi.org/10.1038/nature05009
  if ($headers_test === FALSE) { // Use CURL instead
    if (strpos($doi, '10.2277/') === 0) return FALSE;
    $ch = curl_init();
    curl_setopt_array($ch,
            [CURLOPT_HEADER => TRUE,
             CURLOPT_RETURNTRANSFER => TRUE,
             CURLOPT_URL => "https://doi.org/" . doi_encode($doi),
             CURLOPT_TIMEOUT => 15,
             CURLOPT_CONNECTTIMEOUT => 10,
             CURLOPT_NOBODY => TRUE,
             CURLOPT_FOLLOWLOCATION => TRUE,
             CURLOPT_SSL_VERIFYHOST => 0,
             CURLOPT_SSL_VERIFYPEER => FALSE,
             CURLOPT_SSL_VERIFYSTATUS => FALSE,
             CURLOPT_USERAGENT => BOT_USER_AGENT]);
    $head = (string) @curl_exec($ch);
    $url  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (($code === 302 || $code === 200) &&
        (stripos($url, 'doi.org') === FALSE) &&
        (strlen($head) > 55 &&
        (stripos($head, 'Content-Type') !== FALSE) &&
        (stripos($head, 'location') !== FALSE)) || (stripos($url, 'dtic.mil') !== FALSE)) // Expect something, unless dtic.mil
    {
        return TRUE;
    } else {
        return NULL; // most likely bad, but will recheck again and again
    }
  }
  if (empty($headers_test['Location']) && empty($headers_test['location'])) return FALSE; // leads nowhere
  $resp0 = (string) @$headers_test[0];
  $resp1 = (string) @$headers_test[1];
  $resp2 = (string) @$headers_test[2];
  if (stripos($resp0, '404 Not Found') !== FALSE         || stripos($resp0, 'HTTP/1.1 404') !== FALSE) return FALSE; // Bad
  if (stripos($resp0, '302 Found') !== FALSE             || stripos($resp0, 'HTTP/1.1 302') !== FALSE) return TRUE;  // Good
  if (stripos($resp0, '301 Moved Permanently') !== FALSE || stripos($resp0, 'HTTP/1.1 301') !== FALSE) { // Could be DOI change or bad prefix
      if (stripos($resp1, '302 Found') !== FALSE         || stripos($resp1, 'HTTP/1.1 302') !== FALSE) {
        return TRUE;  // Good
      } elseif (stripos($resp1, '301 Moved Permanently') !== FALSE || stripos($resp1, 'HTTP/1.1 301') !== FALSE) { // Just in case code.  Curl code deals with better
        if (stripos($resp2, '200 OK') !== FALSE         || stripos($resp2, 'HTTP/1.1 200') !== FALSE) {    // @codeCoverageIgnoreStart
          return TRUE;
        } else {
          return FALSE;
        }                                                                                                  // @codeCoverageIgnoreEnd
      } else {
        return FALSE;
      }
  }
  report_minor_error("Unexpected response in is_doi_works " . echoable($headers_test[0])); // @codeCoverageIgnore
  return NULL; // @codeCoverageIgnore
}

/** @psalm-suppress UnusedParam */
function query_jstor_api(array $ids, array &$templates) : bool { // $ids not used yet   // Pointer to save memory
  $return = FALSE;
  foreach ($templates as $template) {
    if (expand_by_jstor($template)) $return = TRUE;
  }
  return $return;
}

function sanitize_doi(string $doi) : string {
  if (substr($doi, -1) === '.') {
    $try_doi = substr($doi, 0, -1);
    if (doi_works($try_doi)) { // If it works without dot, then remove it
      $doi = $try_doi;
    } elseif (doi_works($try_doi . '.x')) { // Missing the very common ending .x
      $doi = $try_doi . '.x';
    } elseif (!doi_works($doi)) { // It does not work, so just remove it to remove wikipedia error.  It's messed up
      $doi = $try_doi;
    }
  }
  $doi = safe_preg_replace('~^https?://d?x?\.?doi\.org/~i', '', $doi); // Strip URL part if present
  $doi = safe_preg_replace('~^/?d?x?\.?doi\.org/~i', '', $doi);
  $doi = safe_preg_replace('~^doi:~i', '', $doi); // Strip doi: part if present
  $doi = str_replace("+" , "%2B", $doi); // plus signs are valid DOI characters, but in URLs are "spaces"
  $doi = str_replace(HTML_ENCODE_DOI, HTML_DECODE_DOI, trim(urldecode($doi)));
  if ($pos = (int) strrpos($doi, '.')) {
   $extension = (string) substr($doi, $pos);
   if (in_array(strtolower($extension), array('.htm', '.html', '.jpg', '.jpeg', '.pdf', '.png', '.xml', '.full'))) {
      $doi = (string) substr($doi, 0, $pos);
   }
  }
  if ($pos = (int) strrpos($doi, '#')) {
   $extension = (string) substr($doi, $pos);
   if (strpos(strtolower($extension), '#page_scan_tab_contents') === 0) {
      $doi = (string) substr($doi, 0, $pos);
   }
  }
  if ($pos = (int) strrpos($doi, ';')) {
   $extension = (string) substr($doi, $pos);
   if (strpos(strtolower($extension), ';jsessionid') === 0) {
      $doi = (string) substr($doi, 0, $pos);
   }
  }
  if ($pos = (int) strrpos($doi, '/')) {
   $extension = (string) substr($doi, $pos);
   if (in_array(strtolower($extension), array('/abstract', '/full', '/pdf', '/epdf', '/asset/', '/summary', '/short', '/meta', '/html'))) {
      $doi = (string) substr($doi, 0, $pos);
   }
  }
  $doi = str_replace('//', '/', $doi);
  // And now for 10.1093 URLs
  // The add chapter/page stuff after the DOI in the URL and it looks like part of the DOI to us
  // Things like 10.1093/oxfordhb/9780199552238.001.0001/oxfordhb-9780199552238-e-003 and 10.1093/acprof:oso/9780195304923.001.0001/acprof-9780195304923-chapter-7
  if (strpos($doi, '10.1093') === 0 && doi_works($doi) === FALSE) {
    if (preg_match('~^(10\.1093/oxfordhb.+)(?:/oxfordhb.+)$~', $doi, $match) ||
        preg_match('~^(10\.1093/acprof.+)(?:/acprof.+)$~', $doi, $match) ||
        preg_match('~^(10\.1093/acref.+)(?:/acref.+)$~', $doi, $match) ||
        preg_match('~^(10\.1093/ref:odnb.+)(?:/odnb.+)$~', $doi, $match) ||
        preg_match('~^(10\.1093/ww.+)(?:/ww.+)$~', $doi, $match) ||
        preg_match('~^(10\.1093/anb.+)(?:/anb.+)$~', $doi, $match)) {
       $new_doi = $match[1];
       if (doi_works($new_doi)) $doi = $new_doi;
    }
  }
  return $doi;
}

/* extract_doi
 * Returns an array containing:
 * 0 => text containing a DOI, possibly encoded, possibly with additional text
 * 1 => the decoded DOI
 */
function extract_doi(string $text) : array {
  if (preg_match(
        "~(10\.\d{4}\d?(/|%2[fF])..([^\s\|\"\?&>]|&l?g?t;|<[^\s\|\"\?&]*>)+)~",
        $text, $match)) {
    $doi = $match[1];
    if (preg_match(
          "~^(.*?)(/abstract|/e?pdf|/full|/figure|/default|</span>|[\s\|\"\?]|</).*+$~",
          $doi, $new_match)
        ) {
      $doi = $new_match[1];
    }
    $doi_candidate = sanitize_doi($doi);
    while (preg_match(REGEXP_DOI, $doi_candidate) && !doi_works($doi_candidate)) {
      $last_delimiter = 0;
      foreach (array('/', '.', '#', '?') as $delimiter) {
        $delimiter_position = (int) strrpos($doi_candidate, $delimiter);
        $last_delimiter = ($delimiter_position > $last_delimiter) ? $delimiter_position : $last_delimiter;
      }
      $doi_candidate = substr($doi_candidate, 0, $last_delimiter);
    }
    if (doi_works($doi_candidate)) $doi = $doi_candidate;
    if (!doi_works($doi) && !doi_works(sanitize_doi($doi))) { // Reject URLS like ...../25.10.2015/2137303/default.htm
      if (preg_match('~^10\.([12]\d{3})~', $doi, $new_match)) {
        if (preg_match("~[0-3][0-9]\.10\." . $new_match[1] . "~", $text)) {
          return array(FALSE, FALSE);
        }
      }
    }
    return array($match[0], sanitize_doi($doi));
  }
  return array(FALSE, FALSE);
}

// ============================================= String/Text functions ======================================
function wikify_external_text(string $title) : string {
  $replacement = [];
  $placeholder = [];
  if (preg_match_all("~<(?:mml:)?math[^>]*>(.*?)</(?:mml:)?math>~", $title, $matches)) {
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
  $title = html_entity_decode($title, ENT_COMPAT | ENT_HTML401, "UTF-8");
  $title = safe_preg_replace("~\s+~"," ", $title);  // Remove all white spaces before
  if (mb_substr($title, -6) === "&nbsp;") $title = mb_substr($title, 0, -6);
  // Special code for ending periods
  while (mb_substr($title, -2) === "..") {
    $title = mb_substr($title, 0, -1);
  }
  if (mb_substr($title, -1) === ".") { // Ends with a period
   if (mb_substr_count($title, '.') === 1) { // Only one period
      $title = mb_substr($title, 0, -1);
   } elseif (mb_substr_count($title, ' ') === 0) { // No spaces at all and multiple periods
      ;
   } else { // Multiple periods and at least one space
    $last_word_start = (int) mb_strrpos(' ' . $title, ' ');
    $last_word = mb_substr($title, $last_word_start);
    if (mb_substr_count($last_word, '.') === 1 && // Do not remove if something like D.C. or D. C.
        mb_substr($title, $last_word_start-2, 1) !== '.') { 
      $title = mb_substr($title, 0, -1);
    }
   }
  }
  $title = safe_preg_replace('~[\*]$~', '', $title);
  $title = title_capitalization($title, TRUE);

  $htmlBraces  = array("&lt;", "&gt;");
  $angleBraces = array("<", ">");
  $title = str_ireplace($htmlBraces, $angleBraces, $title);

  $originalTags = array('<title>', '</title>', '</ title>', 'From the Cover: ');
  $wikiTags = array('','','','');
  $title = str_ireplace($originalTags, $wikiTags, $title);
  $originalTags = array('.<br>', '.</br>', '.</ br>', '.<p>', '.</p>', '.</ p>', '.<strong>', '.</strong>', '.</ strong>');
  $wikiTags = array('. ','. ','. ','. ','. ','. ','. ','. ','. ');
  $title = str_ireplace($originalTags, $wikiTags, $title);
  $originalTags = array('<br>', '</br>', '</ br>', '<p>', '</p>', '</ p>', '<strong>', '</strong>', '</ strong>');
  $wikiTags = array('. ','. ','. ','. ','. ','. ', ' ',' ',' ');
  $title = trim(str_ireplace($originalTags, $wikiTags, $title));
  if (preg_match("~^\. (.+)$~", $title, $matches)) {
    $title = trim($matches[1]);
  }
 if (preg_match("~^(.+)(\.\s+)\.$~s", $title, $matches)) {
    $title = trim($matches[1] . ".");
  }
  $title_orig = '';
  while ($title !== $title_orig) {
    $title_orig = $title;  // Might have to do more than once.   The following do not allow < within the inner match since the end tag is the same :-( and they might nest or who knows what
    $title = preg_replace_callback('~(?:<Emphasis Type="Italic">)([^<]+)(?:</Emphasis>)~iu',
      function (array $matches) : string {return ("''" . $matches[1] . "''");},
      $title);
    $title = preg_replace_callback('~(?:<Emphasis Type="Bold">)([^<]+)(?:</Emphasis>)~iu',
      function (array $matches) : string {return ("'''" . $matches[1] . "'''");},
      $title);
    $title = preg_replace_callback('~(?:<em>)([^<]+)(?:</em>)~iu',
      function (array $matches) : string {return ("''" . $matches[1] . "''");},
      $title);
    $title = preg_replace_callback('~(?:<i>)([^<]+)(?:</i>)~iu',
      function (array $matches) : string {return ("''" . $matches[1] . "''");},
      $title);
    $title = preg_replace_callback('~(?:<italics>)([^<]+)(?:</italics>)~iu',
      function (array $matches) : string {return ("''" . $matches[1] . "''");},
      $title);
  }

  if (mb_substr($title, -1) === '.') {
    $title = sanitize_string($title) . '.';
  } else {
    $title = sanitize_string($title);
  }

  $title = str_replace(['​'],[' '], $title); // Funky spaces
  
  $title = str_ireplace('<p class="HeadingRun \'\'In\'\'">', ' ', $title);
  
  $title = str_ireplace(['    ', '   ', '  '], [' ', ' ', ' '], $title);
  $title = trim($title," \t\n\r\0\x0B\xc2\xa0");

  for ($i = 0; $i < count($replacement); $i++) {
    $title = str_replace($placeholder[$i], $replacement[$i], $title); // @phan-suppress-current-line PhanTypePossiblyInvalidDimOffset
  }
  return $title; 
}

function restore_italics (string $text) : string {
  // <em> tags often go missing around species names in CrossRef
  return safe_preg_replace('~([a-z]+)([A-Z][a-z]+\b)~', "$1 ''$2''", $text);
}

function sanitize_string(string $str) : string {
  // ought only be applied to newly-found data.
  if ($str === '') return '';
  if (strtolower(trim($str)) === 'science (new york, n.y.)') return 'Science';
  if (preg_match('~^\[http.+\]$~', $str)) return $str; // It is a link out
  $replacement = [];
  $placeholder = [];
  $math_templates_present = preg_match_all("~<\s*math\s*>.*<\s*/\s*math\s*>~", $str, $math_hits);
  if ($math_templates_present) {
    for ($i = 0; $i < count($math_hits[0]); $i++) {
      $replacement[$i] = $math_hits[0][$i];
      $placeholder[$i] = sprintf(TEMP_PLACEHOLDER, $i);
    }
    $str = str_replace($replacement, $placeholder, $str);
  }
  $dirty = array ('[', ']', '|', '{', '}', " what�s ");
  $clean = array ('&#91;', '&#93;', '&#124;', '&#123;', '&#125;', " what's ");
  $str = trim(str_replace($dirty, $clean, safe_preg_replace('~[;.,]+$~', '', $str)));
  if ($math_templates_present) {
    $str = str_replace($placeholder, $replacement, $str);
  }
  return $str;
}

function truncate_publisher(string $p) : string {
  return safe_preg_replace("~\s+(group|inc|ltd|publishing)\.?\s*$~i", "", $p);
}

function str_remove_irrelevant_bits(string $str) : string {
  if ($str === '') return '';
  $str = trim($str);
  $str = str_replace('�', 'X', $str);
  $str = safe_preg_replace(REGEXP_PLAIN_WIKILINK, "$1", $str);   // Convert [[X]] wikilinks into X
  $str = safe_preg_replace(REGEXP_PIPED_WIKILINK, "$2", $str);   // Convert [[Y|X]] wikilinks into X
  $str = trim($str);
  $str = safe_preg_replace("~^the\s+~i", "", $str);  // Ignore leading "the" so "New York Times" == "The New York Times"
  // punctuation
  $str = str_replace(array('.', ',', ';', ': '), array(' ', ' ', ' ', ' '), $str);
  $str = str_replace(array(':', '-', '&mdash;', '&ndash;', '—', '–'), array('', '', '', '', '', ''), $str);
  $str = str_replace(array('   ', '  '), array(' ', ' '), $str);
  $str = trim($str);
  $str = str_ireplace(array('Proceedings', 'Proceeding', 'Symposium', 'Huffington ', 'the Journal of ', 'nytimes.com'   , '& '  , '(Clifton, N.J.)'),
                      array('Proc',        'Proc',       'Sym',       'Huff ',       'journal of ',     'New York Times', 'and ', ''), $str);
  $str = str_ireplace(array('<sub>', '<sup>', '<i>', '<b>', '</sub>', '</sup>', '</i>', '</b>'), '', $str);
  $str = str_ireplace(array('SpringerVerlag', 'Springer Verlag Springer', 'Springer Verlag', 'Springer Springer'),
                      array('Springer',       'Springer',                 'Springer',        'Springer'         ), $str);
  $str = straighten_quotes($str, TRUE);
  $str = str_replace("′","'", $str);
  $str = safe_preg_replace('~\(Incorporating .*\)$~i', '', $str);  // Physical Chemistry Chemical Physics (Incorporating Faraday Transactions)
  $str = safe_preg_replace('~\d+ Volume Set$~i', '', $str);  // Ullmann's Encyclopedia of Industrial Chemistry, 40 Volume Set
  $str = safe_preg_replace('~^Retracted~i', '', $str);
  $str = safe_preg_replace('~\d?\d? ?The ?sequence ?of ?\S+ ?has ?been ?deposited ?in ?the ?GenBank ?database ?under ?accession ?number ?\S+ ?\d?~i', '', $str);
  $str = safe_preg_replace('~(?:\:\.\,)? ?(?:an|the) official publication of the.+$~i', '', $str);
  $str = trim($str);
  $str = strip_diacritics($str);
  return $str;
}

// See also titles_are_similar()
function str_equivalent(string $str1, string $str2) : bool {
  return str_i_same(str_remove_irrelevant_bits($str1), str_remove_irrelevant_bits($str2));
}

// See also str_equivalent()
function titles_are_similar(string $title1, string $title2) : bool {
  if (!titles_are_dissimilar($title1, $title2)) return TRUE;
  // Try again but with funky stuff mapped out of existence
  $title1 = str_replace('�', '', str_replace(array_keys(MAP_DIACRITICS), '', $title1));
  $title2 = str_replace('�', '', str_replace(array_keys(MAP_DIACRITICS), '', $title2));
  if (!titles_are_dissimilar($title1, $title2)) return TRUE;
  return FALSE;
}


function de_wikify(string $string) : string {
  return str_replace(Array("[", "]", "'''", "''", "&"), Array("", "", "'", "'", ""), preg_replace(Array("~<[^>]*>~", "~\&[\w\d]{2,7};~", "~\[\[[^\|\]]*\|([^\]]*)\]\]~"), Array("", "", "$1"),  $string));
}

function titles_are_dissimilar(string $inTitle, string $dbTitle) : bool {
        // Blow away junk from OLD stuff
        if (stripos($inTitle, 'CITATION_BOT_PLACEHOLDER_') !== FALSE) {
          $possible = preg_replace("~# # # CITATION_BOT_PLACEHOLDER_[A-Z]+ \d+ # # #~isu", ' ' , $inTitle);
          if ($possible !== NULL) {
             $inTitle = $possible;
          } else { // When PHP fails with unicode, try withou it 
            $inTitle = preg_replace("~# # # CITATION_BOT_PLACEHOLDER_[A-Z]+ \d+ # # #~i", ' ' , $inTitle);  // @codeCoverageIgnore
            if ($inTitle === NULL) return TRUE;                                                             // @codeCoverageIgnore
          }
        }
        // Strip diacritics before decode
        $inTitle = strip_diacritics($inTitle);
        $dbTitle = strip_diacritics($dbTitle);
        // always decode new data
        $dbTitle = titles_simple(mb_convert_encoding(html_entity_decode($dbTitle), "HTML-ENTITIES", 'UTF-8'));
        // old data both decoded and not
        $inTitle2 = titles_simple($inTitle);
        $inTitle = titles_simple(mb_convert_encoding(html_entity_decode($inTitle), "HTML-ENTITIES", 'UTF-8'));
        $dbTitle = strip_diacritics($dbTitle);
        $inTitle = strip_diacritics($inTitle);
        $inTitle2 = strip_diacritics($inTitle2);
        $dbTitle = mb_strtolower($dbTitle);
        $inTitle = mb_strtolower($inTitle);
        $inTitle2 = mb_strtolower($inTitle2);
        $drops = [" ", "<strong>", "</strong>", "<em>", "</em>", "&nbsp", "&ensp", "&emsp", "&thinsp", "&zwnj", "&#45", "&#8208", "&#700", "&", "'", ",", ".", ";", '"', "\n", "\r", "\t", "\v", "\e", "‐", "-", "ʼ", "`"];
        $inTitle  = str_replace($drops, "", $inTitle);
        $inTitle2 = str_replace($drops, "", $inTitle2);
        $dbTitle  = str_replace($drops, "", $dbTitle);
  // This will convert &delta into delta
        return ((strlen($inTitle) > 254 || strlen($dbTitle) > 254)
              ? (strlen($inTitle) !== strlen($dbTitle)
                || similar_text($inTitle, $dbTitle) / strlen($inTitle) < 0.98)
              : (levenshtein($inTitle, $dbTitle) > 3)
        )
        &&  
        ((strlen($inTitle2) > 254 || strlen($dbTitle) > 254)
              ? (strlen($inTitle2) !== strlen($dbTitle)
                || similar_text($inTitle2, $dbTitle) / strlen($inTitle2) < 0.98)
              : (levenshtein($inTitle2, $dbTitle) > 3)
        );
}

function titles_simple(string $inTitle) : string {
        // Failure leads to null or empty strings!!!!
        // Leading Chapter # -   Use callback to make sure there are a few characters after this
        $inTitle2 = (string) preg_replace_callback('~^(?:Chapter \d+ \- )(.....+)~iu',
            function (array $matches) : string {return ($matches[1]);}, trim($inTitle));
        if ($inTitle2 !== "") $inTitle = $inTitle2;
        // Trailing "a review"
        $inTitle2 = (string) safe_preg_replace('~(?:\: | |\:)a review$~iu', '', trim($inTitle));
        if ($inTitle2 !== "") $inTitle = $inTitle2;
        // Strip trailing Online
        $inTitle2 = (string) safe_preg_replace('~ Online$~iu', '', $inTitle);
        if ($inTitle2 !== "") $inTitle = $inTitle2;
        // Strip trailing (Third Edition)
        $inTitle2 = (string) safe_preg_replace('~\([^\s\(\)]+ Edition\)^~iu', '', $inTitle);
        if ($inTitle2 !== "") $inTitle = $inTitle2;
        // Strip leading International Symposium on 
        $inTitle2 = (string) safe_preg_replace('~^International Symposium on ~iu', '', $inTitle);
        if ($inTitle2 !== "") $inTitle = $inTitle2;
        // Strip leading the
        $inTitle2 = (string) safe_preg_replace('~^The ~iu', '', $inTitle);
        if ($inTitle2 !== "") $inTitle = $inTitle2;
        // Strip trailing 
        $inTitle2 = (string) safe_preg_replace('~ A literature review$~iu', '', $inTitle);
        if ($inTitle2 !== "") $inTitle = $inTitle2;
        // Reduce punctuation
        $inTitle = straighten_quotes(mb_strtolower((string) $inTitle), TRUE);
        $inTitle2 = (string) safe_preg_replace("~(?: |‐|−|-|—|–|â€™|â€”|â€“)~u", "", $inTitle);
        if ($inTitle2 !== "") $inTitle = $inTitle2;
        $inTitle = str_replace(array("\n", "\r", "\t", "&#8208;", ":", "&ndash;", "&mdash;", "&ndash", "&mdash"), "", $inTitle);
        // Retracted
        $inTitle2 = (string) safe_preg_replace("~\[RETRACTED\]~ui", "", $inTitle);
        if ($inTitle2 !== "") $inTitle = $inTitle2;
        $inTitle2 = (string) safe_preg_replace("~\(RETRACTED\)~ui", "", $inTitle);
        if ($inTitle2 !== "") $inTitle = $inTitle2;
        $inTitle2 = (string) safe_preg_replace("~RETRACTED~ui", "", $inTitle);
        if ($inTitle2 !== "") $inTitle = $inTitle2;
        // Drop normal quotes
        $inTitle = str_replace(array("'", '"'), "", $inTitle);
        // Strip trailing periods
        $inTitle = trim(rtrim($inTitle, '.'));
        // greek
        $inTitle = strip_diacritics($inTitle);
        $inTitle = str_remove_irrelevant_bits($inTitle);
        return $inTitle;
}

function strip_diacritics (string $input) : string {
    return str_replace(array_keys(MAP_DIACRITICS), array_values(MAP_DIACRITICS), $input);
}

function straighten_quotes(string $str, bool $do_more) : string { // (?<!\') and (?!\') means that it cannot have a single quote right before or after it
  // These Regex can die on Unicode because of backward looking
  if ($str === '') return '';
  $str = str_replace('Hawaiʻi', 'CITATION_BOT_PLACEHOLDER_HAWAII', $str);
  $str2 = safe_preg_replace('~(?<!\')&#821[679];|&#39;|&#x201[89];|[\x{FF07}\x{2018}-\x{201B}`]|&[rl]s?[b]?quo;(?!\')~u', "'", $str);
  if ($str2 !== NULL) $str = $str2;
  if((mb_strpos($str, '&rsaquo;') !== FALSE && mb_strpos($str, '&[lsaquo;')  !== FALSE) ||
     (mb_strpos($str, '\x{2039}') !== FALSE && mb_strpos($str, '\x{203A}') !== FALSE) ||
     (mb_strpos($str, '‹')        !== FALSE && mb_strpos($str, '›')        !== FALSE)) { // Only replace single angle quotes if some of both
     $str2 = safe_preg_replace('~&[lr]saquo;|[\x{2039}\x{203A}]|[‹›]~u', "'", $str);           // Websites tiles: Jobs ›› Iowa ›› Cows ›› Ames
     if ($str2 !== NULL) $str = $str2;
  }
  $str2 = safe_preg_replace('~&#822[013];|[\x{201C}-\x{201F}]|&[rlb][d]?quo;~u', '"', $str);
  if ($str2 !== NULL) $str = $str2;
  if((mb_strpos($str, '&raquo;')  !== FALSE && mb_strpos($str, '&laquo;')  !== FALSE) ||
     (mb_strpos($str, '\x{00AB}') !== FALSE && mb_strpos($str, '\x{00AB}') !== FALSE) ||
     (mb_strpos($str, '«')        !== FALSE && mb_strpos($str, '»')        !== FALSE)) { // Only replace double angle quotes if some of both // Websites tiles: Jobs » Iowa » Cows » Ames
     if ($do_more){
       $str2 = safe_preg_replace('~&[lr]aquo;|[\x{00AB}\x{00BB}]|[«»]~u', '"', $str);
     } else { // Only outer funky quotes, not inner quotes
       if (preg_match('~^(?:&laquo;|&raquo;|\x{00AB}|\x{00BB}|«|»)~u', $str) &&
           preg_match( '~(?:&laquo;|&raquo;|\x{00AB}|\x{00BB}|«|»)$~u', $str) // Only if there is an outer quote on both ends
       ) {
         $str2 = safe_preg_replace('~^(?:&laquo;|&raquo;|\x{00AB}|\x{00BB}|«|»)~u' , '"', $str);
         $str2 = safe_preg_replace( '~(?:&laquo;|&raquo;|\x{00AB}|\x{00BB}|«|»)$~u', '"', $str2);
       } else {
         $str2 = $str; // No change
       }
     }
     if ($str2 !== NULL) $str = $str2;
  }
  $str = str_replace('CITATION_BOT_PLACEHOLDER_HAWAII', 'Hawaiʻi', $str);
  return $str;
}

// ============================================= Capitalization functions ======================================

function title_case(string $text) : string {
  if (stripos($text, 'www.') !== FALSE || stripos($text, 'www-') !== FALSE || stripos($text, 'http://') !== FALSE) {
     return $text; // Who knows - duplicate code below
  }
  return mb_convert_case($text, MB_CASE_TITLE, "UTF-8");
}

/** Returns a properly capitalized title.
 *      If $caps_after_punctuation is TRUE (or there is an abundance of periods), it allows the 
 *      letter after colons and other punctuation marks to remain capitalized.
 *      If not, it won't capitalize after : etc.
 */
function title_capitalization(string $in, bool $caps_after_punctuation) : string {
  // Use 'straight quotes' per WP:MOS
  $new_case = straighten_quotes(trim($in), FALSE);
  if (mb_substr($new_case, 0, 1) === "[" && mb_substr($new_case, -1) === "]") {
     return $new_case; // We ignore wikilinked names and URL linked since who knows what's going on there.
                       // Changing case may break links (e.g. [[Journal YZ|J. YZ]] etc.)
  }
  
  if (stripos($new_case, 'www.') !== FALSE || stripos($new_case, 'www-') !== FALSE || stripos($new_case, 'http://') !== FALSE) {
     return $new_case; // Who knows - duplicate code above
  }

  if ($new_case === mb_strtoupper($new_case) 
     && mb_strlen(str_replace(array("[", "]"), "", trim($in))) > 6
     ) {
    // ALL CAPS to Title Case
    $new_case = mb_convert_case($new_case, MB_CASE_TITLE, "UTF-8");
  }

  // Implicit acronyms
  $new_case = ' ' . $new_case . ' ';
  $new_case = preg_replace_callback("~[^\w&][b-df-hj-np-tv-xz]{3,}(?=\W)~ui", 
      function (array $matches) : string {return mb_strtoupper($matches[0]);}, // Three or more consonants.  NOT Y
      $new_case);
  $new_case = preg_replace_callback("~[^\w&][aeiou]{3,}(?=\W)~ui", 
      function (array $matches) : string {return mb_strtoupper($matches[0]);}, // Three or more vowels.  NOT Y
      $new_case);
  $new_case = mb_substr($new_case, 1, -1); // Remove added spaces

  $new_case = mb_substr(str_replace(UC_SMALL_WORDS, LC_SMALL_WORDS, " " . $new_case . " "), 1, -1);
  foreach(UC_SMALL_WORDS as $key=>$_value) {
    $upper = UC_SMALL_WORDS[$key];
    $lower = LC_SMALL_WORDS[$key];
    foreach ([': ', ', ', '. ', '; '] as $char) {
       $new_case = str_replace(mb_substr($upper, 0, -1) . $char, mb_substr($lower, 0, -1) . $char, $new_case);
    }
  }

  if ($caps_after_punctuation || (substr_count($in, '.') / strlen($in)) > .07) {
    // When there are lots of periods, then they probably mark abbreviations, not sentence ends
    // We should therefore capitalize after each punctuation character.
    $new_case = preg_replace_callback("~[?.:!/]\s+[a-z]~u" /* Capitalize after punctuation */,
      function (array $matches) : string {return mb_strtoupper($matches[0]);},
      $new_case);
    $new_case = preg_replace_callback("~(?<!<)/[a-z]~u" /* Capitalize after slash unless part of ending html tag */,
      function (array $matches) : string {return mb_strtoupper($matches[0]);},
      $new_case);
    // But not "Ann. Of...." which seems to be common in journal titles
    $new_case = str_replace("Ann. Of ", "Ann. of ", $new_case);
  }

  $new_case = preg_replace_callback(
    "~ \([a-z]~u" /* uppercase after parenthesis */, 
    function (array $matches) : string {return mb_strtoupper($matches[0]);},
    trim($new_case)
  );

  $new_case = preg_replace_callback(
    "~\w{2}'[A-Z]\b~u" /* Lowercase after apostrophes */, 
    function (array $matches) : string {return mb_strtolower($matches[0]);},
    trim($new_case)
  );
  /** French l'Words and d'Words  **/
  $new_case = preg_replace_callback(
    "~(\s[LD][\'\x{00B4}])([a-zA-ZÀ-ÿ]+)~u",
    function (array $matches) : string {return mb_strtolower($matches[1]) . mb_ucfirst($matches[2]);},
    ' ' . $new_case
  );

  /** Italian dell'xxx words **/
  $new_case = preg_replace_callback(
    "~(\s)(Dell|Degli|Delle)([\'\x{00B4}][a-zA-ZÀ-ÿ]{3})~u",
    function (array $matches) : string {return $matches[1] . strtolower($matches[2]) . $matches[3];},
    $new_case
  );

  $new_case = mb_ucfirst(trim($new_case));

  // Solitary 'a' should be lowercase
  $new_case = safe_preg_replace("~(\w\s+)A(\s+\w)~u", "$1a$2", $new_case);
  // but not in "U S A"
  $new_case = trim(str_replace(" U S a ", " U S A ", ' ' . $new_case . ' '));

  // This should be capitalized
  $new_case = str_replace(['(new Series)', '(new series)'] , ['(New Series)', '(New Series)'], $new_case);

  // Catch some specific epithets, which should be lowercase
  $new_case = preg_replace_callback(
    "~(?:'')?(?P<taxon>\p{L}+\s+\p{L}+)(?:'')?\s+(?P<nova>(?:(?:gen\.? no?v?|sp\.? no?v?|no?v?\.? sp|no?v?\.? gen)\b[\.,\s]*)+)~ui" /* Species names to lowercase */,
    function (array $matches) : string {return "''" . ucfirst(strtolower($matches['taxon'])) . "'' " . strtolower($matches["nova"]);},
    $new_case);

  // "des" at end is "Des" for Design not german "The"
  if (mb_substr($new_case, -4, 4) === ' des') $new_case = mb_substr($new_case, 0, -4)  . ' Des';

  // Capitalization exceptions, e.g. Elife -> eLife
  $new_case = str_replace(UCFIRST_JOURNAL_ACRONYMS, JOURNAL_ACRONYMS, " " .  $new_case . " ");
  $new_case = mb_substr($new_case, 1, mb_strlen($new_case) - 2); // remove spaces, needed for matching in LC_SMALL_WORDS

  // Single letter at end should be capitalized  J Chem Phys E for example.  Obviously not the spanish word "e".
  if (mb_substr($new_case, -2, 1) === ' ') $new_case = strrev(ucfirst(strrev($new_case)));
  
  if ($new_case === 'Now and then') $new_case = 'Now and Then'; // Odd journal name

  // Trust existing "ITS", "its", ... 
  $its_in = preg_match_all('~ its(?= )~iu', ' ' . trim($in) . ' ', $matches_in, PREG_OFFSET_CAPTURE);
  $new_case = trim($new_case);
  $its_out = preg_match_all('~ its(?= )~iu', ' ' . $new_case . ' ', $matches_out, PREG_OFFSET_CAPTURE);
  if ($its_in === $its_out && $its_in !== 0 && $its_in !== FALSE) {
    $matches_in = $matches_in[0];
    $matches_out = $matches_out[0];
    foreach ($matches_in as $key => $_value) {
      if ($matches_in[$key][0] !== $matches_out[$key][0]  &&
          $matches_in[$key][1] === $matches_out[$key][1]) {
        $new_case = mb_substr_replace($new_case, trim($matches_in[$key][0]), $matches_out[$key][1], 3);
      }
    }
  }
  // Trust existing "DOS", "dos", ... 
  $its_in = preg_match_all('~ dos(?= )~iu', ' ' . trim($in) . ' ', $matches_in, PREG_OFFSET_CAPTURE);
  $new_case = trim($new_case);
  $its_out = preg_match_all('~ dos(?= )~iu', ' ' . $new_case . ' ', $matches_out, PREG_OFFSET_CAPTURE);
  if ($its_in === $its_out && $its_in !== 0 && $its_in !== FALSE) {
    $matches_in = $matches_in[0];
    $matches_out = $matches_out[0];
    foreach ($matches_in as $key => $_value) {
      if ($matches_in[$key][0] !== $matches_out[$key][0]  &&
          $matches_in[$key][1] === $matches_out[$key][1]) {
        $new_case = mb_substr_replace($new_case, trim($matches_in[$key][0]), $matches_out[$key][1], 3);
      }
    }
  }
  // Part XII: Roman numerals
  $new_case = preg_replace_callback(
    "~ part ([xvil]+): ~iu",
    function (array $matches) : string {return " Part " . strtoupper($matches[1]) . ": ";},
    $new_case);
  $new_case = preg_replace_callback(
    "~ part ([xvi]+) ~iu",
    function (array $matches) : string {return " Part " . strtoupper($matches[1]) . " ";},
    $new_case);
  // Special cases - Only if the full title
  if ($new_case === 'Bioscience') {
    $new_case = 'BioScience';
  } elseif ($new_case === 'Aids') {
    $new_case = 'AIDS';
  } elseif ($new_case === 'Biomedical Engineering Online') {
    $new_case = 'BioMedical Engineering OnLine';
  }
  return $new_case;
}

function mb_ucfirst(string $string) : string
{
    return mb_strtoupper(mb_substr($string, 0, 1)) . mb_substr($string, 1, NULL);
}

function mb_substr_replace(string $string, string $replacement, int $start, int $length) : string {
    return mb_substr($string, 0, $start).$replacement.mb_substr($string, $start+$length);
}

function remove_brackets(string $string) : string {
  return str_replace(['(', ')', '{', '}', '[', ']'], '' , $string);
}


// ============================================= Wikipedia functions ======================================

function throttle (int $min_interval) : void {
  static $last_write_time = 0;
  static $phase = 0;
  $cycles = intdiv(180, $min_interval); // average over three minutes
  $phase = $phase + 1;
  
  if ($last_write_time === 0) $last_write_time = time();

  if ($phase < $cycles) {
    return;
  } else {
    // @codeCoverageIgnoreStart
    $phase = 0;
    $min_interval = $min_interval * $cycles;
  }

  $time_since_last_write = time() - $last_write_time;
  if ($time_since_last_write < 0) $time_since_last_write = 0; // Super paranoid, this would be a freeze point
  if ($time_since_last_write < $min_interval) {
    $time_to_pause = floor($min_interval - $time_since_last_write);
    report_warning("Throttling: waiting $time_to_pause seconds...");
    for ($i = 0; $i < $time_to_pause; $i++) {
      sleep(1); 
      report_inline(' .');
    }
  }
  $last_write_time = time();
  // @codeCoverageIgnoreEnd
}

// ============================================= Data processing functions ======================================

function tidy_date(string $string) : string {
  $string=trim($string);
  if (stripos($string, 'Invalid') !== FALSE) return '';
  if (strpos($string, '1/1/0001') !== FALSE) return '';
  if (!preg_match('~\d{2}~', $string)) return ''; // If there are not two numbers next to each other, reject
  // Google sends ranges
  if (preg_match('~^(\d{4})(\-\d{2}\-\d{2})\s+\-\s+(\d{4})(\-\d{2}\-\d{2})$~', $string, $matches)) { // Date range
     if ($matches[1] === $matches[3]) {
       return date('j F', strtotime($matches[1].$matches[2])) . ' – ' . date('j F Y', strtotime($matches[3].$matches[4]));
     } else {
       return date('j F Y', strtotime($matches[1].$matches[2])) . ' – ' . date('j F Y', strtotime($matches[3].$matches[4])); 
     }
  }
  // Huge amount of character cleaning
  if (strlen($string) !== mb_strlen($string)) {  // Convert all multi-byte characters to dashes
    $cleaned = '';
    for ($i = 0; $i < mb_strlen($string); $i++) {
       $char = mb_substr($string,$i,1);
       if (mb_strlen($char) === strlen($char)) {
          $cleaned .= $char;
       } else {
          $cleaned .= '-';
       }
    }
    $string = $cleaned;
  }
  $string = safe_preg_replace("~[^\x01-\x7F]~","-", $string); // Convert any non-ASCII Characters to dashes
  $string = safe_preg_replace('~[\s\-]*\-[\s\-]*~', '-',$string); // Combine dash with any following or preceding white space and other dash
  $string = safe_preg_replace('~^\-*(.+?)\-*$~', '\1', $string);  // Remove trailing/leading dashes
  $string = trim($string);
  // End of character clean-up
  $string = safe_preg_replace('~[^0-9]+\d{2}:\d{2}:\d{2}$~', '', $string); //trailing time
  $string = safe_preg_replace('~^Date published \(~', '', $string); // seen this
  // https://stackoverflow.com/questions/29917598/why-does-0000-00-00-000000-return-0001-11-30-000000
  if (strpos($string, '0001-11-30') !== FALSE) return '';
  if (strpos($string, '1969-12-31') !== FALSE) return '';
  if (str_i_same('19xx', $string)) return ''; //archive.org gives this if unknown
  if (preg_match('~^\d{4} \d{4}\-\d{4}$~', $string)) return ''; // si.edu
  if (preg_match('~^(\d\d?)/(\d\d?)/(\d{4})$~', $string, $matches)) { // dates with slashes 
    if (intval($matches[1]) < 13 && intval($matches[2]) > 12) {
      if (strlen($matches[1]) === 1) $matches[1] = '0' . $matches[1];
      return $matches[3] . '-' . $matches[1] . '-' . $matches[2];
    } elseif (intval($matches[2]) < 13 && intval($matches[1]) > 12) {
      if (strlen($matches[2]) === 1) $matches[2] = '0' . $matches[2];
      return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
    } elseif (intval($matches[2]) > 12 && intval($matches[1]) > 12) {
      return '';
    } elseif ($matches[1] === $matches[2]) {
      if (strlen($matches[2]) === 1) $matches[2] = '0' . $matches[2];
      return $matches[3] . '-' . $matches[2] . '-' . $matches[2];
    } else {
      return $matches[3];// do not know. just give year
    }
  }
  $string = trim($string);
  if (preg_match('~^(\d{4}\-\d{2}\-\d{2})T\d{2}:\d{2}:\d{2}\+\d{2}:\d{2}$~', $string, $matches)) return tidy_date($matches[1]); // Remove time zone stuff from standard date format
  if (preg_match('~^\-?\d+$~', $string)) {
    $string = intval($string);
    if ($string < -2000 || $string > (int)date("Y") + 10) return ''; // A number that is not a year; probably garbage 
    if ($string > -2 && $string < 2) return ''; // reject -1,0,1
    return (string) $string; // year
  }
  if (preg_match('~^(\d{1,2}) ([A-Za-z]+\.?), ?(\d{4})$~', $string, $matches)) { // strtotime('3 October, 2016') gives 2019-10-03.  The comma is evil and strtotime is stupid
    $string = $matches[1] . ' ' . $matches[2] . ' ' . $matches[3];   // Remove comma
  }
  $time = strtotime($string);
  if ($time) {
    $day = date('d', $time);
    $year = intval(date('Y', $time));
    if ($year < -2000 || $year > (int)date("Y") + 10) return ''; // We got an invalid year
    if ($year < 100 && $year > -100) return '';
    if ($day === '01') { // Probably just got month and year
      $string = date('F Y', $time);
    } else {
      $string = date('Y-m-d', $time);
    }
    if (stripos($string, 'Invalid') !== FALSE) return '';
    return $string;
  }
  if (preg_match( '~^(\d{4}\-\d{1,2}\-\d{1,2})[^0-9]~', $string, $matches)) return tidy_date($matches[1]); // Starts with date
  if (preg_match('~\s(\d{4}\-\d{1,2}\-\d{1,2})$~',     $string, $matches)) return tidy_date($matches[1]);  // Ends with a date
  if (preg_match('~^(\d{1,2}/\d{1,2}/\d{4})[^0-9]~', $string, $matches)) return tidy_date($matches[1]); // Recursion to clean up 3/27/2000
  if (preg_match('~[^0-9](\d{1,2}/\d{1,2}/\d{4})$~', $string, $matches)) return tidy_date($matches[1]);
  
  // Dates with dots -- convert to slashes and try again.
  if (preg_match('~(\d\d?)\.(\d\d?)\.(\d{2}(?:\d{2})?)$~', $string, $matches) || preg_match('~^(\d\d?)\.(\d\d?)\.(\d{2}(?:\d{2})?)~', $string, $matches)) {
    if (intval($matches[3]) < ((int) date("y")+2))  $matches[3] = (int) $matches[3] + 2000;
    if (intval($matches[3]) < 100)  $matches[3] = (int) $matches[3] + 1900;
    return tidy_date((string) $matches[1] . '/' . (string) $matches[2] . '/' . (string) $matches[3]);
  }
  
  if (preg_match('~\s(\d{4})$~', $string, $matches)) return $matches[1]; // Last ditch effort - ends in a year
  return ''; // And we give up
}

function not_bad_10_1093_doi(string $url) : bool { // We assume DOIs are bad, unless on good list
  if ($url === '') return TRUE;
  if(!preg_match('~10.1093/([^/]+)/~u', $url, $match)) return TRUE;
  $test = strtolower($match[1]);
  // March 2019 Good list
  if (in_array($test, GOOD_10_1093_DOIS)) return TRUE;
  return FALSE;
}

function bad_10_1093_doi(string $url) :bool {
  return !not_bad_10_1093_doi($url);
}

// ============================================= Other functions ======================================

function remove_comments(string $string) : string {
  // See Comment::PLACEHOLDER_TEXT for syntax
  $string = preg_replace('~# # # CITATION_BOT_PLACEHOLDER_COMMENT \d+ # # #~isu', "", $string);
  return preg_replace("~<!--.*?-->~us", "", $string);
}

function prior_parameters(string $par, array $list=array()) : array {
  array_unshift($list, $par);
  if (preg_match('~(\D+)(\d+)~', $par, $match) && stripos($par, 's2cid') === FALSE) {
    $before = (string) ((int) $match[2] - 1);
    switch ($match[1]) {
      case 'first': case 'initials': case 'forename':
        return array('last' . $match[2], 'surname' . $match[2], 'author' . $before);
      case 'last': case 'surname': case 'author':
        return array('first' . $before, 'forename' . $before, 'initials' . $before, 'author' . $before);
      default:
        $base = $match[1] . $before;
        return array_merge(FLATTENED_AUTHOR_PARAMETERS, array($base, $base . '-last', $base . '-first'));
    }
  }
  switch ($par) {
    case 'author': case 'authors':    return $list;
    case 'dummy':                     return $list;
    case 'title': case 'others': case 'display-editors': case 'displayeditors': case 'display-authors': case 'displayauthors':
      return prior_parameters('dummy', array_merge(FLATTENED_AUTHOR_PARAMETERS, $list));
    case 'title-link':case 'titlelink':return prior_parameters('title', $list);
    case 'chapter':                   return prior_parameters('title-link', array_merge(['titlelink'], $list));
    case 'journal': case 'work': case 'newspaper': case 'website': case 'magazine': case 'periodical': case 'encyclopedia': case 'encyclopaedia':
      return prior_parameters('chapter', $list);
    case 'series':                    return prior_parameters('journal', array_merge(['work', 'newspaper', 'magazine', 'periodical', 'website', 'encyclopedia', 'encyclopaedia'], $list));
    case 'year': case 'date':         return prior_parameters('series', $list);
    case 'volume':                    return prior_parameters('year', array_merge(['date'], $list));
    case 'issue': case 'number':      return prior_parameters('volume', $list);
    case 'page' : case 'pages':       return prior_parameters('issue', array_merge(['number'], $list));
    case 'location': case 'publisher':return prior_parameters('page', array_merge(['pages'], $list));
    case 'doi':                       return prior_parameters('location', array_merge(['publisher'], $list));
    case 'doi-broken-date':           return prior_parameters('doi', $list);
    case 'jstor':                     return prior_parameters('doi-broken-date', $list);
    case 'pmid':                      return prior_parameters('jstor', $list);
    case 'pmc':                       return prior_parameters('pmid', $list);
    case 'arxiv': case 'eprint': case 'class' : return prior_parameters('pmc', $list);
    case 'bibcode':                   return prior_parameters('arxiv', array_merge(['eprint', 'class'], $list));
    case 'hdl':                       return prior_parameters('bibcode', $list);
    case 'isbn': case 'biorxiv': case 'citeseerx': case 'jfm': case 'zbl': case 'mr': case 'osti': case 'ssrn': case 'rfc':
       return prior_parameters('hdl', $list);
    case 'lccn': case 'issn': case 'ol': case 'oclc': case 'asin': case 's2cid':
       return prior_parameters('isbn', array_merge(['biorxiv', 'citeseerx', 'jfm', 'zbl', 'mr', 'osti', 'ssrn', 'rfc'], $list));
    case 'url':
        return prior_parameters('lccn', array_merge(['issn', 'ol', 'oclc', 'asin', 's2cid'], $list));
    case 'archive-url': case 'archiveurl': case 'accessdate': case 'access-date': return prior_parameters('url', $list);
    case 'archive-date': case 'archivedate': return prior_parameters('archive-url', array_merge(['archiveurl', 'accessdate', 'access-date'], $list));
    case 'id': case 'type': case 'via':return prior_parameters('archive-date', array_merge(['archivedate'], $list));
    default:
      return $list;
  }
}

function equivalent_parameters(string $par) : array {
  switch ($par) {
    case 'author': case 'authors': case 'author1': case 'last1': 
      return FLATTENED_AUTHOR_PARAMETERS;
    case 'pmid': case 'pmc': 
      return array('pmc', 'pmid');
    case 'page_range': case 'start_page': case 'end_page': # From doi_crossref
    case 'pages': case 'page':
      return array('page_range', 'pages', 'page', 'end_page', 'start_page');
    default: return array($par);
  }
}
  
function check_doi_for_jstor(string $doi, Template $template) : void {
  if ($template->has('jstor')) return;
  $doi = trim($doi);
  if ($doi === '') return;
  if (strpos($doi, '10.2307') === 0) { // special case
    $doi = substr($doi, 8);
  }
  $ch = curl_init();
  curl_setopt_array($ch,
          [CURLOPT_RETURNTRANSFER => TRUE,
           CURLOPT_TIMEOUT => 10,
           CURLOPT_CONNECTTIMEOUT => 10,
           CURLOPT_URL => "https://www.jstor.org/citation/ris/" . $doi,
           CURLOPT_USERAGENT => BOT_USER_AGENT]);
  $ris = (string) @curl_exec($ch);
  $httpCode = (int) @curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($httpCode === 200 &&
      stripos($ris, $doi) !== FALSE &&
      strpos ($ris, 'Provider') !== FALSE &&
      stripos($ris, 'No RIS data found for') === FALSE &&
      stripos($ris, 'Block Reference') === FALSE &&
      stripos($ris, 'A problem occurred trying to deliver RIS data') === FALSE &&
      substr_count($ris, '-') > 3) { // It is actually a working JSTOR
      $template->add_if_new('jstor', $doi);
  } elseif ($pos = strpos($doi, '?')) {
      $doi = substr($doi, 0, $pos);
      check_doi_for_jstor($doi, $template);
  }      
}

function can_safely_modify_dashes(string $value) : bool {
   return((stripos($value, "http") === FALSE)
       && (strpos($value, "[//") === FALSE)
       && (substr_count($value, "<") === 0) // <span></span> stuff
       && (stripos($value, 'CITATION_BOT_PLACEHOLDER') === FALSE)
       && (strpos($value, "(") === FALSE)
       && (preg_match('~(?:[a-zA-Z].*\s|\s.*[a-zA-Z])~u', trim($value)) !== 1) // Spaces and letters
       && ((substr_count($value, '-') + substr_count($value, '–') + substr_count($value, ',') + substr_count($value, 'dash')) < 3) // This line helps us ignore with 1-5–1-6 stuff
       && (preg_match('~^[a-zA-Z]+[0-9]*.[0-9]+$~u',$value) !== 1) // A-3, A3-5 etc.  Use "." for generic dash
       && (preg_match('~^\d{4}\-[a-zA-Z]+$~u',$value) !== 1)); // 2005-A used in {{sfn}} junk
}

function str_i_same(string $str1, string $str2) : bool {
   return (0 === strcasecmp($str1, $str2));
}
  
function doi_encode (string $doi) : string {
   /** @psalm-taint-escape html */  /** @psalm-taint-escape quotes */
    $doi = urlencode($doi);
    $doi = str_replace('%2F', '/', $doi);
    return $doi;
}

function hdl_decode(string $hdl) : string {
    $hdl = urldecode($hdl);
    $hdl = str_replace(';', '%3B', $hdl);
    $hdl = str_replace('#', '%23', $hdl);
    $hdl = str_replace(' ', '%20', $hdl);
    return $hdl;
}

/**
 * Only on webpage
 * @codeCoverageIgnore
 */
function edit_a_list_of_pages(array $pages_in_category, WikipediaBot $api, string $edit_summary_end) : void {
  $final_edit_overview = "";
  // Remove pages with blank as the name, if present
  if (($key = array_search("", $pages_in_category)) !== FALSE) {
    unset($pages_in_category[$key]);
  }
  if (empty($pages_in_category)) {
    report_warning('No links to expand found');
    bot_html_footer();
    exit();
  }
  $total = count($pages_in_category);
  if ($total > MAX_PAGES) {
    report_warning('Number of links is huge (' . (string) $total . ')  Cancelling run (maximum size is ' . (string) MAX_PAGES . ').  Listen to Obi-Wan Kenobi:  You want to go home and rethink your life.');
    bot_html_footer();
    exit();
  }
  if ($total > BIG_RUN) check_overused();

  $page = new Page();
  $done = 0;

  foreach ($pages_in_category as $page_title) {
    check_killed();
    $done++;
    if ($page->get_text_from($page_title) && $page->expand_text()) {
      report_phase("Writing to " . echoable($page_title) . '... ');
      $attempts = 0;
      if ($total === 1) {
        $edit_sum = $edit_summary_end;
      } else {
        $edit_sum = $edit_summary_end . (string) $done . '/' . (string) $total . ' ';
      }
      while (!$page->write($api, $edit_sum) && $attempts < MAX_TRIES) ++$attempts;
      if ($attempts < MAX_TRIES) {
        $last_rev = WikipediaBot::get_last_revision($page_title);
        html_echo(
        "\n  <a href=" . WIKI_ROOT . "?title=" . urlencode($page_title) . "&diff=prev&oldid="
        . $last_rev . ">diff</a>" .
        " | <a href=" . WIKI_ROOT . "?title=" . urlencode($page_title) . "&action=history>history</a>", 
        "\n" . WIKI_ROOT . "?title=" . urlencode($page_title) . "&diff=prev&oldid=". $last_rev . "\n");
        $final_edit_overview .=
          "\n [ <a href=" . WIKI_ROOT . "?title=" . urlencode($page_title) . "&diff=prev&oldid="
        . $last_rev . ">diff</a>" .
        " | <a href=" . WIKI_ROOT . "?title=" . urlencode($page_title) . "&action=history>history</a> ] " . "<a href=" . WIKI_ROOT . "?title=" . urlencode($page_title) . ">" . echoable($page_title) . "</a>";
      } else {
        report_warning("Write failed.");
        $final_edit_overview .= "\n Write failed.      " . "<a href=" . WIKI_ROOT . "?title=" . urlencode($page_title) . ">" . echoable($page_title) . "</a>";
      }
    } else {
      report_phase($page->parsed_text() ? "No changes required. \n\n    # # # " : "Blank page. \n\n    # # # ");
       $final_edit_overview .= "\n No changes needed. " . "<a href=" . WIKI_ROOT . "?title=" . urlencode($page_title) . ">" . echoable($page_title) . "</a>";
    }
    echo "\n";
  }
  if ($total > 1) {
    if (!HTML_OUTPUT) $final_edit_overview = ''; 
    echo "\n Done all " . (string) $total . " pages. \n  # # # \n" . $final_edit_overview;
  } else {
    echo "\n Done with page.";
  }
  bot_html_footer();
}


/**
 * Only on webpage
 * @codeCoverageIgnore
 */
function bot_html_header() : void {
  echo('<!DOCTYPE html>
<html lang="en" dir="ltr">
  <head>
  <title>Citation Bot: running</title>
  <link rel="copyright" type="text/html" href="https://www.gnu.org/licenses/gpl-3.0" />
  <link rel="stylesheet" type="text/css" href="results.css" />
  </head>
<body>
  <header>
    <p>Follow Citation bots progress below.</p>
    <p>
      <a href="https://en.wikipedia.org/wiki/User:Citation_bot/use" target="_blank" title="Using Citation Bot">How&nbsp;to&nbsp;Use&nbsp;/&nbsp;Tips&nbsp;and&nbsp;Tricks</a> |
      <a href="https://en.wikipedia.org/wiki/User_talk:Citation_bot" title="Report bugs at Wikipedia" target="_blank">Report&nbsp;bugs</a> |
      <a href="https://github.com/ms609/citation-bot" target="_blank" title="GitHub repository">Source&nbsp;code</a>
    </p>
  </header>

  <pre id="botOutput">
   ');
}

/**
 * Only on webpage
 * @codeCoverageIgnore
 */
function bot_html_footer() : void {
   if (HTML_OUTPUT) echo '</pre><footer><a href="./" title="Use Citation Bot again">Another</a>?</footer></body></html>';
}

  /**
   * @return string|null|false       Returns NULL/FALSE/String of location
   **/
function hdl_works(string $hdl) {
  // Greatly speed-up by having one array of each kind and only look for hash keys, not values
  static $cache_good = [];
  static $cache_bad  = [];
  $hdl = trim($hdl);
  if (isset($cache_good[$hdl])) return $cache_good[$hdl];
  if (isset($cache_bad[$hdl]))  return FALSE;
  if (count($cache_bad) > 250) $cache_bad = []; // Lots of things that look like handles are not handles
  if (count($cache_good) > 1000) $cache_good = [];
  $works = is_hdl_works($hdl);
  if ($works === NULL) {
    return NULL; // @codeCoverageIgnore
  }
  if ($works === FALSE) {
    $cache_bad[$hdl] = TRUE;
    return FALSE;
  }
  $cache_good[$hdl] = $works;
  return $works;
}

  /**
   * @return string|null|false       Returns NULL/FALSE/String of location
   **/
function is_hdl_works(string $hdl) {
  $hdl = trim($hdl);
  // And now some obvious fails
  if (strpos($hdl, '/') === FALSE) return FALSE;
  if (strpos($hdl, 'CITATION_BOT_PLACEHOLDER') !== FALSE) return FALSE;
  if (strpos($hdl, '123456789') === 0) return FALSE;
  if (strpos($hdl, '10.') === 0 && doi_works($hdl) === FALSE) return FALSE;
  // See if it works
  $context = stream_context_create(CONTEXT_INSECURE_11); // HDL does 1.1 always
  usleep(100000);
  $test_url = "https://hdl.handle.net/" . $hdl;
  set_time_limit(120);
  $headers_test = @get_headers($test_url, GET_THE_HEADERS, $context);
  if ($headers_test === FALSE) {
      sleep(3);                                                           // @codeCoverageIgnore
      set_time_limit(120);                                                // @codeCoverageIgnore
      report_inline(' .');                                                // @codeCoverageIgnore
      $headers_test = @get_headers($test_url, GET_THE_HEADERS, $context); // @codeCoverageIgnore
  }
  if ($headers_test === FALSE) {
      sleep(8);                                                           // @codeCoverageIgnore
      set_time_limit(120);                                                // @codeCoverageIgnore
      report_inline(' .');                                                // @codeCoverageIgnore
      $headers_test = @get_headers($test_url, GET_THE_HEADERS, $context); // @codeCoverageIgnore
  }
  if ($headers_test === FALSE) return NULL; // most likely bad, but will recheck again and again
  if (empty($headers_test['Location']) && empty($headers_test['location'])) return FALSE; // leads nowhere
  if (is_array(@$headers_test['Location'])) {
      $the_header_loc = (string) $headers_test['Location'][0]; // @codeCoverageIgnore
   } elseif (is_array(@$headers_test['location'])) {
      $the_header_loc = (string) $headers_test['location'][0]; // @codeCoverageIgnore
   } else {
      $the_header_loc = (string) @$headers_test['Location'] . (string) @$headers_test['location'];
   }
  if (stripos($headers_test[0], '404 Not Found') !== FALSE         || stripos($headers_test[0], 'HTTP/1.1 404') !== FALSE) return FALSE; // Bad
  if (isset($headers_test[1])) {
     if (stripos($headers_test[1], '404 Not Found') !== FALSE      || stripos($headers_test[1], 'HTTP/1.1 404') !== FALSE) return FALSE; // Bad next location
  }
  if (stripos($headers_test[0], '302 Found') !== FALSE             || stripos($headers_test[0], 'HTTP/1.1 302') !== FALSE) return $the_header_loc;  // Good
  // @codeCoverageIgnoreStart
  if (stripos($headers_test[0], '301 Moved Permanently') !== FALSE || stripos($headers_test[0], 'HTTP/1.1 301') !== FALSE) return $the_header_loc;  // Good, but only for moved DOIs and those will be checked with doi_works()
  report_minor_error("Unexpected response in is_hdl_works " . echoable($headers_test[0]));
  return NULL;
  // @codeCoverageIgnoreEnd
}

// Sometimes (UTF-8 non-english characters) preg_replace fails, and we would rather have the original string than a null
function safe_preg_replace(string $regex, string $replace, string $old) : string {
  if ($old === "") return "";
  $new = preg_replace($regex, $replace, $old);
  if ($new === NULL) return $old;
  return (string) $new;
}

function wikifyURL(string $url) : string {
   $in  = array(' '  , '"'  , "'"  , '<'  ,'>'   , '['  , ']'  , '{'  , '|'  , '}');
   $out = array('%20', '%22', '%27', '%3C', '%3E', '%5B', '%5D', '%7B', '%7C', '%7D');
   return str_replace($in, $out, $url);
}

function numberToRomanRepresentation(int $number) : string { // https://stackoverflow.com/questions/14994941/numbers-to-roman-numbers-with-php
    $map = array('M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1);
    $returnValue = '';
    while ($number > 0) {
        foreach ($map as $roman => $int) {
            if($number >= $int) {
                $number -= $int;
                $returnValue .= $roman;
                break;
            }
        }
    }
    return $returnValue;
}
