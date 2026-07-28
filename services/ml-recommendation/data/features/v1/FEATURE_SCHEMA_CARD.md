# Feature Schema Card

## Identity and purpose

- Feature Schema: `job-rec-features-v1`
- Feature Pipeline: `0.1.0`
- Source Dataset: `synthetic-job-rec-1.0.0` / `synthetic-job-rec-schema-v1`
- Release date: `2026-07-24` (fixed release metadata)
- Feature count: `103`

This schema converts Candidate and Job professional facts into the same fixed,
ordered vector for later training, validation, locked testing, and FastAPI
inference. It is not a ranking Model and does not make hiring decisions.

## Inputs and normalization

`CandidateFeatureInput` accepts domain, adjacent domains, headline, career
level, experience, education, skills, and work preferences.
`JobFeatureInput` accepts domain, title, department, description,
responsibilities, skills, experience, education, career level, work mode, and
employment type. Identity, audit, outcome, company, and sensitive facts are
forbidden.

Text uses Unicode NFKC, `casefold()`, whitespace collapse, normalized hyphens,
and bounded Unicode-aware word tokens. There is no stemming, locale-dependent
processing, external NLP, TF-IDF, or embedding.

Candidate duplicate skills keep maximum proficiency and years independently.
Required duplicates keep maximum weight; nice-to-have values are de-duplicated,
and required skills take precedence. Critical required skills have weight
`>= 4`.

## Missing and unknown values

Missing strings and lists become empty values. Missing numeric values become
`0.0`, with explicit indicators for important facts. Unknown categorical
values use `__unknown__` and never change vector length. No Dataset mean, label,
split, or distribution is used for imputation.

## Vocabularies, families, bounds, and order

The fixed vocabularies are stored in `feature_schema.json` for domains, career
levels, education levels, work modes, and employment types. Families cover
domain compatibility, required and transferable skills, experience, career
level, education, preferences, deterministic text alignment, four bounded
interactions, and missing indicators.

Ratios and indicators are bounded to `[0,1]`. Signed experience and ordered
level distances are bounded to `[-1,1]`. Experience is capped at
`30` years. Token limits are headline/title `32`,
description `256`, and combined responsibilities `256`.

The exact immutable feature order and per-feature definitions are authoritative
in `feature_schema.json`.

## Label separation, leakage, and privacy

`FeaturePipelineV1.transform()` accepts only the two professional-fact input
schemas. The relevance target and three synthetic IDs are attached only by the
Dataset exporter, outside `feature_values`. Scenario, rationale, controlled
noise, generator-only factors, identity, contact, demographic, CV,
application, assessment, interview, company, internal-note, and authentication
data are excluded. Exported feature records contain no raw input text or audit
metadata.

## Reproducibility

From the repository root:

```powershell
& services/ml-recommendation/.venv/Scripts/python.exe -m smart_recruitment_ml.features `
    --input-dir services/ml-recommendation/data/synthetic/v1 `
    --output-dir services/ml-recommendation/data/features/v1 `
    --feature-schema-version job-rec-features-v1 `
    --pipeline-version 0.1.0 `
    --source-revision 6cd51f733d5197e0c3f6b7dfb3711c2860ffef71 `
    --architecture-sha256 60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F
```

The four outputs are byte-for-byte deterministic for identical locked inputs.

## Intended and non-intended use

Intended use is Phase 6+ offline ranking research and later shared inference
transformation. There is no train/validation/test split yet, baseline
evaluation, learned text representation, trained Model, calibration, SHAP,
inference endpoint, or production-quality guarantee. The handcrafted catalog
and labels are synthetic and static.
