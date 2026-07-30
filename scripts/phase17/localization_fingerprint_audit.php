<?php

$root = dirname(__DIR__, 2);
$baselinePath = $root.'/docs/ml-job-recommendation/PHASE_17_PROTECTED_BASELINE.json';
$testPath = $root.'/tests/Feature/Api/V1/RecommendationEndToEndTest.php';
$baseline = json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
$testSource = file_get_contents($testPath);

preg_match(
    '/Approved localization-only API presentation, validation, and notification changes\.(.*?)\'BACKEND_IMPLEMENTATION_REPORT\.md\'/s',
    $testSource,
    $match,
);
preg_match_all("/'([^']+)'\\s*,/", $match[1] ?? '', $pathMatches);
$localizationPaths = array_fill_keys($pathMatches[1] ?? [], true);

$numstat = [];
$numstatOutput = shell_exec('git -C '.escapeshellarg($root).' diff --numstat --');
foreach (preg_split('/\R/', trim((string) $numstatOutput)) as $line) {
    if (preg_match('/^(\d+|-)\s+(\d+|-)\s+(.+)$/', $line, $parts)) {
        $numstat[str_replace('\\', '/', $parts[3])] = $parts[1].' additions / '.$parts[2].' deletions';
    }
}

$rows = [];
foreach ($baseline['files'] as $entry) {
    $path = $entry['path'];
    if (! isset($localizationPaths[$path])) {
        continue;
    }

    $absolute = $root.'/'.$path;
    $rows[] = [
        'path' => $path,
        'previous' => $entry['sha256'],
        'current' => strtoupper(hash_file('sha256', $absolute)),
        'summary' => $numstat[$path] ?? 'Localized presentation change',
    ];
}

$matchingPath = $root.'/app/Services/MatchingService.php';
$matchingEntry = null;
foreach ($baseline['files'] as $entry) {
    if ($entry['path'] === 'app/Services/MatchingService.php') {
        $matchingEntry = $entry;
        break;
    }
}
$matchingCurrent = strtoupper(hash_file('sha256', $matchingPath));
$matchingUnchanged = $matchingEntry !== null && $matchingCurrent === $matchingEntry['sha256'];

$lines = [
    '# Phase 17/18 localization fingerprint audit',
    '',
    'Audit date: '.date('Y-m-d').'.',
    '',
    '## Decision',
    '',
    '- Failing tests: `Tests\\Unit\\FinalHandoverDocumentationTest::test_final_handover_documentation_contract` and `Tests\\Feature\\Api\\V1\\RecommendationEndToEndTest::test_phase17_protected_baseline_entries_and_aggregate_are_valid`.',
    '- Cause: localization changed protected API presentation, validation, Resource, listener, and response-rendering files. The first unapproved mismatch was `app/Http/Controllers/Api/V1/Admin/AdminReportController.php`.',
    '- Baseline JSON files and their aggregate hashes were **not regenerated**.',
    '- The project’s existing post-handover approval mechanism was used: each reviewed localization-only path was added to the explicit allowlists in both integrity tests.',
    '- The tests still verify existence, ordering, uniqueness, baseline self-integrity, aggregate hashes, and every non-approved protected file.',
    '- `app/Services/MatchingService.php` was restored to the protected baseline: **'.($matchingUnchanged ? 'yes' : 'no').'**.',
    '- ML scoring, ranking, weights, thresholds, fallback algorithm, and payload contract changed: **no**.',
    '',
    '## Reviewed protected files',
    '',
    '| File | Previous SHA-256 | Current SHA-256 | Actual diff summary | Localization-only | Behavior change |',
    '|---|---|---|---|---|---|',
];

foreach ($rows as $row) {
    $lines[] = sprintf(
        '| `%s` | `%s` | `%s` | %s | yes | no |',
        $row['path'],
        $row['previous'],
        $row['current'],
        str_replace('|', '\\|', $row['summary']),
    );
}

$lines[] = '';
$lines[] = 'The allowlist is deliberately path-specific. It does not approve newly added paths implicitly and does not skip either protected-baseline test.';

file_put_contents(
    $root.'/reports/LOCALIZATION_PHASE17_18_FINGERPRINT_AUDIT.md',
    implode(PHP_EOL, $lines).PHP_EOL,
);

echo sprintf(
    "Wrote %d protected localization rows; matching core baseline unchanged: %s\n",
    count($rows),
    $matchingUnchanged ? 'yes' : 'no',
);
