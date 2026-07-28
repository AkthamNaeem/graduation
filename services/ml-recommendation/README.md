# Smart Recruitment ML Service

This directory contains the internal FastAPI inference service for the Smart
Recruitment Platform's Job Recommendation capability.

Phase 12 adds a versioned self-contained Model Bundle, strict startup loader,
shared offline/online Feature transformation, frozen XGBRanker inference,
Validation-derived display scores, safe feature-group explanations,
shared-secret internal authentication, and deterministic API contract
artifacts. Phase 16 adds a production-oriented, internal-only Docker package
without changing inference behavior. See [DEPLOYMENT.md](DEPLOYMENT.md) and
the versioned container card under `deployment/container/v1`.

## Requirements

- Python `>=3.12,<3.14`. Python 3.12 is the minimum because it is the approved
  ML runtime for the pinned Phase 8 dependency set.
- Use a supported Python interpreter available as `python`.

## Setup with Windows PowerShell

Run these commands from the repository root. Activation is not required.

```powershell
python -m venv services/ml-recommendation/.venv
$python = (Resolve-Path 'services/ml-recommendation/.venv/Scripts/python.exe').Path
& $python -m pip install --upgrade pip
& $python -m pip install -e './services/ml-recommendation[dev,ml]'
```

## Run locally

```powershell
$env:ML_BUNDLE_DIR = (
    Resolve-Path 'services/ml-recommendation/data/bundles/recommendation/v1'
).Path
$env:ML_SERVICE_TOKEN = 'replace-with-at-least-32-random-characters'

& $python -m uvicorn smart_recruitment_ml.main:app `
    --app-dir services/ml-recommendation/src `
    --host 127.0.0.1 `
    --port 8100
```

The service reads non-secret settings with the `ML_` prefix. Copy
`.env.example` values into the process environment when overrides are needed;
do not commit a `.env` file.

## Health contracts

`GET /health/live` returns `200` when the HTTP application is running:

```json
{
  "status": "live",
  "service": "ml-recommendation",
  "service_version": "0.2.0"
}
```

`GET /health/ready` returns `200` after Bundle and token validation:

```json
{
  "status": "ready",
  "service": "ml-recommendation",
  "service_version": "0.2.0",
  "bundle_version": "job-rec-inference-bundle-v1",
  "model_version": "xgbranker-tuned-v1",
  "feature_schema_version": "job-rec-features-v1"
}
```

Missing or corrupt Bundle state returns `503` with
`MODEL_BUNDLE_NOT_READY`; an absent token returns
`SERVICE_TOKEN_NOT_CONFIGURED`. Readiness never exposes paths or secrets.

`POST /v1/recommendations/rank` and `GET /v1/model/metadata` require the
`X-ML-Service-Token` header. Health routes do not.

Set `ML_DOCS_ENABLED=false` to disable `/docs`, `/redoc`, and `/openapi.json`
without disabling the health endpoints.

## Tests and quality checks

```powershell
& $python -m pytest services/ml-recommendation/tests
& $python -m pytest services/ml-recommendation/tests --cov=smart_recruitment_ml --cov-report=term-missing
& $python -m ruff check services/ml-recommendation
& $python -m ruff format --check services/ml-recommendation
& $python -m mypy services/ml-recommendation/src services/ml-recommendation/tests
& $python -m compileall -q services/ml-recommendation/src
```

## Architectural boundaries

- Python has no access to the Laravel database and has no database
  configuration or drivers.
- Runtime inference uses pinned NumPy 2.5.1, SciPy 1.18.0, and XGBoost 3.3.0.
  Pandas, scikit-learn, Optuna, SHAP, Joblib, MLflow, database clients, Redis
  clients, and GPU packages are not dependencies.
- Laravel integration is deferred to Phases 13–14.
- Containerization and Docker deployment are deferred to Phase 16.
- The API is internal-only and assumes eligible Jobs are supplied by Laravel.

## Phase 4 deterministic synthetic Dataset

Phase 4 adds a fully synthetic, deterministic Dataset generator for future
Job Seeker → Job Recommendation Learning-to-Rank experiments. It does not use
production users, CVs, applications, or external data.

Generate the locked v1 Dataset from the repository root:

```powershell
& $python -m smart_recruitment_ml.data `
    --output-dir services/ml-recommendation/data/synthetic/v1 `
    --seed 20260724 `
    --candidate-count 180 `
    --job-count 180 `
    --pairs-per-candidate 60 `
    --source-revision 6cd51f733d5197e0c3f6b7dfb3711c2860ffef71 `
    --architecture-sha256 60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F
```

The equivalent installed console command is:

```powershell
& services/ml-recommendation/.venv/Scripts/generate-synthetic-dataset.exe `
    --output-dir services/ml-recommendation/data/synthetic/v1
```

Generated files:

- `candidates.jsonl`: synthetic professional Candidate facts.
- `jobs.jsonl`: synthetic Laravel-pre-filtered eligible Job facts.
- `pairs.jsonl`: Candidate–Job relevance labels and audit rationales.
- `manifest.json`: versions, distributions, constraints, and JSONL hashes.
- `DATASET_CARD.md`: intended use, limitations, privacy, and reproducibility.

Re-running with the same seed, configuration, source revision, and
Architecture hash produces byte-for-byte identical files. Verify any file
with `Get-FileHash -Algorithm SHA256`; authoritative JSONL hashes are recorded
in `manifest.json`.

The Dataset is synthetic and its labels are not hiring outcomes, acceptance
probabilities, or production-quality evidence. Phase 4 still has no Feature
Pipeline, train/validation/test split, Model, or inference implementation.
`/health/ready` therefore remains `503`, and the inference and model metadata
endpoints remain unimplemented.

## Phase 5 shared versioned Feature Pipeline

Phase 5 adds `FeaturePipelineV1`, a deterministic transformation of Candidate
and Job professional facts into a fixed, versioned feature vector. The same
stateless `transform()` contract is intended for later training, validation,
locked testing, and FastAPI inference, preventing training-serving skew.

Build the locked feature Dataset from the repository root:

```powershell
& $python -m smart_recruitment_ml.features `
    --input-dir services/ml-recommendation/data/synthetic/v1 `
    --output-dir services/ml-recommendation/data/features/v1 `
    --feature-schema-version job-rec-features-v1 `
    --pipeline-version 0.1.0 `
    --source-revision 6cd51f733d5197e0c3f6b7dfb3711c2860ffef71 `
    --architecture-sha256 60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F
```

The equivalent installed command is:

```powershell
& services/ml-recommendation/.venv/Scripts/build-feature-dataset.exe `
    --input-dir services/ml-recommendation/data/synthetic/v1 `
    --output-dir services/ml-recommendation/data/features/v1
```

Generated files in `data/features/v1`:

- `feature_schema.json`: versions, exact feature order, vocabularies, bounds,
  normalization, missing-value semantics, and exclusions.
- `features.jsonl`: 10,800 ordered vectors with identifiers and relevance
  targets kept outside the vector.
- `manifest.json`: source/output hashes, counts, configuration, intended use,
  and limitations.
- `FEATURE_SCHEMA_CARD.md`: human-readable contract and reproducibility notes.

The schema uses fixed vocabularies and an explicit `__unknown__` bucket.
Important missing professional facts use neutral values plus separate missing
indicators. Input identity, labels, scenario, rationale, controlled-noise,
hidden generator factors, sensitive fields, and raw text are excluded from the
vector. Source hashes are verified before an atomic write, and repeated builds
are byte-for-byte identical.

Phase 5 creates no train/validation/test split and no Model. Readiness remains
`503`; ranking and model-metadata inference endpoints remain unimplemented.

## Phase 6 Candidate-grouped Train/Validation/Test split

Phase 6 creates a deterministic, versioned split grouped by `candidate_id`.
Every Candidate and all 60 associated Feature records remain in exactly one
split, preventing Candidate leakage across Train, Validation, and Test.

Build the locked split from the repository root:

```powershell
& $python -m smart_recruitment_ml.splits `
    --features-dir services/ml-recommendation/data/features/v1 `
    --candidates-file services/ml-recommendation/data/synthetic/v1/candidates.jsonl `
    --output-dir services/ml-recommendation/data/splits/v1 `
    --split-version candidate-group-split-v1 `
    --generator-version 0.1.0 `
    --seed 20260724 `
    --train-ratio 0.70 `
    --validation-ratio 0.15 `
    --test-ratio 0.15 `
    --source-revision 6cd51f733d5197e0c3f6b7dfb3711c2860ffef71 `
    --architecture-sha256 60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F
```

The installed equivalent is:

```powershell
& services/ml-recommendation/.venv/Scripts/build-candidate-group-split.exe `
    --features-dir services/ml-recommendation/data/features/v1 `
    --candidates-file services/ml-recommendation/data/synthetic/v1/candidates.jsonl `
    --output-dir services/ml-recommendation/data/splits/v1
```

The exact allocation is:

| Split | Candidates | Feature records |
|---|---:|---:|
| Train | 126 | 7,560 |
| Validation | 27 | 1,620 |
| Locked Test | 27 | 1,620 |

All 12 professional domains occur in every split. Each domain begins with a
10/2/2 allocation, and a deterministic seed-derived domain rotation distributes
the remaining Candidate to six Train, three Validation, and three Test domains.
Assignment uses only Candidate ID, primary domain, and the fixed seed; it does
not read labels, Feature values, scenarios, rationales, noise markers, pair
ordering, or Job IDs.

Generated files under `data/splits/v1` are `train.jsonl`,
`validation.jsonl`, `test.jsonl`, `assignments.jsonl`, `test_lock.json`,
`manifest.json`, and `SPLIT_CARD.md`. Feature records retain the Phase 5 schema
and values unchanged.

Test is cryptographically locked. Phases 7–9 must not use it for baseline
comparison, feature decisions, tuning, early stopping, calibration, threshold
selection, or promotion decisions. Phase 10 alone may perform final locked Test
evaluation. Phase 6 performs only structural counts, hashes, schema validation,
and overlap checks.

There is still no trained Model. Readiness remains `503`, and
ranking/model-metadata inference endpoints remain unimplemented.

## Phase 7 existing baseline evaluation

Phase 7 evaluates three deterministic references on Train and Validation only:
the weighted-skills baseline, the actual Laravel `MatchingService` 2.0 through
an in-memory Eloquent bridge, and an independent Python Matching 2.0 parity
oracle. It does not train a model and does not parse or evaluate Locked Test.

Run the evaluator from the repository root:

```powershell
& $python -m smart_recruitment_ml.baselines `
    --candidates-file services/ml-recommendation/data/synthetic/v1/candidates.jsonl `
    --jobs-file services/ml-recommendation/data/synthetic/v1/jobs.jsonl `
    --train-file services/ml-recommendation/data/splits/v1/train.jsonl `
    --validation-file services/ml-recommendation/data/splits/v1/validation.jsonl `
    --assignments-file services/ml-recommendation/data/splits/v1/assignments.jsonl `
    --feature-schema-file services/ml-recommendation/data/features/v1/feature_schema.json `
    --split-manifest services/ml-recommendation/data/splits/v1/manifest.json `
    --test-lock-file services/ml-recommendation/data/splits/v1/test_lock.json `
    --output-dir services/ml-recommendation/data/baselines/v1 `
    --php-executable php `
    --laravel-root . `
    --source-revision 6cd51f733d5197e0c3f6b7dfb3711c2860ffef71 `
    --architecture-sha256 60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F
```

The evaluator rejects a Locked Test path or hash, verifies every upstream
source hash, and requires zero Laravel database queries/writes. Laravel and
Python final scores, components, and cosine values must agree within `0.01`;
all ranks must match exactly at 100% pair coverage. Generated predictions,
metrics, parity evidence, provenance, and the acceptance report are stored in
`data/baselines/v1`.

Metrics are candidate-macro NDCG@5, NDCG@10, Precision@5, Recall@5, MRR,
and HitRate@5. NDCG uses gains `2^relevance_label - 1`; the four binary
metrics use `relevance_label >= 2`, with zero Recall/MRR/HitRate for a group
that has no relevant Job.

The bridge never persists its in-memory models and fails if any database query
or write occurs. No new dependency, XGBRanker, trained Model, or inference
endpoint is introduced. Phase 10 alone may open and evaluate Locked Test.
Readiness therefore remains `503`.

## Phase 8 Initial XGBRanker training

Phase 8 trains exactly one `XGBRanker` with the locked
`xgbranker-fixed-config-v1` configuration. Fit uses the 126-Candidate Train
split only. The 27-Candidate Validation split is passed only for evaluation
history and offline metrics. There is no parameter search, cross-validation,
early stopping, best-round selection, calibration, SHAP, inference, or model
promotion.

Install the pinned ML runtime into the existing `.venv`:

```powershell
$python = (Resolve-Path 'services/ml-recommendation/.venv/Scripts/python.exe').Path
& $python -m pip install -e './services/ml-recommendation[dev,ml]'
& $python -m pip check
```

Build the deterministic initial model from the repository root:

```powershell
& $python -m smart_recruitment_ml.training `
    --train-file services/ml-recommendation/data/splits/v1/train.jsonl `
    --validation-file services/ml-recommendation/data/splits/v1/validation.jsonl `
    --feature-schema-file services/ml-recommendation/data/features/v1/feature_schema.json `
    --split-manifest services/ml-recommendation/data/splits/v1/manifest.json `
    --test-lock-file services/ml-recommendation/data/splits/v1/test_lock.json `
    --baseline-metrics-file services/ml-recommendation/data/baselines/v1/metrics.json `
    --baseline-manifest-file services/ml-recommendation/data/baselines/v1/manifest.json `
    --output-dir services/ml-recommendation/data/models/initial/v1 `
    --model-version xgbranker-initial-v1 `
    --training-config-version xgbranker-fixed-config-v1 `
    --seed 20260724 `
    --source-revision 6cd51f733d5197e0c3f6b7dfb3711c2860ffef71 `
    --architecture-sha256 60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F
```

The installed equivalent is
`services/ml-recommendation/.venv/Scripts/train-initial-xgbranker.exe`.
The output directory contains XGBoost JSON, strict metadata, complete
Train/Validation predictions, six reused Phase 7 ranking metrics and baseline
deltas, 300-round history, a manifest, and a Model Card.

The Trainer verifies the Locked Test file by hash only and rejects its path or
content hash as model input. It does not parse its records, predict on it, or
calculate Test metrics; final Locked Test evaluation remains reserved for
Phase 10. The artifact is based on synthetic data and handcrafted features,
has no fairness or production-quality guarantee, and must not make automatic
candidate acceptance or rejection decisions. `/health/ready` remains `503`,
and the ranking/model-metadata endpoints remain unimplemented.

## Phase 9 bounded XGBRanker tuning

Phase 9 evaluates exactly eight predeclared CPU/single-thread configurations
on Train, selects by the locked Validation NDCG@10 policy, and retrains the
selected configuration once on Train + Validation. T00 must reproduce the
Phase 8 Initial Model byte-for-byte before selection is allowed.

Run from the repository root:

```powershell
& $python -m smart_recruitment_ml.tuning `
    --train-file services/ml-recommendation/data/splits/v1/train.jsonl `
    --validation-file services/ml-recommendation/data/splits/v1/validation.jsonl `
    --feature-schema-file services/ml-recommendation/data/features/v1/feature_schema.json `
    --split-manifest services/ml-recommendation/data/splits/v1/manifest.json `
    --test-lock-file services/ml-recommendation/data/splits/v1/test_lock.json `
    --baseline-metrics-file services/ml-recommendation/data/baselines/v1/metrics.json `
    --baseline-manifest-file services/ml-recommendation/data/baselines/v1/manifest.json `
    --initial-model-dir services/ml-recommendation/data/models/initial/v1 `
    --output-dir services/ml-recommendation/data/models/tuned/v1 `
    --tuning-run-version xgbranker-bounded-tuning-v1 `
    --tuned-model-version xgbranker-tuned-v1 `
    --seed 20260724 `
    --source-revision 6cd51f733d5197e0c3f6b7dfb3711c2860ffef71 `
    --architecture-sha256 60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F
```

The installed equivalent is
`services/ml-recommendation/.venv/Scripts/train-tuned-xgbranker.exe`.
The tuner rejects the Locked Test path and content hash, verifies it by hash
only, and records `test_evaluated=false`. It uses no early stopping,
cross-validation, SHAP, calibration, inference, or production promotion.
Phase 10 alone may parse and evaluate the Locked Test.

## Phase 10 Locked Final Test evaluation

Phase 10 performs the first and only evaluation of the five frozen systems on
the Locked Test. It loads the Initial and Tuned XGBRankers without training,
reuses the existing baseline and parity implementations, and publishes a
one-shot Final Evaluation receipt. Existing valid output is never overwritten.

```powershell
& $python -m smart_recruitment_ml.evaluation `
    --candidates-file services/ml-recommendation/data/synthetic/v1/candidates.jsonl `
    --jobs-file services/ml-recommendation/data/synthetic/v1/jobs.jsonl `
    --test-file services/ml-recommendation/data/splits/v1/test.jsonl `
    --feature-schema-file services/ml-recommendation/data/features/v1/feature_schema.json `
    --test-lock-file services/ml-recommendation/data/splits/v1/test_lock.json `
    --split-manifest services/ml-recommendation/data/splits/v1/manifest.json `
    --baseline-manifest-file services/ml-recommendation/data/baselines/v1/manifest.json `
    --initial-model-dir services/ml-recommendation/data/models/initial/v1 `
    --tuned-model-dir services/ml-recommendation/data/models/tuned/v1 `
    --output-dir services/ml-recommendation/data/evaluations/final-test/v1 `
    --php-executable php `
    --laravel-root . `
    --evaluation-version locked-final-test-v1 `
    --source-revision 6cd51f733d5197e0c3f6b7dfb3711c2860ffef71 `
    --architecture-sha256 60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F
```

No training, tuning, model modification, feature change, SHAP, inference, or
production promotion is performed. Phase 11 is reserved for explainability.
# Phase 11 model explainability

The frozen `xgbranker-tuned-v1` model is explained offline with native, exact
XGBoost Tree SHAP contributions. The explainability command accepts only the
frozen Train and Validation feature records, the tuned model artifacts, the
final Train+Validation prediction artifact, and aggregate Phase 10 evidence.
It has no Locked Test input and performs no training, tuning, calibration,
feature selection, model modification, or interaction attribution.

```powershell
$python = (Resolve-Path '.venv/Scripts/python.exe').Path

& $python -m smart_recruitment_ml.explainability `
    --train-file data/splits/v1/train.jsonl `
    --validation-file data/splits/v1/validation.jsonl `
    --feature-schema-file data/features/v1/feature_schema.json `
    --tuned-model-dir data/models/tuned/v1 `
    --final-train-validation-predictions-file data/models/tuned/v1/final_train_validation_predictions.jsonl `
    --final-test-comparison-file data/evaluations/final-test/v1/comparison.json `
    --final-test-receipt-file data/evaluations/final-test/v1/evaluation_receipt.json `
    --final-test-manifest-file data/evaluations/final-test/v1/manifest.json `
    --output-dir data/explainability/tuned/v1 `
    --explanation-version xgbranker-tuned-explainability-v1 `
    --source-revision 6cd51f733d5197e0c3f6b7dfb3711c2860ffef71 `
    --architecture-sha256 60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F
```

The seven deterministic artifacts include global and feature-group importance,
108 validation-origin local explanations, validation checks, a future consumer
contract, an integrity manifest, and a model explainability report. Scores and
contributions are model-margin attributions—not probabilities, causal claims,
fairness certification, acceptance predictions, or automatic hiring decisions.

## Phase 12 FastAPI inference service

Build the eight-file self-contained Bundle from frozen Phase 9 and Phase 11
inputs:

```powershell
$bundleDir = 'services/ml-recommendation/data/bundles/recommendation/v1'

& $python -m smart_recruitment_ml.bundle `
    --tuned-model-dir services/ml-recommendation/data/models/tuned/v1 `
    --feature-schema-file services/ml-recommendation/data/features/v1/feature_schema.json `
    --explanation-contract-file services/ml-recommendation/data/explainability/tuned/v1/explanation_contract.json `
    --explainability-manifest-file services/ml-recommendation/data/explainability/tuned/v1/manifest.json `
    --selected-validation-predictions-file services/ml-recommendation/data/models/tuned/v1/selected_validation_predictions.jsonl `
    --output-dir $bundleDir `
    --bundle-version job-rec-inference-bundle-v1 `
    --source-revision 6cd51f733d5197e0c3f6b7dfb3711c2860ffef71 `
    --architecture-sha256 60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F
```

Export the five deterministic internal Contract artifacts:

```powershell
$env:ML_BUNDLE_DIR = (Resolve-Path $bundleDir).Path
$env:ML_SERVICE_TOKEN = 'replace-with-at-least-32-random-characters'

& $python -m smart_recruitment_ml.api.contract_export `
    --output-dir services/ml-recommendation/data/contracts/inference/v1 `
    --candidates-file services/ml-recommendation/data/synthetic/v1/candidates.jsonl `
    --jobs-file services/ml-recommendation/data/synthetic/v1/jobs.jsonl `
    --source-revision 6cd51f733d5197e0c3f6b7dfb3711c2860ffef71 `
    --architecture-sha256 60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F
```

The Bundle loader verifies file sizes, SHA-256 checksums, Model metadata,
Feature Schema order/count, explanation and reason-code contracts, Validation
score transform, source revision, and locked non-usage evidence. The Model is
loaded exactly once during FastAPI lifespan startup.

Ranking accepts only strict professional facts and runs the unchanged
`FeaturePipelineV1`. It returns every supplied Job ordered by `raw_score DESC`
then `job_id ASC`; `limit` is echoed but does not truncate predictions.
`display_score` is a clipped 0-100 relevance indicator derived only from the
selected T06 Validation predictions. Explanations contain at most three
positive and three negative allowlisted feature-group reason codes, never raw
Feature names or values.

The recursive privacy denylist rejects identity, contact, demographic, CV,
application, assessment, interview, internal-note, credential, session, and
database-secret fields. Errors use a stable safe envelope without payloads,
secrets, paths, or stack traces.

Phase 12 performs no training, tuning, calibration, Model modification,
Feature Pipeline modification, database access, network calls, or locked Test
inference. Laravel orchestration and reconciliation begin in Phase 13.

## Final Handover Index

The complete Laravel-to-container implementation and operational boundary are
documented in the repository handover:

- [Final handover](../../docs/ml-job-recommendation/phase18/FINAL_HANDOVER.md)
- [Demo runbook](../../docs/ml-job-recommendation/phase18/DEMO_RUNBOOK.md)
- [Final verification](../../docs/ml-job-recommendation/phase18/FINAL_VERIFICATION_REPORT.md)
- [Handover manifest](../../docs/ml-job-recommendation/phase18/FINAL_HANDOVER_MANIFEST.json)
- [Deployment runbook](DEPLOYMENT.md)
- [Phase 17 E2E evidence](../../docs/ml-job-recommendation/phase17/PHASE_17_E2E_REPORT.md)

Locked runtime identities are `xgbranker-tuned-v1`,
`job-rec-inference-bundle-v1`, `job-rec-features-v1`,
`recommendation-ranking-api-v1`, and
`recommendation-explanation-contract-v1`.

Safe local verification uses the existing environment and image:

```powershell
& .venv/Scripts/python.exe -m pytest -p no:cacheprovider tests
& .venv/Scripts/python.exe -m ruff check .
& .venv/Scripts/python.exe -m ruff format --check src tests container
powershell -ExecutionPolicy Bypass -File ../../scripts/phase17/run-e2e.ps1
```

The service is an AI-assisted ranking component only. It does not establish
eligibility, make hiring decisions, produce probabilities, or replace human
review. The model is synthetic-trained, and production deployment has not
been performed.
