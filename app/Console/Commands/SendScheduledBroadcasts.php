<?php

namespace App\Console\Commands;

use App\Http\Controllers\BroadcastController;
use App\Models\Broadcast;
use Illuminate\Console\Command;

class SendScheduledBroadcasts extends Command
{
    protected $signature = 'broadcasts:send-scheduled';

    protected $description = 'Sends any scheduled broadcasts whose scheduled_at time has passed.';

    public function handle(BroadcastController $controller): int
    {
        $due = Broadcast::where('sent', false)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        if ($due->isEmpty()) {
            $this->info('No scheduled broadcasts are due.');
            return self::SUCCESS;
        }

        foreach ($due as $broadcast) {
            $label = $broadcast->name ?? $broadcast->title ?? "#{$broadcast->id}";
            $this->info("Sending broadcast {$label}...");
            $controller->executeScheduled($broadcast);
        }

        $this->info("Processed {$due->count()} scheduled broadcast(s).");
        return self::SUCCESS;
    }
}
