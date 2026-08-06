<?php

namespace App\Http\Controllers;

use App\Classes\TemplateParser;
use App\Mail\AdminNotificationMail;
use App\Models\ChildCustomer;
use App\Models\ChildCustomerMessage;
use App\Models\ChildInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-off email contact with a child affiliate's customer, using the
 * parent's own mail infrastructure — the "parent can talk to the child's
 * customers directly" half of the affiliate relationship. Every send is
 * logged to child_customer_messages so the modal reads as a history;
 * replies arrive in the admin's normal inbox, not here.
 *
 * Templates support the same {{ user.* }} placeholders as broadcasts —
 * resolved against the ChildCustomer (username, email, phone,
 * wallet_balance, ...); unknown placeholders pass through untouched.
 */
class ChildCustomerContactController extends Controller
{
    public function sendBulk(Request $request, string $instanceId): JsonResponse
    {
        $validated = $request->validate([
            'customer_ids' => 'required|array|min:1|max:500',
            'customer_ids.*' => 'required|distinct',
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:10000',
        ]);

        $instance = ChildInstance::find($instanceId);
        if (!$instance) {
            return $this->fail([], 'Affiliate not found', 404);
        }

        $customerIds = array_values(array_unique(array_map('strval', $validated['customer_ids'])));
        $customers = ChildCustomer::where('child_instance_id', $instance->id)
            ->whereIn('id', $customerIds)
            ->get()
            ->keyBy(fn (ChildCustomer $customer) => (string) $customer->id);
        $results = [];
        $sent = 0;

        foreach ($customerIds as $customerId) {
            $customer = $customers->get($customerId);

            if (!$customer) {
                $results[] = [
                    'customer_id' => $customerId,
                    'success' => false,
                    'email_sent' => false,
                    'message' => 'Customer not found on this affiliate',
                ];
                continue;
            }

            if (!$customer->migrated_to_user_id) {
                $results[] = [
                    'customer_id' => $customerId,
                    'success' => false,
                    'email_sent' => false,
                    'message' => 'Customer must be migrated before sending this email',
                ];
                continue;
            }

            if (!$customer->email) {
                $results[] = [
                    'customer_id' => $customerId,
                    'success' => false,
                    'email_sent' => false,
                    'message' => 'Customer has no email address',
                ];
                continue;
            }

            $parser = TemplateParser::make()->with(['user' => $customer]);
            $subject = $parser->parse($validated['subject']);
            $body = $parser->parse($validated['body']);

            try {
                Mail::to($customer->email)->send(new AdminNotificationMail($subject, $body));

                ChildCustomerMessage::create([
                    'child_customer_id' => $customer->id,
                    'sent_by' => $request->user()?->id,
                    'subject' => $subject,
                    'body' => $body,
                ]);

                $results[] = [
                    'customer_id' => $customerId,
                    'success' => true,
                    'email_sent' => true,
                    'message' => 'Email sent',
                ];
                $sent++;
            } catch (Throwable $e) {
                Log::warning('Bulk affiliate customer email failed', [
                    'child_instance_id' => $instance->id,
                    'child_customer_id' => $customerId,
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'customer_id' => $customerId,
                    'success' => false,
                    'email_sent' => false,
                    'message' => 'Email could not be sent',
                ];
            }
        }

        return $this->success($results, "Sent email to {$sent} of " . count($customerIds) . ' customers');
    }

    public function index(Request $request, string $instanceId, string $customerId): JsonResponse
    {
        [$customer, $error] = $this->resolveCustomer($instanceId, $customerId);
        if ($error) {
            return $error;
        }

        $messages = $customer->messages()
            ->with('sender:id,username')
            ->latest('id')
            ->limit(50)
            ->get();

        return $this->success($messages);
    }

    public function send(Request $request, string $instanceId, string $customerId): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:10000',
        ]);

        [$customer, $error] = $this->resolveCustomer($instanceId, $customerId);
        if ($error) {
            return $error;
        }

        if (!$customer->email) {
            return $this->fail([], 'This customer has no email address synced from the child yet.', 422);
        }

        $parser = TemplateParser::make()->with(['user' => $customer]);
        $subject = $parser->parse($validated['subject']);
        $body = $parser->parse($validated['body']);

        Mail::to($customer->email)->send(new AdminNotificationMail($subject, $body));

        $message = ChildCustomerMessage::create([
            'child_customer_id' => $customer->id,
            'sent_by' => $request->user()?->id,
            'subject' => $subject,
            'body' => $body,
        ]);

        return $this->success($message->load('sender:id,username'), 'Email sent');
    }

    /**
     * @return array{0: ?ChildCustomer, 1: ?JsonResponse}
     */
    protected function resolveCustomer(string $instanceId, string $customerId): array
    {
        $instance = ChildInstance::find($instanceId);
        if (!$instance) {
            return [null, $this->fail([], 'Affiliate not found', 404)];
        }

        $customer = ChildCustomer::where('child_instance_id', $instance->id)->find($customerId);
        if (!$customer) {
            return [null, $this->fail([], 'Customer not found on this affiliate', 404)];
        }

        return [$customer, null];
    }
}
