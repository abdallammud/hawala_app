@php
    $me = auth()->user();
    // Permission and ownership come from the policy; the state decides which button makes sense.
    $canDecide = $expense->isPending() && Gate::forUser($me)->allows('decide', $expense);
    $canReopen = ! $expense->isPending() && Gate::forUser($me)->allows('reopen', $expense);
    $canDelete = ! $expense->isApproved() && Gate::forUser($me)->allows('delete', $expense);
    $canEdit = Gate::forUser($me)->allows('update', $expense);
    $tz = \App\Support\Settings::get('general.timezone', 'Africa/Mogadishu');
    $when = fn ($d) => $d?->timezone($tz)->format('M j, Y H:i');
@endphp

<x-layouts.app :title="$expense->number">
    @include('partials.expenses-tabs', ['active' => 'expenses'])

    @error('status')
        <x-alert type="error">{{ $message }}</x-alert>
    @enderror

    <div class="max-w-2xl space-y-6">
        <x-card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <span class="font-mono">{{ $expense->number }}</span> · {{ $expense->type->name }}
                    </p>
                    <p class="mt-1 text-2xl font-bold text-slate-900 font-mono"><x-money :cents="$expense->amount_cents" /></p>
                    @if ($expense->original_currency !== 'USD')
                        <p class="text-sm text-slate-500">
                            {{ number_format($expense->original_amount_cents / 100, 2) }} {{ $expense->original_currency }}
                            @ {{ rtrim(rtrim((string) $expense->rate, '0'), '.') }}
                        </p>
                    @endif
                </div>
                <x-badge :color="$expense->status->color()">{{ $expense->status->label() }}</x-badge>
            </div>

            <dl class="mt-5 divide-y divide-slate-100 text-sm">
                @foreach ([
                    __('Date') => $expense->expense_date->format('Y-m-d'),
                    __('Paid to') => $expense->payee ?: '—',
                    __('Reference') => $expense->reference ?: '—',
                    __('Paid from') => $expense->companyAccount->name,
                    __('Branch') => $expense->branch?->name ?? '—',
                    __('Recorded by') => $expense->createdBy->name.' · '.$when($expense->created_at),
                ] as $label => $value)
                    <div class="flex justify-between gap-4 py-2.5">
                        <dt class="text-slate-500">{{ $label }}</dt>
                        <dd class="text-slate-900 text-right">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($expense->description)
                <div class="mt-5 pt-5 border-t border-slate-100">
                    <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">{{ __('Note') }}</p>
                    <p class="text-sm text-slate-700 mt-1 whitespace-pre-line">{{ $expense->description }}</p>
                </div>
            @endif

            {{-- Attachment --}}
            <div class="mt-5 pt-5 border-t border-slate-100">
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">{{ __('Attachment') }}</p>
                @if ($expense->hasAttachment())
                    @if ($expense->attachmentIsImage())
                        <a href="{{ route('expenses.attachment', $expense) }}" target="_blank" rel="noopener" class="mt-2 block">
                            <img src="{{ route('expenses.attachment', $expense) }}" alt="{{ $expense->attachment_name }}" class="max-h-64 rounded-lg border border-slate-200">
                        </a>
                    @endif
                    <a href="{{ route('expenses.attachment', $expense) }}" target="_blank" rel="noopener"
                       class="mt-2 inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 hover:text-brand-800">
                        <x-icon name="paper-clip" class="w-4 h-4" />
                        {{ $expense->attachment_name }}
                    </a>
                @else
                    <p class="text-sm text-slate-400 mt-1">{{ __('None attached.') }}</p>
                @endif
            </div>
        </x-card>

        {{-- Decision --}}
        <x-card :title="__('Approval')">
            @if ($expense->isPending())
                @if ($canDecide)
                    <p class="text-sm text-slate-600">{{ __('Approving pays :amount out of :account.', ['amount' => \App\Support\Money::format($expense->amount_cents), 'account' => $expense->companyAccount->name]) }}</p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <x-button type="button" x-data x-on:click="$dispatch('open-modal', 'approve-expense')">
                            <x-icon name="check-circle" class="w-4 h-4" /> {{ __('Approve') }}
                        </x-button>
                        <x-button type="button" variant="danger" x-data x-on:click="$dispatch('open-modal', 'reject-expense')">
                            <x-icon name="x-circle" class="w-4 h-4" /> {{ __('Reject') }}
                        </x-button>
                    </div>
                @elseif ($expense->isOwnedBy($me))
                    <x-alert type="info">{{ __('Waiting for approval. You recorded this expense, so someone else has to approve it.') }}</x-alert>
                @else
                    <p class="text-sm text-slate-600">{{ __('Waiting for approval by someone with permission to approve expenses.') }}</p>
                @endif
            @else
                <p class="text-sm text-slate-800">
                    <strong>{{ $expense->status->label() }}</strong>
                    {{ __('by :who on :date', ['who' => $expense->decidedBy?->name ?? __('the system'), 'date' => $when($expense->decided_at)]) }}
                </p>
                @if ($expense->decision_reason)
                    <p class="mt-2 text-sm text-slate-600 whitespace-pre-line">{{ __('Reason') }}: {{ $expense->decision_reason }}</p>
                @endif
                @if ($canReopen)
                    <div class="mt-4">
                        <x-button type="button" variant="secondary" x-data x-on:click="$dispatch('open-modal', 'reopen-expense')">
                            <x-icon name="arrow-uturn-left" class="w-4 h-4" /> {{ __('Return to pending') }}
                        </x-button>
                    </div>
                @endif
            @endif
        </x-card>

        {{-- History --}}
        <x-card :title="__('History')">
            <ol class="space-y-4 text-sm">
                @foreach ($history as $entry)
                    @php
                        $label = match ($entry->event) {
                            'created' => __('Recorded'),
                            'updated' => __('Edited'),
                            'approved' => __('Approved'),
                            'rejected' => __('Rejected'),
                            'returned' => __('Returned to pending'),
                            default => $entry->description,
                        };
                    @endphp
                    <li class="flex gap-3">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ match ($entry->event) { 'approved' => 'bg-emerald-500', 'rejected' => 'bg-red-500', 'returned' => 'bg-amber-500', default => 'bg-slate-300' } }}"></span>
                        <div>
                            <p class="text-slate-900"><span class="font-medium">{{ $label }}</span> {{ __('by :who', ['who' => $entry->causer?->name ?? __('the system')]) }}</p>
                            <p class="text-xs text-slate-500">{{ $when($entry->created_at) }}</p>
                            @if ($entry->properties->get('reason'))
                                <p class="mt-1 text-slate-600 whitespace-pre-line">{{ __('Reason') }}: {{ $entry->properties->get('reason') }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
                @if ($history->where('event', 'created')->isEmpty())
                    <li class="text-slate-500">{{ __('Recorded by :who on :date', ['who' => $expense->createdBy->name, 'date' => $when($expense->created_at)]) }}</li>
                @endif
            </ol>
        </x-card>

        <div class="flex flex-wrap gap-3">
            <x-button :href="route('expenses.index')" variant="ghost">{{ __('Back') }}</x-button>
            <x-button :href="route('expenses.print', $expense)" variant="secondary" target="_blank" rel="noopener">
                <x-icon name="printer" class="w-4 h-4" /> {{ __('Print voucher') }}
            </x-button>
            @if ($canEdit)
                <x-button :href="route('expenses.edit', $expense)" variant="secondary">{{ __('Edit') }}</x-button>
            @endif
            @if ($canDelete)
                <form method="POST" action="{{ route('expenses.destroy', $expense) }}"
                      x-data x-on:submit="if (! confirm('{{ __('Delete expense :number for good? This cannot be undone.', ['number' => $expense->number]) }}')) $event.preventDefault()">
                    @csrf
                    @method('DELETE')
                    <x-button type="submit" variant="danger"><x-icon name="trash" class="w-4 h-4" /> {{ __('Delete') }}</x-button>
                </form>
            @endif
        </div>
    </div>

    {{-- Reason is optional on every decision. --}}
    @if ($canDecide)
        <x-modal name="approve-expense" title="{{ __('Approve expense') }}">
            <form method="POST" action="{{ route('expenses.approve', $expense) }}" class="space-y-4">
                @csrf
                <x-alert type="info">{{ __(':amount will be paid out of :account now.', ['amount' => \App\Support\Money::format($expense->amount_cents), 'account' => $expense->companyAccount->name]) }}</x-alert>
                <x-textarea name="reason" label="{{ __('Reason (optional)') }}" />
                <div class="flex justify-end"><x-button type="submit">{{ __('Approve') }}</x-button></div>
            </form>
        </x-modal>

        <x-modal name="reject-expense" title="{{ __('Reject expense') }}">
            <form method="POST" action="{{ route('expenses.reject', $expense) }}" class="space-y-4">
                @csrf
                <x-alert type="warning">{{ __('Nothing is paid. A rejected expense is locked until someone returns it to pending.') }}</x-alert>
                <x-textarea name="reason" label="{{ __('Reason (optional)') }}" />
                <div class="flex justify-end"><x-button type="submit" variant="danger">{{ __('Reject') }}</x-button></div>
            </form>
        </x-modal>
    @endif

    @if ($canReopen)
        <x-modal name="reopen-expense" title="{{ __('Return to pending') }}">
            <form method="POST" action="{{ route('expenses.reopen', $expense) }}" class="space-y-4">
                @csrf
                @if ($expense->isApproved())
                    <x-alert type="warning">{{ __('The money goes back to :account, and the expense can be edited or deleted again.', ['account' => $expense->companyAccount->name]) }}</x-alert>
                @endif
                <x-textarea name="reason" label="{{ __('Reason (optional)') }}" />
                <div class="flex justify-end"><x-button type="submit">{{ __('Return to pending') }}</x-button></div>
            </form>
        </x-modal>
    @endif
</x-layouts.app>
