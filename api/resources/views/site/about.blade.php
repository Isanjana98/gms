@extends('site.layout')

@section('content')
    @include('site.partials.banner', [
        'eyebrow' => 'Who we are',
        'title' => $copy['about_title'],
        'image' => $banner,
    ])

    <section class="section">
        <div class="wrap">
            <div class="prose" data-reveal>
                {{-- Plain text with paragraph breaks preserved, not raw HTML.
                     This field is edited in the admin panel, and rendering it
                     unescaped would turn a content editor into a way to inject
                     script onto the gym's own domain. --}}
                @foreach (preg_split('/\R{2,}/', $copy['about_body']) as $paragraph)
                    @if (trim($paragraph) !== '')
                        <p>{{ trim($paragraph) }}</p>
                    @endif
                @endforeach
            </div>
        </div>
    </section>

    @if (count($facilities) > 0)
        <section class="section section--tint section--line">
            <div class="wrap">
                <div class="section__head">
                    <span class="eyebrow" data-reveal>Facilities</span>
                    <h2 data-reveal style="--i:1">What is here</h2>
                </div>

                <div class="grid grid--3">
                    @foreach ($facilities as $index => $facility)
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

    @if ($gallery->isNotEmpty())
        <section class="section">
            <div class="wrap">
                <div class="gallery">
                    @include('site.partials.gallery', ['gallery' => $gallery])
                </div>
            </div>
        </section>
    @endif

    <section class="section section--tint section--line">
        <div class="wrap">
            <div class="cta" data-reveal="scale">
                <h2>Come and see for yourself</h2>
                <p>{{ $copy['contact_note'] }}</p>
                <div class="cta__actions">
                    <a class="btn btn--primary" href="{{ route('site.contact') }}">Get in touch</a>
                </div>
            </div>
        </div>
    </section>
@endsection
