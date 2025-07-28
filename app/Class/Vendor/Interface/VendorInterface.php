<?php

namespace App\Class\Vendor\Interface;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

interface VendorInterface
{
    public function process(string $service, array $payload): JsonResponse;

    public function checkBalance(): string;
    public function login(): mixed;

    public function verifyTransaction(string $tx_ref): array;

    public function supportsService(string $service): bool;

    public function isHealthy(): bool;
    public function plans(?array $payload=null): mixed;

    public function formatPayload(string $service, array $payload): array;

    public function webhook(Request $request):void;

    public function sandbox(): self;
}
