<x-layouts.guest :title="__('Recovery codes')">
    <h1 class="text-xl font-semibold text-slate-900">{{ __('Save your recovery codes') }}</h1>
    <p class="mt-1.5 text-sm text-slate-500">
        {{ __('If you lose your phone, each of these codes lets you log in once. Write them down or print them and keep them somewhere safe. They will not be shown again.') }}
    </p>

    <div class="mt-6 grid grid-cols-2 gap-2 rounded-xl border border-slate-200 bg-slate-50 p-4 font-mono text-sm text-slate-900">
        @foreach ($codes as $code)
            <span class="select-all">{{ $code }}</span>
        @endforeach
    </div>

    <div class="mt-6 flex flex-col gap-3">
        <x-button type="button" variant="secondary" class="w-full" x-data x-on:click="window.print()">{{ __('Print') }}</x-button>
        <x-button :href="route('dashboard')" class="w-full" size="lg">{{ __('I have saved them — continue') }}</x-button>
    </div>
</x-layouts.guest>
