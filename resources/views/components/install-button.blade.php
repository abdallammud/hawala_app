{{--
    "Install app" button. Stays hidden when the app is already installed or the
    browser cannot install it. On iPhone/iPad there is no install prompt, so the
    button opens short instructions instead.

    <x-install-button />                    compact, for the topbar
    <x-install-button variant="block" />    full width, for the login page
--}}
@props(['variant' => 'compact'])

<div x-data="installApp()" x-show="visible" x-cloak class="{{ $variant === 'block' ? 'mt-6' : '' }}">
    @if ($variant === 'block')
        <button
            type="button"
            x-on:click="install()"
            class="w-full inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-brand-700 hover:bg-slate-50 transition"
        >
            <x-icon name="arrow-down-tray" class="w-5 h-5" />
            {{ __('Install app') }}
        </button>
    @else
        <button
            type="button"
            x-on:click="install()"
            title="{{ __('Install app') }}"
            aria-label="{{ __('Install app') }}"
            class="flex items-center gap-1.5 p-2 sm:px-2.5 sm:py-1.5 text-xs font-medium text-brand-700 hover:bg-brand-50 rounded-lg"
        >
            <x-icon name="arrow-down-tray" class="w-5 h-5 sm:w-4 sm:h-4" />
            <span class="hidden sm:inline">{{ __('Install app') }}</span>
        </button>
    @endif

    <template x-teleport="body">
        <div x-show="help" x-cloak x-on:keydown.escape.window="help = false" class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center sm:p-4" style="display: none;">
            <div class="absolute inset-0 bg-slate-900/50" x-on:click="help = false"></div>
            <div class="relative w-full sm:max-w-sm rounded-t-xl sm:rounded-xl bg-white p-5 shadow-xl text-left">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-900">{{ __('Install Bluestar') }}</h3>
                    <button type="button" x-on:click="help = false" aria-label="{{ __('Close') }}" class="-m-2 p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>
                <ol class="mt-4 space-y-3 text-sm text-slate-700 list-decimal list-inside">
                    <li>{{ __('Open this page in Safari.') }}</li>
                    <li>{{ __('Tap the Share button.') }}</li>
                    <li>{{ __('Choose “Add to Home Screen”.') }}</li>
                    <li>{{ __('Tap Add.') }}</li>
                </ol>
            </div>
        </div>
    </template>
</div>
