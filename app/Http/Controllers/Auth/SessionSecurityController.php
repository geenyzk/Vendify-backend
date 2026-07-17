<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\HttpResponse;
use App\Models\AuthSession;
use App\Services\Auth\SessionSecurityService;
use App\Support\AuditLogger;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionSecurityController extends Controller
{
    use HttpResponse;

    public function __construct(private readonly SessionSecurityService $sessions)
    {
    }

    public function refresh(Request $request)
    {
        $validated = $request->validate(['refresh_token' => ['required', 'string', 'min:40', 'max:512']]);
        try {
            return $this->success($this->sessions->rotateMobileRefresh($validated['refresh_token'], $request));
        } catch (AuthenticationException $exception) {
            return $this->fail(null, $exception->getMessage(), 401);
        }
    }

    public function status(Request $request)
    {
        $session = $this->sessions->ensureActive($request);
        return $this->success(['session' => $this->sessions->payload($session, $session->id)]);
    }

    public function extend(Request $request)
    {
        $session = $this->sessions->ensureActive($request);
        $session = $this->sessions->extend($session, $request);
        return $this->success(['session' => $this->sessions->payload($session, $session->id)], 'Session extended');
    }

    public function index(Request $request)
    {
        $current = $this->sessions->currentSession($request);
        $items = AuthSession::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->where('absolute_expires_at', '>', now())
            ->where(function ($query) {
                $query->whereNotIn('channel', ['web', 'impersonation'])
                    ->orWhere('idle_expires_at', '>', now());
            })
            ->latest('last_active_at')
            ->get()
            ->map(fn (AuthSession $session) => $this->sessions->payload($session, $current?->id));

        return $this->success(['sessions' => $items]);
    }

    public function destroy(Request $request, AuthSession $authSession)
    {
        abort_unless($authSession->user_id === $request->user()->id, 404);
        $current = $this->sessions->currentSession($request);
        $isCurrent = $current?->is($authSession) ?? false;
        $this->sessions->revoke($authSession, 'user_revoked');
        AuditLogger::record(
            'session_revoked',
            subject: $request->user(),
            actor: $request->user(),
            description: 'An active device session was revoked.',
            context: ['auth_session_id' => $authSession->id, 'current' => $isCurrent],
        );

        if ($isCurrent) {
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return $this->success(['current' => $isCurrent], 'Session revoked');
    }

    public function destroyOthers(Request $request)
    {
        $current = $this->sessions->currentSession($request);
        $count = $this->sessions->revokeAllForUser($request->user(), 'logout_all_other_devices', $current?->id);
        AuditLogger::record(
            'sessions_revoked',
            subject: $request->user(),
            actor: $request->user(),
            description: 'All other device sessions were revoked.',
            context: ['count' => $count],
        );
        return $this->success(['revoked_count' => $count], 'Other devices signed out');
    }

    public function destroyAll(Request $request)
    {
        $count = $this->sessions->revokeAllForUser($request->user(), 'logout_all_devices');
        AuditLogger::record(
            'sessions_revoked',
            subject: $request->user(),
            actor: $request->user(),
            description: 'All device sessions were revoked.',
            context: ['count' => $count],
        );
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        return $this->success(['revoked_count' => $count], 'All devices signed out');
    }

    public function reauthenticate(Request $request)
    {
        $validated = $request->validate(['password' => ['required', 'string', 'max:255']]);
        if (!Hash::check($validated['password'], $request->user()->password)) {
            AuditLogger::record(
                'sensitive_authentication_failed',
                subject: $request->user(),
                actor: $request->user(),
                description: 'Recent authentication confirmation failed.',
            );
            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }

        $session = $this->sessions->ensureActive($request);
        $this->sessions->markRecentlyAuthenticated($session, $request);
        AuditLogger::record(
            'sensitive_authentication_succeeded',
            subject: $request->user(),
            actor: $request->user(),
            description: 'Recent authentication was confirmed.',
        );
        return $this->success(null, 'Identity confirmed');
    }

    public function unlock(Request $request)
    {
        $validated = $request->validate(['password' => ['required', 'string', 'max:255']]);
        $user = $request->user();
        if (!Hash::check($validated['password'], $user->password)) {
            AuditLogger::record(
                'unlock_failed',
                subject: $user,
                actor: $user,
                description: 'An app unlock attempt failed.',
            );
            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }

        $session = $this->sessions->ensureActive($request);
        $this->sessions->markRecentlyAuthenticated($session, $request);
        AuditLogger::record('unlock_succeeded', subject: $user, actor: $user, description: 'The app was unlocked.');

        return $this->success(['session' => $this->sessions->payload($session, $session->id)], 'App unlocked');
    }
}
