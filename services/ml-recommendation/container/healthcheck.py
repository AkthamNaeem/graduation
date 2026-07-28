"""Body-free readiness probe implemented with the Python standard library."""

from __future__ import annotations

import os
from typing import TYPE_CHECKING
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

if TYPE_CHECKING:
    from collections.abc import Mapping


def parse_port(environment: Mapping[str, str] | None = None) -> int:
    source = os.environ if environment is None else environment
    raw_port = source.get("PORT", "8100")
    try:
        port = int(raw_port)
    except ValueError as error:
        raise ValueError("Invalid healthcheck port.") from error
    if not 1 <= port <= 65535:
        raise ValueError("Invalid healthcheck port.")
    return port


def ready(port: int, timeout: float = 2.0) -> bool:
    request = Request(
        f"http://127.0.0.1:{port}/health/ready",
        headers={"Accept": "application/json"},
        method="GET",
    )
    try:
        with urlopen(request, timeout=timeout) as response:
            return response.status == 200
    except (HTTPError, URLError, TimeoutError, OSError):
        return False


def main() -> int:
    try:
        port = parse_port()
    except ValueError:
        return 1
    return 0 if ready(port) else 1


if __name__ == "__main__":
    raise SystemExit(main())
