{{-- SRS 18 - Payment receipt, A5. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $payment->receipt_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; }
        .brand { font-size: 17px; font-weight: bold; }
        .muted { color: #666; }
        .header { text-align: center; border-bottom: 2px solid #1a1a1a; padding-bottom: 10px; margin-bottom: 14px; }
        .title { font-size: 13px; font-weight: bold; letter-spacing: .12em; margin-top: 6px; }
        table { width: 100%; border-collapse: collapse; }
        .rows td { padding: 5px 0; border-bottom: 1px solid #eee; vertical-align: top; }
        .rows td.label { color: #666; width: 42%; }
        .amount { margin-top: 16px; border: 2px solid #1a1a1a; padding: 10px; text-align: center; }
        .amount .value { font-size: 19px; font-weight: bold; }
        .refund { border-color: #b00; color: #b00; }
        .sign { margin-top: 34px; }
        .sign td { padding-top: 22px; border-top: 1px solid #999; font-size: 9px; color: #666; }
        .footer { margin-top: 18px; text-align: center; font-size: 9px; color: #888; }
    </style>
</head>
<body>

@php
    $isRefund = $payment->is_refund;
    $amount = abs((float) $payment->amount);
@endphp

<div class="header">
    {{-- The gym's name, not the branch's internal label: the member keeps
         this. The branch is named underneath. --}}
    <div class="brand">{{ $gymName }}</div>
    @if ($payment->branch?->name)
        <div class="muted">{{ $payment->branch->name }}</div>
    @endif
    <div class="muted">
        @if ($payment->branch?->address){{ $payment->branch->address }}@endif
        @if ($payment->branch?->telephone) &middot; Tel: {{ $payment->branch->telephone }}@endif
    </div>
    <div class="title">{{ $isRefund ? 'REFUND RECEIPT' : 'PAYMENT RECEIPT' }}</div>
</div>

<table class="rows">
    <tr>
        <td class="label">Receipt No</td>
        <td><strong>{{ $payment->receipt_number }}</strong></td>
    </tr>
    <tr>
        <td class="label">Date</td>
        <td>{{ $payment->paid_at?->format('d M Y, H:i') }}</td>
    </tr>
    <tr>
        <td class="label">Member</td>
        <td>{{ $payment->member?->full_name }}<br>
            <span class="muted">{{ $payment->member?->membership_number }}</span></td>
    </tr>
    @if ($payment->membership)
        <tr>
            <td class="label">Membership</td>
            <td>{{ $payment->membership->plan_name }}<br>
                <span class="muted">{{ $payment->membership->start_date->format('d M Y') }} &ndash; {{ $payment->membership->end_date->format('d M Y') }}</span></td>
        </tr>
    @endif
    <tr>
        <td class="label">For</td>
        <td>{{ $payment->payment_type->label() }}</td>
    </tr>
    <tr>
        <td class="label">Method</td>
        <td>{{ $payment->method->label() }}@if ($payment->reference) <span class="muted">({{ $payment->reference }})</span>@endif</td>
    </tr>
    @if ($isRefund && $payment->refund_of_payment_id)
        <tr>
            <td class="label">Refund of</td>
            <td>{{ $payment->reference }}</td>
        </tr>
    @endif
    <tr>
        <td class="label">Received by</td>
        <td>{{ $payment->receivedBy?->name ?? '—' }}</td>
    </tr>
</table>

<div class="amount {{ $isRefund ? 'refund' : '' }}">
    <div class="muted" style="font-size: 9px; letter-spacing: .1em;">{{ $isRefund ? 'AMOUNT REFUNDED' : 'AMOUNT RECEIVED' }}</div>
    <div class="value">{{ $currency }} {{ number_format($amount, 2) }}</div>
</div>

@if ($payment->notes)
    <p class="muted" style="margin-top: 12px;">{{ $payment->notes }}</p>
@endif

<table class="sign">
    <tr>
        <td style="width: 48%;">Received by</td>
        <td style="width: 4%;"></td>
        <td style="width: 48%;">Member signature</td>
    </tr>
</table>

<div class="footer">
    Thank you. Please retain this receipt.<br>
    Generated {{ now()->format('d M Y H:i') }}
</div>

</body>
</html>
