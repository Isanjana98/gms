@extends('site.layout')

@section('content')
    @include('site.partials.banner', [
        'eyebrow' => 'Say hello',
        'title' => 'Contact us',
        'lead' => $copy['contact_note'],
        'image' => $banner,
    ])

    <section class="section">
        <div class="wrap">
            <div class="grid grid--2" style="align-items:start">
                <div class="card" data-reveal>
                    @if (session('sent'))
                        <div class="notice notice--ok">
                            <strong>Thank you.</strong> We have your message and will be in touch shortly.
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="notice notice--error">
                            Please check the form below and try again.
                        </div>
                    @endif

                    <h2 style="font-size:1.25rem">Send us a message</h2>

                    <form method="POST" action="{{ route('site.enquire') }}" novalidate>
                        @csrf

                        {{-- The honeypot. A person never sees it; a bot filling
                             every field gives itself away. --}}
                        <div class="hp" aria-hidden="true">
                            <label for="website">Website</label>
                            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        <div class="field">
                            <label for="name">Your name</label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" required
                                   autocomplete="name">
                            @error('name')<p class="field__error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field">
                            <label for="telephone">Telephone</label>
                            <input type="tel" id="telephone" name="telephone" value="{{ old('telephone') }}"
                                   autocomplete="tel" inputmode="tel">
                            @error('telephone')<p class="field__error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field">
                            <label for="email">Email <span style="color:var(--ink-500);font-weight:400">(optional)</span></label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="email">
                            @error('email')<p class="field__error">{{ $message }}</p>@enderror
                        </div>

                        @if ($plans->isNotEmpty())
                            <div class="field">
                                <label for="membership_plan_id">Interested in</label>
                                <select id="membership_plan_id" name="membership_plan_id">
                                    <option value="">Not sure yet</option>
                                    @foreach ($plans as $plan)
                                        {{-- Pre-selected when they arrived from a
                                             pricing card, so reception knows what
                                             the conversation is about. --}}
                                        <option value="{{ $plan->id }}"
                                            @selected((int) old('membership_plan_id', request('plan')) === $plan->id)>
                                            {{ $plan->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        <div class="field">
                            <label for="message">Message</label>
                            <textarea id="message" name="message">{{ old('message') }}</textarea>
                            @error('message')<p class="field__error">{{ $message }}</p>@enderror
                        </div>

                        <button type="submit" class="btn btn--primary">Send message</button>

                        <p style="font-size:.8125rem;color:var(--ink-500);margin:1rem 0 0">
                            We use your details only to reply to this enquiry.
                        </p>
                    </form>
                </div>

                <div>
                    @foreach ($branches as $location)
                        <div class="card" style="margin-bottom:1.25rem" data-reveal>
                            <h3>{{ $location->name }}</h3>
                            <ul class="facts">
                                @if ($location->address)
                                    <li><span>Address</span><span style="text-align:right">{{ $location->address }}{{ $location->city ? ', '.$location->city : '' }}</span></li>
                                @endif
                                @if ($location->telephone)
                                    <li><span>Telephone</span><a href="tel:{{ $location->telephone }}">{{ $location->telephone }}</a></li>
                                @endif
                                @if ($location->email)
                                    <li><span>Email</span><a href="mailto:{{ $location->email }}">{{ $location->email }}</a></li>
                                @endif
                            </ul>
                        </div>
                    @endforeach

                    @if (($openingHours ?? []) !== [])
                        <div class="card" style="margin-bottom:1.25rem" data-reveal>
                            <h3>Opening hours</h3>
                            <ul class="facts">
                                @foreach ($openingHours as $row)
                                    <li><span>{{ $row['days'] ?? '' }}</span><span>{{ $row['hours'] ?? '' }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (filled($mapEmbed))
                        {{-- loading="lazy" because an embedded map is by far the
                             heaviest thing on this page and sits below the form
                             that visitors actually came to use. --}}
                        <iframe class="map" src="{{ $mapEmbed }}" loading="lazy" title="Map"
                                referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
                    @endif
                </div>
            </div>
        </div>
    </section>
@endsection
