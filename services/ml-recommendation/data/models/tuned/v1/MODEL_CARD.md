# Tuned XGBRanker Model Card

## Model

- Name/version: `xgbranker-tuned-v1`
- Format: `xgboost-json-v1`
- Status: tuned candidate model only; not production-ready
- Bounded tuning run: `xgbranker-bounded-tuning-v1`
- Selected configuration: `T06`

Phase 9 evaluated exactly eight fixed configurations, T00 through T07. T00 exactly
reproduced the Phase 8 Initial Model. Selection used Validation NDCG@10, then ties
within `1e-12` used Validation NDCG@5, Validation MRR, fewer estimators, lower depth,
and lexicographic configuration ID.

## Selected hyperparameters

- `objective`: `"rank:ndcg"`
- `eval_metric`: `["ndcg@5","ndcg@10"]`
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
- `reg_alpha`: `0.0`
- `n_estimators`: `300`
- `learning_rate`: `0.05`
- `max_depth`: `5`
- `min_child_weight`: `1.0`
- `gamma`: `0.0`
- `subsample`: `1.0`
- `colsample_bytree`: `1.0`
- `reg_lambda`: `1.0`

## Validation comparison

| System | NDCG@5 | NDCG@10 | Precision@5 | Recall@5 | MRR | HitRate@5 |
|---|---:|---:|---:|---:|---:|---:|
| Skills-only | 0.420584605348 | 0.498812704696 | 0.266666666667 | 0.074719842453 | 0.471340388007 | 0.962962962963 |
| Matching 2.0 | 0.478747957727 | 0.532605495359 | 0.281481481481 | 0.078552479839 | 0.656790123457 | 1.000000000000 |
| Initial XGBRanker | 0.843476306190 | 0.827860270814 | 0.925925925926 | 0.261149074852 | 0.962962962963 | 1.000000000000 |
| Selected Trial (T06) | 0.844066178654 | 0.845593500405 | 0.933333333333 | 0.262864761126 | 0.962962962963 | 1.000000000000 |

The selected deltas versus Initial XGBRanker are
`{'NDCG@5': 0.0005898724639947783, 'NDCG@10': 0.01773322959077228, 'Precision@5': 0.007407407407407418, 'Recall@5': 0.0017156862745098533, 'MRR': 0.0, 'HitRate@5': 0.0}` and versus Matching 2.0 are
`{'NDCG@5': 0.36531822092687816, 'NDCG@10': 0.31298800504663793, 'Precision@5': 0.6518518518518519, 'Recall@5': 0.184312281287349, 'MRR': 0.3061728395061728, 'HitRate@5': 0.0}`.

## Final retraining

The final candidate model was fitted once on Train + Validation: 153 Candidate groups,
9,180 records, 60 records per group, and 103 handcrafted features. There was no eval
set, early stopping, cross-validation, SHAP, calibration, threshold selection, or Test
use in final retraining.

## Intended use and limitations

- Offline research and Phase 10 locked final evaluation only.
- AI is decision support and is not the decision-maker.
- Synthetic data and handcrafted features may not represent production behavior.
- Validation contains only 27 Candidate groups and selection may overfit it.
- The fixed bounded search does not establish global optimality.
- Reproducibility is bounded by the pinned dependency versions and platform.
- There is no fairness guarantee and no production-quality guarantee.
- It must not automatically accept or reject Candidates.
- The Locked Test was not parsed, predicted, or evaluated; Phase 10 alone owns it.
- No configuration may be changed after observing the Phase 10 Test result.
