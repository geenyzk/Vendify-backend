<?php

use App\Http\Middleware\VerifyChildSignature;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('child_instances');
    Schema::create('child_instances', function (Blueprint $table) {
        $table->id();
        $table->string('slug')->unique();
        $table->string('status')->default('active');
        $table->text('shared_secret');
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
    });
});

test('child directive requests are rejected with a clear auth error when the instance is unknown', function () {
    $request = Request::create('/api/child/example/directives', 'GET');
    $request->headers->set('X-Child-Instance', 'example');
    $request->headers->set('X-Timestamp', (string) time());
    $request->headers->set('X-Signature', 'not-a-valid-signature');

    $middleware = new VerifyChildSignature();

    $response = $middleware->handle($request, function ($request) {
        return response()->json(['ok' => true], 200);
    });

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true)['message'])->toBe('Unknown or inactive child instance');
});
