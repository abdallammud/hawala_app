<x-layouts.app :title="__('Email')">
    @include('partials.email-tabs', ['active' => 'templates'])

    @unless ($emailReady)
        <x-alert type="info">
            {{ __('Email is not switched on yet, so nothing is sent. Texts can still be written and tested. Set the Gmail account up under Connection.') }}
        </x-alert>
    @endunless

    <x-card :title="__('One email per event')" :subtitle="__('Change the wording here. Who receives each email is fixed.')" :padding="false">
        <x-table>
            <x-slot:head>
                <th class="px-4 py-3">{{ __('Event') }}</th>
                <th class="px-4 py-3">{{ __('Sent to') }}</th>
                <th class="px-4 py-3">{{ __('Subject') }}</th>
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
                    <td class="px-4 py-3 text-sm text-slate-600" data-label="{{ __('Sent to') }}">{{ $event->recipients() }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 max-w-xs" data-label="{{ __('Subject') }}">{{ $template ? \Illuminate\Support\Str::limit($template->subject, 70) : '—' }}</td>
                    <td class="px-4 py-3" data-label="{{ __('Status') }}">
                        @if ($template?->is_active)
                            <x-badge color="green">{{ __('On') }}</x-badge>
                        @else
                            <x-badge color="slate">{{ __('Off') }}</x-badge>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right" data-label="{{ __('Action') }}">
                        @if ($template)
                            <x-button :href="route('setup.email-templates.edit', $template)" variant="secondary" size="sm">{{ __('Edit') }}</x-button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</x-layouts.app>
