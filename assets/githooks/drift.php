<?php
// ddev-wordpress-generated — managed by augustash/ddev-wordpress; overwritten on
// composer update. Remove this line to take ownership and stop updates.
/**
 * drift.php — compare repo plugin/theme versions against the live server.
 *
 * Usage:  drift.php <repo-versions-file>  < server-json
 *
 *   <repo-versions-file>  tab-separated "type<TAB>name<TAB>version" lines
 *                         (produced by .githooks/repo-versions)
 *   server-json (stdin)   the live `wp plugin list --format=json` output,
 *                         then a line "@@THEMES@@", then `wp theme list` json.
 *
 * Prints one line per item where the SERVER version is strictly newer than
 * the repo's (a deploy would revert it). Uses PHP's version_compare — the
 * same function WordPress uses — so "newer" means exactly what WP means.
 *
 * Exit: 2 if any drift found, 0 if clean, 1 on bad input.
 */

if ($argc < 2 || !is_readable($argv[1])) {
    fwrite(STDERR, "drift.php: cannot read repo-versions file\n");
    exit(1);
}

$raw = stream_get_contents(STDIN);
list($pluginJson, $themeJson) = array_pad(explode('@@THEMES@@', $raw, 2), 2, '[]');
$plugins = json_decode($pluginJson, true);
$themes  = json_decode($themeJson,  true);
if (!is_array($plugins) || !is_array($themes)) {
    fwrite(STDERR, "drift.php: server did not return valid json\n");
    exit(1);
}

$server = [];
foreach ($plugins as $p) { $server["plugin\t{$p['name']}"] = $p['version']; }
foreach ($themes  as $t) { $server["theme\t{$t['name']}"]  = $t['version']; }

$drift = [];
foreach (file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    list($type, $name, $rver) = array_pad(explode("\t", $line, 3), 3, '');
    $key = "$type\t$name";
    if (!isset($server[$key])) { continue; }          // not on server → nothing to clobber
    $sver = $server[$key];
    if (version_compare($sver, $rver, '>')) {          // server strictly ahead → clobber risk
        $drift[] = sprintf('    %-46s repo=%-14s server=%s', "$type/$name", $rver, $sver);
    }
}

if ($drift) {
    fwrite(STDERR, implode("\n", $drift) . "\n");
    exit(2);
}
exit(0);
