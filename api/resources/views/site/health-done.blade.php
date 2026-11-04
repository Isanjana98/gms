{{-- Confirmation after a health form is submitted. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Done &middot; {{ $gymName }}</title>

    <style>
        :root { --ink:#15161a; --muted:#64666f; --dim:#8b8d96; --line:#e3e4e8; --bg:#f6f7f9; --good:#0f9d6b; --warn:#b4121f; }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font: 16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; min-height: 100dvh; padding: 24px;
        }
        .card {
            background: #fff; border: 1px solid var(--line); border-radius: 18px;
            padding: 32px 26px; max-width: 460px; width: 100%; text-align: center;
        }
        .tick {
            width: 66px; height: 66px; margin: 0 auto 20px;
            border-radius: 50%; background: #e7f6f0;
            display: flex; align-items: center; justify-content: center;
            color: var(--good); font-size: 32px; line-height: 1;
        }
        .gym { font-size: 12px; letter-spacing: .14em; text-transform: uppercase; color: var(--dim); }
        h1 { font-size: 23px; margin: 10px 0 12px; letter-spacing: -.02em; }
        p { color: var(--muted); margin: 0 0 14px; }
        .ref {
            display: inline-block; margin-top: 4px; padding: 9px 15px;
            border: 1px solid var(--line); border-radius: 10px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 15px;
        }
        .next {
            margin-top: 22px; padding: 15px; border-radius: 12px; text-align: left;
            background: #fdf7ec; border: 1px solid #f0dfbe; color: #7a5a20;
        }
        .next strong { display: block; margin-bottom: 4px; color: #6d4c12; }
        .note { font-size: 13px; color: var(--dim); margin: 22px 0 0; }
    </style>
</head>
<body>

<div class="card">
    <div class="tick" aria-hidden="true">&#10003;</div>

    <div class="gym">{{ $gymName }}</div>
    <h1>That&rsquo;s done</h1>

    <p>Your health form has been signed and sent to the gym.</p>

    <div class="ref">{{ $reference }}</div>

    @if ($hasFlags)
        <div class="next">
            <strong>Somebody will have a quick word</strong>
            You answered yes to at least one question, so a member of staff or a trainer will talk it through
            with you before you start. Nothing is wrong &mdash; it just means we want to get your programme
            right.
        </div>
    @endif

    <p class="note">You can close this page. There is nothing else to do.</p>
</div>

</body>
</html>
