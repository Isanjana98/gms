{{--
    Confirmation after a self-registration is submitted.

    Careful about what this promises. The person has sent a registration, not
    become a member - staff still have to approve it - and saying "welcome,
    you're a member" here would be a lie they only discover at the counter.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Sent &middot; {{ $gymName }}</title>

    <style>
        :root { --ink:#15161a; --muted:#64666f; --dim:#8b8d96; --line:#e3e4e8; --bg:#f6f7f9; --good:#0f9d6b; --warn:#b4121f; }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
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
        .next {
            margin-top: 22px; padding: 15px; border-radius: 12px; text-align: left;
            background: #fdf7ec; border: 1px solid #f0dfbe; color: #7a5a20;
        }
        .next strong { display: block; margin-bottom: 4px; color: #5d4316; }
        .note { font-size: 14px; color: var(--dim); margin-top: 20px; }
    </style>
</head>
<body>

<div class="card">
    <div class="tick" aria-hidden="true">&#10003;</div>

    <div class="gym">{{ $gymName }}</div>
    <h1>Thank you, {{ $firstName }}</h1>

    <p>Your details and your signed health form have been sent to the gym.</p>

    <div class="next">
        <strong>Please speak to the desk</strong>
        A member of staff will check your details and finish setting up your membership. Nothing happens
        automatically, so do come and say you have filled it in.
    </div>

    <p class="note">You can close this page.</p>
</div>

</body>
</html>
