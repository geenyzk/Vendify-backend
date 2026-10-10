# PaymentPoint KYC rollout and recovery

## What this release implements

- Registration creates the Vendify account without collecting BVN/NIN or calling PaymentPoint.
- Wallet activation asks the authenticated customer for either an 11-digit BVN or an 11-digit NIN plus explicit consent.
- The first consented request asks only for PalmPay (`20946`).
- Raw identity numbers are removed from the Laravel request before controller/error handling, kept only in request-scoped memory, and are never returned or stored.
- A usable account is displayed only after both account number and bank name have been validated and the `banks` row has been saved.
- Existing usable accounts are returned without another provider call.
- Provisioning state, a per-user/provider lock, and the unique state row prevent automatic or concurrent duplicate calls.
- A provider timeout, unusable response, or local persistence failure becomes a non-retryable support state. Wallet balance and payment webhooks are unchanged.

## Deliberately blocked

PaymentPoint has confirmed that identity may be collected after Vendify registration. That confirmation enables the normal post-registration onboarding flow, but it does not establish that repeating `createVirtualAccount` for a provider customer created by an earlier attempt is idempotent or duplicate-safe.

`PAYMENTPOINT_KYC_RECOVERY_ENABLED=false` must therefore remain in production. Customers whose earlier request may already have created a PaymentPoint customer receive the single `recovery_pending` wallet state and are not asked to submit BVN/NIN. No retrieval, allocation, or recovery endpoint has been invented.

Before enabling recovery, obtain written PaymentPoint confirmation of the supported operation for an existing `customer_id`, including its idempotency/duplicate behavior and timeout reconciliation procedure. Then test that documented method in a controlled environment. Do not enable the flag merely because calling `createVirtualAccount` a second time appears to work once.

The provisioning migration backfills the customer IDs evidenced in the incident review (Vendify user IDs 435, 436, and 437). If production logs identify more pre-release attempts, add those users to the recovery state before they open Wallet. A user with neither an account nor reliable attempt evidence is treated as never attempted; this is necessary to support legitimate first-time activation but means log reconciliation is an operational prerequisite.

## Required production configuration

Edit the backend `.env` through cPanel File Manager. Preserve all credentials and set:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.vendify.com.ng
PAYMENTPOINT_VIRTUAL_ACCOUNT_BANK_CODES=20946
PAYMENTPOINT_KYC_RECOVERY_ENABLED=false
```

Do not put PaymentPoint credentials in GitHub variables exposed to the frontend and do not create any `VITE_` identity or PaymentPoint secret variables.

## cPanel deployment without SSH

1. In phpMyAdmin, export a full database backup (structure and data) before deployment. Keep it outside `public_html`.
2. In cPanel File Manager, verify the server-side backend `.env` contains the values above. The FTP workflow excludes `.env`, so it should not be replaced.
3. Merge and push the backend and frontend changes to their production branches. Confirm both GitHub Actions workflows finish successfully; they build/install dependencies and deploy by FTP/FTPS.
4. Sign in as the owner/staff account that has the protected `migrations` permission. Re-authenticate when prompted, then use **Admin → Settings → Danger Zone → Run migrations** once. This authenticated POST runs only `php artisan migrate --force`; the former public `/deploy/{action}` route has been removed.
5. The migration list must include all outstanding migrations. For this feature, specifically verify these rows exist in phpMyAdmin's `migrations` table:

   - `2026_07_16_180000_harden_customer_funding_accounts`
   - `2026_10_08_230000_repair_payment_point_service_control`
   - `2026_10_09_120000_create_payment_point_provisionings_table`

6. In phpMyAdmin, confirm `payment_point_provisionings` exists and has a unique key on `(user_id, provider_id)`. Do not delete or merge legacy `banks` rows during this rollout.
7. If `bootstrap/cache/config.php` exists, download a backup and remove that generated cache file through cPanel File Manager after changing `.env`; Laravel will then read the new server-side values. Do not remove `bootstrap/cache/.gitignore` or application source files. The unsafe public `/cache` route has been removed. Alternatively, use an existing authenticated cache control or ask cPanel support to run `php artisan config:clear`.
8. Smoke-test with a new production test customer over HTTPS: register, open Wallet, submit a consenting test BVN/NIN approved for production testing, and verify exactly one PalmPay account is securely persisted and displayed. Also open Wallet for an existing funded customer and one known recovery customer.

## Production acceptance criteria

Do not call the incident fully resolved until the controlled new customer receives a real PalmPay account in production and the database contains one usable `banks` row without a wallet-balance change. Check logs only for correlation IDs and redacted categories; never copy BVN/NIN into tickets, chat, analytics, screenshots, or logs.

For known recovery customers, support should record the correlation ID and tell the customer activation is pending. They do not re-register and they do not repeatedly submit identity. If PaymentPoint confirms that resubmitting `createVirtualAccount` with BVN/NIN is the supported duplicate-safe procedure, test that procedure and then enable the flag. If PaymentPoint specifies retrieval, allocation, or another operation instead, implement and test that documented operation before enabling recovery. In either case the existing authenticated wallet form and provisioning state can be reused.
