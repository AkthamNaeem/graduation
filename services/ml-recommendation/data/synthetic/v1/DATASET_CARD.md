# Synthetic Job Recommendation Dataset Card

## Identity

- Dataset: `synthetic-job-rec-1.0.0`
- Schema: `synthetic-job-rec-schema-v1`
- Generator: `0.1.0`
- Release date: `2026-07-24` (fixed Dataset release metadata)
- Synthetic: **yes, entirely synthetic**
- Fixed seed: `20260724`

## Intended use

This Dataset supports later development and evaluation of Learning-to-Rank for
Job Seeker → Job Recommendation. Candidate IDs are future ranking query groups.
It may be used for pipeline tests, baseline experiments, and model research.

It must not be used as evidence of production quality, hiring suitability,
acceptance probability, application outcome, or a decision to accept or reject
a person. Rationale codes are audit metadata and must not become Phase 5 model
features.

## Counts and schemas

- Candidates: `180`
- Jobs: `180`
- Candidate-Job pairs: `10800`
- Pairs per Candidate: `60`

`candidates.jsonl` contains synthetic professional facts: domain, headline,
career level, experience, education, skills, and work preferences.

`jobs.jsonl` contains synthetic professional requirements: domain, title,
responsibilities, required/nice-to-have skills, experience, education,
seniority, work mode, and employment type.

`pairs.jsonl` contains IDs, relevance label `0..3`, scenario, rationale codes,
and a controlled-noise marker. It never contains latent scores or feature
vectors.

## Domains

- Backend Engineering
- Frontend Engineering
- Mobile Development
- Data Engineering
- Data Analysis
- DevOps / Cloud
- Cybersecurity
- Quality Assurance
- Product Management
- UI/UX Design
- Digital Marketing
- Finance / Accounting

## Labels and scenarios

- `0`: low professional relevance.
- `1`: limited relevance with important gaps.
- `2`: useful relevance with some gaps.
- `3`: strong relevance.

Scenarios are `strong_match`, `good_match`, `adjacent_domain`, `borderline`,
`hard_negative`, `clear_mismatch`, and `noise_injected`. Hard negatives retain
a positive surface signal while exposing a critical professional conflict.
Borderline records include both positive and negative factors near a label
threshold.

Rationale vocabulary includes skill coverage, experience, seniority,
education, domain transfer, work-mode, employment-type, and controlled-noise
codes. Rationales never reveal runtime hidden affinity values.

## Generation approach

The generator combines professional compatibility, weighted skill coverage,
proficiency, experience, seniority, education, work preferences, nonlinear
interactions, hidden Candidate/Job affinity, and bounded random noise. Hidden
factors exist only during generation and are not serialized or available to a
future Feature Pipeline. Labels are not Matching `2.0`, skill counts, or
application outcomes.

Pair sampling is deterministic and stratified across same-domain, adjacent,
borderline, hard-negative, mismatch, and noise scenarios. The final regular
sampling schedule gives every Candidate exactly 60 unique Jobs and balances
Job appearances.

## Privacy and eligibility

No real people, companies, CVs, applications, demographics, contact details,
authentication data, tests, interviews, or internal notes are used. All IDs
are synthetic.

Jobs represent `laravel_pre_filtered_eligible_jobs`. Job status, company
approval, deadline, and prior-application exclusion remain Laravel concerns
and are intentionally not model features.

## Limitations

The catalog and labels are authored synthetic approximations. They have a
synthetic-to-production gap, limited occupational breadth, and provide no
fairness or production-performance guarantee. No train/validation/test split
exists yet, and Feature Pipeline definitions are deferred to Phase 5.

## Reproducibility

From the repository root:

```powershell
& services/ml-recommendation/.venv/Scripts/python.exe -m smart_recruitment_ml.data `
    --output-dir services/ml-recommendation/data/synthetic/v1 `
    --seed 20260724 `
    --candidate-count 180 `
    --job-count 180 `
    --pairs-per-candidate 60 `
    --source-revision 6cd51f733d5197e0c3f6b7dfb3711c2860ffef71 `
    --architecture-sha256 60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F
```

JSONL hashes:

- `candidates.jsonl`: `5d0ddbe461437afd80576e4b36044c94e083adfe2d232c05e4653a9fa54ef320`
- `jobs.jsonl`: `7aa398a1957c8851fb4fea4743f953be3f915177ae19266970ccf2d61440e74d`
- `pairs.jsonl`: `31a2e7c6f26e0c9840674cd7caff465be70ec4f753c13333f61ae3593998ecb1`

See `manifest.json` for counts, distributions, configuration, and file
integrity metadata.
