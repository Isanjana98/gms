@extends('site.layout')

@section('content')
    @include('site.partials.banner', [
        'eyebrow' => 'The team',
        'title' => 'Our trainers',
        'lead' => 'The people who will show you how to use the place properly.',
        'image' => $banner,
    ])

    <section class="section">
        <div class="wrap">
            <div class="grid grid--{{ min(max($trainers->count(), 2), 4) }}">
                @include('site.partials.trainers', ['trainers' => $trainers])
            </div>
        </div>
    </section>

    <section class="section section--tint section--line">
        <div class="wrap">
            <div class="cta" data-reveal="scale">
                <h2>Want to train with one of them?</h2>
                <p>Tell us what you are working towards and we will match you with the right trainer.</p>
                <div class="cta__actions">
                    <a class="btn btn--primary" href="{{ route('site.contact') }}">Get in touch</a>
                </div>
            </div>
        </div>
    </section>
@endsection
