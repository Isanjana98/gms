{{--
    Prices straight from the membership plans the gym actually sells, so the
    website cannot quote a price reception will not honour.

    $featured is the index to highlight; pass null for none.
--}}
@foreach ($plans as $index => $plan)
    @php $isFeatured = isset($featured) && $index === $featured; @endphp

    <div class="card price {{ $isFeatured ? 'price--featured' : '' }}" data-reveal>
        @if ($isFeatured)
            <span class="price__tag">Most popular</span>
        @endif

        <h3>{{ $plan->name }}</h3>

        <div>
            <span class="price__currency">{{ config('gms.currency', 'LKR') }}</span>
            <span class="price__amount">{{ number_format((float) $plan->price) }}</span>
            <span class="price__period">/ {{ $plan->duration_label ?? $plan->duration_value.' '.strtolower($plan->duration_unit) }}</span>
        </div>

        <ul>
            <li>Full access to the gym floor</li>
            @if ((float) $plan->registration_fee > 0)
                <li>One-off joining fee of {{ config('gms.currency', 'LKR') }} {{ number_format((float) $plan->registration_fee) }}</li>
            @else
                <li>No joining fee</li>
            @endif
            @if ($plan->grace_days > 0)
                <li>{{ $plan->grace_days }} days' grace on renewal</li>
            @endif
            @if (filled($plan->description))
                <li>{{ $plan->description }}</li>
            @endif
        </ul>

        {{-- Carries the plan through to the enquiry form, so reception knows
             what was asked about before they pick up the phone. --}}
        <a class="btn {{ $isFeatured ? 'btn--primary' : 'btn--ghost' }} btn--block"
           href="{{ route('site.contact', ['plan' => $plan->id]) }}">
            Enquire
        </a>
    </div>
@endforeach
