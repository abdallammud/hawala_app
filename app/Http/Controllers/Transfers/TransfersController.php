<?php

namespace App\Http\Controllers\Transfers;

use App\Enums\TransferStatus;
use App\Exceptions\Ledger\InsufficientBalanceException;
use App\Exceptions\Rates\RateUnavailableException;
use App\Exceptions\Transfers\AmountTooSmallException;
use App\Exceptions\Transfers\TransferStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transfers\StoreTransferRequest;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Transfer;
use App\Services\Transfers\ReceiptService;
use App\Services\Transfers\TransferCancellationService;
use App\Services\Transfers\TransferCollectionService;
use App\Services\Transfers\TransferService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TransfersController extends Controller
{
    public function __construct(
        private TransferService $transfers,
        private TransferCancellationService $cancellations,
        private TransferCollectionService $collections,
        private ReceiptService $receipts,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Transfer::class);

        $transfers = Transfer::query()
            ->visibleTo($request->user())
            ->with(['sendingAgent', 'branch', 'claimedBy'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search');
                $q->where(function ($q) use ($s) {
                    $q->where('code', 'like', "%{$s}%")
                        ->orWhere('sender_name', 'like', "%{$s}%")
                        ->orWhere('receiver_name', 'like', "%{$s}%")
                        ->orWhere('receiver_phone', 'like', "%{$s}%");
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->boolean('uncollected'), fn ($q) => $q->where('is_uncollected', true)->whereNull('fully_collected_at'))
            ->when($request->boolean('large'), fn ($q) => $q->where('is_large', true))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('transfers.index', [
            'transfers' => $transfers,
            'statuses' => TransferStatus::options(),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Transfer::class);

        $branches = Branch::query()->payoutPoints()->orderBy('name')->get();

        $currencies = Currency::query()->where('is_active', true)->orderBy('sort')->pluck('code', 'code');

        // An agent in Europe collects in the same currency every day, so the
        // "Customer pays" box opens on whatever they used last.
        $lastCurrency = Transfer::query()
            ->where('sending_agent_id', $request->user()->id)
            ->latest('id')
            ->value('original_currency');

        // "New transfer" from a customer's page arrives with ?customer=ID.
        $prefill = null;

        if ($request->filled('customer')) {
            $prefill = Customer::query()->visibleTo($request->user())->find($request->integer('customer'));
        }

        // Most agents send from one place: start on the last country used.
        $lastSender = Transfer::query()
            ->where('sending_agent_id', $request->user()->id)
            ->latest('id')
            ->first(['sender_country', 'sender_city', 'sender_province']);

        // Keep the token (and so the tracking number) across a failed submit.
        $idempotencyKey = $request->old('idempotency_key') ?: (string) Str::uuid();

        return view('transfers.create', [
            'branches' => $branches->pluck('name', 'id'),
            'onlyBranchId' => $branches->count() === 1 ? $branches->first()->id : null,
            'currencies' => $currencies,
            'payCurrency' => $currencies->has($lastCurrency) ? $lastCurrency : 'USD',
            'accountTypes' => AccountType::query()->where('is_active', true)->orderBy('sort')->pluck('name', 'id'),
            'prefill' => $prefill,
            'senderDefaults' => [
                'country' => $lastSender?->sender_country,
                'city' => $lastSender?->sender_city,
                'province' => $lastSender?->sender_province,
            ],
            'countries' => config('hawala.countries'),
            'idempotencyKey' => $idempotencyKey,
            'trackingNumber' => $this->transfers->reserveTrackingCode($idempotencyKey),
        ]);
    }

    public function store(StoreTransferRequest $request): RedirectResponse
    {
        $agent = $request->user();
        $branch = Branch::findOrFail($request->integer('branch_id'));
        $mode = $request->mode();
        $amountCents = $request->sourceAmountCents();
        $payoutCurrency = $request->string('payout_currency')->toString();

        // Duplicate warning is a speed bump, not a block: the agent resubmits
        // with confirm_duplicate once they've seen it (BUILD.md §8).
        if (! $request->boolean('confirm_duplicate')) {
            $duplicate = $this->transfers->findRecentDuplicate(
                $agent,
                $request->string('receiver_phone'),
                $amountCents,
                $mode,
                $request->string('currency'),
                $payoutCurrency,
            );

            if ($duplicate) {
                return back()->withInput()->with('duplicate_warning', __(
                    'You sent :amount to this number :ago (:code). Send anyway?',
                    [
                        'amount' => Money::format($duplicate->amount_cents),
                        'ago' => $duplicate->created_at->diffForHumans(),
                        'code' => $duplicate->code,
                    ]
                ));
            }
        }

        try {
            $transfer = $this->transfers->create($agent, $branch, [
                'sender_name' => $request->string('sender_name'),
                'sender_phone' => $request->string('sender_phone'),
                'sender_country' => $request->string('sender_country')->trim()->toString(),
                'sender_city' => $request->string('sender_city')->trim()->toString(),
                'sender_province' => $request->filled('sender_province') ? $request->string('sender_province')->trim()->toString() : null,
                'receiver_name' => $request->string('receiver_name'),
                'receiver_phone' => $request->string('receiver_phone'),
                'receiver_country' => $request->string('receiver_country')->trim()->toString(),
                'receiver_city' => $request->string('receiver_city')->trim()->toString(),
                'receiver_province' => $request->filled('receiver_province') ? $request->string('receiver_province')->trim()->toString() : null,
                'receiver_account_type_id' => $request->input('receiver_account_type_id'),
                'receiver_account_number' => $request->input('receiver_account_number'),
                'currency' => $request->string('currency'),
                'mode' => $mode,
                'payout_currency' => $payoutCurrency,
                'amount_cents' => $amountCents,
                'is_uncollected' => $request->boolean('is_uncollected'),
                'note' => $request->input('note'),
            ], $request->string('idempotency_key'));
        } catch (InsufficientBalanceException $e) {
            return back()->withInput()->with('insufficient_balance', $e->getMessage());
        } catch (RateUnavailableException $e) {
            return back()->withInput()->withErrors(['currency' => $e->getMessage()]);
        } catch (AmountTooSmallException $e) {
            // Only reachable in Pay mode: the cash on the counter is smaller
            // than the fee it has to carry.
            return back()->withInput()->withErrors(['pay_amount' => $e->getMessage()]);
        }

        return redirect()->route('transfers.show', $transfer)
            ->with('status', __('Transfer :code created.', ['code' => $transfer->code]));
    }

    public function show(Transfer $transfer): View
    {
        $this->authorize('view', $transfer);

        return view('transfers.show', [
            'transfer' => $transfer->load([
                'sendingAgent', 'branch', 'claimedBy', 'paidBy', 'cancelledBy', 'assignedBy',
                'payoutAccount.accountType', 'receiverAccountType',
                'collections.recordedBy', 'cancellationRequests.requestedBy',
            ]),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('sort')->pluck('code', 'code'),
            'receipts' => $this->receipts,
        ]);
    }

    /** Receipt PDF, behind the receipts.enabled switch (BUILD.md §13). */
    public function receipt(Transfer $transfer): Response
    {
        $this->authorize('view', $transfer);

        abort_unless($this->receipts->enabled(), 404);

        return $this->receipts->pdf($transfer)->download($this->receipts->filename($transfer));
    }

    public function cancel(Request $request, Transfer $transfer): RedirectResponse
    {
        $this->authorize('cancel', $transfer);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->cancellations->cancel($transfer, $request->user(), $validated['reason']);
        } catch (TransferStateException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Transfer cancelled.'));
    }

    public function requestCancel(Request $request, Transfer $transfer): RedirectResponse
    {
        $this->authorize('requestCancel', $transfer);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->cancellations->requestCancellation($transfer, $request->user(), $validated['reason']);
        } catch (TransferStateException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Cancellation requested — an admin will review it.'));
    }

    public function approveLarge(Request $request, Transfer $transfer): RedirectResponse
    {
        $this->authorize('approveLarge', Transfer::class);

        try {
            $this->cancellations->approveLarge($transfer, $request->user());
        } catch (TransferStateException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Transfer approved and queued.'));
    }

    public function rejectLarge(Request $request, Transfer $transfer): RedirectResponse
    {
        $this->authorize('approveLarge', Transfer::class);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->cancellations->rejectLarge($transfer, $request->user(), $validated['reason']);
        } catch (TransferStateException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Transfer rejected and fully refunded.'));
    }

    public function recordCollection(Request $request, Transfer $transfer): RedirectResponse
    {
        $this->authorize('recordCollection', $transfer);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'exists:currencies,code'],
            'received_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->collections->record(
                $transfer,
                $request->user(),
                Money::parseCents((string) $validated['amount']),
                $validated['currency'],
                $request->date('received_at'),
                $validated['note'] ?? null,
            );
        } catch (RateUnavailableException $e) {
            return back()->withErrors(['currency' => $e->getMessage()]);
        }

        return back()->with('status', __('Payment recorded.'));
    }
}
