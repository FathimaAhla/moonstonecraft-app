<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $sale->invoice_no }}</title>
<style>
  @page { size: A4; margin: 15mm; }
  body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #222; background: #fff; }
  @if(! $pdf) body { max-width: 190mm; margin: 10mm auto; } @endif
  table { width: 100%; border-collapse: collapse; }
  td, th { vertical-align: top; padding: 4px 6px; }
  .lines th { background: #0e5468; color: #fff; border: 1px solid #0e5468; text-align: left; }
  .lines td { border: 1px solid #e7e5e4; }
  .r { text-align: right; } .b { font-weight: bold; }
  .title { font-size: 24px; font-weight: bold; letter-spacing: 2px; color: #ff5a5f; }
  .rule { height: 3px; background: #f9c74f; margin: 10px 0 0; }
  .muted { color: #666; }
  .box { border: 1px solid #ddd; padding: 8px; }
  .collect { font-size: 16px; font-weight: bold; border: 2px solid #000; padding: 8px; text-align: center; margin-top: 10px; }
  @media print { .noprint { display: none; } }
</style>
</head>
<body>
  @unless($pdf)
    <p class="noprint"><button onclick="window.print()">Print</button></p>
  @endunless
  <table>
    <tr>
      <td style="width:70px">
        <img src="{{ $pdf ? public_path('brand/moonstone-badge-sm.png') : asset('brand/moonstone-badge-sm.png') }}" alt="" style="width:64px;height:64px">
      </td>
      <td>
        <div class="b" style="font-size:16px;color:#0e5468">{{ $s['business_name'] }}</div>
        <div class="muted">{!! nl2br(e($s['business_address'])) !!}</div>
        <div class="muted">{{ $s['business_phone'] }} {{ $s['business_email'] }}</div>
      </td>
      <td class="r">
        <div class="title">INVOICE</div>
        <div>No: <b>{{ $sale->invoice_no }}</b></div>
        <div>Date: {{ $sale->sold_on->format('Y-m-d') }}</div>
        @if($sale->due_date && $sale->balance > 0)<div class="b">Due: {{ $sale->due_date->format('Y-m-d') }}</div>@endif
      </td>
    </tr>
  </table>

  <div class="rule"></div>

  <table style="margin-top:12px">
    <tr>
      <td style="width:50%" class="box">
        <div class="muted">Bill to</div>
        @if($sale->customer)
          <div class="b">{{ $sale->customer->name }}</div>
          <div>{{ $sale->customer->phone }}</div>
          <div>{{ $sale->customer->address }} {{ $sale->customer->city }}</div>
        @else
          <div>Walk-in customer</div>
        @endif
      </td>
      <td style="width:50%" class="box">
        <div class="muted">Delivery</div>
        <div>{{ ucfirst($sale->delivery_method) }}</div>
        @if($sale->delivery_method === 'courier')<div>{{ $sale->delivery_address }}</div>@endif
      </td>
    </tr>
  </table>

  <table class="lines" style="margin-top:12px">
    <thead>
      <tr><th>Code</th><th>Item</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Amount</th></tr>
    </thead>
    <tbody>
      @foreach($sale->items as $item)
        <tr>
          <td>{{ $item->code }}</td>
          <td>{{ $item->name }}</td>
          <td class="r">{{ rtrim(rtrim(number_format($item->qty, 3), '0'), '.') }}</td>
          <td class="r">{{ number_format($item->unit_price, 2) }}</td>
          <td class="r">{{ number_format($item->qty * $item->unit_price, 2) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <table style="margin-top:8px">
    <tr>
      <td style="width:55%">
        <div class="box">
          <div><span class="muted">Payment method:</span> {{ strtoupper($sale->payment_method) }}</div>
          <div><span class="muted">Status:</span> {{ str_replace('_', ' ', ucfirst($sale->payment_status)) }}</div>
          @if($sale->payment_method === 'bank' && $s['bank_details'])
            <div style="margin-top:6px"><span class="muted">Bank details</span><br>{!! nl2br(e($s['bank_details'])) !!}</div>
          @endif
        </div>
      </td>
      <td style="width:45%">
        <table>
          <tr><td>Subtotal</td><td class="r">{{ number_format($sale->subtotal, 2) }}</td></tr>
          @if($sale->discount > 0)<tr><td>Discount</td><td class="r">-{{ number_format($sale->discount, 2) }}</td></tr>@endif
          @if($sale->courier_charge > 0)<tr><td>Courier</td><td class="r">{{ number_format($sale->courier_charge, 2) }}</td></tr>@endif
          <tr class="b" style="font-size:14px"><td>Total</td><td class="r">{{ $s['currency_symbol'] }} {{ number_format($sale->total, 2) }}</td></tr>
          <tr><td>Paid</td><td class="r">{{ number_format($sale->paid_amount, 2) }}</td></tr>
          <tr class="b"><td>Balance</td><td class="r">{{ number_format($sale->balance, 2) }}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  @if($sale->payment_method === 'cod' && $sale->balance > 0)
    <div class="collect">COLLECT {{ $s['currency_symbol'] }} {{ number_format($sale->balance, 2) }} ON DELIVERY</div>
  @endif

  <p class="muted" style="margin-top:20px; text-align:center">{{ $s['invoice_footer'] }}</p>
</body>
</html>
