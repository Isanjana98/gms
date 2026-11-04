{{--
    Gallery tiles.

    A <button> rather than a <div> with a click handler, so the lightbox opens
    from the keyboard and a screen reader announces it as something operable.
    The caption doubles as the alt text: it is the only description anyone has
    written, and an empty alt on a content image tells a screen reader nothing.
--}}
@foreach ($gallery as $image)
    <button type="button" data-lightbox data-reveal="scale" aria-label="View: {{ $image->caption ?: 'photograph' }}">
        <img src="{{ $image->url }}" alt="{{ $image->caption }}" loading="lazy" decoding="async">
    </button>
@endforeach
