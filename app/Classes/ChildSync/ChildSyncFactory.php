<?php

namespace App\Classes\ChildSync;

use App\HttpResponse;
use App\Models\ChildCustomer;
use App\Models\ChildInstance;
use App\Models\ChildSyncEvent;
use App\Models\ChildTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors VendorFactory::webhook()'s shape (resolve identifier -> delegate)
 * for the child push-in direction. Unlike vendors, a child's payload is
 * already normalized to parent column names by its own config mapping
 * before it arrives here, so there's no per-child provider class to
 * dispatch to — this class does the upsert directly.
 *
 * Expected payload:
 * {
 *   "event_id": "...",          // unique per batch, for idempotency
 *   "resource": "customers"|"transactions",
 *   "records": [ {...}, ... ]
 * }
 */
class ChildSyncFactory
{
    use HttpResponse;

    // Records committed per DB transaction. A batch is ingested in chunks of
    // this size rather than one big transaction so a large push makes durable,
    // resumable progress even if the child's HTTP timeout cuts the request off
    // mid-way (each committed chunk survives; the rest replays next run).
    private const CHUNK_SIZE = 25;

    public static function webhook(Request $request, ChildInstance $instance): JsonResponse
    {
        return (new self())->ingest($request, $instance);
    }

    protected function ingest(Request $request, ChildInstance $instance): JsonResponse
    {
        $eventId = (string) $request->input('event_id');
        $resource = (string) $request->input('resource');
        $records = (array) $request->input('records', []);

        if ($eventId === '' || !in_array($resource, ['customers', 'transactions'], true) || empty($records)) {
            return $this->fail([], 'Invalid sync payload', 422);
        }

        // Idempotency: a batch the child already delivered (but never saw
        // our 2xx for) is a no-op rather than a duplicate ingest.
        $alreadyProcessed = ChildSyncEvent::where('child_instance_id', $instance->id)
            ->where('event_id', $eventId)
            ->exists();
        if ($alreadyProcessed) {
            return $this->success(['skipped' => true], 'Already processed');
        }

        // Ingest in small, independently-committed chunks. Every write is an
        // idempotent upsert keyed on (child_instance_id, external_id), so a
        // chunk that a timed-out earlier attempt already committed is simply
        // re-applied harmlessly on retry — progress is never lost or doubled.
        $count = 0;
        foreach (array_chunk($records, self::CHUNK_SIZE) as $chunk) {
            $count += DB::transaction(fn () => $resource === 'customers'
                ? $this->ingestCustomers($instance, $chunk)
                : $this->ingestTransactions($instance, $chunk));
        }

        // Marker written only once the whole batch is in, so a half-finished
        // batch replays (cheaply, via the idempotent upserts) next time rather
        // than being wrongly skipped. firstOrCreate guards the unique
        // (child_instance_id, event_id) index against a duplicate on replay.
        ChildSyncEvent::firstOrCreate(
            ['child_instance_id' => $instance->id, 'event_id' => $eventId],
            ['resource' => $resource, 'record_count' => $count],
        );

        return $this->success(['ingested' => $count], 'Synced');
    }

    protected function ingestCustomers(ChildInstance $instance, array $records): int
    {
        $count = 0;
        foreach ($records as $record) {
            if (empty($record['external_id'])) {
                continue;
            }
            ChildCustomer::updateOrCreate(
                ['child_instance_id' => $instance->id, 'external_id' => (string) $record['external_id']],
                [
                    'username' => $record['username'] ?? null,
                    'email' => $record['email'] ?? null,
                    'phone' => $record['phone'] ?? null,
                    'wallet_balance' => $record['wallet_balance'] ?? 0,
                    'status' => $record['status'] ?? null,
                ]
            );
            $count++;
        }
        return $count;
    }

    protected function ingestTransactions(ChildInstance $instance, array $records): int
    {
        $count = 0;
        foreach ($records as $record) {
            if (empty($record['external_id'])) {
                continue;
            }

            $childCustomerId = null;
            if (!empty($record['external_customer_id'])) {
                $childCustomerId = ChildCustomer::where('child_instance_id', $instance->id)
                    ->where('external_id', (string) $record['external_customer_id'])
                    ->value('id');
            }

            ChildTransaction::updateOrCreate(
                ['child_instance_id' => $instance->id, 'external_id' => (string) $record['external_id']],
                [
                    'child_customer_id' => $childCustomerId,
                    'transaction_type' => $record['transaction_type'] ?? null,
                    'amount' => $record['amount'] ?? 0,
                    'status' => $record['status'] ?? null,
                    'raw_payload' => $record,
                ]
            );
            $count++;
        }
        return $count;
    }
}
