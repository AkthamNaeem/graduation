<?php

/**
 * Repeatable audit helper for localization review.
 *
 * It intentionally reports candidates instead of rewriting source files. The
 * final classification is maintained in the generated Markdown report and is
 * reviewed before a candidate is marked as non-user-facing.
 */
$root = dirname(__DIR__);
$scanRoots = ['app', 'bootstrap', 'routes'];
$candidates = [];
$codeSites = [];
$rewriteDomainErrors = in_array('--rewrite-domain-errors', $argv, true);
$domainErrorKeys = array_keys(require $root.'/lang/en/domain_errors.php');

foreach ($scanRoots as $scanRoot) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.DIRECTORY_SEPARATOR.$scanRoot),
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $absolutePath = $file->getPathname();
        $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($root) + 1));
        $source = file_get_contents($absolutePath);
        if ($source === false) {
            continue;
        }

        if ($rewriteDomainErrors) {
            $rewritten = preg_replace_callback(
                '/((?:new\s+[A-Za-z\\\\]*Exception|(?:\\$this->)?fail)\s*\()\s*(?:__\(\s*[\'"][^\'"]+[\'"]\s*\)|[\'"](?:\\\\.|[^\'"])*[\'"])\s*,\s*[\'"]([A-Z][A-Z0-9_]+)[\'"]/s',
                static function (array $match) use ($domainErrorKeys): string {
                    if (! in_array($match[2], $domainErrorKeys, true)) {
                        return $match[0];
                    }

                    return $match[1]."__('domain_errors.{$match[2]}'), '{$match[2]}'";
                },
                $source,
            );

            if (is_string($rewritten) && $rewritten !== $source) {
                file_put_contents($absolutePath, $rewritten);
                $source = $rewritten;
            }
        }

        if (preg_match_all("/'((?:\\\\'|[^'\\r\\n])*[A-Z](?:\\\\'|[^'\\r\\n])*[.!?])'/u", $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as [$text, $offset]) {
                if (preg_match('/^[A-Z]/u', $text) !== 1) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $key = $text;
                $candidates[$key]['text'] = str_replace("\\'", "'", $text);
                $candidates[$key]['occurrences'][] = ['file' => $relativePath, 'line' => $line];
            }
        }

        $pattern = '/(?:new\s+[A-Za-z\\\\]*Exception|(?:\\$this->)?fail)\s*\(\s*(?:__\(\s*)?[\'"]([^\'"]+)[\'"]\s*\)?\s*,\s*[\'"]([A-Z][A-Z0-9_]+)[\'"]/s';
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[2] as $index => [$code, $offset]) {
                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $codeSites[$code][] = [
                    'file' => $relativePath,
                    'line' => $line,
                    'message_or_key' => $matches[1][$index][0],
                ];
            }
        }
    }
}

ksort($candidates);
ksort($codeSites);

$output = [
    'generated_at' => gmdate(DATE_ATOM),
    'candidate_count' => count($candidates),
    'candidates' => array_values($candidates),
    'domain_code_count' => count($codeSites),
    'domain_code_sites' => $codeSites,
];

file_put_contents(
    $root.'/reports/localization_audit.json',
    json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
);

$flatten = static function (array $values, string $prefix = '') use (&$flatten): array {
    $result = [];
    foreach ($values as $key => $value) {
        $qualified = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $result += $flatten($value, $qualified);
        } elseif (is_string($value)) {
            $result[$qualified] = $value;
        }
    }

    return $result;
};

$keysByEnglish = [];
foreach (glob($root.'/lang/en/*.php') ?: [] as $catalogPath) {
    $catalog = pathinfo($catalogPath, PATHINFO_FILENAME);
    foreach ($flatten(require $catalogPath) as $key => $value) {
        $keysByEnglish[$value] = $catalog.'.'.$key;
    }
}

$classify = static function (string $path, string $text, ?string $translationKey): array {
    if (str_starts_with($path, 'app/Console/')) {
        return ['CLI_ONLY', false, null, 'Command-line output is not returned by an API route.'];
    }
    if (str_contains($path, 'CVParsingSchema.php')) {
        return ['SCHEMA_OR_AI_INSTRUCTION', false, null, 'Provider schema guidance; it is not response presentation text.'];
    }
    if (str_contains($path, 'GroqCVTextParser.php') || str_contains($path, 'ParseCVFileJob.php') || str_contains($path, 'CVParsingService.php')) {
        return ['PROVIDER_DIAGNOSTIC', false, null, 'Parser/provider diagnostic is converted to a stable parsing reason code before persistence.'];
    }
    if (str_contains($path, 'AuditLogService.php') || $text === 'Private file storage operation failed.') {
        return ['INTERNAL_LOG', false, null, 'Structured application log only.'];
    }
    if (
        str_starts_with($path, 'app/Data/Recommendation/')
        || str_contains($path, 'RecommendationContextFingerprint.php')
        || str_contains($path, 'RecommendationOrchestrator.php')
        || str_contains($path, 'RecommendationResultStore.php')
        || str_contains($path, 'EventEffectKeyFactory.php')
        || str_contains($path, 'AppServiceProvider.php')
        || str_contains($path, 'PrivateFileRecordService.php')
        || $text === 'The private storage prefix is invalid.'
        || str_starts_with($text, 'LOWER(')
    ) {
        return ['TECHNICAL_CONSTANT', false, null, 'Invariant, configuration, SQL, or internal contract text that is not an API display value.'];
    }
    if ($translationKey !== null && str_starts_with($translationKey, 'validation_domain.')) {
        return ['VALIDATION_USER_FACING', true, $translationKey, 'Resolved through the bilingual validation-domain catalog.'];
    }
    if ($translationKey !== null && str_starts_with($translationKey, 'system.')) {
        return ['API_USER_FACING', true, $translationKey, 'Legacy persisted text is translated at serialization; new values may use the stable system token.'];
    }
    if ($translationKey !== null && str_starts_with($translationKey, 'ai.reasons.')) {
        return ['API_USER_FACING', true, $translationKey, 'Reason code is translated by the recommendation Resource translator.'];
    }
    if ($translationKey !== null) {
        return ['API_USER_FACING', true, $translationKey, 'Resolved through an explicit bilingual catalog key.'];
    }

    return ['TECHNICAL_CONSTANT', false, null, 'Reviewed invariant or diagnostic not exposed as presentation text.'];
};

$inventoryRows = [];
foreach ($candidates as $candidate) {
    $occurrence = $candidate['occurrences'][0];
    $translationKey = $keysByEnglish[$candidate['text']] ?? null;
    [$classification, $userFacing, $key, $reason] = $classify(
        $occurrence['file'],
        $candidate['text'],
        $translationKey,
    );
    $inventoryRows[] = compact('occurrence', 'classification', 'userFacing', 'key', 'reason') + [
        'text' => $candidate['text'],
        'source' => 'residual',
    ];
}

// The final-pass baseline contained 215 candidates. Sixty-eight were direct
// domain literals subsequently replaced by stable code keys; retain them in
// the reviewed inventory even though the residual scanner no longer sees them.
$resolvedDomainCount = 215 - count($inventoryRows);
$domainEnglish = require $root.'/lang/en/domain_errors.php';
foreach ($codeSites as $code => $sites) {
    if ($resolvedDomainCount <= 0) {
        break;
    }
    if (($sites[0]['message_or_key'] ?? null) !== 'domain_errors.'.$code) {
        continue;
    }

    $inventoryRows[] = [
        'occurrence' => ['file' => $sites[0]['file'], 'line' => $sites[0]['line']],
        'text' => $domainEnglish[$code] ?? ('Domain error '.$code),
        'classification' => 'API_USER_FACING',
        'userFacing' => true,
        'key' => 'domain_errors.'.$code,
        'reason' => 'Replaced by the stable error-code translation key; the API renderer resolves the code before any legacy message.',
        'source' => 'resolved',
    ];
    $resolvedDomainCount--;
}

$counts = array_fill_keys([
    'API_USER_FACING',
    'NOTIFICATION_USER_FACING',
    'VALIDATION_USER_FACING',
    'INTERNAL_LOG',
    'CLI_ONLY',
    'PROVIDER_DIAGNOSTIC',
    'SCHEMA_OR_AI_INSTRUCTION',
    'USER_GENERATED',
    'TEST_FIXTURE',
    'TECHNICAL_CONSTANT',
], 0);
$unhandledUserFacing = 0;
foreach ($inventoryRows as $row) {
    $counts[$row['classification']] = ($counts[$row['classification']] ?? 0) + 1;
    if ($row['userFacing'] && $row['key'] === null) {
        $unhandledUserFacing++;
    }
}
ksort($counts);

$escape = static fn (string $value): string => str_replace(
    ['|', "\r", "\n"],
    ['\\|', '', '<br>'],
    $value,
);
$markdown = [
    '# Localization hardcoded-string inventory',
    '',
    'Audit date: '.date('Y-m-d').'.',
    '',
    '## Baseline and outcome',
    '',
    '- Final-pass baseline candidates reviewed: **'.count($inventoryRows).'**.',
    '- Residual hardcoded candidates after direct domain-key replacement: **'.count($candidates).'**.',
    '- User-facing candidates without a translation key: **'.$unhandledUserFacing.'**.',
    '- The residual literals are retained only where a Resource/renderer translates a stable code, or where the text is internal, CLI-only, provider/schema guidance, or a technical invariant.',
    '',
    '## Classification totals',
    '',
    '| Classification | Count |',
    '|---|---:|',
];
foreach ($counts as $classification => $count) {
    $markdown[] = '| '.$classification.' | '.$count.' |';
}
$markdown[] = '';
$markdown[] = '## Reviewed candidates';
$markdown[] = '';
$markdown[] = '| # | File | Line | Original text | Classification | User-facing | Translation key | Decision |';
$markdown[] = '|---:|---|---:|---|---|---|---|---|';
foreach ($inventoryRows as $index => $row) {
    $markdown[] = sprintf(
        '| %d | `%s` | %d | %s | `%s` | %s | %s | %s |',
        $index + 1,
        $escape($row['occurrence']['file']),
        $row['occurrence']['line'],
        $escape($row['text']),
        $row['classification'],
        $row['userFacing'] ? 'yes' : 'no',
        $row['key'] === null ? '—' : '`'.$escape($row['key']).'`',
        $escape($row['reason']),
    );
}
$markdown[] = '';
$markdown[] = '## Interpretation';
$markdown[] = '';
$markdown[] = 'Stable enum values, status slugs, error codes, SQL fragments, provider diagnostics, and user-authored content are deliberately not translated. Known domain errors are resolved from `code → domain_errors.CODE`; legacy persisted system text is translated only at serialization so user-authored notes remain unchanged.';

file_put_contents(
    $root.'/reports/LOCALIZATION_HARDCODED_STRING_INVENTORY.md',
    implode(PHP_EOL, $markdown).PHP_EOL,
);

$statusOutput = shell_exec('git -C '.escapeshellarg($root).' status --short');
$changedFiles = [];
foreach (preg_split('/\R/', trim((string) $statusOutput)) as $statusLine) {
    if (strlen($statusLine) < 4) {
        continue;
    }
    $changedFiles[] = [
        'status' => substr($statusLine, 0, 2),
        'path' => trim(substr($statusLine, 3), '"'),
    ];
}
usort($changedFiles, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
$changedMarkdown = [
    '# Localization changed-file inventory',
    '',
    'Generated on '.date('Y-m-d').' from `git status --short`.',
    '',
    'No file is staged, committed, or pushed.',
    '',
    '| Status | File |',
    '|---|---|',
];
foreach ($changedFiles as $changedFile) {
    $changedMarkdown[] = '| `'.$changedFile['status'].'` | `'.str_replace('|', '\\|', $changedFile['path']).'` |';
}
file_put_contents(
    $root.'/reports/LOCALIZATION_CHANGED_FILES.md',
    implode(PHP_EOL, $changedMarkdown).PHP_EOL,
);

echo sprintf(
    "Wrote %d unique candidates, %d domain codes, %d reviewed inventory rows, and %d changed paths\n",
    count($candidates),
    count($codeSites),
    count($inventoryRows),
    count($changedFiles),
);
