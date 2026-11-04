@extends('site.layout')

@section('content')
    @include('site.partials.banner', [
        'eyebrow' => 'Inside',
        'title' => 'The gym',
        'lead' => 'The floor, the equipment and the people who use it.',
        'image' => $banner,
    ])

    <section class="section">
        <div class="wrap">
            @if ($gallery->isEmpty())
                <p>There are no photographs here yet.</p>
            @else
                <div class="gallery">
                    @include('site.partials.gallery', ['gallery' => $gallery])
                </div>
            @endif
        </div>
    </section>
@endsection
