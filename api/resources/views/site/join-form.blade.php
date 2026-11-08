{{--
    Self-registration from the QR code at the desk.

    The health form's sibling: same layout, same signature pad, same four
    steps' worth of chrome - all of which now live in the two partials below
    rather than being written twice.

    What differs is who is filling it in. A health form is issued to somebody
    the gym already knows, from a link only they were given. This one is open
    to anyone who can see a printed code, so it asks who they are first, and
    what it produces is a registration for staff to approve rather than a
    member.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Somebody's name, address and health answers. Never indexed, never
         cached by a proxy, never kept in the back/forward cache. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>Join &middot; {{ $gymName }}</title>

    <style>
        @include('site.partials.public-form-styles')
    </style>
</head>
<body>

<header>
    <div class="inner">
        <div class="gym">{{ $gymName }}</div>
        <h1>Join {{ $gymName }}</h1>
        <div class="who">Fill this in on your own phone &mdash; it takes a couple of minutes</div>
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

    <form method="POST" action="{{ route('site.join.submit', ['code' => $code]) }}" id="public-form" novalidate>
        @csrf

        {{-- ---------------------------------------------------- about you --}}
        <section class="card">
            <div class="step-title">Step 1 &middot; About you</div>
            <p class="lede">
                Only your name is required. The rest helps us reach you, and reach somebody else if you are
                ever unwell here.
            </p>

            <div class="detail">
                <label for="full_name">Full name <span aria-hidden="true">*</span></label>
                <input type="text" id="full_name" name="full_name" autocomplete="name"
                       value="{{ old('full_name') }}" maxlength="255" required>
            </div>

            <div class="detail">
                <label for="telephone">Mobile number</label>
                <input type="tel" id="telephone" name="telephone" autocomplete="tel"
                       inputmode="tel" value="{{ old('telephone') }}" maxlength="30">
            </div>

            <div class="detail">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" autocomplete="email"
                       inputmode="email" value="{{ old('email') }}" maxlength="255">
            </div>

            <div class="detail">
                <label for="nic_passport">NIC or passport number</label>
                <input type="text" id="nic_passport" name="nic_passport"
                       value="{{ old('nic_passport') }}" maxlength="30">
            </div>

            <div class="detail">
                <label for="date_of_birth">Date of birth</label>
                <input type="date" id="date_of_birth" name="date_of_birth" autocomplete="bday"
                       value="{{ old('date_of_birth') }}">
            </div>

            <div class="detail">
                <label for="gender">Gender</label>
                <select id="gender" name="gender">
                    <option value="">Prefer not to say</option>
                    <option value="MALE"   @selected(old('gender') === 'MALE')>Male</option>
                    <option value="FEMALE" @selected(old('gender') === 'FEMALE')>Female</option>
                    <option value="OTHER"  @selected(old('gender') === 'OTHER')>Other</option>
                </select>
            </div>

            <div class="detail">
                <label for="address">Address</label>
                <input type="text" id="address" name="address" autocomplete="street-address"
                       value="{{ old('address') }}" maxlength="255">
            </div>

            <div class="detail">
                <label for="city">City</label>
                <input type="text" id="city" name="city" autocomplete="address-level2"
                       value="{{ old('city') }}" maxlength="100">
            </div>
        </section>

        {{-- -------------------------------------------- emergency contact --}}
        <section class="card">
            <div class="step-title">Step 2 &middot; If something happens</div>
            <p class="lede">Who should we call if you are taken ill while you are here?</p>

            <div class="detail">
                <label for="emergency_contact_name">Their name</label>
                <input type="text" id="emergency_contact_name" name="emergency_contact_name"
                       value="{{ old('emergency_contact_name') }}" maxlength="255">
            </div>

            <div class="detail">
                <label for="emergency_contact_number">Their number</label>
                <input type="tel" id="emergency_contact_number" name="emergency_contact_number"
                       inputmode="tel" value="{{ old('emergency_contact_number') }}" maxlength="30">
            </div>
        </section>

        {{-- ------------------------------------------------------ the PAR-Q --}}
        <section class="card">
            <div class="step-title">Step 3 &middot; About your health</div>
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

        {{-- -------------------------------------------- the gym's own questions --}}
        @if ($extras->isNotEmpty())
            <section class="card">
                <div class="step-title">A few more things</div>
                <p class="lede">These help us look after you properly.</p>

                @foreach ($extras as $extra)
                    @php $old = old("custom.{$extra->key}"); @endphp

                    @if ($extra->type === 'BOOLEAN')
                        <div class="q" data-question="{{ $extra->key }}">
                            <span class="q-text" id="label-{{ $extra->key }}">{{ $extra->label }}</span>

                            <div class="choice" role="radiogroup" aria-labelledby="label-{{ $extra->key }}">
                                <label class="no">
                                    <input type="radio" name="custom[{{ $extra->key }}]" value="no"
                                           @checked($old === 'no')>
                                    <span>No</span>
                                </label>
                                <label class="yes">
                                    <input type="radio" name="custom[{{ $extra->key }}]" value="yes"
                                           @checked($old === 'yes')>
                                    <span>Yes</span>
                                </label>
                            </div>

                            {{-- data-detail is what the shared script shows and
                                 hides, so these behave exactly like a PAR-Q yes. --}}
                            <div class="detail" data-detail @if($old !== 'yes') hidden @endif>
                                <label for="detail-{{ $extra->key }}">{{ $extra->help ?: 'Please tell us more.' }}</label>
                                <textarea id="detail-{{ $extra->key }}" name="custom[{{ $extra->key }}_detail]"
                                          maxlength="500">{{ old("custom.{$extra->key}_detail") }}</textarea>
                            </div>
                        </div>
                    @else
                        <div class="detail">
                            <label for="custom-{{ $extra->key }}">
                                {{ $extra->label }}
                                @if ($extra->is_required)<span aria-hidden="true">*</span>@endif
                            </label>

                            @if ($extra->type === 'TEXTAREA')
                                <textarea id="custom-{{ $extra->key }}" name="custom[{{ $extra->key }}]"
                                          maxlength="2000" @required($extra->is_required)>{{ $old }}</textarea>
                            @elseif ($extra->type === 'CHOICE')
                                <select id="custom-{{ $extra->key }}" name="custom[{{ $extra->key }}]"
                                        @required($extra->is_required)>
                                    <option value="">Choose&hellip;</option>
                                    @foreach ($extra->options ?? [] as $option)
                                        <option value="{{ $option }}" @selected($old === $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="text" id="custom-{{ $extra->key }}" name="custom[{{ $extra->key }}]"
                                       value="{{ $old }}" maxlength="255" @required($extra->is_required)>
                            @endif

                            @if ($extra->help)
                                <p class="note" style="margin-top:6px">{{ $extra->help }}</p>
                            @endif
                        </div>
                    @endif
                @endforeach
            </section>
        @endif

        {{-- ------------------------------------------------ terms and sign --}}
        <section class="card">
            <div class="step-title">Last step &middot; Agree and sign</div>

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
                       value="{{ old('signed_name') }}" maxlength="255">
            </div>

            {{--
                A field no person will ever see, and no person should ever
                fill in. The code is printed on a wall, so the form is open to
                anything that crawls the web; a bot that fills every input it
                finds announces itself here, and the submission is dropped
                without telling it why.
            --}}
            <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;">
                <label for="website">Leave this field empty</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" value="">
            </div>

            <div class="actions">
                <button type="submit" class="btn" id="submit">Send my registration</button>
            </div>

            <p class="note">
                We record the time you signed and the device you used, so this form can be relied on later.
                A member of staff will check your details before your membership starts.
            </p>
        </section>
    </form>
</div>

<script>
@include('site.partials.signature-pad')
</script>

</body>
</html>
