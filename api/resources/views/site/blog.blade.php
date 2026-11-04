@extends('site.layout')

@section('content')
    @include('site.partials.banner', [
        'eyebrow' => 'News',
        'title' => 'From the gym',
        'lead' => 'What is happening on the floor.',
        'image' => $banner,
    ])

    <section class="section">
        <div class="wrap">
            @if ($posts->isEmpty())
                <p>Nothing has been published yet.</p>
            @else
                <div class="grid grid--3">
                    @include('site.partials.posts', ['posts' => $posts])
                </div>

                <div style="margin-top:3rem">
                    {{ $posts->links() }}
                </div>
            @endif
        </div>
    </section>
@endsection
