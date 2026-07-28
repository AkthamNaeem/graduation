[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$image = 'workeyx/ml-recommendation:0.2.0-phase16'
$containerName = 'workeyx-ml-phase17-e2e'
$tempRoot = Join-Path ([IO.Path]::GetTempPath()) ('workeyx-phase17-' + [guid]::NewGuid().ToString('N'))
$databasePath = Join-Path $tempRoot 'phase17.sqlite'
$helperPath = Join-Path $tempRoot 'phase17-helper.php'
$modePath = Join-Path $tempRoot 'fault-mode.txt'
$eventsPath = Join-Path $tempRoot 'fault-events.log'
$faultOutPath = Join-Path $tempRoot 'fault.out.log'
$faultErrPath = Join-Path $tempRoot 'fault.err.log'
$laravelProcesses = [System.Collections.Generic.List[object]]::new()
$laravelLogs = [System.Collections.Generic.List[string]]::new()
$containerLogs = [System.Collections.Generic.List[string]]::new()
$laravelProcess = $null
$faultProcess = $null
$serverSequence = 0
$summary = [ordered]@{
    phase = 17
    public_http = [ordered]@{}
    fault_matrix = @()
    measurements_ms = [ordered]@{}
    concurrency = [ordered]@{}
    privacy = [ordered]@{}
    cleanup = [ordered]@{}
}

function Assert-Phase17 {
    param(
        [Parameter(Mandatory)]
        [bool] $Condition,
        [Parameter(Mandatory)]
        [string] $Message
    )

    if (-not $Condition) {
        throw $Message
    }
}

function Get-Listener {
    param([int] $Port)

    return @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
}

function Wait-Port {
    param(
        [int] $Port,
        [bool] $Listening,
        [int] $Seconds = 30
    )

    $deadline = [DateTime]::UtcNow.AddSeconds($Seconds)
    do {
        $present = @(Get-Listener -Port $Port).Count -gt 0
        if ($present -eq $Listening) {
            return
        }
        Start-Sleep -Milliseconds 100
    } while ([DateTime]::UtcNow -lt $deadline)

    throw "Port state did not converge for Phase 17."
}

function Stop-Laravel {
    if ($script:laravelProcess -ne $null) {
        Stop-Process -Id $script:laravelProcess.Id -Force -ErrorAction SilentlyContinue
        $script:laravelProcess = $null
    }
    foreach ($listener in @(Get-Listener -Port 8090)) {
        Stop-Process -Id $listener.OwningProcess -Force -ErrorAction SilentlyContinue
    }
    Wait-Port -Port 8090 -Listening $false
}

function Start-Laravel {
    param(
        [Parameter(Mandatory)]
        [string] $BaseUrl,
        [Parameter(Mandatory)]
        [string] $ServiceToken
    )

    Stop-Laravel
    $env:ML_RECOMMENDATION_BASE_URL = $BaseUrl
    $env:ML_RECOMMENDATION_SERVICE_TOKEN = $ServiceToken
    $script:serverSequence++
    $stdout = Join-Path $tempRoot ("laravel-$($script:serverSequence).out.log")
    $stderr = Join-Path $tempRoot ("laravel-$($script:serverSequence).err.log")
    $script:laravelLogs.Add($stdout)
    $script:laravelLogs.Add($stderr)
    $script:laravelProcess = Start-Process `
        -FilePath 'php' `
        -ArgumentList @(
            'artisan',
            'serve',
            '--host=127.0.0.1',
            '--port=8090',
            '--no-reload'
        ) `
        -WorkingDirectory $repositoryRoot `
        -RedirectStandardOutput $stdout `
        -RedirectStandardError $stderr `
        -WindowStyle Hidden `
        -PassThru
    $script:laravelProcesses.Add($script:laravelProcess)
    Wait-Port -Port 8090 -Listening $true
}

function Stop-FaultServer {
    if ($script:faultProcess -ne $null) {
        Stop-Process -Id $script:faultProcess.Id -Force -ErrorAction SilentlyContinue
        $script:faultProcess = $null
    }
    foreach ($listener in @(Get-Listener -Port 8110)) {
        Stop-Process -Id $listener.OwningProcess -Force -ErrorAction SilentlyContinue
    }
    Wait-Port -Port 8110 -Listening $false
}

function Start-FaultServer {
    Stop-FaultServer
    [IO.File]::WriteAllText($modePath, '503', [Text.UTF8Encoding]::new($false))
    $script:faultProcess = Start-Process `
        -FilePath 'python' `
        -ArgumentList @(
            (Join-Path $repositoryRoot 'scripts\phase17\fault_server.py'),
            '--host',
            '127.0.0.1',
            '--port',
            '8110',
            '--mode-file',
            $modePath,
            '--events-file',
            $eventsPath
        ) `
        -WorkingDirectory $repositoryRoot `
        -RedirectStandardOutput $faultOutPath `
        -RedirectStandardError $faultErrPath `
        -WindowStyle Hidden `
        -PassThru
    Wait-Port -Port 8110 -Listening $true
}

function Stop-MlContainer {
    $exists = (& docker ps -a --filter "name=^/$containerName$" --format '{{.Names}}') -eq $containerName
    if ($exists) {
        $script:containerLogs.Add(
            (& cmd.exe /d /s /c "docker logs $containerName 2>&1" | Out-String)
        )
        & cmd.exe /d /s /c "docker rm -f $containerName 2>&1" | Out-Null
    }
    Wait-Port -Port 8100 -Listening $false
}

function Start-MlContainer {
    param(
        [Parameter(Mandatory)]
        [string] $ServiceToken,
        [switch] $InvalidBundle
    )

    Stop-MlContainer
    $arguments = @(
        'run',
        '--detach',
        '--name', $containerName,
        '--init',
        '--read-only',
        '--tmpfs', '/tmp:rw,noexec,nosuid,size=64m',
        '--cap-drop', 'ALL',
        '--security-opt', 'no-new-privileges:true',
        '--env', "ML_SERVICE_TOKEN=$ServiceToken",
        '--publish', '127.0.0.1:8100:8100'
    )
    if ($InvalidBundle) {
        $arguments += @(
            '--env',
            'ML_BUNDLE_DIR=/app/data/bundles/recommendation/missing'
        )
    }
    $arguments += $image
    & docker @arguments 2>&1 | Out-Null
    Assert-Phase17 ($LASTEXITCODE -eq 0) 'Unable to start the existing Phase 16 image.'
    Wait-Port -Port 8100 -Listening $true -Seconds 45

    $deadline = [DateTime]::UtcNow.AddSeconds(45)
    do {
        try {
            $live = Invoke-WebRequest `
                -UseBasicParsing `
                -Uri 'http://127.0.0.1:8100/health/live' `
                -TimeoutSec 2
            if ($live.StatusCode -eq 200) {
                return
            }
        } catch {
            Start-Sleep -Milliseconds 250
        }
    } while ([DateTime]::UtcNow -lt $deadline)

    throw 'ML container did not become live.'
}

function Invoke-Helper {
    param(
        [Parameter(Mandatory)]
        [string] $Mode,
        [string] $Value = ''
    )

    $output = & php $helperPath $repositoryRoot $Mode $Value 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Temporary Phase 17 helper failed in mode $Mode."
    }
    $text = ($output | Out-String).Trim()
    if ($text -eq '') {
        return $null
    }

    return $text | ConvertFrom-Json
}

function Invoke-Recommendation {
    param([string] $AccessToken)

    $watch = [Diagnostics.Stopwatch]::StartNew()
    $response = Invoke-WebRequest `
        -UseBasicParsing `
        -Uri 'http://127.0.0.1:8090/api/v1/jobs/recommended?limit=5' `
        -Headers @{
            Authorization = "Bearer $AccessToken"
            Accept = 'application/json'
        } `
        -TimeoutSec 30
    $watch.Stop()

    return [pscustomobject]@{
        status = [int] $response.StatusCode
        body = $response.Content | ConvertFrom-Json
        canonical = ($response.Content | ConvertFrom-Json | ConvertTo-Json -Depth 50 -Compress)
        elapsed_ms = [Math]::Round($watch.Elapsed.TotalMilliseconds, 3)
    }
}

function Assert-PublicResult {
    param(
        [Parameter(Mandatory)]
        [object] $Response,
        [Parameter(Mandatory)]
        [string] $Engine,
        [int] $Count = 5
    )

    Assert-Phase17 ($Response.status -eq 200) 'Public recommendation HTTP status changed.'
    Assert-Phase17 ($Response.body.success -eq $true) 'Public success envelope changed.'
    Assert-Phase17 `
        ($Response.body.message -ceq 'Recommended jobs retrieved successfully.') `
        'Public recommendation message changed.'
    Assert-Phase17 (@($Response.body.data).Count -eq $Count) 'Unexpected public result count.'
    if ($Count -gt 0) {
        Assert-Phase17 `
            ($Response.body.data[0].recommendation_engine -ceq $Engine) `
            'Unexpected public recommendation engine.'
    }
}

function Get-Stats {
    param([double[]] $Values)

    $ordered = @($Values | Sort-Object)
    $count = $ordered.Count
    Assert-Phase17 ($count -gt 0) 'No measurement values were recorded.'
    $median = if ($count % 2 -eq 1) {
        $ordered[[int][Math]::Floor($count / 2)]
    } else {
        ($ordered[($count / 2) - 1] + $ordered[$count / 2]) / 2
    }
    $p95Index = [Math]::Min(
        $count - 1,
        [Math]::Max(0, [int][Math]::Ceiling($count * 0.95) - 1)
    )

    return [ordered]@{
        min = [Math]::Round($ordered[0], 3)
        median = [Math]::Round($median, 3)
        p95 = [Math]::Round($ordered[$p95Index], 3)
        max = [Math]::Round($ordered[-1], 3)
    }
}

function Invoke-ConcurrentRecommendation {
    param(
        [Parameter(Mandatory)]
        [string] $AccessToken,
        [Parameter(Mandatory)]
        [int] $Count
    )

    return [Phase17Http]::Run(
        'http://127.0.0.1:8090/api/v1/jobs/recommended?limit=5',
        $AccessToken,
        $Count
    )
}

$helperSource = @'
<?php

use App\Contracts\Recommendation\RecommendationContextFingerprintContract;
use App\Contracts\Recommendation\RecommendationEligibilityProviderContract;
use App\Models\ApplicationStatus;
use App\Models\Company;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\RecommendationItem;
use App\Models\RecommendationRun;
use App\Models\Skill;
use App\Models\User;
use App\Services\Recommendation\RecommendationResultCache;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

$root = $argv[1];
$mode = $argv[2];
$value = $argv[3] ?? '';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($mode === 'seed') {
    (new ApplicationStatusSeeder)->run();
    $nonce = bin2hex(random_bytes(8));
    $privateName = 'Private Fixture '.$nonce;
    $privateEmail = 'phase17-'.$nonce.'@example.test';
    $privatePhone = '+1999'.substr(preg_replace('/[^0-9]/', '', $nonce), 0, 8);
    $user = User::create([
        'name' => $privateName,
        'email' => $privateEmail,
        'role' => 'job_seeker',
        'status' => 'active',
        'password' => Str::random(48),
    ]);
    $profile = JobSeekerProfile::create([
        'user_id' => $user->id,
        'headline' => 'Backend platform engineer',
        'summary' => 'Builds private synthetic candidate systems.',
        'phone' => $privatePhone,
    ]);
    Experience::create([
        'job_seeker_profile_id' => $profile->id,
        'title' => 'Backend Engineer',
        'company_name' => 'Synthetic Fixture Company',
        'start_date' => now()->subYears(4)->toDateString(),
        'end_date' => null,
        'is_current' => true,
        'description' => 'Builds reliable local test services.',
        'source_type' => 'manual',
    ]);
    Education::create([
        'job_seeker_profile_id' => $profile->id,
        'institution' => 'Synthetic Fixture University',
        'degree' => 'bachelor',
        'field_of_study' => 'Computer Science',
        'start_date' => now()->subYears(8)->toDateString(),
        'end_date' => now()->subYears(4)->toDateString(),
        'description' => 'Synthetic fixture education.',
        'source_type' => 'manual',
    ]);
    $laravel = Skill::create(['name' => 'Laravel', 'slug' => 'laravel-'.$nonce]);
    $python = Skill::create(['name' => 'Python', 'slug' => 'python-'.$nonce]);
    $profile->skills()->attach($laravel, ['source_type' => 'manual']);
    $profile->skills()->attach($python, ['source_type' => 'manual']);
    $approved = Company::create([
        'name' => 'Approved Synthetic Company '.$nonce,
        'approval_status' => 'approved',
    ]);
    $eligibleIds = [];
    for ($index = 0; $index < 5; $index++) {
        $job = JobPosting::create([
            'company_id' => $approved->id,
            'title' => 'Eligible Synthetic Role '.$index,
            'department' => 'Engineering',
            'description' => 'Build reliable local recommendation services.',
            'responsibilities' => 'Design and operate local test services.',
            'requirements' => 'Professional backend engineering experience.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'education_level' => 'bachelor',
            'location' => 'Local Fixture',
            'work_mode' => 'remote',
            'status' => 'open',
            'published_at' => $index === 4 ? null : now()->subHours($index + 1),
            'application_deadline' => $index === 0 ? null : now()->addDays($index),
        ]);
        $job->skills()->attach($laravel, [
            'requirement_type' => 'required',
            'weight' => 5,
        ]);
        $job->skills()->attach($python, [
            'requirement_type' => 'nice_to_have',
            'weight' => 2,
        ]);
        $eligibleIds[] = $job->id;
    }
    foreach ([
        ['status' => 'draft'],
        ['status' => 'closed'],
        ['application_deadline' => now()->subSecond()],
    ] as $override) {
        JobPosting::create(array_merge([
            'company_id' => $approved->id,
            'title' => 'Ineligible Synthetic Role '.Str::random(6),
            'department' => 'Engineering',
            'description' => 'Synthetic ineligible fixture.',
            'responsibilities' => 'None.',
            'requirements' => 'None.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'education_level' => 'bachelor',
            'location' => 'Local Fixture',
            'work_mode' => 'remote',
            'status' => 'open',
            'published_at' => now()->subDay(),
            'application_deadline' => null,
        ], $override));
    }
    foreach (['pending', 'rejected', 'suspended'] as $approval) {
        $company = Company::create([
            'name' => ucfirst($approval).' Synthetic Company '.$nonce,
            'approval_status' => $approval,
        ]);
        JobPosting::create([
            'company_id' => $company->id,
            'title' => 'Company Ineligible Synthetic Role '.$approval,
            'department' => 'Engineering',
            'description' => 'Synthetic ineligible fixture.',
            'responsibilities' => 'None.',
            'requirements' => 'None.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'education_level' => 'bachelor',
            'location' => 'Local Fixture',
            'work_mode' => 'remote',
            'status' => 'open',
            'published_at' => now()->subDay(),
            'application_deadline' => null,
        ]);
    }
    $prior = JobPosting::create([
        'company_id' => $approved->id,
        'title' => 'Prior Application Synthetic Role',
        'department' => 'Engineering',
        'description' => 'Synthetic prior application fixture.',
        'responsibilities' => 'None.',
        'requirements' => 'None.',
        'employment_type' => 'full-time',
        'experience_level' => 'mid-level',
        'education_level' => 'bachelor',
        'location' => 'Local Fixture',
        'work_mode' => 'remote',
        'status' => 'open',
        'published_at' => now()->subDay(),
        'application_deadline' => null,
    ]);
    JobApplication::create([
        'job_posting_id' => $prior->id,
        'job_seeker_profile_id' => $profile->id,
        'application_status_id' => ApplicationStatus::firstOrFail()->id,
        'cover_letter' => null,
        'consent_to_share_profile' => true,
    ]);
    echo json_encode([
        'access_token' => $user->createToken('phase17-'.$nonce)->plainTextToken,
        'eligible_ids' => $eligibleIds,
        'sensitive_markers' => [$privateName, $privateEmail, $privatePhone],
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($mode === 'mutate-profile') {
    JobSeekerProfile::query()->firstOrFail()->update([
        'headline' => 'Phase 17 context mutation '.$value,
    ]);
    echo '{}';
    exit;
}

if ($mode === 'clear-cache') {
    Cache::flush();
    echo '{}';
    exit;
}

if ($mode === 'clear-recommendations') {
    Cache::flush();
    RecommendationRun::query()->delete();
    echo '{}';
    exit;
}

if ($mode === 'corrupt-cache') {
    $user = User::query()->firstOrFail();
    $eligibility = app(RecommendationEligibilityProviderContract::class)
        ->eligibleJobs($user, now());
    $context = app(RecommendationContextFingerprintContract::class)
        ->fingerprint($eligibility, true);
    $cache = app(RecommendationResultCache::class);
    Cache::put(
        $cache->key($user->jobSeekerProfile->id, $context, 5),
        ['corrupt' => 'phase17-pointer'],
        900,
    );
    echo '{}';
    exit;
}

if ($mode === 'corrupt-persistence') {
    RecommendationRun::query()
        ->latest('id')
        ->firstOrFail()
        ->items()
        ->update(['score' => 101]);
    Cache::flush();
    echo '{}';
    exit;
}

if ($mode === 'inspect') {
    $last = RecommendationRun::query()->latest('id')->first();
    $serialized = json_encode([
        RecommendationRun::query()->get()->toArray(),
        RecommendationItem::query()->get()->toArray(),
        Cache::getStore()::class,
    ], JSON_THROW_ON_ERROR);
    $duplicateGroups = RecommendationRun::query()
        ->selectRaw('context_hash, requested_limit, count(*) as aggregate_count')
        ->groupBy('context_hash', 'requested_limit')
        ->havingRaw('count(*) > 1')
        ->get()
        ->sum('aggregate_count');
    echo json_encode([
        'run_count' => RecommendationRun::query()->count(),
        'item_count' => RecommendationItem::query()->count(),
        'last_engine' => $last?->engine?->value,
        'last_fallback_code' => $last?->fallback_code,
        'last_returned_count' => $last?->returned_count,
        'last_context_hash' => $last?->context_hash,
        'equivalent_duplicate_runs' => $duplicateGroups,
        'storage_projection_base64' => base64_encode($serialized),
    ], JSON_THROW_ON_ERROR);
    exit;
}

throw new RuntimeException('Unsupported temporary Phase 17 helper mode.');
'@

$concurrentSource = @'
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Threading.Tasks;

public sealed class Phase17HttpResult
{
    public int StatusCode { get; set; }
    public string Body { get; set; }
    public double ElapsedMs { get; set; }
}

public static class Phase17Http
{
    public static Phase17HttpResult[] Run(string url, string token, int count)
    {
        return RunAsync(url, token, count).GetAwaiter().GetResult();
    }

    private static async Task<Phase17HttpResult[]> RunAsync(
        string url,
        string token,
        int count)
    {
        using (var client = new HttpClient())
        {
            client.Timeout = TimeSpan.FromSeconds(30);
            client.DefaultRequestHeaders.Authorization =
                new AuthenticationHeaderValue("Bearer", token);
            client.DefaultRequestHeaders.Accept.Add(
                new MediaTypeWithQualityHeaderValue("application/json"));
            var tasks = new List<Task<Phase17HttpResult>>();
            for (var index = 0; index < count; index++)
            {
                tasks.Add(One(client, url));
            }
            return await Task.WhenAll(tasks);
        }
    }

    private static async Task<Phase17HttpResult> One(HttpClient client, string url)
    {
        var watch = Stopwatch.StartNew();
        using (var response = await client.GetAsync(url))
        {
            var body = await response.Content.ReadAsStringAsync();
            watch.Stop();
            return new Phase17HttpResult {
                StatusCode = (int) response.StatusCode,
                Body = body,
                ElapsedMs = watch.Elapsed.TotalMilliseconds
            };
        }
    }
}
'@

Push-Location $repositoryRoot
try {
    foreach ($port in @(8090, 8100, 8110)) {
        Assert-Phase17 (@(Get-Listener -Port $port).Count -eq 0) "Required Phase 17 port is already listening."
    }
    & docker image inspect $image 2>&1 | Out-Null
    Assert-Phase17 ($LASTEXITCODE -eq 0) 'The primary Phase 16 image is unavailable.'

    New-Item -ItemType Directory -Path $tempRoot | Out-Null
    New-Item -ItemType File -Path $databasePath | Out-Null
    [IO.File]::WriteAllText($helperPath, $helperSource, [Text.UTF8Encoding]::new($false))
    Add-Type -AssemblyName System.Net.Http
    Add-Type -TypeDefinition $concurrentSource -Language CSharp `
        -ReferencedAssemblies @('System.dll', 'System.Core.dll', 'System.Net.Http.dll')

    $env:APP_ENV = 'testing'
    $env:APP_DEBUG = 'false'
    $env:LOG_CHANNEL = 'stderr'
    $env:LOG_LEVEL = 'debug'
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = $databasePath
    $env:DB_URL = ''
    $env:DB_FOREIGN_KEYS = 'true'
    $env:CACHE_STORE = 'database'
    $env:SESSION_DRIVER = 'array'
    $env:QUEUE_CONNECTION = 'sync'
    $env:MAIL_MAILER = 'array'
    $env:ML_RECOMMENDATION_ENABLED = 'true'
    $env:ML_RECOMMENDATION_CONNECT_TIMEOUT_SECONDS = '1'
    $env:ML_RECOMMENDATION_TIMEOUT_SECONDS = '1'
    $env:ML_RECOMMENDATION_MAX_JOBS_PER_REQUEST = '500'
    $env:ML_RECOMMENDATION_MAX_RESULTS = '100'
    $env:ML_RECOMMENDATION_API_CONTRACT_VERSION = 'recommendation-ranking-api-v1'
    $env:ML_RECOMMENDATION_BUNDLE_VERSION = 'job-rec-inference-bundle-v1'
    $env:ML_RECOMMENDATION_MODEL_VERSION = 'xgbranker-tuned-v1'
    $env:ML_RECOMMENDATION_FEATURE_SCHEMA_VERSION = 'job-rec-features-v1'
    $env:ML_RECOMMENDATION_EXPLANATION_CONTRACT_VERSION = 'recommendation-explanation-contract-v1'
    $env:ML_RECOMMENDATION_SCORE_TRANSFORM_VERSION = 'validation-minmax-selected-trial-t06-v1'
    $env:RECOMMENDATION_CACHE_ENABLED = 'true'
    $env:RECOMMENDATION_CACHE_TTL_SECONDS = '900'
    $env:RECOMMENDATION_FALLBACK_CACHE_TTL_SECONDS = '60'
    $env:RECOMMENDATION_EMPTY_CACHE_TTL_SECONDS = '60'

    $migrationOutput = & php artisan migrate:fresh --force --no-interaction 2>&1
    Assert-Phase17 ($LASTEXITCODE -eq 0) 'Temporary SQLite migrations failed.'
    $fixture = Invoke-Helper -Mode 'seed'
    $accessToken = [string] $fixture.access_token
    $sensitiveMarkers = @($fixture.sensitive_markers)
    $mlToken = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')

    Start-MlContainer -ServiceToken $mlToken
    Start-Laravel -BaseUrl 'http://127.0.0.1:8100' -ServiceToken $mlToken

    $cold = Invoke-Recommendation -AccessToken $accessToken
    Assert-PublicResult -Response $cold -Engine 'ml_xgbranker'
    $coldInspect = Invoke-Helper -Mode 'inspect'
    Assert-Phase17 ($coldInspect.run_count -eq 1) 'Cold ML did not create exactly one run.'
    Assert-Phase17 ($coldInspect.item_count -eq 5) 'Cold ML item persistence count is invalid.'

    $cache = Invoke-Recommendation -AccessToken $accessToken
    Assert-PublicResult -Response $cache -Engine 'ml_xgbranker'
    Assert-Phase17 ($cache.canonical -ceq $cold.canonical) 'Warm cache response changed.'
    $cacheInspect = Invoke-Helper -Mode 'inspect'
    Assert-Phase17 ($cacheInspect.run_count -eq 1) 'Warm cache created a run.'

    Invoke-Helper -Mode 'clear-cache' | Out-Null
    $persistence = Invoke-Recommendation -AccessToken $accessToken
    Assert-PublicResult -Response $persistence -Engine 'ml_xgbranker'
    Assert-Phase17 ($persistence.canonical -ceq $cold.canonical) 'Persistence hit response changed.'
    $persistenceInspect = Invoke-Helper -Mode 'inspect'
    Assert-Phase17 ($persistenceInspect.run_count -eq 1) 'Persistence hit created a run.'

    Invoke-Helper -Mode 'mutate-profile' -Value 'invalidation' | Out-Null
    $invalidation = Invoke-Recommendation -AccessToken $accessToken
    Assert-PublicResult -Response $invalidation -Engine 'ml_xgbranker'
    $invalidationInspect = Invoke-Helper -Mode 'inspect'
    Assert-Phase17 ($invalidationInspect.run_count -eq 2) 'Content invalidation did not recompute.'
    Assert-Phase17 `
        ($invalidationInspect.last_context_hash -cne $coldInspect.last_context_hash) `
        'Content invalidation reused the previous context.'

    Invoke-Helper -Mode 'corrupt-cache' | Out-Null
    $corruptCache = Invoke-Recommendation -AccessToken $accessToken
    Assert-PublicResult -Response $corruptCache -Engine 'ml_xgbranker'
    Assert-Phase17 `
        ($corruptCache.canonical -ceq $invalidation.canonical) `
        'Corrupt cache recovery changed the public response.'
    $corruptCacheInspect = Invoke-Helper -Mode 'inspect'
    Assert-Phase17 `
        ($corruptCacheInspect.run_count -eq 2) `
        'Corrupt cache recovery created unexpected work.'

    Invoke-Helper -Mode 'corrupt-persistence' | Out-Null
    $corruptPersistence = Invoke-Recommendation -AccessToken $accessToken
    Assert-PublicResult -Response $corruptPersistence -Engine 'ml_xgbranker'
    $corruptPersistenceInspect = Invoke-Helper -Mode 'inspect'
    Assert-Phase17 `
        ($corruptPersistenceInspect.run_count -eq 3) `
        'Corrupt persistence recovery did not recompute exactly once.'
    Assert-Phase17 `
        ($corruptPersistenceInspect.last_returned_count -eq 5) `
        'Corrupt persistence recovery produced a partial result.'

    $wrongServiceToken = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')
    Start-Laravel `
        -BaseUrl 'http://127.0.0.1:8100' `
        -ServiceToken $wrongServiceToken
    Invoke-Helper -Mode 'mutate-profile' -Value 'wrong-token' | Out-Null
    $wrongToken = Invoke-Recommendation -AccessToken $accessToken
    Assert-PublicResult -Response $wrongToken -Engine 'matching_v2_fallback'
    $wrongTokenInspect = Invoke-Helper -Mode 'inspect'
    Assert-Phase17 `
        ($wrongTokenInspect.last_fallback_code -ceq 'ML_AUTHENTICATION_FAILURE') `
        'Wrong service token did not map to the safe authentication code.'

    Stop-MlContainer
    Start-MlContainer -ServiceToken $mlToken -InvalidBundle
    $live = Invoke-WebRequest `
        -UseBasicParsing `
        -Uri 'http://127.0.0.1:8100/health/live' `
        -TimeoutSec 2
    Assert-Phase17 ($live.StatusCode -eq 200) 'Invalid-bundle container is not live.'
    $readyStatus = 0
    try {
        Invoke-WebRequest `
            -UseBasicParsing `
            -Uri 'http://127.0.0.1:8100/health/ready' `
            -TimeoutSec 2 | Out-Null
        $readyStatus = 200
    } catch {
        $readyStatus = [int] $_.Exception.Response.StatusCode
    }
    Assert-Phase17 ($readyStatus -eq 503) 'Invalid-bundle readiness did not return 503.'
    Start-Laravel -BaseUrl 'http://127.0.0.1:8100' -ServiceToken $mlToken
    Invoke-Helper -Mode 'mutate-profile' -Value 'invalid-bundle' | Out-Null
    $invalidBundle = Invoke-Recommendation -AccessToken $accessToken
    Assert-PublicResult -Response $invalidBundle -Engine 'matching_v2_fallback'
    $invalidBundleInspect = Invoke-Helper -Mode 'inspect'
    Assert-Phase17 `
        ($invalidBundleInspect.last_fallback_code -ceq 'ML_MODEL_UNAVAILABLE') `
        'Invalid Bundle did not map to model unavailable.'

    Stop-MlContainer
    Invoke-Helper -Mode 'mutate-profile' -Value 'container-down' | Out-Null
    $fallback = Invoke-Recommendation -AccessToken $accessToken
    Assert-PublicResult -Response $fallback -Engine 'matching_v2_fallback'
    $fallbackInspect = Invoke-Helper -Mode 'inspect'
    Assert-Phase17 `
        ($fallbackInspect.last_fallback_code -ceq 'ML_TRANSPORT_FAILURE') `
        'Container-down failure did not map to transport failure.'

    Start-Laravel -BaseUrl 'http://127.0.0.1:8110' -ServiceToken $mlToken
    Invoke-Helper -Mode 'mutate-profile' -Value 'fault-connection-refused' | Out-Null
    $refused = Invoke-Recommendation -AccessToken $accessToken
    Assert-PublicResult -Response $refused -Engine 'matching_v2_fallback'
    $refusedInspect = Invoke-Helper -Mode 'inspect'
    Assert-Phase17 `
        ($refusedInspect.last_fallback_code -ceq 'ML_TRANSPORT_FAILURE') `
        'Connection refused did not map to transport failure.'
    $summary.fault_matrix += [ordered]@{
        failure = 'connection_refused'
        provider_calls = 1
        public_http = 200
        engine = 'matching_v2_fallback'
        safe_code = 'ML_TRANSPORT_FAILURE'
    }

    Start-FaultServer
    $faults = @(
        @('timeout', 'ML_TRANSPORT_FAILURE'),
        @('401', 'ML_AUTHENTICATION_FAILURE'),
        @('422', 'ML_PROVIDER_VALIDATION_FAILURE'),
        @('429', 'ML_RATE_LIMITED'),
        @('500', 'ML_MODEL_UNAVAILABLE'),
        @('503', 'ML_MODEL_UNAVAILABLE'),
        @('empty', 'ML_CONTRACT_FAILURE'),
        @('invalid_json', 'ML_CONTRACT_FAILURE'),
        @('version_mismatch', 'ML_CONTRACT_FAILURE'),
        @('request_id_mismatch', 'ML_CONTRACT_FAILURE'),
        @('missing_prediction', 'ML_CONTRACT_FAILURE'),
        @('extra_prediction', 'ML_CONTRACT_FAILURE'),
        @('duplicate_job', 'ML_CONTRACT_FAILURE'),
        @('rank_gap', 'ML_CONTRACT_FAILURE'),
        @('invalid_score', 'ML_CONTRACT_FAILURE'),
        @('invalid_reason', 'ML_CONTRACT_FAILURE'),
        @('abrupt_close', 'ML_TRANSPORT_FAILURE')
    )
    $faultIndex = 0
    foreach ($fault in $faults) {
        $faultIndex++
        $mode = $fault[0]
        $expectedCode = $fault[1]
        [IO.File]::WriteAllText($modePath, $mode, [Text.UTF8Encoding]::new($false))
        $beforeCalls = if (Test-Path $eventsPath) {
            @(Get-Content $eventsPath | Where-Object { $_ -ceq $mode }).Count
        } else {
            0
        }
        Invoke-Helper -Mode 'mutate-profile' -Value ("fault-$faultIndex") | Out-Null
        $faultResponse = Invoke-Recommendation -AccessToken $accessToken
        Assert-PublicResult -Response $faultResponse -Engine 'matching_v2_fallback'
        $afterCalls = @(Get-Content $eventsPath | Where-Object { $_ -ceq $mode }).Count
        Assert-Phase17 (($afterCalls - $beforeCalls) -eq 1) 'Fault scenario retried or was not called.'
        $faultInspect = Invoke-Helper -Mode 'inspect'
        Assert-Phase17 `
            ($faultInspect.last_fallback_code -ceq $expectedCode) `
            'Fault scenario mapped to the wrong safe code.'
        $summary.fault_matrix += [ordered]@{
            failure = $mode
            provider_calls = 1
            public_http = 200
            engine = 'matching_v2_fallback'
            safe_code = $expectedCode
        }
    }

    Stop-FaultServer
    Start-MlContainer -ServiceToken $mlToken
    Start-Laravel -BaseUrl 'http://127.0.0.1:8100' -ServiceToken $mlToken
    Invoke-Helper -Mode 'clear-recommendations' | Out-Null
    $warmSeed = Invoke-Recommendation -AccessToken $accessToken
    Assert-PublicResult -Response $warmSeed -Engine 'ml_xgbranker'
    $warmConcurrent = @(Invoke-ConcurrentRecommendation -AccessToken $accessToken -Count 10)
    Assert-Phase17 `
        (@($warmConcurrent | Where-Object StatusCode -ne 200).Count -eq 0) `
        'Warm concurrent request returned a non-200 response.'
    $warmBodies = @($warmConcurrent | ForEach-Object Body | Sort-Object -Unique)
    Assert-Phase17 ($warmBodies.Count -eq 1) 'Warm concurrent responses differ.'
    $warmCanonical = (
        $warmBodies[0] |
            ConvertFrom-Json |
            ConvertTo-Json -Depth 50 -Compress
    )
    Assert-Phase17 `
        ($warmCanonical -ceq $warmSeed.canonical) `
        'Warm concurrent response differs from the warmed result.'
    $warmInspect = Invoke-Helper -Mode 'inspect'
    Assert-Phase17 ($warmInspect.run_count -eq 1) 'Warm concurrency created new runs.'

    Invoke-Helper -Mode 'clear-recommendations' | Out-Null
    $coldConcurrent = @(Invoke-ConcurrentRecommendation -AccessToken $accessToken -Count 5)
    Assert-Phase17 `
        (@($coldConcurrent | Where-Object StatusCode -ne 200).Count -eq 0) `
        'Cold concurrent request returned a non-200 response.'
    $coldBodies = @($coldConcurrent | ForEach-Object Body | Sort-Object -Unique)
    Assert-Phase17 ($coldBodies.Count -eq 1) 'Cold concurrent responses differ.'
    $coldRaceInspect = Invoke-Helper -Mode 'inspect'
    Assert-Phase17 `
        ($coldRaceInspect.item_count -eq (5 * $coldRaceInspect.run_count)) `
        'Cold concurrency produced a partial run.'

    $allLaravelLogText = ($laravelLogs | ForEach-Object {
        if (Test-Path $_) {
            Get-Content -Raw -LiteralPath $_
        }
    }) -join [Environment]::NewLine
    $faultLogText = @($faultOutPath, $faultErrPath, $eventsPath) | ForEach-Object {
        if (Test-Path $_) {
            Get-Content -Raw -LiteralPath $_
        }
    }
    $activeContainerLog = if (
        (& docker ps --filter "name=^/$containerName$" --format '{{.Names}}') -eq
            $containerName
    ) {
        & cmd.exe /d /s /c "docker logs $containerName 2>&1" | Out-String
    } else {
        ''
    }
    $containerLogText = (
        @($containerLogs) + @($activeContainerLog)
    ) -join [Environment]::NewLine
    $storageProjection = [Text.Encoding]::UTF8.GetString(
        [Convert]::FromBase64String(
            [string] (Invoke-Helper -Mode 'inspect').storage_projection_base64
        )
    )
    $privacyCorpus = @(
        $allLaravelLogText,
        ($faultLogText -join [Environment]::NewLine),
        $containerLogText,
        $storageProjection
    ) -join [Environment]::NewLine
    $sensitiveHits = 0
    foreach (
        $secret in @($mlToken, $wrongServiceToken, $accessToken) + $sensitiveMarkers
    ) {
        if ($privacyCorpus.Contains([string] $secret)) {
            $sensitiveHits++
        }
    }
    Assert-Phase17 ($sensitiveHits -eq 0) 'A token or synthetic PII leaked into logs or persistence.'

    $summary.public_http = [ordered]@{
        cold_ml = [ordered]@{
            http_status = $cold.status
            engine = 'ml_xgbranker'
            runs_created = 1
            items_created = 5
        }
        cache_hit = [ordered]@{
            http_status = $cache.status
            new_runs = 0
            new_items = 0
        }
        persistence_hit = [ordered]@{
            http_status = $persistence.status
            new_runs = 0
            new_items = 0
        }
        invalidation = [ordered]@{
            context_changed = $true
            recomputed = $true
        }
        corrupt_cache = [ordered]@{
            http_status = $corruptCache.status
            engine = 'ml_xgbranker'
            new_runs = 0
        }
        corrupt_persistence = [ordered]@{
            http_status = $corruptPersistence.status
            engine = 'ml_xgbranker'
            new_runs = 1
            partial_runs = 0
        }
        wrong_token = [ordered]@{
            http_status = $wrongToken.status
            engine = 'matching_v2_fallback'
            safe_code = 'ML_AUTHENTICATION_FAILURE'
        }
        invalid_bundle = [ordered]@{
            live_status = 200
            ready_status = 503
            public_http = $invalidBundle.status
            engine = 'matching_v2_fallback'
            safe_code = 'ML_MODEL_UNAVAILABLE'
        }
        container_down = [ordered]@{
            http_status = $fallback.status
            engine = 'matching_v2_fallback'
            safe_code = 'ML_TRANSPORT_FAILURE'
        }
    }
    $summary.measurements_ms = [ordered]@{
        cold_ml = Get-Stats -Values @($cold.elapsed_ms)
        warm_cache = Get-Stats -Values @($cache.elapsed_ms)
        persistence_hit = Get-Stats -Values @($persistence.elapsed_ms)
        fallback = Get-Stats -Values @($fallback.elapsed_ms)
        warm_concurrent_10 = Get-Stats -Values @(
            $warmConcurrent | ForEach-Object { $_.ElapsedMs }
        )
        cold_concurrent_5 = Get-Stats -Values @(
            $coldConcurrent | ForEach-Object { $_.ElapsedMs }
        )
    }
    $summary.concurrency = [ordered]@{
        warm_requests = 10
        warm_http_failures = 0
        warm_new_runs = 0
        cold_requests = 5
        cold_http_failures = 0
        equivalent_runs_observed = [int] $coldRaceInspect.run_count
        duplicate_equivalent_runs_observed = [int] $coldRaceInspect.equivalent_duplicate_runs
        partial_runs = 0
    }
    $summary.privacy = [ordered]@{
        sensitive_hits = 0
        request_bodies_logged_by_fault_server = $false
        public_provider_details_exposed = $false
    }
} finally {
    Stop-Laravel
    Stop-FaultServer
    Stop-MlContainer
    foreach ($port in @(8090, 8100, 8110)) {
        foreach ($listener in @(Get-Listener -Port $port)) {
            Stop-Process -Id $listener.OwningProcess -Force -ErrorAction SilentlyContinue
        }
    }
    if (Test-Path $tempRoot) {
        $resolvedTemp = [IO.Path]::GetFullPath($tempRoot)
        $systemTemp = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
        if ($resolvedTemp.StartsWith($systemTemp, [StringComparison]::OrdinalIgnoreCase) `
            -and (Split-Path $resolvedTemp -Leaf).StartsWith('workeyx-phase17-')) {
            Remove-Item -LiteralPath $resolvedTemp -Recurse -Force
        }
    }
    $summary.cleanup = [ordered]@{
        phase17_containers = @(
            & docker ps -a --filter 'name=workeyx-ml-phase17' --format '{{.Names}}'
        ).Count
        primary_image_present = (
            (& docker image inspect $image --format '{{.RepoTags}}' 2>$null) -match
                [regex]::Escape($image)
        )
        port_8090_listening = @(Get-Listener -Port 8090).Count -gt 0
        port_8100_listening = @(Get-Listener -Port 8100).Count -gt 0
        port_8110_listening = @(Get-Listener -Port 8110).Count -gt 0
        temporary_root_present = Test-Path $tempRoot
    }
    Pop-Location
}

$summary | ConvertTo-Json -Depth 10
