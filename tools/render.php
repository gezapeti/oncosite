<?php
declare(strict_types=1);

/**
 * Render HTML pages from language PHP files.
 *
 * Assumptions:
 * - Source files live in ../src
 * - Output HTML goes to ../site
 * - Files end with "_lang.php" (hardcoded one-off)
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

$suffix   = 'lang'; // one-off hardcoded suffix
$srcDir   = __DIR__ . '/../src';
$outDir   = __DIR__ . '/../site';
$pattern  = sprintf('/*_%s.php', $suffix);

if (!is_dir($srcDir)) {
    fwrite(STDERR, "src directory not found: {$srcDir}\n");
    exit(1);
}
if (!is_dir($outDir) && !@mkdir($outDir, 0777, true)) {
    fwrite(STDERR, "cannot create output directory: {$outDir}\n");
    exit(1);
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sanitize_value_html(string $html): string {
    // allow safe subset of HTML
    $allowed = '<p><br><ul><ol><li><b><i><strong><em><u><a><h1><h2><h3><h4><h5><h6><blockquote><code><pre><span>';
    $clean = strip_tags($html, $allowed);
    // force safe <a> tags
    $clean = preg_replace_callback(
        '/<a\b[^>]*href=("|\')(.*?)\1[^>]*>/i',
        function ($m) {
            $tag = $m[0];
            if (!preg_match('/\btarget=/', $tag)) $tag = rtrim($tag, '>') . ' target="_blank">';
            if (!preg_match('/\brel=/', $tag)) $tag = rtrim($tag, '>') . ' rel="noopener noreferrer">';
            return $tag;
        },
        $clean
    );
    return $clean;
}

/**
 * Load language arrays from PHP files with different formats.
 */
function load_lang_file(string $path): array {
    $ret = @include $path;
    if (is_array($ret)) return $ret;

    $varsAfter = (function($p) {
        include $p;
        return get_defined_vars();
    })($path);

    if (isset($varsAfter['lang']) && is_array($varsAfter['lang'])) {
        return $varsAfter['lang'];
    }

    $best = null;
    $bestScore = -1;
    foreach ($varsAfter as $val) {
        if (!is_array($val)) continue;
        $count = count($val);
        $stringKeys = 0;
        $stringVals = 0;
        foreach ($val as $k => $v) {
            if (is_string($k)) $stringKeys++;
            if (is_string($v)) $stringVals++;
        }
        $score = $count + (2 * $stringKeys) + $stringVals;
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $val;
        }
    }
    if (is_array($best)) return $best;

    $code = @file_get_contents($path);
    if ($code !== false) {
        $dict = [];
        $re = '/\$\w+\s*\[\s*([\'"])([^\'"]+)\1\s*\]\s*=\s*([\'"])((?:\\\\.|(?!\3).)*?)\3\s*;/sm';
        if (preg_match_all($re, $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $k = $row[2];
                $v = stripcslashes($row[4]);
                $dict[$k] = $v;
            }
            if ($dict) return $dict;
        }
    }
    return [];
}

$files = glob($srcDir . $pattern) ?: [];
sort($files, SORT_STRING);
if (!$files) fwrite(STDERR, "no *_lang.php files found\n");

$indexLinks = [];

foreach ($files as $f) {
    $base = basename($f);
    $name = preg_replace('/_' . preg_quote($suffix, '/') . '\.php$/', '', $base) ?: $base;

    $dict = load_lang_file($f);

    $html = [];
    $html[] = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    $html[] = '<style>
        body { max-width: 960px; margin: 2rem auto; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; line-height: 1.5; }
        h1 { font-size: 1.6rem; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ccc; padding: .5rem .6rem; vertical-align: top; }
        th { background: #f5f5f5; }
        code { background: #f6f8fa; padding: .1rem .3rem; border-radius: 4px; }
        td.content > * { margin: .3rem 0; }
    </style>';
    $html[] = '<title>' . e($name) . '</title>';
    $html[] = '<h1>' . e($name) . '</h1>';

    if (!$dict) {
        $html[] = '<p><strong>Note:</strong> no key→value pairs detected.</p>';
    } else {
        $html[] = '<table><thead><tr><th>Key</th><th>Text</th></tr></thead><tbody>';
        foreach ($dict as $k => $v) {
            $hk = e((string)$k);
            if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $valHtml = sanitize_value_html((string)$v);
            $html[] = '<tr><td style="white-space:nowrap">' . $hk . '</td><td class="content">' . $valHtml . '</td></tr>';
        }
        $html[] = '</tbody></table>';
    }

    $html[] = '<p>Source: <code>' . e($base) . '</code></p>';

    $outPath = $outDir . '/' . $name . '.html';
    if (@file_put_contents($outPath, implode("\n", $html)) === false) {
        fwrite(STDERR, "failed to write {$outPath}\n");
    } else {
        $indexLinks[] = '<li><a href="' . e($name) . '.html">' . e($name) . '</a></li>';
    }
}

$index = [];
$index[] = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
$index[] = '<style>body{max-width:720px;margin:2rem auto;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;line-height:1.5;}h1{font-size:1.8rem;}li{margin:.25rem 0;}</style>';
$index[] = '<title>Index</title><h1>Content</h1><ul>' . implode("\n", $indexLinks) . '</ul>';

@file_put_contents($outDir . '/index.html', implode("\n", $index));
