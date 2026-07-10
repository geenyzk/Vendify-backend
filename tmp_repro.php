<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$request = new Illuminate\Http\Request();
$request->headers->set('X-Child-Instance', 'maditel-qf6qfh');
$request->headers->set('X-Timestamp', (string) time());
$request->headers->set('X-Signature', 'invalid');

$middleware = new App\Http\Middleware\VerifyChildSignature();
$response = $middleware->handle($request, function ($request) {
    return response()->json(['ok' => true], 200);
});

echo $response->getStatusCode() . PHP_EOL;
echo json_encode($response->getData(true)) . PHP_EOL;
