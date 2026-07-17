<?php

namespace App\Services\Auth;

use App\Models\AuthRefreshToken;
use App\Models\AuthSession;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class SessionSecurityService
{
    public function createWebSession(User $user, Request $request, bool $remember = false): AuthSession
    {
        $now = now();
        $absoluteExpiry = $remember
            ? $now->copy()->addDays(config('security.remember_days'))
            : $now->copy()->addHours(config('security.web_absolute_hours'));

        $session = AuthSession::create([
            'user_id' => $user->id,
            'channel' => 'web',
            'laravel_session_id' => $request->session()->getId(),
            ...$this->deviceMetadata($request),
            'last_active_at' => $now,
            'idle_expires_at' => $now->copy()->addMinutes(config('security.web_idle_minutes')),
            'absolute_expires_at' => $absoluteExpiry,
            'reauthenticated_at' => $now,
        ]);

        $request->session()->put('auth_session_id', $session->id);
        $request->session()->put('auth.password_confirmed_at', $now->timestamp);

        return $session;
    }

    /** @return array{access_token:string,refresh_token:string,token_type:string,expires_in:int,session:array<string,mixed>} */
    public function createMobileCredentials(User $user, Request $request): array
    {
        return DB::transaction(function () use ($user, $request) {
            $now = now();
            $session = AuthSession::create([
                'user_id' => $user->id,
                'channel' => 'mobile',
                ...$this->deviceMetadata($request),
                'last_active_at' => $now,
                'absolute_expires_at' => $now->copy()->addDays(config('security.refresh_token_days')),
                'reauthenticated_at' => $now,
            ]);

            $credentials = $this->issueMobileCredentials($session, $user, $request);
            unset($credentials['_refresh_token_id']);
            return $credentials;
        });
    }

    /** @return array{access_token:string,refresh_token:string,token_type:string,expires_in:int,session:array<string,mixed>} */
    public function rotateMobileRefresh(string $plainRefreshToken, Request $request): array
    {
        $result = DB::transaction(function () use ($plainRefreshToken, $request) {
            $refreshToken = AuthRefreshToken::query()
                ->where('token_hash', hash('sha256', $plainRefreshToken))
                ->lockForUpdate()
                ->first();

            if (!$refreshToken) {
                return ['__auth_error' => 'Invalid refresh token.'];
            }

            $session = AuthSession::query()->lockForUpdate()->find($refreshToken->auth_session_id);
            $deviceMismatch = $session?->device_id
                && !hash_equals($session->device_id, (string) $request->header('X-Device-Id'));
            $invalid = !$session
                || $session->revoked_at
                || $session->absolute_expires_at->isPast()
                || $refreshToken->revoked_at
                || $refreshToken->expires_at->isPast()
                || $deviceMismatch;

            if ($refreshToken->used_at || $invalid) {
                if ($session) {
                    $reason = $refreshToken->used_at
                        ? 'refresh_token_reuse'
                        : ($deviceMismatch ? 'suspected_token_replay' : 'refresh_token_invalid');
                    $this->revoke($session, $reason);
                    AuditLogger::record(
                        $refreshToken->used_at ? 'refresh_token_reuse' : ($deviceMismatch ? 'suspected_token_replay' : 'refresh_failed'),
                        subject: $session->user,
                        actor: $session->user,
                        description: 'A mobile refresh credential was rejected and its session was revoked.',
                        context: ['auth_session_id' => $session->id],
                    );
                }
                return ['__auth_error' => 'Refresh token is no longer valid.'];
            }

            $refreshToken->forceFill(['used_at' => now()])->save();
            if ($session->access_token_id) {
                PersonalAccessToken::query()->whereKey($session->access_token_id)->delete();
            }

            $credentials = $this->issueMobileCredentials($session, $session->user, $request);
            $refreshToken->forceFill(['replaced_by_id' => $credentials['_refresh_token_id']])->save();
            unset($credentials['_refresh_token_id']);

            AuditLogger::record(
                'session_refreshed',
                subject: $session->user,
                actor: $session->user,
                description: 'Mobile session credentials were rotated.',
                context: ['auth_session_id' => $session->id],
            );

            return $credentials;
        });

        if (isset($result['__auth_error'])) {
            throw new AuthenticationException($result['__auth_error']);
        }

        return $result;
    }

    public function currentSession(Request $request): ?AuthSession
    {
        $resolved = $request->attributes->get('auth_session');
        if ($resolved instanceof AuthSession) {
            return $resolved;
        }

        $id = $request->hasSession() ? $request->session()->get('auth_session_id') : null;
        if ($id) {
            return AuthSession::find($id);
        }

        $accessToken = $request->user()?->currentAccessToken();
        return $accessToken instanceof PersonalAccessToken
            ? AuthSession::where('access_token_id', $accessToken->getKey())->first()
            : null;
    }

    public function adoptLegacySession(Request $request): ?AuthSession
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        $accessToken = $user->currentAccessToken();
        if ($accessToken instanceof PersonalAccessToken) {
            $now = now();
            return AuthSession::firstOrCreate(
                ['access_token_id' => $accessToken->getKey()],
                [
                    'user_id' => $user->id,
                    'channel' => 'api',
                    ...$this->deviceMetadata($request),
                    'last_active_at' => $now,
                    'absolute_expires_at' => $accessToken->expires_at ?? $now->copy()->addMinutes(30),
                ],
            );
        }

        if ($request->hasSession()) {
            return $this->createWebSession($user, $request, false);
        }

        // Non-browser guard contexts (including trusted internal clients and
        // Laravel's test guard) may already be authenticated without exposing
        // a session store or a persisted Sanctum token. Track them with a
        // short logical API session instead of rejecting an otherwise valid
        // existing authentication flow.
        $now = now();
        return AuthSession::create([
            'user_id' => $user->id,
            'channel' => 'api',
            ...$this->deviceMetadata($request),
            'last_active_at' => $now,
            'absolute_expires_at' => $now->copy()->addMinutes(30),
            'reauthenticated_at' => $now,
        ]);
    }

    public function ensureActive(Request $request): AuthSession
    {
        $user = $request->user();
        $session = $this->currentSession($request) ?? $this->adoptLegacySession($request);

        if (!$user || !$session || $user->is_active === false || !$user->isActive()) {
            if ($session) {
                $this->revoke($session, 'account_inactive');
            }
            throw new AuthenticationException('Session is not active.');
        }

        if ($session->channel === 'mobile' && $session->device_id) {
            $presentedDeviceId = (string) $request->header('X-Device-Id');
            if ($presentedDeviceId === '' || !hash_equals($session->device_id, $presentedDeviceId)) {
                $this->revoke($session, 'suspected_token_replay');
                AuditLogger::record(
                    'suspected_token_replay',
                    subject: $user,
                    actor: $user,
                    description: 'A mobile access token was presented from an unexpected device.',
                    context: ['auth_session_id' => $session->id],
                );
                throw new AuthenticationException('Session device verification failed.');
            }
        }

        if ($session->revoked_at) {
            throw new AuthenticationException('Session revoked.');
        }

        $expired = $session->absolute_expires_at->isPast()
            || (in_array($session->channel, ['web', 'impersonation'], true) && (!$session->idle_expires_at || $session->idle_expires_at->isPast()));

        if ($expired) {
            $this->revoke($session, 'session_expired');
            AuditLogger::record(
                'session_expired',
                subject: $user,
                actor: $user,
                description: 'An authentication session expired.',
                context: ['auth_session_id' => $session->id, 'channel' => $session->channel],
            );
            throw new AuthenticationException('Session expired.');
        }

        $this->touch($session, $request);
        return $session->fresh();
    }

    public function extend(AuthSession $session, Request $request): AuthSession
    {
        if (in_array($session->channel, ['web', 'impersonation'], true)) {
            $idle = now()->addMinutes(config('security.web_idle_minutes'));
            $session->idle_expires_at = $idle->min($session->absolute_expires_at);
        }
        $session->last_active_at = now();
        $session->fill($this->deviceMetadata($request));
        $session->save();
        return $session->fresh();
    }

    public function markRecentlyAuthenticated(AuthSession $session, Request $request): void
    {
        $session->forceFill(['reauthenticated_at' => now()])->save();
        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->put('auth.password_confirmed_at', now()->timestamp);
            $session->forceFill(['laravel_session_id' => $request->session()->getId()])->save();
        }
    }

    public function isRecentlyAuthenticated(AuthSession $session): bool
    {
        return $session->reauthenticated_at
            && $session->reauthenticated_at->gte(now()->subMinutes(config('security.recent_auth_minutes')));
    }

    public function revoke(AuthSession $session, string $reason = 'revoked'): void
    {
        if (!$session->revoked_at) {
            $session->forceFill(['revoked_at' => now(), 'revocation_reason' => $reason])->save();
        }
        AuthRefreshToken::where('auth_session_id', $session->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        if ($session->access_token_id) {
            PersonalAccessToken::query()->whereKey($session->access_token_id)->delete();
        }
        if ($session->laravel_session_id) {
            DB::table(config('session.table', 'sessions'))->where('id', $session->laravel_session_id)->delete();
        }
    }

    public function revokeAllForUser(User $user, string $reason, ?string $exceptSessionId = null): int
    {
        $sessions = AuthSession::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->when($exceptSessionId, fn ($query) => $query->whereKeyNot($exceptSessionId))
            ->get();
        foreach ($sessions as $session) {
            $this->revoke($session, $reason);
        }
        if (!$exceptSessionId) {
            $user->tokens()->delete();
        } else {
            $currentAccessTokenId = AuthSession::whereKey($exceptSessionId)->value('access_token_id');
            $user->tokens()
                ->when($currentAccessTokenId, fn ($query) => $query->where('id', '!=', $currentAccessTokenId))
                ->delete();
        }
        return $sessions->count();
    }

    /** @return array<string,mixed> */
    public function payload(AuthSession $session, ?string $currentSessionId = null): array
    {
        return [
            'id' => $session->id,
            'channel' => $session->channel,
            'device_name' => $session->device_name ?: 'Unknown device',
            'device_type' => $session->device_type,
            'platform' => $session->platform,
            'browser' => $session->browser,
            'approximate_location' => $session->approximate_location,
            'last_active_at' => $session->last_active_at?->toIso8601String(),
            'expires_at' => (in_array($session->channel, ['web', 'impersonation'], true) ? $session->idle_expires_at : $session->absolute_expires_at)?->toIso8601String(),
            'current' => $session->id === $currentSessionId,
        ];
    }

    private function touch(AuthSession $session, Request $request): void
    {
        if ($session->last_active_at && $session->last_active_at->gt(now()->subSeconds(config('security.activity_write_seconds')))) {
            return;
        }
        // Ordinary API traffic (including background notification polling)
        // updates device recency but must not keep an unattended browser
        // signed in. Only the explicit /session/extend user-activity signal
        // slides a web idle deadline.
        $session->last_active_at = now();
        $session->fill($this->deviceMetadata($request));
        $session->save();
    }

    /** @return array<string,mixed> */
    private function issueMobileCredentials(AuthSession $session, User $user, Request $request): array
    {
        $minutes = config('security.access_token_minutes');
        $expiresAt = now()->addMinutes($minutes)->min($session->absolute_expires_at);
        $access = $user->createToken("mobile:{$session->id}", ['*'], $expiresAt);
        $plainRefresh = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $refresh = AuthRefreshToken::create([
            'auth_session_id' => $session->id,
            'token_hash' => hash('sha256', $plainRefresh),
            'expires_at' => $session->absolute_expires_at,
        ]);

        $session->forceFill([
            'access_token_id' => $access->accessToken->getKey(),
            'device_id' => $request->header('X-Device-Id') ?: $session->device_id,
            'last_active_at' => now(),
        ])->save();

        return [
            'access_token' => $access->plainTextToken,
            'refresh_token' => $plainRefresh,
            'token_type' => 'Bearer',
            'expires_in' => max(1, now()->diffInSeconds($expiresAt)),
            'session' => $this->payload($session, $session->id),
            '_refresh_token_id' => $refresh->id,
        ];
    }

    /** @return array<string,string|null> */
    private function deviceMetadata(Request $request): array
    {
        $ua = (string) $request->userAgent();
        $deviceType = str_contains($ua, 'iPad') ? 'Tablet' : (preg_match('/Mobile|Android|iPhone/i', $ua) ? 'Mobile' : 'Desktop');
        $platform = match (true) {
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => $request->header('X-Client-Platform') === 'app' ? 'Vendify app' : 'Unknown',
        };
        $country = strtoupper(substr((string) ($request->header('CF-IPCountry') ?: $request->header('X-Country-Code')), 0, 2));

        return [
            'device_id' => substr((string) $request->header('X-Device-Id'), 0, 128) ?: null,
            'device_name' => substr((string) ($request->header('X-Device-Name') ?: "$browser on $platform"), 0, 255),
            'device_type' => $deviceType,
            'platform' => $platform,
            'browser' => $browser,
            'ip_address' => $request->ip(),
            'approximate_location' => preg_match('/^[A-Z]{2}$/', $country) ? $country : null,
        ];
    }
}
