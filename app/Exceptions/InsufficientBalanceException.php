<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by TransactionService::reserve() when a wallet can't cover a
 * purchase. Distinct type so the vendor dispatch can turn it into a clean
 * 402 instead of a generic 500 — and so it's never confused with an actual
 * failure that delivered value.
 */
class InsufficientBalanceException extends RuntimeException
{
}
