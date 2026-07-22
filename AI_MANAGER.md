# AI Manager

An in-app admin assistant that monitors the platform and manages operations
through natural-language chat. It uses OpenAI (Chat Completions with function
calling) to read live site data and to **propose** admin actions — nothing that
changes state ever runs without an explicit human approval.

## Setup

1. Set an OpenAI key in `.env` (see `.env.example` for all keys):

   ```env
   OPENAI_API_KEY=sk-...
   OPENAI_MODEL=gpt-4o        # any tool-calling capable model
   ```

2. Run migrations (creates the tables + the `ai_manager` permission and grants
   it to the admin role):

   ```bash
   php artisan migrate
   ```

3. Grant the **AI Manager** permission to any additional roles via
   *Admin → Customers → Roles & Permissions*. The admin role gets it by default.

The UI lives at **Admin → AI Manager** (`/admin/ai-manager`).

## How access works

- The whole feature is gated by the `ai_manager` permission
  (`routes/api.php`, middleware `permission:ai_manager`).
- The assistant is only ever shown the tools the **current** admin is allowed to
  use. A mutating tool is filtered out entirely if the admin's role lacks the
  underlying permission, so the AI can't even *propose* it.
- Approving a proposed action re-checks that same permission again at execution
  time (`AiManagerService::approve`) — defence in depth, the AI can never let an
  admin exceed their own rights.

## Safety model (read + gated actions)

- **Read-only tools** (site stats, transaction/user search, vendor balances,
  plan/marketing/affiliate listings) run automatically and only ground the
  assistant's answers in real data.
- **Mutating tools** (refunds, wallet adjustments, transaction status, service
  toggles, broadcasts, plan prices, discounts/promotions, affiliate directives)
  are **never executed inline**. Each becomes a row in `ai_action_proposals`
  with status `pending`; an admin must click **Approve** for it to run.
- `ai_action_proposals` doubles as the audit log: it records the arguments, who
  approved/rejected, when it executed, and the result or error.

## Tools

| Tool | Type | Permission |
|------|------|------------|
| `get_site_stats`, `search_transactions`, `get_transaction`, `search_users`, `get_user`, `get_vendor_balances`, `search_data_plans`, `list_marketing`, `list_affiliates` | read | — (any AI Manager admin) |
| `refund_transaction`, `update_transaction_status` | action | `transactions` |
| `fund_user_wallet` | action | `wallets` |
| `toggle_service_control`, `update_data_plan_price`, `create_discount`, `create_promotion`, `create_affiliate_directive` | action | `settings` |
| `send_broadcast` | action | `support` |

Each mutating tool mirrors the existing controller/service logic it wraps (e.g.
`refund_transaction` uses the same `TransactionService::fundUser` primitive and
guards as `TransactionController::refund`), so behaviour never diverges from the
manual admin UI.

## Code map

- Config: `config/services.php` → `openai`
- HTTP: `app/Services/AiManager/OpenAiClient.php`
- Orchestration: `app/Services/AiManager/AiManagerService.php`
- Tools: `app/Services/AiManager/Tools/` (`AiTool` base, `ToolRegistry`)
- API: `app/Http/Controllers/AiManagerController.php`, routes under `/admin/ai/*`
- Models: `AiConversation`, `AiMessage`, `AiActionProposal`
- Frontend: `vtu_2/src/features/admin/pages/ai-manager/`

## Restricted Vendify Data Plans browser

The optional browser executor is intentionally limited to the first-party
`/admin/products/airtime-data` Data Plans list and its create/edit forms.
Reads run inline; creates and edits use the normal AI action proposal and only
run after an admin approves the preview. Every approved run captures before,
preview and after screenshots, reopens the saved row, verifies supplied values,
and writes the run id and evidence paths to the audit trail.

Install the isolated runtime and browser once:

```bash
cd browser
npm install
npx playwright install chromium
```

Create a dedicated admin browser session without putting credentials in `.env`
or AI tool arguments. Sign in in the window that opens; the state is saved
automatically when the admin panel loads:

```bash
node browser/save-auth-state.mjs https://your-vendify-domain.example storage/app/private/vendify-browser-auth.json
```

Then configure the `VENDIFY_BROWSER_*` values from `.env.example` and enable the
feature. `VENDIFY_BROWSER_ALLOWED_ORIGINS` must contain only the Vendify UI/API
origins. The runner blocks other origins, non-Data-Plan document routes,
downloads, deletion, arbitrary JavaScript and arbitrary shell commands.
