{{--
    Setup.

    Entirely self-contained: no layout, no CSS file, no session. Everything
    this page depends on is either in the file or already in the browser,
    because at the moment it renders there is no database, no APP_KEY and
    possibly no .env.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Set up</title>

    <style>
        :root {
            --ink: #15161a; --muted: #64666f; --dim: #8b8d96;
            --line: #e3e4e8; --bg: #f6f7f9; --card: #fff;
            --accent: #e11d2e; --accent-dark: #b4121f;
            --good: #0f9d6b; --bad: #c42b1c; --warn: #b4121f;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .wrap { max-width: 680px; margin: 0 auto; padding: 0 16px 56px; }
        header { background: var(--ink); color: #fff; padding: 26px 16px 30px; margin-bottom: -14px; }
        header .inner { max-width: 680px; margin: 0 auto; }
        h1 { font-size: 24px; margin: 4px 0 0; letter-spacing: -.02em; }
        .sub { color: #a7a9b4; font-size: 14px; margin-top: 7px; }

        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 20px; margin-top: 14px; }
        .card h2 { font-size: 12px; letter-spacing: .14em; text-transform: uppercase; color: var(--dim); margin: 0 0 14px; }

        .checks { list-style: none; margin: 0; padding: 0; }
        .checks li { display: flex; gap: 10px; align-items: baseline; padding: 6px 0; border-bottom: 1px solid var(--line); }
        .checks li:last-child { border-bottom: 0; }
        .checks .mark { width: 16px; flex: none; font-weight: 700; }
        .ok .mark { color: var(--good); }
        .no .mark { color: var(--bad); }
        .checks .label { flex: 1; }
        .checks .detail { color: var(--dim); font-size: 13px; }

        .row { margin-bottom: 15px; }
        .row label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 5px; }
        .row .hint { color: var(--dim); font-size: 13px; margin-top: 5px; }
        input {
            width: 100%; font: inherit; color: var(--ink); background: #fff;
            border: 1.5px solid var(--line); border-radius: 10px; padding: 11px 12px;
        }
        input:focus { outline: 2px solid var(--accent); outline-offset: 1px; border-color: transparent; }
        .pair { display: flex; gap: 12px; }
        .pair .row { flex: 1; }
        .pair .row.port { max-width: 130px; }

        .err { color: var(--bad); font-size: 13px; margin-top: 5px; }
        input.bad { border-color: var(--bad); }

        .alert { border-radius: 10px; padding: 13px 15px; margin-top: 14px; border: 1.5px solid; }
        .alert.bad { border-color: var(--bad); background: #fdf2f2; color: #8a1b12; }
        .alert.warn { border-color: #e0b64a; background: #fdf8ec; color: #7a5a20; }

        button {
            width: 100%; padding: 15px; font: inherit; font-weight: 700;
            border: 0; border-radius: 11px; background: var(--accent); color: #fff; cursor: pointer;
            margin-top: 4px;
        }
        button:active { background: var(--accent-dark); }
        button[disabled] { background: #c9cbd2; cursor: not-allowed; }
        .note { color: var(--dim); font-size: 13px; margin-top: 14px; }
    </style>
</head>
<body>

<header>
    <div class="inner">
        <div class="sub">Gym management system</div>
        <h1>Set up</h1>
        <div class="sub">This runs once. After it finishes, this page stops existing.</div>
    </div>
</header>

<div class="wrap">

    {{-- ------------------------------------------------ requirements --}}
    <div class="card">
        <h2>What this host provides</h2>
        <ul class="checks">
            @foreach ($requirements as $check)
                <li class="{{ $check['ok'] ? 'ok' : 'no' }}">
                    <span class="mark">{!! $check['ok'] ? '&check;' : '&times;' !!}</span>
                    <span class="label">{{ $check['label'] }}</span>
                    <span class="detail">{{ $check['detail'] }}</span>
                </li>
            @endforeach
        </ul>

        @unless ($ready)
            <div class="alert bad">
                Setup cannot continue until the items marked &times; are resolved. On shared hosting these are
                usually a request to your provider rather than something you can change yourself.
            </div>
        @endunless
    </div>

    @if ($failure)
        <div class="alert bad" role="alert"><strong>That did not work.</strong><br>{{ $failure }}</div>
    @endif

    <form method="POST" action="{{ url('/install') }}">
        {{-- No @csrf: these routes run without a session, because the session
             driver is the database and the database does not exist yet. --}}

        {{-- ----------------------------------------------- the gym --}}
        <div class="card">
            <h2>The gym</h2>

            <div class="row">
                <label for="gym_name">Name</label>
                <input id="gym_name" name="gym_name" value="{{ $values['gym_name'] ?? '' }}"
                       class="{{ isset($errors['gym_name']) ? 'bad' : '' }}" required>
                @isset($errors['gym_name'])<div class="err">{{ $errors['gym_name'][0] }}</div>@endisset
                <div class="hint">Shown on receipts, the website and the phone apps.</div>
            </div>

            <div class="row">
                <label for="app_url">Address</label>
                <input id="app_url" name="app_url" type="url" value="{{ $values['app_url'] ?? '' }}"
                       class="{{ isset($errors['app_url']) ? 'bad' : '' }}" required>
                @isset($errors['app_url'])<div class="err">{{ $errors['app_url'][0] }}</div>@endisset
                <div class="hint">
                    Where this system will be reached. It is encoded into the QR codes members scan, so get it
                    right — including <strong>https</strong> if you have a certificate.
                </div>
            </div>
        </div>

        {{-- ------------------------------------------ the database --}}
        <div class="card">
            <h2>Database</h2>

            <div class="pair">
                <div class="row">
                    <label for="db_host">Host</label>
                    <input id="db_host" name="db_host" value="{{ $values['db_host'] ?? '' }}"
                           class="{{ isset($errors['db_host']) ? 'bad' : '' }}" required>
                </div>
                <div class="row port">
                    <label for="db_port">Port</label>
                    <input id="db_port" name="db_port" inputmode="numeric" value="{{ $values['db_port'] ?? '3306' }}"
                           class="{{ isset($errors['db_port']) ? 'bad' : '' }}" required>
                </div>
            </div>
            <div class="hint" style="margin-top:-8px;margin-bottom:15px">
                On shared hosting the host is nearly always <code>localhost</code>.
            </div>

            <div class="row">
                <label for="db_database">Database name</label>
                <input id="db_database" name="db_database" value="{{ $values['db_database'] ?? '' }}"
                       class="{{ isset($errors['db_database']) ? 'bad' : '' }}" required>
                @isset($errors['db_database'])<div class="err">{{ $errors['db_database'][0] }}</div>@endisset
                <div class="hint">
                    Created automatically if this user is allowed to. Most shared hosts are not, so if setup
                    stops here, create it in your control panel and come back.
                </div>
            </div>

            <div class="pair">
                <div class="row">
                    <label for="db_username">Username</label>
                    <input id="db_username" name="db_username" value="{{ $values['db_username'] ?? '' }}"
                           class="{{ isset($errors['db_username']) ? 'bad' : '' }}" required>
                </div>
                <div class="row">
                    <label for="db_password">Password</label>
                    <input id="db_password" name="db_password" type="password" autocomplete="off">
                </div>
            </div>
        </div>

        {{-- --------------------------------------- the first account --}}
        <div class="card">
            <h2>Your account</h2>

            <div class="row">
                <label for="admin_name">Name</label>
                <input id="admin_name" name="admin_name" value="{{ $values['admin_name'] ?? '' }}"
                       class="{{ isset($errors['admin_name']) ? 'bad' : '' }}" required>
                @isset($errors['admin_name'])<div class="err">{{ $errors['admin_name'][0] }}</div>@endisset
            </div>

            <div class="row">
                <label for="admin_email">Email</label>
                <input id="admin_email" name="admin_email" type="email" value="{{ $values['admin_email'] ?? '' }}"
                       class="{{ isset($errors['admin_email']) ? 'bad' : '' }}" required autocomplete="username">
                @isset($errors['admin_email'])<div class="err">{{ $errors['admin_email'][0] }}</div>@endisset
                <div class="hint">What you will sign in with.</div>
            </div>

            <div class="pair">
                <div class="row">
                    <label for="admin_password">Password</label>
                    <input id="admin_password" name="admin_password" type="password"
                           class="{{ isset($errors['admin_password']) ? 'bad' : '' }}"
                           required autocomplete="new-password">
                    @isset($errors['admin_password'])<div class="err">{{ $errors['admin_password'][0] }}</div>@endisset
                </div>
                <div class="row">
                    <label for="admin_password_confirmation">Again</label>
                    <input id="admin_password_confirmation" name="admin_password_confirmation" type="password"
                           required autocomplete="new-password">
                </div>
            </div>

            <div class="alert warn">
                This account can do everything, including seeing every member&rsquo;s health answers and the
                gym&rsquo;s takings. Give it a password you do not use anywhere else.
            </div>
        </div>

        <div class="card">
            <button type="submit" {{ $ready ? '' : 'disabled' }}>Set up the system</button>
            <p class="note">
                This creates the tables, the roles and your account. It takes a few seconds; do not reload the
                page while it runs.
            </p>
        </div>
    </form>
</div>

</body>
</html>
