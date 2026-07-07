<?php

namespace App\Http\Controllers;

use App\Models\ChildInstance;
use App\Models\DataPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Backs both search bars (admin topbar, user topbar). Each endpoint is
 * scoped to what that caller is actually allowed to see — there's no
 * granular per-admin permission-slug system on this branch (no
 * Role/Permission models exist), so "permission filtered" here means: the
 * admin search requires user_type=admin, and the user search only ever
 * touches the logged-in user's own rows, never another user's.
 */
class SearchController extends Controller
{
    private const LIMIT_PER_GROUP = 5;

    public function adminSearch(Request $request)
    {
        $user = Auth::user();
        if (!$user || $user->user_type !== 'admin') {
            return $this->fail([], 'Unauthorized', 403);
        }

        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return $this->success(['results' => []]);
        }
        $like = "%{$q}%";

        $users = User::where(function ($query) use ($like) {
            $query->where('username', 'like', $like)
                ->orWhere('fullname', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like);
        })
            ->limit(self::LIMIT_PER_GROUP)
            ->get(['id', 'fullname', 'username', 'email'])
            ->map(fn ($u) => [
                'type' => 'customer',
                'id' => $u->id,
                'title' => $u->fullname ?: $u->username,
                'subtitle' => $u->email ?? $u->username,
                'path' => "/admin/customers/users/{$u->id}",
            ]);

        $transactions = Transaction::where('transaction_reference', 'like', $like)
            ->orWhere('account_or_phone', 'like', $like)
            ->limit(self::LIMIT_PER_GROUP)
            ->get(['id', 'transaction_reference', 'transaction_type', 'amount', 'status'])
            ->map(fn ($t) => [
                'type' => 'transaction',
                'id' => $t->id,
                'title' => $t->transaction_reference,
                'subtitle' => ucwords(str_replace('_', ' ', $t->transaction_type)) . " · ₦{$t->amount} · {$t->status}",
                'path' => "/admin/transactions?reference={$t->transaction_reference}",
            ]);

        $vendors = Vendor::where('name', 'like', $like)
            ->limit(self::LIMIT_PER_GROUP)
            ->get(['id', 'name', 'sub_category'])
            ->map(fn ($v) => [
                'type' => 'provider',
                'id' => $v->id,
                'title' => $v->name,
                'subtitle' => $v->sub_category ?? 'Provider',
                'path' => "/admin/apis/provider/{$v->id}",
            ]);

        $dataPlans = DataPlan::where('plan_name', 'like', $like)
            ->orWhere('network', 'like', $like)
            ->limit(self::LIMIT_PER_GROUP)
            ->get(['id', 'plan_name', 'plan_size', 'network'])
            ->map(fn ($p) => [
                'type' => 'data_plan',
                'id' => $p->id,
                'title' => "{$p->plan_name}{$p->plan_size}",
                'subtitle' => ucfirst($p->network) . ' data plan',
                'path' => "/admin/products/airtime-data?tab=data-plans",
            ]);

        $affiliates = ChildInstance::where('name', 'like', $like)
            ->orWhere('slug', 'like', $like)
            ->limit(self::LIMIT_PER_GROUP)
            ->get(['id', 'name', 'slug', 'status'])
            ->map(fn ($a) => [
                'type' => 'affiliate',
                'id' => $a->id,
                'title' => $a->name,
                'subtitle' => "Affiliate · {$a->status}",
                'path' => "/admin/affiliates/{$a->id}",
            ]);

        $results = $users->concat($transactions)->concat($vendors)->concat($dataPlans)->concat($affiliates);

        return $this->success(['results' => $results->values()]);
    }

    public function userSearch(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return $this->fail([], 'Unauthorized', 401);
        }

        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return $this->success(['results' => []]);
        }
        $like = "%{$q}%";

        // Scoped to this user's own rows only — never another customer's.
        $transactions = Transaction::where('user_id', $user->id)
            ->where(function ($query) use ($like) {
                $query->where('transaction_reference', 'like', $like)
                    ->orWhere('receiver', 'like', $like)
                    ->orWhere('account_or_phone', 'like', $like);
            })
            ->limit(self::LIMIT_PER_GROUP)
            ->get(['id', 'transaction_reference', 'transaction_type', 'amount', 'status'])
            ->map(fn ($t) => [
                'type' => 'transaction',
                'id' => $t->id,
                'title' => $t->transaction_reference,
                'subtitle' => ucwords(str_replace('_', ' ', $t->transaction_type)) . " · ₦{$t->amount} · {$t->status}",
                'path' => "/transactions?reference={$t->transaction_reference}",
            ]);

        return $this->success(['results' => $transactions->values()]);
    }
}
