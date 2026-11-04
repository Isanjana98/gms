{{-- Setup finished. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Set up &middot; done</title>

    <style>
        :root { --ink:#15161a; --muted:#64666f; --dim:#8b8d96; --line:#e3e4e8; --bg:#f6f7f9; --good:#0f9d6b; }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 24px;
        }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 32px 28px; max-width: 540px; width: 100%; }
        .tick {
            width: 60px; height: 60px; margin: 0 auto 20px; border-radius: 50%;
            background: #e7f6f0; color: var(--good); font-size: 30px;
            display: flex; align-items: center; justify-content: center;
        }
        h1 { font-size: 23px; margin: 0 0 8px; text-align: center; letter-spacing: -.02em; }
        .lede { color: var(--muted); text-align: center; margin: 0 0 22px; }

        .go {
            display: block; text-align: center; text-decoration: none;
            background: #e11d2e; color: #fff; font-weight: 700;
            padding: 15px; border-radius: 11px; margin-bottom: 20px;
        }

        h2 { font-size: 12px; letter-spacing: .14em; text-transform: uppercase; color: var(--dim); margin: 22px 0 10px; }
        ol { margin: 0; padding-left: 20px; }
        ol li { margin-bottom: 9px; }
        code { background: #f1f2f5; padding: 1px 5px; border-radius: 4px; font-size: 13px; }

        .warn { border: 1.5px solid #e0b64a; background: #fdf8ec; color: #7a5a20; border-radius: 10px; padding: 13px 15px; margin-top: 18px; }
    </style>
</head>
<body>

<div class="card">
    <div class="tick" aria-hidden="true">&#10003;</div>

    <h1>{{ $gymName }} is ready</h1>
    <p class="lede">Sign in with <strong>{{ $email }}</strong>.</p>

    <a class="go" href="{{ $appUrl }}/admin">Open the admin panel</a>

    <h2>Before anyone else uses it</h2>
    <ol>
        <li>Set the scheduler running — without it nothing expires, no attendance syncs and no backups are
            taken:<br><code>* * * * * cd {{ base_path() }} && php artisan schedule:run</code></li>
        <li>Add your branch details, membership plans and staff accounts.</li>
        <li>Publish the public website under <strong>Website</strong>, if you want one.</li>
        <li>Add the fingerprint reader under <strong>Fingerprint devices</strong>.</li>
    </ol>

    @unless ($storageLinked)
        {{--
            Only shown when it actually failed. Hosts that disable symlink()
            are common enough to handle, and the symptom - every photograph
            and the logo returning 404 - points nowhere near setup.
        --}}
        <div class="warn">
            <strong>Uploaded images will not display yet.</strong> This host would not let the installer link
            <code>public/storage</code> to <code>storage/app/public</code>. Run
            <code>php artisan storage:link</code> from a shell, or create that link in your hosting control
            panel's file manager.
        </div>
    @endunless

    <div class="warn">
        <strong>Delete the installer.</strong> It has locked itself and now answers 404, but the surest thing is
        to remove <code>app/Http/Controllers/Install</code> and the <code>/install</code> routes from a system
        anybody on the internet can reach.
    </div>
</div>

</body>
</html>
