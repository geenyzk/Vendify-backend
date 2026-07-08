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
