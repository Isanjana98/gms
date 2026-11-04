@extends('site.layout')

@section('content')
    <article>
        <section class="hero hero--compact">
            @if ($post->image_url)
                <div class="hero__media" aria-hidden="true">
                    <img src="{{ $post->image_url }}" alt="" class="is-active" fetchpriority="high">
                </div>
            @endif

            <div class="wrap hero__inner">
                <span class="eyebrow" data-reveal>{{ optional($post->published_at)->format('d F Y') }}</span>
                <h1 data-reveal style="--i:1">{{ $post->title }}</h1>
            </div>
        </section>

        <section class="section">
            <div class="wrap">
                <div class="prose" data-reveal>
                    {{-- Escaped, with paragraph breaks preserved.
                         The body is written in the admin panel; rendering it as
                         raw HTML would make the post editor a way to put script
                         on the gym's own domain, which is the same origin the
                         staff session cookie belongs to. --}}
                    @foreach (preg_split('/\R{2,}/', (string) $post->body) as $paragraph)
                        @if (trim($paragraph) !== '')
                            <p>{!! nl2br(e(trim($paragraph))) !!}</p>
                        @endif
                    @endforeach
                </div>

                <p style="margin-top:3rem">
                    <a class="btn btn--ghost" href="{{ route('site.blog') }}">All news</a>
                </p>
            </div>
        </section>
    </article>

    @if ($more->isNotEmpty())
        <section class="section section--tint section--line">
            <div class="wrap">
                <div class="section__head">
                    <span class="eyebrow" data-reveal>Keep reading</span>
                    <h2 data-reveal style="--i:1">More from the gym</h2>
                </div>
                <div class="grid grid--3">
                    @include('site.partials.posts', ['posts' => $more])
                </div>
            </div>
        </section>
    @endif
@endsection
