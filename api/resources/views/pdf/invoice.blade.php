{{-- SRS 18 - Printable invoice. Plain tables and inline styles because dompdf
     supports very little CSS; anything clever here silently renders wrong. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; }
        .header { border-bottom: 2px solid #1a1a1a; padding-bottom: 12px; margin-bottom: 18px; }
        .brand { font-size: 20px; font-weight: bold; }
        .muted { color: #666; }
        .title { font-size: 16px; font-weight: bold; text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 2px 0; vertical-align: top; }
        .items { margin-top: 18px; }
        .items th { background: #f2f2f2; text-align: left; padding: 7px 8px; border-bottom: 1px solid #ddd; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
        .items td { padding: 7px 8px; border-bottom: 1px solid #eee; }
        .right { text-align: right; }
        .totals { margin-top: 14px; width: 45%; float: right; }
        .totals td { padding: 4px 8px; }
        .totals .grand { border-top: 2px solid #1a1a1a; font-weight: bold; font-size: 13px; }
        .status { display: inline-block; padding: 3px 9px; border: 1px solid #999; border-radius: 3px; font-size: 10px; font-weight: bold; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 9px; color: #888; border-top: 1px solid #eee; padding-top: 6px; }
    </style>
</head>
<body>

<div class="header">
    <table>
        <tr>
            <td style="width: 60%;">
                {{-- The gym's own name, not the branch's internal label: this
                     document goes to the member. The branch appears below it. --}}
                <div class="brand">{{ $gymName }}</div>
                @if ($invoice->branch?->name)
                    <div class="muted">{{ $invoice->branch->name }}</div>
                @endif
                <div class="muted">
                    @if ($invoice->branch?->address){{ $invoice->branch->address }}<br>@endif
                    @if ($invoice->branch?->city){{ $invoice->branch->city }}<br>@endif
                    @if ($invoice->branch?->telephone)Tel: {{ $invoice->branch->telephone }}@endif
                </div>
            </td>
            <td class="right" style="vertical-align: top;">
                <div class="title">INVOICE</div>
                <div class="muted">{{ $invoice->invoice_number }}</div>
                <div style="margin-top: 6px;"><span class="status">{{ $invoice->status }}</span></div>
            </td>
        </tr>
    </table>
</div>

<table class="meta">
    <tr>
        <td style="width: 55%;">
            <strong>Billed to</strong><br>
            {{ $invoice->member?->full_name }}<br>
            <span class="muted">Member No: {{ $invoice->member?->membership_number }}</span><br>
            @if ($invoice->member?->telephone)<span class="muted">{{ $invoice->member->telephone }}</span><br>@endif
            @if ($invoice->member?->email)<span class="muted">{{ $invoice->member->email }}</span>@endif
        </td>
        <td class="right">
            <strong>Issued:</strong> {{ $invoice->issue_date?->format('d M Y') }}<br>
            @if ($invoice->due_date)<strong>Due:</strong> {{ $invoice->due_date->format('d M Y') }}<br>@endif
            @if ($invoice->membership)<strong>Membership:</strong> {{ $invoice->membership->reference }}@endif
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th>Description</th>
            <th class="right" style="width: 12%;">Qty</th>
            <th class="right" style="width: 18%;">Unit price</th>
            <th class="right" style="width: 15%;">Discount</th>
            <th class="right" style="width: 18%;">Amount</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="right">{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}</td>
                <td class="right">{{ number_format((float) $item->unit_price, 2) }}</td>
                <td class="right">{{ number_format((float) $item->discount, 2) }}</td>
                <td class="right">{{ number_format((float) $item->line_total, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No items on this invoice.</td></tr>
        @endforelse
    </tbody>
</table>

<table class="totals">
    <tr>
        <td>Subtotal</td>
        <td class="right">{{ $currency }} {{ number_format((float) $invoice->sub_total, 2) }}</td>
    </tr>
    @if ((float) $invoice->discount > 0)
        <tr>
            <td>Discount</td>
            <td class="right">- {{ $currency }} {{ number_format((float) $invoice->discount, 2) }}</td>
        </tr>
    @endif
    @if ((float) $invoice->tax > 0)
        <tr>
            <td>Tax</td>
            <td class="right">{{ $currency }} {{ number_format((float) $invoice->tax, 2) }}</td>
        </tr>
    @endif
    <tr class="grand">
        <td>Total</td>
        <td class="right">{{ $currency }} {{ number_format((float) $invoice->total, 2) }}</td>
    </tr>
    <tr>
        <td>Paid</td>
        <td class="right">{{ $currency }} {{ number_format((float) $invoice->paid_amount, 2) }}</td>
    </tr>
    <tr style="font-weight: bold;">
        <td>Balance due</td>
        <td class="right">{{ $currency }} {{ number_format($invoice->balance, 2) }}</td>
    </tr>
</table>

<div class="footer">
    Generated {{ now()->format('d M Y H:i') }} &middot; {{ $invoice->invoice_number }} &middot; This is a computer-generated document.
</div>

</body>
</html>
