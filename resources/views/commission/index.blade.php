<x-layouts.app :title="__('Commission')">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <x-stat label="{{ __('Commission balance') }}" :value="\App\Support\Money::format($balanceCents)" icon="gift" tone="gold" class="lg:col-span-3" />

        <x-card :title="__('Move to send balance')" :subtitle="$moveRequiresApproval ? __('Requires admin approval.') : __('Instant.')">
            <form method="POST" action="{{ route('commission.move') }}" class="space-y-4">
                @csrf
                <x-input name="amount" label="{{ __('Amount ($)') }}" type="number" min="0.01" step="0.01" prefix="$" />
                <div class="flex justify-end">
                    <x-button type="submit">{{ __('Move') }}</x-button>
                </div>
            </form>
        </x-card>

        <x-card :title="__('Request withdrawal')" :subtitle="__('Paid out from a company account — needs approval.')">
            <form method="POST" action="{{ route('commission.withdraw') }}" class="space-y-4">
                @csrf
                <x-input name="amount" label="{{ __('Amount ($)') }}" type="number" min="0.01" step="0.01" prefix="$" />
                <x-select name="company_account_id" label="{{ __('Paid from') }}" :options="$companyAccounts" placeholder="{{ __('Select an account') }}" />
                <div class="flex justify-end">
                    <x-button type="submit" variant="secondary">{{ __('Request withdrawal') }}</x-button>
                </div>
            </form>
        </x-card>

    </div>

    <x-card :title="__('History')" :padding="false">
        @if ($history->isEmpty())
            <x-empty-state icon="gift" title="{{ __('No commission activity yet') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Date') }}</th>
                    <th class="px-4 py-3">{{ __('Kind') }}</th>
                    <th class="px-4 py-3">{{ __('Amount') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                </x-slot:head>

                @foreach ($history as $req)
                    <tr>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Date') }}">{{ $req->created_at->format('M j, Y') }}</td>
                        <td class="px-4 py-3 font-medium text-slate-900" data-label="{{ __('Kind') }}">{{ $req->kind->label() }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Amount') }}"><x-money :cents="$req->amount_cents" /></td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            <x-badge :color="$req->status->color()">{{ $req->status->label() }}</x-badge>
                        </td>
                    </tr>
                @endforeach
            </x-table>

            <div class="px-5">
                <x-pagination :paginator="$history" />
            </div>
        @endif
    </x-card>
</x-layouts.app>
