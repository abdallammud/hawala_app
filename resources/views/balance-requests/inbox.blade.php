<x-layouts.app :title="__('Balance Requests Inbox')">
    @include('partials.balance-requests-tabs', ['active' => 'inbox'])

    @error('status')
        <x-alert type="error">{{ $message }}</x-alert>
    @enderror

    <x-card :padding="false">
        <div class="p-5 border-b border-slate-100">
            <form method="GET" class="flex flex-col sm:flex-row gap-3">
                <x-select name="status" :options="['pending' => __('Pending'), 'approved' => __('Approved'), 'rejected' => __('Rejected')]" placeholder="{{ __('All statuses (pending first)') }}" :value="request('status')" class="sm:w-56" />
                <x-button type="submit" variant="secondary">{{ __('Filter') }}</x-button>
                @if (request()->hasAny('status'))
                    <x-button :href="route('balance-requests.inbox')" variant="ghost">{{ __('Clear') }}</x-button>
                @endif
            </form>
        </div>

        @if ($requests->isEmpty())
            <x-empty-state icon="banknotes" title="{{ __('Nothing here') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Date') }}</th>
                    <th class="px-4 py-3">{{ __('Beneficiary') }}</th>
                    <th class="px-4 py-3">{{ __('Kind') }}</th>
                    <th class="px-4 py-3">{{ __('Amount') }}</th>
                    <th class="px-4 py-3">{{ __('Requested by') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3"></th>
                </x-slot:head>

                @foreach ($requests as $req)
                    <tr>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Date') }}">{{ $req->created_at->format('M j, Y') }}</td>
                        <td class="px-4 py-3 font-medium text-slate-900" data-label="{{ __('Beneficiary') }}">{{ $req->user->name }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Kind') }}">{{ $req->kind->label() }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Amount') }}"><x-money :cents="$req->amount_cents" /></td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Requested by') }}">{{ $req->requestedBy->name }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            <x-badge :color="$req->status->color()">{{ $req->status->label() }}</x-badge>
                        </td>
                        <td class="px-4 py-3 text-right space-x-3" data-label="">
                            @if ($req->status->value === 'pending' && auth()->user()->can($req->kind->approvePermission()) && auth()->id() !== $req->requested_by)
                                @if ($req->kind->value === 'topup_paid')
                                    <button type="button" x-data x-on:click="$dispatch('open-modal', 'approve-{{ $req->id }}')" class="text-brand-600 hover:text-brand-700 font-medium text-sm">
                                        {{ __('Approve') }}
                                    </button>
                                    <x-modal name="approve-{{ $req->id }}" title="{{ __('Approve top-up') }}">
                                        <form method="POST" action="{{ route('balance-requests.approve', $req) }}" class="space-y-5">
                                            @csrf
                                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-100">
                                                <x-icon name="exclamation-triangle" class="w-7 h-7 text-amber-600" />
                                            </div>
                                            <div>
                                                <p class="text-base font-semibold text-slate-900">{{ __('Has the money arrived?') }}</p>
                                                <p class="mt-1.5 text-sm leading-relaxed text-slate-600">
                                                    {{ __('Approve only if :amount from :name is already in a company account.', ['amount' => \App\Support\Money::format($req->amount_cents), 'name' => $req->user->name]) }}
                                                    {{ __('If not, reject this and ask the agent to request credit instead.') }}
                                                </p>
                                            </div>
                                            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                                                <x-button type="button" variant="secondary" x-data x-on:click="$dispatch('close-modal', 'approve-{{ $req->id }}')">
                                                    {{ __('Cancel') }}
                                                </x-button>
                                                <x-button type="submit">
                                                    <x-icon name="check-circle" class="w-5 h-5" />
                                                    {{ __('Yes, approve') }}
                                                </x-button>
                                            </div>
                                        </form>
                                    </x-modal>
                                @else
                                    <form method="POST" action="{{ route('balance-requests.approve', $req) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-brand-600 hover:text-brand-700 font-medium text-sm">{{ __('Approve') }}</button>
                                    </form>
                                @endif
                                <button type="button" x-data x-on:click="$dispatch('open-modal', 'reject-{{ $req->id }}')" class="text-red-600 hover:text-red-700 font-medium text-sm">
                                    {{ __('Reject') }}
                                </button>

                                <x-modal name="reject-{{ $req->id }}" title="{{ __('Reject request') }}">
                                    <form method="POST" action="{{ route('balance-requests.reject', $req) }}" class="space-y-4">
                                        @csrf
                                        <x-textarea name="reason" label="{{ __('Reason') }}" />
                                        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                                            <x-button type="submit" variant="danger">{{ __('Reject request') }}</x-button>
                                        </div>
                                    </form>
                                </x-modal>
                            @else
                                <span class="text-slate-400 text-sm">
                                    @if ($req->decidedBy)
                                        {{ $req->decided_at?->format('M j, Y') }} — {{ $req->decidedBy->name }}
                                    @endif
                                </span>
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
