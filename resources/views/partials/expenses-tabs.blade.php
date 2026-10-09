@php $active = $active ?? 'expenses'; @endphp

<div class="border-b border-slate-200">
    <nav class="-mb-px flex gap-6">
        <a
            href="{{ route('expenses.index') }}"
            class="border-b-2 px-1 py-3 text-sm font-medium {{ $active === 'expenses' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }}"
        >
            {{ __('Expenses') }}
        </a>
        @can('expenses.types')
            <a
                href="{{ route('expense-types.index') }}"
                class="border-b-2 px-1 py-3 text-sm font-medium {{ $active === 'types' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }}"
            >
                {{ __('Expense types') }}
            </a>
        @endcan
    </nav>
</div>
