{{--
    The compact banner every page but the front one uses.

    Takes $title, optional $lead, and an optional $image so an inner page is
    not a flat block of colour. Kept as one partial so the pages cannot drift
    apart in spacing or type scale.
--}}
<section class="hero hero--compact">
    @if (($image ?? null))
        <div class="hero__media" aria-hidden="true">
            <img src="{{ $image->url }}" alt="" class="is-active" fetchpriority="high">
        </div>
    @endif

    <div class="wrap hero__inner">
        @if (($eyebrow ?? null))
            <span class="eyebrow" data-reveal>{{ $eyebrow }}</span>
        @endif

        <h1 data-reveal style="--i:1">{{ $title }}</h1>

        @if (($lead ?? null))
            <p class="hero__lead" data-reveal style="--i:2">{{ $lead }}</p>
        @endif
    </div>
</section>
