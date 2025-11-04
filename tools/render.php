<?php
declare(strict_types=1);

/**
 * Render HTML pages from language PHP files and show diffs vs a baseline commit.
 *
 * Assumptions:
 * - Source files live in ../src
 * - Output HTML goes to ../site
 * - Files end with "_lang.php" (hardcoded)
 * - Git history is available (checkout with fetch-depth: 0 in CI) for diffs
 *
 * Usage:
 *   php tools/render.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

/* ---------------------------- config -------------------------------------- */

$repoUrl        = 'https://github.com/gezapeti/oncosite';
$baselineCommit = 'cab94ac3fd68b41f6577288cd4f6c3b8ca08d0d4';

$suffix   = 'lang';                 // one-off hardcoded suffix
$srcDir   = __DIR__ . '/../src';
$outDir   = __DIR__ . '/../site';
$pattern  = sprintf('/*_%s.php', $suffix);
$repoRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

/* ---------------------------- fs setup ------------------------------------ */

if (!is_dir($srcDir)) {
    fwrite(STDERR, "src directory not found: {$srcDir}\n");
    exit(1);
}
if (!is_dir($outDir) && !@mkdir($outDir, 0777, true)) {
    fwrite(STDERR, "cannot create output directory: {$outDir}\n");
    exit(1);
}

/* ---------------------------- helpers ------------------------------------- */

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sanitize_value_html(string $html): string {
    $allowed = '<p><br><ul><ol><li><b><i><strong><em><u><a><h1><h2><h3><h4><h5><h6><blockquote><code><pre><span>';
    $clean = strip_tags($html, $allowed);
    // Harden <a> tags
    $clean = preg_replace_callback(
        '/<a\b[^>]*href=("|\')(.*?)\1[^>]*>/i',
        function ($m) {
            $tag = $m[0];
            if (!preg_match('/\btarget=/', $tag)) $tag = rtrim($tag, '>') . ' target="_blank">';
            if (!preg_match('/\brel=/', $tag))    $tag = rtrim($tag, '>') . ' rel="noopener noreferrer">';
            return $tag;
        },
        $clean
    );
    return $clean;
}

/**
 * Word-level diff with simple LCS.
 * Returns HTML with <del> for deletions and <ins> for insertions.
 * Input strings are converted to plain text for diffing, but result is safe HTML.
 */
function html_word_diff(string $old, string $new): string {
    // Strip tags for diff clarity, keep text
    $old = trim(preg_replace('/\s+/u', ' ', strip_tags($old)));
    $new = trim(preg_replace('/\s+/u', ' ', strip_tags($new)));

    if ($old === '' && $new === '') return '';
    if ($old === $new) return '<span class="unchanged">no change</span>';

    $a = preg_split('/(\s+)/u', $old, -1, PREG_SPLIT_DELIM_CAPTURE);
    $b = preg_split('/(\s+)/u', $new, -1, PREG_SPLIT_DELIM_CAPTURE);

    // Build index of words in $b
    $bIndex = [];
    foreach ($b as $i => $token) {
        if (trim($token) === '') continue;
        $bIndex[$token][] = $i;
    }

    // LCS dynamic programming compressed via patience diff style map
    $matches = [];
    $prevK = -1;
    foreach ($a as $i => $token) {
        if (trim($token) === '' || !isset($bIndex[$token])) continue;
        foreach (array_reverse($bIndex[$token]) as $j) {
            if ($j > $prevK) {
                $matches[] = [$i, $j];
                $prevK = $j;
                break;
            }
        }
    }

    $out = [];
    $ai = 0; $bi = 0;
    foreach ($matches as [$mi, $mj]) {
        // deletions from $a
        while ($ai < $mi) {
            $tok = $a[$ai++];
            $out[] = trim($tok) === '' ? e($tok) : '<del>' . e($tok) . '</del>';
        }
        // insertions from $b
        while ($bi < $mj) {
            $tok = $b[$bi++];
            $out[] = trim($tok) === '' ? e($tok) : '<ins>' . e($tok) . '</ins>';
        }
        // match
        $out[] = e($a[$ai++]); // equals $b[$bi++]
        $bi++;
    }
    // tail
    while ($ai < count($a)) {
        $tok = $a[$ai++];
        $out[] = trim($tok) === '' ? e($tok) : '<del>' . e($tok) . '</del>';
    }
    while ($bi < count($b)) {
        $tok = $b[$bi++];
        $out[] = trim($tok) === '' ? e($tok) : '<ins>' . e($tok) . '</ins>';
    }

    return implode('', $out);
}

/**
 * Load language arrays from a PHP file path using multiple strategies.
 */
function load_lang_file(string $path): array {
    // Case 1: include returns array
    $ret = @include $path;
    if (is_array($ret)) return $ret;

    // Case 2/3: include in isolated scope, inspect vars
    $varsAfter = (function($p) {
        include $p;
        return get_defined_vars();
    })($path);

    if (isset($varsAfter['lang']) && is_array($varsAfter['lang'])) {
        return $varsAfter['lang'];
    }

    // Choose best array by heuristic
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

    // Case 4: parse $var['key'] = 'value';
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

/**
 * Load language array for a given source file as it existed at $commit.
 * Requires Git history locally.
 */
function load_lang_at_commit(string $repoRoot, string $relPath, string $commit): array {
    // Use git show to get blob
    $cmd = sprintf('git -C %s show %s:%s 2>/dev/null', escapeshellarg($repoRoot), escapeshellarg($commit), escapeshellarg($relPath));
    $code = shell_exec($cmd);
    if ($code === null) return [];
    // Write to a temp file and reuse the loader
    $tmp = tempnam(sys_get_temp_dir(), 'lang_');
    if ($tmp === false) return [];
    file_put_contents($tmp, $code);
    $arr = load_lang_file($tmp);
    @unlink($tmp);
    return is_array($arr) ? $arr : [];
}

/* ---------------------------- discovery ----------------------------------- */

$files = glob($srcDir . $pattern) ?: [];
sort($files, SORT_STRING);
if (!$files) {
    fwrite(STDERR, "no files matched {$pattern} under {$srcDir}\n");
}

/* ---------------------------- render -------------------------------------- */

$indexLinks = [];

foreach ($files as $f) {
    $base = basename($f);
    // strip the trailing "_lang.php"
    $name = preg_replace('/_' . preg_quote($suffix, '/') . '\.php$/', '', $base) ?: $base;

    $dictNew = load_lang_file($f);

    // Compute baseline dict via git. relPath from repo root:
    $relPath = ltrim(str_replace($repoRoot, '', realpath($f) ?: $f), '/');
    // If realpath failed or path not under repoRoot, fall back to "src/<base>"
    if ($relPath === '' || !str_ends_with($relPath, $base)) {
        $relPath = 'src/' . $base;
    }
    $dictOld = load_lang_at_commit($repoRoot, $relPath, $baselineCommit);

    // Build GitHub URLs
    $ghFileUrl   = rtrim($repoUrl, '/') . '/blob/main/' . e('src/' . $base);
    $ghHistoryUrl= rtrim($repoUrl, '/') . '/commits/main/' . e('src/' . $base);
    $ghAtCommit  = rtrim($repoUrl, '/') . '/blob/' . e($baselineCommit) . '/' . e('src/' . $base);

    $html = [];
    $html[] = '<!doctype html>';
    $html[] = '<meta charset="utf-8">';
    $html[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
    $html[] = '<style>
        body { max-width: 1100px; margin: 2rem auto; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; line-height: 1.5; }
        h1 { font-size: 1.6rem; margin-bottom: .75rem; }
        .toplinks { margin:.5rem 0 1.25rem; font-size:.95rem; }
        .toplinks a { margin-right: 1rem; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        th, td { border: 1px solid #ccc; padding: .5rem .6rem; vertical-align: top; }
        th { background: #f5f5f5; text-align:left; }
        th:nth-child(1) { width: 24%; }
        th:nth-child(2) { width: 38%; }
        th:nth-child(3) { width: 38%; }
        td.content > * { margin: .3rem 0; }
        code { background: #f6f8fa; padding: .1rem .3rem; border-radius: 4px; }
        /* diff colors */
        del { background: #ffeef0; text-decoration: line-through; }
        ins { background: #e6ffed; text-decoration: none; }
        .unchanged { color:#777; }
        .note { color:#555; }
    </style>';
    $html[] = '<title>' . e($name) . '</title>';
    $html[] = '<h1>' . e($name) . '</h1>';
    $html[] = '<div class="toplinks">'
            . '<a href="' . $ghFileUrl . '" target="_blank" rel="noopener noreferrer">View source on GitHub</a>'
            . '<a href="' . $ghHistoryUrl . '" target="_blank" rel="noopener noreferrer">History</a>'
            . '<a href="' . $ghAtCommit . '" target="_blank" rel="noopener noreferrer">Baseline @ ' . e(substr($baselineCommit,0,7)) . '</a>'
            . '</div>';

    if (!$dictNew) {
        $html[] = '<p class="note"><strong>Note:</strong> no key→value pairs detected.</p>';
    } else {
        $html[] = '<table>';
        $html[] = '<thead><tr><th>Key</th><th>Text</th><th>Diff vs ' . e(substr($baselineCommit,0,7)) . '</th></tr></thead><tbody>';

        foreach ($dictNew as $k => $vNew) {
            $hk = e((string)$k);

            // Normalize values for display
            if (is_array($vNew)) $vNew = json_encode($vNew, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $valHtml = sanitize_value_html((string)$vNew);

            // Baseline value
            $vOld = $dictOld[$k] ?? '';
            if (is_array($vOld)) $vOld = json_encode($vOld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $diffHtml = html_word_diff((string)$vOld, (string)$vNew);

            $html[] = '<tr>'
                    .  '<td style="white-space:nowrap">' . $hk . '</td>'
                    .  '<td class="content">' . $valHtml . '</td>'
                    .  '<td class="content">' . $diffHtml . '</td>'
                    . '</tr>';
        }
        $html[] = '</tbody></table>';
    }

    $html[] = '<p class="note">Source file: <code>' . e('src/' . $base) . '</code></p>';

    $outPath = $outDir . '/' . $name . '.html';
    if (@file_put_contents($outPath, implode("\n", $html)) === false) {
        fwrite(STDERR, "failed to write {$outPath}\n");
    } else {
        $indexLinks[] = '<li><a href="' . e($name) . '.html">' . e($name) . '</a></li>';
    }
}

/* ---------------------------- index --------------------------------------- */

$index = [];
$index[] = '<!doctype html>';
$index[] = '<meta charset="utf-8">';
$index[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
$index[] = '<style>
    body{max-width:720px;margin:2rem auto;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;line-height:1.5;}
    h1{font-size:1.8rem;}
    li{margin:.25rem 0;}
</style>';
$index[] = '<title>Index</title>';
$index[] = '<h1>Content</h1>';
$index[] = '<ul>' . implode("\n", $indexLinks) . '</ul>';

@file_put_contents($outDir . '/index.html', implode("\n", $index));
