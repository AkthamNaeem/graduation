"""Pure, locale-independent normalization helpers for Feature Pipeline v1."""

from __future__ import annotations

import re
import unicodedata
from dataclasses import dataclass
from typing import TYPE_CHECKING, Final

if TYPE_CHECKING:
    from collections.abc import Iterable

    from smart_recruitment_ml.schemas.features import CandidateSkillInput, RequiredSkillInput

_WHITESPACE_RE: Final = re.compile(r"\s+")
_WORD_RE: Final = re.compile(
    "[^\\W_]+(?:['\\N{RIGHT SINGLE QUOTATION MARK}][^\\W_]+)?",
    re.UNICODE,
)
_HYPHENS: Final = str.maketrans(
    {
        "\u058a": "-",
        "\u05be": "-",
        "\u1400": "-",
        "\u1806": "-",
        "\u2010": "-",
        "\u2011": "-",
        "\u2012": "-",
        "\u2013": "-",
        "\u2014": "-",
        "\u2015": "-",
        "\u2e17": "-",
        "\u2e1a": "-",
        "\u2e3a": "-",
        "\u2e3b": "-",
        "\u2e40": "-",
        "\u301c": "-",
        "\u3030": "-",
        "\u30a0": "-",
        "\ufe31": "-",
        "\ufe32": "-",
        "\ufe58": "-",
        "\ufe63": "-",
        "\uff0d": "-",
    },
)


@dataclass(frozen=True, slots=True)
class NormalizedCandidateSkill:
    """Merged Candidate skill values used by the transformer."""

    name: str
    proficiency: float
    years_experience: float


@dataclass(frozen=True, slots=True)
class NormalizedRequiredSkill:
    """Merged required-skill values used by the transformer."""

    name: str
    weight: float


def normalize_text(value: str | None) -> str:
    """Apply NFKC, casefolding, hyphen normalization, and whitespace collapse."""
    if value is None:
        return ""
    normalized = unicodedata.normalize("NFKC", value).casefold().translate(_HYPHENS)
    return _WHITESPACE_RE.sub(" ", normalized).strip()


def normalize_skill_name(value: str | None) -> str:
    """Normalize a skill while making spacing around hyphens deterministic."""
    normalized = normalize_text(value)
    return re.sub(r"\s*-\s*", "-", normalized)


def tokenize(value: str | None, *, limit: int | None = None) -> tuple[str, ...]:
    """Return deterministic Unicode-aware word tokens with an optional bound."""
    tokens = tuple(match.group(0) for match in _WORD_RE.finditer(normalize_text(value)))
    return tokens if limit is None else tokens[: max(0, limit)]


def normalize_categories(values: Iterable[str] | None) -> tuple[str, ...]:
    """Normalize, de-duplicate, and sort category values."""
    if values is None:
        return ()
    return tuple(sorted({item for value in values if (item := normalize_text(value))}))


def merge_candidate_skills(
    skills: Iterable[CandidateSkillInput] | None,
) -> tuple[NormalizedCandidateSkill, ...]:
    """Merge duplicate Candidate skills using independent maxima."""
    merged: dict[str, tuple[float, float]] = {}
    for skill in skills or ():
        name = normalize_skill_name(skill.name)
        if not name:
            continue
        proficiency = float(skill.proficiency or 0.0)
        years = float(skill.years_experience or 0.0)
        previous = merged.get(name, (0.0, 0.0))
        merged[name] = (max(previous[0], proficiency), max(previous[1], years))
    return tuple(
        NormalizedCandidateSkill(name=name, proficiency=values[0], years_experience=values[1])
        for name, values in sorted(merged.items())
    )


def merge_required_skills(
    skills: Iterable[RequiredSkillInput] | None,
) -> tuple[NormalizedRequiredSkill, ...]:
    """Merge duplicate required skills using the highest weight."""
    merged: dict[str, float] = {}
    for skill in skills or ():
        name = normalize_skill_name(skill.name)
        if not name:
            continue
        merged[name] = max(merged.get(name, 0.0), float(skill.weight or 0.0))
    return tuple(
        NormalizedRequiredSkill(name=name, weight=weight) for name, weight in sorted(merged.items())
    )


def normalize_job_skills(
    required: Iterable[RequiredSkillInput] | None,
    nice_to_have: Iterable[str] | None,
) -> tuple[tuple[NormalizedRequiredSkill, ...], tuple[str, ...]]:
    """Normalize Job skills, giving required skills precedence over nice-to-have."""
    merged_required = merge_required_skills(required)
    required_names = {skill.name for skill in merged_required}
    normalized_nice = {
        name
        for value in nice_to_have or ()
        if (name := normalize_skill_name(value)) and name not in required_names
    }
    return merged_required, tuple(sorted(normalized_nice))
