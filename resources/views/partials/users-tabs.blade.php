@php $active = $active ?? 'users'; @endphp

<div class="border-b border-slate-200">
    <nav class="-mb-px flex gap-6">
        <a
            href="{{ route('users.index') }}"
            class="border-b-2 px-1 py-3 text-sm font-medium {{ $active === 'users' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }}"
        >
            {{ __('Users') }}
        </a>
        @can('setup.roles')
            <a
                href="{{ route('roles.index') }}"
                class="border-b-2 px-1 py-3 text-sm font-medium {{ $active === 'roles' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }}"
            >
                {{ __('Roles') }}
            </a>
        @endcan
    </nav>
</div>
