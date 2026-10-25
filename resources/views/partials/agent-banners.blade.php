{{-- Scrolling notices for sending agents. Pure CSS (the <marquee> tag is
     obsolete); pauses on hover and stands still for anyone who has asked
     their device for reduced motion. The second copy of each message only
     exists to make the loop seamless, so screen readers skip it. --}}
@php
    $bannerUser = auth()->user();
    $showBanners = $bannerUser && $bannerUser->is_active && $bannerUser->type->sends();
    $reminder = $showBanners && \App\Support\Settings::get('banners.transfer_reminder', true);
    $places = $showBanners && \App\Support\Settings::get('banners.new_destination', true)
        ? app(\App\Services\Banners\DestinationAnnouncer::class)->placesToShow()
        : collect();
@endphp

@if ($reminder || $places->isNotEmpty())
    <div class="print:hidden">
        @if ($reminder)
            <div class="marquee bg-gold-50 border-b border-gold-200 text-gold-900" role="status">
                <div class="marquee-track">
                    @foreach ([false, true] as $copy)
                        <a href="{{ route('transfers.index') }}" @if ($copy) aria-hidden="true" tabindex="-1" @endif class="marquee-item flex items-center gap-2 py-2 text-sm font-medium hover:underline">
                            <x-icon name="bell" class="w-4 h-4 shrink-0 text-gold-600" />
                            {{ __('Reminder: please check your transfers and make sure they are all up to date.') }}
                            <span class="underline">{{ __('Open all transfers') }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($places->isNotEmpty())
            <div class="marquee bg-brand-50 border-b border-brand-200 text-brand-900" role="status">
                <div class="marquee-track">
                    @foreach ([false, true] as $copy)
                        <p class="marquee-item flex items-center gap-2 py-2 text-sm font-medium" @if ($copy) aria-hidden="true" @endif>
                            <x-icon name="bell" class="w-4 h-4 shrink-0 text-brand-500" />
                            {{ $places->map(fn ($place) => __('You can now send money to :place.', ['place' => $place]))->implode(' · ') }}
                        </p>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif
