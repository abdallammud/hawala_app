<x-layouts.app :title="__('Cancellation Requests')">
    @error('status')
        <x-alert type="error">{{ $message }}</x-alert>
    @enderror

    <x-card :padding="false">
        <div class="p-5 border-b border-slate-100">
            <form method="GET" class="flex flex-col sm:flex-row gap-3">
                <x-select name="status" :options="$statuses" placeholder="{{ __('All (pending first)') }}" :value="request('status')" class="sm:w-56" />
                <x-button type="submit" variant="secondary">{{ __('Filter') }}</x-button>
                @if (request()->hasAny('status'))
                    <x-button :href="route('cancellation-requests.index')" variant="ghost">{{ __('Clear') }}</x-button>
                @endif
            </form>
        </div>

        @if ($requests->isEmpty())
            <x-empty-state icon="x-circle" title="{{ __('No cancellation requests') }}" description="{{ __('Sending agents can ask to cancel transfers a payout agent already claimed.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Transfer') }}</th>
                    <th class="px-4 py-3">{{ __('Requested by') }}</th>
                    <th class="px-4 py-3">{{ __('Reason') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3"></th>
                </x-slot:head>

                @foreach ($requests as $req)
                    <tr>
                        <td class="px-4 py-3" data-label="{{ __('Transfer') }}">
                            <a href="{{ route('transfers.show', $req->transfer) }}" class="font-medium text-slate-900 hover:text-brand-600">{{ $req->transfer->code }}</a>
                            <div class="text-xs text-slate-500 font-mono">{{ \App\Support\Money::format($req->transfer->amount_cents) }}</div>
                        </td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Requested by') }}">
                            {{ $req->requestedBy->name }}
                            <div class="text-xs">{{ $req->created_at->diffForHumans() }}</div>
                        </td>
                        <td class="px-4 py-3 text-slate-600 text-sm" data-label="{{ __('Reason') }}">{{ $req->reason }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            <x-badge :color="$req->status->color()">{{ $req->status->label() }}</x-badge>
                        </td>
                        <td class="px-4 py-3 text-right space-x-3" data-label="">
                            @if ($req->status === \App\Enums\CancellationRequestStatus::Pending && auth()->id() !== $req->requested_by)
                                <form method="POST" action="{{ route('cancellation-requests.approve', $req) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-brand-600 hover:text-brand-700 font-medium text-sm">{{ __('Approve') }}</button>
                                </form>
                                <form method="POST" action="{{ route('cancellation-requests.reject', $req) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-red-600 hover:text-red-700 font-medium text-sm">{{ __('Reject') }}</button>
                                </form>
                            @elseif ($req->decidedBy)
                                <span class="text-xs text-slate-400">{{ $req->decided_at?->format('M j, Y') }} — {{ $req->decidedBy->name }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-table>

            <div class="px-5">
                <x-pagination :paginator="$requests" />
            </div>
        @endif
    </x-card>
</x-layouts.app>
