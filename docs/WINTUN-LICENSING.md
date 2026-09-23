# Wintun licensing — can we bundle it in a closed-source commercial agent?

**Short answer: yes, in my reading, and the licence says so explicitly rather
than by implication. This is my reading of the text, not legal advice.**

Checked against `wintun-0.14.1.zip`, downloaded from <https://www.wintun.net/builds>
on 21 September 2026.

| Artefact | SHA-256 |
|---|---|
| `wintun-0.14.1.zip` | `07c256185d6ee3652e09fa55c0b673e2624b565e02c4b9091c79ca7d2f24ef51` |
| `bin/amd64/wintun.dll` | `e5da8447dc2c320edc0fc52fa01885c103de8c118481f683643cacc3220dafce` |
| `bin/arm64/wintun.dll` | `f7ba89005544be9d85231a9e0d5f23b2d15b3311667e2dad0debd344918a3f80` |

## Two different licences, and only one of them applies to us

This is the part that causes confusion. Wintun's **source code** is GPLv2. Its
**prebuilt signed DLLs** are not: they ship under a separate "Prebuilt Binaries
License" included in the ZIP. We ship the prebuilt DLL and never touch the
source, so GPLv2 never enters our supply chain.

wintun.net also states the signed DLLs are *"the only supported way of
distributing Wintun"* — so building our own from the GPL source would be both
unsupported and the one route that would create a GPL problem.

## The clause that decides it

From `LICENSE.txt`, clause 3, "RESTRICTIONS" — emphasis mine:

> You must not:
> […]
> **d.** resell, redistribute, lease, rent, transfer, sublicense, or otherwise
> transfer rights of the Software without the prior written consent of
> WireGuard LLC, **except insofar as the Software is distributed alongside
> other software that uses the Software only via the Permitted API**;

"Permitted API" is defined in clause 3(b) as the API interfaces of `wintun.h`.

Our agent calls Wintun only through those exported functions, via
`golang.zx2c4.com/wintun`, which is a thin wrapper over `wintun.h`. We do not
link it statically, modify it, or reach past its API. So the exception in 3(d)
covers us, and no written consent is needed for that route.

**Commercial sale is not restricted.** Clause 2 grants the right to use the
Software "for lawful purposes" with no field-of-use or non-commercial limit,
and nothing anywhere requires us to disclose our own source.

## What we must actually do

| Obligation | Clause | What it means for us |
|---|---|---|
| Ship the DLL unmodified | 3(a), 3(b) | Byte-for-byte as downloaded. Our build pins the SHA-256 above. |
| Keep the proprietary notices | 3(c) | `LICENSE-wintun.txt` ships in every package, verbatim. |
| Use it only via the documented API | 3(d) | We do — through `wintun.h` exports only. |
| Do not imply endorsement | 3(e) | **Marketing must not say "powered by WireGuard" or use the Wintun or WireGuard name to promote the product.** Stating factually that the product uses Wintun is different from using the name to endorse it; keep to the former. |

## Where the real risk is, and it is not GPL

1. **Clause 8 lets WireGuard LLC change the terms at any time.** Our right to
   ship the version we ship is not affected retroactively, but a future
   version could arrive under worse terms. Mitigation: we pin the version and
   its checksum, so a licence change cannot silently reach shipped builds, and
   we re-read the licence on every version bump. That is a process commitment,
   not a technical one.

2. **No warranty and no liability, at all** (clauses 4 and 5). We are selling a
   commercial product on top of a component whose author disclaims everything.
   That risk transfers to us and should be reflected in our own customer terms.

3. **Clause 6 terminates the licence on any non-compliance**, and requires us
   to stop distributing. The naming restriction in 3(e) is the easiest one to
   breach accidentally, in a brochure rather than in code.

## If we ever needed to stop using it

In rough order of cost:

1. **Ask WireGuard LLC for written consent** under 3(d). Free, removes all
   doubt about the redistribution route, and worth doing anyway — see below.
2. **WireGuardNT**, also WireGuard LLC — a kernel-mode alternative, and very
   likely the same licensing posture, so it solves nothing if the problem is
   WireGuard LLC's terms.
3. **OpenVPN's `tap-windows6`** — a GPLv2 NDIS driver. Using a driver from
   userspace through its device interface does not make our agent a derivative
   work, so the GPL does not reach us. But it is layer-2 rather than layer-3,
   measurably slower, and we would have to obtain and maintain a signed build.
4. **Write our own NDIS driver.** Needs an EV certificate, Microsoft
   attestation or WHQL signing, and months of work. Only worth considering if
   we are large enough that a third-party driver dependency is itself the
   problem.

## Recommendation

Bundle it, pin it, ship the licence file, and keep the Wintun name out of
marketing copy.

Separately, **send WireGuard LLC a short email asking for written confirmation
that bundling their signed `wintun.dll` alongside a commercial closed-source
product that uses only the `wintun.h` API is within clause 3(d).** It costs
nothing, it is the exact question the clause anticipates, and a one-line reply
on file is worth far more than my reading of a paragraph if this is ever
questioned after fifty licences are sold.

**This document is my reading of a licence text. It is not legal advice, and I
am not a lawyer. For a product being sold commercially, have a lawyer read
clause 3(d) and clause 8 before launch.**
