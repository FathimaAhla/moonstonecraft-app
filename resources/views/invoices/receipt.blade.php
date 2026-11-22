<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $sale->invoice_no }}</title>
<style>
  @page { size: {{ $s['receipt_width_mm'] }}mm auto; margin: 0; }
  * { box-sizing: border-box; }
  body { width: {{ (int) $s['receipt_width_mm'] - 8 }}mm; margin: 0 auto; padding: 3mm 0; font: 12px/1.35 "Courier New", monospace; color: #000; background: #fff; }
  .c { text-align: center; } .r { text-align: right; } .b { font-weight: bold; }
  hr { border: 0; border-top: 1px dashed #000; margin: 4px 0; }
  table { width: 100%; border-collapse: collapse; }
  td { vertical-align: top; padding: 1px 0; }
  .big { font-size: 15px; }
  @media screen { body { border: 1px solid #ccc; margin-top: 10px; padding: 3mm; } }
  @media print { .noprint { display: none; } }
</style>
</head>
<body onload="window.print()">
  <div class="c b big">{{ $s['business_name'] }}</div>
  <div class="c">{!! nl2br(e($s['business_address'])) !!}@if($s['business_phone'])<br>{{ $s['business_phone'] }}@endif</div>
  <hr>
  <div>Invoice: {{ $sale->invoice_no }}</div>
  <div>Date: {{ $sale->sold_on->format('Y-m-d') }} {{ $sale->created_at->format('H:i') }}</div>
  @if($sale->customer)<div>Customer: {{ $sale->customer->name }} {{ $sale->customer->phone }}</div>@endif
  @if($sale->delivery_method === 'courier' && $sale->delivery_address)<div>Deliver to: {{ $sale->delivery_address }}</div>@endif
  <hr>
  <table>
    @foreach($sale->items as $item)
      <tr><td colspan="2">{{ $item->code }} {{ $item->name }}</td></tr>
      <tr><td>{{ rtrim(rtrim(number_format($item->qty, 3), '0'), '.') }} x {{ number_format($item->unit_price, 2) }}</td><td class="r">{{ number_format($item->qty * $item->unit_price, 2) }}</td></tr>
    @endforeach
  </table>
  <hr>
  <table>
    <tr><td>Subtotal</td><td class="r">{{ number_format($sale->subtotal, 2) }}</td></tr>
    @if($sale->discount > 0)<tr><td>Discount</td><td class="r">-{{ number_format($sale->discount, 2) }}</td></tr>@endif
    @if($sale->courier_charge > 0)<tr><td>Courier</td><td class="r">{{ number_format($sale->courier_charge, 2) }}</td></tr>@endif
    <tr class="b big"><td>TOTAL</td><td class="r">{{ $s['currency_symbol'] }} {{ number_format($sale->total, 2) }}</td></tr>
    <tr><td>Paid ({{ strtoupper($sale->payment_method) }})</td><td class="r">{{ number_format($sale->paid_amount, 2) }}</td></tr>
    @if($sale->balance > 0)<tr class="b"><td>Balance</td><td class="r">{{ number_format($sale->balance, 2) }}</td></tr>@endif
    @if($sale->due_date && $sale->balance > 0)<tr><td>Due date</td><td class="r">{{ $sale->due_date->format('Y-m-d') }}</td></tr>@endif
  </table>
  @if($sale->payment_method === 'cod' && $sale->balance > 0)
    <hr><div class="c b big">COLLECT {{ $s['currency_symbol'] }} {{ number_format($sale->balance, 2) }} on delivery</div>
  @endif
  <hr>
  <div class="c">{{ $s['receipt_footer'] }}</div>
  <p class="c noprint"><button onclick="window.print()">Print</button></p>
</body>
</html>
