<?php
declare(strict_types=1);

function load_lang_file(string $path): array {
    // Pattern A: file returns an array
    $ret = @include $path;
    if (is_array($ret)) return $ret;

    // Pattern B: file sets $lang = [ ... ];
    $lang = null;
    (function($p) use (&$lang) { $lang = null; include $p; })($path);
    if (is_array($lang)) return $lang;

    // Pattern C fallback: parse simple "$lang['k'] = 'v';" assignments
    $lang = [];
    $code = file_get_contents($path);
    if ($code !== false) {
        $re = "/\\$lang\\[['\"]([^'\"\\]]+)['\"]\\]\\s*=\\s*['\"]((?:\\\\.|[^'\"\\\\])*)['\"];?/u";
        if (preg_match_all($re, $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) $lang[$row[1]] = stripcslashes($row[2]);
        }
    }
    return $lang;
}

$srcDir  = __DIR__ . '/../src';
$outDir  = __DIR__ . '/../site';
@mkdir($outDir, 0777, true);

$files = glob($srcDir . '/*_lang.php');
sort($files);

$indexLinks = [];

foreach ($files as $f) {
    $base = basename($f);
    $name = preg_replace('/_lang\.php$/', '', $base) ?: $base;

    $dict = load_lang_file($f);
    $html = [];
    $html[] = '<!doctype html><meta charset="utf-8">';
    $html[] = "<title>{$name}</title>";
    $html[] = "<h1>{$name}</h1>";
    if (!$dict) {
        $html[] = "<p><strong>Note:</strong> no key→value pairs detected.</p>";
    } else {
        $html[] = '<table border="1" cellpadding="6" cellspacing="0">';
        $html[] = '<thead><tr><th>Key</th><th>Text</th></tr></thead><tbody>';
        foreach ($dict as $k => $v) {
            $hk = htmlspecialchars((string)$k, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $hv = htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html[] = "<tr><td style=\"white-space:nowrap\">{$hk}</td><td>{$hv}</td></tr>";
        }
        $html[] = '</tbody></table>';
    }
    $html[] = "<p>Source: <code>{$base}</code></p>";
    file_put_contents($outDir . "/{$name}.html", implode("\n", $html));
    $indexLinks[] = "<li><a href=\"{$name}.html\">{$name}</a></li>";
}

// index
$index = "<!doctype html><meta charset=\"utf-8\"><title>Index</title><h1>Content</h1><ul>"
       . implode("\n", $indexLinks) . "</ul>";
file_put_contents($outDir . "/index.html", $index);
