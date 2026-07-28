# Initial XGBRanker Model Card

## Model

- Name/version: `xgbranker-initial-v1`
- Format: `xgboost-json-v1`
- Status: initial experimental ranker; not production-ready
- Objective: `rank:ndcg`
- Inputs: exactly 103 handcrafted features using `job-rec-features-v1`
- Training groups: 126 Candidates / 7,560 records
- Validation groups: 27 Candidates / 1,620 records

## Fixed hyperparameters

- `objective`: `"rank:ndcg"`
- `eval_metric`: `["ndcg@5","ndcg@10"]`
- `n_estimators`: `300`
- `learning_rate`: `0.05`
- `max_depth`: `4`
- `min_child_weight`: `1.0`
- `gamma`: `0.0`
- `subsample`: `1.0`
- `colsample_bytree`: `1.0`
- `reg_alpha`: `0.0`
- `reg_lambda`: `1.0`
- `max_bin`: `256`
- `tree_method`: `"hist"`
- `device`: `"cpu"`
- `random_state`: `20260724`
- `n_jobs`: `1`
- `verbosity`: `0`
- `validate_parameters`: `true`
- `lambdarank_pair_method`: `"topk"`
- `lambdarank_num_pair_per_sample`: `10`
- `ndcg_exp_gain`: `true`

Exactly one fixed configuration was trained on Train. Validation was used only for
evaluation history. There was no tuning, cross-validation, early stopping, calibration,
threshold selection, or best-round selection.

## Metric definitions

Metrics reuse Phase 7 candidate-macro implementations: NDCG uses graded gain
`2^relevance_label - 1`; Precision, Recall, MRR, and HitRate use binary relevance
`relevance_label >= 2`.

## Train results

| System | NDCG@5 | NDCG@10 | Precision@5 | Recall@5 | MRR | HitRate@5 |
|---|---:|---:|---:|---:|---:|---:|
| Skills-only | 0.416863550898 | 0.484924386886 | 0.253968253968 | 0.071775728369 | 0.570568783069 | 0.976190476190 |
| Matching 2.0 | 0.464125879995 | 0.514173665863 | 0.277777777778 | 0.078405875554 | 0.705423280423 | 1.000000000000 |
| Initial XGBRanker | 0.873569667492 | 0.870571639458 | 0.958730158730 | 0.270532673676 | 1.000000000000 | 1.000000000000 |

## Validation results

| System | NDCG@5 | NDCG@10 | Precision@5 | Recall@5 | MRR | HitRate@5 |
|---|---:|---:|---:|---:|---:|---:|
| Skills-only | 0.420584605348 | 0.498812704696 | 0.266666666667 | 0.074719842453 | 0.471340388007 | 0.962962962963 |
| Matching 2.0 | 0.478747957727 | 0.532605495359 | 0.281481481481 | 0.078552479839 | 0.656790123457 | 1.000000000000 |
| Initial XGBRanker | 0.843476306190 | 0.827860270814 | 0.925925925926 | 0.261149074852 | 0.962962962963 | 1.000000000000 |

Baseline differences are descriptive only and did not alter the configuration.

## Determinism and verification

CPU execution, one thread, seed `20260724`, fixed data ordering, stable JSON
serialization, and pinned NumPy `2.5.1`, SciPy `1.18.0`, and
XGBoost `3.3.0` control reproducibility. The XGBoost estimator wrapper
uses its bundled minimal compatibility protocol; no optional estimator framework was
installed. The saved model reloaded successfully with maximum absolute prediction error
`0.0` and rank agreement
`100%` across Train and Validation.

## Intended use

This initial model is a Phase 8 research artifact for offline job-ranking experiments and
bounded Phase 9 tuning. AI output is decision support only and requires human oversight.

## Limitations and non-intended uses

- Synthetic training data and handcrafted features may not represent production behavior.
- Validation has only 27 Candidate groups.
- No fairness guarantee or production-quality guarantee is established.
- It must not automatically accept or reject candidates.
- It is not a production model and has not been promoted.
- There is no inference endpoint or Laravel integration.
- The Locked Test was not parsed, predicted, or evaluated; Phase 10 owns that evaluation.
- Phase 9 is reserved for bounded hyperparameter tuning.
