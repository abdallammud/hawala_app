<?php

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomersController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Customer::class);

        $user = $request->user();

        $customers = Customer::query()
            ->visibleTo($user)
            ->when($request->filled('search'), fn ($q) => $q->search($request->string('search')->toString()))
            // Counts and totals only cover transfers this user may report on.
            ->withCount(['transfers' => fn ($q) => $q->reportableBy($user)])
            ->withSum(['transfers as sent_cents' => fn ($q) => $q->reportableBy($user)], 'amount_cents')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('customers.index', ['customers' => $customers]);
    }

    /** Type-ahead for the New Transfer form. */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('create', Transfer::class);

        $term = trim($request->string('q')->toString());

        if (mb_strlen($term) < 2 || ! $request->user()->canAny(['customers.view_own', 'customers.view_all'])) {
            return response()->json([]);
        }

        return response()->json(
            Customer::query()
                ->visibleTo($request->user())
                ->search($term)
                ->orderBy('name')
                ->limit(8)
                ->get()
                ->map(fn (Customer $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'phone_display' => $c->phoneLabel(),
                    'country' => $c->country,
                    'city' => $c->city,
                    'province' => $c->province,
                    'location' => $c->location(),
                ])
        );
    }

    public function show(Request $request, Customer $customer): View
    {
        $this->authorize('view', $customer);

        $user = $request->user();

        $transfers = $customer->transfers()->reportableBy($user);

        return view('customers.show', [
            'customer' => $customer,
            'stats' => [
                'count' => (clone $transfers)->count(),
                'sent' => (int) (clone $transfers)->sum('amount_cents'),
                'fees' => (int) (clone $transfers)->sum('fee_cents'),
                'last' => (clone $transfers)->max('created_at'),
            ],
            'transfers' => (clone $transfers)->with(['branch', 'sendingAgent'])->latest()->paginate(15),
        ]);
    }

    public function edit(Customer $customer): View
    {
        $this->authorize('update', $customer);

        return view('customers.edit', ['customer' => $customer, 'countries' => config('hawala.countries')]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update([
            ...$request->safe()->only(['name', 'country', 'city', 'province']),
            'display_phone' => $request->string('phone')->trim()->toString(),
            'phone' => $request->string('phone_digits')->toString(),
        ]);

        return redirect()->route('customers.show', $customer)->with('status', __('Customer updated.'));
    }
}
