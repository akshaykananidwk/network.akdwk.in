#!/usr/bin/env python3
"""A panel that records what was actually sent to it.

upgrade-edge.sh signs its requests to the panel, and for one release it signed
them correctly and sent the timestamp as an empty header — because `TS` was
assigned inside a function called in a `$( )` subshell, so the assignment never
reached the caller. curl drops a header whose value is empty rather than
sending it, so the panel saw no timestamp, answered 401, and logged "missing
signature headers". Nothing in the output of either side named the cause.

Asserting on the panel's status code alone would not have caught it either:
plenty of things produce a 401. What catches it is looking at the bytes that
left the machine, which is what this is for.

    capture-panel.py <port> <capture-file> [--secret HEX]
                     [--version V] [--commit SHA] [--branch NAME]
                     [--published V]

It answers every request with a plausible /api/v1/edge/release body so the
script under test carries on, and appends one JSON object per request to the
capture file: method, path, headers, body. With --secret it also reports
whether the signature verifies, so the drill can assert the scheme is right and
not merely that the header was non-empty.
"""

import hashlib
import hmac
import json
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

CAPTURE = None
SECRET = b""

# What /api/v1/edge/release answers. Overridden by flags rather than by a
# caller string-patching this file: a drill that did that produced JSON with
# "branch" twice, the second occurrence won, and the scenario silently tested
# something other than what it said it did.
RELEASE = {
    "version": "9.9.9-capture",
    "commit": "0" * 40,
    "current_commit": "0" * 40,
    "repo": "example/example",
    "branch": "main",
    "panel_url": "http://127.0.0.1",
}


class Handler(BaseHTTPRequestHandler):
    def _record(self, method: str) -> bytes:
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length) if length else b""

        timestamp = self.headers.get("X-Coordinator-Timestamp")
        signature = self.headers.get("X-Coordinator-Signature")

        entry = {
            "method": method,
            "path": self.path,
            # None when the header was absent, "" when it was present and
            # empty. The distinction is the whole defect: curl turns the
            # second into the first.
            "timestamp": timestamp,
            "signature": signature,
            "body_len": len(body),
            "signature_verifies": None,
        }

        if SECRET and timestamp and signature:
            expected = hmac.new(
                SECRET, timestamp.encode() + b"\n" + body, hashlib.sha256
            ).hexdigest()
            entry["signature_verifies"] = hmac.compare_digest(expected, signature)

        with open(CAPTURE, "a", encoding="utf-8") as handle:
            handle.write(json.dumps(entry) + "\n")

        return body

    def do_GET(self) -> None:  # noqa: N802 - the base class names it
        self._record("GET")
        self._answer()

    def do_POST(self) -> None:  # noqa: N802
        self._record("POST")
        self._answer()

    def _answer(self) -> None:
        payload = json.dumps({"data": RELEASE}).encode()

        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, *_args) -> None:
        """Quiet: the capture file is the output."""


def main() -> int:
    global CAPTURE, SECRET

    if len(sys.argv) < 3:
        print(__doc__, file=sys.stderr)
        return 2

    port = int(sys.argv[1])
    CAPTURE = sys.argv[2]

    if "--secret" in sys.argv:
        SECRET = sys.argv[sys.argv.index("--secret") + 1].encode()

    for field in ("version", "commit", "branch"):
        flag = "--" + field
        if flag in sys.argv:
            RELEASE[field] = sys.argv[sys.argv.index(flag) + 1]

    # Which installers the panel says it already serves. Absent unless asked
    # for, which is what a panel older than the field reports.
    if "--published" in sys.argv:
        published = sys.argv[sys.argv.index("--published") + 1]
        RELEASE["published_setup"] = published
        RELEASE["published_agent"] = published

    open(CAPTURE, "w", encoding="utf-8").close()

    HTTPServer(("127.0.0.1", port), Handler).serve_forever()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
