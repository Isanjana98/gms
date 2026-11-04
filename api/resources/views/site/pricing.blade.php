@extends('site.layout')

@section('content')
    @include('site.partials.banner', [
        'eyebrow' => 'Membership',
        'title' => 'Pick your plan',
        'lead' => 'Every plan gives full access to the gym floor. Choose the length that suits how you train.',
        'image' => $banner,
    ])

    <section class="section">
        <div class="wrap">
            <div class="grid grid--3">
                {{-- Highlighted when there is a middle option to highlight.
                     Marking the only plan "most popular" reads as a joke. --}}
                @include('site.partials.plans', [
                    'plans' => $plans,
                    'featured' => $plans->count() >= 3 ? 1 : null,
                ])
            </div>

            <p style="margin-top:2.5rem;color:var(--dim);font-size:.9rem" data-reveal>
                Prices shown in {{ config('gms.currency', 'LKR') }}. Ask at reception about student and corporate rates.
            </p>
        </div>
    </section>

    <section class="section section--tint section--line">
        <div class="wrap">
            <div class="section__head">
                <span class="eyebrow" data-reveal>Before you ask</span>
                <h2 data-reveal style="--i:1">Questions people ask</h2>
            </div>

            <div class="grid grid--2">
                <div class="card" data-reveal>
                    <h3>Can I look around first?</h3>
                    <p>Yes. Come in during opening hours and someone will show you the floor — no appointment needed.</p>
                </div>
                <div class="card" data-reveal>
                    <h3>How do I renew?</h3>
                    <p>Renew at reception any time before your membership ends. Renewing early does not cost you the days you have already paid for.</p>
                </div>
                <div class="card" data-reveal>
                    <h3>How do I get in?</h3>
                    <p>Members check in with a fingerprint at the front desk, so there is no card to lose.</p>
                </div>
                <div class="card" data-reveal>
                    <h3>Do you do personal training?</h3>
                    <p>Our trainers write programmes for members. Ask at reception, or send a message and we will call you back.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="wrap">
            <div class="cta" data-reveal="scale">
                <h2>Ready to start?</h2>
                <p>Send us a message and we will call you back with everything you need to know.</p>
                <div class="cta__actions">
                    <a class="btn btn--primary" href="{{ route('site.contact') }}">Enquire now</a>
                </div>
            </div>
        </div>
    </section>
@endsection
