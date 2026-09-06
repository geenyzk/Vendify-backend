# Manual Airtime-to-Cash: Phase 1 audit and verification

## Scope and evidence

Local implementation and tests only. Production has not been accessed or modified. The user will verify the live deployment manually. No provider integration, OTP, transfer PIN, callbacks, polling, quotas or provider API was added. Manual transfer → pending request → admin approval/rejection remains the workflow.

Audited starting revisions:

- Backend: `58c8e79420ec76d4b521141f7569da69982e77ef` (includes the earlier case-insensitive lookup fix).
- Frontend: `0a53c0a849b9d23ec1f22895cee548c016c7d450`.

The frontend already had an unrelated working-tree change in `src/features/auth/components/AuthLayout.tsx`; this task does not modify or commit that change.

## Root cause: what is proved

The exact string `Airtime to cash is not available for this network yet.` existed in three backend branches and a frontend fallback:

1. `VTUServicesController::discountPreview`: `$service === 'airtimeToCash' && ! $discount`, where `$discount = Discount::findApplicable($service, $network)`. **This is the branch responsible for that message from the review quote request. It does not resolve a Network row at all.** An enabled flag/destination/min/max cannot make this quote succeed without an applicable discount/rate.
2. `AirtimeToCashController::submit`: a failed network lookup, filtered by `airtime_to_cash_active = true`.
3. The same submission method: no applicable rate after resolving the network.
4. The customer component used this string as the fallback when its quote request failed.

The review’s `You receive: Confirmed on review` came from `payoutQuery.data?.final_amount ?? null`: after a failed quote, `isPending` became false and a null payout selected that literal fallback. It was not a business rule for determining payout after submission. The starting frontend already disabled submission for a pending/failed quote or null payout; the misleading copy and late discovery remained problems.

**Production finding is not established:** we cannot claim the live MTN rate is absent rather than inactive, expired, incorrectly scoped, or served by an older deployed revision. On the audited code, an actual quote response containing the old generic message proves `findApplicable` returned null. It does not prove why. Use the checklist below to classify the production data before saving configuration.

## Pricing findings

`Discount` is the existing rate model/table; no separate conversion-rate table exists. The relevant contract is:

| Field | Meaning |
| --- | --- |
| `service_type` | Exact service string `airtimeToCash`; `airtime`, `airtime_to_cash` and other spellings do not match. |
| `network` | Legacy nullable text scope. Historically `mtn`, `airtel`, `glo`, `9mobile`; null is an all-network default. |
| `discount_type` | `percentage` or `fixed`, both **deductions**, not payout percentages. |
| `value` | Deduction percentage or naira amount, stored to two decimal places. |
| `active` | Boolean cast on the model. |
| `starts_at`, `ends_at` | Optional dates; inclusive calendar-day window using the application timezone. |

`isCurrentlyActive()` requires `active` and a current date inside the window. The old lookup compared lowercase network strings, preferred a network-scoped rule over a null default, and chose the first matching active rule. It did not map T2/Etisalat aliases. Existing case normalization already handled `MTN` versus `mtn` in this checkout.

`AirtimeToCashRatesSeeder` defines 5% deductions for the four legacy network names using `firstOrCreate`. Its existence does not demonstrate that it ran in production or that current records are active. No seeder or data repair has been run by this task.

Payout remains `round(amount - deduction, 2)`: ₦500 less 5% = ₦475; fixed ₦25 from ₦500 also = ₦475. The shared `Discount::payoutFor()` now applies an already-selected rule once. New quotes and submissions never fall back to a full payout when no valid rate exists. Pending requests retain their stored `payout_amount` even if configuration subsequently changes.

## Actual starting flow

All endpoint paths below include the `/api` prefix.

| Step | Frontend function / state | Endpoint | Backend and fields / identifiers |
| --- | --- | --- | --- |
| Network catalog | `AirtimeToCashPage` → `customerService.getNetworks()` | GET `/customer/catalog/networks` | `CustomerCatalogController::networks` → `Network`; `id`, `name`, `airtime_to_cash_active`, destination/min/max. UI filtered only the enabled flag and held `name` in selection state. |
| Amount / sender / proof | Component state | None | Local min/max and sender checks; optional image. No provider operation. |
| Quote on entering review | `customerService.previewDiscount('airtimeToCash', network, amount)` | GET `/vtu/airtimeToCash/discount?network=MTN&amount=500` | `VTUServicesController::discountPreview` → `Discount::findApplicable` and `getDiscountedAmount`; name string, no network ID/config check. Rate ID was not returned; payout exposed as `final_amount`. |
| Submit | `handleConfirm` → `submitAirtimeToCash` | POST `/customer/airtime-to-cash` (multipart) | `AirtimeToCashController::submit`; `network_id` preferred; legacy case-insensitive `network` fallback; `amount`, `sender_phone`, optional `proof_image`. Network active + limits + rate checked, destination presence not checked. |
| Pending request | `Submissions` → `getMyAirtimeToCashRequests` | GET `/customer/airtime-to-cash` | Controller scopes to authenticated user. `AirtimeToCashRequest` stores canonical row name, amount, destination snapshot, payout snapshot, sender, proof, reference, `pending`. No rate ID or network FK is stored in this existing request table. No wallet credit on submission. |
| Admin queue | Requests tab `load` → `airtimeToCashRequestService.getAll` | GET `/admin/airtime-to-cash` | `adminIndex`, eager-loaded user/reviewer; admin + `airtime_to_cash` permission. |
| Approve | Request service `approve(id)` | POST `/admin/airtime-to-cash/{atc}/approve` | Controller → `AirtimeToCashSettlementService::settle`; request ID, not network/rate identifier. Locks request and user; pending/duplicate-payout guards; creates one successful `airtime_to_cash` credit transaction, increments wallet, sets reviewer/date/payout reference and approved status atomically. Uses stored payout; no re-pricing. |
| Reject | Request service `reject(id, reason)` | POST `/admin/airtime-to-cash/{atc}/reject` | Controller locks request, checks pending/no payout, stores reason/reviewer/date and rejected status; no wallet credit. |
| Generic network config | `networkService.update` | PUT `/table/networks/{id}` | `AdminController::universalCreateOrUpdate`/bulk writer; column filtering and model writes; no conversion validation. |
| Rate admin | Growth `discountService` | GET/POST `/admin/discounts`, PUT/DELETE `/admin/discounts/{id}` | `DiscountController` and `Discount`. Separate from network configuration and gated by settings permission. |

## Identifier and code audit

- **Database `networks.id` is the public canonical identifier.** Selection, quote, submission and configuration now use it. A supplied invalid ID never falls back to a valid name.
- `name` remains display text and the bridge to historical discount/request rows. Legacy clients may still send `network`; one backend resolver handles case/whitespace and supported aliases. Ambiguous duplicate legacy names are rejected instead of selecting an arbitrary row.
- `code` is not a column in the repository’s network schema, is absent from the model’s fillable fields and API resource, and is not used by the conversion lookup. The generic writer drops non-column fields. The old frontend nevertheless displayed and required it: that was a UI/data-contract mismatch. Creation/edit no longer requires or displays that phantom field. No codes were filled in and no migration was added.
- `slug` is not a Network field. Provider codes, bank codes and provider-specific `airtime_api_id`, `data_api_id`, etc. are different concepts and remain intact. Normal airtime/data product configuration is retained.

| Existing spelling | Internal historical rate key | Public request identifier |
| --- | --- | --- |
| MTN / mtn | `mtn` | Selected `networks.id` |
| Airtel / airtel | `airtel` | Selected `networks.id` |
| Glo / GLO / glo | `glo` | Selected `networks.id` |
| `T2`, `9mobile`, `Etisalat`, `T2 / 9mobile` | `9mobile` | Selected `networks.id` |

These conventions are exercised by local tests. **Live row IDs, duplicates, live code/schema differences and rate contents for all four networks remain to be checked by the operator.** ID-based contracts do not require rewriting existing historical rows.

## Backend changes and resulting contract

- `app/Services/AirtimeToCashAvailabilityService.php`: authoritative resolver, legacy alias bridge, rate selection, availability and quote. Requires a network, conversion enabled, valid 11-digit Nigerian destination, min > 0, max ≥ min, exactly one applicable active rate at the chosen specificity, positive payout across the accepted range, and requested amount within range. Duplicate applicable active rates fail closed. Specific rates take priority over all-network defaults. General network `active` remains the airtime/data control; conversion has its own enable flag.
- `AirtimeToCashController.php`: conversion catalog; proper quote response; submit uses the same policy, validates sender and compares the optional client-reviewed payout/destination with current values. Legacy clients remain compatible, but still cannot submit without an applicable rate. The new frontend always sends the reviewed payout and destination.
- `VTUServicesController.php`: routes the existing conversion discount-preview URL to the authoritative quote; other services keep their existing preview behavior.
- `CustomerCatalogController.php`: adds `airtime_to_cash_available` and `airtime_to_cash_reason` using a current policy evaluation, not a cached rate/date-window result.
- `AirtimeToCashConfigurationController.php`: dedicated permission-gated list and atomic network/rate save. On an explicitly saved network, updates one scoped rate, deactivates duplicate scoped rows, preserves global defaults, validates enabled configuration, and rolls back both records on failure. No automatic production data repair. Saving can intentionally change dates/type/value/active state; those controls are visible.
- `AdminController.php`: rejects conversion-specific network fields through generic single/bulk/reorder writes, preventing validation bypass. Other network fields remain editable.
- `Discount.php`: reusable calculation for an exact selected rule; conversion-specific legacy lookup uses the same alias/rate resolver. Other discount services retain their existing selection rules.
- `routes/api.php`: adds customer catalog and admin configuration routes. Configuration uses the existing admin + `airtime_to_cash` permission boundary, so conversion administrators need not use Growth/settings as their primary surface.
- Tests extend `tests/Feature/AirtimeToCashTest.php`; the existing settlement suite remains intact.

New/normalized endpoints:

```text
GET /api/customer/airtime-to-cash/networks
GET /api/vtu/airtimeToCash/discount?network_id=7&amount=500
GET /api/admin/airtime-to-cash/configuration
PUT /api/admin/airtime-to-cash/configuration/7
```

Successful quote example (inside existing `success/message/data/type` envelope):

```json
{
  "network_id": 7,
  "network": "mtn",
  "amount": 500,
  "available": true,
  "reason": null,
  "rate_id": 8,
  "rate_type": "percentage",
  "rate_value": 5,
  "payout_amount": 475,
  "final_amount": 475,
  "destination_number": "08030000000"
}
```

IDs/numbers above are illustrative, not production facts. Unavailable quotes return HTTP 422, `success:false`, `data.available:false`, `data.reason` and a matching message. `final_amount` is retained for older clients. No provider details are exposed.

## Frontend changes

- `admin/pages/airtime-to-cash/index.tsx`: Requests (default) and Configuration, with `?tab=configuration` deep link.
- `configuration-tab.tsx` and `service.ts`: per-network logo/name, enable switch, destination, min/max, deduction type/value, rate-active flag, date window, calculated example and Save. Shows current backend availability and server validation errors. Invalid required configuration blocks saving/enabling. No duplicated rate storage.
- Generic network create/edit removes phantom code/provider inputs and conversion controls. Edit links to conversion configuration; Growth discounts also points operators there. Generic Growth records remain available for compatibility, but customer availability is always revalidated server-side.
- `user/services/customerService.ts`: fresh dedicated catalog, ID-based quote, ID-based multipart submission with reviewed values. The conversion catalog bypasses the one-minute module memo and the five-minute generic persisted catalog query.
- `user/pages/airtime-to-cash.tsx`: select ID → enter amount → obtain payout → see current quoted destination → transfer → review payout/deduction → submit. Missing/failed/pending/mismatched quotes block progression and submission. Payout never displays “Confirmed on review.” Backend reasons and retry controls are shown. Review displays current quoted destination rather than a stale catalog snapshot.
- Focused component/service tests cover both admin sections, settings display/save/error handling, payout/deduction rendering, ID-only request contract, missing-rate rejection and stale/refreshed quote gating.

## Tests and limits

Final verification:

- `php artisan test --compact --filter=AirtimeToCash`: exit 0; 51 tests, 278 assertions, no failures (runner labels tests deprecated because of existing PHP 8.5 deprecation output).
- `npm test -- src/features/user/pages/airtime-to-cash.test.tsx src/features/admin/pages/airtime-to-cash/index.test.tsx src/features/user/services/customerService.test.ts`: 3 files, 12 tests passed.
- `npm run build`: TypeScript and Vite build passed.
- Targeted `npx eslint` on all 11 changed/new TypeScript files: passed. Including the untouched requests component produced the separate existing lint finding described below.
- `git diff --check` in each repository: passed.

 Tests run on isolated SQLite (`DB_DATABASE=:memory:`) and mocked frontend API calls; they do not prove production data or production MySQL deployment state. PHP 8.5 emits existing PDO SSL-constant deprecations, and the test discovery emits an unrelated existing WhatsApp test import warning. An ESLint sweep that included the unchanged `requests-tab.tsx` found its pre-existing `react-hooks/set-state-in-effect` error at the `load()` effect; this task does not change that request-review implementation.

No connected browser was available, so no visual browser QA is claimed. The operator checklist includes desktop/mobile UI verification.

## Migration

**No new migration or automatic data seeding is required.** Uses existing Network conversion fields, Discount rate rows and manual-request/settlement schema. Verify the earlier settlement-hardening migration (`2026_09_02_000000_harden_airtime_to_cash_settlement`) is already applied; this task did not run production migrations. Do not run the rates seeder to “fix” unknown production pricing. Read the data, then explicitly save the intended deduction in Configuration.

## Operator live-verification checklist (after authorized deployment)

### 1. Record evidence before changing configuration

Open Admin → Airtime to Cash → Configuration. For MTN, Airtel, Glo and T2/9mobile record:

- Display name and **network ID**; flag duplicate names/aliases or missing networks.
- Enabled state, destination, min/max, rate ID/network scope, deduction type/value, active/date window, and displayed availability reason.
- In browser DevTools Network, inspect GET `/api/admin/airtime-to-cash/configuration`: verify the actual API fields match the screen. Do not infer `active` from a coloured badge alone.
- Inspect the corresponding customer quote response and its `network_id`, `rate_id`, `available`, `reason` and `payout_amount`.
- Classify MTN’s original rate issue: absent (`rate:null` and no matching/default rate in the historical list), inactive, expired/future, wrong `service_type`, wrong network alias, duplicate active rules, or a stale/mismatched deployed response. Save the evidence before making the corrective configuration save. The current policy understands aliases that the previous one did not.

For a full read-only historical rate audit, use existing Growth Discounts/its GET `/api/admin/discounts` response with a settings-authorized admin. Compare all rows, not just the selected rate in Configuration. A network-scoped rule wins over a null global default. An inactive/expired scoped rule can coexist with a valid global fallback.

If database access is available, these are optional **read-only** MySQL checks; they have not been executed by this task:

```sql
SHOW COLUMNS FROM networks;
SELECT id, name, active, airtime_to_cash_active,
       airtime_to_cash_destination_number, airtime_to_cash_min, airtime_to_cash_max
FROM networks ORDER BY id;
SELECT id, name, service_type, network, discount_type, value, active, starts_at, ends_at
FROM discounts ORDER BY service_type, network, id;
SELECT LOWER(TRIM(name)) AS normalized_name, COUNT(*) AS row_count
FROM networks GROUP BY LOWER(TRIM(name)) HAVING COUNT(*) > 1;
SELECT CURRENT_DATE AS database_date;
```

Also compare T2/9mobile/Etisalat rows manually: the simple SQL duplicate query does not collapse those aliases. Compare rate windows with the Laravel application date/timezone, not just the browser timezone or database date. If a live `code` column exists despite the repository schema, document that drift; it is not the new conversion identifier.

### 2. Verify configuration and permissions

For each network, use the business-approved deduction, not an assumed default. Where 5% is intentionally configured, a ₦1,000 example must show ₦950. Check fixed deductions similarly if used. Save and reload; verify destination, limits, rate and dates persist.

Check failures with a designated test network/configuration and restore its intended state afterward: missing destination, min 0, max below min, missing/inactive rate, future/expired rate and deduction producing zero payout must not enable conversion. Validation must show a reason and must not partially persist a new rate when the network save fails.

A customer and a staff account without `airtime_to_cash` must not access configuration. An authorized conversion admin must see Requests and Configuration. Products → Airtime & Data → Networks must still edit ordinary network status/name and link to the new conversion surface.

### 3. Verify customer catalog and quote before transferring

Use a customer account, fresh reload and DevTools Network. For all four networks:

1. GET `/api/customer/airtime-to-cash/networks` must expose the selected network ID and authoritative availability/reason.
2. Enter ₦500 (if inside that network’s intended range). Quote request must send `network_id=<actual ID>` and `amount=500`, not the display name.
3. With a configured 5% deduction, response and screen must both show ₦475; deduction 5%; current destination. Test min, max, one value below min and one above max.
4. Missing/inactive/expired rates must display a specific unavailable reason and prevent Continue/Submit. They must not display “Confirmed on review” or invent a ₦500 payout.
5. Change amount/network quickly: no prior quote may authorize the new selection while its request is pending. On quote failure, test Retry/Refresh quote.
6. After admin changes, return focus/reload; verify fresh configuration/quote, not an old generic catalog response. Compare the Network-panel response with rendered values if anything disagrees.

### 4. Verify manual submission and settlement

Only perform a real transfer using a deliberately chosen small test amount and the verified destination, after confirming the known payout. Record opening wallet balance and request reference.

1. Submit once. Expect HTTP 201 and one `pending` request with the correct network name, amount, sender, destination and quoted `payout_amount`. Wallet must not increase yet.
2. Requests tab must show that reference and payout. Approve once after manually verifying the transfer. Wallet must increase by exactly stored payout; one successful `airtime_to_cash` credit must link to this request and its payout reference.
3. A repeated approval must be rejected and must not credit again. Reload both accounts and verify balances/status/history.
4. For a separate controlled request, reject with a reason: status/reason visible to the customer; no wallet credit.
5. If testing a rate change after submission, existing pending payout must remain unchanged on approval. If changing a rate/destination between quote and submit, the new client’s submission must reject the stale reviewed value and require a fresh quote.
6. Confirm existing requests/proof images/history still render and normal airtime/data purchases/configuration are unaffected.

### 5. Visual and deployment checks

Check admin tabs/configuration and customer form/review at desktop and approximately 390px mobile width. Verify controls remain labelled, fields and Save fit, validation is visible, and the payout is visible before transfer instructions. Confirm new URLs return JSON, not 404/HTML or a stale frontend bundle. Record deployment run IDs and the two deployed commit hashes.

## Deployment plan — authorization required

Do not push until the user authorizes deployment. Both repositories currently deploy from pushes to their production branches (backend `main`; frontend `main`/`master` for matching paths). A push is a deployment action here.

Prepare one isolated local commit per repository for this task, excluding the existing AuthLayout change. The delivery manifest records their exact hashes. After authorization:

1. Recheck remote branch state and review any intervening changes before merging the approved commits.
2. Deploy backend first; verify the configuration/catalog routes exist and old `final_amount` clients still work. Clear/rebuild Laravel route/config caches through the established deployment procedure if they are enabled. No new migration/seeder step.
3. Deploy the approved frontend commit; verify the deploy workflow built against `https://api.vendify.com.ng/api` and the new bundle is served.
4. Operator performs the checklist above and records MTN’s actual rate-data cause before editing the rate.
5. If rollback is needed, revert the two task commits through the normal deployment process. Configuration saves are data changes and must be restored separately from recorded values; reverting code does not restore rates. No existing manual requests should be deleted or re-priced.
