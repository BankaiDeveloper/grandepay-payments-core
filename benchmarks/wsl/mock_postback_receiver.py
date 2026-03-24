#!/usr/bin/env python3
"""Simple threaded postback receiver for local WSL benchmarks."""

from __future__ import annotations

import argparse
import json
import random
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Mock postback receiver for local benchmark runs.")
    parser.add_argument("--bind", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=18080)
    parser.add_argument("--status", type=int, default=200)
    parser.add_argument("--base-delay-ms", type=int, default=0)
    parser.add_argument("--jitter-ms", type=int, default=0)
    parser.add_argument("--slow-rate", type=float, default=0.0)
    parser.add_argument("--slow-delay-ms", type=int, default=0)
    parser.add_argument("--failure-rate", type=float, default=0.0)
    parser.add_argument("--failure-status", type=int, default=500)
    parser.add_argument("--log-file", default="")
    return parser


class PostbackHandler(BaseHTTPRequestHandler):
    server_version = "GrandePayPostbackReceiver/1.0"

    def do_POST(self) -> None:  # noqa: N802
        content_length = int(self.headers.get("Content-Length", "0"))
        raw_body = self.rfile.read(content_length) if content_length > 0 else b""
        body = raw_body.decode("utf-8", errors="replace")

        total_delay_ms = self.server.base_delay_ms  # type: ignore[attr-defined]
        if self.server.jitter_ms > 0:  # type: ignore[attr-defined]
            total_delay_ms += random.randint(0, self.server.jitter_ms)  # type: ignore[attr-defined]

        if self.server.slow_rate > 0 and random.random() < self.server.slow_rate:  # type: ignore[attr-defined]
            total_delay_ms += self.server.slow_delay_ms  # type: ignore[attr-defined]

        if total_delay_ms > 0:
            time.sleep(total_delay_ms / 1000)

        status_code = self.server.status_code  # type: ignore[attr-defined]
        if self.server.failure_rate > 0 and random.random() < self.server.failure_rate:  # type: ignore[attr-defined]
            status_code = self.server.failure_status  # type: ignore[attr-defined]

        log_line = {
            "received_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "path": self.path,
            "status_code": status_code,
            "headers": dict(self.headers),
            "body": body,
        }

        if self.server.log_file:  # type: ignore[attr-defined]
            log_path = Path(self.server.log_file)  # type: ignore[attr-defined]
            log_path.parent.mkdir(parents=True, exist_ok=True)
            with log_path.open("a", encoding="utf-8") as handle:
                handle.write(json.dumps(log_line, ensure_ascii=True) + "\n")

        response = {
            "ok": status_code < 400,
            "status": status_code,
            "received_path": self.path,
        }

        payload = json.dumps(response).encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, fmt: str, *args) -> None:
        return


def main() -> None:
    args = build_parser().parse_args()

    server = ThreadingHTTPServer((args.bind, args.port), PostbackHandler)
    server.status_code = args.status
    server.base_delay_ms = args.base_delay_ms
    server.jitter_ms = args.jitter_ms
    server.slow_rate = args.slow_rate
    server.slow_delay_ms = args.slow_delay_ms
    server.failure_rate = args.failure_rate
    server.failure_status = args.failure_status
    server.log_file = args.log_file

    print(f"Mock postback receiver listening on http://{args.bind}:{args.port}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
