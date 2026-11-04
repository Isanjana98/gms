@php
    // Falls back through the page title, the configured SEO title, and finally
    // the gym's own name, so a page is never published as "Untitled".
    $pageTitle = trim($title ?? '');
    $siteName = $branding['gym_name'] ?? config('app.name');
    $fullTitle = $pageTitle ? "{$pageTitle} · {$siteName}" : ($seoTitle ?: $siteName);
    $description = $metaDescription ?? $seoDescription ?? ($branding['tagline'] ?? '');
@endphp
<!doctype html>
<html lang="en" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#08080a">

    {{--
        Set before first paint, and the reason the reveal animations are safe:
        their hidden state is scoped to `.js`, so if site.js fails to load the
        page renders fully visible instead of blank.
    --}}
    <script>document.documentElement.className = 'js'</script>

    <title>{{ $fullTitle }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags((string) $description), 160) }}">
    {{-- The canonical URL keeps /pricing and /pricing?utm_source=… from being
         indexed as two competing pages. --}}
    <link rel="canonical" href="{{ url()->current() }}">

    {{-- What appears when somebody shares the link on WhatsApp or Facebook,
         which for a gym is most of how the site gets seen at all. --}}
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $fullTitle }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit(strip_tags((string) $description), 160) }}">
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:url" content="{{ url()->current() }}">
    @isset($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
    @endisset
    <meta name="twitter:card" content="summary_large_image">

    @if ($branding['logo_url'] ?? null)
        <link rel="icon" href="{{ $branding['logo_url'] }}">
    @else
        {{-- A gym that has not uploaded a logo still needs *something* here,
             or every page load ends with a failed request for /favicon.ico.
             Drawn inline from the gym's initial rather than shipping a file. --}}
        <link rel="icon" href="data:image/svg+xml,{{ rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            .'<rect width="32" height="32" rx="7" fill="#3182f6"/>'
            .'<text x="16" y="22" font-family="system-ui,sans-serif" font-size="17" font-weight="700"'
            .' fill="#fff" text-anchor="middle">'.e(\Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($siteName, 0, 1))).'</text></svg>'
        ) }}">
    @endif

    <link rel="stylesheet" href="{{ asset('css/site.css') }}">
    {{-- Deferred: nothing here is needed before the page paints. --}}
    <script src="{{ asset('js/site.js') }}" defer></script>

    {{-- Tells Google this is a local business: opening hours, address and
         phone number can then show directly in search results. --}}
    @if ($branch ?? null)
        @php
            // Built here rather than inline in @json: that directive parses its
            // own argument and stops at the first closing bracket, which a
            // multi-line array literal breaks outright.
            $localBusiness = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'ExerciseGym',
                'name' => $siteName,
                'url' => url('/'),
                'telephone' => $branch->telephone,
                'email' => $branch->email,
                'image' => $branding['logo_url'] ?? null,
                'address' => array_filter([
                    '@type' => 'PostalAddress',
                    'streetAddress' => $branch->address,
                    'addressLocality' => $branch->city,
                    'addressCountry' => 'LK',
                ]),
            ]);

            // The HEX flags matter: without them a gym name containing "</script>"
            // would close this tag and everything after it would be parsed as
            // markup on our own origin.
            $localBusinessJson = json_encode(
                $localBusiness,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
            );
        @endphp

        <script type="application/ld+json">{!! $localBusinessJson !!}</script>
    @endif
</head>
<body>
    <header class="header">
        <div class="wrap header__inner">
            <a class="brand" href="{{ route('site.home') }}">
                @if ($branding['logo_url'] ?? null)
                    <img class="brand__mark" src="{{ $branding['logo_url'] }}" alt="">
                @else
                    <span class="brand__mark">{{ \Illuminate\Support\Str::substr($siteName, 0, 1) }}</span>
                @endif
                <span>{{ $siteName }}</span>
            </a>

            {{-- A checkbox rather than a script: the menu then works on a phone
                 even if JavaScript fails, which on a marketing page is the
                 difference between a visitor calling and leaving. --}}
            <input type="checkbox" id="nav-state" class="nav__state hp" aria-hidden="true">
            <label class="nav__toggle" for="nav-state" aria-label="Menu">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round"/>
                </svg>
            </label>

            <nav class="nav">
                <a href="{{ route('site.about') }}" @if (request()->routeIs('site.about')) aria-current="page" @endif>About</a>
                @if ($sections['pricing'])
                    <a href="{{ route('site.pricing') }}" @if (request()->routeIs('site.pricing')) aria-current="page" @endif>Membership</a>
                @endif
                @if ($sections['timetable'])
                    <a href="{{ route('site.timetable') }}" @if (request()->routeIs('site.timetable')) aria-current="page" @endif>Timetable</a>
                @endif
                @if ($sections['trainers'])
                    <a href="{{ route('site.trainers') }}" @if (request()->routeIs('site.trainers')) aria-current="page" @endif>Trainers</a>
                @endif
                @if ($sections['gallery'])
                    <a href="{{ route('site.gallery') }}" @if (request()->routeIs('site.gallery')) aria-current="page" @endif>Gallery</a>
                @endif
                @if ($sections['blog'])
                    <a href="{{ route('site.blog') }}" @if (request()->routeIs('site.blog', 'site.post')) aria-current="page" @endif>News</a>
                @endif
                <a class="btn btn--primary" href="{{ route('site.contact') }}">Join now</a>
            </nav>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    <footer class="footer">
        <div class="wrap">
            <div class="footer__grid">
                <div>
                    <h4>{{ $siteName }}</h4>
                    @if ($branding['tagline'] ?? null)
                        <p style="font-size:.9375rem">{{ $branding['tagline'] }}</p>
                    @endif
                    @if (($social ?? []) !== [])
                        <div class="social">
                            @foreach ($social as $network => $url)
                                @if (filled($url))
                                    <a href="{{ $url }}" rel="noopener noreferrer nofollow" target="_blank"
                                       title="{{ ucfirst($network) }}" aria-label="{{ ucfirst($network) }}">
                                        {{ strtoupper(substr($network, 0, 1)) }}
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>

                <div>
                    <h4>Visit</h4>
                    @if ($branch ?? null)
                        <p style="font-size:.9375rem">
                            @if ($branch->address){{ $branch->address }}<br>@endif
                            @if ($branch->city){{ $branch->city }}@endif
                        </p>
                        @if ($branch->telephone)
                            <p style="font-size:.9375rem"><a href="tel:{{ $branch->telephone }}">{{ $branch->telephone }}</a></p>
                        @endif
                        @if ($branch->email)
                            <p style="font-size:.9375rem"><a href="mailto:{{ $branch->email }}">{{ $branch->email }}</a></p>
                        @endif
                    @endif
                </div>

                @if (($openingHours ?? []) !== [])
                    <div>
                        <h4>Opening hours</h4>
                        <ul class="footer__links">
                            @foreach ($openingHours as $row)
                                <li>{{ $row['days'] ?? '' }} — {{ $row['hours'] ?? '' }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div>
                    <h4>Pages</h4>
                    <ul class="footer__links">
                        <li><a href="{{ route('site.about') }}">About</a></li>
                        @if ($sections['pricing'])<li><a href="{{ route('site.pricing') }}">Membership</a></li>@endif
                        @if ($sections['timetable'])<li><a href="{{ route('site.timetable') }}">Timetable</a></li>@endif
                        @if ($sections['trainers'])<li><a href="{{ route('site.trainers') }}">Trainers</a></li>@endif
                        @if ($sections['blog'])<li><a href="{{ route('site.blog') }}">News</a></li>@endif
                        <li><a href="{{ route('site.contact') }}">Contact</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer__bottom">
                <span>&copy; {{ date('Y') }} {{ $siteName }}. All rights reserved.</span>
            </div>
        </div>
    </footer>
</body>
</html>
