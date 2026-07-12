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
        $this->register(new SearchDataPlansTool());
        $this->register(new ListMarketingTool());
        $this->register(new ListAffiliatesTool());
        $this->register(new ListAffiliateCustomersTool());
        $this->register(new AnalyzePricingTrendsTool());
        $this->register(new RecommendPricingStrategyTool());
        $this->register(new GetPricingStrategyTool());

        // Mutating tools — proposal-only, gated by their real permission slug.
        $this->register(new RefundTransactionTool());
        $this->register(new UpdateTransactionStatusTool());
        $this->register(new FundUserWalletTool());
        $this->register(new ToggleServiceControlTool());
        $this->register(new SendBroadcastTool());
        $this->register(new UpdateDataPlanPriceTool());
        $this->register(new AdjustDataPlanPriceTool());
        $this->register(new CreateDiscountTool());
        $this->register(new CreatePromotionTool());
        $this->register(new CreateAffiliateDirectiveTool());
        $this->register(new GetSiteSettingsTool());
        $this->register(new UpdateSiteSettingsTool());
        $this->register(new GetWelcomeMessageTool());
        $this->register(new UpdateWelcomeMessageTool());
        $this->register(new SendAffiliateCustomerMessageTool());
    }

    public function register(AiTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?AiTool
    {
        return $this->tools[$name] ?? null;
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
        $permission = $tool->permission();

        if ($permission === null) {
            return true;
        }

        return (bool) ($user->role?->hasPermission($permission) ?? false);
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
