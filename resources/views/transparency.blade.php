@extends('layouts.app')
@section('title', 'Transparansi')
@section('content')
<div class="wrap section">
    <h1>Transparansi &amp; Laporan Dana</h1>
    <p class="muted">Laporan campaign yang telah disetujui. Pemasukan dihitung dari pembayaran berhasil setelah pengembalian dana.</p>
    <div class="stats">
        <div class="stat"><strong>Rp {{ number_format($raised, 0, ',', '.') }}</strong>Dana Terkumpul</div>
        <div class="stat"><strong>Rp {{ number_format($spent, 0, ',', '.') }}</strong>Dana Disalurkan</div>
        <div class="stat"><strong>Rp {{ number_format($raised - $spent, 0, ',', '.') }}</strong>Sisa Dana</div>
    </div>
    <section class="section"><div class="panel"><h2>Perbandingan Campaign pada Halaman Ini</h2><canvas id="chart" height="95"></canvas></div></section>
    <div class="panel table-scroll">
        <table>
            <tr><th>Campaign</th><th>Masuk</th><th>Disalurkan</th><th>Sisa</th></tr>
            @forelse($campaigns as $campaign)
                <tr>
                    <td><a href="{{ route('campaign.show', $campaign) }}">{{ $campaign->title }}</a></td>
                    <td>Rp {{ number_format($campaign->raised, 0, ',', '.') }}</td>
                    <td>Rp {{ number_format($campaign->spent, 0, ',', '.') }}</td>
                    <td>Rp {{ number_format($campaign->available, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="4">Belum ada campaign yang disetujui.</td></tr>
            @endforelse
        </table>
    </div>
    {{ $campaigns->links() }}
</div>
<script>
new Chart(document.getElementById('chart'), {
    type: 'bar',
    data: {
        labels: {{ Illuminate\Support\Js::from($campaigns->pluck('title')) }},
        datasets: [
            {label: 'Donasi Berhasil', data: {{ Illuminate\Support\Js::from($campaigns->getCollection()->map(fn ($campaign) => $campaign->raised)->values()) }}, backgroundColor: '#a50021'},
            {label: 'Penyaluran', data: {{ Illuminate\Support\Js::from($campaigns->getCollection()->map(fn ($campaign) => $campaign->spent)->values()) }}, backgroundColor: '#e85d75'}
        ]
    },
    options: {responsive: true}
});
</script>
@endsection
