<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tagihan {{ $reservation->code }} | {{ $hotel->name }}</title><link rel="stylesheet" href="/app.css">@include('partials.pastel')<script src="/invoice.js" defer></script></head>
<body><main style="max-width:900px;margin:40px auto;padding:24px">
    <header><p class="eyebrow">{{ $hotel->name }} @if($hotel->is_demo) · Data contoh @endif</p><h1>Tagihan {{ $reservation->code }}</h1><p>{{ $hotel->address }}</p></header>
    <section class="panel"><h2>Informasi tamu</h2><dl class="detail-grid"><div><dt>Nama tamu</dt><dd>{{ $reservation->guest }}</dd></div><div><dt>Kamar</dt><dd>{{ $room->number }} · {{ $room->type }}</dd></div><div><dt>Check-in</dt><dd>{{ $reservation->arrival }}</dd></div><div><dt>Check-out</dt><dd>{{ $reservation->departure }}</dd></div></dl></section>
    @php($foodTotal = $orders->sum('total'))
    <section class="panel"><h2>Rincian biaya</h2><table><thead><tr><th scope="col">Keterangan</th><th scope="col">Jumlah</th></tr></thead><tbody><tr><td>Akomodasi · {{ (int) \Carbon\Carbon::parse($reservation->arrival)->diffInDays($reservation->departure) }} malam</td><td>Rp {{ number_format($reservation->total - $foodTotal, 0, ',', '.') }}</td></tr>
    @foreach($orders as $order) @foreach(json_decode($order->items, true) as $item)<tr><td>{{ $item['quantity'] }} × {{ $item['name'] }}</td><td>Rp {{ number_format($item['quantity'] * $item['price'], 0, ',', '.') }}</td></tr>@endforeach
    @if($order->discount)<tr><td>Diskon pesanan #{{ $order->id }}</td><td>− Rp {{ number_format($order->discount,0,',','.') }}</td></tr>@endif
    @if($order->service)<tr><td>Service pesanan #{{ $order->id }}</td><td>Rp {{ number_format($order->service,0,',','.') }}</td></tr>@endif
    @if($order->tax)<tr><td>Pajak pesanan #{{ $order->id }}</td><td>Rp {{ number_format($order->tax,0,',','.') }}</td></tr>@endif
    @endforeach
    <tr><td><strong>Total tagihan</strong></td><td><strong>Rp {{ number_format($reservation->total, 0, ',', '.') }}</strong></td></tr></tbody></table></section>
    @include('partials.invoice-payments')
    <p class="muted">Dokumen tagihan operasional. Bukan faktur pajak.</p><div class="actions"><a href="/#reservations">Kembali ke reservasi</a><button type="button" class="primary" id="print-invoice">Cetak / simpan PDF</button></div>
</main></body></html>
