@php $active = $active ?? 'mine'; @endphp

@can('balances.view_all')
    <div class="border-b border-slate-200">
        <nav class="-mb-px flex gap-6">
            <a
                href="{{ route('balance-requests.index') }}"
                class="border-b-2 px-1 py-3 text-sm font-medium {{ $active === 'mine' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }}"
            >
                {{ __('My Requests') }}
            </a>
            <a
                href="{{ route('balance-requests.inbox') }}"
                class="border-b-2 px-1 py-3 text-sm font-medium {{ $active === 'inbox' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }}"
            >
                {{ __('Inbox') }}
            </a>
        </nav>
    </div>
@endcan
