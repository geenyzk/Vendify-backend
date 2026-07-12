<?php

namespace App\Services\AiManager\Tools;

use App\Classes\TemplateParser;
use App\Mail\AdminNotificationMail;
use App\Models\ChildCustomer;
use App\Models\ChildInstance;
use App\Models\User;
use App\Services\AiManager\AiManagerException;
use Illuminate\Support\Facades\Mail;

class SendAffiliateCustomerMessageTool extends AiTool
{
    public function name(): string
    {
        return 'send_affiliate_customer_message';
    }

    public function description(): string
    {
        return 'Propose sending an email from the parent platform to a specific affiliate customer. Use this to message an affiliate customer directly with a subject and body. Creates a pending action; the email is sent after an admin approves it.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function permission(): ?string
    {
        return 'support';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'affiliate_id' => ['type' => 'integer', 'description' => 'Numeric id of the affiliate (child) instance).'],
                'customer_id' => ['type' => 'integer', 'description' => 'Numeric id of the affiliate customer.'],
                'subject' => ['type' => 'string', 'description' => 'Email subject line.'],
                'body' => ['type' => 'string', 'description' => 'Email body text.'],
            ],
            'required' => ['affiliate_id', 'customer_id', 'subject', 'body'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'affiliate_id' => 'required|integer',
            'customer_id' => 'required|integer',
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:10000',
        ];
    }

    public function summarize(array $arguments): string
    {
        return 'Send email to affiliate customer #' . $arguments['customer_id'] . ' on affiliate #' . $arguments['affiliate_id'];
    }

    public function handle(array $arguments, User $actor): array
    {
        $instance = ChildInstance::find($arguments['affiliate_id']);
        if (!$instance) {
            throw new AiManagerException('Affiliate not found.');
        }

        $customer = ChildCustomer::where('child_instance_id', $instance->id)
            ->find($arguments['customer_id']);

        if (!$customer) {
            throw new AiManagerException('Affiliate customer not found.');
        }

        if (!$customer->email) {
            throw new AiManagerException('Affiliate customer has no email address.');
        }

        $parser = TemplateParser::make()->with(['user' => $customer]);
        $subject = $parser->parse($arguments['subject']);
        $body = $parser->parse($arguments['body']);

        Mail::to($customer->email)->send(new AdminNotificationMail($subject, $body));

        return [
            'sent' => true,
            'affiliate_id' => $instance->id,
            'customer_id' => $customer->id,
            'email' => $customer->email,
        ];
    }
}
