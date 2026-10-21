@extends('layouts.app')
@section('title', 'Transparansi')
@section('content')
@php
    $summary = $fundingSummary ?? $summary ?? ['raised' => $raised, 'spent' => $spent, 'reserved' => $reserved ?? 0, 'available' => $available ?? max(0, $raised - $spent), 'confirmed' => $confirmed ?? 0];
@endphp
<div class="wrap section" data-disbursement-live="transparency" data-status-url="{{ route('transparency.status', ['page' => request('page', 1)]) }}">
    <h1>Transparansi &amp; Laporan Dana</h1>
    <p class="muted">Laporan campaign yang telah disetujui. Pemasukan dihitung dari pembayaran berhasil setelah pengembalian dana.</p>
    <div class="stats">
        <div class="stat"><strong data-funding="raised">Rp {{ number_format($summary['raised'], 0, ',', '.') }}</strong>Dana Terkumpul</div>
        <div class="stat"><strong data-funding="reserved">Rp {{ number_format($summary['reserved'], 0, ',', '.') }}</strong>Dalam Pengajuan</div>
        <div class="stat"><strong data-funding="spent">Rp {{ number_format($summary['spent'], 0, ',', '.') }}</strong>Keluar / Tercatat</div>
        <div class="stat"><strong data-funding="confirmed">Rp {{ number_format($summary['confirmed'], 0, ',', '.') }}</strong>Dikonfirmasi Diterima</div>
        <div class="stat"><strong data-funding="available">Rp {{ number_format($summary['available'], 0, ',', '.') }}</strong>Tersedia</div>
    </div>
    <p class="muted" style="margin-top:20px"><strong>Transfer dilaporkan</strong> berarti admin sudah mencatat transfer manual beserta bukti. <strong>Diterima penerima</strong> berarti penerima sudah mengonfirmasi dana masuk. Dana keluar juga mencakup catatan pembukuan manual.</p>
    <section class="section"><div class="panel"><h2>Perbandingan Campaign pada Halaman Ini</h2><canvas id="chart" height="95"></canvas></div></section>
    <div class="panel table-scroll">
        <table>
            <tr><th>Campaign</th><th>Masuk</th><th>Dalam Pengajuan</th><th>Keluar / Tercatat</th><th>Dikonfirmasi Diterima</th><th>Tersedia</th></tr>
            @forelse($campaigns as $campaign)
                <tr data-campaign-row="{{ $campaign->id }}">
                    <td><a class="text-link" href="{{ route('campaign.show', $campaign) }}#penyaluran">{{ $campaign->title }}</a></td>
                    <td data-campaign-funding="raised">Rp {{ number_format($campaign->raised, 0, ',', '.') }}</td>
                    <td data-campaign-funding="reserved">Rp {{ number_format($campaign->reserved, 0, ',', '.') }}</td>
                    <td data-campaign-funding="spent">Rp {{ number_format($campaign->spent, 0, ',', '.') }}</td>
                    <td data-campaign-funding="confirmed">Rp {{ number_format($campaign->confirmed, 0, ',', '.') }}</td>
                    <td data-campaign-funding="available">Rp {{ number_format($campaign->available, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="6">Belum ada campaign yang disetujui.</td></tr>
            @endforelse
        </table>
    </div>
    {{ $campaigns->links() }}
    <p class="muted" data-live-feedback role="status" aria-live="polite" style="margin-top:20px">Status diperbarui otomatis setiap 10 detik selama halaman aktif.</p>
    <noscript><p class="muted">Muat ulang halaman untuk melihat pembaruan dana dan penyaluran.</p></noscript>
</div>
<script>
if (typeof Chart !== 'undefined') window.disbursementChart = new Chart(document.getElementById('chart'), {
    type: 'bar',
    data: {
        labels: {{ Illuminate\Support\Js::from($campaigns->pluck('title')) }},
        datasets: [
            {label: 'Donasi Berhasil', data: {{ Illuminate\Support\Js::from($campaigns->getCollection()->map(fn ($campaign) => $campaign->raised)->values()) }}, backgroundColor: '#a50021'},
            {label: 'Keluar / tercatat', data: {{ Illuminate\Support\Js::from($campaigns->getCollection()->map(fn ($campaign) => $campaign->spent)->values()) }}, backgroundColor: '#e85d75'},
            {label: 'Dikonfirmasi diterima', data: {{ Illuminate\Support\Js::from($campaigns->getCollection()->map(fn ($campaign) => $campaign->confirmed)->values()) }}, backgroundColor: '#51906b'}
        ]
    },
    options: {responsive: true}
});
</script>
<script src="{{ asset('js/disbursements.js') }}?v={{ filemtime(public_path('js/disbursements.js')) }}" defer></script>
@endsection
