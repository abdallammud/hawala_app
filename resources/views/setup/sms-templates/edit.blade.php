@php
    $recipientLabels = [
        'to_sender' => __('Sender'),
        'to_receiver' => __('Receiver'),
        'to_sending_agent' => __('Sending agent'),
        'to_payout_agent' => __('Payout agent'),
    ];
    $available = $template->event->availableRecipients();
@endphp

<x-layouts.app :title="$template->event->label()">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">

        <div class="lg:col-span-2 space-y-6">
            <x-card :title="$template->event->label()" :subtitle="$template->event->description()">
                <form method="POST" action="{{ route('setup.sms-templates.update', $template) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <x-textarea
                        name="body"
                        label="{{ __('Message') }}"
                        :rows="4"
                        :value="old('body', $template->body)"
                        hint="{{ __('Keep it under 160 characters to stay a single SMS.') }}"
                    />

                    <div>
                        <p class="block text-sm font-medium text-slate-700 mb-2">{{ __('Send to') }}</p>
                        <div class="space-y-2">
                            @foreach ($recipientLabels as $field => $label)
                                @if (in_array($field, $available, true))
                                    <x-checkbox :name="$field" :label="$label" :checked="old($field, $template->$field)" />
                                @endif
                            @endforeach
                        </div>
                        @error('to_sender')
                            <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="pt-4 border-t border-slate-100">
                        <x-checkbox
                            name="is_active"
                            label="{{ __('Active — send this message when the event happens') }}"
                            :checked="old('is_active', $template->is_active)"
                        />
                    </div>

                    <div class="flex justify-end gap-3">
                        <x-button :href="route('setup.sms-templates.index')" variant="ghost">{{ __('Back') }}</x-button>
                        <x-button type="submit">{{ __('Save changes') }}</x-button>
                    </div>
                </form>
            </x-card>

            <x-card :title="__('Send a test')" :subtitle="__('Sends the preview below through the configured driver, without switching SMS on.')">
                <form method="POST" action="{{ route('setup.sms-templates.test', $template) }}" class="flex flex-col sm:flex-row sm:items-end gap-3">
                    @csrf
                    <div class="flex-1">
                        <x-input name="test_phone" label="{{ __('Phone number') }}" placeholder="+252612345678" :value="old('test_phone')" />
                    </div>
                    <x-button type="submit" variant="secondary">{{ __('Send test SMS') }}</x-button>
                </form>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card :title="__('Preview')" :subtitle="__('With sample data.')">
                <div class="rounded-lg bg-brand-50 border border-brand-200 p-4">
                    <p class="text-sm text-brand-900 whitespace-pre-wrap">{{ $preview }}</p>
                </div>
            </x-card>

            <x-card :title="__('Placeholders')" :subtitle="__('Anything the event cannot supply is left blank.')">
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($placeholders as $placeholder)
                        <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-mono text-slate-700">{{ $placeholder }}</code>
                    @endforeach
                </div>
            </x-card>
        </div>

    </div>
</x-layouts.app>
