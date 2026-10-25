<x-layouts.app :title="__('Balance Requests')">
    @include('partials.balance-requests-tabs', ['active' => 'mine'])

    <x-card :padding="false">
        <x-slot:actions>
            <x-button :href="route('balance-requests.create')" size="sm">
                <x-icon name="plus-circle" class="w-4 h-4" />
                {{ __('New request') }}
            </x-button>
        </x-slot:actions>

        @if ($requests->isEmpty())
            <x-empty-state icon="banknotes" title="{{ __('No requests yet') }}" description="{{ __('Top-ups, credit, payout funding — your requests will appear here.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Date') }}</th>
                    <th class="px-4 py-3">{{ __('Kind') }}</th>
                    <th class="px-4 py-3">{{ __('Amount') }}</th>
                    <th class="px-4 py-3">{{ __('Company account') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3">{{ __('Note') }}</th>
                </x-slot:head>

                @foreach ($requests as $req)
                    <tr>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Date') }}">{{ $req->created_at->toLocal()->format('M j, Y') }}</td>
                        <td class="px-4 py-3 font-medium text-slate-900" data-label="{{ __('Kind') }}">{{ $req->kind->label() }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Amount') }}"><x-money :cents="$req->amount_cents" /></td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Company account') }}">{{ $req->companyAccount?->name ?? '—' }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            <x-badge :color="$req->status->color()">{{ $req->status->label() }}</x-badge>
                            @if ($req->approvals_required > 1 && $req->status->value === 'pending')
                                <p class="mt-1 text-xs text-slate-500">{{ __(':given of :required approvals', ['given' => $req->approvals->count(), 'required' => $req->approvals_required]) }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-500 text-sm" data-label="{{ __('Note') }}">{{ $req->note ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-table>

            <div class="px-5">
                <x-pagination :paginator="$requests" />
            </div>
        @endif
    </x-card>
</x-layouts.app>
