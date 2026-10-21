@extends('layouts.app')
@section('title', 'Sertifikat Donatur')
@section('content')
<div class="wrap section">
    <div class="panel" style="text-align:center;border:12px double #8f1734;max-width:820px;margin:auto;padding:55px 25px">
        <div class="brand">♥ BeneFund</div>
        <span class="tag">MIDTRANS SANDBOX / DATA DEMO</span>
        <h1 style="font-size:40px">Sertifikat Donatur</h1>
        <p>Nomor BF-{{ str_pad($donation->id, 8, '0', STR_PAD_LEFT) }}</p>
        <p>Diberikan kepada</p>
        <h2 style="color:var(--red)">{{ $donation->user->name }}</h2>
        <p>Atas kontribusi sebesar</p>
        <h2>Rp {{ number_format($donation->net_amount, 0, ',', '.') }}</h2>
        @if($donation->refunded_amount > 0)
            <p class="muted">Donasi awal Rp {{ number_format($donation->amount, 0, ',', '.') }} dikurangi pengembalian donasi Rp {{ number_format(min($donation->amount, $donation->refunded_amount), 0, ',', '.') }}.</p>
        @endif
        @if($donation->admin_fee > 0)<p class="muted">Nominal kontribusi tidak termasuk biaya admin pembayaran.</p>@endif
        <p>Untuk campaign <b>{{ $donation->campaign->title }}</b></p>
        <p class="muted">{{ $donation->paid_at->format('d F Y') }} · Tim BeneFund</p>
        <p class="muted" style="overflow-wrap:anywhere">{{ $donation->order_id ?? 'Data donasi demo / transaksi lama' }}</p>
        <button class="btn" onclick="window.print()">Cetak / Simpan PDF</button>
    </div>
</div>
<style>@media print{.nav,.footer,button{display:none!important}.panel{box-shadow:none!important}}</style>
@endsection
