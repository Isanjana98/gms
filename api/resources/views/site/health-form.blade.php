{{--
    The health form a member completes on their own phone.

    Standalone on purpose: it carries its own styles and does not extend the
    marketing site's layout, because it has to keep working when the public
    website is switched off, and because a form somebody is signing should not
    share a page with a "Join today" button.

    Progressive enhancement throughout. Without JavaScript this is one long
    ordinary form with radio buttons that posts and works â€” except the
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
        @include('site.partials.public-form-styles')
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

    <form method="POST" action="{{ route('site.health.submit', ['token' => $token]) }}" id="public-form" novalidate>
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
@include('site.partials.signature-pad')
</script>

</body>
</html>
