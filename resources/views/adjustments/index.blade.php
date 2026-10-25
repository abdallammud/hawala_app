<x-layouts.app :title="__('Manual Adjustments')">
    <a href="{{ route('company-accounts.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700 mb-2">
        <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
        {{ __('Company Accounts') }}
    </a>

    @error('status')
        <x-alert type="error">{{ $message }}</x-alert>
    @enderror

    <x-card :padding="false">
        <x-slot:actions>
            @can('adjustments.create')
                <x-button :href="route('adjustments.create')" size="sm">
                    <x-icon name="plus-circle" class="w-4 h-4" />
                    {{ __('New adjustment') }}
                </x-button>
            @endcan
        </x-slot:actions>

        @if ($requests->isEmpty())
            <x-empty-state icon="banknotes" title="{{ __('No adjustments yet') }}" description="{{ __('Rare — used to correct the ledger, e.g. a bank fee no one recorded.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Date') }}</th>
                    <th class="px-4 py-3">{{ __('Debit') }}</th>
                    <th class="px-4 py-3">{{ __('Credit') }}</th>
                    <th class="px-4 py-3">{{ __('Amount') }}</th>
                    <th class="px-4 py-3">{{ __('Reason') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3"></th>
                </x-slot:head>

                @foreach ($requests as $req)
                    <tr>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Date') }}">{{ $req->created_at->toLocal()->format('M j, Y') }}</td>
                        <td class="px-4 py-3 text-slate-700" data-label="{{ __('Debit') }}">{{ $req->debitLedgerAccount->name }}</td>
                        <td class="px-4 py-3 text-slate-700" data-label="{{ __('Credit') }}">{{ $req->creditLedgerAccount->name }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Amount') }}"><x-money :cents="$req->amount_cents" /></td>
                        <td class="px-4 py-3 text-slate-500 text-sm" data-label="{{ __('Reason') }}">{{ $req->reason }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            <x-badge :color="$req->status->color()">{{ $req->status->label() }}</x-badge>
                        </td>
                        <td class="px-4 py-3 text-right" data-label="">
                            @if ($req->status->value === 'pending' && auth()->user()->can('adjustments.approve') && auth()->id() !== $req->requested_by)
                                <div class="flex flex-row flex-wrap items-center justify-end gap-4">
                                    <form method="POST" action="{{ route('adjustments.approve', $req) }}">
                                        @csrf
                                        <x-button type="submit" size="sm">
                                            <x-icon name="check-circle" class="w-4 h-4" />
                                            {{ __('Approve') }}
                                        </x-button>
                                    </form>
                                    <x-button type="button" size="sm" variant="danger" x-data x-on:click="$dispatch('open-modal', 'reject-{{ $req->id }}')">
                                        <x-icon name="x-circle" class="w-4 h-4" />
                                        {{ __('Reject') }}
                                    </x-button>
                                </div>
                                <x-modal name="reject-{{ $req->id }}" title="{{ __('Reject adjustment') }}">
                                    <form method="POST" action="{{ route('adjustments.reject', $req) }}" class="space-y-4 text-left">
                                        @csrf
                                        <x-textarea name="reason" label="{{ __('Reason') }}" />
                                        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                                            <x-button type="submit" variant="danger">
                                                <x-icon name="x-circle" class="w-4 h-4" />
                                                {{ __('Reject adjustment') }}
                                            </x-button>
                                        </div>
                                    </form>
                                </x-modal>
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
