<?php

namespace App\Services\AiManager\Tools;

use App\Models\User;

/**
 * The catalogue of tools the AI Manager can use. Tools are filtered per-actor:
 * a mutating tool is only exposed to the model if the admin actually holds the
 * permission that action requires, so the assistant can never even *propose*
 * something the current admin isn't allowed to do.
 */
class ToolRegistry
{
    /** @var array<string, AiTool> */
    private array $tools = [];

    public function __construct()
    {
        // Read-only tools — run inline to ground the assistant in live data.
        $this->register(new GetSiteStatsTool());
        $this->register(new GetSystemHealthTool());
        $this->register(new SearchTransactionsTool());
        $this->register(new GetTransactionTool());
        $this->register(new SearchUsersTool());
        $this->register(new GetUserTool());
        $this->register(new GetVendorBalancesTool());
        $this->register(new SearchPlansTool());
        $this->register(new ListMarketingTool());
        $this->register(new ListAffiliatesTool());
        $this->register(new ListAffiliateCustomersTool());
        $this->register(new ListRolesTool());
        $this->register(new GetRoleTool());
        $this->register(new AnalyzeDataPlanPricingTool());
        $this->register(new GetAnalyticsTool());
        $this->register(new ListWalletWithdrawalsTool());
        $this->register(new ListAirtimeToCashTool());
        $this->register(new GetServiceRoutingTool());
        $this->register(new GetRoleCostMarginsTool());
        $this->register(new ListNetworksTool());
        $this->register(new ListTemplatesTool());
        // Always catalogue browser tools so diagnostics can explain why they
        // are disabled. forUser() is the only path that exposes tools to the
        // model and applies both the feature flag and actor permission.
        $this->register(new InspectVendifyDataPlansTool());

        // Mutating tools — proposal-only, gated by their real permission slug.
        $this->register(new RefundTransactionTool());
        $this->register(new UpdateTransactionStatusTool());
        $this->register(new FundUserWalletTool());
        $this->register(new ToggleServiceControlTool());
        $this->register(new SendBroadcastTool());
        $this->register(new SetDataPlanPriceTool());
        $this->register(new ManagePlanTool());
        $this->register(new CreateDiscountTool());
        $this->register(new CreatePromotionTool());
        $this->register(new CreateAffiliateDirectiveTool());
        $this->register(new GetSiteSettingsTool());
        $this->register(new UpdateSiteSettingsTool());
        $this->register(new GetWelcomeMessageTool());
        $this->register(new UpdateWelcomeMessageTool());
        $this->register(new CreateRoleTool());
        $this->register(new UpdateRoleTool());
        $this->register(new DeleteRoleTool());
        $this->register(new SendAffiliateCustomerMessageTool());
        $this->register(new ReviewWalletWithdrawalTool());
        $this->register(new ReviewAirtimeToCashTool());
        $this->register(new UpdateServiceRoutingTool());
        $this->register(new SetRoleCostMarginsTool());
        $this->register(new UpdateUserStatusTool());
        $this->register(new ManageNetworkTool());
        $this->register(new ManageTemplateTool());
        $this->register(new AutomateVendifyDataPlanTool());
    }

    public function register(AiTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?AiTool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return array<string, AiTool> */
    public function all(): array
    {
        return $this->tools;
    }

    public function enabled(AiTool $tool): bool
    {
        return ! $this->isBrowserTool($tool) || (bool) config('services.vendify_browser.enabled');
    }

    public function disabledReason(AiTool $tool): ?string
    {
        return $this->enabled($tool) ? null : 'VENDIFY_BROWSER_ENABLED is false in the active Laravel configuration.';
    }

    /**
     * Tools this actor is allowed to use, keyed by name. A tool with a
     * permission() slug is only included when the actor's role carries it.
     *
     * @return array<string, AiTool>
     */
    public function forUser(User $user): array
    {
        return array_filter(
            $this->tools,
            fn (AiTool $tool) => $this->userMayUse($user, $tool),
        );
    }

    public function userMayUse(User $user, AiTool $tool): bool
    {
        if (! $this->enabled($tool)) {
            return false;
        }

        $permission = $tool->permission();

        if ($permission === null) {
            return true;
        }

        return (bool) ($user->role?->hasPermission($permission) ?? false);
    }

    private function isBrowserTool(AiTool $tool): bool
    {
        return $tool instanceof InspectVendifyDataPlansTool
            || $tool instanceof AutomateVendifyDataPlanTool;
    }

    /**
     * OpenAI `tools` payload for everything this actor may use.
     *
     * @return array<int, array>
     */
    public function openAiSchemasForUser(User $user): array
    {
        return array_values(array_map(
            fn (AiTool $tool) => $tool->toOpenAiSchema(),
            $this->forUser($user),
        ));
    }
}
