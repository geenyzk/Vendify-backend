# Airtime-to-Cash Phase 2 provider foundation

Prepared locally on 7 September 2026. This phase adds a disabled provider architecture around the Phase 1 manual flow. It has not contacted either provider, collected production credentials, modified production rate data, or been deployed.

## Architecture

`AirtimeToCashProviderInterface` defines provider identity, supported networks and limits, capabilities, OTP request/verification, conversion, normalization, and retry/terminal decisions. Optional `ChecksQuota`, `ChecksSession`, and `LooksUpTransactions` interfaces keep provider-specific abilities explicit.

`AirtimeToCashProviderManager` merges server configuration with safe database settings, selects an enabled/configured adapter by priority before a session starts, validates network and provider limits, and resolves an existing request only from its persisted provider key. It never fails over a started conversion. Provider mode requires both global gates in production; tests may exercise adapters while live calls remain disabled.

`AirtimeToCashProviderService` owns quote, start, OTP verification, conversion, and settlement handoff. A hashed client idempotency key prevents duplicate starts, and a unique active-SIM key prevents simultaneous sessions for the same network/SIM. The provider choice, amount, payout, rate snapshot, and provider reference become immutable once the request is created. The same reference is used for every conversion or lookup.

State transitions are validated by `ProviderState`:

```text
created -> awaiting_otp | ready_to_transfer | manual_review | failed
awaiting_otp -> verifying_otp | expired
verifying_otp -> awaiting_otp | ready_to_transfer | expired | manual_review
ready_to_transfer -> processing | expired
processing -> provider_confirmed | provider_pending | failed | session_expired
provider_pending -> provider_confirmed | failed | manual_review
manual_review -> provider_confirmed | failed
expired | session_expired -> created
provider_confirmed -> settlement_pending | completed
settlement_pending -> completed
completed | failed -> terminal
```

The historical `status` values (`pending`, `approved`, `rejected`) remain the compatibility/customer settlement status. Provider lifecycle is stored separately in `provider_status`.

## Provider A

Contract source reviewed: [AirtimeToCash Automation API documentation](https://automation.airtimetocash.com/api/documentation).

The `airtime_to_cash_automation` adapter implements the documented MTN, Airtel, Glo, and 9mobile mappings and limits, quota check, OTP request, OTP verification, session login support, and airtime transfer. Protected requests use the server-side Bearer token. Transport permits HTTPS to the configured provider host only, rejects redirects, has bounded timeouts, and does not automatically retry or log request/response bodies.

Documented JSON codes normalize to internal states: 2000 success, 3000 failed, 4000 pending, 4030 authentication error, 4010 session expired, 4290 rate limited, and 5030 unavailable. HTTP 500 and malformed/ambiguous conversion responses are unknown. Conversion success also requires a positive converted amount; a mismatched amount cannot settle. The provider documentation currently uses code 5030 in contradictory quota examples, so this implementation treats every 5030 quota result as unavailable.

## Provider B

Contract source reviewed: [2FAST Business API documentation](https://www.2fast.com.ng/docs).

The `2fast` adapter implements the three documented `/api/Airtime-To-Cash` steps for MTN (`1`) and Airtel (`2`), including the documented skip-OTP path, identifier and airtime-balance extraction, and immutable Vendify reference in step 3. The provider docs publish maximums of ₦10,000 for MTN and ₦20,000 for Airtel but no minimum; Vendify and integer-amount validation still apply.

The same documented `status: success` appears in both completed and awaiting-confirmation examples. The adapter therefore accepts completion only when the documented credited-completion wording is present, maps the documented awaiting-confirmation wording to pending, and treats other success wording as unknown. The credited NGN amount is parsed into the staff-only provider cost field without treating it as the customer payout. A 409 duplicate-reference result is unknown pending reconciliation. Transaction-history lookup is authoritative only when the response is wallet history, the reference matches exactly, the type is Airtime To Cash, and status is Successful.

## Database changes

Migration `2026_09_06_000000_add_airtime_to_cash_provider_foundation.php` adds `processing_mode` with a `manual` default; nullable network/provider/reference/encrypted identifier/status/message/timestamps; attempt counters; a rate snapshot; allowlisted provider metadata; nullable provider cost/fee; and unique start/active-session keys. It also creates `airtime_to_cash_provider_settings` for safe enable/priority values.

Existing records receive `processing_mode = manual` and nullable provider fields, so the Phase 1 approval/rejection path remains valid. Rollback refuses to remove the schema if provider-mode evidence exists. No rate seeder or production data mutation is part of this phase.

## Security

OTP and PIN routes require authenticated secure sessions, reject impersonation, require HTTPS, and are throttled. `SanitizeAirtimeToCashSecrets` removes PIN/OTP/secret-like fields from request input and JSON before the controller, passes them in a short-lived memory-only `RequestSecrets` object, clears that object in `finally`, and Laravel is configured never to flash those fields. PIN and OTP parameters use PHP sensitive-parameter annotations and are unset after each adapter call.

PIN and OTP are absent from request fillable fields, database columns, audit metadata, responses, notifications, jobs, and logs. Provider identifiers use an encrypted model cast and are hidden from serialization; only an allowlisted numeric airtime balance may enter provider metadata. Tokens live only in environment configuration, never appear in the admin/customer contracts, and transport exceptions are reduced to controlled states without raw bodies or exception context.

## Settlement

Provider success moves a request to `provider_confirmed` and calls the existing `AirtimeToCashSettlementService`. The service still locks the request and wallet owner, checks the unique payout relationship, credits once within one database transaction, and preserves the stored customer payout. Provider-mode settlement requires the persisted provider/reference plus authoritative confirmed state; manual-mode settlement still requires an admin reviewer. A temporary settlement failure leaves `settlement_pending` evidence for a later explicit retry.

## Pending transactions

Pending, unknown, malformed, HTTP 500, or unproven duplicate responses never credit a wallet and never trigger another transfer or another provider. Customer status reads are local only.

`AirtimeToCashReconciliationService` is explicitly invoked and rate-limited; there is no scheduled polling. 2FAST uses transaction history with the original reference and strict evidence checks. AirtimeToCash Automation has no documented transaction lookup, so pending requests move to manual review for provider-support reconciliation. Unknown/not-found lookup results are not proof that no transfer happened and do not unlock failover or retry.

## Admin

Admin → Airtime to Cash → Configuration now shows the two adapters, configured/credential state, enable switch, priority, capabilities, supported networks, and documented limits. It never exposes or accepts tokens and does not run health calls while rendering. Provider mode and the independent live-call gate are visible. Automatic failover is explicitly shown as disabled.

The Requests queue distinguishes automated state from the legacy status. Manual requests retain approve/reject controls. Automated requests cannot be manually approved or rejected through those controls; eligible uncertain requests expose explicit reconciliation.

## Frontend

When the provider availability endpoint is true, customers explicitly choose automated or manual conversion before starting. Automated flow supports canonical network selection, server quote/exact payout, sender SIM, OTP, optional reported airtime balance, masked transfer PIN, conversion, local status refresh, expiration restart, and completed/pending/failed messages. Provider names, internal fees, identifiers, and raw messages are never shown. OTP and PIN remain component memory only and clear after submission. Manual fallback remains available before a provider session starts, with an accessible method switch.

## Tests

Run before deployment:

```text
php artisan test tests/Feature/AirtimeToCashProviderFoundationTest.php
php artisan test --compact --filter=AirtimeToCash
npm test -- src/features/user/pages/airtime-to-cash.test.tsx src/features/admin/pages/airtime-to-cash/index.test.tsx src/features/user/services/customerService.test.ts
npm run build
npx eslint <changed Phase 2 TypeScript files>
git diff --check
```

Final local results:

- `php artisan test --compact --filter=AirtimeToCash`: 65 tests, 361 assertions, exit 0. The runner labels them deprecated because the repository emits existing PHP 8.5 PDO constant deprecations; it also discovers an unrelated existing ineffective import warning in `WhatsAppSupportRoutingTest`.
- Focused frontend Vitest command: 3 files, 16 tests, exit 0.
- `npm run build`: TypeScript and Vite production build passed.
- Targeted ESLint across the eight changed Phase 2 TypeScript files: exit 0.
- Pint check across the Phase 2 PHP files and `git diff --check` in both repositories: exit 0.

The backend suite uses Laravel HTTP fakes with stray-request prevention; frontend suites mock API calls. No test contacted a live provider.

## Environment variables required

Names only:

```text
AIRTIME_TO_CASH_PROVIDER_MODE_ENABLED
AIRTIME_TO_CASH_LIVE_CALLS_ENABLED
AIRTIME_TO_CASH_AUTOMATION_BASE_URL
AIRTIME_TO_CASH_AUTOMATION_TOKEN
AIRTIME_TO_CASH_AUTOMATION_ENABLED
AIRTIME_TO_CASH_2FAST_BASE_URL
AIRTIME_TO_CASH_2FAST_TOKEN
AIRTIME_TO_CASH_2FAST_ENABLED
```

All enable flags default to false and token values are blank in `.env.example`.

## Deployment plan

Create one isolated backend commit and one isolated frontend commit, excluding the unrelated frontend `AuthLayout.tsx` worktree change. Stop before pushing because `main` is the production branch and a push triggers cPanel deployment.

After a separate deployment authorization and live-activation review:

1. Deploy the backend commit first.
2. Run `php artisan migrate --force`. Do not run an Airtime-to-Cash seeder and do not modify rate rows automatically.
3. Keep every new provider flag false; verify Phase 1 manual requests, approval, and idempotent settlement.
4. Deploy the frontend commit and verify manual mode remains the only customer path while provider mode is off.
5. Configure credentials server-side only, then validate each provider in an approved sandbox/test account using controlled amounts.
6. Enable one provider setting and provider-mode gate only after the remaining contract questions are resolved. Enable the independent live-call gate as the final deliberate activation step.
7. Perform customer/admin desktop and mobile checks, and verify pending/unknown results do not credit or enable retry.

Rollback the code by reverting the frontend and backend commits. The migration down command is unsafe after any provider request exists and deliberately refuses; retain transaction evidence and roll forward instead.

## Remaining questions

Confirm with AirtimeToCash Automation before activation:

- Which quota response definitively means recipient capacity is available, given the contradictory 5030 examples?
- Does HTTP 500 after `/transfer/airtime` mean accepted/pending, and what official reference-based status or support process proves the outcome?
- Are OTP/session TTL, OTP attempt limits, and restart rules fixed by the provider?
- Is `amountConverted` guaranteed on every successful transfer, and are charge/cost fields and units stable?

Confirm with 2FAST before activation:

- What exact machine-readable field distinguishes completed conversion from awaiting confirmation, instead of relying on published message wording?
- What is the Airtime-to-Cash minimum for each network?
- What exact response identifies a duplicate reference, and should lookup be immediate or delayed?
- Does transaction history paginate/filter, and is `wallet_history` plus exact type/status/reference the complete authoritative proof contract?
- Are identifier lifetime, OTP length/attempt limits, and restart behavior guaranteed?

For Vendify operations, approve provider-specific customer limits, reconciliation ownership/runbook, timeout/alert thresholds, and test-account/SIM procedures before either live gate is enabled.
