@php $current = $current ?? null; @endphp

<div>
    <label for="attachment" class="block text-sm font-medium text-slate-700 mb-1.5">{{ __('Attachment') }} <span class="font-normal text-slate-400">({{ __('optional') }})</span></label>
    <input id="attachment" name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
        class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100">
    <p class="mt-1.5 text-xs text-slate-500">
        {{ __('A photo or scan of the receipt or invoice. PDF, JPG or PNG, up to 5 MB.') }}
        @if ($current)
            {{ __('Choosing a file replaces the current one (:name).', ['name' => $current]) }}
        @endif
    </p>
    @error('attachment')
        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
