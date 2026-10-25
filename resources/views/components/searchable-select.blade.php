{{--
    `dependsOn` makes this a child of another searchable-select: pass that field's
    name, a `byParent` map of parent value => options, and the parent's current
    value. The options are then only the ones for the chosen parent, and a value
    that doesn't belong to it is cleared.
--}}
@props(['label' => null, 'name', 'options' => [], 'hint' => null, 'placeholder' => null, 'value' => null,
        'dependsOn' => null, 'byParent' => [], 'parentValue' => null])

@php
$hasError = $errors->has($name);
$selected = (string) old($name, $value);
$map = collect($byParent)->map(fn ($list) => collect($list)->map(fn ($o) => (string) $o)->values()->all())->all();
$parent = $dependsOn ? (string) old($dependsOn, $parentValue) : '';
$list = $dependsOn ? ($map[$parent] ?? []) : collect($options)->map(fn ($o) => (string) $o)->values()->all();
@endphp

<div
    class="relative"
    x-data="{
        options: @js($list),
        value: @js($selected),
        parentName: @js($dependsOn),
        byParent: @js($map),
        parent: @js($parent),
        query: '',
        open: false,
        active: 0,
        init() {
            if (this.parentName) { this.options = this.optionsFor(this.parent); this.value = this.canonical(this.value); }
            else if (this.value && !this.options.includes(this.value)) this.options.unshift(this.value);
            this.query = this.value;
        },
        // The listed spelling of v, or '' when this field has no such option.
        canonical(v) { return this.options.find(o => o.toLowerCase() === (v ?? '').toLowerCase()) ?? ''; },
        announce() { this.$dispatch('select-changed', { name: @js($name), value: this.value }); },
        optionsFor(p) {
            const key = Object.keys(this.byParent).find(k => k.toLowerCase() === (p ?? '').toLowerCase());
            return key === undefined ? [] : [...this.byParent[key]];
        },
        parentChanged(d) {
            if (d.name !== this.parentName) return;
            this.parent = d.value;
            this.options = this.optionsFor(d.value);
            this.setValue(this.canonical(this.value));
        },
        setValue(v) {
            const changed = v !== this.value;
            this.value = v; this.query = v;
            if (changed) this.announce();
        },
        get placeholderText() {
            if (!this.parentName || this.options.length) return @js($placeholder ?? __('Type to search'));
            return this.parent ? @js(__('No cities set up for this country yet')) : @js(__('Select a country first'));
        },
        get filtered() {
            const q = this.query.trim().toLowerCase();
            if (q === '' || q === this.value.toLowerCase()) return this.options;
            return this.options.filter(o => o.toLowerCase().includes(q));
        },
        show() { this.open = true; this.active = Math.max(0, this.filtered.indexOf(this.value)); this.$refs.box.select(); },
        choose(o) { this.open = false; this.setValue(o); },
        // Called when another script (e.g. picking a saved customer) sets the hidden input.
        external() {
            let v = this.$refs.hidden.value;
            if (this.parentName) v = this.canonical(v);
            else if (v && !this.options.includes(v)) this.options.unshift(v);
            this.setValue(v);
        },
        close() {
            this.open = false;
            const match = this.options.find(o => o.toLowerCase() === this.query.trim().toLowerCase());
            this.choose(match ?? this.value);
        },
        move(d) {
            if (!this.open) { this.show(); return; }
            const n = this.filtered.length;
            if (n) { this.active = (this.active + d + n) % n; this.$nextTick(() => this.$refs.list.children[this.active]?.scrollIntoView({ block: 'nearest' })); }
        },
        pick() { if (this.open && this.filtered[this.active] !== undefined) this.choose(this.filtered[this.active]); },
    }"
    x-on:click.outside="close()"
    @if ($dependsOn)
        x-on:select-changed.window="parentChanged($event.detail)"
    @endif
>
    @if ($label)
        <label for="{{ $name }}_search" class="block text-sm font-medium text-slate-700 mb-1.5">{{ $label }}</label>
    @endif

    <input type="hidden" name="{{ $name }}" x-ref="hidden" :value="value" x-on:input="external()">

    <input
        id="{{ $name }}_search"
        type="text"
        role="combobox"
        autocomplete="off"
        x-ref="box"
        x-model="query"
        :placeholder="placeholderText"
        placeholder="{{ $placeholder ?? __('Type to search') }}"
        x-on:focus="show()"
        x-on:input="open = true; active = 0"
        x-on:keydown.arrow-down.prevent="move(1)"
        x-on:keydown.arrow-up.prevent="move(-1)"
        x-on:keydown.enter="if (open) { $event.preventDefault(); pick(); }"
        x-on:keydown.escape="close()"
        x-on:keydown.tab="close()"
        :aria-expanded="open"
        {{ $attributes->merge([
            'class' => 'block w-full rounded-lg border py-3 text-base sm:py-2.5 sm:text-sm pl-3.5 pr-10 shadow-sm transition
                focus:outline-none focus:ring-2 focus:ring-offset-0
                '.($hasError
                    ? 'border-red-400 text-red-900 focus:border-red-500 focus:ring-red-200'
                    : 'border-slate-300 text-slate-900 focus:border-brand-500 focus:ring-brand-100'),
        ]) }}
    />
    <svg class="pointer-events-none absolute right-3 bottom-3.5 sm:bottom-3 h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>

    <ul x-show="open" x-cloak x-ref="list" role="listbox" class="absolute z-30 mt-1 w-full max-h-60 overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
        <template x-for="(o, i) in filtered" :key="o">
            <li
                role="option"
                x-text="o"
                x-on:mousedown.prevent="choose(o)"
                x-on:mouseenter="active = i"
                :aria-selected="o === value"
                :class="[i === active ? 'bg-brand-50 text-brand-900' : 'text-slate-800', o === value ? 'font-semibold' : '']"
                class="cursor-pointer px-3.5 py-2.5 text-sm"
            ></li>
        </template>
        <li x-show="filtered.length === 0" class="px-3.5 py-2.5 text-sm text-slate-500">{{ __('No match') }}</li>
    </ul>

    @if ($hint && !$hasError)
        <p class="mt-1.5 text-xs text-slate-500">{{ $hint }}</p>
    @endif

    @error($name)
        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
