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
(web_api slot URLs), and `message` (logged). Anything else is acked as
`skipped` so it never shows as applied when it wasn't.

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
