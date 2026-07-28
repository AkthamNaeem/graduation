"""Phase 16 static and unit contracts for the production container package."""

from __future__ import annotations

import hashlib
import importlib.util
import json
from email.message import Message
from pathlib import Path
from typing import TYPE_CHECKING
from urllib.error import HTTPError, URLError

import pytest
import yaml  # type: ignore[import-untyped]

if TYPE_CHECKING:
    from types import ModuleType

SERVICE_ROOT = Path(__file__).resolve().parents[1]
REPOSITORY_ROOT = SERVICE_ROOT.parents[1]
DOCKERFILE = SERVICE_ROOT / "Dockerfile"
DOCKERIGNORE = SERVICE_ROOT / ".dockerignore"
COMPOSE = REPOSITORY_ROOT / "compose.ml.yml"
MANIFEST = SERVICE_ROOT / "deployment/container/v1/container_manifest.json"
BUNDLE = SERVICE_ROOT / "data/bundles/recommendation/v1"


def _module(name: str, path: Path) -> ModuleType:
    specification = importlib.util.spec_from_file_location(name, path)
    assert specification is not None
    assert specification.loader is not None
    module = importlib.util.module_from_spec(specification)
    specification.loader.exec_module(module)
    return module


def _sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def test_dockerfile_runtime_contract() -> None:
    text = DOCKERFILE.read_text(encoding="utf-8")
    lowered = text.casefold()

    assert text.count("\nFROM ") == 2
    assert "python:3.12.10-slim@sha256:" in text
    assert " AS builder" in text
    assert " AS runtime" in text
    assert "USER 10001:10001" in text
    assert "WORKDIR /app" in text
    assert "ML_BUNDLE_DIR=/app/data/bundles/recommendation/v1" in text
    assert "COPY --chown=0:0 data/bundles/recommendation/v1" in text
    assert "COPY --chown=0:0 container/start.py container/healthcheck.py" in text
    assert "HEALTHCHECK " in text
    assert 'ENTRYPOINT ["python", "/app/container/start.py"]' in text
    assert "EXPOSE 8100" in text
    assert "PYTHONDONTWRITEBYTECODE=1" in text
    assert "PYTHONUNBUFFERED=1" in text
    assert "org.opencontainers.image.title" in text
    assert "org.opencontainers.image.revision" in text
    assert "com.workeyx.ml.bundle.version" in text
    assert "COPY . " not in text
    assert "ADD . " not in text
    assert "ml_service_token" not in lowered
    assert "arg token" not in lowered
    assert "--reload" not in lowered
    assert "pip install" not in text[text.index("ENTRYPOINT") :]
    assert "tests" not in text


def test_dockerignore_protects_context_and_allows_only_runtime_bundle() -> None:
    lines = {
        line.strip()
        for line in DOCKERIGNORE.read_text(encoding="utf-8").splitlines()
        if line.strip() and not line.startswith("#")
    }

    for required in {
        ".git",
        ".venv",
        "__pycache__",
        ".pytest_cache",
        ".mypy_cache",
        ".ruff_cache",
        ".coverage",
        "tests",
        ".env",
        ".env.*",
        "*.pem",
        "*.key",
        "*.crt",
        "data/synthetic",
        "data/features",
        "data/splits",
        "data/models",
        "data/evaluations",
        "**/train.jsonl",
        "**/validation.jsonl",
        "**/test.jsonl",
        "**/test_lock.json",
        "**/test_predictions.jsonl",
        "**/final_train_validation_predictions.jsonl",
        "**/selected_validation_predictions.jsonl",
        "**/local_explanations.jsonl",
        "**/candidates.jsonl",
        "**/jobs.jsonl",
        "**/pairs.jsonl",
    }:
        assert required in lines

    assert "*" in lines
    assert "!data/bundles/recommendation/v1/**" in lines
    assert "!src/**" in lines
    assert "!container/start.py" in lines
    assert "!container/healthcheck.py" in lines


def test_startup_rejects_invalid_ports_and_locks_uvicorn(monkeypatch, capsys) -> None:
    startup = _module("phase16_start", SERVICE_ROOT / "container/start.py")

    assert startup.parse_port({"PORT": "8100"}) == 8100
    assert startup.parse_port({}) == 8100
    for raw in ("not-a-port", "0", "65536", "-1"):
        with pytest.raises(ValueError, match="between 1 and 65535"):
            startup.parse_port({"PORT": raw})

    command = startup.uvicorn_command(8100)
    assert command[:4] == ["python", "-m", "uvicorn", "smart_recruitment_ml.main:app"]
    assert command[command.index("--workers") + 1] == "1"
    assert command[command.index("--host") + 1] == "0.0.0.0"
    assert command[command.index("--port") + 1] == "8100"
    assert command[command.index("--timeout-graceful-shutdown") + 1] == "30"
    assert "--no-server-header" in command
    assert "--reload" not in command

    captured: list[str] = []
    monkeypatch.setattr(startup, "execute", lambda value: captured.extend(value))
    monkeypatch.setenv("PORT", "8100")
    monkeypatch.setenv("ML_SERVICE_TOKEN", "not-observed-by-startup")
    assert startup.main() == 0
    assert captured == command
    output = capsys.readouterr()
    assert output.out == ""
    assert output.err == ""
    assert "ML_SERVICE_TOKEN" not in (SERVICE_ROOT / "container/start.py").read_text()
    assert "os.execvp" in (SERVICE_ROOT / "container/start.py").read_text()


class _Response:
    def __init__(self, status: int) -> None:
        self.status = status

    def __enter__(self) -> _Response:
        return self

    def __exit__(self, *_args: object) -> None:
        return None


def test_healthcheck_requires_ready_200_and_prints_no_body(monkeypatch, capsys) -> None:
    healthcheck = _module(
        "phase16_healthcheck",
        SERVICE_ROOT / "container/healthcheck.py",
    )

    assert healthcheck.parse_port({"PORT": "8100"}) == 8100
    for raw in ("invalid", "0", "65536"):
        with pytest.raises(ValueError, match="Invalid healthcheck port"):
            healthcheck.parse_port({"PORT": raw})

    monkeypatch.setattr(healthcheck, "urlopen", lambda *_args, **_kwargs: _Response(200))
    assert healthcheck.ready(8100)
    monkeypatch.setattr(healthcheck, "urlopen", lambda *_args, **_kwargs: _Response(503))
    assert not healthcheck.ready(8100)

    def unavailable(*_args: object, **_kwargs: object) -> None:
        raise URLError("not ready")

    monkeypatch.setattr(healthcheck, "urlopen", unavailable)
    assert not healthcheck.ready(8100)

    def timeout(*_args: object, **_kwargs: object) -> None:
        raise TimeoutError

    monkeypatch.setattr(healthcheck, "urlopen", timeout)
    assert not healthcheck.ready(8100)

    def service_unavailable(*_args: object, **_kwargs: object) -> None:
        raise HTTPError("safe", 503, "safe", Message(), None)

    monkeypatch.setattr(healthcheck, "urlopen", service_unavailable)
    assert not healthcheck.ready(8100)
    output = capsys.readouterr()
    assert output.out == ""
    assert output.err == ""


def test_compose_contract_is_internal_and_hardened() -> None:
    text = COMPOSE.read_text(encoding="utf-8")
    compose = yaml.safe_load(text)
    service = compose["services"]["ml-recommendation"]

    assert compose["name"] == "workeyx-ml"
    assert service["build"]["context"] == "./services/ml-recommendation"
    assert service["image"] == "workeyx/ml-recommendation:0.2.0-phase16"
    assert service["ports"] == ["127.0.0.1:8100:8100"]
    assert service["read_only"] is True
    assert service["tmpfs"] == ["/tmp:rw,noexec,nosuid,size=67108864"]
    assert service["cap_drop"] == ["ALL"]
    assert service["security_opt"] == ["no-new-privileges:true"]
    assert service["init"] is True
    assert service["stop_grace_period"] == "30s"
    assert "healthcheck" in service
    assert "ML_SERVICE_TOKEN is required" in service["environment"]["ML_SERVICE_TOKEN"]
    assert "${ML_SERVICE_TOKEN:" in text
    assert "privileged" not in service
    assert "network_mode" not in service
    assert "volumes" not in service
    assert "redis" not in text.casefold()
    assert "database" not in text.casefold()


def test_container_manifest_is_deterministic_secret_free_and_current() -> None:
    raw = MANIFEST.read_text(encoding="utf-8")
    manifest = json.loads(raw)

    assert raw == json.dumps(manifest, indent=2, sort_keys=True) + "\n"
    assert manifest["container_packaging_version"] == "ml-container-v1"
    assert manifest["service_version"] == "0.2.0"
    assert manifest["api_contract_version"] == "recommendation-ranking-api-v1"
    assert manifest["bundle_version"] == "job-rec-inference-bundle-v1"
    assert manifest["model_version"] == "xgbranker-tuned-v1"
    assert manifest["feature_schema_version"] == "job-rec-features-v1"
    assert manifest["runtime_python_version"] == "3.12.10"
    assert manifest["runtime_user"] == {"gid": 10001, "uid": 10001}
    assert manifest["worker_count"] == 1
    assert manifest["exposed_port"] == 8100
    assert manifest["read_only_supported"] is True
    assert manifest["training_executed"] is False
    assert manifest["model_modified"] is False
    assert manifest["test_non_usage"] == {
        "locked_test_opened": False,
        "locked_test_predictions_read": False,
        "locked_test_rerun": False,
    }
    assert manifest["required_environment_names"] == ["ML_SERVICE_TOKEN"]
    assert "image_id" not in raw.casefold()
    assert "timestamp" not in raw.casefold()
    assert "c:\\" not in raw.casefold()
    assert "/home/" not in raw.casefold()
    assert "replace-with" not in raw.casefold()
    assert "temporary" not in raw.casefold()

    assert manifest["dockerfile_sha256"] == _sha256(DOCKERFILE)
    assert manifest["dockerignore_sha256"] == _sha256(DOCKERIGNORE)
    assert manifest["startup_file_sha256"] == _sha256(
        SERVICE_ROOT / "container/start.py",
    )
    assert manifest["healthcheck_file_sha256"] == _sha256(
        SERVICE_ROOT / "container/healthcheck.py",
    )
    assert manifest["architecture_sha256"] == (
        "60eb219152ce26b525735ed65564f667d403bf438f29000b4ece90d65950553f"
    )

    bundle_files = {path.name: _sha256(path) for path in BUNDLE.iterdir() if path.is_file()}
    assert manifest["bundle_file_hashes"] == dict(sorted(bundle_files.items()))
    assert len(bundle_files) == 8
    assert manifest["dependency_versions"]["fastapi"] == "0.139.2"
    assert manifest["dependency_versions"]["pydantic"] == "2.13.4"
    assert manifest["dependency_versions"]["numpy"] == "2.5.1"
    assert manifest["dependency_versions"]["scipy"] == "1.18.0"
    assert manifest["dependency_versions"]["xgboost"].startswith("3.3.0")
