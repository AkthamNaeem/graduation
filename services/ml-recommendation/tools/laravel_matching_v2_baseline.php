<?php

declare(strict_types=1);

use App\Models\Education;
use App\Models\Experience;
use App\Models\JobPosting;
use App\Models\JobPostingSkill;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Services\MatchingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const ADAPTER_VERSION = 'synthetic-to-laravel-matching-v1';
const MATCHING_VERSION = '2.0';
const LOCKED_TEST_SHA256 = '79fcb93b232b63482a9c26d1d0caa660289b7b798776c09f0945865ca6741a05';
const ALLOWED_SPLIT_HASHES = [
    'train' => 'd87095055d16ced57461eb8d4543bf4c3863b0ebe1771e5b3528eaf290b98c3d',
    'validation' => 'a8cc27158bc126b11e93a0eefdf6a82a0e3f88e8d82cf9e9a0bae0491b04da7e',
];

/**
 * Execute the production MatchingService against deterministic, in-memory
 * Eloquent models. This harness must never issue a database query or write.
 */
function failBaseline(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit($exitCode);
}

/** @param array<int, string|null> $parts */
function joinParts(array $parts): string
{
    return trim(collect($parts)
        ->filter(fn ($part): bool => filled($part))
        ->map(fn ($part): string => trim((string) $part))
        ->implode(' '));
}

/** @param array<string, int> $registry */
function skillModel(int $skillId, array $registry): Skill
{
    $name = array_search($skillId, $registry, true);
    if (! is_string($name)) {
        failBaseline("Unknown adapted skill id: {$skillId}");
    }

    $skill = new Skill(['name' => $name, 'slug' => str_replace(' ', '-', $name)]);
    $skill->id = $skillId;

    return $skill;
}

/** @param array<string, mixed> $data
 *  @param array<string, int> $registry
 */
function profileModel(array $data, array $registry): JobSeekerProfile
{
    $profile = new JobSeekerProfile([
        'headline' => (string) $data['headline'],
        'summary' => (string) $data['summary'],
    ]);
    $profile->id = (int) preg_replace('/\D+/', '', (string) $data['source_id']);
    $profile->setRelation('skills', collect($data['skill_ids'])->map(
        fn ($skillId): Skill => skillModel((int) $skillId, $registry),
    )->values());

    $experience = new Experience([
        'title' => (string) $data['experience_title'],
        'description' => null,
        'start_date' => (string) $data['experience_start'],
        'end_date' => (string) $data['experience_end'],
        'is_current' => false,
    ]);
    $education = new Education([
        'institution' => null,
        'degree' => (string) $data['education_degree'],
        'field_of_study' => (string) $data['education_field'],
        'description' => null,
    ]);
    $profile->setRelation('experiences', collect([$experience]));
    $profile->setRelation('education', collect([$education]));

    return $profile;
}

/** @param array<string, mixed> $data
 *  @param array<string, int> $registry
 */
function jobModel(array $data, array $registry): JobPosting
{
    $job = new JobPosting([
        'title' => (string) $data['title'],
        'department' => (string) $data['department'],
        'description' => (string) $data['description'],
        'responsibilities' => (string) $data['responsibilities'],
        'requirements' => (string) $data['requirements'],
        'experience_level' => (string) $data['experience_level'],
        'education_level' => (string) $data['education_level'],
        'published_at' => (string) $data['published_at'],
    ]);
    $job->id = (int) preg_replace('/\D+/', '', (string) $data['source_id']);
    $job->setRelation('skills', collect($data['skills'])->map(
        function (array $requirement) use ($job, $registry): Skill {
            $skill = skillModel((int) $requirement['skill_id'], $registry);
            $pivot = new JobPostingSkill([
                'job_posting_id' => (int) $job->id,
                'skill_id' => (int) $requirement['skill_id'],
                'requirement_type' => (string) $requirement['requirement_type'],
                'weight' => (int) $requirement['weight'],
            ]);
            $skill->setRelation('pivot', $pivot);

            return $skill;
        },
    )->values());

    return $job;
}

try {
    $request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($request)) {
        failBaseline('The bridge request must be a JSON object.');
    }
    $split = (string) ($request['split_name'] ?? '');
    if (! array_key_exists($split, ALLOWED_SPLIT_HASHES)) {
        failBaseline('Only train and validation splits are permitted.');
    }
    if (($request['adapter_version'] ?? null) !== ADAPTER_VERSION) {
        failBaseline('Adapter version mismatch.');
    }
    if (strtolower((string) ($request['locked_test_sha256'] ?? '')) !== strtolower(LOCKED_TEST_SHA256)) {
        failBaseline('Locked Test hash contract mismatch.');
    }

    $splitPath = (string) ($request['split_file']['path'] ?? '');
    $requestedHash = strtolower((string) ($request['split_file']['sha256'] ?? ''));
    if (strtolower(basename($splitPath)) === 'test.jsonl') {
        failBaseline('Locked Test evaluation is prohibited.');
    }
    if (! is_file($splitPath)) {
        failBaseline('Split file does not exist.');
    }
    $actualHash = strtolower((string) hash_file('sha256', $splitPath));
    if ($actualHash === strtolower(LOCKED_TEST_SHA256)) {
        failBaseline('Locked Test content is prohibited.');
    }
    if ($requestedHash !== $actualHash || $actualHash !== ALLOWED_SPLIT_HASHES[$split]) {
        failBaseline('Split hash mismatch.');
    }

    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=:memory:');
    putenv('CACHE_STORE=array');
    putenv('SESSION_DRIVER=array');
    putenv('QUEUE_CONNECTION=sync');
    $root = getcwd();
    if (! is_string($root) || ! is_file($root.'/vendor/autoload.php')) {
        failBaseline('Run the bridge from the Laravel repository root.');
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if ((string) config('matching.version') !== MATCHING_VERSION
        || config('matching.components') !== [
            'required_skills' => 45,
            'nice_to_have_skills' => 10,
            'experience' => 20,
            'education' => 10,
            'text_similarity' => 15,
        ]) {
        failBaseline('Production MatchingService 2.0 configuration mismatch.');
    }

    $queryCount = 0;
    $writeCount = 0;
    DB::listen(function ($query) use (&$queryCount, &$writeCount): void {
        $queryCount++;
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
            $writeCount++;
        }
    });

    /** @var array<string, int> $registry */
    $registry = $request['skill_registry'];
    /** @var array<string, array<string, mixed>> $candidateData */
    $candidateData = $request['candidates'];
    /** @var array<string, array<string, mixed>> $jobData */
    $jobData = $request['jobs'];
    /** @var array<int, array{candidate_id: string, job_ids: array<int, string>}> $groups */
    $groups = $request['groups'];
    $matching = app(MatchingService::class);
    $records = [];

    foreach ($groups as $group) {
        $candidateId = (string) $group['candidate_id'];
        if (! array_key_exists($candidateId, $candidateData)) {
            failBaseline("Unknown candidate: {$candidateId}");
        }
        $profile = profileModel($candidateData[$candidateId], $registry);
        $profileSections = $matching->buildTextFromProfile($profile);
        $documents = ['anchor' => joinParts(array_values($profileSections))];
        $jobs = [];
        foreach ($group['job_ids'] as $jobId) {
            if (! array_key_exists($jobId, $jobData)) {
                failBaseline("Unknown job: {$jobId}");
            }
            $job = jobModel($jobData[$jobId], $registry);
            $jobs[$jobId] = $job;
            $documents[$jobId] = joinParts(array_values($matching->buildTextFromJob($job)));
        }
        $vectors = $matching->computeTFIDF($documents);
        $candidateRecords = [];
        foreach ($jobs as $jobId => $job) {
            $cosine = $matching->cosineSimilarity(
                $vectors['anchor'] ?? [],
                $vectors[$jobId] ?? [],
            );
            $result = $matching->scoreMatch($job, $profile, $cosine);
            $breakdown = $result['breakdown'];
            $candidateRecords[] = [
                'candidate_id' => $candidateId,
                'job_id' => $jobId,
                'score' => (float) $result['score'],
                'version' => (string) $result['matching_score_version'],
                'components' => [
                    'required_skills' => (float) $breakdown['required_skills']['score'],
                    'nice_to_have_skills' => (float) $breakdown['nice_to_have_skills']['score'],
                    'experience' => (float) $breakdown['experience']['score'],
                    'education' => (float) $breakdown['education']['score'],
                    'text_similarity' => (float) $breakdown['text_similarity']['score'],
                    'cosine_similarity' => (float) $breakdown['text_similarity']['cosine_similarity'],
                ],
            ];
        }
        usort($candidateRecords, fn (array $left, array $right): int => ($right['score'] <=> $left['score'])
            ?: ($left['job_id'] <=> $right['job_id']));
        foreach ($candidateRecords as $index => $record) {
            $record['rank'] = $index + 1;
            $records[] = $record;
        }
    }

    if ($queryCount !== 0 || $writeCount !== 0) {
        failBaseline("Database isolation failure: {$queryCount} queries, {$writeCount} writes.");
    }
    echo json_encode([
        'protocol_version' => 'laravel-matching-v2-baseline-v1',
        'split_name' => $split,
        'matching_version' => MATCHING_VERSION,
        'query_count' => $queryCount,
        'write_count' => $writeCount,
        'records' => $records,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo PHP_EOL;
} catch (Throwable $exception) {
    failBaseline($exception::class.': '.$exception->getMessage());
}
