<?php

namespace App\Jobs;


use App\Models\Transaction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;

class SendTransactionCallback implements ShouldQueue
{
   use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public User $user;
    public Transaction $transaction;

   public function __construct(User $user, Transaction $transaction)
    {
        $this->user = $user;
        $this->transaction = $transaction;
    }


    public function handle(): void
    {
        // If user doesn't have a webhook set up, skip
        if (empty($this->user->webhook_url)) {
            return;
        }

        $payload = [
            'transaction_reference' => $this->transaction->transaction_reference,
            'status' => $this->transaction->status,
            'amount' => $this->transaction->amount,
            'type' => $this->transaction->transaction_type,
            'provider' => $this->transaction->provider,
            'phone' => $this->transaction->receiver,
            'plan_type' => $this->transaction->plan_type,
            'response_message' => $this->transaction->response_message,
        ];

        // Fire the callback to the user's webhook
        Http::timeout(10)->post($this->user->webhook_url, $payload);
    }
}
