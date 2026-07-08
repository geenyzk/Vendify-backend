<?php

use App\Models\Provider;

it('exposes payment connection and balance through the provider model', function () {
    $provider = new Provider([
        'name' => 'flutterwave',
        'category' => 'payment',
    ]);

    expect($provider->connection)->toBeNull();
    expect($provider->balance)->toBeNull();
});
