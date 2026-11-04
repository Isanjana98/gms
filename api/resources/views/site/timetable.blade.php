@extends('site.layout')

@section('content')
    @include('site.partials.banner', [
        'eyebrow' => 'Every week',
        'title' => 'Class timetable',
        'lead' => 'Turn up a few minutes early. Classes run whether or not you book — there is nothing to reserve.',
        'image' => $banner,
    ])

    <section class="section">
        <div class="wrap">
            @if ($timetable->isEmpty())
                <p>The timetable is being updated. Ask at reception for this week's classes.</p>
            @else
                @foreach (\App\Models\TimetableEntry::DAYS as $number => $name)
                    @php $classes = $timetable->get($number); @endphp

                    @if ($classes && $classes->isNotEmpty())
                        <div class="day" data-reveal>
                            <h3>{{ $name }}</h3>

                            @foreach ($classes as $entry)
                                <div class="slot">
                                    <span class="slot__time">{{ $entry->time_label }}</span>
                                    <span class="slot__name">{{ $entry->class_name }}</span>
                                    <span class="slot__meta">
                                        @if ($entry->instructor){{ $entry->instructor }}@endif
                                        @if ($entry->room) · {{ $entry->room }}@endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            @endif
        </div>
    </section>
@endsection
