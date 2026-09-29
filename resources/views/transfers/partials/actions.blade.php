@php
    $canApproveLarge = $transfer->status === \App\Enums\TransferStatus::AwaitingApproval
        && auth()->user()->can('approveLarge', \App\Models\Transfer::class);
    $canCancel = auth()->user()->can('cancel', $transfer);
    $canRequestCancel = auth()->user()->can('requestCancel', $transfer);
    $pendingCancellation = $transfer->cancellationRequests
        ->firstWhere('status', \App\Enums\CancellationRequestStatus::Pending);
@endphp

@if ($canApproveLarge || $canCancel || $canRequestCancel || $pendingCancellation)
    <x-card :title="__('Actions')">
        @if ($pendingCancellation)
            <x-alert type="warning" class="mb-4">
                {{ __('Cancellation requested by :who — awaiting an admin decision.', ['who' => $pendingCancellation->requestedBy->name]) }}
            </x-alert>
        @endif

        <div class="space-y-3">
            @if ($canApproveLarge)
                <form method="POST" action="{{ route('transfers.approve-large', $transfer) }}">
                    @csrf
                    <x-button type="submit" class="w-full">{{ __('Approve and queue') }}</x-button>
                </form>

                <x-button type="button" variant="danger" class="w-full"
                    x-data x-on:click="$dispatch('open-modal', 'reject-large')">
                    {{ __('Reject transfer') }}
                </x-button>

                <x-modal name="reject-large" title="{{ __('Reject transfer') }}">
                    <form method="POST" action="{{ route('transfers.reject-large', $transfer) }}" class="space-y-4">
                        @csrf
                        <x-alert type="info">{{ __('The agent is refunded in full, including the fee.') }}</x-alert>
                        <x-textarea name="reason" label="{{ __('Reason') }}" />
                        <div class="flex justify-end">
                            <x-button type="submit" variant="danger">{{ __('Reject transfer') }}</x-button>
                        </div>
                    </form>
                </x-modal>
            @endif

            @if ($canCancel)
                <x-button type="button" variant="danger" class="w-full"
                    x-data x-on:click="$dispatch('open-modal', 'cancel-transfer')">
                    {{ __('Cancel transfer') }}
                </x-button>

                <x-modal name="cancel-transfer" title="{{ __('Cancel transfer') }}">
                    <form method="POST" action="{{ route('transfers.cancel', $transfer) }}" class="space-y-4">
                        @csrf
                        <x-alert type="warning">
                            @if (\App\Support\Settings::get('fees.refund_on_cancel', true))
                                {{ __('The full amount and fee go back to the sending agent.') }}
                            @else
                                {{ __('The amount goes back to the sending agent, but the company keeps the fee.') }}
                            @endif
                        </x-alert>
                        <x-textarea name="reason" label="{{ __('Reason') }}" />
                        <div class="flex justify-end">
                            <x-button type="submit" variant="danger">{{ __('Cancel transfer') }}</x-button>
                        </div>
                    </form>
                </x-modal>
            @endif

            @if ($canRequestCancel && ! $pendingCancellation)
                <x-button type="button" variant="secondary" class="w-full"
                    x-data x-on:click="$dispatch('open-modal', 'request-cancel')">
                    {{ __('Ask admin to cancel') }}
                </x-button>

                <x-modal name="request-cancel" title="{{ __('Request cancellation') }}">
                    <form method="POST" action="{{ route('transfers.request-cancel', $transfer) }}" class="space-y-4">
                        @csrf
                        <x-alert type="info">{{ __('A payout agent already claimed this, so an admin has to approve the cancellation.') }}</x-alert>
                        <x-textarea name="reason" label="{{ __('Reason') }}" />
                        <div class="flex justify-end">
                            <x-button type="submit">{{ __('Send request') }}</x-button>
                        </div>
                    </form>
                </x-modal>
            @endif
        </div>
    </x-card>
@endif
