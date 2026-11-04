<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Not available</title>
    {{-- Explicitly out of the index. The site is unpublished precisely because
         it is not ready to be seen, and a crawler that reaches it during setup
         should not remember this page. --}}
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="{{ asset('css/site.css') }}">
</head>
<body>
    <section class="section">
        <div class="wrap" style="max-width:520px;text-align:center;padding-block:6rem">
            <h1 style="font-size:1.75rem">Nothing here yet</h1>
            <p>This website has not been published.</p>
            <p style="font-size:.9375rem;color:var(--ink-500)">
                If you manage this gym, switch it on under <strong>Website → Content</strong> in the admin panel.
            </p>
        </div>
    </section>
</body>
</html>
