<?php

use App\Models\Role;
use App\Models\User;
use App\Support\VendorErrorMessage;
use Illuminate\Support\Facades\Auth;

afterEach(fn () => Auth::logout());

test('customers do not receive upstream provider funding details', function () {
    $user = new User;
    $role = new Role;
    $role->is_staff = false;
    $user->setRelation('role', $role);
    Auth::setUser($user);

    expect(VendorErrorMessage::forCurrentUser('Insufficient Account Kindly Fund Your Wallet -> ₦153.00'))
        ->toBe(VendorErrorMessage::TEMPORARY_FAILURE)
        ->not->toContain('Fund Your Wallet');
});

test('staff receive the exact upstream diagnostic', function () {
    $user = new User;
    $role = new Role;
    $role->is_staff = true;
    $user->setRelation('role', $role);
    Auth::setUser($user);

    $message = 'No Adex plan ID for data plan #24 on vendor [Data world api 1].';
    expect(VendorErrorMessage::forCurrentUser($message))->toBe($message);
});

test('customers receive a useful plan unavailable message for mapping errors', function () {
    expect(VendorErrorMessage::forCurrentUser('No Adex plan ID for data plan #24'))
        ->toBe(VendorErrorMessage::PLAN_UNAVAILABLE);
});
