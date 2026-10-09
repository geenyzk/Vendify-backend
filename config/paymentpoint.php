<?php

$bankCodes = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('PAYMENTPOINT_VIRTUAL_ACCOUNT_BANK_CODES', '20946')),
)));

return [
    // PalmPay only by default. Restore OPay later with "20946,20897" after
    // PaymentPoint confirms that its allocation service is available again.
    'virtual_account_bank_codes' => $bankCodes,

    // Keep disabled until PaymentPoint confirms that resubmitting an existing
    // customer to createVirtualAccount with idType/idNumber is duplicate-safe.
    'kyc_recovery_enabled' => (bool) env('PAYMENTPOINT_KYC_RECOVERY_ENABLED', false),
];
