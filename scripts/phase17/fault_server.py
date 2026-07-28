"""Local Phase 17 ML provider fault server.

The server uses only the Python standard library, binds to loopback, never
logs request bodies or headers, and reads its active behavior from a temporary
mode file controlled by the PowerShell E2E coordinator.
"""

from __future__ import annotations

import argparse
import json
import socket
import time
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

EXPLANATION_NOTE = "Model attribution only; not a probability or hiring decision."


class FaultServer(ThreadingHTTPServer):
    """Threading server carrying process-local control paths."""

    mode_file: Path
    events_file: Path


class FaultHandler(BaseHTTPRequestHandler):
    """Return one controlled provider behavior without recording payloads."""

    server: FaultServer

    def do_GET(self) -> None:
        if self.path == "/health/live":
            self._json(HTTPStatus.OK, {"status": "ok"})
            return
        self._json(HTTPStatus.NOT_FOUND, {"code": "NOT_FOUND"})

    def do_POST(self) -> None:
        mode = self.server.mode_file.read_text(encoding="utf-8").strip()
        self.server.events_file.parent.mkdir(parents=True, exist_ok=True)
        with self.server.events_file.open(
            "a", encoding="utf-8", newline="\n"
        ) as events:
            events.write(f"{mode}\n")

        length = self.headers.get("Content-Length", "0")
        try:
            body_size = min(max(int(length), 0), 2_000_000)
        except ValueError:
            body_size = 0
        raw_body = self.rfile.read(body_size)

        if mode == "timeout":
            time.sleep(3)
            self._json(HTTPStatus.SERVICE_UNAVAILABLE, {"code": "TIMEOUT_COMPLETE"})
            return
        if mode == "abrupt_close":
            self.connection.shutdown(socket.SHUT_RDWR)
            self.connection.close()
            return
        if mode in {"401", "422", "429", "500", "503"}:
            self._json(
                HTTPStatus(int(mode)),
                {"code": "SAFE_PROVIDER_ERROR"},
            )
            return
        if mode == "empty":
            self.send_response(HTTPStatus.OK)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        if mode == "invalid_json":
            self._bytes(HTTPStatus.OK, b"{invalid", "application/json")
            return

        try:
            payload = json.loads(raw_body)
        except (UnicodeDecodeError, json.JSONDecodeError):
            self._json(HTTPStatus.UNPROCESSABLE_ENTITY, {"code": "INVALID_REQUEST"})
            return
        response = self._valid_response(payload)
        self._mutate(response, mode)
        self._json(HTTPStatus.OK, response)

    def log_message(self, _format: str, *_args: Any) -> None:
        """Suppress default access logging, including paths and headers."""

    def _valid_response(self, payload: dict[str, Any]) -> dict[str, Any]:
        predictions = []
        for index, job in enumerate(payload.get("jobs", [])):
            predictions.append(
                {
                    "job_id": job["job_id"],
                    "rank": index + 1,
                    "raw_score": 1000.0 - index,
                    "display_score": 90.0 - index,
                    "top_positive_factors": [
                        {
                            "code": "DOMAIN_ALIGNMENT",
                            "feature_group": "domain_compatibility",
                            "direction": "increases_model_score",
                            "contribution": 0.5,
                            "strength": 0.8,
                        }
                    ],
                    "top_negative_factors": [],
                }
            )

        return {
            "request_id": payload.get("request_id"),
            "api_contract_version": "recommendation-ranking-api-v1",
            "bundle_version": "job-rec-inference-bundle-v1",
            "model_version": "xgbranker-tuned-v1",
            "dataset_version": "synthetic-job-rec-1.0.0",
            "feature_schema_version": "job-rec-features-v1",
            "model_source_revision": "a" * 40,
            "score_transform_version": "validation-minmax-selected-trial-t06-v1",
            "explanation_contract_version": "recommendation-explanation-contract-v1",
            "requested_limit": payload.get("limit"),
            "prediction_count": len(predictions),
            "predictions": predictions,
            "explanation_note": EXPLANATION_NOTE,
            "latency_ms": 1.0,
        }

    def _mutate(self, response: dict[str, Any], mode: str) -> None:
        predictions = response["predictions"]
        if mode == "version_mismatch":
            response["model_version"] = "unexpected-model"
        elif mode == "request_id_mismatch":
            response["request_id"] = "00000000-0000-4000-8000-000000000099"
        elif mode == "missing_prediction" and predictions:
            predictions.pop()
            response["prediction_count"] = len(predictions)
        elif mode == "extra_prediction" and predictions:
            predictions.append(
                {
                    **predictions[0],
                    "job_id": 999999,
                    "rank": len(predictions) + 1,
                }
            )
            response["prediction_count"] = len(predictions)
        elif mode == "duplicate_job" and len(predictions) > 1:
            predictions[1]["job_id"] = predictions[0]["job_id"]
        elif mode == "rank_gap" and len(predictions) > 1:
            predictions[1]["rank"] = 3
        elif mode == "invalid_score" and predictions:
            predictions[0]["display_score"] = 101
        elif mode == "invalid_reason" and predictions:
            predictions[0]["top_positive_factors"][0]["code"] = "RAW_PRIVATE_FEATURE"

    def _json(self, status: HTTPStatus, payload: dict[str, Any]) -> None:
        self._bytes(
            status,
            json.dumps(
                payload,
                ensure_ascii=False,
                separators=(",", ":"),
                sort_keys=True,
            ).encode("utf-8"),
            "application/json",
        )

    def _bytes(self, status: HTTPStatus, payload: bytes, content_type: str) -> None:
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        try:
            self.wfile.write(payload)
        except (BrokenPipeError, ConnectionResetError):
            return


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8110)
    parser.add_argument("--mode-file", type=Path, required=True)
    parser.add_argument("--events-file", type=Path, required=True)
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    if args.host != "127.0.0.1":
        raise SystemExit("The Phase 17 fault server may bind only to 127.0.0.1.")
    server = FaultServer((args.host, args.port), FaultHandler)
    server.mode_file = args.mode_file
    server.events_file = args.events_file
    server.serve_forever()


if __name__ == "__main__":
    main()
