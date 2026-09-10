# Automation documentation comparison

Compared against the API documentation pasted by the user (official base URL: https://automation.airtimetocash.com). No production credentials or live transfers were used.

| Contract | Vendify implementation |
| --- | --- |
| JSON POST requests under `/api/v1` | Matches all five documented endpoints; exact request shapes tested |
| OTP generation/verification without Bearer token | Matches; only JSON headers sent |
| Quota, session login and transfer require Bearer token | Matches; token stays server-side |
| MTN 50–10,000; Airtel 50–20,000; Glo 50–1,000; 9MOBILE 50–20,000 | Matches; boundaries and fractional-amount rejection tested; configured Vendify limits can be stricter |
| Reference length 10–40 | Generated `ATC-` plus UUID is exactly 40 characters and remains immutable |
| Session ID from verification | Parsed and encrypted; used on transfer; login-with-session method also implemented |
| Quota positive example uses 5030 | Fixed: only HTTP 200, code 5030 and exact `Recipient(s) Available` means quota success |
| General 5030 means unavailable | Preserved outside that exact quota exception; never treated as transfer success |
| 2000 successful transfer | Requires positive parsed `amountConverted` matching the requested amount before settlement |
| 3000 failure | Normalized to failure; existing documented PIN/low-balance correction paths remain resumable |
| 4000 delivery uncertain | Remains pending, without wallet credit or automatic transfer retry |
| 4030 forbidden | Authentication error; fixed classification for documented HTTP 400 / code 4030 combination |
| 4010 session expired | Existing explicit re-verification path; not mistaken for transfer success |
| 4290 / HTTP 429 | Rate-limited outcome; no automatic retry or payout |
| HTTP 500 / transport timeout | Unknown outcome held pending for confirmation, no payout |
| Naira-formatted balances, converted amounts and automation charges | Parsed numerically; verified against documented formats |

The quota documentation overloads 5030. Matching the exact positive quota example is intentionally operation-specific; arbitrary wording and negative messages cannot permit a transfer or credit a wallet. Existing tests previously expected every quota 5030 to fail and have been corrected to use the documented response.

The customer page previously advertised a universal 50–20,000 range, misleading for MTN and Glo. It now explains that limits depend on the network; the backend quote remains authoritative.

## Scope and operational limits

- Session login is available in the adapter but not called between successful OTP verification and transfer; the pasted documentation does not require an extra login step.
- No transaction-history endpoint or webhook is documented for Automation. Session login cannot prove that a transfer completed. Ambiguous transfers remain subject to explicit manual reconciliation, unlike 2FAST's separate history integration.
- The documented 60 requests/minute standard and 100 maximum are provider limits per outbound IP. Vendify handles their 429 response, but does not currently have a shared outbound-IP limiter guaranteeing those budgets across customers/processes.
- The ten-minute local session window is a Vendify rule; it is not claimed as the provider's session lifetime.
- Provider charges are tracked separately from the customer payout, which follows Vendify's stored rate quote.
- This comparison validates the documented contract with fake HTTP responses, not live provider availability or undisclosed upstream behavior.

## Verification

80 Airtime-to-Cash backend tests, 560 assertions, no failures (existing PHP deprecation notices). Contract tests verify all five outgoing JSON requests and headers, all network limits, the 40-character reference, currency parsing and the quota exception. Existing tests cover wallet settlement, definitive failures, timeouts, duplicate handling and manual conversion regressions.
