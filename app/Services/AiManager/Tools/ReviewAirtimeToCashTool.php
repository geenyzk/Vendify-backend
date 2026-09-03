<?php

namespace App\Services\AiManager\Tools;

use App\Http\Controllers\AirtimeToCashController;
use App\Models\AirtimeToCashRequest;
use App\Models\User;
use App\Services\AiManager\AiManagerException;
use App\Services\AiManager\Tools\Concerns\CallsControllerAction;
use App\Services\AirtimeToCashSettlementService;
use Illuminate\Http\Request;

/**
 * Approve (credit the payout) or reject a pending airtime-to-cash request.
 * Mutating: proposal-only, gated by `airtime_to_cash`. Delegates to
 * AirtimeToCashController so the wallet credit and notifications are reused.
 */
class ReviewAirtimeToCashTool extends AiTool
{
    use CallsControllerAction;

    public function name(): string
    {
        return 'review_airtime_to_cash';
    }

    public function description(): string
    {
        return 'Approve or reject a pending airtime-to-cash request. "approve" credits the pre-computed payout to the customer wallet; "reject" declines it and requires a reason. Verify the id is still pending with list_airtime_to_cash first. Creates a pending action an admin must approve.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function permission(): ?string
    {
        return 'airtime_to_cash';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'request_id' => ['type' => 'integer', 'description' => 'Id of the airtime-to-cash request.'],
                'action' => ['type' => 'string', 'enum' => ['approve', 'reject'], 'description' => 'approve = credit payout; reject = decline.'],
                'reason' => ['type' => 'string', 'description' => 'Required when rejecting: why it was declined (shown to the customer).'],
            ],
            'required' => ['request_id', 'action'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'request_id' => 'required|integer',
            'action' => 'required|in:approve,reject',
            'reason' => 'nullable|string|max:255|required_if:action,reject',
        ];
    }

    public function summarize(array $arguments): string
    {
        $id = $arguments['request_id'];

        return $arguments['action'] === 'approve'
            ? "Approve airtime-to-cash request #{$id} and credit the payout"
            : "Reject airtime-to-cash request #{$id} (reason: " . ($arguments['reason'] ?? '—') . ')';
    }

    public function handle(array $arguments, User $actor): array
    {
        $atc = AirtimeToCashRequest::find($arguments['request_id']);
        if (!$atc) {
            throw new AiManagerException('Airtime-to-cash request not found.');
        }

        $controller = app(AirtimeToCashController::class);

        if ($arguments['action'] === 'approve') {
            return $this->unwrap(
                $controller->approve($atc, app(AirtimeToCashSettlementService::class)),
                'The request could not be approved.',
            );
        }

        $request = Request::create('/', 'POST', ['reason' => $arguments['reason'] ?? '']);

        return $this->unwrap($controller->reject($request, $atc), 'The request could not be rejected.');
    }
}
