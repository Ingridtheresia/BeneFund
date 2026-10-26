@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<div class="wrap section">
    <h1>Halo, {{ auth()->user()->name }}</h1>
    <p class="muted">Dashboard {{ ucfirst(auth()->user()->role) }}</p>
    <div class="actions">
        <a class="btn outline" href="{{ route('notifications.index') }}">Notifikasi</a>
        <a class="btn" href="{{ route('donations.history') }}">Riwayat Donasi</a>
        <a class="btn outline" href="{{ route('disbursements.index') }}">Penyaluran Donasi</a>
        <a class="btn outline" href="{{ route('favorites') }}">Favorit</a>
        <a class="btn outline" href="{{ route('profile') }}">Profil</a>
        @if(in_array(auth()->user()->role, ['fundraiser', 'admin']))<a class="btn" href="{{ route('manage.campaigns') }}">Kelola Campaign</a>@endif
        @if(auth()->user()->role === 'admin')<a class="btn" href="{{ route('admin') }}">Panel Admin</a>@endif
    </div>
    <section class="section">
        <div class="stats">
            <div class="stat"><strong>Rp {{ number_format($donatedTotal, 0, ',', '.') }}</strong>Total Donasi Bersih</div>
            <div class="stat"><strong>{{ $donationCount }}</strong>Donasi Berhasil</div>
            <div class="stat"><strong>{{ $campaignCount }}</strong>Campaign Saya</div>
        </div>
        <div class="panel" style="margin-top:22px">
            <h2>Terima dan Salurkan Dana</h2>
            <p>Jika Anda ditunjuk sebagai penerima, periksa penyaluran yang ditujukan ke akun ini. Setelah admin melaporkan transfer dan dana masuk ke rekening Anda, buka detail untuk mengonfirmasi penerimaan.</p>
            <a class="btn outline" href="{{ route('disbursements.index') }}">Lihat Penyaluran & Penerimaan</a>
        </div>
        <div class="panel" style="margin-top:22px">
            <h2>Aktivitas Donasi Terbaru</h2>
            <div class="table-scroll">
                <table>
                    <tr><th>Campaign</th><th>Nominal</th><th>Status</th></tr>
                    @forelse($donations as $donation)
                        <tr><td>{{ $donation->campaign->title }}</td><td>Rp {{ number_format($donation->amount, 0, ',', '.') }}</td><td>{{ $donation->status }}</td></tr>
                    @empty
                        <tr><td colspan="3">Belum ada donasi.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </section>
</div>
@endsection
