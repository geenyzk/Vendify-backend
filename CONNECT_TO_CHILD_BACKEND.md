# Connect this backend to the child backend

This document explains the step-by-step flow for connecting the parent Laravel app in this folder to the child Laravel app in the sibling folder [child_backend](../child_backend).

## 1. Make sure both apps are reachable

Before you connect them, confirm that:

- the parent app is running and publicly reachable
- the child app is running and publicly reachable
- both apps can reach each other over HTTP/HTTPS

Use the real public URL for the parent app, for example:

- Parent: `https://your-parent-domain.com`
- Child: `https://your-child-domain.com`

## 2. Create the child registration entry on the parent

The parent backend already exposes a registration endpoint for the child app.

- Open the parent admin area
- Create or locate the child instance that should be linked
- Generate a one-time registration code for that child
- Share that code with the person setting up the child app

The parent side expects the child to call:

- `POST /api/child/register`

with a body containing:

```json
{
  "registration_code": "YOUR_CODE"
}
```

## 3. Register the child app from the child project

From the child app directory, run:

```bash
php artisan parent-sync:register YOUR_REGISTRATION_CODE --base-url=https://your-parent-domain.com
```

This command will exchange the one-time code for:

- `PARENT_SYNC_CHILD_SLUG`
- `PARENT_SYNC_SECRET`

The child app will print these values for you to paste into its `.env` file.

## 4. Configure the child app environment

In the child app `.env`, set these values:

```env
PARENT_SYNC_ENABLED=true
PARENT_SYNC_DRY_RUN=false
PARENT_SYNC_BASE_URL=https://your-parent-domain.com
PARENT_SYNC_CHILD_SLUG=your-child-slug
PARENT_SYNC_SECRET=your-shared-secret
PARENT_SYNC_BATCH_SIZE=200
PARENT_SYNC_HTTP_TIMEOUT=15
```

Important:

- `PARENT_SYNC_BASE_URL` must point to the parent app
- `PARENT_SYNC_CHILD_SLUG` must match the child instance created on the parent
- `PARENT_SYNC_SECRET` must match the secret returned during registration

## 5. Verify the parent accepts the child

On the parent side, confirm that the child instance now has:

- `status = active`
- a non-empty `slug`
- a non-empty `shared_secret`
- a `registered_at` value

The parent exposes these routes for the sync channel:

- `GET /api/child/{slug}/directives`
- `POST /api/child/{slug}/directives/{id}/ack`
- `POST /api/webhook/child/{slug}`

The ack accepts an optional signed JSON body reporting the execution
outcome, which the admin UI shows per directive:

```json
{ "result": "executed" | "failed" | "skipped", "note": "human-readable detail" }
```

An empty ack body is still accepted (legacy children) and is recorded as
`delivered` — acknowledged, outcome unknown. Directive types the child
executes: `redirect_user` (one customer, matched by `external_id`),
`redirect_all_users`, `update_settings` (allowlisted flags), `reroute_provider`
(web_api slot URL + adex_api slot credentials), `retry_transaction`
(re-dispatches a STUCK data purchase — debited but unconfirmed — with its
original transid; refunds on a definitive fail), and `message` (logged).
Anything else is acked as `skipped` so it never shows as applied when it
wasn't.

A pending directive can be retracted from the admin Directives page (or
`DELETE /api/admin/child-instances/{id}/directives/{directiveId}`) — it
disappears from the pull feed before the child ever sees it.

## Route tunneling

The parent doubles as an adex-protocol provider (`POST /api/user/`,
`/api/data/`, `/api/topup/` — see `ChildTunnelController`), so a
`reroute_provider` directive can point a child's provider slot at the parent
itself and the parent performs the child's transactions:

1. Create (and fund) a parent account that should pay for the child's
   tunneled transactions.
2. On the affiliate's Controls page, set the slot's URL to this app's base
   URL and enter that account's username + password — the child stores them
   in its `adex_api` slot credentials.
3. Map the child's `data_plan.adex{slot}` column values to THIS platform's
   data plan ids (the tunnel resolves `data_plan` against the parent
   catalog), and its `network.adex_id` to 1=mtn, 2=airtel, 3=glo, 4=9mobile.

Tunneled vends are idempotent per `request-id` (child_tunnel_requests
ledger) — a retried stuck transaction never charges the funding account
twice.

## Parent-managed funding (funding aggregation)

When the affiliate's **"Aggregate funding to this platform"** toggle (Controls
page) is on, the parent issues the child's customer bank accounts and receives
all of their funding; the child never touches a payment provider. The parent
then tells the child which customer to credit, keeping the child's local wallet
in sync.

All three endpoints use the same HMAC scheme as the directive channel and
require the toggle to be on (otherwise `403`).

**1. Request a virtual account for a customer** (on-demand, idempotent per
customer):

```text
POST /api/child/{slug}/virtual-accounts
{ "external_customer_id": "<child customer id>", "email": "...", "name": "...", "phone": "..." }
-> { "external_customer_id", "provider", "account_number", "bank_name", "account_name" }
```

Call this the first time a customer wants a funding account; store what comes
back and show it to the customer. Calling again for the same
`external_customer_id` returns the existing account (no new provider account is
created).

**2. Pull credit events** (the child's sync cron polls these, same cadence as
directives):

```text
GET /api/child/{slug}/credit-events
-> [ { "id", "external_customer_id", "amount", "gross_amount", "fee", "provider", "reference", "created_at" }, ... ]
```

`amount` is what to credit the customer (already net of the payment provider's
fee). For each event, credit the local customer identified by
`external_customer_id` by `amount`, keyed on the unique `reference` so a
re-delivered event is never applied twice.

**3. Ack each credit event** once applied:

```text
POST /api/child/{slug}/credit-events/{id}/ack
{ "result": "credited" | "failed", "note": "optional detail" }
```

The parent holds the money (it is the affiliate owner); the child's customer
wallet is a mirror it keeps in sync from these events. The `reference` is the
funding transaction reference, and the parent enqueues each funding exactly once
even across payment-provider webhook retries — so the credit is safe to apply
idempotently on the child.

The `set_funding_mode` directive (`{ "aggregate": true|false, "parent_url": "..." }`)
is queued when the toggle is flipped, so a child that wants to eact immediately
(rather than re-reading its own config) can switch its funding UI to the parent.

## 6. Test the sync flow

From the child app, you can test the connection without sending real data:

```bash
php artisan parent-sync:push --dry-run
```

When everything is ready, run the real sync:

```bash
php artisan parent-sync:push
```

And pull directives from the parent:

```bash
php artisan parent-sync:pull-directives
```

## 7. Enable automatic syncing

The child app already has scheduled sync commands. Make sure the scheduler is running:

```bash
php artisan schedule:work
```

Or use a cron entry that runs the Laravel scheduler.

## 8. Troubleshooting

If the connection fails, check the following:

- the registration code is still valid
- the child app can reach the parent URL
- the parent URL is correct in `PARENT_SYNC_BASE_URL`
- the `slug` and `secret` were copied correctly
- the child app has `PARENT_SYNC_ENABLED=true`
- the child app is not in dry-run mode
