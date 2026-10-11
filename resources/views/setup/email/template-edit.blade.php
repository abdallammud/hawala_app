<x-layouts.app :title="$template->event->label()">
    @include('partials.email-tabs', ['active' => 'templates'])

    <div
        class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start"
        x-data="{
            subject: @js(old('subject', $template->subject)),
            body: @js(old('body', $template->body)),
            preview: @js($preview),
            timer: null,
            last: 'body',
            refresh() {
                clearTimeout(this.timer);
                this.timer = setTimeout(async () => {
                    try {
                        const res = await fetch('{{ route('setup.email-templates.preview', $template) }}', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                            body: JSON.stringify({ subject: this.subject, body: this.body }),
                        });
                        if (res.ok) { this.preview = await res.json(); }
                    } catch (e) {}
                }, 300);
            },
            insert(token) {
                const el = this.$refs[this.last];
                const start = el.selectionStart ?? el.value.length;
                const end = el.selectionEnd ?? el.value.length;
                el.value = el.value.slice(0, start) + token + el.value.slice(end);
                this[this.last] = el.value;
                el.focus();
                el.setSelectionRange(start + token.length, start + token.length);
                this.refresh();
            },
        }"
    >
        <div class="lg:col-span-2 space-y-6">
            <x-card :title="$template->event->label()" :subtitle="$template->event->description()">
                <form method="POST" action="{{ route('setup.email-templates.update', $template) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div>
                        <label for="subject" class="block text-sm font-medium text-slate-700 mb-1.5">{{ __('Subject') }}</label>
                        <input id="subject" name="subject" type="text" x-ref="subject" x-model="subject" x-on:input="refresh()" x-on:focus="last = 'subject'"
                            class="block w-full rounded-lg border border-slate-300 py-3 text-base sm:py-2.5 sm:text-sm px-3.5 shadow-sm focus:outline-none focus:ring-2 focus:border-brand-500 focus:ring-brand-100">
                        @error('subject')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="body" class="block text-sm font-medium text-slate-700 mb-1.5">{{ __('Message') }}</label>
                        <textarea id="body" name="body" rows="14" x-ref="body" x-model="body" x-on:input="refresh()" x-on:focus="last = 'body'"
                            class="block w-full rounded-lg border border-slate-300 py-3 text-base sm:py-2.5 sm:text-sm px-3.5 shadow-sm font-mono focus:outline-none focus:ring-2 focus:border-brand-500 focus:ring-brand-100"></textarea>
                        @error('body')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
                        <p class="mt-1.5 text-xs text-slate-500">{{ __('Plain text. Blank lines make paragraphs; web addresses become links. The logo and footer are added automatically.') }}</p>
                    </div>

                    <div class="pt-4 border-t border-slate-100">
                        <x-checkbox name="is_active" label="{{ __('On — send this email when the event happens') }}" :checked="old('is_active', $template->is_active)" />
                    </div>

                    <div class="flex flex-col-reverse sm:flex-row sm:justify-between gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                        <x-button :href="route('setup.email-templates.index')" variant="ghost">{{ __('Back') }}</x-button>
                        <div class="flex flex-col-reverse sm:flex-row gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                            <x-button type="submit" variant="secondary" form="reset-template" x-on:click="if (! confirm('{{ __('Replace this wording with the original?') }}')) $event.preventDefault()">{{ __('Reset to original') }}</x-button>
                            <x-button type="submit">{{ __('Save changes') }}</x-button>
                        </div>
                    </div>
                </form>

                <form id="reset-template" method="POST" action="{{ route('setup.email-templates.reset', $template) }}" class="hidden">@csrf</form>
            </x-card>

            <x-card :title="__('Send a test')" :subtitle="__('Sends the saved version with sample data through the company Gmail account.')">
                <form method="POST" action="{{ route('setup.email-templates.test', $template) }}" class="flex flex-col sm:flex-row sm:items-end gap-3">
                    @csrf
                    <div class="flex-1">
                        <x-input name="test_to" type="email" label="{{ __('Send to') }}" :value="old('test_to', $testAddress)" />
                    </div>
                    <x-button type="submit" variant="secondary">{{ __('Send test email') }}</x-button>
                </form>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card :title="__('Preview')" :subtitle="__('With sample data, as you type.')">
                <template x-if="preview.unknown.length">
                    <x-alert type="warning"><span x-text="'{{ __('Unknown placeholder:') }} ' + preview.unknown.map(n => '{' + n + '}').join(', ')"></span></x-alert>
                </template>
                <p class="text-xs text-slate-500 mb-1">{{ __('Subject') }}</p>
                <p class="text-sm font-semibold text-slate-900 mb-4" x-text="preview.subject"></p>
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-800 leading-relaxed" x-html="preview.html"></div>
            </x-card>

            <x-card :title="__('Placeholders')" :subtitle="__('Click one to put it where the cursor is. Anything the event cannot supply is left blank.')">
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($placeholders as $placeholder)
                        <button type="button" x-on:click="insert('{{ '{'.$placeholder.'}' }}')"
                            class="rounded bg-slate-100 hover:bg-brand-50 hover:text-brand-700 px-1.5 py-0.5 text-xs font-mono text-slate-700">{{ '{'.$placeholder.'}' }}</button>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-slate-500">{{ __('Sent to: :who', ['who' => $template->event->recipients()]) }}</p>
            </x-card>
        </div>
    </div>
</x-layouts.app>
