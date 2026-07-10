<?php

use App\Http\Middleware\VerifyChildSignature;
use Illuminate\Http\Request;

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
