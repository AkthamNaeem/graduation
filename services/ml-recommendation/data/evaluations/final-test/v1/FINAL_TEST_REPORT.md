# Locked Final Test Report

## Evaluation contract

This is the first and only Phase 10 Final Test evaluation. The Test remained locked
through Phases 6-9 and was opened only after every immutable source and frozen-model
gate passed. Exactly 1,620 records across 27 Candidate groups were parsed once.

The five predeclared systems were evaluated: Skills-only, the actual Laravel
MatchingService 2.0 through its isolated bridge, the independent Python Matching 2.0
oracle, the frozen Initial XGBRanker, and the frozen Tuned XGBRanker. Both models were
loaded only. No training, tuning, feature change, model modification, or second Test
prediction run occurred.

## Final Test metrics

| System | NDCG@5 | NDCG@10 | Precision@5 | Recall@5 | MRR | HitRate@5 |
|---|---:|---:|---:|---:|---:|---:|
| A — Skills-only | 0.437209031004 | 0.490018490179 | 0.251851851852 | 0.072574023112 | 0.591975308642 | 1.000000000000 |
| B — Laravel Matching 2.0 | 0.498045684283 | 0.525541603736 | 0.274074074074 | 0.078880639325 | 0.794444444444 | 1.000000000000 |
| C — Python Matching 2.0 | 0.498045684283 | 0.525541603736 | 0.274074074074 | 0.078880639325 | 0.794444444444 | 1.000000000000 |
| D — Initial XGBRanker | 0.797711275000 | 0.821207971452 | 0.888888888889 | 0.256810667737 | 0.981481481481 | 1.000000000000 |
| E — Tuned XGBRanker | 0.850991293173 | 0.858134578455 | 0.933333333333 | 0.270272904483 | 0.981481481481 | 1.000000000000 |

## Laravel-Python parity

- Pair count: `1620`
- Maximum/mean score error: `0.0` /
  `0.0`
- Component mismatches: `{"cosine_similarity": 0, "education": 0, "experience": 0, "nice_to_have_skills": 0, "required_skills": 0, "text_similarity": 0}`
- Rank agreement: `100%`
- Database queries/writes: `0/0`
- Passed: `true`

## Frozen-model comparison

- Tuned versus Skills-only: `{'NDCG@5': 0.4137822621685546, 'NDCG@10': 0.3681160882758429, 'Precision@5': 0.6814814814814815, 'Recall@5': 0.19769888137191197, 'MRR': 0.3895061728395063, 'HitRate@5': 0.0}`
- Tuned versus Matching 2.0: `{'NDCG@5': 0.3529456088893787, 'NDCG@10': 0.33259297471911276, 'Precision@5': 0.6592592592592592, 'Recall@5': 0.191392265158175, 'MRR': 0.1870370370370371, 'HitRate@5': 0.0}`
- Tuned versus Initial XGBRanker: `{'NDCG@5': 0.053280018172929156, 'NDCG@10': 0.03692660700234629, 'Precision@5': 0.04444444444444451, 'Recall@5': 0.013462236746550449, 'MRR': 0.0, 'HitRate@5': 0.0}`
- Quality disposition: `PROMOTE_TO_EXPLAINABILITY`

The disposition is the predeclared Phase 10 rule and did not trigger model selection
or modification.

## Controlled Recovery Disclosure

The first attempt failed before artifact publication. No metrics or quality
disposition were available from that attempt. Only the generic SystemScore
conversion defect was corrected. All models, features, parameters, baselines, and
evaluation rules remained frozen. This recovery run was explicitly authorized by
the user. No further Test execution is permitted.

## Limitations and next phase

- The Test is synthetic and contains only 27 Candidate groups.
- Features are handcrafted; selection overfit may remain.
- There is no fairness guarantee or production-quality guarantee.
- This is not real production-traffic evaluation.
- AI output is decision support only and must not make automatic hiring decisions.
- Test results must not be used for retraining or further model improvement.
- Phase 11 is reserved for explainability; the model remains unchanged after Test.
