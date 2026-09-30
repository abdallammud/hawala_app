<x-layouts.app :title="__('SMS Templates')">

    @unless (\App\Support\Settings::get('sms.enabled', false))
        <x-alert type="info">
            {{ __('SMS is switched off, so nothing is sent yet. Templates can still be written and tested. Turn it on in Settings → SMS.') }}
        </x-alert>
    @endunless

    <x-card :title="__('One template per event')" :padding="false">
        <x-slot:actions>
            <x-button :href="route('setup.index')" variant="ghost" size="sm">{{ __('Back') }}</x-button>
        </x-slot:actions>

        <x-table>
            <x-slot:head>
                <th class="px-4 py-3">{{ __('Event') }}</th>
                <th class="px-4 py-3">{{ __('Message') }}</th>
                <th class="px-4 py-3">{{ __('Recipients') }}</th>
                <th class="px-4 py-3">{{ __('Status') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Action') }}</th>
            </x-slot:head>

            @foreach ($events as $event)
                @php $template = $templates->get($event->value); @endphp
                <tr>
                    <td class="px-4 py-3" data-label="{{ __('Event') }}">
                        <p class="font-medium text-slate-900">{{ $event->label() }}</p>
                        <p class="text-xs text-slate-500">{{ $event->description() }}</p>
                    </td>
                    <td class="px-4 py-3 text-slate-600 max-w-md" data-label="{{ __('Message') }}">
                        <span class="text-sm">{{ $template ? \Illuminate\Support\Str::limit($template->body, 90) : '—' }}</span>
                    </td>
                    <td class="px-4 py-3" data-label="{{ __('Recipients') }}">
                        @if ($template && $template->hasRecipients())
                            <div class="flex flex-wrap gap-1">
                                @if ($template->to_sender)<x-badge color="slate">{{ __('Sender') }}</x-badge>@endif
                                @if ($template->to_receiver)<x-badge color="slate">{{ __('Receiver') }}</x-badge>@endif
                                @if ($template->to_sending_agent)<x-badge color="slate">{{ __('Sending agent') }}</x-badge>@endif
                                @if ($template->to_payout_agent)<x-badge color="slate">{{ __('Payout agent') }}</x-badge>@endif
                            </div>
                        @else
                            <span class="text-slate-400 text-sm">{{ __('None') }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3" data-label="{{ __('Status') }}">
                        @if ($template?->is_active)
                            <x-badge color="green">{{ __('Active') }}</x-badge>
                        @else
                            <x-badge color="slate">{{ __('Off') }}</x-badge>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right" data-label="{{ __('Action') }}">
                        @if ($template)
                            <x-button :href="route('setup.sms-templates.edit', $template)" variant="secondary" size="sm">
                                {{ __('Edit') }}
                            </x-button>
                        @else
                            <span class="text-xs text-slate-400">{{ __('Not seeded') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</x-layouts.app>
