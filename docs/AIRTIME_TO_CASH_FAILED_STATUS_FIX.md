# Airtime-to-Cash canonical failure status

## Root cause and trace

Automated `start()` creates `airtime_to_cash_requests` with `status=pending` and a separate `provider_status=created`. The adapters normalize upstream responses into `ProviderResult`. Both direct transfer responses and explicit admin reconciliation reach `AirtimeToCashProviderService::recordResult()`. Pre-transfer responses reach `finishPreTransfer()`.

Previously, terminal failure changed only `provider_status=failed`. The customer controller returned that field as `state`, causing the red failure screen. Admin `adminIndex()` and customer history serialized the unchanged `status=pending`. Admin badges, status filters, Needs review and Pending payout counters all used that main status. This was a persisted lifecycle mismatch, not a styling problem.

Reference reported by the user: `ATC-eeadbdf5-fd89-4b9b-a7db-4f172671816a`. The supplied log does not contain this reference or its upstream response. No production record or database was accessed. Tests reproduce the reported mismatch and use that reference in the admin fixture; its actual upstream rejection reason remains unverified.

## Corrected lifecycle

- Manual: `pending -> approved/rejected`, unchanged.
- Automated: main status remains `pending` through verification, processing, provider pending, manual review and settlement pending. A terminal provider failure persists `status=failed` in the same save. Successful settlement retains the established `status=approved`, with `provider_status=completed`.
- The model enforces terminal failure consistency, clears the session/reservation and prevents reopening a failed record or marking a confirmed/paid record failed.
- Customer status/start/verify/convert/restart responses now also include the main `status`. The existing `state` field remains for step navigation and older clients. The current customer frontend uses `status` for failure/completion; admin and history use the same persisted field.
- Admin uses its existing Failed badge, adds a Failed filter, and prevents actions on terminal provider rows. Existing pending counts and amounts naturally exclude failed records. Automated remains a count of all automated requests, including historical failures.

## Provider semantics and refresh

AirtimeToCash Automation: general explicit failure remains terminal; direct authentication rejection now terminates the conversion. Existing recognized invalid-PIN/low-balance rejections remain correctable at the PIN step with an explicit customer retry. That return to the PIN step is restricted to a direct processing response. Existing expired-session states can restart verification and remain pending. Unknown/timeout/server/rate-limit results do not become final failure. Quota uncertainty is no longer rewritten to an explicit failure.

2FAST: existing direct error normalization remains; a matching Airtime-to-Cash wallet-history row with status Failed now persists main status failed through the shared service. Matching Successful history settles once. Unknown, not-found, wrong-reference, wrong-service or failed lookup authentication do not establish delivery failure.

There are no automated A2C callback routes, scheduled polls or queue jobs. Customer Refresh status reads local storage only. Explicit admin Reconcile uses 2FAST history; Automation has no transaction lookup and goes to manual review for ambiguous outcomes. Neither path resends airtime automatically.

Failure reasons stay in the existing sanitized `provider_message` field. Raw upstream payloads, PINs, OTPs and session identifiers are not exposed. References and history are preserved. Generic existing failures cannot retrospectively recover a discarded upstream reason.

## Migration and wallet safety

`2026_09_11_000000_persist_failed_airtime_to_cash_status.php` widens the existing status enum to include failed. Backfill requires all of: provider processing mode, main pending, provider failed, no provider confirmation, no payout reference and no linked payout transaction. It changes only the status and terminal session/reservation fields. It does not alter rates, wallet balances, references, messages or timestamps. Rollback does not erase failed history.

SQLite enum rebuilding disables foreign keys outside a migration transaction to preserve linked payouts; repeating the migration skips an already widened SQLite schema. Production MySQL migration execution has not been tested here.

Settlement remains atomic and requires authoritative confirmation and a pending main status. The existing unique payout link prevents double credit. Tests cover duplicate failure/success results, duplicate reconciliation, attempts to settle failed records, late success after terminal failure, and backfill exclusion of linked payouts.

## Validation

- Backend A2C suite: 78 tests, 498 assertions; no failures (existing PHP 8.5 deprecation notices).
- Customer/admin frontend suites: 14 tests passed.
- Frontend TypeScript and production build passed; targeted ESLint passed.
- Local tests exercise both API statuses, customer failure rendering, admin Failed badges/filter, counters, provider-specific outcomes, timeout handling, backfill exclusions and manual review/settlement regressions.

## Deployment and manual verification

Deploy the backend and run `php artisan migrate --force` before deploying the updated frontend. No production operation was performed as part of this fix.

1. Inspect the reported reference before/after migration. If it meets the exact backfill conditions, its main status must become failed while its reference, reason and payout state remain unchanged. Conflicting confirmation/payout evidence must remain untouched for investigation.
2. As admin, open Airtime to Cash > Requests and select Failed or All statuses (the existing default filter is Pending). Search the reference. Verify the Failed badge, no Approve/Reconcile action, and exclusion from Needs review and Pending payout. The Automated historical count should still include it.
3. As its customer, check request history and the status endpoint. Both must return/display failed; the endpoint retains `state=failed` and the safe failure message. Confirm the wallet balance and payout ledger have not increased.
4. For a newly authorized real conversion, verify success produces one payout and approved/completed states; refresh/reconciliation must not pay twice. Do not resend a transfer whose delivery is unknown.
5. Confirm a manual pending request remains pending and can still be approved or rejected through its existing workflow.

## Files changed

Backend: `app/Models/AirtimeToCashRequest.php`, `app/Services/AirtimeToCash/AirtimeToCashProviderService.php`, `app/Services/AirtimeToCash/ProviderState.php`, `app/Http/Controllers/AirtimeToCashProviderController.php`, the migration above, `tests/Feature/AirtimeToCashProviderFoundationTest.php`, and this document.

Frontend: `src/features/admin/pages/airtime-to-cash/{service.ts,requests-tab.tsx,index.test.tsx}`, `src/features/user/services/customerService.ts`, `src/features/user/pages/airtime-to-cash/automated.tsx`, `src/features/user/pages/airtime-to-cash.test.tsx`, and `tools/qa/harness-fixtures.mjs`.
