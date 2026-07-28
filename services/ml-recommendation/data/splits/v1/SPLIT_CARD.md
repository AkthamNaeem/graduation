# Candidate-Grouped Split Card

## Identity

- Split: `candidate-group-split-v1`
- Generator: `0.1.0`
- Source Dataset: `synthetic-job-rec-1.0.0`
- Feature Schema: `job-rec-features-v1`
- Fixed seed: `20260724`
- Group key: `candidate_id`
- Release date: `2026-07-24` (fixed release metadata)

## Purpose and exact allocation

This artifact partitions the Feature Dataset by Candidate, not by
Candidate-Job pair. All 60 records for a Candidate stay in exactly one split.
The exact allocation is Train 126 Candidates / 7,560 records, Validation 27 /
1,620, and locked Test 27 / 1,620.

Each of the 12 domains starts with a 10/2/2 Candidate allocation. One remaining
Candidate per domain is assigned through a deterministic seed-derived domain
rotation: six extras to Train, three to Validation, and three to Test. Candidate
IDs are sorted before a domain-local deterministic shuffle. Labels, feature
values, scenarios, rationales, noise, pair ordering, Job IDs, and metrics are
not inputs to assignment.

Jobs may occur in more than one split; Candidate groups may not. Train supports
Phase 7+ fitting and analysis. Validation supports model selection without
opening Test.

## Locked Test policy

Test is cryptographically locked at Phase 6. Phases 7-9 must not use it for
baseline comparison, feature decisions, hyperparameter tuning, early stopping,
calibration, threshold selection, or promotion decisions. Phase 10 alone may
perform the final locked evaluation. This phase computes only structural
counts, integrity hashes, schema checks, and overlap checks.

The Test Candidate hash is SHA-256 over ascending Candidate IDs, one UTF-8 ID
per line, LF endings, and a final newline. The Candidate list itself is not
printed here.

## Leakage, privacy, and limitations

Candidate and Pair intersections are zero and their unions equal the complete
sources. Split files preserve the original Feature record schema and values.
Assignments contain only Candidate ID, professional primary domain, and split.
No raw Candidate/Job facts, sensitive data, timestamps, or audit metadata are
added.

The data and groups are synthetic. Domain-aware allocation does not guarantee
production balance, Jobs intentionally repeat across splits, and Validation
and Test contain only 27 Candidate groups each.

## Reproducibility

From the repository root:

```powershell
& services/ml-recommendation/.venv/Scripts/python.exe -m smart_recruitment_ml.splits `
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

See `manifest.json` for output hashes. No baseline evaluation, training,
calibration, trained Model, or inference is included.
