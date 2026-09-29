<?php

namespace App\Http\Controllers\Transfers;

use App\Enums\CancellationRequestStatus;
use App\Exceptions\Transfers\TransferStateException;
use App\Http\Controllers\Controller;
use App\Models\CancellationRequest;
use App\Models\Transfer;
use App\Services\Transfers\TransferCancellationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CancellationRequestsController extends Controller
{
    public function __construct(private TransferCancellationService $cancellations) {}

    public function index(Request $request): View
    {
        $this->authorize('approveCancel', Transfer::class);

        $requests = CancellationRequest::query()
            ->with(['transfer.sendingAgent', 'transfer.claimedBy', 'requestedBy', 'decidedBy'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when(! $request->filled('status'), fn ($q) => $q->orderByRaw("status = 'pending' desc"))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('transfers.cancellation-requests', [
            'requests' => $requests,
            'statuses' => collect(CancellationRequestStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all(),
        ]);
    }

    public function approve(Request $request, CancellationRequest $cancellationRequest): RedirectResponse
    {
        $this->authorize('approveCancel', Transfer::class);

        try {
            $this->cancellations->approveCancellation($cancellationRequest, $request->user());
        } catch (TransferStateException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Cancellation approved — the transfer was reversed.'));
    }

    public function reject(Request $request, CancellationRequest $cancellationRequest): RedirectResponse
    {
        $this->authorize('approveCancel', Transfer::class);

        try {
            $this->cancellations->rejectCancellation($cancellationRequest, $request->user());
        } catch (TransferStateException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Cancellation rejected — the transfer stands.'));
    }
}
