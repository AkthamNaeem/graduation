"""Minimal PID 1 handoff for the fixed single-worker Uvicorn runtime."""

from __future__ import annotations

import os
import sys
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from collections.abc import Mapping, Sequence


def parse_port(environment: Mapping[str, str] | None = None) -> int:
    """Return a safe TCP port without exposing the source environment."""
    source = os.environ if environment is None else environment
    raw_port = source.get("PORT", "8100")
    try:
        port = int(raw_port)
    except ValueError as error:
        raise ValueError("PORT must be an integer between 1 and 65535.") from error
    if not 1 <= port <= 65535:
        raise ValueError("PORT must be an integer between 1 and 65535.")
    return port


def uvicorn_command(port: int) -> list[str]:
    """Build the locked one-worker production command."""
    return [
        "python",
        "-m",
        "uvicorn",
        "smart_recruitment_ml.main:app",
        "--host",
        "0.0.0.0",
        "--port",
        str(port),
        "--workers",
        "1",
        "--no-server-header",
        "--timeout-graceful-shutdown",
        "30",
    ]


def execute(command: Sequence[str]) -> None:
    """Replace PID 1 so Uvicorn receives termination signals directly."""
    os.execvp(command[0], list(command))


def main() -> int:
    try:
        port = parse_port()
    except ValueError as error:
        print(str(error), file=sys.stderr)
        return 2
    execute(uvicorn_command(port))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
