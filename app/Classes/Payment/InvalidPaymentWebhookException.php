<?php

namespace App\Classes\Payment;

use RuntimeException;

/** A validly signed webhook that can never safely result in a wallet credit. */
class InvalidPaymentWebhookException extends RuntimeException
{
}
