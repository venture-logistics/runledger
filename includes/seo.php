<?php
// includes/seo.php
// Auto SEO: first <h1> => title, first <p> => meta description (first 21 words)
// Works without editing each page by using output buffering and placeholder replacement.
$scheme = (
  (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
  || ($_SERVER['SERVER_PORT'] ?? '') == 443
) ? 'https' : 'http';

$host = $_SERVER['HTTP_HOST'] ?? '';
$uri  = $_SERVER['REQUEST_URI'] ?? '';

$seoCanonical = $scheme . '://' . $host . $uri;



if (!function_exists('seo_words')) {
  function seo_words(string $text, int $limit = 21): string {
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if ($text === '') return '';
    $words = preg_split('/\s+/', $text);
    $words = array_slice($words, 0, $limit);
    return implode(' ', $words);
  }
}

if (!function_exists('seo_extract_first')) {
  function seo_extract_first(string $html, string $tag): string {
    if (preg_match('~<'.$tag.'\b[^>]*>(.*?)</'.$tag.'>~is', $html, $m)) {
      $txt = $m[1];
      $txt = html_entity_decode(strip_tags($txt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      return trim(preg_replace('/\s+/', ' ', $txt));
    }
    return '';
  }
}

// ---- Placeholders (so header.php can output them immediately) ----
$SEO_TITLE_TOKEN = '%%__SEO_TITLE__%%';
$SEO_DESC_TOKEN  = '%%__SEO_DESC__%%';

$seoTitle = $SEO_TITLE_TOKEN;
$seoDesc  = $SEO_DESC_TOKEN;

// ---- Buffer whole response, then replace tokens with extracted values ----
if (!function_exists('seo_callback')) {
  function seo_callback(string $html): string {
    $siteName = '';
    if (isset($GLOBALS['site']) && is_array($GLOBALS['site'])) {
      $siteName = trim((string)($GLOBALS['site']['site_name'] ?? ''));
    }

    $h1 = seo_extract_first($html, 'h1');
    $p  = seo_extract_first($html, 'p');

    // Title: H1 + site name (no hard-coded names)
    $finalTitle = $h1;
    if ($finalTitle !== '' && $siteName !== '') {
      $finalTitle = $finalTitle . ' | ' . $siteName;
    } elseif ($finalTitle === '' && $siteName !== '') {
      $finalTitle = $siteName;
    }

    // Description: first 21 words of first <p>
    $finalDesc = seo_words($p, 21);

    // Replace tokens everywhere they appear (header prints them via h())
    $finalTitleEsc = htmlspecialchars($finalTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $finalDescEsc  = htmlspecialchars($finalDesc,  ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $html = str_replace('%%__SEO_TITLE__%%', $finalTitleEsc, $html);
    $html = str_replace('%%__SEO_DESC__%%',  $finalDescEsc,  $html);

    return $html;
  }
}

if (!function_exists('seo_start')) {
  function seo_start(): void {
    // start once
    static $started = false;
    if (!$started) {
      $started = true;
      ob_start('seo_callback');
    }
  }
}

if (!function_exists('seo_flush')) {
  function seo_flush(): void {
    // flush if active
    if (ob_get_level() > 0) {
      @ob_end_flush();
    }
  }
}

seo_start();
