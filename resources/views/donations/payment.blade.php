@extends('layouts.app')
@section('title', 'Pembayaran Midtrans Sandbox')
@section('content')
@php
    $channelConfig = config('payments.methods.'.$donation->method.'.channels.'.$donation->payment_channel, []);
    $simulatorUrl = $channelConfig['simulator_url'] ?? null;
@endphp
<div class="wrap section">
    <div class="panel" style="max-width:640px;margin:auto;text-align:center"
         id="payment-panel" data-status-url="{{ route('payments.status', $donation) }}"
         data-poll="{{ $donation->status === 'pending' && $donation->snap_token && $configured ? 'true' : 'false' }}">
        <span class="tag">MIDTRANS SANDBOX</span>
        <h1>Pembayaran Donasi</h1>
        <p>{{ $donation->campaign->title }}</p>
        <h2 style="color:var(--red)">Rp {{ number_format($donation->total_amount, 0, ',', '.') }}</h2>
        <div class="payment-selected-channel">
            @if(!empty($channelConfig['logo']))
                <img src="{{ asset($channelConfig['logo']) }}" alt="" class="payment-logo" data-logo-theme="{{ $channelConfig['logo_theme'] ?? 'default' }}" width="108" height="32">
            @endif
            <p>{{ $donation->payment_channel_label }}</p>
        </div>
        <dl style="text-align:left;margin:24px 0;padding:16px;background:var(--bg, #fff7f7);border-radius:10px">
            <div style="display:flex;justify-content:space-between;gap:12px"><dt>Nominal donasi</dt><dd style="margin:0">Rp {{ number_format($donation->amount, 0, ',', '.') }}</dd></div>
            <div style="display:flex;justify-content:space-between;gap:12px;margin-top:8px"><dt>Biaya admin</dt><dd style="margin:0">{{ $donation->admin_fee > 0 ? 'Rp '.number_format($donation->admin_fee, 0, ',', '.') : 'Gratis' }}</dd></div>
            <div style="display:flex;justify-content:space-between;gap:12px;margin-top:16px"><dt><strong>Total pembayaran</strong></dt><dd style="margin:0"><strong>Rp {{ number_format($donation->total_amount, 0, ',', '.') }}</strong></dd></div>
            @if($donation->refunded_amount > 0)
                <div style="display:flex;justify-content:space-between;gap:12px;margin-top:8px"><dt>Dikembalikan</dt><dd style="margin:0">Rp {{ number_format($donation->refunded_amount, 0, ',', '.') }}</dd></div>
            @endif
        </dl>
        <p class="muted">Biaya admin terpisah dari dana campaign dan nominal pada sertifikat.</p>
        <p><b>Status: <span id="payment-status">{{ $donation->status_label }}</span></b></p>
        @if($donation->order_id)<p class="muted" style="overflow-wrap:anywhere">Order: {{ $donation->order_id }}</p>@endif
        <p class="muted">Pembayaran uji coba. Gunakan simulator Midtrans untuk menyelesaikan transaksi Sandbox.</p>
        <p id="payment-feedback" role="status" aria-live="polite"></p>

        @if(in_array($donation->status, ['paid', 'partially_refunded'], true) && $donation->net_amount > 0)
            <a class="btn" href="{{ route('certificates.show', $donation) }}">Lihat Sertifikat</a>
        @elseif($donation->status === 'pending')
            @if($configured)
                @if(!$donation->expires_at || $donation->expires_at->isFuture())
                    <form method="POST" action="{{ route('payments.checkout', $donation) }}" id="checkout-form">
                        @csrf
                        <button class="btn" id="pay-button" type="submit">{{ $donation->snap_token ? 'Lanjutkan Pembayaran' : 'Bayar via Midtrans' }}</button>
                    </form>
                @else
                    <p>Sesi pembayaran kedaluwarsa. Periksa status terakhir atau buat donasi baru.</p>
                @endif
            @else
                <p>Pembayaran belum tersedia. Hubungi pengelola untuk mengaktifkan Midtrans Sandbox.</p>
            @endif
        @else
            <p>Transaksi ini {{ strtolower($donation->status_label) }}.</p>
        @endif

        @if($donation->order_id && $configured)
            <form method="POST" action="{{ route('payments.status', $donation) }}" style="margin-top:16px">
                @csrf
                <button class="btn outline" type="submit">Periksa Status Pembayaran</button>
            </form>
        @endif
        @if($donation->status === 'pending' && $configured)
            <div style="text-align:left;margin-top:24px">
                <h3>Cara mencoba pembayaran Sandbox</h3>
                <ol>
                    <li>Klik tombol pembayaran di atas untuk membuat transaksi di Midtrans.</li>
                    @if($donation->payment_channel === 'echannel')
                        <li>Salin <strong>company code / kode perusahaan</strong> dan <strong>bill key / kode pembayaran</strong> dari halaman Mandiri di Midtrans. Masukkan keduanya ke kolom yang sesuai pada simulator Mandiri.</li>
                    @elseif($donation->method === 'bank')
                        <li>Salin nomor virtual account atau kode tagihan {{ $donation->payment_channel_label }} dari halaman Midtrans, lalu masukkan ke simulator bank yang sama.</li>
                    @elseif($donation->method === 'ewallet')
                        <li>Di komputer, tampilkan kode QR {{ $donation->payment_channel_label }} pada halaman Midtrans. Klik kanan gambar QR, pilih <strong>Salin alamat gambar</strong>, lalu tempel ke kolom <strong>QRCode Image URL</strong> di simulator. Di ponsel, ikuti tautan pembayaran yang membuka simulator otomatis.</li>
                    @else
                        <li>Tampilkan kode QR pada halaman Midtrans. Klik kanan gambar QR, pilih <strong>Salin alamat gambar</strong>, lalu tempel ke kolom <strong>QRCode Image URL</strong> di simulator QRIS.</li>
                    @endif
                    <li>Setelah simulator menyatakan berhasil, kembali ke halaman ini dan klik <strong>Periksa Status Pembayaran</strong>. Halaman ini juga memeriksa status otomatis.</li>
                </ol>
                @if($simulatorUrl)
                    <p><a class="btn outline" href="{{ $simulatorUrl }}" target="_blank" rel="noopener noreferrer">Buka Simulator {{ $donation->method === 'ewallet' ? 'QR ' : '' }}{{ $donation->payment_channel_label }}</a></p>
                @endif
                <p class="muted"><a href="https://docs.midtrans.com/docs/testing-payment-on-sandbox" target="_blank" rel="noopener noreferrer">Panduan resmi simulator Midtrans</a>. Gunakan total pembayaran Rp {{ number_format($donation->total_amount, 0, ',', '.') }} jika simulator meminta nominal. Untuk QR, masukkan alamat gambar QR dari Midtrans, bukan Order ID. Gunakan simulator Sandbox untuk menyelesaikan pengujian.</p>
            </div>
        @endif
        <p><a href="{{ route('campaign.show', $donation->campaign) }}">Kembali ke campaign</a> · <a href="{{ route('donations.history') }}">Riwayat donasi</a></p>
    </div>
</div>
@if($configured && $donation->status === 'pending')
    <script src="{{ config('midtrans.script_url') }}" data-client-key="{{ config('midtrans.client_key') }}"></script>
    <script src="{{ asset('js/payment.js') }}?v={{ filemtime(public_path('js/payment.js')) }}" defer></script>
@endif
@endsection
