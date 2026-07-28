# ML Job Recommendation — Project Completion Checklist

This checklist distinguishes the completed 18-phase implementation plan from
future production operations.

## Implementation Complete

- [x] **Database:** `recommendation_runs` and `recommendation_items` migration,
  indexes, constraints, cascades, expiry, and retention contract.
- [x] **Models:** typed Laravel run/item models and relationships.
- [x] **Contracts:** locked Model, Bundle, Feature Schema, internal API, and
  explanation versions.
- [x] **Validation:** strict Laravel mapping/reconciliation and FastAPI request,
  privacy, Bundle, and response validation.
- [x] **Permissions:** Sanctum and existing active-user/company middleware on
  the public endpoint; service-token protection internally.
- [x] **Services:** `RecommendationMlClient`, `RecommendationOrchestrator`,
  eligibility, context fingerprint, store, cache, hydrator, and mapper.
- [x] **API:** backward-compatible `GET /api/v1/jobs/recommended`.
- [x] **Tests:** Laravel unit/feature/regression, Python regression, contract,
  packaging, E2E, failure, privacy, and bounded concurrency tests.
- [x] **Documentation:** architecture, model/Bundle/contract cards, deployment,
  Phase 17 evidence, and Phase 18 handover.
- [x] **Deployment packaging:** pinned hardened Phase 16 image and Compose
  contract; no Phase 18 rebuild.
- [x] **Privacy:** sensitive-field denylist, safe outbound payload, safe errors,
  and zero Phase 17 runtime marker exposure.
- [x] **Explainability:** SHAP-based allowlisted reason codes with non-causal,
  non-probability, and decision-support disclaimers.
- [x] **Fallback:** stable MatchingService 2.0 behavior and safe codes.
- [x] **Cache:** compact pointers, TTL separation, corruption rejection, and
  database reuse.
- [x] **Pruning:** dry-run and executing retention command.
- [x] **E2E:** cold, cache, persistence, invalidation, failure, corruption,
  concurrency, privacy, and cleanup evidence.
- [x] **Rollback:** ML disablement and image/Bundle rollback documented.
- [x] **Handover:** final document, demo, traceability, verification, Arabic
  defense guide, deterministic manifest, and cryptographic baseline.

## Academic Completion Boundaries

- [x] AI is documented as decision-support only.
- [x] `display_score` is documented as not a probability.
- [x] SHAP attribution is documented as not causality.
- [x] Synthetic-data limitations are explicit.
- [x] Laravel remains the only eligibility authority.
- [x] Candidate Ranking remains outside ML Job Recommendation.
- [x] Locked Test non-usage after Phase 10 is explicit.
- [x] Production deployment is recorded as not performed.
- [x] Production load testing is recorded as not performed.

## Final Verification Gate

- [x] Phase 18 baseline and frozen artifact hashes verified.
- [x] Documentation contract, Python regression and coverage, static checks,
  Pint, E2E smoke, privacy scan, protected comparison, and cleanup passed.
- [x] Literal full Laravel command is green: 762 passed, two expected opt-in
  skips, and zero failures. The stale Phase 17 README byte-size assertion was
  replaced with semantic documentation checks, and the changed E2E test is
  covered by one explicit final-gate maintenance allowlist.
- [x] Commit-safety portability remediation replaced workstation paths in the
  ML README and historical baseline report metadata. The evaluator change is
  limited to the report label and changes no ranking, metric, feature, split,
  inference, Model, or Bundle behavior. The immutable Phase 18 baseline records
  nine total approved differences, including the Phase 7 manifest's
  report-only integrity record, with zero unexpected mismatches and zero missing
  paths.
- [x] Trainer current provenance equals the portable Phase 7 manifest hash.
  Historical byte reproduction supplies the old manifest provenance and Locked
  Test SHA-256 from frozen metadata inside the test only, blocks every Locked
  Test `Path.open`, trains on real Train/Validation data in a temporary
  directory, and reproduces all eight historical artifacts byte-for-byte.
- [x] Phase 17 and Phase 18 integrity tests list the two approved Python
  maintenance paths explicitly; no wildcard, directory-level, all-source, or
  all-test exemption was added, and every other protected path remains enforced.
- [x] Composer audit is recorded as indeterminate because Packagist was
  unavailable; no dependency changed in scope.

## Production Operational Work Still Required

- [ ] Build a consented, governed real-world labeled Dataset.
- [ ] Perform subgroup fairness and adverse-impact evaluation with qualified
  review.
- [ ] Define production monitoring dashboards, SLOs, and alerting.
- [ ] Add a distributed lock only if production cold-miss measurements justify
  it.
- [ ] Implement CI/CD gates for Laravel, Python, contracts, image, and
  documentation.
- [ ] Run external container and dependency vulnerability scanning.
- [ ] Run production-representative load, soak, race, and recovery tests.
- [ ] Integrate the service token with managed key/secret storage and rotation.
- [ ] Add paging/alerting for readiness, latency, fallback, contract, and
  persistence failures.
- [ ] Define model-input and quality drift monitoring.
- [ ] Establish a controlled retraining, validation, approval, promotion, and
  rollback policy.
- [ ] Execute a controlled production deployment and operational acceptance.

Unchecked production items are deliberate future work and do not reduce the
completed academic implementation status.
