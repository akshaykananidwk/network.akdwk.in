#!/usr/bin/env python3
"""Upload an installer to the panel, in signed chunks.

Called by upgrade-edge.sh. Separate from it because this is the one part that
is genuinely awkward in shell: a base64 chunk inside JSON, each chunk carrying
an HMAC over "<timestamp>\\n<body>" with the coordinator's shared secret — the
same scheme the coordinator itself signs with, so the panel needs no new
credential and no new trust.

    publish-artifact.py <panel-url> <kind> <version> <file>

The secret comes from AKCONNECT_COORDINATOR_SECRET in the environment, never
from a flag: a flag is visible in ps to every user on the machine.

Prints the download URL on success. Exits 3 when the panel took the upload
but holds it for an administrator (its release key is not trusted there yet),
and non-zero otherwise, with the panel's own message, on failure.
"""

from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
import sys
import time
import urllib.error
import urllib.request

# Three megabytes raw, four encoded. Chosen to sit well inside the
# post_max_size a shared host is willing to allow — the whole reason this is
# chunked at all is that a fourteen megabyte body is not a safe assumption.
CHUNK = 3 * 1024 * 1024

TIMEOUT = 120


def post(panel: str, secret: bytes, path: str, payload: dict) -> dict:
    body = json.dumps(payload, separators=(",", ":")).encode()
    timestamp = str(int(time.time())).encode()

    signature = hmac.new(secret, timestamp + b"\n" + body, hashlib.sha256).hexdigest()

    request = urllib.request.Request(
        panel.rstrip("/") + path,
        data=body,
        method="POST",
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-Coordinator-Timestamp": timestamp.decode(),
            "X-Coordinator-Signature": signature,
        },
    )

    try:
        with urllib.request.urlopen(request, timeout=TIMEOUT) as response:
            return json.loads(response.read().decode())
    except urllib.error.HTTPError as error:
        detail = error.read().decode(errors="replace")
        try:
            parsed = json.loads(detail)
            message = (parsed.get("error") or {}).get("message") or detail
        except ValueError:
            message = detail
        raise SystemExit(f"  the panel refused the upload ({error.code}): {message.strip()[:300]}")
    except urllib.error.URLError as error:
        raise SystemExit(f"  the panel could not be reached: {error.reason}")


def main() -> int:
    if len(sys.argv) != 5:
        raise SystemExit(__doc__)

    panel, kind, version, path = sys.argv[1:5]

    secret = os.environ.get("AKCONNECT_COORDINATOR_SECRET", "")
    if not secret:
        raise SystemExit("  AKCONNECT_COORDINATOR_SECRET is not set")

    # The edge's own signature over kind, version and digest (1.9.7-dev.23),
    # made by upgrade-edge.sh with the release key. Without it such a panel
    # refuses the upload; with a key it does not trust yet, it holds it.
    # upgrade-edge.sh leaves both unset only for a panel whose release
    # predates signatures, which ignores them.
    edge_key = os.environ.get("AKCONNECT_EDGE_KEY", "")
    edge_signature = os.environ.get("AKCONNECT_EDGE_SIGNATURE", "")
    signed = {"edge_key": edge_key, "edge_signature": edge_signature} if edge_key and edge_signature else {}

    total = os.path.getsize(path)
    if total == 0:
        raise SystemExit(f"  {path} is empty")

    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for block in iter(lambda: handle.read(1 << 20), b""):
            digest.update(block)
    sha256 = digest.hexdigest()

    url = ""
    held = ""
    sent = 0

    with open(path, "rb") as handle:
        while True:
            chunk = handle.read(CHUNK)
            if not chunk:
                break

            answer = post(
                panel,
                secret.encode(),
                "/api/v1/edge/artifact",
                {
                    "kind": kind,
                    "version": version,
                    "sha256": sha256,
                    "offset": sent,
                    "total": total,
                    "data": base64.b64encode(chunk).decode(),
                    **signed,
                },
            )

            data = answer.get("data") or {}
            if not answer.get("success"):
                message = (answer.get("error") or {}).get("message", "unknown error")
                raise SystemExit(f"  the panel refused a chunk: {message}")

            sent += len(chunk)

            # Progress on one line: this runs over somebody's VPS uplink and
            # silence for two minutes reads as a hang.
            percent = sent * 100 // total
            print(f"\r  uploading… {percent:3d}% ({sent // 1024}k of {total // 1024}k)",
                  end="", file=sys.stderr, flush=True)

            if data.get("complete"):
                url = str(data.get("url") or "")
                if data.get("held"):
                    held = str(data.get("message") or "held for an administrator")

    print("", file=sys.stderr)

    if held:
        # Exit 3: uploaded, verified, and waiting for an administrator.
        print(f"  {held}", file=sys.stderr)
        return 3

    if not url:
        raise SystemExit("  the upload finished but the panel published nothing")

    print(url)

    return 0


if __name__ == "__main__":
    sys.exit(main())
