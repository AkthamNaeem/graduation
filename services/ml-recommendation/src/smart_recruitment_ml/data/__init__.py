"""Deterministic synthetic Dataset generation."""

from smart_recruitment_ml.data.generator import (
    ARCHITECTURE_SHA256,
    DATASET_SCHEMA_VERSION,
    DATASET_VERSION,
    DEFAULT_CANDIDATE_COUNT,
    DEFAULT_JOB_COUNT,
    DEFAULT_PAIRS_PER_CANDIDATE,
    DEFAULT_RANDOM_SEED,
    GENERATOR_VERSION,
    SOURCE_REVISION,
    DatasetRecords,
    GenerationConfig,
    generate_dataset,
    write_dataset,
)

__all__ = [
    "ARCHITECTURE_SHA256",
    "DATASET_SCHEMA_VERSION",
    "DATASET_VERSION",
    "DEFAULT_CANDIDATE_COUNT",
    "DEFAULT_JOB_COUNT",
    "DEFAULT_PAIRS_PER_CANDIDATE",
    "DEFAULT_RANDOM_SEED",
    "GENERATOR_VERSION",
    "SOURCE_REVISION",
    "DatasetRecords",
    "GenerationConfig",
    "generate_dataset",
    "write_dataset",
]
