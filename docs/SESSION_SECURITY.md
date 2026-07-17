# Vendify session security

Vendify uses two authentication channels with one shared device-session registry.

## Web

- The SPA authenticates with Laravel Sanctum's stateful, encrypted `HttpOnly` session cookie. It does not store or send a browser bearer token.
- Activity slides the logical idle expiry by 30 minutes. The server remains authoritative; the SPA uses `X-Session-Expires-At` only to display the two-minute warning and countdown.
- `remember=true` raises the absolute session ceiling to 30 days but does not bypass the 30-minute inactivity rule or recent-authentication checks.
- Login and successful recent-password confirmation regenerate the Laravel session ID.
- Expiry, logout, revocation, password changes and account suspension revoke the logical session and its underlying Laravel session.
- Cross-origin SPA deployments must list the exact frontend origin in `FRONTEND_URL`, its host in `SANCTUM_STATEFUL_DOMAINS`, and use HTTPS with `SESSION_SECURE_COOKIE=true`. Keep `SESSION_SAME_SITE=lax` for same-site subdomains; use `none` only when the UI and API are genuinely cross-site, and only over HTTPS.

## Mobile API and native bridge

The Laravel backend and WebView-facing bridge are included here. The native Expo project is not present in this workspace, so its keychain and biometric implementation must be applied in that repository.

1. Native WebView sets `window.__VENDIFY_NATIVE__ = "app"`, an opaque `X-Device-Id`, and a user-friendly `X-Device-Name`.
2. Mobile login sends `client_type=mobile` (or `X-Client-Platform: app`). The response contains a 15-minute access token and single-use refresh token with a 30-day absolute ceiling.
3. The web layer immediately posts `vendify-auth-credentials` to `ReactNativeWebView`. The native shell must store both credentials only in iOS Keychain / Android Keystore-backed secure storage. It must never place them in AsyncStorage, localStorage, sessionStorage, logs, analytics, crash reports, or backups.
4. Native injects only the current access token with `window.__vendifySetAccessToken(token)`. The access token remains in JavaScript memory only.
5. On `vendify-auth-refresh-required`, native calls `POST /api/auth/refresh` itself, atomically replaces both secure credentials, then injects the new access token. Reusing an old refresh token revokes the whole device session.
6. After five minutes in the background or without local interaction, native covers the WebView with a lock screen. Unlock with platform biometrics/device authentication where supported. Password fallback calls `POST /api/security/unlock`. Locking does not delete the refresh token or sign the user out.
7. On `vendify-auth-cleared`, native deletes all stored credentials. A revoked or unrecoverable refresh response must do the same and return to login.

The native app should prefer `expo-secure-store` (or direct Keychain/Keystore bindings) and `expo-local-authentication`, with screenshots/app-switcher previews obscured while locked.

## Security endpoints

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `POST` | `/api/auth/refresh` | Rotate a mobile refresh/access token pair |
| `GET` | `/api/session` | Read the current logical session |
| `POST` | `/api/session/extend` | Explicitly extend an active web session |
| `GET` | `/api/security/sessions` | List active devices |
| `DELETE` | `/api/security/sessions/{id}` | Revoke one device |
| `POST` | `/api/security/sessions/logout-others` | Revoke every device except this one |
| `POST` | `/api/security/sessions/logout-all` | Revoke all devices; recent auth required |
| `POST` | `/api/security/re-authenticate` | Confirm password for sensitive settings |
| `POST` | `/api/security/unlock` | Password fallback for a locally locked app |

Money-moving controllers continue to verify the transaction PIN. The centralized verifier now rate-limits failures and writes safe audit events without recording PIN values.

## Deployment

Run:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
```

Set the session variables documented in `.env.example`. Production must use its own existing `APP_KEY`, database credentials, `APP_ENV=production`, `APP_DEBUG=false`, and `SESSION_SECURE_COOKIE=true`; do not generate a new `APP_KEY` for an existing encrypted deployment.

Scheduled cleanup may use Laravel's existing Sanctum pruning command and session-table cleanup policy. Revoked logical records should be retained for the audit-retention period before deletion.
