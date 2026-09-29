<x-layouts.app :title="__(':name — Statement', ['name' => $user->name])">
    <a href="{{ route('users.edit', $user) }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700 mb-2">
        <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
        {{ $user->name }}
    </a>

    <div class="border-b border-slate-200">
        <nav class="-mb-px flex gap-6 overflow-x-auto">
            @foreach ($types as $type)
                <a
                    href="{{ route('users.statement', [$user, 'type' => $type->value]) }}"
                    class="border-b-2 px-1 py-3 text-sm font-medium whitespace-nowrap {{ $active === $type ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }}"
                >
                    {{ $type->label() }}
                </a>
            @endforeach
        </nav>
    </div>

    @if ($statement)
        <x-statement-table :statement="$statement" :closing-balance="$closingBalance" />
    @else
        <x-card :padding="false">
            <x-empty-state icon="banknotes" title="{{ __('No activity yet') }}" description="{{ __('This balance has never been used.') }}" />
        </x-card>
    @endif
</x-layouts.app>
