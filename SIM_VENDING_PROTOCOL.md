# SIM Vending — Device Agent Protocol

How an agent (Android phone app or GSM-modem host) that physically holds the
platform's SIMs talks to the backend. The backend queues vend jobs; agents
poll, execute the USSD transfer / data gift on the SIM, and report back.
Reference implementations: `sim_agent/python` (GSM modem / AT commands) and
`sim_agent/expo` (Android).

## Lifecycle at a glance

```
admin generates one-time code ──▶ agent POST /api/sim/register ──▶ {slug, shared_secret}
agent loop:  heartbeat (balances) ─▶ claim job ─▶ execute USSD ─▶ ack result
backend:     purchase → sim_vend_jobs (pending) → claimed (leased) → success | failed
             success keeps the customer's reserved funds; failed refunds them
             sim:expire-jobs sweeper refunds anything never acked (lease/TTL)
```

## 1. Registration (once per device)

An admin creates the device under **Admin → APIs → SIM Vending → Add
device** and receives a one-time code (24 h expiry). The agent exchanges it:

```
POST /api/sim/register            (no signature — trust = the code itself)
{
  "registration_code": "AB12CD34EF",
  "app_version": "1.0.0",
  "sims": [
    {"slot_index": 0, "network": "mtn",  "phone_number": "0803..."},
    {"slot_index": 1, "network": "airtel"}
  ]
}
→ 200 {"data": {"slug": "...", "shared_secret": "...", "config": {"poll_interval": 15, "lease_seconds": 300}}}
```

Store `slug` + `shared_secret` securely. The secret is never shown again; an
admin can rotate it (old one dies instantly) from the same screen.

`network` is free text but must match the platform's network names
(`mtn`, `airtel`, `glo`, `9mobile`) — it is lowercased server-side and used
verbatim to match jobs.

## 2. Request signing (every call after registration)

Headers on every request:

```
X-Sim-Device:  <slug>
X-Timestamp:   <unix seconds, ±300s of server time>
X-Signature:   hex(HMAC_SHA256(key = shared_secret, msg = "<timestamp>.<raw body bytes>"))
Content-Type:  application/json
```

The signature covers the **exact raw body bytes** you send. Sign first, then
send those same bytes — re-serializing after signing breaks verification.
An empty body signs as `"<timestamp>."`. Every verified call also counts as
a liveness ping; a device silent past the online window (default 180 s) gets
no new jobs and purchases fail over to API providers.

Errors: `401` (bad/missing signature, unknown/paused device, stale
timestamp), `422` (validation), `409` (job already settled).

## 3. Heartbeat — report SIM stock

Send on every poll cycle (or at least every couple of minutes):

```
POST /api/sim/{slug}/heartbeat
{
  "app_version": "1.0.0",
  "sims": [
    {"slot_index": 0, "network": "mtn", "phone_number": "0803...",
     "airtime_balance": 8450.00, "data_balance_mb": 10240}
  ]
}
→ 200 {"data": {"config": {"poll_interval": 15, "lease_seconds": 300}}}
```

Reported balances drive routing: a SIM whose stock can't cover a vend (plus
the airtime reserve, default ₦100) is skipped, and low balances alert the
admin. Honor the returned `poll_interval`.

## 4. Claim a job

```
POST /api/sim/{slug}/jobs/claim      (empty JSON body "{}")
→ 200 {"data": {"jobs": [ ...zero or one job... ]}}

job = {
  "id": 42,
  "reference": "TXN-20260711...-ABC123",   // the customer's transaction ref
  "service": "airtime" | "data",
  "network": "mtn",
  "phone": "08012345678",
  "amount": 500.0,                          // naira
  "plan": {                                 // data only, null for airtime
    "name": "1GB", "size_mb": 1024, "validity": "30 days",
    "plan_type": "SME", "vend_code": "usually-null-or-admin-set-hint"
  },
  "sim_slot": 0,                            // which of YOUR sims to vend from
  "lease_expires_at": "2026-07-11T12:05:00+00:00"
}
```

Claiming **leases** the job to this device until `lease_expires_at`
(default 300 s + 120 s grace). Rules:

- Never execute a job after its lease expired — re-claim instead. An expired
  lease is refunded by the sweeper and a late delivery creates a
  money-vs-value discrepancy the admin has to untangle by hand.
- **Always ack**, even late or after failure. A late ack gets a `409` —
  that's fine, it flags the discrepancy for reconciliation.
- Execute exactly once per claim. If you can't tell whether the USSD went
  through, ack `failed` with `retryable: false` and a note — never re-run it.

## 5. Ack the result

```
POST /api/sim/{slug}/jobs/{id}/ack
{
  "result": "executed" | "failed",
  "note": "free text, max 1000",
  "retryable": false,                // failed only: true = confirmed NON-delivery
                                     // (e.g. USSD rejected), safe to requeue
  "receipt": {"ussd_response": "Y'ello! You have successfully..."},   // optional, stored
  "sim": {"airtime_balance": 7950.0, "data_balance_mb": 9216}         // optional post-vend stock
}
→ 200 (settled)  |  409 (job already terminal — do NOT retry the vend)
```

Semantics:

- `executed` → customer's pending transaction settles as success.
- `failed` + `retryable: true` → job goes back to the queue (once; default
  max 2 attempts total). Use ONLY when you are certain nothing was delivered.
- `failed` (terminal) → customer refunded.
- No `sim` block: the backend decrements its last-known balance by the vend
  amount/size as an estimate until your next heartbeat.

## 6. USSD execution is the agent's concern

The backend ships `network`, `amount`/`plan`, and `phone`; the agent owns the
carrier codes. Typical transfer templates (verify per carrier/tariff — codes
change):

| Network  | Airtime transfer                          | Notes                          |
|----------|-------------------------------------------|--------------------------------|
| MTN      | `*321*phone*amount*pin#` (Share'N'Sell)   | needs transfer PIN set on SIM  |
| Airtel   | `*432*pin*amount*phone#` (Me2U)           | needs Me2U PIN                 |
| Glo      | `*131*phone*amount*pin#` (EasyShare)      | needs EasyShare PIN            |
| 9mobile  | `*223*pin*amount*phone#`                  | needs transfer PIN             |

Data gifting uses each carrier's share/gift menu (often SIM-plan-specific,
e.g. MTN SME data share). `plan.vend_code` is an optional admin-set hint
(stored per data plan) your agent can key its own template table on;
otherwise map from `network` + `plan.size_mb`.

Success detection = parsing the carrier's USSD/SMS response. Treat an
ambiguous response as `failed`, `retryable: false`, with the raw text in
`receipt` — a human reconciles it; the code never guesses.

## 7. Backend safety rails (context for agent authors)

- Funds are reserved at purchase and the transaction is `pending` until your
  ack (or the sweeper) settles it — refund/reward logic is locked and
  idempotent server-side; duplicate acks can't double-move money.
- `sim:expire-jobs` (scheduled every minute) refunds claimed jobs whose lease
  + grace lapsed and pending jobs unclaimed past the TTL (default 600 s).
- Purchases only route to SIM vending when an online device has a matching,
  sufficiently-funded SIM — otherwise they silently use the API providers,
  so a dead fleet degrades gracefully rather than failing customers.
