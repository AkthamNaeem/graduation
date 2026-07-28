# Phase 7 Baseline Report

## 1. Baseline

Repository `<repository-root>`, branch `master`, HEAD `6cd51f733d5197e0c3f6b7dfb3711c2860ffef71`; no staged or tracked changes existed at the gate, with 53 approved untracked Phase 3-6 files. Architecture SHA-256 `60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F` and Git blob `e3c80c1928292678e9a3bd8fbcc7f83521a16300` matched. Synthetic candidates/jobs hashes are `5d0ddbe461437afd80576e4b36044c94e083adfe2d232c05e4653a9fa54ef320` / `7aa398a1957c8851fb4fea4743f953be3f915177ae19266970ccf2d61440e74d`; Feature schema/features hashes are `aeb260b25f34b55b7164b215e613a0b4327df33ee65af95abc904045849ce4a0` / `4e405d74714a6a9a79b3d6339b19b595cb67b8cbd6f589b721e662d274ebd18e`; Train/Validation/Assignments/lock hashes are `d87095055d16ced57461eb8d4543bf4c3863b0ebe1771e5b3528eaf290b98c3d`, `a8cc27158bc126b11e93a0eefdf6a82a0e3f88e8d82cf9e9a0bae0491b04da7e`, `ba5c075f244c8d65200316e44a4b0bb68f579aa6e2b0546e3527e17db98bc502`, and `00f938c9f888156022d221a9fb3eab7c76e8d4316803d175470355a84f33ec73`. Python 3.12.10 and PHP 8.2.12 were used. The protected snapshot contained 50 files.

Production source SHA-256 values: `MatchingService.php` `b8f7df3f8f9189467ab73384498aa1f2aee725f15971bba4c07e67bd3b7eabee`; `CandidateExperienceCalculator.php` `86e819ec94ab76a368735338c92f0708f85128ec489e7b58c94ead7ee22c4e87`; `EducationLevelNormalizer.php` `6ddbc82ba8fd1ceeed128cd68a86f941b8cdbf611dea6966d0a4afbb8ec04d56`; `EducationLevel.php` `00935de9c5a8ffee30739b4007e23cf1734ab898a5161784591dcd24535fd40a`; `JobSkillRequirementType.php` `3109acb30206414eb5f8b5dfe05e5ab38ac9f203234a31b4090a9d0298736c43`; `config/matching.php` `766f9d734c6297d971e22aa2da5b29549e602f45d1b9bb84a146644a087838fc`.

## 2. Files Changed

Existing files modified: `services/ml-recommendation/pyproject.toml`, `services/ml-recommendation/README.md`. New Python source: seven `baselines/*.py` files and `schemas/baselines.py`. New PHP tool: `services/ml-recommendation/tools/laravel_matching_v2_baseline.php`. New tests: the five required Phase 7 modules. Generated artifacts: the six files under `data/baselines/v1`.

## 3. Implementation Details

Baseline A calculates only normalized weighted skill overlap. One shared adapter creates deterministic in-memory profile/job representations. Baseline B boots Laravel and invokes the real `buildTextFromProfile`, `buildTextFromJob`, `computeTFIDF`, `cosineSimilarity`, and `scoreMatch` methods. Baseline C independently reproduces the formula, Unicode tokenization, dynamic candidate-local 61-document TF-IDF corpus, cosine, and PHP half-up rounding. Labels are attached only after ranking for metrics; feature vectors are never consumed. All validations finish before staged atomic publication. The evaluator rejects a Test name/hash and the bridge fails on any database query/write.

## 4. Baseline Contracts

A is `skills-weighted-v1`: `100 * (0.85 * required_weight_coverage + 0.15 * nice_count_coverage)`, ordered score descending then `job_id` ascending. B is actual Laravel Matching `2.0` with 45/10/20/10/15 component weights. C is independent `matching-v2-parity-v1`, not a second ranking algorithm. Each prediction contains `pair_id`, `candidate_id`, `job_id`, `relevance_label`, `skills_baseline`, `laravel_matching_v2`, `python_matching_v2_parity`, and pair-level absolute-score/rank parity.

## 5. Adapter Contract

`synthetic-to-laravel-matching-v1` builds one alphabetically sorted, normalized, one-based skill registry shared by PHP and Python. Candidate mapping uses headline, empty summary, registry skills, one experience beginning 2000-01-01 with `round(years * 365.25)` days, and mapped degree plus primary-domain field. Job mapping preserves professional source fields and source education, joins required skill names deterministically, uses required source weights, gives nice skills weight 1, maps `lead` to `senior`, and fixes publication at 2026-01-01T00:00:00Z.

## 6. Metrics Definitions

`ranking-metrics-v1` computes candidate-macro NDCG@5, NDCG@10, Precision@5, Recall@5, MRR, and HitRate@5. NDCG gain is `2^relevance_label - 1` with `log2(rank + 1)` discount. Binary relevance is `label >= 2`; a zero-relevant group has Recall, MRR, and HitRate zero. Each metric stores macro mean, median, minimum, maximum, population standard deviation, and group count.

## 7. Train Results

| Baseline | NDCG@5 | NDCG@10 | Precision@5 | Recall@5 | MRR | HitRate@5 |
|---|---:|---:|---:|---:|---:|---:|
| Skills-only | 0.416864 | 0.484924 | 0.253968 | 0.071776 | 0.570569 | 0.976190 |
| Laravel Matching 2.0 | 0.464126 | 0.514174 | 0.277778 | 0.078406 | 0.705423 | 1.000000 |
| Python Matching 2.0 | 0.464126 | 0.514174 | 0.277778 | 0.078406 | 0.705423 | 1.000000 |

## 8. Validation Results

| Baseline | NDCG@5 | NDCG@10 | Precision@5 | Recall@5 | MRR | HitRate@5 |
|---|---:|---:|---:|---:|---:|---:|
| Skills-only | 0.420585 | 0.498813 | 0.266667 | 0.074720 | 0.471340 | 0.962963 |
| Laravel Matching 2.0 | 0.478748 | 0.532605 | 0.281481 | 0.078552 | 0.656790 | 1.000000 |
| Python Matching 2.0 | 0.478748 | 0.532605 | 0.281481 | 0.078552 | 0.656790 | 1.000000 |

## 9. Laravel–Python Parity

Train: 7560 pairs, maximum/mean score error 0.000000/0.000000, exact/tolerance matches 7560/7560, rank matches 7560 (100%), missing/extra 0/0. Validation: 1620 pairs, maximum/mean error 0.000000/0.000000, exact/tolerance matches 1620/1620, rank matches 1620 (100%), missing/extra 0/0. All six component mismatch counts are zero. Database queries/writes are 0/0. Tolerance is <= 0.01; exact rank agreement is required.

## 10. Locked Test Verification

The lock reports SHA-256 `79fcb93b232b63482a9c26d1d0caa660289b7b798776c09f0945865ca6741a05`, 1,620 records, `test_locked=true`, and prohibition before Phase 10. Only the cryptographic hash was verified. Records parsed=false; metrics run=false. Test labels, vectors, Candidate IDs, and samples were neither read nor printed.

## 11. Reproducibility

Two complete runs used source revision `6cd51f733d5197e0c3f6b7dfb3711c2860ffef71`, architecture SHA-256 `60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F`, and fixed release date `2026-07-24`. All six artifacts matched byte-for-byte; the temporary reproduction directory was removed. The canonical command is recorded in `manifest.json`; output metadata records deterministic hashes without self-hashing the manifest.

## 12. Samples

Train and Validation predictions only; raw professional text, feature vectors, and Locked Test data are excluded.

- Train `cand_0001` / `job_0001`: label 3, skills 85.96 (rank 1), Laravel 86.85 (rank 1), Python 86.85 (rank 1).
- Validation `cand_0003` / `job_0121`: label 1, skills 24.29 (rank 15), Laravel 55.13 (rank 5), Python 55.13 (rank 5).

## 13. Dependencies

No dependency was added. Only Python standard library, existing Pydantic, Composer autoload, and existing Laravel packages are used. NumPy, Pandas, SciPy, scikit-learn, XGBoost, SHAP, Joblib, database clients, Redis, and Faker are absent from Phase 7.

## 14. Tests Executed

`python -m pip check`; Phase-specific `python -m pytest` command; full `python -m pytest ... --cov=smart_recruitment_ml`; `python -m ruff check services/ml-recommendation`; `python -m ruff format --check services/ml-recommendation`; `python -m mypy services/ml-recommendation/src services/ml-recommendation/tests`; `python -m compileall -q services/ml-recommendation/src`; PHP lint; `php artisan test --compact --do-not-cache-result --filter=Matching`; full Laravel tests; `git diff --check`; forbidden-dependency and Test-policy scans; protected/source hash comparisons; and deterministic regeneration.

## 15. Test Results

Phase-specific: 60 passed. Full Python suite: 125 passed, 0 failed, 0 skipped, 1 existing Starlette deprecation warning; coverage 93%. Ruff, format, Mypy (45 files), pip check, compileall, PHP syntax, metrics, bridge, parity, lock, CLI, determinism, and `git diff --check` passed. Laravel Matching: 32 passed (170 assertions). Full Laravel regression: 534 passed, 1 skipped (3,994 assertions). No Laravel source changed.

## 16. Generated Artifacts

| File | Records |
|---|---:|
| `train_predictions.jsonl` | 7,560 |
| `validation_predictions.jsonl` | 1,620 |
| `metrics.json` | 1 |
| `parity.json` | 1 |
| `manifest.json` | 1 |
| `BASELINE_REPORT.md` | 1 |

Sizes and SHA-256 values are recorded in `manifest.json` for every non-self-referential output and verified externally for all six files. No XGBRanker or Model artifacts exist.

## 17. Risks

The judgments are synthetic; the adapter is an explicit approximation boundary; TF-IDF is dynamic per Candidate's 60-job corpus; Validation contains only 27 Candidate groups; exact B/C parity proves implementation equivalence, not production recommendation quality. There is no Model yet. Locked Test protection remains valid only while its lock and hash stay unchanged.

## 18. Remaining Work

Phase 8 — Initial XGBRanker Training

## 19. Phase Gate

READY FOR PHASE 8

## 20. Exact Repository State

Branch `master`, HEAD `6cd51f733d5197e0c3f6b7dfb3711c2860ffef71`; staged=0 and tracked modifications=0. The original 53 approved untracked files plus exactly 20 Phase 7 allowlisted files remain untracked. Ignored `.venv`, coverage, and pytest temporary outputs are not source artifacts. Architecture, Dataset, Features, Splits, lock, and production hashes match; protected mismatches=0. Commit created=false; push performed=false; Laravel files modified=false; Baseline evaluation created=true; Test evaluated=false; XGBRanker created=false; Model created=false; Docker modified=false.
