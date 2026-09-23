# Code signing for the Windows agent

Unsigned binaries are not shippable. This is what the options actually are,
what they cost in India, and what changes in our build.

**Prices are indicative** — CA pricing moves, resellers discount heavily, and
the figures below are ranges I would budget against, not quotes. Get two
quotes before buying.

---

## The short version — corrected 21 September 2026

**Buy an EV certificate. Azure's service is not open to an Indian entity.**

My earlier advice to prefer Azure Trusted Signing was wrong for this business,
and the error was mine: I reasoned from the pricing and the eligibility rule I
remembered, and did not check which countries it serves. It does not serve
India. Corrected below, with the source.

Do not buy an OV certificate for a network agent either. It gets you a
signature, but SmartScreen still warns until the binary earns reputation, and
reputation is earned per-signature — so every release restarts the clock until
the *publisher identity* itself is established. For a security product being
installed by a shop owner, "Windows protected your PC" on day one is not
acceptable.

## Why Azure is out

Microsoft renamed the service: **Trusted Signing is now "Artifact Signing"**
(`learn.microsoft.com/azure/artifact-signing/`). Its current quickstart, last
updated 18 September 2026, states the geography rule in a prerequisites note:

> "Public Trust certificates are available to organizations in the United
> States, Canada, the European Union, the United Kingdom, Australia, New
> Zealand, Japan, South Korea, Singapore, Switzerland, Norway, and Israel.
> Individual developers must be located in the United States or Canada. These
> geographic restrictions do not apply to Private Trust certificates."

**India is not on that list.** Public Trust is the only kind that Windows and
SmartScreen trust on a customer's machine; Private Trust certificates are for
signing inside an organisation that has deployed your own root, which is not
how software is sold. So the service cannot sign AK Connect for customers,
whatever the entity's age.

On the age question, which is now moot but worth recording: **the current
documentation states no three-year rule.** What it says about organisation
vetting is:

> "For a quicker onboarding process, ensure that public records for the legal
> business entity that you're validated are up to date."

and that supporting documents "must be issued within the previous 12 months".
So the relevant dates would have been the *currency* of records, not the
founding date — the January 2024 GST registration and December 2023 Udyam
would both have been fine as current registrations, and the April 2018
operating history would not have been the deciding factor either way.

Confirm the country list at purchase time: it is a note on a page Microsoft
edits, and it has changed before.

---

## The three options

### 1. Azure Artifact Signing (formerly Trusted Signing) — **not available in India**

Microsoft's own signing service, and on paper the best option: about
**$9.99/month**, no USB token, works in any CI. It is ruled out here purely by
geography — see above. Worth re-checking if the country list grows, or if AK
Computer ever has an entity in a listed country.

### 2. EV certificate — what to buy

| | |
|---|---|
| Cost | **₹25,000–₹45,000/year** typical in India (DigiCert, Sectigo via resellers) |
| Identity check | Full organisation vetting: registration documents, a verifiable phone listing, sometimes a legal opinion letter |
| SmartScreen | **Immediate reputation** — no warning period |
| Hardware | Certificate is issued on a **FIPS 140-2 USB token** or must live in a cloud HSM |
| Issuance | **1–3 weeks**, longer if documents need chasing |

The USB token is the operational problem: signing requires the token plugged
into the signing machine and its password entered. That rules out ordinary
CI unless you pay extra for a cloud HSM (Azure Key Vault, DigiCert KeyLocker,
around ₹8,000–₹15,000/year more).

### 3. OV certificate — cheaper, and not enough

₹12,000–₹20,000/year, easier vetting, no token in some cases. But SmartScreen
reputation must be earned through download volume, and a new product has none.
**Not recommended for this.**

---

## Documents for an Indian proprietorship or company

Have these ready before applying; chasing them is what makes issuance take
three weeks instead of one.

**Private Limited / LLP**
- Certificate of Incorporation
- PAN of the company
- GST registration certificate
- Bank statement or a letter on bank letterhead confirming the account
- Director's PAN and Aadhaar
- Registered address proof — utility bill or rent agreement
- A **verifiable phone listing** for the company: the CA calls a number they
  found independently, not one you gave them. Justdial, a bank listing or a
  D-U-N-S record all count. **This is the step that most often stalls.**

**Proprietorship**
- GST registration in the business name
- Proprietor's PAN and Aadhaar
- Shop & Establishment licence or Udyam registration
- Bank account in the business name
- The same independent phone listing requirement

A D-U-N-S number (free from Dun & Bradstreet India, 2–4 weeks) makes vetting
much smoother and is worth starting now regardless of which option you pick.

---

## What changes in our build

Today `services/kit/build-windows-pack.sh` runs `go build` and zips the result.
Signing adds one step per binary, after the build and before packaging:

```bash
# Azure Trusted Signing
azuresigntool sign \
  --azure-key-vault-url "$AKV_URL" \
  --azure-key-vault-client-id "$CLIENT_ID" \
  --azure-key-vault-tenant-id "$TENANT_ID" \
  --azure-key-vault-client-secret "$CLIENT_SECRET" \
  --azure-key-vault-certificate "$CERT_NAME" \
  --timestamp-rfc3161 http://timestamp.acs.microsoft.com \
  --file-digest sha256 \
  akconnect-agent.exe

# EV token on a signing machine
signtool sign /fd SHA256 /tr http://timestamp.digicert.com /td SHA256 \
  /n "AK Computer" akconnect-agent.exe
```

Three things matter and are easy to get wrong:

1. **Always timestamp** (`/tr`). Without it every signature expires when the
   certificate does, and binaries already installed on customer machines start
   failing validation. With it, signatures stay valid after expiry.
2. **Sign the release binary, not a rebuild.** Sign exactly the file that ships,
   then compute `SHA256SUMS` *after* signing — signing changes the file.
3. **Never put the signing credential in the repo.** It goes in CI secrets or
   stays on the token. Our own rule already: *do not commit secrets, ever.*

`build-windows-pack.sh` has one obvious insertion point, right after the two
`go build` calls and before `sha256sum`.

---

## SmartScreen reputation, concretely

| State | What the shop owner sees |
|---|---|
| Unsigned (today) | "Windows protected your PC" — blue box, **Run anyway** hidden behind *More info* |
| OV, no reputation | Same warning, wording slightly softer |
| OV, reputation earned | No warning — but it takes thousands of downloads |
| **EV or Trusted Signing** | **No warning from the first install** |

Reputation attaches to the *publisher identity*, not the file, once you are on
EV or Trusted Signing — so a new release does not reset it. That is the whole
reason to skip OV.

Defender is separate. A signed binary is far less likely to be heuristically
quarantined, but if it happens, the fix is a false-positive submission to
Microsoft, not an exclusion on the customer's machine.

---

## How agent auto-update verifies signatures (§14)

Once we have a real certificate, the agent must verify updates itself. The
panel's own updater already has the mechanism — `update.json` carries a
`signature` field and the code checks it when present — but it is currently
unused, which the verification report records as a known limitation.

For the **agent**, the chain should be:

1. **Sign the manifest, not just the binary.** The release manifest lists each
   platform's binary and its SHA-256. Sign that manifest with an **ed25519 key
   we control** — not the code-signing certificate. Two different keys, two
   different jobs: Authenticode tells *Windows* the file is from us, and the
   ed25519 signature tells *our agent* the update is one we published.
2. **Pin the public key in the agent binary.** It ships compiled in. An
   attacker who compromises the update server can then serve a malicious
   binary, but cannot sign a manifest for it.
3. **Verify in this order**, refusing at the first failure: manifest signature
   → binary SHA-256 matches the manifest → Authenticode signature on the
   downloaded binary chains to our publisher → version is newer than the
   installed one.
4. **Refuse to downgrade** unless explicitly forced by an administrator. A
   silent downgrade to a version with a known hole is a supply-chain attack
   that needs no code execution at all.
5. **Verify before replacing**, never after. The current binary keeps running
   until the new one has passed every check.
6. **Keep the previous binary** and roll back if the new one fails to start or
   fails its self-test — the same shape as the panel's rollback journal.

The `selftest` command already exists and is exactly the health check step 6
needs.

**Do not** rely on Authenticode alone. It proves Microsoft's chain trusts the
signer; it does not prove *we* published this particular version, and it is
verifiable only on Windows, while the agent also runs on Linux.

---

## What I would do

1. **Start the EV application now.** Azure is not an option for an Indian
   entity, so there is nothing to wait for. Budget ₹35,000/year plus
   ₹8,000–15,000 for a cloud HSM if you want signing to work in CI rather than
   on one machine with a token plugged into it.
2. **Apply for a D-U-N-S number today** — free, takes 2–4 weeks, and it is the
   single thing that most reliably prevents the independent-phone-verification
   stall that turns a one-week issuance into three.
3. Use the **April 2018** operating history where a CA asks for business
   longevity, and the **January 2024 GST** and **December 2023 Udyam**
   registrations as the current registration documents — CAs want documents
   issued recently, and those are the current ones.
4. Meanwhile, keep shipping unsigned to yourself and your test machines only.
   Nothing goes to a paying customer unsigned.
