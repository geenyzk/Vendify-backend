# VTU Backend — API Documentation

> **Base URL:** `https://<your-domain>/api`
> All requests must include `Accept: application/json` and `Content-Type: application/json` headers.
> Authenticated routes require a valid session cookie (Laravel Sanctum cookie-based auth).

---

## Table of Contents

1. [Authentication](#1-authentication)
2. [Email Verification](#2-email-verification)
3. [VTU Services](#3-vtu-services)
4. [Transactions](#4-transactions)
5. [Customer](#5-customer)
6. [Promotions](#6-promotions)
7. [Admin — Dashboard](#7-admin--dashboard)
8. [Admin — Users](#8-admin--users)
9. [Admin — Service Controls](#9-admin--service-controls)
10. [Admin — Roles](#10-admin--roles)
11. [Admin — Service Cost Margins](#11-admin--service-cost-margins)
12. [Universal Table API](#12-universal-table-api)
13. [Webhooks](#13-webhooks)
14. [Vendor Auto-Funding](#14-vendor-auto-funding)
15. [Admin — Templates](#15-admin--templates)
16. [Welcome Message](#16-welcome-message)

---

## Response Format

All endpoints (unless noted) return JSON in one of these shapes:

**Success**
```json
{
  "status": true,
  "message": "Request successful",
  "data": { ... }
}
```

**Failure / Error**
```json
{
  "status": false,
  "message": "Error description",
  "data": { ... }
}
```

**Validation Error (422)**
```json
{
  "status": false,
  "message": "Validation Error",
  "data": {
    "field_name": ["Error message"]
  }
}
```

---

## 1. Authentication

### 1.1 Register

**`POST /register`**

Creates a new user account and logs them in. Sends an email verification notification.

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `username` | string | Yes | Unique username (max 255 chars) |
| `email` | string | Yes | Unique, valid email address |
| `phone` | string | Yes | Unique phone number |
| `pin` | string | Yes | 4-digit transaction PIN |
| `password` | string | Yes | Must meet Laravel default password rules |
| `fullname` | string | No | User's full name (defaults to "Anonymous") |
| `referral_code` | string | No | Referral code of an existing user |

**Example Request**
```json
{
  "username": "johndoe",
  "email": "john@example.com",
  "phone": "08012345678",
  "pin": "1234",
  "password": "StrongPass@123",
  "fullname": "John Doe",
  "referral_code": "REF001"
}
```

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Registration successful",
  "data": {
    "user": {
      "id": 1,
      "fullname": "John Doe",
      "username": "johndoe",
      "email": "john@example.com",
      "phone": "08012345678",
      "wallet_balance": 0,
      "email_verified_at": null,
      "created_at": "2025-08-01T10:00:00.000000Z"
    },
    "message": "Registration successful! Please verify your email.",
    "email_verified_at": null
  }
}
```

**Error Responses**

| Code | Description |
|---|---|
| 422 | Validation error (duplicate email/username/phone, weak password, etc.) |

---

### 1.2 Login

**`POST /login`**

Logs in a user with email and password. Uses session-based (cookie) auth.

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `email` | string | Yes | Registered email |
| `password` | string | Yes | Account password |

**Example Request**
```json
{
  "email": "john@example.com",
  "password": "StrongPass@123"
}
```

**Response — 200 OK**

On success, a session cookie is set and the user is redirected internally. For SPA/API clients that receive JSON:
```json
{
  "status": true,
  "message": "Request successful",
  "data": {
    "redirect": "/customer"
  }
}
```
> Admin users are redirected to `/admin`; all others to `/customer`.

**Error Responses**

| Code | Description |
|---|---|
| 422 | Invalid credentials |

---

### 1.3 Get Authenticated User

**`GET /user`** `🔒 Auth Required`

Returns the current logged-in user's data including their role.

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": {
    "user": {
      "id": 1,
      "fullname": "John Doe",
      "username": "johndoe",
      "email": "john@example.com",
      "phone": "08012345678",
      "wallet_balance": 5000.00,
      "referral_balance": 200.00,
      "user_type": "user",
      "email_verified_at": "2025-08-01T10:05:00.000000Z",
      "role": {
        "id": 2,
        "name": "User",
        "slug": "user"
      }
    }
  }
}
```

---

### 1.4 Logout

**`POST /logout`** or **`GET /logout`** `🔒 Auth Required`

Logs out the current user and invalidates the session.

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Logged out",
  "data": null
}
```

---

## 2. Email Verification

### 2.1 Resend Verification Email

**`POST /email/verification-notification`** `🔒 Auth Required`

Sends a new verification link to the user's email. Rate limited to 6 requests/minute.

**Response — 200 OK**
```json
{
  "message": "verification-link-sent"
}
```

If already verified:
```json
{
  "message": "Email already verified"
}
```

---

### 2.2 Verify Email via Code

**`POST /verify-email-code`** `🔒 Auth Required`

Verifies the user's email using a 6-character code received in their email.

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `code` | string | Yes | 6-character verification code |

**Example Request**
```json
{
  "code": "ABC123"
}
```

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Email verified successfully",
  "data": {
    "user": {
      "id": 1,
      "email": "john@example.com",
      "email_verified_at": "2025-08-01T10:10:00.000000Z"
    }
  }
}
```

**Error Responses**

| Code | Description |
|---|---|
| 422 | Failed to verify email |

---

## 3. VTU Services

### 3.1 Purchase a VTU Service

**`POST /vtu/{service}`** `🔒 Auth Required`

Processes a VTU transaction — airtime, data, cable, electricity, or exam PIN.

**URL Parameters**

| Param | Description | Examples |
|---|---|---|
| `service` | The service type | `airtime`, `data`, `cable`, `electricity`, `exam`, `airtime-pin`, `data-pin` |

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `amount` | numeric | Yes | Transaction amount (airtime: min 50, max 5000) |
| `network` | string | Conditional | Network provider (e.g., `mtn`, `airtel`, `glo`, `9mobile`) — for airtime/data |
| `network_type` | string | Conditional | Network type/category — for airtime/data |
| `phone` | string | Conditional | Recipient phone number — for airtime/data |
| `provider` | string | Conditional | Provider identifier — for cable/electricity |
| `plan_type` | string | Conditional | Plan type — for data/cable |
| `pin` | string | Yes | User's 4-digit transaction PIN |
| `code` | string | No | Promo/discount code to apply |

**Example Request (Airtime)**
```json
{
  "amount": 500,
  "network": "mtn",
  "network_type": "airtime",
  "phone": "08012345678",
  "pin": "1234"
}
```

**Example Request (Data)**
```json
{
  "amount": 1000,
  "network": "airtel",
  "network_type": "data",
  "phone": "08098765432",
  "plan_type": "1GB",
  "pin": "1234",
  "code": "DATA20"
}
```

**Response — 200 OK**
```json
{
  "status": "success",
  "message": "Transaction successful",
  "data": { ... }
}
```

**Error Responses**

| Code | Description |
|---|---|
| 402 | Insufficient wallet balance |
| 422 | Invalid PIN or promo code |
| 400 | Unsupported or unconfigured service |
| 500 | Failed to process VTU request |

---

### 3.2 Get Service Plans

**`GET /vtu/{service}/plans`** `🔒 Auth Required`

Returns the available plans for a given service.

**URL Parameters**

| Param | Description | Examples |
|---|---|---|
| `service` | Service type | `data`, `cable`, `exam`, `airtime-pin`, `data-pin` |

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": {
    "plans": [
      {
        "id": 1,
        "name": "MTN 1GB",
        "amount": 300,
        "validity": "30 days",
        "network": "mtn"
      }
    ]
  }
}
```

---

### 3.3 Verify Service Identifier

**`GET /vtu/{service}/verify`** `🔒 Auth Required`

Validates a customer's smartcard, meter number, or other identifier before a purchase.

**URL Parameters**

| Param | Description | Examples |
|---|---|---|
| `service` | Service to verify | `cable`, `electricity` |

**Query Parameters**

| Field | Type | Required | Description |
|---|---|---|---|
| `identifier` | string | Yes | Smartcard or meter number |
| `cable_network` | string | Conditional (cable) | e.g., `dstv`, `gotv`, `startimes` |
| `meter_type` | string | Conditional (electricity) | `prepaid` or `postpaid` |
| `disco` | string | Conditional (electricity) | Disco/utility company name |

**Response — 200 OK**
```json
{
  "status": "success",
  "message": "User verified",
  "data": {
    "name": "JOHN DOE",
    "address": "123 Main Street, Lagos",
    "account_number": "1234567890"
  }
}
```

---

### 3.4 Get System Information

**`GET /system-information-get`** `🔒 Auth Required`

Returns general system configuration.

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": {
    "general": {
      "id": 1,
      "site_name": "VTU Platform",
      "support_email": "support@example.com"
    }
  }
}
```

---

## 4. Transactions

### 4.1 Transaction Report

**`POST /transactions/report`** `🔒 Auth Required`

Returns a summary of transactions within a date range.

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `start_date` | date (YYYY-MM-DD) | No | Defaults to start of current month |
| `end_date` | date (YYYY-MM-DD) | No | Defaults to today |
| `user_id` | integer | No | Filter by a specific user (admin use) |

**Example Request**
```json
{
  "start_date": "2025-08-01",
  "end_date": "2025-08-31",
  "user_id": 12
}
```

**Response — 200 OK**
```json
{
  "start_date": "2025-08-01",
  "end_date": "2025-08-31",
  "transactions": {
    "total": 50000,
    "count": 25,
    "breakdown": {
      "airtime_recharge": 15000,
      "data_subscription": 20000,
      "cable_subscription": 8000,
      "electric_bill": 7000
    }
  }
}
```

---

## 5. Customer

### 5.1 Customer Stats

**`GET /customer/stats`** `🔒 Auth Required`

Returns the authenticated customer's monthly transaction counts and a 5-day activity chart.

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": {
    "monthly_successful": 18,
    "monthly_pending": 2,
    "tx_chart": {
      "labels": ["Mon", "Tue", "Wed", "Thu", "Fri"],
      "datasets": [
        {
          "label": "Transactions",
          "data": [3, 5, 2, 7, 4],
          "backgroundColor": "#36A2EB"
        }
      ]
    }
  }
}
```

---

### 5.2 Convert Referral Balance to Wallet

**`POST /customer/{id}/convert-referral`** `🔒 Auth Required`

Moves the user's entire referral bonus balance into their main wallet.

**URL Parameters**

| Param | Type | Description |
|---|---|---|
| `id` | integer | The user's ID |

**Response — 200 OK**
```json
{
  "message": "Referral balance converted successfully.",
  "user": {
    "id": 5,
    "fullname": "Jane Doe",
    "wallet_balance": 1200.00,
    "referral_balance": 0
  }
}
```

**Error Responses**

| Code | Description |
|---|---|
| 422 | Referral balance is zero or insufficient |
| 404 | User not found |

---

### 5.3 Upgrade Account

**`POST /customer/account/upgrade`** `🔒 Auth Required`

Upgrades the authenticated user's account tier. The upgrade cost is deducted from the wallet.

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `upgrade_to` | string | Yes | Target tier: `user`, `agent`, `bonanza`, `api` |

**Example Request**
```json
{
  "upgrade_to": "agent"
}
```

**Response — 200 OK**
```json
{
  "message": "Successfully upgraded your account to agent.",
  "user": {
    "id": 1,
    "fullname": "John Doe",
    "wallet_balance": 1500.00,
    "user_type": "agent"
  }
}
```

**Error Responses**

| Code | Description |
|---|---|
| 400 | Already at the requested tier |
| 402 | Insufficient wallet balance |
| 404 | Upgrade tier pricing not configured |
| 422 | Invalid upgrade option |

---

## 6. Promotions

### 6.1 Validate Promo Code

**`POST /promotions/validate`** `🔒 Auth Required`

Checks whether a promo code is valid and returns the discount details without applying it.

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `code` | string | Yes | Promo code to validate (max 50 chars) |
| `product` | string | Yes | Product type: `airtime`, `data`, `bundle` |
| `provider` | string | No | Network provider (e.g., `MTN`, `AIRTEL`, `GLO`, `9MOBILE`) |
| `amount` | numeric | No | Transaction amount (used to check `min_amount` conditions) |

**Example Request**
```json
{
  "code": "MTN20",
  "product": "airtime",
  "provider": "MTN",
  "amount": 500
}
```

**Response — 200 OK**
```json
{
  "status": "success",
  "data": {
    "promotion": {
      "id": 1,
      "name": "MTN Airtime Bonus 20%",
      "code": "MTN20",
      "type": "percentage",
      "value": 20,
      "discount_amount": 100
    },
    "message": "Promo code applied successfully"
  }
}
```

**Error Responses**

| Code | Message |
|---|---|
| 422 | Invalid or expired promo code |
| 422 | This promo code is no longer valid |
| 422 | You are not eligible for this promo code |
| 422 | This promo code is only valid for `{product}` |
| 422 | This promo code is only valid for `{provider}` |
| 422 | You have reached the usage limit for this promo code |
| 422 | Transaction does not meet promo code requirements |

---

### 6.2 Apply Promo Code

**`POST /promotions/apply`** `🔒 Auth Required`

Applies a promo code and optionally links it to a transaction.

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `code` | string | Yes | Promo code to apply |
| `transaction_id` | numeric | No | Transaction ID to link the promotion to |

**Example Request**
```json
{
  "code": "MTN20",
  "transaction_id": 123
}
```

**Response — 200 OK**
```json
{
  "status": "success",
  "message": "Promo code applied",
  "promotion_id": 1
}
```

**Error Responses**

| Code | Description |
|---|---|
| 422 | Promo code not found |

---

## 7. Admin — Dashboard

> All routes in this section are prefixed with `/admin` and require authentication.

### 7.1 Admin Stats

**`GET /admin/stats`** `🔒 Auth Required`

Returns comprehensive dashboard statistics.

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": {
    "users_graph": {
      "labels": ["user", "agent", "admin"],
      "datasets": [{ "label": "Users by Type", "data": [150, 30, 5] }]
    },
    "transaction_count": 340,
    "total_user": 185,
    "total_user_balance": 250000.00,
    "api_balances": { "vendor_a": 50000, "vendor_b": 30000 },
    "total_funding_today": 15000.00,
    "total_signups_today": 7,
    "sales_chart": [
      { "name": "Airtime VTU", "value": 8000, "fill": "#36A2EB" },
      { "name": "Data Bundle", "value": 5000, "fill": "#FF6384" },
      { "name": "Cable Sales", "value": 2000, "fill": "#FFCE56" },
      { "name": "Bill Sales", "value": 1000, "fill": "#9966FF" }
    ],
    "transaction_status": {
      "successful": 300,
      "failed": 20,
      "pending": 20
    },
    "tx_chart": {
      "labels": ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"],
      "datasets": [{ "label": "Transactions", "data": [40, 55, 30, 70, 60, 45, 40] }]
    }
  }
}
```

---

### 7.2 Broadcast Notification

**`POST /admin/broadcast`** `🔒 Auth Required`

Sends a notification (SMS, email, or push) to users filtered by user type.

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `channels` | array | Yes | Channels to use: `sms`, `email`, `notification` |
| `recipients` | array | Yes | User types to target: e.g., `["user", "agent"]` |
| `smsMessage` | string | No | SMS message body (max 160 chars) |
| `emailSubject` | string | No | Email subject (max 255 chars) |
| `emailBody` | string | No | Email HTML body |
| `notifTitle` | string | No | Push notification title (max 255 chars) |
| `notifMessage` | string | No | Push notification body |
| `sendNow` | boolean | Yes | `true` to send immediately, `false` to schedule |
| `scheduleDate` | datetime | Conditional | Required if `sendNow` is `false`. Must be a future date |
| `priorityHigh` | boolean | Yes | Whether to send as high priority |

**Example Request**
```json
{
  "channels": ["email", "notification"],
  "recipients": ["user", "agent"],
  "emailSubject": "System Maintenance",
  "emailBody": "<p>We will be down for maintenance on Sunday.</p>",
  "notifTitle": "Maintenance Alert",
  "notifMessage": "System maintenance scheduled for Sunday 2am.",
  "sendNow": true,
  "priorityHigh": false
}
```

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Notifications processed for 120 users",
  "data": {
    "notified": 120
  }
}
```

---

### 7.3 Fund a User

**`POST /admin/users/{id}/fund`** `🔒 Auth Required`

Credits or debits a user's wallet balance.

**URL Parameters**

| Param | Type | Description |
|---|---|---|
| `id` | integer | The user's ID |

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `amount` | numeric | Yes | Amount to credit or debit (min: 0.01) |
| `type` | string | Yes | `credit` or `debit` |

**Example Request**
```json
{
  "amount": 5000,
  "type": "credit"
}
```

**Response — 200 OK**
```json
{
  "status": true,
  "message": "User wallet credited successfully",
  "data": {
    "user": {
      "id": 5,
      "fullname": "Jane Doe",
      "wallet_balance": 6500.00
    }
  }
}
```

---

### 7.4 Refresh Vendor Token

**`GET /admin/vendor/{id}/refresh-token`** `🔒 Auth Required`

Regenerates the unique identifier/token for a vendor/provider.

**URL Parameters**

| Param | Type | Description |
|---|---|---|
| `id` | integer | The provider's ID |

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Token refreshed",
  "data": {
    "identifier": "a3f8c2d1-1234-5678-abcd-ef0123456789"
  }
}
```

---

### 7.5 Get Banks for a Payment Provider

**`GET /admin/vendor/{id}/banks`** `🔒 Auth Required`

Fetches the list of banks supported by a payment gateway (used to look up a `bank_code` before configuring a vendor for [auto-funding](#142-configure-a-vendor-for-auto-funding)). Results are cached for 24 hours per provider.

**URL Parameters**

| Param | Type | Description |
|---|---|---|
| `id` | integer | The payment provider's ID (`providers` table, `category = 'payment'`) |

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": {
    "banks": [
      { "code": "044", "name": "Access Bank" },
      { "code": "058", "name": "GTBank" }
    ]
  }
}
```

**Error Responses**

| Code | Description |
|---|---|
| 500 | Provider not found, or the gateway does not support fetching banks (e.g. PaymentPoint) |

---

### 7.6 Get Airtime Discount

**`GET /admin/airtime_discount`** `🔒 Auth Required`

Returns the current airtime discount configuration.

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": {
    "discount": {
      "id": 1,
      "name": "airtime",
      "percentage": 3,
      "active": true
    }
  }
}
```

---

### 7.7 Analytics

**`GET /admin/analytics`** `🔒 Auth Required`

Returns time-series and breakdown data for the admin analytics page, over a date range. Distinct from [7.1 Admin Stats](#71-admin-stats), which only covers fixed windows (today / this month / last 7 days) for the main dashboard widgets.

**Query Parameters**

| Field | Type | Required | Description |
|---|---|---|---|
| `start_date` | date (YYYY-MM-DD) | No | Defaults to 29 days before `end_date` |
| `end_date` | date (YYYY-MM-DD) | No | Defaults to today |

**Example**
```
GET /admin/analytics?start_date=2026-06-01&end_date=2026-07-04
```

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": {
    "period": { "start_date": "2026-06-01", "end_date": "2026-07-04" },
    "summary": {
      "total_revenue": 291000.50,
      "total_transactions": 340,
      "successful_transactions": 300,
      "failed_transactions": 40,
      "pending_transactions": 0,
      "success_rate": 88.24,
      "average_transaction_value": 970.0,
      "new_signups": 25
    },
    "revenue_over_time": {
      "labels": ["2026-06-01", "2026-06-02", "..."],
      "values": [12000, 8500, 0]
    },
    "transactions_over_time": {
      "labels": ["2026-06-01", "2026-06-02", "..."],
      "success": [10, 8, 0],
      "fail": [1, 0, 0],
      "pending": [0, 0, 0]
    },
    "by_service_type": [
      { "type": "airtime_recharge", "label": "Airtime", "revenue": 120000, "count": 150 },
      { "type": "data_subscription", "label": "Data", "revenue": 90000, "count": 100 },
      { "type": "cable_subscription", "label": "Cable", "revenue": 0, "count": 0 },
      { "type": "electric_bill", "label": "Electricity", "revenue": 0, "count": 0 },
      { "type": "exam", "label": "Exam PIN", "revenue": 0, "count": 0 },
      { "type": "betting_funding", "label": "Betting", "revenue": 0, "count": 0 },
      { "type": "airtime_pin", "label": "Airtime Pin", "revenue": 0, "count": 0 },
      { "type": "data_pin", "label": "Data Pin", "revenue": 0, "count": 0 },
      { "type": "wallet_funding", "label": "Wallet Funding", "revenue": 0, "count": 0 },
      { "type": "manual_funding", "label": "Manual Funding", "revenue": 81000, "count": 50 },
      { "type": "bulksms", "label": "Bulk SMS", "revenue": 0, "count": 0 }
    ],
    "by_provider": [
      { "provider": "mtn", "revenue": 120000, "count": 150 },
      { "provider": "glo", "revenue": 40000, "count": 60 }
    ],
    "signups_over_time": {
      "labels": ["2026-06-01", "2026-06-02", "..."],
      "values": [2, 1, 0]
    },
    "funding_vs_spend": {
      "total_funding": 81000,
      "total_spend": 210000.50
    },
    "top_customers": [
      {
        "user_id": 8,
        "name": "Olaniyi Oladele",
        "email": "oladele@example.com",
        "total_spent": 45000,
        "transaction_count": 30
      }
    ]
  }
}
```

**Field Notes**

- `summary.total_revenue`, `revenue_over_time`, `by_service_type[].revenue`, and `top_customers[].total_spent` exclude `wallet_funding`/`manual_funding` transactions — those are wallet top-ups, not platform revenue. They're broken out separately in `funding_vs_spend.total_funding`.
- `by_service_type` always returns all 11 transaction types (zero-filled) so the frontend doesn't need to merge in missing categories.
- `by_provider` only includes rows with a non-null `provider` column, sorted by revenue descending.
- `top_customers` is capped at 10 and ranked by successful non-funding spend within the date range.

**Error Responses**

| Code | Description |
|---|---|
| 422 | `end_date` is before `start_date`, or either is not a valid date |

---

## 8. Admin — Users

> Routes prefixed with `/admin/users`.

### 8.1 List All Users

**`GET /admin/users`** `🔒 Auth Required`

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": {
    "users": [
      {
        "id": 1,
        "fullname": "John Doe",
        "username": "johndoe",
        "email": "john@example.com",
        "phone": "08012345678",
        "wallet_balance": 5000.00,
        "user_type": "user",
        "created_at": "2025-08-01T10:00:00.000000Z"
      }
    ]
  }
}
```

---

### 8.2 Get a Single User

**`GET /admin/users/{id}`** `🔒 Auth Required`

**URL Parameters**

| Param | Type | Description |
|---|---|---|
| `id` | integer | The user's ID |

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": {
    "user": {
      "id": 1,
      "fullname": "John Doe",
      "username": "johndoe",
      "email": "john@example.com",
      "phone": "08012345678",
      "wallet_balance": 5000.00,
      "user_type": "user"
    }
  }
}
```

---

### 8.3 Create a User (Admin)

**`POST /admin/users`** `🔒 Auth Required`

Creates a new user from the admin panel.

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `fullname` | string | Yes | Full name |
| `username` | string | Yes | Unique username |
| `email` | string | Yes | Unique email |
| `phone` | string | Yes | Unique phone |
| `password` | string | Yes | Initial password |
| `user_type` | string | No | Role: `user`, `agent`, `admin`, etc. |

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": {
    "user": { ... },
    "user_token": "generated-api-token"
  }
}
```

---

### 8.4 Update a User

**`PUT /admin/users/{id}`** `🔒 Auth Required`

Updates user information.

**URL Parameters**

| Param | Type | Description |
|---|---|---|
| `id` | integer | The user's ID |

**Request Body** — Send only the fields to update.

| Field | Type | Description |
|---|---|---|
| `fullname` | string | Full name |
| `email` | string | Email |
| `phone` | string | Phone |
| `user_type` | string | Role |
| `wallet_balance` | numeric | Wallet balance |

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": {
    "user": { ... }
  }
}
```

---

### 8.5 Delete a User

**`DELETE /admin/users/{id}`** `🔒 Auth Required`

**URL Parameters**

| Param | Type | Description |
|---|---|---|
| `id` | integer | The user's ID |

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": {}
}
```

---

## 9. Admin — Service Controls

> Routes prefixed with `/admin/controls`.

Service controls allow toggling on/off individual VTU services (airtime, data, cable, etc.) per network.

### 9.1 List Service Controls

**`GET /admin/controls`** `🔒 Auth Required`

Returns all active service controls grouped by category and sub-category.

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": {
    "control": {
      "Airtime": {
        "MTN": [
          {
            "id": 1,
            "name": "MTN Airtime",
            "isActive": true,
            "isDevLock": false,
            "category": "Airtime",
            "sub_category": "MTN"
          }
        ],
        "AIRTEL": [ { ... } ]
      },
      "Data": { ... }
    }
  }
}
```

---

### 9.2 Update Service Control Status

**`PUT /admin/controls/{id}`** `🔒 Auth Required`

Toggles the `isActive` status of a specific service control.

**URL Parameters**

| Param | Type | Description |
|---|---|---|
| `id` | integer | Service control ID |

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `isActive` | boolean | Yes | `true` to enable, `false` to disable |

**Example Request**
```json
{
  "isActive": false
}
```

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": []
}
```

**Error Responses**

| Code | Description |
|---|---|
| 404 | Service control not found |
| 422 | Validation error |

---

## 10. Admin — Roles

> Routes prefixed with `/admin/roles`.

### 10.1 List All Roles

**`GET /admin/roles`** `🔒 Auth Required`

**Response — 200 OK**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Admin",
      "slug": "admin",
      "description": "Full system access",
      "is_active": true,
      "service_cost_margins": [ ... ],
      "users": [ ... ],
      "permissions": [
        { "id": 1, "name": "Customers", "slug": "customers", "description": "Manage customer accounts" }
      ]
    }
  ]
}
```

---

### 10.2 Get a Single Role

**`GET /admin/roles/{id}`** `🔒 Auth Required`

**Response — 200 OK**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Agent",
    "slug": "agent",
    "is_active": true,
    "service_cost_margins": [ ... ],
    "users": [ ... ],
    "permissions": [
      { "id": 1, "name": "Customers", "slug": "customers", "description": "Manage customer accounts" }
    ]
  }
}
```

---

### 10.3 Create a Role

**`POST /admin/roles`** `🔒 Auth Required`

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | Yes | Unique role name |
| `slug` | string | Yes | Unique slug |
| `description` | string | No | Description |
| `is_active` | boolean | No | Default: `true` |
| `permission_ids` | integer[] | No | IDs of permissions to assign (see [10.7](#107-list-available-permissions)) |

**Response — 201 Created**
```json
{
  "success": true,
  "data": {
    "id": 4,
    "name": "Reseller",
    "slug": "reseller",
    "permissions": [
      { "id": 2, "name": "Wallets", "slug": "wallets", "description": "Manage wallet balances and funding" }
    ]
  },
  "message": "Role created successfully"
}
```

---

### 10.4 Update a Role

**`PUT /admin/roles/{id}`** `🔒 Auth Required`

Send only the fields to update. Accepts the same body as [10.3 Create a Role](#103-create-a-role), including `permission_ids`. When `permission_ids` is present, it fully replaces the role's assigned permissions (sync, not merge).

**Response — 200 OK**
```json
{
  "success": true,
  "data": { ... },
  "message": "Role updated successfully"
}
```

---

### 10.5 Delete a Role

**`DELETE /admin/roles/{id}`** `🔒 Auth Required`

**Response — 200 OK**
```json
{
  "success": true,
  "message": "Role deleted successfully"
}
```

---

### 10.6 Get Users by Role

**`GET /admin/roles/{id}/users`** `🔒 Auth Required`

Returns all users assigned to the given role.

**Response — 200 OK**
```json
{
  "success": true,
  "data": [
    {
      "id": 3,
      "fullname": "Alice Smith",
      "email": "alice@example.com",
      "user_type": "agent"
    }
  ]
}
```

---

### 10.7 List Available Permissions

**`GET /admin/permissions`** `🔒 Auth Required`

Returns every permission that can be assigned to a role (e.g. to populate a "select permissions" UI). Permissions are a fixed reference list seeded by the app, not created via this API.

**Response — 200 OK**
```json
{
  "success": true,
  "data": [
    { "id": 1, "name": "Customers", "slug": "customers", "description": "Manage customer accounts" },
    { "id": 2, "name": "Wallets", "slug": "wallets", "description": "Manage wallet balances and funding" },
    { "id": 3, "name": "Transactions", "slug": "transactions", "description": "View and manage transactions" },
    { "id": 4, "name": "Support", "slug": "support", "description": "Handle support tickets and messages" },
    { "id": 5, "name": "Settings", "slug": "settings", "description": "Manage platform settings" }
  ]
}
```

---

## 11. Admin — Service Cost Margins

> Routes prefixed with `/admin`.

Service cost margins define the cost price and profit margin for each service type, per role.

### 11.1 List All Cost Margins

**`GET /admin/service-cost-margins`** `🔒 Auth Required`

**Response — 200 OK**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "role_id": 2,
      "service_type": "airtime",
      "cost_price": 95,
      "margin_profit": 5,
      "margin_type": "fiat",
      "is_active": true,
      "role": { "id": 2, "name": "Agent" }
    }
  ]
}
```

---

### 11.2 Get Margins by Role

**`GET /admin/roles/{roleId}/cost-margins`** `🔒 Auth Required`

Returns all service cost margins for a specific role.

---

### 11.3 Get Margin by Role and Service Type

**`GET /admin/roles/{roleId}/cost-margins/{serviceType}`** `🔒 Auth Required`

| Param | Description |
|---|---|
| `roleId` | The role's ID |
| `serviceType` | e.g., `airtime`, `data`, `cable`, `electricity` |

**Response — 200 OK**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "role_id": 2,
    "service_type": "airtime",
    "cost_price": 95,
    "margin_profit": 5,
    "margin_type": "fiat",
    "is_active": true
  }
}
```

---

### 11.4 Create a Cost Margin

**`POST /admin/service-cost-margins`** `🔒 Auth Required`

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `role_id` | integer | Yes | Must exist in `roles` table |
| `service_type` | string | Yes | e.g., `airtime`, `data`, `cable` |
| `cost_price` | numeric | Yes | Cost price (min: 0) |
| `margin_profit` | numeric | Yes | Profit margin (min: 0) |
| `margin_type` | string | Yes | `fiat` or `percentage` |
| `is_active` | boolean | No | Default: `true` |

**Response — 201 Created**
```json
{
  "success": true,
  "data": { ... },
  "message": "Service cost margin created successfully"
}
```

**Error Responses**

| Code | Description |
|---|---|
| 409 | Cost margin already exists for this role and service type |

---

### 11.5 Update a Cost Margin

**`PUT /admin/service-cost-margins/{id}`** `🔒 Auth Required`

Send only the fields to update.

**Response — 200 OK**
```json
{
  "success": true,
  "data": { ... },
  "message": "Service cost margin updated successfully"
}
```

---

### 11.6 Delete a Cost Margin

**`DELETE /admin/service-cost-margins/{id}`** `🔒 Auth Required`

**Response — 200 OK**
```json
{
  "success": true,
  "message": "Service cost margin deleted successfully"
}
```

---

### 11.7 Bulk Update Cost Margins for a Role

**`POST /admin/roles/{roleId}/cost-margins/bulk`** `🔒 Auth Required`

Creates or updates multiple cost margins for a role in one request.

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `margins` | array | Yes | Array of margin objects |
| `margins[].service_type` | string | Yes | Service type |
| `margins[].cost_price` | numeric | Yes | Cost price |
| `margins[].margin_profit` | numeric | Yes | Margin profit |
| `margins[].margin_type` | string | No | `fiat` or `percentage` (defaults to `fiat`) |

**Example Request**
```json
{
  "margins": [
    {
      "service_type": "airtime",
      "cost_price": 95,
      "margin_profit": 5,
      "margin_type": "fiat"
    },
    {
      "service_type": "data",
      "cost_price": 90,
      "margin_profit": 10,
      "margin_type": "percentage"
    }
  ]
}
```

**Response — 200 OK**
```json
{
  "success": true,
  "data": [ { ... }, { ... } ],
  "message": "Bulk update completed successfully"
}
```

---

## 12. Universal Table API

These are generic CRUD endpoints for any database table. Used primarily by the admin panel.

> **Note:** These endpoints do not currently enforce authentication middleware. Secure them appropriately on your server or behind an admin guard.

### 12.1 Get Records

**`GET /table/{table}`**

Fetches all records from a table with optional filtering, eager loading, and sorting.

**URL Parameters**

| Param | Description |
|---|---|
| `table` | Database table name (e.g., `users`, `transactions`, `data_plans`) |

**Query Parameters**

| Param | Description | Example |
|---|---|---|
| Any column name | Filter by that column's value | `?status=active` |
| `with` | Comma-separated relationships to eager load | `?with=role,transactions` |
| `sort` | Column and direction | `?sort=created_at,desc` |

**Example**
```
GET /table/transactions?status=success&sort=created_at,desc&with=user
```

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": [ { ... }, { ... } ]
}
```

---

### 12.2 Get a Single Record

**`GET /table/{table}/{id}`**

**Query Parameters**

| Param | Description |
|---|---|
| `with` | Comma-separated relationships to eager load |

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": { ... }
}
```

---

### 12.3 Create or Update a Record

**`POST /table/{table}`** — Create

**`PUT /table/{table}/{id}`** — Update

**Request Body** — A flat JSON object with the fields matching the table's columns.

**Example**
```json
{
  "name": "New Data Plan",
  "amount": 500,
  "validity": "30 days",
  "network": "mtn"
}
```

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Operation completed successfully",
  "data": { ... }
}
```

---

### 12.4 Bulk Create or Update

**`POST /table/{table}/bulk`**

**`PUT /table/{table}/bulk`**

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `items` | array | Yes | Array of record objects. Include `id` to update, omit or set `id: 0` to create |

**Example**
```json
{
  "items": [
    { "id": 0, "name": "Plan A", "amount": 300 },
    { "id": 5, "name": "Plan B Updated", "amount": 500 }
  ]
}
```

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Operation completed successfully",
  "data": [ { ... }, { ... } ]
}
```

---

### 12.5 Delete a Record

**`DELETE /table/{table}/{id}`**

**Response — 200 OK**
```json
{
  "status": true,
  "message": "1 record(s) deleted",
  "data": { "deleted": 1 }
}
```

---

### 12.6 Bulk Delete

**`DELETE /table/{table}`**

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `ids` | array | Yes | Array of record IDs to delete |

**Example**
```json
{
  "ids": [1, 2, 5]
}
```

**Response — 200 OK**
```json
{
  "status": true,
  "message": "3 record(s) deleted",
  "data": { "deleted": 3 }
}
```

---

### 12.7 Reorder Records

**`POST /table/{table}/reorder`**

Updates the `sort_order` (or a custom column) for multiple records at once.

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `items` | array | Yes | Array of `{ id, sort_order }` objects |
| `column` | string | No | Column to use for ordering (default: `sort_order`) |

**Example**
```json
{
  "items": [
    { "id": 3, "sort_order": 1 },
    { "id": 1, "sort_order": 2 },
    { "id": 5, "sort_order": 3 }
  ]
}
```

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Reorder completed",
  "data": [ { ... }, { ... }, { ... } ]
}
```

---

## 13. Webhooks

**`ANY /webhook/{type}/{identifier}`**

Handles incoming webhook events from third-party payment/VTU providers.

| Param | Description |
|---|---|
| `type` | Provider type (e.g., `payscribe`, `flutterwave`) |
| `identifier` | Provider-specific webhook identifier |

This endpoint accepts any HTTP method (`GET`, `POST`, `PUT`, etc.) and is handled internally by the `WebhookController`. The frontend does not call this endpoint directly.

---

## 14. Vendor Auto-Funding

The auto-funding system monitors VTU vendor balances on a schedule and automatically initiates a bank transfer from the configured payment gateway (e.g. Flutterwave) to the vendor's bank account when the balance drops below a set threshold. Every transfer attempt — success or failure — is recorded in the `vendor_fundings` audit table and an in-app notification is sent to all admin users.

> **No new API routes are introduced.** Configuration and history are managed entirely through the [Universal Table API](#12-universal-table-api) against the `providers` and `vendor_fundings` tables.

---

### 14.1 How It Works

```
[Scheduler — every 30 min]
        ↓
  vendors:check-balances
        ↓
  For each vendor where auto_fund_enabled = true:
    → Fetch live balance (cache bypassed)
    → If balance < auto_fund_threshold:
        → Dispatch AutoFundVendor job
              ↓
        → Call payment gateway transfer API
              (Flutterwave POST /transfers)
              ↓
        → Record result in vendor_fundings
        → Notify all admin users (in-app)
```

---

### 14.2 Configure a Vendor for Auto-Funding

Use the Universal Table API to set the auto-fund fields on a vendor record (`providers` table, `category = 'vendor'`).

**`PUT /table/providers/{id}`**

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `auto_fund_enabled` | boolean | Yes | Set `true` to activate auto-funding for this vendor |
| `auto_fund_threshold` | numeric | Yes | Balance level (in NGN) that triggers a top-up |
| `auto_fund_amount` | numeric | Yes | Amount (in NGN) to transfer when triggered |
| `account_number` | string | Yes | Vendor's bank account number to receive the transfer |
| `account_name` | string | Yes | Account name (for display/verification only) |
| `bank_code` | string | Yes | Bank code used by the payment gateway (e.g. `"044"` for Access Bank) |
| `bank_name` | string | No | Human-readable bank name |
| `funding_provider_id` | integer | Yes | ID of the payment provider record (`category = 'payment'`) to use for the transfer (e.g. the Flutterwave row) |

Example request:

```json
{
  "auto_fund_enabled": true,
  "auto_fund_threshold": 5000,
  "auto_fund_amount": 50000,
  "account_number": "0123456789",
  "account_name": "Adex VTU Nigeria",
  "bank_code": "044",
  "bank_name": "Access Bank",
  "funding_provider_id": 3
}
```

Response — 200 OK:

```json
{
  "status": true,
  "message": "Operation completed successfully",
  "data": {
    "id": 1,
    "name": "adex",
    "auto_fund_enabled": true,
    "auto_fund_threshold": "5000.00",
    "auto_fund_amount": "50000.00",
    "account_number": "0123456789",
    "account_name": "Adex VTU Nigeria",
    "bank_code": "044",
    "bank_name": "Access Bank",
    "funding_provider_id": 3
  }
}
```

---

### 14.3 Disable Auto-Funding for a Vendor

**`PUT /table/providers/{id}`**

```json
{
  "auto_fund_enabled": false
}
```

---

### 14.4 List All Vendor Auto-Fund Configurations

Returns all vendor records. Filter to only auto-fund-enabled ones using a query param.

**`GET /table/providers?auto_fund_enabled=1&category=vendor`**

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": [
    {
      "id": 1,
      "name": "adex",
      "auto_fund_enabled": true,
      "auto_fund_threshold": "5000.00",
      "auto_fund_amount": "50000.00",
      "account_number": "0123456789",
      "account_name": "Adex VTU Nigeria",
      "bank_code": "044",
      "bank_name": "Access Bank",
      "funding_provider_id": 3,
      "balance": "12500.00"
    }
  ]
}
```

---

### 14.5 View Funding History (Audit Log)

Every transfer attempt (whether pending, successful, or failed) is logged in the `vendor_fundings` table.

**`GET /table/vendor_fundings`**

Supports all standard query params: filtering, sorting, and eager loading.

Get all failed transfers for a vendor:

```http
GET /table/vendor_fundings?vendor_id=1&status=failed&sort=created_at,desc
```

Load vendor and payment provider details inline:

```http
GET /table/vendor_fundings?with=vendor,paymentProvider
```

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": [
    {
      "id": 12,
      "vendor_id": 1,
      "payment_provider_id": 3,
      "amount": "50000.00",
      "reference": "TXN-2025-XXXXX",
      "status": "success",
      "balance_before": "3200.00",
      "gateway_response": {
        "status": "success",
        "message": "Transfer Queued Successfully",
        "data": {
          "id": 396574,
          "account_number": "0123456789",
          "bank_code": "044",
          "currency": "NGN",
          "amount": 50000,
          "status": "NEW",
          "reference": "TXN-2025-XXXXX"
        }
      },
      "created_at": "2025-08-15T14:30:00.000000Z",
      "vendor": { "id": 1, "name": "adex" },
      "payment_provider": { "id": 3, "name": "flutterwave" }
    }
  ]
}
```

**`vendor_fundings` Field Reference**

| Field | Type | Description |
|---|---|---|
| `id` | integer | Auto-increment primary key |
| `vendor_id` | integer | FK → providers (the VTU vendor) |
| `payment_provider_id` | integer | FK → providers (the payment gateway used) |
| `amount` | decimal | Amount transferred in NGN |
| `reference` | string | Unique transaction reference sent to the gateway |
| `status` | enum | `pending` · `success` · `failed` |
| `balance_before` | decimal | Vendor balance at the time the job ran |
| `gateway_response` | JSON | Full raw response from the payment gateway API |
| `created_at` | datetime | When the transfer was initiated |
| `updated_at` | datetime | When the status was last updated |

---

### 14.6 Get a Single Funding Record

**`GET /table/vendor_fundings/{id}`**

**Response — 200 OK**
```json
{
  "status": true,
  "message": "Request successful",
  "data": {
    "id": 12,
    "vendor_id": 1,
    "payment_provider_id": 3,
    "amount": "50000.00",
    "reference": "TXN-2025-XXXXX",
    "status": "success",
    "balance_before": "3200.00",
    "gateway_response": { ... },
    "created_at": "2025-08-15T14:30:00.000000Z"
  }
}
```

---

### 14.7 Supported Payment Gateways for Transfers

| Gateway | `name` in DB | Transfer Support | Bank List Support | Notes |
|---|---|---|---|---|
| Flutterwave | `flutterwave` | Yes | Yes | Transfers via `/v3/transfers`; banks via `/v3/banks/NG` |
| Monnify | `monnify` | Not yet | Yes | Payout API not wired — transfer throws an error if selected; banks via `/api/v1/banks` |
| PaymentPoint | `payment point` | Not yet | Not yet | Payout and bank-list APIs not wired — both throw an error if selected |

To find the correct `funding_provider_id` for Flutterwave:

```http
GET /table/providers?category=payment&name=flutterwave
```

Use [`GET /admin/vendor/{id}/banks`](#75-get-banks-for-a-payment-provider) with that same provider `id` to look up the `bank_code` for a vendor's bank before saving it in [14.2](#142-configure-a-vendor-for-auto-funding).

---

### 14.8 Manual Trigger (Server-side only)

The balance check can be triggered manually from the server without waiting for the scheduler:

```bash
# Dry-run — logs what would happen, dispatches nothing
php artisan vendors:check-balances --dry-run

# Live run — dispatches AutoFundVendor jobs for all vendors below threshold
php artisan vendors:check-balances
```

> This is a server-side Artisan command, not an HTTP endpoint. It is not callable from the frontend.

---

### 14.9 Admin Notifications

When a transfer is processed (success or failure), all users with `user_type = admin` receive a **database** notification. These appear in the standard Laravel notifications table and can be fetched via:

**`GET /table/notifications?notifiable_type=App\Models\User&notifiable_id={admin_id}`**

Notification data shape:

```json
{
  "title": "Vendor Auto-Fund Successful",
  "message": "N50,000.00 transfer to adex (0123456789) — success."
}
```

---

## 15. Admin — Templates

> Routes prefixed with `/admin/templates`.

Templates are reusable, variable-driven messages (`{{name}}`, `{{amount}}`, ...) used across the platform. Each template is either:
- an **event** template (`type: "event"`), tied to one of `login`, `register`, `purchase`, `wallet_credit`, `wallet_debit` — e.g. the **"welcome message"** sent to new users is simply the template where `event = "register"`, or
- a **broadcast** template (`type: "broadcast"`), a reusable one-off message (not tied to a lifecycle event) that can be used as a starting point for [7.2 Broadcast Notification](#72-broadcast-notification).

> **Note:** This endpoint only stores/manages the template content. It does not yet trigger sending — no code currently fires the `register`/`login`/etc. templates automatically during those events.

### 15.1 List Templates

**`GET /admin/templates`** `🔒 Auth Required`

**Query Parameters**

| Field | Type | Required | Description |
|---|---|---|---|
| `type` | string | No | Filter by `event` or `broadcast` |
| `event` | string | No | Filter by event name, e.g. `?event=register` to find the welcome message |
| `enabled` | boolean | No | Filter by enabled state |

**Example**
```
GET /admin/templates?event=register
```

**Response — 200 OK**
```json
{
  "success": true,
  "data": [
    {
      "id": 44,
      "name": "Welcome message",
      "slug": "welcome-message",
      "type": "event",
      "event": "register",
      "subject": "Welcome to {{app_name}}, {{name}}!",
      "content": "Hi {{name}}, welcome to {{app_name}}! Your account is ready — top up your wallet to get started with airtime, data, cable, and bill payments.",
      "channels": ["email", "in_app"],
      "enabled": true,
      "variables": ["app_name", "name"],
      "created_at": "2026-07-04T11:31:51.000000Z",
      "updated_at": "2026-07-04T11:31:51.000000Z"
    }
  ]
}
```

---

### 15.2 Get a Single Template

**`GET /admin/templates/{id}`** `🔒 Auth Required`

**Response — 200 OK** — same shape as a single item in [15.1](#151-list-templates).

---

### 15.3 Create a Template

**`POST /admin/templates`** `🔒 Auth Required`

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | Yes | Human-friendly name shown in the admin UI |
| `slug` | string | No | Unique slug; auto-generated from `name` if omitted |
| `type` | string | Yes | `event` or `broadcast` |
| `event` | string | No | Required in practice for `type: "event"`: `login`, `register`, `purchase`, `wallet_credit`, or `wallet_debit` |
| `subject` | string | No | Email subject / notification headline |
| `content` | string | Yes | Template body. Use `{{variable}}` placeholders |
| `channels` | string[] | No | Delivery channels: `email`, `sms`, `in_app`, `push` |
| `enabled` | boolean | No | Default: `true` |

**Response — 201 Created**
```json
{
  "success": true,
  "data": { ... },
  "message": "Template created successfully"
}
```

---

### 15.4 Update a Template

**`PUT /admin/templates/{id}`** `🔒 Auth Required`

Send only the fields to update — same body as [15.3](#153-create-a-template). To edit the welcome message: look it up via `GET /admin/templates?event=register`, then `PUT` its `content`/`subject`.

**Response — 200 OK**
```json
{
  "success": true,
  "data": { ... },
  "message": "Template updated successfully"
}
```

---

### 15.5 Delete a Template

**`DELETE /admin/templates/{id}`** `🔒 Auth Required`

Soft-deletes the template (the model uses `SoftDeletes`).

**Response — 200 OK**
```json
{
  "success": true,
  "message": "Template deleted successfully"
}
```

---

## 16. Welcome Message

> A single admin-configured message shown to customers as an in-app popup/banner —
> not the same thing as [§15 Admin — Templates](#15-admin--templates). A Template is a
> reusable, event/variable-driven message (the `register` template is not
> auto-triggered by anything yet). A Welcome Message is a single standing message —
> think "announcement banner" — that the admin can turn on or off at any time, shown to
> any customer who hasn't seen the *current version* of it yet.
>
> There is always at most one welcome message record. `PUT /admin/welcome-message`
> updates it in place rather than creating a new row each time — editing the
> title/body automatically makes it pop up again for everyone (including users who'd
> already dismissed the old version), because "seen" is tracked per `(user, message)`
> pair and compared against the message's `updated_at`, not a fixed version number.

### 16.1 Get Welcome Message (customer-facing)

**`GET /welcome-message`** `🔒 Auth Required`

Returns the welcome message only if it's currently `active`, along with whether the
authenticated user has already seen the current version of it. If there's no message,
or it's turned off, `welcome_message` is `null`.

**Response — 200 OK**
```json
{
  "success": true,
  "message": "successful",
  "data": {
    "welcome_message": {
      "id": 1,
      "title": "Welcome to Laravel VTU!",
      "body": "Top up your wallet to get started with airtime, data, cable, and bill payments.",
      "active": true,
      "created_at": "2026-07-04T11:45:34.000000Z",
      "updated_at": "2026-07-04T11:45:34.000000Z"
    },
    "seen": false
  },
  "type": "success"
}
```

---

### 16.2 Get Welcome Message (admin)

**`GET /admin/welcome-message`** `🔒 Auth Required`

Same shape as [16.1](#161-get-welcome-message-customer-facing), minus the per-user
`seen` flag — returns the current record regardless of `active` (or `null` if none
exists yet), so the admin UI can load and edit a currently-disabled message.

**Response — 200 OK**
```json
{
  "success": true,
  "message": "successful",
  "data": {
    "welcome_message": {
      "id": 1,
      "title": "Welcome to Laravel VTU!",
      "body": "Top up your wallet to get started with airtime, data, cable, and bill payments.",
      "active": true,
      "created_at": "2026-07-04T11:45:34.000000Z",
      "updated_at": "2026-07-04T11:45:34.000000Z"
    }
  },
  "type": "success"
}
```

---

### 16.3 Create / Update Welcome Message (admin)

**`PUT /admin/welcome-message`** `🔒 Auth Required`

Upserts the single welcome message record — creates it if none exists yet, otherwise
updates it in place. Any update bumps `updated_at`, which is what makes it re-appear
for users who'd already marked the previous version as seen.

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `title` | string | Yes | Headline shown in the popup (max 255 chars) |
| `body` | string | Yes | Message body |
| `active` | boolean | No | Whether it should currently be shown. Default: `true` on first create; unchanged on update if omitted |

**Response — 200 OK**
```json
{
  "success": true,
  "message": "Welcome message updated",
  "data": {
    "welcome_message": {
      "id": 1,
      "title": "Welcome to Laravel VTU!",
      "body": "Top up your wallet to get started with airtime, data, cable, and bill payments.",
      "active": true,
      "created_at": "2026-07-04T11:45:34.000000Z",
      "updated_at": "2026-07-04T11:45:34.000000Z"
    }
  },
  "type": "success"
}
```

**Error Responses**

| Code | Description |
|---|---|
| 422 | `title` or `body` missing/invalid |

---

### 16.4 Delete / Clear Welcome Message (admin)

**`DELETE /admin/welcome-message`** `🔒 Auth Required`

Removes the configured message entirely (and cascades to delete all "seen" records for
it), so `GET /welcome-message` starts returning `welcome_message: null` again.

**Response — 200 OK**
```json
{
  "success": true,
  "message": "Welcome message removed",
  "data": null,
  "type": "success"
}
```

---

### 16.5 Mark as Seen

**`POST /welcome-message/seen`** `🔒 Auth Required`

Records that the authenticated user has seen the current welcome message, so it won't
pop up again for them on other devices/browsers — this is server-tracked, not
`localStorage`.

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `welcome_message_id` | integer | Yes | The `id` from [16.1](#161-get-welcome-message-customer-facing). Must exist in `welcome_messages` |

**Response — 200 OK**
```json
{
  "success": true,
  "message": "Marked as seen",
  "data": null,
  "type": "success"
}
```

**Error Responses**

| Code | Description |
|---|---|
| 422 | `welcome_message_id` missing or doesn't exist |

---

## Quick Reference

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/register` | No | Register a new user |
| POST | `/login` | No | Login |
| GET | `/user` | Yes | Get current user |
| POST | `/logout` | Yes | Logout |
| POST | `/email/verification-notification` | Yes | Resend verification email |
| POST | `/verify-email-code` | Yes | Verify email via code |
| POST | `/vtu/{service}` | Yes | Purchase VTU service |
| GET | `/vtu/{service}/plans` | Yes | Get service plans |
| GET | `/vtu/{service}/verify` | Yes | Verify customer identifier |
| GET | `/system-information-get` | Yes | Get system information |
| POST | `/transactions/report` | Yes | Get transaction report |
| GET | `/customer/stats` | Yes | Customer dashboard stats |
| POST | `/customer/{id}/convert-referral` | Yes | Convert referral to wallet |
| POST | `/customer/account/upgrade` | Yes | Upgrade account tier |
| POST | `/promotions/validate` | Yes | Validate promo code |
| POST | `/promotions/apply` | Yes | Apply promo code |
| GET | `/admin/stats` | Yes | Admin dashboard stats |
| GET | `/admin/analytics` | Yes | Analytics over a date range |
| POST | `/admin/broadcast` | Yes | Broadcast notifications |
| POST | `/admin/users/{id}/fund` | Yes | Fund/debit user wallet |
| GET | `/admin/vendor/{id}/refresh-token` | Yes | Refresh vendor token |
| GET | `/admin/vendor/{id}/banks` | Yes | Get banks supported by a payment provider |
| GET | `/admin/airtime_discount` | Yes | Get airtime discount |
| GET | `/admin/users` | Yes | List all users |
| GET | `/admin/users/{id}` | Yes | Get single user |
| POST | `/admin/users` | Yes | Create user |
| PUT | `/admin/users/{id}` | Yes | Update user |
| DELETE | `/admin/users/{id}` | Yes | Delete user |
| GET | `/admin/controls` | Yes | List service controls |
| PUT | `/admin/controls/{id}` | Yes | Toggle service control |
| GET | `/admin/roles` | Yes | List roles |
| POST | `/admin/roles` | Yes | Create role |
| PUT | `/admin/roles/{id}` | Yes | Update role |
| DELETE | `/admin/roles/{id}` | Yes | Delete role |
| GET | `/admin/roles/{id}/users` | Yes | Get users by role |
| GET | `/admin/permissions` | Yes | List available permissions |
| GET | `/admin/service-cost-margins` | Yes | List cost margins |
| POST | `/admin/service-cost-margins` | Yes | Create cost margin |
| PUT | `/admin/service-cost-margins/{id}` | Yes | Update cost margin |
| DELETE | `/admin/service-cost-margins/{id}` | Yes | Delete cost margin |
| GET | `/admin/roles/{roleId}/cost-margins` | Yes | Get margins by role |
| GET | `/admin/roles/{roleId}/cost-margins/{serviceType}` | Yes | Get margin by role+service |
| POST | `/admin/roles/{roleId}/cost-margins/bulk` | Yes | Bulk update margins for role |
| GET | `/admin/templates` | Yes | List templates (e.g. `?event=register` for welcome message) |
| GET | `/admin/templates/{id}` | Yes | Get single template |
| POST | `/admin/templates` | Yes | Create template |
| PUT | `/admin/templates/{id}` | Yes | Update template |
| DELETE | `/admin/templates/{id}` | Yes | Delete template |
| GET | `/welcome-message` | Yes | Get active welcome message + seen status |
| POST | `/welcome-message/seen` | Yes | Mark welcome message as seen |
| GET | `/admin/welcome-message` | Yes | Get welcome message (any active state) |
| PUT | `/admin/welcome-message` | Yes | Create/update welcome message |
| DELETE | `/admin/welcome-message` | Yes | Remove welcome message |
| GET | `/table/{table}` | No | Get table records |
| GET | `/table/{table}/{id}` | No | Get single record |
| POST | `/table/{table}` | No | Create record |
| PUT | `/table/{table}/{id}` | No | Update record |
| POST | `/table/{table}/bulk` | No | Bulk create/update |
| DELETE | `/table/{table}/{id}` | No | Delete record |
| DELETE | `/table/{table}` | No | Bulk delete |
| POST | `/table/{table}/reorder` | No | Reorder records |
