# Final Verification Report

Release date: 2026-07-26

Scope: Phase 18 documentation, verification, demo, and handover

Source revision: `6cd51f733d5197e0c3f6b7dfb3711c2860ffef71`

## 1. Repository state

Verification started on branch `master` at the expected source revision. The
working tree already contained the cumulative, uncommitted implementation from
Phases 13–17: five tracked modifications, no staged files, and the untracked
Laravel, ML, Docker, test, and documentation files. Phase 18 did not commit,
push, deploy, rebuild an image, or change production behavior.

## 2. Phase completion summary

All 18 planned implementation phases are represented by source, tests, or
evidence artifacts. Phase 18 added the protected baseline, consolidated
handover, repeatable demo, requirements traceability, completion checklist,
Arabic defense guide, final verification report, deterministic manifest, and
one documentation contract test. The implementation plan is 100% complete with
documented production limitations; production deployment remains
`not_deployed`.

## 3. Laravel tests

The focused Matching regression passed: 52 tests and 414 assertions.

The focused Recommendation regression passed with 211 tests, 4,282 assertions,
and one intentional Docker-integration skip. The focused
RecommendationEndToEnd regression passed with six tests and 2,877 assertions.

The only previous failure was a stale Phase 17 assertion that treated the
historical root README byte size and hash as immutable. Phase 18 explicitly
required and permitted adding final handover links, so final-gate maintenance
replaced that brittle check with semantic assertions for required
repository-relative links, the production-deployment disclaimer, path safety,
and secret safety. The changed E2E test was added to one explicit Phase 18
maintenance allowlist; no wildcard or test-directory exemption was introduced.

The dedicated Phase 18 documentation contract passed: one test and 7,999
assertions.

The complete Laravel command passed with 762 tests, two expected opt-in skips,
zero failures, and 16,547 assertions. No production behavior changed.

## 4. Python tests

The safe regression used Python 3.12.10 and did not open the repository's
Locked Test artifact for historical model reproduction. It ignored
`test_final_evaluation_artifacts.py` and deselected the real pre-open gate that
would access the Locked Test artifact. Tests using synthetic temporary locked
fixtures remained enabled.

Result: 358 passed, zero failed, one third-party deprecation warning.

The historical initial-model test read the old Phase 7 manifest SHA-256 and
Locked Test SHA-256 from the frozen initial-model manifest. Inside that test
only, it supplied the historical Phase 7 manifest and substituted the recorded
Locked Test SHA-256. A guard on the trainer's actual `Path.open` read path
failed any attempted Locked Test open; observed opens, reads, and parsing were
all zero. Real Train/Validation training ran in pytest's external temporary
directory, and all eight generated artifacts matched their frozen historical
bytes without normalization. No repository training output was created.

The first infrastructure attempt used a temporary root that refused pytest and
coverage writes and produced no valid verdict. The correct pytest-root-relative
deselection was then rerun in a writable external temporary directory and
completed successfully; all temporary test and coverage files are removed
during cleanup.

## 5. Coverage

Safe whole-package statement coverage was 93% (`8128` statements, `537`
missed), above the required 90% gate. The coverage database and temporary test
tree are not handover artifacts and are removed.

## 6. E2E results

The existing-image smoke harness ran exactly once with
`workeyx/ml-recommendation:0.2.0-phase16`. It verified:

- cold public HTTP computation with engine `ml_xgbranker`;
- warm cache hit with no new run or item writes;
- durable persistence hit after cache removal with no new writes;
- content-addressed invalidation and recomputation;
- safe cache and persistence corruption recovery;
- stopped-container fallback through `matching_v2_fallback`;
- bounded warm and cold concurrency with no failed HTTP responses or partial runs;
- cleanup of all harness containers and temporary state.

No Docker image was built. The primary Phase 16 image remained available.

## 7. Failure matrix

All 18 injected provider/network failures returned public HTTP success through
the stable `MatchingService 2.0` fallback after one provider call:

connection refused, timeout, 401, 422, 429, 500, 503, empty body, invalid JSON,
version mismatch, request-id mismatch, missing prediction, extra prediction,
duplicate job, rank gap, invalid score, invalid reason, and abrupt close.
Failures mapped to the bounded safe codes for transport, authentication,
provider validation, rate limiting, model availability, or contract failure.

## 8. Cache and persistence

The E2E run verified cold write, cache reuse, database reuse, invalidation,
corrupt-cache recovery, and corrupt-persistence replacement without partial
runs. Laravel remains responsible for the context fingerprint, final
reconciliation and sorting, durable run/item storage, and cache pointers.

## 9. Docker verification

The smoke run used the existing Phase 16 tag, confirmed the primary image
remained present, and left zero Phase 17 test containers. The container contract
remains Python 3.12.10, non-root UID/GID 10001, and one worker. The image was
neither rebuilt nor deployed.

## 10. Security and privacy

The E2E privacy scan found zero sensitive log hits, no fault-server request-body
logging, and no public provider detail exposure. Phase 18 files were checked
for real bearer values, secrets, private keys, user contact markers, local
absolute paths, machine identifiers, container identifiers, and unsupported
production claims. Only environment-variable names and visibly synthetic
placeholders are permitted.

AI output remains decision support only. `display_score` is not a Probability,
and SHAP Attribution is not Causality. Laravel is the sole source of
Eligibility; the ML service does not discover users, query application data, or
make employment decisions.

## 11. Frozen artifacts

| Artifact | SHA-256 |
|---|---|
| Architecture | `60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F` |
| Model | `3ABD74137BC8881667643F31A658C790EF6712359D7802EA7FCFFA0C4CF9E26E` |
| Feature Schema | `AEB260B25F34B55B7164B215E613A0B4327DF33EE65AF95ABC904045849CE4A0` |
| Bundle Manifest | `1D566E4516724FAE0C08CD6131214C0722DFFCD589A370CF2405B8B0450DFB00` |
| OpenAPI | `B73B11B5FA67C40927E5A05AB72E2D2F7B292FA3149F0D945AE74BE08F7CA96D` |
| Contract Manifest | `A51E8F4E74189CCB086BDB7FE32816C6E56953533F3C77243E50650BE0BF9CB2` |
| Container Manifest | `1DD6BB89D805544266F04B6EA1C5BD4E71C00DF7AC97E78473DDE6A19B431FD1` |
| Phase 17 Matrix | `039755B90097B2A0AFE34E444F49E143FF9466789247222DBA81FED82FA0006C` |
| Phase 17 Report | `6FB97968B6FD59890308CC16BDAD7BF7E69E0382BC4A1B20BF7C5F245D223C10` |

All eight files in the frozen inference bundle matched their protected Phase 18
baseline entries byte for byte.

## 12. Baseline verification

The Phase 18 baseline artifact SHA-256 is
`F425D51C0094D2D2AAAFC220C02FE3DA4AA1796DDC59534F0F5E471A27995521`.
It contains 873 sorted protected entries with aggregate SHA-256
`5CC89EC6F445E1DB25CABED4666314890186080D0799B1954CB8F0AF59D233A4`.
Initial entry, missing-path, aggregate, duplicate-path, and self-entry checks all
passed.

The current comparison finds nine protected differences. Three are the permitted
existing-document changes: `README.md`, `services/ml-recommendation/README.md`,
and `BACKEND_IMPLEMENTATION_REPORT.md`. One is the explicitly approved
final-gate maintenance change to
`tests/Feature/Api/V1/RecommendationEndToEndTest.php`. The remaining two are
post-handover commit-safety portability remediations:
`services/ml-recommendation/data/baselines/v1/BASELINE_REPORT.md` and
`services/ml-recommendation/src/smart_recruitment_ml/baselines/evaluator.py`.
A seventh difference is
`services/ml-recommendation/data/baselines/v1/manifest.json`, whose single
`BASELINE_REPORT.md` output record was updated to match the approved portable
report bytes. The eighth is the approved current-provenance correction in
`services/ml-recommendation/src/smart_recruitment_ml/training/trainer.py`; it
now identifies the current Phase 7 manifest SHA-256
`C591708A58AE66941BB004CE08522EAADC90F476105F7BED08B5E2DB477046BF`.
The ninth is the Locked-Test-safe historical adapter and current-provenance
regression in `services/ml-recommendation/tests/test_model_artifacts.py`. The
documentation test names those exact protected paths in its allowlist; no
wildcard or directory exemption is used. There are zero unexpected protected
mismatches and zero missing paths across all 873 entries.

The Phase 17 integrity method now names the same two approved post-handover
Python maintenance paths explicitly. The Phase 17 and Phase 18 integrity tests
therefore recognize the provenance and Locked-Test-safety remediations without
a wildcard, directory exemption, general Python-source exemption, or general
Python-test exemption. Every other protected path remains subject to its
recorded size and SHA-256.

The evaluator correction changes only the repository-root label emitted into
the historical baseline report, replacing a workstation path with
`<repository-root>`. It does not change ranking, metrics, features, data
splits, inference, Model, or Bundle behavior. The checked-in baseline report
received the same textual metadata correction; every metric, numerical result,
version, and artifact hash stated in that report remains unchanged. The Phase
18 baseline itself remains immutable. Historical training and final-evaluation
provenance continues to record the pre-remediation manifest hash
`CB5853921B6CDFEF7A53989E79950116290B06CFD27596ACF70F79FBB33636D4`
because that was the exact input used at those earlier gates; it is not a
current-integrity claim. The historical hash is used only by the reproduction
test adapter. The unchanged `test_baseline_cli.py` verifies the updated Phase 7
manifest against every current baseline output.

The Phase 17 baseline, Phase 16 integrity exception, Phase 17 matrix, and Phase
17 report retained their accepted hashes.

## 13. Dependency verification

- Ruff lint: all checks passed.
- Ruff format: 108 files already formatted; no file was rewritten.
- Mypy: explicit Python 3.12 compatibility check passed for 106 source files.
- Existing project configuration targets Python 3.11 and is incompatible with
  current NumPy stubs; configuration was not changed during this scope.
- Python compileall: passed.
- Python `pip check`: no broken requirements found.
- Pint: passed for the Phase 18 documentation test.
- Composer audit: external verification unavailable. The command could not
  connect to Packagist, so no advisory-pass claim is made.

Composer audit:
Indeterminate because external Packagist access was unavailable.
No Composer dependency changed during Phases 13–18.

## 14. Known verification limitations

- The model was trained on synthetic data; external validity is not established.
- No production fairness assessment, drift monitoring, alerting, SLO validation,
  production load test, or live traffic experiment was performed.
- No production deployment or production rollback rehearsal occurred.
- Composer advisory status requires external Packagist access and must not be
  represented as passed when that access is unavailable.
- The real Locked Test was not parsed, predicted, or evaluated after Phase 10;
  historical reproduction used its frozen metadata SHA-256 without opening it.

## 15. Final readiness

All new Phase 18 artifacts, the documentation contract, Python regression,
coverage, Ruff, Mypy, compileall, pip check, Pint, E2E smoke, frozen hashes,
privacy scan, protected comparison, and cleanup passed. Composer audit is
indeterminate solely because external Packagist access was unavailable.

The strict Phase 18 gate is **PROJECT HANDOVER READY**. The complete Laravel
suite has zero failures, the approved final-gate maintenance is explicit and
bounded, the commit-safety portability changes are explicit and behavior
neutral, and all frozen runtime protections remain active. No production
deployment is implied.
