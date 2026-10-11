@php $active = $active ?? 'templates'; @endphp

<div class="border-b border-slate-200 mb-6">
    <nav class="-mb-px flex gap-6 overflow-x-auto">
        @foreach ([
            ['key' => 'settings', 'can' => 'setup.email_settings', 'route' => 'setup.email.edit', 'label' => __('Connection')],
            ['key' => 'templates', 'can' => 'setup.email', 'route' => 'setup.email-templates.index', 'label' => __('Templates')],
            ['key' => 'log', 'can' => 'setup.email', 'route' => 'setup.email-log.index', 'label' => __('Sent emails')],
        ] as $tab)
            @can($tab['can'])
                <a
                    href="{{ route($tab['route']) }}"
                    class="border-b-2 px-1 py-3 text-sm font-medium whitespace-nowrap {{ $active === $tab['key'] ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }}"
                >
                    {{ $tab['label'] }}
                </a>
            @endcan
        @endforeach
    </nav>
</div>
