@foreach ($posts as $post)
    <article class="card card--flush" data-reveal>
        @if ($post->image_url)
            <div class="post__media">
                <img class="post__cover" src="{{ $post->image_url }}" alt="" loading="lazy" decoding="async">
            </div>
        @endif

        <div class="card__body">
            <p class="post__meta">{{ optional($post->published_at)->format('d M Y') }}</p>
            <h3 style="margin:.55rem 0 .5rem">
                <a href="{{ route('site.post', $post->slug) }}">{{ $post->title }}</a>
            </h3>
            @if ($post->excerpt)
                <p>{{ $post->excerpt }}</p>
            @endif
        </div>
    </article>
@endforeach
