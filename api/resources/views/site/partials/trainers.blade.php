{{--
    The team, live from the trainer records.

    Deliberately shows the name and specialisation only. A trainer's telephone
    number and email are staff contact details held for running the gym, not
    for publishing to the open internet.
--}}
@foreach ($trainers as $trainer)
    <article class="person" data-reveal>
        <div class="person__photo" aria-hidden="true">
            {{ \Illuminate\Support\Str::of($trainer->full_name)->explode(' ')->take(2)->map(fn ($p) => \Illuminate\Support\Str::substr($p, 0, 1))->implode('') }}
        </div>

        <div class="person__body">
            <h3 style="margin-bottom:.1rem">{{ $trainer->full_name }}</h3>
            @if ($trainer->specialization)
                <p class="person__role">{{ $trainer->specialization }}</p>
            @endif
        </div>
    </article>
@endforeach
