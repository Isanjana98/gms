@extends('site.layout')

@php
    $ogImage = $hero->first()?->url;
@endphp

@section('content')
    <section class="hero">
        @if ($hero->isNotEmpty())
            <div class="hero__media" aria-hidden="true">
                @foreach ($hero as $index => $image)
                    {{-- The first is eager and high priority: it is the largest
                         element on the page and the one the visitor waits for.
                         The rest only matter once the crossfade reaches them. --}}
                    <img
                        src="{{ $image->url }}"
                        alt=""
                        @if ($index === 0) fetchpriority="high" @else loading="lazy" @endif
                        style="animation-delay: {{ $index * -6 }}s"
                    >
                @endforeach
            </div>
        @endif

        <div class="wrap hero__inner">
            <span class="eyebrow" data-reveal>{{ $branding['tagline'] ?: 'Train with intent' }}</span>

            <h1 data-reveal style="--i:1">{!! $headline !!}</h1>

            <p class="hero__lead" data-reveal style="--i:2">{{ $copy['hero_subtext'] }}</p>

            <div class="hero__actions" data-reveal style="--i:3">
                <a class="btn btn--primary" href="{{ route('site.contact') }}">
                    {{ $copy['hero_cta_label'] }}
                </a>
                @if ($sections['pricing'])
                    <a class="btn btn--ghost" href="{{ route('site.pricing') }}">See membership</a>
                @endif
            </div>

            @if ($stats !== [])
                <div class="hero__stats" data-reveal style="--i:4">
                    @foreach ($stats as $stat)
                        <div class="stat">
                            <span class="stat__value" data-count="{{ $stat['value'] }}" data-suffix="{{ $stat['suffix'] ?? '' }}">0</span>
                            <span class="stat__label">{{ $stat['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- The scrolling band, borrowed from the lettering gyms put on their own
         walls. Pauses on hover, and holds still entirely under reduced motion. --}}
    <div class="marquee" aria-hidden="true">
        <div class="marquee__track">
            @foreach ([1, 2] as $pass)
                @foreach (['No pain no gain', 'Be stronger than your excuses', 'You vs you', 'Discipline today, success tomorrow', 'Never give up'] as $phrase)
                    <span>{{ $phrase }}</span>
                @endforeach
            @endforeach
        </div>
    </div>

    @if (count($facilities) > 0)
        <section class="section">
            <div class="wrap">
                <div class="section__head">
                    <span class="eyebrow" data-reveal>What we offer</span>
                    <h2 data-reveal style="--i:1">Everything you need<br>to train properly</h2>
                </div>

                <div class="grid grid--3">
                    @foreach (array_slice($facilities, 0, 6) as $index => $facility)
                        <div class="card" data-reveal>
                            <span class="card__index">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                            <h3>{{ $facility['title'] ?? '' }}</h3>
                            <p>{{ $facility['description'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($sections['pricing'])
        <section class="section section--tint section--line">
            <div class="wrap">
                <div class="section__head">
                    <span class="eyebrow" data-reveal>Membership</span>
                    <h2 data-reveal style="--i:1">Simple, honest pricing</h2>
                    <p data-reveal style="--i:2">No joining tricks and no hidden extras. What you see is what you pay.</p>
                </div>

                <div class="grid grid--3">
                    @include('site.partials.plans', ['plans' => $plans, 'featured' => $plans->count() >= 3 ? 1 : null])
                </div>

                @if ($sections['pricing'])
                    <p style="margin-top:2rem" data-reveal>
                        <a class="btn btn--ghost" href="{{ route('site.pricing') }}">Compare every plan</a>
                    </p>
                @endif
            </div>
        </section>
    @endif

    @if ($sections['gallery'] && $gallery->isNotEmpty())
        <section class="section">
            <div class="wrap">
                <div class="section__head">
                    <span class="eyebrow" data-reveal>Inside</span>
                    <h2 data-reveal style="--i:1">Have a look around</h2>
                </div>

                <div class="gallery">
                    @include('site.partials.gallery', ['gallery' => $gallery])
                </div>

                <p style="margin-top:2rem" data-reveal>
                    <a class="btn btn--ghost" href="{{ route('site.gallery') }}">See the full gallery</a>
                </p>
            </div>
        </section>
    @endif

    @if ($sections['trainers'])
        <section class="section section--tint section--line">
            <div class="wrap">
                <div class="section__head">
                    <span class="eyebrow" data-reveal>The team</span>
                    <h2 data-reveal style="--i:1">Trainers who know<br>what they are doing</h2>
                </div>

                {{-- Columns follow the number of people, so three trainers do
                     not sit in a four-column grid with a hole on the right. --}}
                <div class="grid grid--{{ min(max($trainers->count(), 2), 4) }}">
                    @include('site.partials.trainers', ['trainers' => $trainers])
                </div>
            </div>
        </section>
    @endif

    @if ($sections['testimonials'] && $testimonials->isNotEmpty())
        <section class="section">
            <div class="wrap">
                <div class="section__head">
                    <span class="eyebrow" data-reveal>Members</span>
                    <h2 data-reveal style="--i:1">What people say</h2>
                </div>

                <div class="grid grid--3">
                    @foreach ($testimonials as $testimonial)
                        <div class="quote" data-reveal>
                            @if ($testimonial->rating)
                                <div class="stars" aria-label="{{ $testimonial->rating }} out of 5">
                                    {{ str_repeat('★', $testimonial->rating) }}{{ str_repeat('☆', 5 - $testimonial->rating) }}
                                </div>
                            @endif
                            <blockquote>{{ $testimonial->quote }}</blockquote>
                            <div class="quote__who">
                                @if ($testimonial->photo_url)
                                    <img src="{{ $testimonial->photo_url }}" alt="" loading="lazy">
                                @else
                                    <span class="quote__initials">{{ \Illuminate\Support\Str::substr($testimonial->name, 0, 1) }}</span>
                                @endif
                                <div>
                                    <span class="quote__name">{{ $testimonial->name }}</span>
                                    @if ($testimonial->role)
                                        <span class="quote__role">{{ $testimonial->role }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($sections['blog'] && $posts->isNotEmpty())
        <section class="section section--tint section--line">
            <div class="wrap">
                <div class="section__head">
                    <span class="eyebrow" data-reveal>News</span>
                    <h2 data-reveal style="--i:1">From the gym</h2>
                </div>

                <div class="grid grid--3">
                    @include('site.partials.posts', ['posts' => $posts])
                </div>
            </div>
        </section>
    @endif

    <section class="section">
        <div class="wrap">
            <div class="cta" data-reveal="scale">
                <h2>Your first session<br>starts with a hello</h2>
                <p>{{ $copy['contact_note'] }}</p>
                <div class="cta__actions">
                    <a class="btn btn--primary" href="{{ route('site.contact') }}">Get in touch</a>
                    @if ($branch?->telephone)
                        <a class="btn btn--ghost" href="tel:{{ $branch->telephone }}">{{ $branch->telephone }}</a>
                    @endif
                </div>
            </div>
        </div>
    </section>
@endsection
