<?php

namespace App\Http\Controllers\Users;

use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\LedgerStatement;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UsersController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with(['branch', 'roles'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->string('status') === 'active'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'types' => UserType::options(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('users.create', [
            'types' => UserType::options(),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'roles' => Role::query()->orderBy('name')->pluck('name', 'name'),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => $validated['password'],
            'type' => $validated['type'],
            'branch_id' => $validated['branch_id'] ?? null,
            'send_commission_type' => $validated['send_commission_type'] ?? null,
            'send_commission_value' => $validated['send_commission_value'] ?? null,
            'payout_commission_type' => $validated['payout_commission_type'] ?? null,
            'payout_commission_value' => $validated['payout_commission_value'] ?? null,
            'max_credit_cents' => isset($validated['max_credit']) ? Money::parseCents((string) $validated['max_credit']) : null,
        ]);

        $user->assignRole($validated['role']);

        return redirect()->route('users.index')->with('status', __(':name was created.', ['name' => $user->name]));
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('users.edit', [
            'user' => $user->load(['branch', 'roles', 'payoutAccounts.accountType']),
            'types' => UserType::options(),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'roles' => Role::query()->orderBy('name')->pluck('name', 'name'),
            'accountTypes' => AccountType::query()->where('is_active', true)->orderBy('sort')->pluck('name', 'id'),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'type' => $validated['type'],
            'branch_id' => $validated['branch_id'] ?? null,
            'send_commission_type' => $validated['send_commission_type'] ?? null,
            'send_commission_value' => $validated['send_commission_value'] ?? null,
            'payout_commission_type' => $validated['payout_commission_type'] ?? null,
            'payout_commission_value' => $validated['payout_commission_value'] ?? null,
            'max_credit_cents' => isset($validated['max_credit']) ? Money::parseCents((string) $validated['max_credit']) : null,
        ])->save();

        $user->syncRoles([$validated['role']]);

        return back()->with('status', __('Changes saved.'));
    }

    public function statement(Request $request, User $user): View
    {
        $this->authorize('view', $user);

        $types = [
            LedgerAccountType::AgentSend,
            LedgerAccountType::AgentCredit,
            LedgerAccountType::AgentPayout,
            LedgerAccountType::AgentCommission,
        ];

        // Read-only: look up existing ledger accounts without lazily creating
        // one just because someone viewed the page.
        $accounts = LedgerAccount::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->id)
            ->whereIn('type', array_map(fn ($t) => $t->value, $types))
            ->get()
            ->keyBy(fn (LedgerAccount $a) => $a->type->value);

        $active = LedgerAccountType::tryFrom($request->string('type')->toString()) ?? $types[0];
        $activeAccount = $accounts->get($active->value);

        return view('users.statement', [
            'user' => $user,
            'types' => $types,
            'accounts' => $accounts,
            'active' => $active,
            'statement' => $activeAccount ? LedgerStatement::build($activeAccount) : null,
            'closingBalance' => $activeAccount?->balance_cents ?? 0,
        ]);
    }

    public function deactivate(User $user): RedirectResponse
    {
        $this->authorize('deactivate', $user);

        if ($user->hasNonZeroBalances()) {
            return back()->withErrors(['status' => __('This user has a non-zero balance and cannot be deactivated.')]);
        }

        $user->update(['is_active' => false]);

        return back()->with('status', __(':name was deactivated.', ['name' => $user->name]));
    }

    public function activate(User $user): RedirectResponse
    {
        $this->authorize('deactivate', $user);

        $user->update(['is_active' => true]);

        return back()->with('status', __(':name was reactivated.', ['name' => $user->name]));
    }
}
