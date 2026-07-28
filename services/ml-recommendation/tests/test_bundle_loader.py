"""Strict Bundle Loader failure-mode and one-time loading tests."""

import hashlib
import json
import shutil
from pathlib import Path
from typing import TYPE_CHECKING, Any, cast

import pytest
from fastapi.testclient import TestClient

from smart_recruitment_ml.bundle.loader import load_bundle
from smart_recruitment_ml.core.config import Settings
from smart_recruitment_ml.main import create_app

if TYPE_CHECKING:
    from fastapi import FastAPI

SERVICE_ROOT = Path(__file__).resolve().parents[1]
SOURCE_BUNDLE = SERVICE_ROOT / "data" / "bundles" / "recommendation" / "v1"
TOKEN = "phase12-local-test-token-20260725-0001"


def _copy_bundle(tmp_path: Path) -> Path:
    destination = tmp_path / "bundle"
    shutil.copytree(SOURCE_BUNDLE, destination)
    return destination


def _json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_bytes())


def _write_json(path: Path, value: object) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def _refresh_artifact(bundle: Path, name: str) -> None:
    manifest_path = bundle / "bundle_manifest.json"
    manifest = _json(manifest_path)
    content = (bundle / name).read_bytes()
    for artifact in manifest["artifacts"]:
        if artifact["path"] == name:
            artifact["bytes"] = len(content)
            artifact["sha256"] = hashlib.sha256(content).hexdigest()
    _write_json(manifest_path, manifest)


def test_valid_bundle_loads_frozen_booster() -> None:
    bundle = load_bundle(SOURCE_BUNDLE)

    assert bundle.manifest.bundle_version == "job-rec-inference-bundle-v1"
    assert bundle.booster.num_features() == 103
    assert tuple(bundle.feature_schema["feature_names"]) == tuple(
        bundle.model_metadata["feature_names"],
    )
    assert set(bundle.feature_group_by_name.values()) == {
        item.feature_group for item in bundle.reason_code_mapping.groups
    }


def test_missing_file_fails(tmp_path: Path) -> None:
    bundle = _copy_bundle(tmp_path)
    (bundle / "BUNDLE_CARD.md").unlink()

    with pytest.raises(ValueError, match="file set"):
        load_bundle(bundle)


def test_file_size_mismatch_fails(tmp_path: Path) -> None:
    bundle = _copy_bundle(tmp_path)
    path = bundle / "BUNDLE_CARD.md"
    path.write_bytes(path.read_bytes()[:-1])

    with pytest.raises(ValueError, match="size"):
        load_bundle(bundle)


def test_checksum_mismatch_fails(tmp_path: Path) -> None:
    bundle = _copy_bundle(tmp_path)
    path = bundle / "BUNDLE_CARD.md"
    content = bytearray(path.read_bytes())
    content[0] = ord("!")
    path.write_bytes(content)

    with pytest.raises(ValueError, match="checksum"):
        load_bundle(bundle)


def test_model_metadata_mismatch_fails(tmp_path: Path) -> None:
    bundle = _copy_bundle(tmp_path)
    path = bundle / "model_metadata.json"
    metadata = _json(path)
    metadata["model_version"] = "wrong"
    _write_json(path, metadata)
    _refresh_artifact(bundle, path.name)

    with pytest.raises(ValueError, match="metadata"):
        load_bundle(bundle)


@pytest.mark.parametrize(
    ("name", "mutation"),
    [
        (
            "feature_schema.json",
            lambda value: value.update({"feature_count": 102}),
        ),
        (
            "feature_schema.json",
            lambda value: value["feature_names"].reverse(),
        ),
        (
            "explanation_contract.json",
            lambda value: value.update({"model_version": "wrong"}),
        ),
    ],
)
def test_frozen_schema_and_explanation_mismatch_fails(
    tmp_path: Path,
    name: str,
    mutation: Any,
) -> None:
    bundle = _copy_bundle(tmp_path)
    path = bundle / name
    value = _json(path)
    mutation(value)
    _write_json(path, value)
    _refresh_artifact(bundle, name)

    with pytest.raises(ValueError, match="hash"):
        load_bundle(bundle)


def test_invalid_score_transform_fails(tmp_path: Path) -> None:
    bundle = _copy_bundle(tmp_path)
    path = bundle / "score_transform.json"
    transform = _json(path)
    transform["maximum_raw_score"] = transform["minimum_raw_score"]
    _write_json(path, transform)
    _refresh_artifact(bundle, path.name)

    with pytest.raises(ValueError, match="range"):
        load_bundle(bundle)


def test_missing_reason_group_fails(tmp_path: Path) -> None:
    bundle = _copy_bundle(tmp_path)
    path = bundle / "reason_code_mapping.json"
    mapping = _json(path)
    mapping["groups"].pop()
    _write_json(path, mapping)
    _refresh_artifact(bundle, path.name)

    with pytest.raises(ValueError, match="groups"):
        load_bundle(bundle)


def test_source_revision_mismatch_fails(tmp_path: Path) -> None:
    bundle = _copy_bundle(tmp_path)
    path = bundle / "bundle_manifest.json"
    manifest = _json(path)
    manifest["model_source_revision"] = "0" * 40
    _write_json(path, manifest)

    with pytest.raises(ValueError, match="source revision"):
        load_bundle(bundle)


def test_loader_is_invoked_only_once_at_startup(
    monkeypatch: pytest.MonkeyPatch,
    inference_settings: Settings,
) -> None:
    import smart_recruitment_ml.main as main_module

    calls = 0
    real_loader = main_module.load_bundle

    def counting_loader(path: Path) -> object:
        nonlocal calls
        calls += 1
        return real_loader(path)

    monkeypatch.setattr(main_module, "load_bundle", counting_loader)
    with TestClient(create_app(inference_settings)) as client:
        assert client.get("/health/ready").status_code == 200
        assert client.get("/health/ready").status_code == 200
        assert cast("FastAPI", client.app).state.runtime_state.load_count == 1

    assert calls == 1


def test_loader_sources_have_no_database_or_network_imports() -> None:
    source = (
        (SERVICE_ROOT / "src" / "smart_recruitment_ml" / "bundle" / "loader.py")
        .read_text(encoding="utf-8")
        .casefold()
    )

    assert "sqlalchemy" not in source
    assert "pymysql" not in source
    assert "redis" not in source
    assert "requests" not in source
