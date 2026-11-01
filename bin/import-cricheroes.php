<?php
declare(strict_types=1);

// Sets players' CricHeroes profile links from a CSV file with the columns player (a slug, or the
// exact name) and url. Shows what it would change; nothing is saved without --apply:
//   php bin/import-cricheroes.php links.csv           dry run
//   php bin/import-cricheroes.php links.csv --apply   save the links
// Needs the cricheroes_url column (database/cricheroes.sql).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/site.php';
require_once __DIR__ . '/../includes/cricheroes.php';

$file = $argv[1] ?? '';
$apply = in_array('--apply', $argv, true);
if ($file === '' || !is_readable($file)) {
    fwrite(STDERR, "Usage: php bin/import-cricheroes.php links.csv [--apply]\n");
    exit(1);
}
$handle = fopen($file, 'rb');
$header = array_map(fn ($cell) => strtolower(trim((string) $cell, " \t\n\r\0\x0B\xEF\xBB\xBF")), fgetcsv($handle) ?: []);
$playerAt = array_search('player', $header, true);
$urlAt = array_search('url', $header, true);
if ($playerAt === false || $urlAt === false) {
    fwrite(STDERR, "The first row must name the columns: player,url\n");
    exit(1);
}
$counts = ['set' => 0, 'unchanged' => 0, 'skipped' => 0];
for ($line = 2; ($row = fgetcsv($handle)) !== false; $line++) {
    if (!array_filter($row, fn ($cell) => trim((string) $cell) !== '')) continue;
    $who = trim((string) ($row[$playerAt] ?? ''));
    $url = cricheroesUrl((string) ($row[$urlAt] ?? ''));
    if ($url === null) { echo "line $line: skipped, not a CricHeroes link ($who)\n"; $counts['skipped']++; continue; }
    $matches = query('SELECT id, name, cricheroes_url FROM players WHERE deleted_at IS NULL AND (slug = ? OR name = ?)', [strtolower($who), $who]);
    if (count($matches) !== 1) { echo "line $line: skipped, " . (count($matches) ? count($matches) . ' players match' : 'no player matches') . " '{$who}'\n"; $counts['skipped']++; continue; }
    if ($matches[0]['cricheroes_url'] === $url) { $counts['unchanged']++; continue; }
    echo ($apply ? 'set   ' : 'would set ') . $matches[0]['name'] . ' → ' . $url . "\n";
    if ($apply) db()->prepare('UPDATE players SET cricheroes_url = ? WHERE id = ?')->execute([$url, $matches[0]['id']]);
    $counts['set']++;
}
fclose($handle);
echo ($apply ? 'Saved' : 'Dry run') . ": {$counts['set']} to set, {$counts['unchanged']} unchanged, {$counts['skipped']} skipped.\n";
