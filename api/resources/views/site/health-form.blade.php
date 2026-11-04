{{--
    The health form a member completes on their own phone.

    Standalone on purpose: it carries its own styles and does not extend the
    marketing site's layout, because it has to keep working when the public
    website is switched off, and because a form somebody is signing should not
    share a page with a "Join today" button.

    Progressive enhancement throughout. Without JavaScript this is one long
    ordinary form with radio buttons that posts and works — except the
    signature, which genuinely cannot be drawn without a canvas, so that case
    says so plainly instead of failing silently.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    {{-- A health form must never be indexed, cached by a proxy, or kept in
         back/forward cache where the next person on a shared phone could
         reach it with the back button. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>Health form &middot; {{ $gymName }}</title>

    <style>
        :root {
            --ink: #15161a;
            --muted: #64666f;
            --dim: #8b8d96;
            --line: #e3e4e8;
            --bg: #f6f7f9;
            --card: #ffffff;
            --accent: #e11d2e;
            --accent-dark: #b4121f;
            --good: #0f9d6b;
            --radius: 14px;
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font: 16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            padding-bottom: env(safe-area-inset-bottom);
        }

        .wrap { max-width: 640px; margin: 0 auto; padding: 0 16px 48px; }

        header {
            background: var(--ink);
            color: #fff;
            padding: 22px 16px 26px;
            margin-bottom: -14px;
        }
        header .inner { max-width: 640px; margin: 0 auto; }
        .gym { font-size: 13px; letter-spacing: .14em; text-transform: uppercase; color: #a7a9b4; }
        h1 { font-size: 23px; margin: 6px 0 0; letter-spacing: -.02em; }
        .who { margin-top: 10px; font-size: 15px; color: #d5d6dc; }
        .who strong { color: #fff; }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 18px;
            margin-top: 14px;
        }

        .lede { color: var(--muted); font-size: 15px; margin: 0 0 4px; }

        .step-title { font-size: 12px; letter-spacing: .14em; text-transform: uppercase; color: var(--dim); margin-bottom: 10px; }

        /* ---------------------------------------------------- questions */
        .q { padding: 16px 0; border-bottom: 1px solid var(--line); }
        .q:first-of-type { padding-top: 4px; }
        .q:last-of-type { border-bottom: 0; padding-bottom: 4px; }
        .q-text { display: block; margin-bottom: 12px; }
        .q-text .n { color: var(--dim); margin-right: 6px; }

        .choice { display: flex; gap: 10px; }
        .choice label {
            flex: 1;
            display: block;
            text-align: center;
            padding: 13px 10px;
            border: 1.5px solid var(--line);
            border-radius: 11px;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            user-select: none;
            background: #fff;
        }
        .choice input { position: absolute; opacity: 0; pointer-events: none; }
        .choice input:focus-visible + span { outline: 2px solid var(--accent); outline-offset: 3px; border-radius: 4px; }
        .choice label:has(input:checked) { border-color: var(--ink); background: var(--ink); color: #fff; }
        .choice label.yes:has(input:checked) { border-color: var(--accent); background: var(--accent); }

        .detail { margin-top: 12px; }
        .detail label { display: block; font-size: 14px; color: var(--muted); margin-bottom: 6px; }
        /* Hidden only when JS is driving; without it every detail box shows,
           which is correct but verbose rather than broken. */
        .js .detail[hidden] { display: none; }

        textarea, input[type="text"] {
            width: 100%;
            font: inherit;
            color: var(--ink);
            background: #fff;
            border: 1.5px solid var(--line);
            border-radius: 11px;
            padding: 12px 13px;
        }
        textarea { min-height: 78px; resize: vertical; }
        textarea:focus, input[type="text"]:focus { outline: 2px solid var(--accent); outline-offset: 1px; border-color: transparent; }

        /* -------------------------------------------------------- terms */
        .terms {
            max-height: 240px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid var(--line);
            border-radius: 11px;
            padding: 14px;
            background: #fcfcfd;
            font-size: 15px;
            color: #33353c;
        }
        .terms p { margin: 0 0 11px; }
        .terms p:last-child { margin-bottom: 0; }
        .terms-meta { font-size: 12px; color: var(--dim); margin-top: 8px; }

        .agree { display: flex; gap: 12px; align-items: flex-start; margin-top: 16px; cursor: pointer; }
        .agree input { width: 24px; height: 24px; margin: 1px 0 0; accent-color: var(--accent); flex: none; }

        /* ---------------------------------------------------- signature */
        .pad {
            position: relative;
            border: 1.5px dashed var(--line);
            border-radius: 11px;
            background: #fff;
            touch-action: none;
            overflow: hidden;
        }
        .pad canvas { display: block; width: 100%; height: 190px; }
        .pad .hint {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            color: var(--dim); font-size: 15px; pointer-events: none;
        }
        .pad.signed { border-style: solid; border-color: var(--ink); }
        .pad.signed .hint { display: none; }
        .pad-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 9px; }

        .link-button {
            background: none; border: 0; padding: 6px 0;
            color: var(--muted); font: inherit; text-decoration: underline; cursor: pointer;
        }

        /* ------------------------------------------------------ buttons */
        .btn {
            display: block; width: 100%;
            padding: 16px;
            font: inherit; font-weight: 700;
            border: 0; border-radius: 12px;
            background: var(--accent); color: #fff;
            cursor: pointer;
        }
        .btn:active { background: var(--accent-dark); }
        .btn[disabled] { background: #c9cbd2; cursor: not-allowed; }
        .btn.ghost { background: none; color: var(--muted); border: 1.5px solid var(--line); }

        .actions { margin-top: 18px; display: grid; gap: 10px; }

        /* ------------------------------------------------------- errors */
        .errors {
            border: 1.5px solid var(--accent);
            background: #fdf2f3;
            color: var(--accent-dark);
            border-radius: 11px;
            padding: 13px 15px;
            margin-top: 14px;
        }
        .errors ul { margin: 6px 0 0; padding-left: 18px; }

        .note { font-size: 13px; color: var(--dim); margin-top: 14px; }
        .noscript { border: 1.5px solid var(--accent); border-radius: 11px; padding: 13px; background: #fdf2f3; color: var(--accent-dark); }

        @media (prefers-reduced-motion: no-preference) {
            .card { transition: opacity .18s ease; }
        }
    </style>
</head>
<body>

<header>
    <div class="inner">
        <div class="gym">{{ $gymName }}</div>
        <h1>Health form</h1>
        <div class="who">for <strong>{{ $memberName }}</strong></div>
    </div>
</header>

<div class="wrap">

    @if ($errors->any())
        <div class="errors" role="alert">
            <strong>That did not go through.</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('site.health.submit', ['token' => $token]) }}" id="health-form" novalidate>
        @csrf

        {{-- ------------------------------------------------ questions --}}
        <section class="card">
            <div class="step-title">Step 1 of 3 &middot; About your health</div>
            <p class="lede">
                Please answer all seven questions honestly. They tell us whether you should speak to a doctor
                before you start. Answering yes does not stop you joining.
            </p>

            @foreach ($questions as $i => $question)
                @php $id = $question['id']; $old = old("answers.{$id}.answer"); @endphp

                <div class="q" data-question="{{ $id }}">
                    <span class="q-text" id="label-{{ $id }}">
                        <span class="n">{{ $i + 1 }}.</span>{{ $question['text'] }}
                    </span>

                    <div class="choice" role="radiogroup" aria-labelledby="label-{{ $id }}">
                        <label class="no">
                            <input type="radio" name="answers[{{ $id }}][answer]" value="no"
                                   @checked($old === 'no')>
                            <span>No</span>
                        </label>
                        <label class="yes">
                            <input type="radio" name="answers[{{ $id }}][answer]" value="yes"
                                   @checked($old === 'yes')>
                            <span>Yes</span>
                        </label>
                    </div>

                    <div class="detail" data-detail @if($old !== 'yes') hidden @endif>
                        <label for="detail-{{ $id }}">{{ $question['flag_detail'] }}</label>
                        <textarea id="detail-{{ $id }}" name="answers[{{ $id }}][detail]"
                                  maxlength="500">{{ old("answers.{$id}.detail") }}</textarea>
                    </div>
                </div>
            @endforeach
        </section>

        {{-- ---------------------------------------------------- terms --}}
        <section class="card">
            <div class="step-title">Step 2 of 3 &middot; What you are agreeing to</div>

            <div class="terms" tabindex="0">
                @foreach (preg_split('/\n\s*\n/', trim($terms['body'])) as $paragraph)
                    <p>{{ trim($paragraph) }}</p>
                @endforeach
            </div>
            <div class="terms-meta">Version {{ $terms['version'] }}</div>

            <label class="agree">
                <input type="checkbox" name="agree" value="1" id="agree" @checked(old('agree'))>
                <span>I have read the above and I agree to it.</span>
            </label>
        </section>

        {{-- ------------------------------------------------ signature --}}
        <section class="card">
            <div class="step-title">Step 3 of 3 &middot; Sign</div>

            <noscript>
                <div class="noscript">
                    Signing needs JavaScript, which is switched off in this browser. Please ask at the desk and
                    they will sort it out on paper.
                </div>
            </noscript>

            <div class="pad" id="pad">
                <canvas id="signature-canvas" aria-label="Sign with your finger"></canvas>
                <div class="hint">Sign here with your finger</div>
            </div>

            <div class="pad-actions">
                <span class="note" style="margin:0">Use your finger or a stylus.</span>
                <button type="button" class="link-button" id="clear-signature">Clear</button>
            </div>

            <input type="hidden" name="signature" id="signature" value="{{ old('signature') }}">

            <div class="detail" style="margin-top:18px">
                <label for="signed_name">Your full name, typed</label>
                <input type="text" id="signed_name" name="signed_name" autocomplete="name"
                       value="{{ old('signed_name', $memberName) }}" maxlength="255">
            </div>

            <div class="actions">
                <button type="submit" class="btn" id="submit">Submit my health form</button>
            </div>

            <p class="note">
                We record the time you signed and the device you used, so this form can be relied on later.
                Your answers are visible only to gym staff.
            </p>
        </section>
    </form>
</div>

<script>
(function () {
    'use strict';

    // Marks that JS is driving, so CSS can hide the detail boxes that would
    // otherwise all be visible.
    document.documentElement.classList.add('js');
    document.body.classList.add('js');

    /* ------------------------------------------- show detail on a yes */
    document.querySelectorAll('[data-question]').forEach(function (block) {
        var detail = block.querySelector('[data-detail]');

        block.addEventListener('change', function (event) {
            if (event.target.name && event.target.name.indexOf('[answer]') !== -1) {
                var yes = event.target.value === 'yes' && event.target.checked;
                detail.hidden = !yes;
                if (yes) { detail.querySelector('textarea').focus(); }
            }
        });
    });

    /* --------------------------------------------------- signature pad */
    var pad = document.getElementById('pad');
    var canvas = document.getElementById('signature-canvas');
    var field = document.getElementById('signature');
    var context = canvas.getContext('2d');
    var drawing = false;
    var dirty = false;

    // The canvas is sized in CSS pixels but drawn in device pixels, or the
    // signature is a blurry mess on every phone made since about 2012.
    function resize() {
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        var rect = canvas.getBoundingClientRect();

        // Resizing clears the canvas, so an existing signature is redrawn
        // from the stored data URL rather than silently lost when the
        // keyboard opens and the viewport changes.
        var existing = dirty ? field.value : null;

        canvas.width = rect.width * ratio;
        canvas.height = rect.height * ratio;
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.lineWidth = 2.2;
        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.strokeStyle = '#15161a';

        if (existing) {
            var image = new Image();
            image.onload = function () { context.drawImage(image, 0, 0, rect.width, rect.height); };
            image.src = existing;
        }
    }

    function position(event) {
        var rect = canvas.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    }

    canvas.addEventListener('pointerdown', function (event) {
        drawing = true;
        canvas.setPointerCapture(event.pointerId);
        var point = position(event);
        context.beginPath();
        context.moveTo(point.x, point.y);
        // A dot, so a single tap registers as a mark rather than nothing.
        context.lineTo(point.x + 0.1, point.y);
        context.stroke();
        mark();
    });

    canvas.addEventListener('pointermove', function (event) {
        if (!drawing) { return; }
        var point = position(event);
        context.lineTo(point.x, point.y);
        context.stroke();
    });

    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (name) {
        canvas.addEventListener(name, function () {
            if (!drawing) { return; }
            drawing = false;
            store();
        });
    });

    function mark() {
        dirty = true;
        pad.classList.add('signed');
    }

    function store() {
        field.value = dirty ? canvas.toDataURL('image/png') : '';
    }

    document.getElementById('clear-signature').addEventListener('click', function () {
        context.clearRect(0, 0, canvas.width, canvas.height);
        dirty = false;
        field.value = '';
        pad.classList.remove('signed');
    });

    window.addEventListener('resize', resize);
    resize();

    // A signature restored by the browser after a failed submit.
    if (field.value) {
        dirty = true;
        pad.classList.add('signed');
        var restored = new Image();
        restored.onload = function () {
            var rect = canvas.getBoundingClientRect();
            context.drawImage(restored, 0, 0, rect.width, rect.height);
        };
        restored.src = field.value;
    }

    /* -------------------------------------------------------- submit */
    var form = document.getElementById('health-form');
    var submit = document.getElementById('submit');

    form.addEventListener('submit', function (event) {
        store();

        var problems = [];

        document.querySelectorAll('[data-question]').forEach(function (block) {
            if (!block.querySelector('input[type="radio"]:checked')) {
                problems.push(block);
            }
        });

        if (problems.length) {
            event.preventDefault();
            problems[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            alert('Please answer every question before signing.');
            return;
        }

        if (!document.getElementById('agree').checked) {
            event.preventDefault();
            document.getElementById('agree').scrollIntoView({ behavior: 'smooth', block: 'center' });
            alert('Please agree to the terms before signing.');
            return;
        }

        if (!field.value) {
            event.preventDefault();
            pad.scrollIntoView({ behavior: 'smooth', block: 'center' });
            alert('Please sign in the box.');
            return;
        }

        // Double-tap on a slow connection would otherwise post twice; the
        // server refuses the second, but the member sees an error instead of
        // the confirmation they earned.
        submit.disabled = true;
        submit.textContent = 'Sending…';
    });
}());
</script>

</body>
</html>
