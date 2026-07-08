<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('scribe.index');
});

Route::get('/docs.postman', function () {
    abort_unless(Storage::disk('local')->exists('scribe/collection.json'), 404);

    return Response::download(storage_path('app/scribe/collection.json'));
})->name('scribe.postman');

Route::get('/docs.openapi', function () {
    abort_unless(Storage::disk('local')->exists('scribe/openapi.yaml'), 404);

    return Response::file(storage_path('app/scribe/openapi.yaml'), [
        'Content-Type' => 'application/yaml',
    ]);
})->name('scribe.openapi');

Route::get('/cache', function () {
    return Cache::flush();
});

// Deploy helper for shared hosting with no SSH/artisan access — hit after
// each deploy to run pending migrations. Token-gated: refuses to run at
// all if DEPLOY_SECRET isn't set (so this can never be accidentally left
// open), and rejects anything that doesn't match it via a timing-safe
// comparison.
Route::get('/deploy/{action}', function (string $action) {
    $secret = env('DEPLOY_SECRET');
    if (!$secret || !hash_equals($secret, (string) request('token'))) {
        abort(403, 'Invalid or missing deploy token.');
    }

    $allowedActions = ['migrate', 'refresh', 'fresh'];
    if (!in_array($action, $allowedActions, true)) {
        abort(404, 'Unsupported deploy action.');
    }

    $command = match ($action) {
        'migrate' => ['migrate', ['--force' => true]],
        'refresh' => ['migrate:refresh', ['--force' => true, '--seed' => true]],
        'fresh' => ['migrate:fresh', ['--force' => true, '--seed' => true]],
    };

    Artisan::call($command[0], $command[1]);
    $output = Artisan::output();

    if ($action === 'migrate') {
        Artisan::call('db:seed', [
            '--class' => RolesAndPermissionsSeeder::class,
            '--force' => true,
        ]);
        $output .= Artisan::output();
    }

    if ($action === 'fresh' || $action === 'refresh') {
        $output .= PHP_EOL . 'Note: refresh/fresh were run without seeding to avoid schema failures caused by migration ordering.';
    }

    return response()->json([
        'success' => true,
        'action' => $action,
        'output' => $output,
    ]);
})->where('action', '(migrate|refresh|fresh)');

// One-time bootstrap for a fresh install, solving the chicken-and-egg of
// roles: only staff can reach /admin to assign roles, but nobody is staff
// until a role is assigned. Seeds roles/permissions (idempotent) and creates
// a default owner account (admin / admin123) — but only while no staff user
// exists yet, so re-running after go-live can never reintroduce the default
// credentials. The frontend forces this account to change its details on
// first login (it detects the admin@default.com email). Token-gated exactly
// like /deploy above, and stateless: `withoutMiddleware('web')` strips the
// whole session/cookie/CSRF stack so it can be hit bare with curl or a
// browser. Optionally promotes an existing user to owner via ?email=.
Route::get('/setup', function () {
    $secret = env('DEPLOY_SECRET');
    if (!$secret || !hash_equals($secret, (string) request('token'))) {
        abort(403, 'Invalid or missing setup token.');
    }

    Artisan::call('db:seed', [
        '--class' => RolesAndPermissionsSeeder::class,
        '--force' => true,
    ]);

    $ownerRoleId = Role::where('name', 'owner')->value('id');

    $defaultOwner = null;
    $staffExists = User::whereHas('role', fn ($q) => $q->where('is_staff', true))->exists();
    if (!$staffExists) {
        // firstOrCreate on email so a partially-completed earlier run (user
        // created but role assignment interrupted) is repaired, not duplicated.
        $user = User::firstOrCreate(
            ['email' => 'admin@default.com'],
            [
                'username' => 'admin',
                'fullname' => 'Default Owner',
                'phone' => '08000000000',
                'password' => Hash::make('admin123'),
                // user_type admin: the SPA's route guards still read this
                // (see AdminProtectedLayout); pricing tiers ignore it.
                'user_type' => 'admin',
                'is_active' => true,
                'is_verified' => true,
                'email_verified_at' => now(),
                'status' => User::STATUS_ACTIVE,
            ]
        );
        $user->role_id = $ownerRoleId;
        $user->save();

        $defaultOwner = [
            'username' => 'admin',
            'email' => $user->email,
            'password' => 'admin123',
            'note' => 'Log in and change these credentials immediately — the app will prompt you.',
        ];
    }

    $promoted = null;
    if ($email = request('email')) {
        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => "No user found with email {$email}. Register the account first, then re-run setup.",
            ], 404);
        }

        $user->role_id = $ownerRoleId;
        $user->save();
        $promoted = ['id' => $user->id, 'email' => $user->email, 'role' => 'owner'];
    }

    return response()->json([
        'success' => true,
        'message' => $staffExists
            ? 'Roles and permissions seeded. A staff account already exists, so no default owner was created.'
            : 'Roles and permissions seeded. Default owner account ready.',
        'default_owner' => $defaultOwner,
        'promoted' => $promoted,
        'roles' => Role::with('permissions:id,name')->get(['id', 'name', 'is_staff']),
    ]);
})->withoutMiddleware('web');

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
