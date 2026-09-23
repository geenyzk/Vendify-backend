<?php

namespace App\Classes\Payment;

enum WebhookOutcome: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Retry = 'retry';
}
