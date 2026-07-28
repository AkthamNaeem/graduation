# Inference Bundle Card

## Identity

- Bundle: `job-rec-inference-bundle-v1`
- Bundle schema: `inference-bundle-schema-v1`
- Builder: `0.1.0`
- Model: `xgbranker-tuned-v1` (`xgboost-json-v1`)
- Feature Schema: `job-rec-features-v1` (103 ordered features)
- Explanation contract: `recommendation-explanation-contract-v1`
- Reason codes: `recommendation-reason-codes-v1`
- Release date: `2026-07-25`

## Runtime contract

The bundle is self-contained. Runtime loading reads only these eight files and
does not access training, tuning, evaluation, explainability source directories,
a database, a cache, or the network. The frozen XGBoost Model is loaded once
during FastAPI startup and is never trained, modified, or saved.

## Scores and explanations

`raw_score` is the frozen ranking margin. `display_score` applies
`validation-minmax-selected-trial-t06-v1` using selected T06 Validation predictions only:
minimum `-4.985489368438721`, maximum
`4.705573558807373`. Values outside that range are clipped
to 0 or 100. It is not a probability or acceptance prediction.

Explanations are exact Tree SHAP attributions aggregated into ten allowlisted
feature groups. At most three positive and three negative reason codes are
returned. Raw Feature names and values are not returned. Attribution is neither
causality, fairness certification, nor a hiring decision.

## Frozen-state evidence

- Test features read: `false`
- Test predictions read: `false`
- Test inference run: `false`
- Test evaluation rerun: `false`
- Training executed: `false`
- Tuning executed: `false`
- Model modified: `false`
- Feature Pipeline modified: `false`

## Limitations

The Model uses synthetic training data and handcrafted Features. Display
normalization is Validation-derived and clips outside that observed range.
Shared-secret deployment, production traffic, and Laravel integration require
later hardening and validation.
