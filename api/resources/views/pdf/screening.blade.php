{{-- Health screening (PAR-Q), A4. The document produced if anything goes wrong. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Health screening {{ $screening->reference }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; }
        .brand { font-size: 17px; font-weight: bold; }
        .muted { color: #666; }
        .header { border-bottom: 2px solid #1a1a1a; padding-bottom: 10px; margin-bottom: 16px; }
        .title { font-size: 13px; font-weight: bold; letter-spacing: .12em; margin-top: 8px; }
        table { width: 100%; border-collapse: collapse; }
        .facts td { padding: 4px 0; vertical-align: top; }
        .facts td.label { color: #666; width: 26%; }

        .section { font-size: 11px; font-weight: bold; letter-spacing: .1em; text-transform: uppercase;
                   color: #666; margin: 20px 0 8px; border-bottom: 1px solid #ddd; padding-bottom: 4px; }

        .q td { padding: 7px 0; border-bottom: 1px solid #eee; vertical-align: top; }
        .q td.n { width: 22px; color: #999; }
        .q td.a { width: 44px; text-align: right; font-weight: bold; }
        .yes { color: #b00; }
        .no { color: #2a7; }
        .detail { margin-top: 4px; padding: 6px 8px; background: #fbf4f4; border-left: 2px solid #b00; color: #444; }

        .terms { border: 1px solid #ddd; padding: 10px; color: #333; line-height: 1.5; }
        .terms p { margin: 0 0 8px; }
        .terms p:last-child { margin-bottom: 0; }

        /* The signature and the provenance under it are one piece of
           evidence; a page break between them makes the image look
           unattributed. dompdf honours page-break-inside on a block. */
        .sig { margin-top: 10px; border: 1px solid #ddd; padding: 10px; page-break-inside: avoid; }
        .section { page-break-after: avoid; }
        .sig img { height: 70px; }

        .flagbox { border: 2px solid #b00; color: #b00; padding: 9px; margin-bottom: 14px; font-weight: bold; }
        .clearbox { border: 1px solid #2a7; color: #176; padding: 9px; margin-bottom: 14px; }

        .hash { font-family: DejaVu Sans Mono, monospace; font-size: 8px; color: #888; word-break: break-all; }
        .footer { margin-top: 20px; border-top: 1px solid #ddd; padding-top: 8px; font-size: 9px; color: #888; }
        .tamper { color: #b00; font-weight: bold; }
    </style>
</head>
<body>

<div class="header">
    <div class="brand">{{ $gymName }}</div>
    @if ($screening->branch?->name)
        <div class="muted">{{ $screening->branch->name }}</div>
    @endif
    <div class="title">PHYSICAL ACTIVITY READINESS QUESTIONNAIRE</div>
</div>

@if ($screening->has_flags)
    <div class="flagbox">
        {{ $screening->flag_count }} {{ Str::plural('question', $screening->flag_count) }} answered YES &mdash;
        {{ match ($screening->review_status) {
            'PENDING' => 'awaiting review by the gym.',
            'CLEARED' => 'reviewed and cleared to train.',
            'DOCTOR_NOTE_REQUIRED' => "reviewed; doctor's clearance required.",
            'RESTRICTED' => 'reviewed; training restricted.',
            default => 'reviewed.',
        } }}
    </div>
@else
    <div class="clearbox">All seven questions answered NO. No review was required.</div>
@endif

<table class="facts">
    <tr><td class="label">Reference</td><td><strong>{{ $screening->reference }}</strong></td></tr>
    <tr><td class="label">Member</td><td>{{ $screening->member?->full_name }}</td></tr>
    <tr><td class="label">Membership no.</td><td>{{ $screening->member?->membership_number }}</td></tr>
    @if ($screening->member?->date_of_birth)
        <tr><td class="label">Date of birth</td><td>{{ $screening->member->date_of_birth->format('j M Y') }}</td></tr>
    @endif
    <tr><td class="label">Completed</td><td>{{ $screening->signed_at?->format('j M Y, H:i') }}</td></tr>
    <tr><td class="label">Questionnaire</td><td>PAR-Q v{{ $screening->questionnaire_version }}</td></tr>
</table>

<div class="section">The questions, as they were asked</div>

<table class="q">
    @foreach ($questions as $i => $question)
        @php
            $answer = $answers[$question['id']] ?? null;
            $yes = (bool) ($answer['answer'] ?? false);
        @endphp
        <tr>
            <td class="n">{{ $i + 1 }}.</td>
            <td>
                {{ $question['text'] }}
                @if ($yes && ! empty($answer['detail']))
                    <div class="detail">{{ $answer['detail'] }}</div>
                @endif
            </td>
            <td class="a {{ $yes ? 'yes' : 'no' }}">{{ $yes ? 'YES' : 'NO' }}</td>
        </tr>
    @endforeach
</table>

<div class="section">What was agreed</div>

<div class="terms">
    @foreach (preg_split('/\n\s*\n/', trim($terms['body'])) as $paragraph)
        <p>{{ trim($paragraph) }}</p>
    @endforeach
</div>
<div class="muted" style="margin-top:5px; font-size:9px;">
    Terms version {{ $screening->terms_version }} &middot; hash
    <span class="hash">{{ $screening->terms_hash }}</span>
</div>

<div class="section">Signature</div>

<div class="sig">
    @if ($signature)
        <img src="{{ $signature }}" alt="Signature">
    @else
        <div class="muted">No signature image is held for this record.</div>
    @endif
    <table class="facts" style="margin-top:8px;">
        <tr><td class="label">Signed by</td><td><strong>{{ $screening->signed_name }}</strong></td></tr>
        <tr><td class="label">Date and time</td><td>{{ $screening->signed_at?->format('j M Y, H:i:s T') }}</td></tr>
        <tr><td class="label">From</td><td>{{ $screening->ip_address ?? '&mdash;' }}</td></tr>
        @if ($screening->user_agent)
            <tr><td class="label">Device</td><td class="muted">{{ $screening->user_agent }}</td></tr>
        @endif
    </table>
</div>

@if ($screening->review_status !== 'NOT_REQUIRED' && $screening->reviewed_at)
    <div class="section">Review</div>
    <table class="facts">
        <tr><td class="label">Outcome</td><td><strong>{{ str_replace('_', ' ', $screening->review_status) }}</strong></td></tr>
        <tr><td class="label">Reviewed by</td><td>{{ $screening->reviewedBy?->name }}</td></tr>
        <tr><td class="label">On</td><td>{{ $screening->reviewed_at->format('j M Y, H:i') }}</td></tr>
        @if ($screening->review_notes)
            <tr><td class="label">Notes</td><td>{{ $screening->review_notes }}</td></tr>
        @endif
    </table>
@endif

<div class="footer">
    Record hash (SHA-256): <span class="hash">{{ $screening->record_hash }}</span><br>
    @if ($intact)
        Verified against the stored record at the time this document was produced.
    @else
        <span class="tamper">This record does not match its hash. It has been altered since it was signed.</span>
    @endif
    <br>
    Issued by {{ $screening->issuedBy?->name ?? 'the system' }} &middot;
    produced {{ now()->format('j M Y, H:i') }}
</div>

</body>
</html>
