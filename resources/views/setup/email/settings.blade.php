<x-layouts.app :title="__('Email')">
    @include('partials.email-tabs', ['active' => 'settings'])

    <div class="max-w-2xl space-y-6">
        @if ($sentToday >= $dailyWarning)
            <x-alert type="warning" title="{{ __('Close to the daily limit') }}">
                {{ __(':count emails went out in the last 24 hours. A normal Gmail account stops at about 500 a day (Google Workspace allows 2,000). Failed emails can be retried from the Sent emails tab.', ['count' => $sentToday]) }}
            </x-alert>
        @endif

        <x-card :title="__('Company Gmail account')" :subtitle="__('The system sends every email from this account. Nothing is sent until it is switched on.')">
            <form method="POST" action="{{ route('setup.email.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                <x-checkbox name="email_enabled" label="{{ __('Send emails') }}" :checked="old('email_enabled', $enabled)" />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-input name="email_username" type="email" label="{{ __('Gmail address') }}" :value="$username" placeholder="company@gmail.com" autocomplete="off" />
                    <div>
                        <x-input name="email_password" type="password" label="{{ __('App password') }}" autocomplete="new-password"
                            placeholder="{{ $passwordSaved ? '••••••••••••••••' : '' }}"
                            hint="{{ $passwordSaved ? __('One is saved. Leave blank to keep it.') : __('The 16-letter password Google gives you (see below).') }}" />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-input name="email_from_name" label="{{ __('Sender name') }}" :value="$fromName" hint="{{ __('Blank uses the company name.') }}" />
                    <x-input name="email_reply_to" type="email" label="{{ __('Reply-to address') }}" :value="$replyTo" hint="{{ __('Optional — where replies should go.') }}" />
                </div>

                <details class="rounded-lg border border-slate-200 px-4 py-3">
                    <summary class="cursor-pointer text-sm font-medium text-slate-700">{{ __('Mail server (leave as it is for Gmail)') }}</summary>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-4">
                        <x-input name="email_host" label="{{ __('Host') }}" :value="$host" />
                        <x-input name="email_port" type="number" label="{{ __('Port') }}" :value="$port" hint="{{ __('587 for the usual connection, 465 for SSL.') }}" />
                    </div>
                </details>

                <div class="rounded-lg bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600 space-y-1">
                    <p class="font-medium text-slate-800">{{ __('How to get an app password') }}</p>
                    <ol class="list-decimal ml-5 space-y-0.5">
                        <li>{{ __('Sign in to the company Gmail account and turn on 2-Step Verification (Google Account → Security).') }}</li>
                        <li>{{ __('Open Google Account → Security → App passwords, and create one named “Remittance system”.') }}</li>
                        <li>{{ __('Copy the 16 letters here. The spaces do not matter.') }}</li>
                    </ol>
                </div>

                <div class="flex justify-end">
                    <x-button type="submit">{{ __('Save') }}</x-button>
                </div>

                {{-- Posted to the test route with the same boxes, so it checks what is typed, saved or not. --}}
                <div class="pt-5 border-t border-slate-100">
                    <p class="text-sm font-medium text-slate-800 mb-3">{{ __('Send a test email') }}</p>
                    <div class="flex flex-col sm:flex-row sm:items-end gap-3">
                        <div class="flex-1">
                            <x-input name="test_to" type="email" label="{{ __('Send to') }}" :value="old('test_to', $testAddress)" />
                        </div>
                        <x-button type="submit" variant="secondary" formaction="{{ route('setup.email.test') }}" formnovalidate>{{ __('Send test email') }}</x-button>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">{{ __('Uses the address and password above even if they are not saved yet, and shows the exact error if Gmail refuses.') }}</p>
                </div>
            </form>
        </x-card>

        <p class="text-xs text-slate-500">
            {{ __(':count emails sent in the last 24 hours.', ['count' => $sentToday]) }}
            {{ __('The app password is stored encrypted; changing the server’s APP_KEY means entering it again.') }}
        </p>
    </div>
</x-layouts.app>
