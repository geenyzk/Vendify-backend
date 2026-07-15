<?php

use App\Classes\Vendor\Providers\Ogdams;
use App\Models\Vendor;

it('retains the generated request reference when a pending response has no vendor reference', function () {
    $ogdams = new class(new Vendor) extends Ogdams
    {
        public function formattedResponse(string $service, array $response): array
        {
            return $this->formatResponse($service, $response);
        }
    };

    $response = $ogdams->formattedResponse('data', [
        'code' => 202,
        'tx_ref' => 'TXN-20260715010101-ABC123',
        'phone' => '07033358618',
        'amount' => 74.81,
    ]);

    expect($response['status'])->toBe('pending')
        ->and($response['transaction_reference'])->toBe('TXN-20260715010101-ABC123');
});
