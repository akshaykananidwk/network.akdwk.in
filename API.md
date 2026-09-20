# REST API v1

Base URL: `https://your-domain/api/v1`
Machine-readable spec: [`docs/openapi.yaml`](docs/openapi.yaml)

---

## The envelope

Every response has the same shape, so a client can branch on `success` alone.

```json
{ "success": true,  "data": { }, "meta": { }, "error": null }
{ "success": false, "data": null, "meta": { }, "error": { "code": "not_found", "message": "…", "details": { } } }
```

| Status | `error.code` | Means |
|---|---|---|
| 400 | `bad_request` | Malformed request |
| 401 | `unauthenticated` | No credential, or one that is invalid, expired or revoked |
| 402 | `limit_exceeded` | A plan limit was reached; the message names it |
| 403 | `forbidden` | Authenticated, but the scopes or role do not permit this |
| 404 | `not_found` | No such resource — **including one belonging to another customer** |
| 422 | `validation_failed` | `error.details` carries per-field messages |
| 429 | `rate_limited` | Slow down; a `Retry-After` header says how long |
| 503 | `maintenance` | An update is in progress |

A resource belonging to another customer returns **404, not 403**. A 403 would
confirm the id exists, which is itself a cross-tenant leak.

---

## Authenticating

```http
Authorization: Bearer ak_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Create a key under **Settings → API keys**. It is shown once; only a SHA-256
hash is stored, so a lost key is replaced, not recovered.

Two things constrain a key:

* **Scopes**, chosen at creation. A key with `network.view` can list networks
  and nothing else.
* **Its creator.** A key can never do more than the user who made it. If that
  user is later demoted, the key is narrowed with them.

Device tokens are a different credential with a different purpose — they reach
only the `/agent/*` endpoints for their own device, and are issued when an
administrator approves that device.

### Rate limits

120 requests per minute per key, sliding window. On 429 the `Retry-After`
header is authoritative; back off rather than retrying immediately.

---

## Quick start

```bash
export AK_KEY="ak_live_…"
export AK_URL="https://net.example.com/api/v1"

# Create a network
curl -s -X POST "$AK_URL/networks" \
  -H "Authorization: Bearer $AK_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"name":"Head office","cidr":"10.50.0.0/16"}' | jq

# Devices waiting for approval
curl -s "$AK_URL/devices?status=pending" \
  -H "Authorization: Bearer $AK_KEY" | jq '.data[] | {id, name, os}'

# Approve one — the device token comes back exactly once
curl -s -X POST "$AK_URL/devices/42/approve" \
  -H "Authorization: Bearer $AK_KEY" \
  -H 'Content-Type: application/json' -d '{}' | jq '.data.virtual_ip'
```

---

## Endpoints

### Networks

| | |
|---|---|
| `GET /networks` | List. `?page=`, `?per_page=` (max 100), `?q=` |
| `POST /networks` | Create. Requires `name` and `cidr` |
| `GET /networks/{id}` | One network |
| `PATCH /networks/{id}` | Update |
| `DELETE /networks/{id}` | Archive — detaches and revokes its devices |
| `GET /networks/{id}/members` | Devices on it |
| `GET /networks/{id}/routes` | Advertised routes |

The address range must be RFC 1918 private space between /16 and /30, and must
not overlap another network belonging to the same customer. Creating a network
materialises its whole address pool, which is why assignment later is a single
row-locked update rather than a scan.

A range can be **widened** later but never narrowed — narrowing would strand
devices already holding an address outside the new range.

### Access rules

| | |
|---|---|
| `GET /networks/{id}/acl` | Rules in evaluation order |
| `POST /networks/{id}/acl` | Add a rule |
| `PUT /networks/{id}/acl/{ruleId}` | Replace a rule |
| `DELETE /networks/{id}/acl/{ruleId}` | Delete a rule |

Rules are evaluated in priority order, lowest number first; the first match
wins. With no matching rule, the network's default action applies.

They are compiled and enforced **on both devices**, not merely displayed. A
peer you deny is not reachable, whatever it tries. A change reaches connected
agents within about ten seconds.

```json
{
  "priority": 10,
  "description": "Office may reach the NVR on RTSP only",
  "src_type": "tag",  "src_value": "office",
  "dst_type": "tag",  "dst_value": "nvr",
  "protocol": "tcp",  "port_from": 554, "port_to": 554,
  "action": "allow"
}
```

### Devices

| | |
|---|---|
| `GET /devices` | List. `?status=`, `?network_id=`, `?q=` |
| `GET /devices/{id}` | One device |
| `PATCH /devices/{id}` | Rename, re-tag, or mark as a gateway |
| `POST /devices/{id}/approve` | Assign an address and issue a token |
| `POST /devices/{id}/revoke` | Destroy the token, release the address |
| `POST /devices/{id}/disable` | Stop it connecting, keep its address |
| `DELETE /devices/{id}` | Revoke, then remove |

`connection_type` is what an operator actually wants to know:

| | |
|---|---|
| `direct` | Peer-to-peer. No traffic passes through our servers. |
| `relay` | Going via a relay. The agent keeps retrying a direct path and upgrades silently when one appears. |
| `offline` | Not connected. |

**Approval** returns the device token in the response. It appears exactly once
— only its hash is stored. An agent normally collects its own token by polling
`/agent/claim`; the value in this response exists for manual and air-gapped
installs.

**Revocation** takes effect within about ten seconds: the token is destroyed
immediately, the address is released, and the network's config revision moves
so every peer drops it on its next poll.

### Platform

| | |
|---|---|
| `GET /relays` | Relays an agent may use. `?region=` |
| `GET /audit-logs` | Audit entries. `?action=`, `?result=`, `?from=`, `?to=` |
| `GET /usage` | Usage for a period. `?period=YYYY-MM` |

Only **relayed** traffic is metered. Direct peer-to-peer bytes never touch our
infrastructure, so billing for them would be dishonest.

---

## Agent endpoints

These are called by the agent, not by integrators. They are documented because
anyone writing their own client needs them.

| | Auth | |
|---|---|---|
| `POST /enroll` | none | Enrol with a join code. Rate limited per IP. |
| `POST /agent/claim` | none | Collect the token once approved. |
| `GET /agent/config` | device token | Full configuration |
| `POST /agent/heartbeat` | device token | Liveness and traffic |
| `POST /agent/endpoint` | device token | NAT traversal endpoint exchange |
| `GET /agent/version` | device token | Agent update check |

### Enrolment is deliberately useless until approved

```bash
curl -X POST "$AK_URL/enroll" -H 'Content-Type: application/json' -d '{
  "join_code":  "7KQ3M2XB9VTD",
  "public_key": "<base64 Curve25519 public key>",
  "hostname":   "reception-pc",
  "os":         "windows"
}'
```

```json
{ "success": true, "data": { "device_uid": "dev_…", "status": "pending", "poll_after": 10 } }
```

No address. No token. No peer list. That is rule R4, and the test suite
asserts the response contains none of them.

Re-enrolling with the same public key returns the existing device rather than
creating a duplicate, so reinstalling an agent on the same machine keeps its
identity and its address.

### Configuration carries no default route

```bash
curl "$AK_URL/agent/config?revision=41" -H "Authorization: Bearer $DEVICE_TOKEN"
```

Send the last known `revision`; when it matches, the answer is
`{"changed": false}` and no peer list is rebuilt. At ten thousand devices that
is the difference between a trivial query and a serious one.

`0.0.0.0/0` can never appear in the response. The server strips it before
sending, so split tunnelling is a server refusal rather than an agent
convention. The configuration says so explicitly:

```json
"policy": { "split_tunnel_only": true, "allow_default_route": false }
```

DNS is split too: only names under the network's search domain resolve through
the tunnel. Ordinary name resolution is untouched.

---

## Live events

`GET /api/v1/stream` is a Server-Sent Events stream. It needs a dashboard
session rather than an API key — a key would tie up a worker for a minute at a
time to no purpose.

Events: `connected`, `snapshot`, `device.online`, `device.offline`,
`device.pending`, `conn.changed`, `reconnect`.

```javascript
const stream = new EventSource('/api/v1/stream', { withCredentials: true });
stream.addEventListener('device.online', e => console.log(JSON.parse(e.data)));
```

The connection recycles after about sixty seconds by design; reconnect on the
`reconnect` event. Clients that cannot use SSE fall back to polling
`/dashboard/stats`.

> PHP cannot host a WebSocket server, and pretending otherwise would be the
> kind of thing that produces a dashboard which looks finished and does not
> work. SSE is the honest fit: one long-lived HTTP response, server-to-client
> only, which is all a dashboard needs.

---

## Pagination

```json
"meta": { "total": 143, "page": 2, "per_page": 25, "pages": 6 }
```

`per_page` is capped at 100. List endpoints select explicit columns rather
than `*`.

---

## Errors worth handling

**402 — a plan limit.** The message names the limit and suggests an upgrade.
This is enforced at the action, not in the UI: approving the 26th device on a
25-device plan fails here, not just in the dashboard.

```json
{ "error": { "code": "limit_exceeded",
  "message": "Your plan allows 25 devices and 25 are already in use. Upgrade, or revoke a device you no longer need.",
  "details": { "upgrade": "Business raises the limit to 50 devices." } } }
```

**422 — validation.** `details` is keyed by field:

```json
{ "error": { "code": "validation_failed", "details": {
  "cidr": "Address range must be a valid CIDR block, e.g. 10.50.0.0/16." } } }
```

**503 — maintenance.** An update is running. Honour `Retry-After`; connected
devices are unaffected, because tunnels are data plane.
