# Automated Airtime-to-Cash resume investigation

Local investigation and implementation, 10 September 2026. No deployment, production database access, production provider requests, rate changes, or seeding were performed.

## Finding and evidence boundary

The exact error originates in `AirtimeToCashProviderService::start`. Previously, any row whose `active_session_key` matched SHA-256 of canonical network + ':' + sender phone blocked a new start. The query did not inspect ownership, main status, provider status, expiration, or session validity. The unique reservation is global across accounts and remains so.

The particular live record ID, status, provider status, session presence, creation date and whether it predates the failed-status fixes **cannot be established from the supplied evidence**. The literal error does not occur in the local `laravel-1.log`, `laravel.log`, or backend application log. It is caught as a domain error and returned to the customer, so absence from logs is expected and does not prove it never happened. No live database was accessed. Do not describe the customer's specific record as failed or expired without inspecting it.

The implementation defect is established independently of that missing record: the backend told customers to resume, returned only HTTP 422 with an empty data payload, and offered no active-discovery/resume endpoint. The frontend's request was component state initialized to null, lost on reload or changing methods. History showed conversions without resume controls. Its random start key changed on remount, so replay-by-start-key could not recover the old session. The existing status endpoint required an ID already known to the caller and read local state only. The restart-OTP endpoint was an expired-session restart, not a valid active-session resume flow.

## Previous lifecycle problem

Every created automated request reserved its SIM immediately, before provider calls. All states could block if the reservation remained. `failed` and `completed` transitions cleared the key; `expired` and `session_expired` did not. The previous restart endpoint reopened those same expired references. Older inconsistent rows could also have a terminal main/provider status while retaining the key. Recent failed-status normalization handles canonical provider failures, but it did not add customer discovery/resume or clean up expiration reservations.

## Changes

- `GET /api/customer/airtime-to-cash/provider/active`: lists only the authenticated customer's reserved automated conversions using the existing safe customer response. Performs local cleanup of terminal/expired reservations; no provider calls.
- `POST /api/customer/airtime-to-cash/{id}/resume`: verifies account ownership and automated mode before any action. Restores the existing record, reference, immutable amount/payout and provider binding. Returns `resumed: true`; never sends an OTP or transfer.
- Starting the same SIM again returns the owner's active record instead of an error, with `resumed: true`. A different requested amount does not overwrite its existing quote. New requests still require a valid quote. Idempotency-key replays retain identity validation and cannot reopen a terminal reference.
- Other accounts receive no existing record/reference/session information and cannot call resume on that record. The database's unique SIM reservation and transfer claim lock remain in place.
- Customer pages show “Resume conversion,” including when new automated starts are disabled. Restoration shows a clear notice and the original amount/payout/reference. Secrets are entered again; neither OTP nor PIN is restored or persisted.
- Expired verification submissions return their expired state rather than leaving the UI on an unusable OTP form. Expired references are immutable terminal session states. The old restart endpoint returns guidance to create a fresh quoted conversion; it no longer regenerates a session on the released record.
- Terminal main status prevents verify, transfer, pre-transfer callbacks and transfer-result handling from reopening rejected/approved/failed records.

## State and provider behavior

| Existing state | Behavior |
| --- | --- |
| `awaiting_otp`, within local validity | Restore verification-code entry. No new OTP/session. |
| `ready_to_transfer`, within local validity | Restore PIN entry and original payout. Automation checks the existing session ID first. |
| `created` / `verifying_otp` | Restore status/refresh view while the operation is in flight; do not repeat it. |
| `processing` / `provider_pending` / transfer-stage `manual_review` | Restore confirmation/status view; retain reservation even after local expiration. Never resubmit the transfer. |
| `provider_confirmed` / `settlement_pending` | Retry only the existing idempotent local wallet settlement. |
| `failed` / `completed`, main `approved` / `rejected`, legacy cancellation | Release any stale reservation and session identifier. Never reopen. History retained. |
| `expired` / `session_expired` | Release reservation and identifier; offer a new conversion with a new quote/reference. |
| Local timer expired before a transfer | Expire verification and release the key. Includes pre-transfer manual review only when transfer attempt count is zero. Confirmation/payout evidence prevents timer-based expiry. |
| Unknown provider transfer result | Remain pending/review; keep duplicate protection. Age alone is not evidence of failure. |

The local timer remains the existing configurable `airtime_to_cash.session_minutes` (default 10 minutes). This is Vendify's local validity policy, not a claim about a documented provider OTP/session TTL. Expired verification retains the existing `expired`/`session_expired` provider substates; canonical provider failure remains main `failed`, successful settlement main `approved`, and unresolved transfer outcomes main `pending`. Manual records are excluded from discovery/resume and retain their existing lifecycle.

Automation: the supplied documentation describes `POST /api/v1/login/with/session/id` as retrieving information using an existing session ID. Resume uses the adapter's `ChecksSession` implementation only before PIN confirmation. Explicit `4010` expires verification. Network/auth/unknown responses do not prove expiry and do not release the reservation or mark a transaction failed. A row lock rechecks state after the response so an expiry check cannot overwrite a concurrently claimed transfer. The documented Automation integration has no transaction-history lookup: unknown transfer outcomes still require the existing admin/provider investigation path. Refreshing local status cannot manufacture a provider confirmation.

2FAST: its existing integration supports OTP/skip-OTP identifiers and transaction history, not a separate session-check capability. Unexpired OTP/PIN sessions reuse their identifier. Resume of an unresolved transfer uses the existing reconciliation service and its one-minute cooldown. Only matching Airtime-to-Cash transaction/reference evidence can confirm or fail a transfer. Not-found/unknown keeps the reservation. No provider failover, transfer retry, or extra session is introduced.

## Record-specific live investigation (operator only; not executed)

Identify the affected customer's account and sender SIM. Inspect a restricted projection, never the encrypted session value or provider credentials:

```sql
SELECT id, user_id, network, status, provider_status, transaction_reference,
       provider_attempt_count, otp_attempt_count, provider_message,
       provider_started_at, created_at, updated_at, expires_at,
       last_provider_check_at, provider_confirmed_at,
       payout_transaction_reference,
       (active_session_key IS NOT NULL) AS holds_sim_reservation,
       (provider_identifier IS NOT NULL) AS has_session_identifier
FROM airtime_to_cash_requests
WHERE processing_mode = 'provider'
  AND sender_phone = :sender_phone
ORDER BY id DESC;
```

Use bound parameters. Check ownership of any matching global SIM reservation. For the selected row, inspect linked `transactions.airtime_to_cash_request_id` evidence as well as payout references. Compare its creation/update timestamps to actual deployment and migration execution times from deployment history; the migration filename alone does not establish when production was fixed. Record its ID/reference, main/provider states, whether a session is present, expiration, transfer-attempt count, and the conclusion on resumability. No history deletion or blanket failure update is appropriate.

## Manual verification after an approved deployment

1. With the affected account, open Airtime-to-Cash. Confirm its valid reserved conversion is listed with the original amount, SIM and reference. Reload and verify it remains discoverable. Try the path with new automated starts disabled too.
2. Resume awaiting OTP: the OTP entry appears and no second OTP/session is generated. Resume awaiting PIN: the original reviewed payout and an empty PIN input appear; Automation may check the existing session. Do not actually transfer funds unless separately intended.
3. Resume a processing conversion: show status, no PIN/transfer action. For 2FAST, explicit resume may reconcile transaction history; repeated requests respect cooldown and do not repeat transfer.
4. Verify terminal/locally expired records no longer reserve the SIM. A new conversion requires a fresh quote/reference. The old expired restart URL must not reopen history.
5. Using another test account, the active list must omit the first account's records; direct resume ID must return 404. Starting the same reserved SIM must not return that account's conversion information.
6. For an approved controlled conversion, compare the wallet ledger and provider history: one transfer, one linked wallet credit despite repeat resume/refresh/confirm requests. A timed-out unknown transfer must still reserve its SIM.
7. Confirm manual submission, history, review and settlement still work as before. No rate data should be seeded or changed by this patch.

## Local validation

Tests cover fresh-key recovery, original pricing, OTP/PIN/processing restoration, legacy terminal keys, local/provider expiration versus unknown responses, cross-account ownership and secret filtering, in-flight transfer/expiry races, 2FAST unknown-then-confirmed reconciliation, repeated settlement, no duplicate transfers/credits, and manual exclusion. Existing automated and manual regression tests are included.

- Backend Airtime-to-Cash suite: 91 tests, 633 assertions, exit 0 (reported as deprecated because of existing PHP warnings).
- Customer UI: 12 tests passed; admin Airtime-to-Cash UI: 8 tests passed (20 total).
- Frontend TypeScript/Vite build passed.
- ESLint passed on all four modified frontend files.
- Backend Pint and both repository diff-whitespace checks passed.
- Full frontend lint: 117 errors and 7 warnings across existing unrelated code. PHP emits existing runtime deprecation warnings. Repository-wide frontend ESLint has unrelated existing errors; changed-file lint is checked separately. No production verification was performed.
