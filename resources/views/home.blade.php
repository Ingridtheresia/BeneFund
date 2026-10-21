@extends('layouts.app')
@section('title', 'Beranda')
@section('content')
    <section class="hero" aria-labelledby="hero-title">
        <div class="wrap">
            <div class="hero-content">
                <span class="pill"><span aria-hidden="true">✦</span> Transparan · Aman · Berdampak</span>
                <h1 id="hero-title">Bersama Kita Hadirkan <span>Lebih Banyak Kebaikan</span></h1>
                <p>Bantu sesama dengan mudah dan pantau bagaimana setiap donasi memberikan perubahan nyata.</p>
                <div class="actions">
                    <a class="btn light" href="{{ route('campaigns') }}">Mulai Berdonasi <span aria-hidden="true">→</span></a>
                    <a class="btn hero-secondary" href="{{ route('manage.create') }}">Buat Campaign</a>
                </div>
            </div>
        </div>
    </section>

    <div class="wrap home-content">
        <div class="stats home-stats" aria-label="Dampak kebaikan bersama">
            <div class="stat"><strong>{{ $campaigns->count() }}+</strong><span>Campaign Pilihan</span></div>
            <div class="stat"><strong>{{ $donors }}</strong><span>Donatur</span></div>
            <div class="stat"><strong>Rp {{ number_format($total, 0, ',', '.') }}</strong><span>Donasi Terkumpul</span></div>
        </div>

        <section class="section" aria-labelledby="campaigns-title">
            <div class="section-title">
                <div>
                    <p class="eyebrow">LANGKAH KECIL, DAMPAK NYATA</p>
                    <h2 id="campaigns-title">Campaign Pilihan</h2>
                </div>
                <a class="text-link" href="{{ route('campaigns') }}">Lihat Semua <span aria-hidden="true">→</span></a>
            </div>
            <div class="grid">
                @foreach($campaigns as $campaign)
                    @include('components.campaign-card')
                @endforeach
            </div>
        </section>

        <section class="transparency-section" aria-labelledby="transparency-title">
            <div class="panel transparency-callout">
                <div>
                    <p class="eyebrow">KEBAIKAN YANG TERBUKA</p>
                    <h2 id="transparency-title">Setiap Rupiah Punya Cerita</h2>
                    <p class="muted">Ikuti progress campaign, lihat update penggalang, dan periksa laporan penyaluran dana yang dipublikasikan.</p>
                </div>
                <a class="btn" href="{{ route('transparency') }}">Lihat Transparansi <span aria-hidden="true">→</span></a>
            </div>
        </section>
    </div>
@endsection
