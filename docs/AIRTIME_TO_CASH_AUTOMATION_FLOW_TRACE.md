# Automation flow and skipped PIN investigation

Local investigation only. No deployment, live provider transaction, dashboard access, production database access or rate seeding was performed. Source: the provider documentation supplied by the user (`5db8b1f3-7d18-4c27-a15a-d9b2b225b82d/pasted-text.txt`) and the current code.

## Exact cause of the misleading completed confirmation step

`ProviderAirtimeToCashPage.stageFor()` previously returned stage index **2 for every state not explicitly recognized as initial verification, PIN entry or completion**. `ConversionStages` marks every index less than `current` completed. Therefore `manual_review` rendered Verify SIM and Confirm conversion as completed, regardless of PIN entry or transfer evidence.

`AirtimeToCashProviderService.finishPreTransfer()` also used `manual_review` for unknown/unavailable quota, OTP-generation and OTP-verification outcomes. For example, a verify response with `code: 2000` but no required `data.sessionId` normalizes to unknown and previously became `manual_review`. The UI renders PIN entry **only for `ready_to_transfer`**; it was never shown in this path. The controller's default message then claimed that the conversion needed confirmation and warned against another transfer. Admin reconciliation accepted the same pre-transfer state. This combination explains the observed UI without any PIN or transfer being submitted.

Successful OTP verification alone correctly moves to `ready_to_transfer`, not `processing`. It did not automatically invoke transfer. The defect was conflating setup failures with unresolved transfers, then treating the conflated state as proof of completed confirmation.

This is a reproduced code path, not proof of the exact live provider response. Without that record's state/attempt counts and call evidence, we cannot say which quota/OTP response occurred in the user's live attempt. Dashboard counters do not supply that evidence. No historical raw provider response was persisted and exceptions were deliberately swallowed, so exact live HTTP/code/message cannot be reconstructed from the UI text alone.

## Documented contract and actual calls

Base URL for every endpoint below: `https://automation.airtimetocash.com`. All are **POST**, JSON request bodies, with `Accept: application/json` and `Content-Type: application/json`. “Bearer” means `Authorization: Bearer {configured developer token}`. OTP endpoints have no Bearer token under the supplied contract.

| Stage / path | Auth | Required body | Success / fields used | Actual Vendify behavior |
| --- | --- | --- | --- | --- |
| Quota `/api/v1/check/quota/availability` | Bearer | `networkName`, `amount` | HTTP 200 + code 5030 + exact `Recipient(s) Available`; general code 2000 also normalizes as success | Called before generating OTP. Exact positive 5030 continues into `generate/otp`; it does not create a recipient, initiate transfer, or credit a wallet. |
| Generate `/api/v1/generate/otp` | Basic JSON headers | `networkName`, `sender` | code 2000 | Moves to awaiting OTP. No provider session ID yet. |
| Verify `/api/v1/verify/otp` | Basic JSON headers | `networkName`, `sender`, `otp` | code 2000 plus `data.sessionId`; numeric `data.airtimeBalance` | Saves encrypted session ID and safe balance, then moves to PIN entry. Missing session ID is setup uncertainty, not transfer uncertainty. |
| Existing-session login `/api/v1/login/with/session/id` | Bearer | `networkName`, `sender`, `sessionId` | code 2000 plus session ID/balance | Used on resume of an existing verified session. The documentation does not require an extra login immediately after successful OTP verification. This endpoint retrieves an existing session, not a transaction status. |
| Transfer `/api/v1/transfer/airtime` | Bearer | `networkName`, `sender`, integer `amount`, `reference`, `pin`, `sessionId` | code 2000 with positive `data.amountConverted`; exact amount match required for settlement. `automationCharges` saved as fee. | Called only from the convert action after PIN validation and a durable transfer claim. No separate PIN-verification endpoint exists; the transfer request carries the SIM PIN and initiates transfer. |
| Recipient creation | None documented | None documented | None documented | No invented call. API checks availability of recipients and returns recipient information with transfer success. |
| Status lookup / reconciliation | None documented | None documented | None documented | Automation implements no transaction lookup capability. Session login is not a substitute. |

The documentation presents endpoints, but does not mandate a rigid quota-before/after-OTP ordering. Vendify checks recipient readiness early; the successful mocked sequence proves that positive quota continues to OTP, then waits for the customer, then verifies OTP, then waits for PIN, then transfers. There is no missing documented recipient-creation call after 5030. Availability can change after the initial check; a transfer response must still be evaluated independently.

Common documented JSON codes: 2000 success; 3000 failure; 4000 pending/uncertain delivery requiring manual intervention; 4030 forbidden; 4010 session expired; 4290 rate limit; 5030 unavailable. Only quota's exact positive 5030 message has the special success interpretation. HTTP 401/403 and HTTP 400 + code 4030 are authentication errors. HTTP 429 is throttling; HTTP 500 and transport failure are uncertain. Other non-2xx replies normalize as failures. OTP-invalid responses return to OTP entry; authentication rejection is terminal; verification expiration expires verification. Transfer failures remain canonical `failed`, except existing explicitly correctable PIN/balance rejections which return to PIN entry. No raw provider response prose is shown/logged.

## PIN, references and external submission

Customer `Confirm conversion` calls `customerService.convertProviderAirtimeToCash(id, pin)` → `POST /api/customer/airtime-to-cash/{id}/convert` → `AirtimeToCashProviderController::convert` → `AirtimeToCashProviderService::convert` → `AutomationProvider::convert` → `ProviderTransport::post`.

The sensitive-input middleware removes PIN/OTP from ordinary request inputs; controller validation requires a four-digit SIM PIN. The service now also validates the PIN defensively. The service requires a pending main status, `ready_to_transfer`, unexpired verification, an encrypted identifier and sufficient known airtime balance. It claims `processing` under a lock before external I/O to prevent concurrent duplicate transfers. Only that claimant calls the provider. A duplicate convert cannot submit again.

`ATC-` plus UUID is **40 characters**, matching the documented reference length of 10–40. It is locally generated but explicitly sent as the provider's required `reference`, stored immutably in `provider_reference` and `transaction_reference`. It is not an independently generated provider transaction ID.

The provider-generated secret `data.sessionId` is stored encrypted as `provider_identifier`, reused for session login and transfer, hidden from customer/admin serialization, and cleared at terminal/settled states. The supplied transfer response contains `recipient`, balances, converted amount, charges, and session ID. It documents no recipient-registration ID, separate transaction ID, or lookup token. No Automation identifier is used for API reconciliation because no lookup endpoint is documented. The separate 2FAST adapter supports transaction lookup by the submitted reference; that capability must not be assumed for Automation.

## State changes and safety fixes

- Introduced `setup_review` for inconclusive pre-transfer setup/OTP responses. It cannot progress to provider-confirmed or financial reconciliation. It reserves only the existing verification session until safe expiration; it does not represent a submitted transfer. Main `pending` remains the general unfinished-record status, not proof of delivery.
- Historical `manual_review` rows with zero transfer attempts are serialized as `setup_review` without rewriting history. Their messages explicitly say no transfer was submitted. Existing safe expiry cleanup handles both shapes.
- Progress uses explicit stages. `ready_to_transfer` shows verification completed and confirmation **in progress**, with PIN entry. Setup review leaves confirmation not started. The response includes `transfer_attempted` so legacy setup states cannot manufacture progress.
- `recordResult()` will not settle or turn a zero-attempt record into transfer progress. Unexpected or late success data cannot credit a setup-only record.
- Added a typed transport preflight failure for known no-dispatch cases: invalid destination configuration, disabled live calls or missing credentials. Automation logs it as not sent and the local conversion becomes canonical failed, releasing the reservation. It cannot sit in financial pending merely because configuration blocked HTTP dispatch.
- A timeout after dispatch is **not proof that no transfer exists**. It must remain protected against duplicate submission even if no response/visible dashboard history is available. This is different from the proven preflight no-dispatch case. `provider_attempt_count` records the durable claim; it alone does not prove delivery. Logs separately report dispatch attempt and confirmed success.
- Confirmed success still uses the existing locked, idempotent wallet settlement. No wallet credit from OTP/session/quota alone. No automatic retry, failover or second transfer was added.

## Why Reconcile appeared to refresh

Admin `airtimeToCashProviderService.reconcile(id)` posts to `/api/admin/airtime-to-cash/{id}/reconcile`. Previously the backend saw that Automation lacked `LooksUpTransactions`, moved local `provider_pending` to `manual_review` (or returned an already reviewed row), and returned success without making any provider call. The UI simply replaced its row; the main status stayed pending. Pre-transfer manual-review rows could enter this path too.

Now zero-attempt reconciliation is rejected with a setup-specific explanation. Automation's unsupported lookup is rejected explicitly, without pretending to have checked the provider or changing status. The admin UI shows setup guidance or provider-support review guidance instead of an ineffective Reconcile button. Existing local confirmed/settlement-pending wallet settlement remains actionable; 2FAST's existing lookup remains supported.

## Safe instrumentation

`airtime_to_cash.provider_call` structured records include provider, operation, correlated internal reference, HTTP status, numeric provider code, allowlisted semantic message, success, dispatch-attempt flag and confirmed-transfer-success flag. No documented provider transaction ID exists, so that field is null. Health checks have no conversion reference. Operations are quota, otp, verify, session, convert and health.

Sanitized message means a known semantic value such as `success`, `unknown`, `auth_error`, `invalid_pin` or `transport_preflight_rejected`, never arbitrary provider prose. Provider messages can echo secrets or customer data. No API key, password, OTP, PIN, token, authorization header, phone number, session ID or raw payload/response is logged. Correlation is request-scoped and restored in `finally`; logging failure never changes the transfer result or triggers a retry. The tests include provider prose containing secret sentinels and verify those strings never enter logs.

## Live verification after separate approval

For the affected existing row inspect `provider_status`, `provider_attempt_count`, encrypted-identifier presence (not value), main status, reference, timestamps and payout evidence. A pre-transfer manual review with attempt count zero explains no PIN/transfer, but identify which operation failed only from safe call evidence or an operator-authorized new test. Do not retrospectively claim a particular HTTP response without evidence.

For an approved future controlled test:

1. Capture the internal reference. Expect quota → otp trace records, followed by verify after entering the OTP.
2. Before PIN entry: PIN form visible, confirmation in progress, transfer-attempt count zero, no convert trace, no wallet credit.
3. After explicitly entering PIN and confirming: one convert request using the same reference/session; inspect HTTP/code/semantic result. Positive quota/OTP/session logs alone are not evidence of A2C activity.
4. Documented proof of completion is code 2000 with positive matching `amountConverted`. Check one wallet credit and, where the provider exposes history, the corresponding amount/reference/time. Code 4000/HTTP 500/timeout requires provider investigation; no blind repeat.
5. Recipient count is not documented to increase per conversion, and no recipient registration call is specified. The supplied API docs do not define dashboard counter/history timing, filtering, account scope, or behavior for rejected pre-transfer requests. Therefore no recipient-count increment or immediate failed-history row can be promised. A completed transfer should have provider-side evidence, but the exact dashboard location/counter requires provider confirmation. Zero dashboard counters alone cannot establish whether a request was sent, rejected before recording, timed out, or inspected under a different account/filter.

## Validation

Contract/regression tests cover documented success/failure/auth/quota/session/transfer shapes, session/reference persistence, successful OTP+PIN reaching the actual transfer URL, no transfer without PIN, missing-session verification staying in setup, legacy setup serialization, rejection of unsupported reconciliation, transport-preflight failure versus uncertain delivery, secret-safe correlated telemetry, no settlement before transfer evidence, and existing duplicate-transfer/wallet-credit safety. UI tests check the confirmation step remains incomplete after OTP until PIN submission, setup does not fake completed stages, PIN renders correctly, and unsupported admin actions are not offered.

Local validation completed:

- Backend Airtime-to-Cash suite: 96 tests, 678 assertions, exit 0. Existing PHP deprecation warnings remain.
- Customer/admin Airtime-to-Cash UI: 23 tests passed.
- TypeScript/Vite production build passed.
- ESLint on all six modified frontend files passed.
- Pint on modified backend flow/adapter/test files passed; both repository whitespace checks passed.

No live provider call or deployment was made.
