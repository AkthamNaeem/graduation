# Model Explainability Report

## 1. Scope

Phase 11 explains the frozen tuned ranker using Train and Validation only.

## 2. Frozen model details

- Model: `xgbranker-tuned-v1`
- SHA-256: `3abd74137bc8881667643f31a658c790ef6712359d7802ea7fcffa0c4cf9e26e`
- Objective: `rank:ndcg`
- Selected configuration: `T06`

## 3. Explainability method

Exact native XGBoost Tree SHAP contributions were requested with `pred_contribs=True`, `approx_contribs=False`, and `strict_shape=True`. No SHAP dependency or interaction values were used.

## 4. Exact SHAP contribution contract

The 103 feature contributions exclude the separate bias term. Values and model scores are on the raw ranking-margin scale.

## 5. Additivity verification

- Rows: 9180
- Shape: `[9180, 1, 104]`
- Maximum absolute error: 3.79812991014e-06
- Mean absolute error: 8.60623600098e-07
- Failed rows: 0

## 6. Global top Features

| Rank | Feature | Group | Mean Abs | Share | Mean Signed |
| ---: | --- | --- | ---: | ---: | ---: |
| 1 | `domain_mismatch` | `domain_compatibility` | 1.019049694 | 0.2335528979 | -0.6196571542 |
| 2 | `nice_to_have_skill_coverage` | `nice_transferable_skills` | 0.6243079803 | 0.143083246 | -0.4072220636 |
| 3 | `career_level_distance` | `career_level` | 0.3282069942 | 0.07522076214 | -0.2052360608 |
| 4 | `critical_skill_experience_interaction` | `interactions` | 0.2434878411 | 0.05580423727 | -0.1372160676 |
| 5 | `matched_required_skill_mean_proficiency` | `required_skills` | 0.2272086843 | 0.05207326687 | -0.1567631908 |
| 6 | `education_requirement_met` | `education` | 0.1975850508 | 0.04528391646 | -0.09103314208 |
| 7 | `required_skill_overlap_ratio` | `required_skills` | 0.1744697985 | 0.03998620213 | -0.1470801462 |
| 8 | `job_minimum_experience_normalized` | `experience` | 0.1701830448 | 0.03900373411 | -0.1076091729 |
| 9 | `skill_career_alignment_interaction` | `interactions` | 0.1634769881 | 0.03746679337 | -0.1098781288 |
| 10 | `experience_gap_signed` | `experience` | 0.128819914 | 0.02952384405 | -0.06302756414 |
| 11 | `skill_work_mode_interaction` | `interactions` | 0.09323350024 | 0.02136790217 | -0.05419716476 |
| 12 | `matched_required_skill_mean_years` | `required_skills` | 0.07897537224 | 0.01810012521 | -0.05633776087 |
| 13 | `domain_adjacent_match` | `domain_compatibility` | 0.0661463203 | 0.01515987384 | -0.04355739412 |
| 14 | `weighted_required_skill_coverage` | `required_skills` | 0.06577227802 | 0.01507414823 | -0.02101555522 |
| 15 | `candidate_experience_normalized` | `experience` | 0.06097831912 | 0.01397543538 | -0.02473346065 |
| 16 | `employment_type_match` | `preferences` | 0.0603963854 | 0.01384206376 | -0.007583766978 |
| 17 | `job_domain__backend_engineering` | `domain_compatibility` | 0.05690871716 | 0.01304273569 | -0.01453510108 |
| 18 | `education_level_distance` | `education` | 0.05340674108 | 0.01224012845 | -0.01716174442 |
| 19 | `job_domain__ui_ux_design` | `domain_compatibility` | 0.04973699768 | 0.01139907113 | -0.02492456941 |
| 20 | `work_mode_match` | `preferences` | 0.04692879366 | 0.0107554674 | -0.001262056154 |

## 7. Feature Group importance

| Rank | Group | Features | Mean Abs Sum | Share | Mean Signed |
| ---: | --- | ---: | ---: | ---: | ---: |
| 1 | `domain_compatibility` | 29 | 1.336744172 | 0.3063643285 | -0.7217803025 |
| 2 | `nice_transferable_skills` | 5 | 0.6332817402 | 0.1451399147 | -0.409867975 |
| 3 | `required_skills` | 7 | 0.5569500393 | 0.1276456845 | -0.3822242888 |
| 4 | `interactions` | 4 | 0.5409606536 | 0.1239811258 | -0.3125801092 |
| 5 | `career_level` | 16 | 0.4247763868 | 0.09735320733 | -0.2365053146 |
| 6 | `experience` | 6 | 0.359981278 | 0.08250301353 | -0.1953701977 |
| 7 | `education` | 14 | 0.3545318197 | 0.08125406877 | -0.1396942828 |
| 8 | `preferences` | 10 | 0.1266618913 | 0.02902925339 | -0.01040319276 |
| 9 | `text_alignment` | 4 | 0.02936206965 | 0.006729403383 | 0.002189615504 |
| 10 | `missing_indicators` | 8 | 0 | 0 | 0 |

## 8. Train/Validation stability

- Spearman: 0.9993190704
- Top-10 overlap/Jaccard: 10 / 1
- Top-20 overlap/Jaccard: 20 / 1
- Descriptive only; no model decision was made from stability.

## 9. Local selection policy

For all 27 validation-origin candidates, frozen ranks 1, 5, 10, and 60 produce 108 explanations. Selection did not use labels or contributions.

## 10. Local explanation examples

- Rank 1, pair `pair_cand_0003_job_0123`: score 1.928964734; positive [`domain_mismatch`, `critical_skill_experience_interaction`, `required_skill_overlap_ratio`, `education_requirement_met`, `career_level_distance`]; negative [`nice_to_have_skill_coverage`, `skill_work_mode_interaction`, `work_mode_match`, `candidate_career_level__mid`, `job_work_mode__remote`].
- Rank 10, pair `pair_cand_0003_job_0146`: score -0.09096557647; positive [`domain_mismatch`, `career_level_distance`, `domain_adjacent_match`, `employment_type_match`, `education_requirement_met`]; negative [`nice_to_have_skill_coverage`, `experience_gap_signed`, `matched_required_skill_mean_proficiency`, `critical_skill_experience_interaction`, `skill_work_mode_interaction`].
- Rank 60, pair `pair_cand_0003_job_0137`: score -4.402474403; positive [`education_requirement_met`, `work_mode_match`, `employment_type_match`, `candidate_domain__mobile_development`, `job_education_level__bachelor`]; negative [`domain_mismatch`, `nice_to_have_skill_coverage`, `career_level_distance`, `job_minimum_experience_normalized`, `matched_required_skill_mean_proficiency`].

## 11. Explanation Contract

`recommendation-explanation-contract-v1` is prepared for Phase 12 consumers. It limits each direction to five traceable factors.

## 12. Final Test aggregate disposition

`PROMOTE_TO_EXPLAINABILITY` (aggregate Phase 10 artifact only).

## 13. Test non-usage confirmation

Test features and saved Test predictions were not read; no Test explanations were generated and the Final Test evaluation was not rerun.

## 14. No model or Feature modification

Training, tuning, model modification, feature modification, calibration, and selection changes were not executed.

## 15. No SHAP interaction values

Only single-feature exact contributions were computed.

## 16. No new dependency

Native XGBoost contributions were used; no dependency was added.

## 17. Limitations

Synthetic development data, in-sample final-model explanation, handcrafted features, hidden interactions, no Test explanations, and no Production traffic.

## 18. Fairness disclaimer

These artifacts do not certify fairness.

## 19. Non-causal interpretation

SHAP values attribute the model score; they do not establish causality.

## 20. AI assistant-only rule

Explanations support human review and must not make automatic hiring decisions.

## 21. Readiness for Phase 12

READY FOR PHASE 12
