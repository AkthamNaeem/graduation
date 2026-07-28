"""Feature group mapping frozen from schema metadata before attribution is inspected."""

from __future__ import annotations

from collections import Counter
from typing import Any, Final

EXPECTED_GROUP_COUNTS: Final = {
    "career_level": 16,
    "domain_compatibility": 29,
    "education": 14,
    "experience": 6,
    "interactions": 4,
    "missing_indicators": 8,
    "nice_transferable_skills": 5,
    "preferences": 10,
    "required_skills": 7,
    "text_alignment": 4,
}


def build_feature_group_mapping(
    feature_names: list[str],
    definitions: list[dict[str, Any]],
) -> dict[str, str]:
    """Return the one-to-one mapping declared by the frozen schema family metadata."""
    if len(feature_names) != 103 or len(definitions) != 103:
        raise ValueError("Feature group mapping requires exactly 103 schema features.")
    definition_names = [str(item.get("name")) for item in definitions]
    if definition_names != feature_names:
        raise ValueError("Feature definitions do not match locked schema order.")
    mapping: dict[str, str] = {}
    for definition in definitions:
        name = str(definition["name"])
        family = definition.get("family")
        if not isinstance(family, str) or not family:
            raise ValueError(f"Missing frozen feature family for {name}.")
        if name in mapping:
            raise ValueError(f"Duplicate feature mapping for {name}.")
        mapping[name] = family
    counts = Counter(mapping.values())
    if dict(sorted(counts.items())) != EXPECTED_GROUP_COUNTS:
        raise ValueError(f"Unexpected frozen feature groups: {dict(sorted(counts.items()))}")
    return mapping
