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
                     [--published V] [--accept | --held]
                     [--held-release V FINGERPRINT]

It answers every request with a plausible /api/v1/edge/release body so the
script under test carries on, and appends one JSON object per request to the
capture file: method, path, headers, body. With --secret it also reports
whether the signature verifies, so the drill can assert the scheme is right and
not merely that the header was non-empty.

Uploads to /api/v1/edge/artifact are answered like the release by default,
which the uploader reads as a refusal. --accept answers each one as a
complete, published upload; --held as one the panel took and is holding for
an administrator (1.9.7-dev.23). --held-release makes the release answer say
version V is waiting, signed by the edge key with that fingerprint.
"""

import hashlib
import hmac
import json
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

CAPTURE = None
SECRET = b""
UPLOADS = ""  # "", "accept" or "held"

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

        # An upload's edge signature (1.9.7-dev.23), recorded for the drill to
        # verify: python has no ed25519 of its own, php's sodium does.
        if self.path.endswith("/edge/artifact") and body:
            try:
                upload = json.loads(body)
                entry["edge_key"] = upload.get("edge_key")
                entry["edge_signature"] = upload.get("edge_signature")
                entry["kind"] = upload.get("kind")
                entry["version"] = upload.get("version")
                entry["sha256"] = upload.get("sha256")
            except ValueError:
                pass

        with open(CAPTURE, "a", encoding="utf-8") as handle:
            handle.write(json.dumps(entry) + "\n")

        return body

    def do_GET(self) -> None:  # noqa: N802 - the base class names it
        self._record("GET")
        self._answer()

    def do_POST(self) -> None:  # noqa: N802
        self._record("POST")
        if self.path.endswith("/edge/artifact") and UPLOADS:
            data = {"complete": True, "url": "http://127.0.0.1/download/setup.exe"}
            if UPLOADS == "held":
                data = {"complete": True, "held": True, "message": "Held: this panel trusts no edge key yet."}
            self._send({"success": True, "data": data})
            return
        self._answer()

    def _answer(self) -> None:
        self._send({"data": RELEASE})

    def _send(self, answer: dict) -> None:
        payload = json.dumps(answer).encode()

        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, *_args) -> None:
        """Quiet: the capture file is the output."""


def main() -> int:
    global CAPTURE, SECRET, UPLOADS

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

    if "--accept" in sys.argv:
        UPLOADS = "accept"
    if "--held" in sys.argv:
        UPLOADS = "held"
    if "--held-release" in sys.argv:
        at = sys.argv.index("--held-release")
        RELEASE["held_setup"] = sys.argv[at + 1]
        RELEASE["held_agent"] = sys.argv[at + 1]
        RELEASE["held_fingerprint"] = sys.argv[at + 2]

    open(CAPTURE, "w", encoding="utf-8").close()

    HTTPServer(("127.0.0.1", port), Handler).serve_forever()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
