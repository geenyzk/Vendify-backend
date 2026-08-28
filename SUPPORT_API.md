# Vendify Support API

All endpoints return JSON and require a Sanctum token/session plus the existing secure-session checks. Success responses use:

```json
{"success":true,"message":"successful","data":{},"type":"success"}
```

Paginated lists return `data` as an array and `meta` with `current_page`, `last_page`, `per_page`, and `total`. Validation failures use HTTP 422. Missing or cross-user resources use HTTP 404. Admin endpoints additionally require a staff role carrying the `support` permission; failures use HTTP 403.

## Allowed values

- Categories: `transaction`, `wallet_funding`, `account_access`, `kyc`, `airtime_data`, `electricity`, `cable_tv`, `exam_pin`, `other`
- Issue types: `failed`, `pending`, `not_received`, `duplicate_charge`, `incorrect_amount`, `refund`, `funding_not_received`, `locked_out`, `verification`, `service_issue`, `other`
- Statuses: `open`, `in_review`, `awaiting_customer`, `resolved`, `closed`
- Priorities: `low`, `normal`, `high`, `urgent`

## Customer endpoints

### List tickets

`GET /api/support/tickets?status=open&per_page=15`

`status` is optional; `per_page` is 1–50. Only the authenticated customer's tickets are returned.

### Create ticket

`POST /api/support/tickets`

```json
{
  "transaction_id": 1842,
  "category": "transaction",
  "issue_type": "not_received",
  "subject": "Airtime was not delivered",
  "description": "My wallet was charged but the airtime has not arrived."
}
```

`transaction_id` and `issue_type` are optional, except `transaction_id` is required when category is `transaction`. Subject is 3–160 characters; description is 10–10,000 characters. Transaction ownership is derived and checked server-side. Returns HTTP 201.

### View ticket and conversation

`GET /api/support/tickets/{id}`

```json
{
  "success": true,
  "data": {
    "id": 42,
    "reference": "VEN-4K8P2QZ",
    "category": "transaction",
    "issue_type": "not_received",
    "subject": "Airtime was not delivered",
    "description": "My wallet was charged but the airtime has not arrived.",
    "status": "open",
    "priority": "normal",
    "assigned_to": null,
    "transaction": {
      "id": 1842,
      "reference": "TXN-20260828123000-ABC123",
      "type": "airtime_recharge",
      "product": null,
      "amount": "1000.00",
      "recipient": "08012345678",
      "status": "success",
      "date": "2026-08-28T12:30:00.000000Z"
    },
    "messages": [{"id":91,"sender":{"id":7,"name":"Ada Nwosu","role":"customer"},"message":"My wallet was charged but the airtime has not arrived.","created_at":"2026-08-28T12:40:00.000000Z"}],
    "created_at": "2026-08-28T12:40:00.000000Z",
    "updated_at": "2026-08-28T12:40:00.000000Z",
    "resolved_at": null,
    "closed_at": null
  }
}
```

Internal notes are never returned here.

### Reply

`POST /api/support/tickets/{id}/messages`

```json
{"message":"The airtime is still missing this morning."}
```

Returns the created message with HTTP 201. Closed tickets return HTTP 409. A reply to an `awaiting_customer` ticket moves it to `in_review`.

### List selectable transactions

`GET /api/support/transactions?per_page=20`

Returns only the authenticated customer's transactions using the same safe transaction projection shown above. `per_page` is 1–50.

## Admin/support endpoints

### List/search tickets

`GET /api/admin/support/tickets`

Query parameters:

- `status`: one allowed status
- `priority`: one allowed priority
- `assignment`: `all`, `unassigned`, or `mine`
- `search`: reference, customer name/username/email/phone, or transaction reference
- `per_page`: 1–100, default 20

Each item includes the base ticket projection and safe `customer` context.

### View ticket

`GET /api/admin/support/tickets/{id}`

Returns the customer-safe ticket fields plus customer context, messages, `internal_notes`, and up to five `recent_tickets`. Customer context contains ID, name, username, email, phone, account status, active flag, join date, and wallet balance. No credentials or raw provider/payment payloads are returned.

### Reply

`POST /api/admin/support/tickets/{id}/messages`

```json
{"message":"We are checking this transaction now."}
```

Returns HTTP 201. An admin reply moves an `open` ticket to `in_review`. Closed tickets return HTTP 409.

### Add internal note

`POST /api/admin/support/tickets/{id}/notes`

```json
{"note":"Provider lookup opened under incident INC-204."}
```

Returns HTTP 201. Notes are available only from the admin detail endpoint.

### Change status

`PATCH /api/admin/support/tickets/{id}/status`

```json
{"status":"awaiting_customer"}
```

Allowed transitions: `open` → `in_review|awaiting_customer|resolved|closed`; `in_review` → `open|awaiting_customer|resolved|closed`; `awaiting_customer` → `open|in_review|resolved|closed`; `resolved` → `open|in_review|closed`. `closed` is terminal. Invalid transitions return HTTP 422. Resolution and closure timestamps are maintained server-side.

### Change priority

`PATCH /api/admin/support/tickets/{id}/priority`

```json
{"priority":"urgent"}
```

### Assign or unassign

`PATCH /api/admin/support/tickets/{id}/assignment`

```json
{"assigned_to":12}
```

Use `null` to unassign. The target must be an existing staff user whose role has the `support` permission; otherwise HTTP 422 is returned.

## Notifications and WhatsApp

Ticket creation, customer replies, admin replies, status changes, and resolution use Vendify's existing database-notification channel. Notification data contains only ticket ID/reference, event, subject, and status. The frontend can combine `ticket.reference` and `transaction.reference` with the existing configurable branding phone number to construct a WhatsApp escalation message.

## Attachments and FAQ

Attachments are not accepted in this version. Vendify currently has only purpose-specific logo/APK upload handlers, not a reusable secure customer attachment subsystem. FAQ remains frontend/configuration content; no duplicate FAQ backend was introduced.
