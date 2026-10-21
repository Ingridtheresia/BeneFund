@extends('layouts.app')
@section('title', 'Panel Admin')
@section('content')
<div class="wrap section">
    <h1>Dashboard Admin</h1>
    <div class="stats">
        <div class="stat"><strong>{{ $campaignCount }}</strong>Total Campaign</div>
        <div class="stat"><strong>{{ $users }}</strong>Pengguna</div>
        <div class="stat"><strong>Rp {{ number_format($total, 0, ',', '.') }}</strong>Donasi Bersih</div>
    </div>
    <p class="actions">
        <a class="btn outline" href="{{ route('admin.users') }}">Kelola Pengguna</a>
        <a class="btn outline" href="{{ route('admin.transactions') }}">Semua Transaksi</a>
        <a class="btn outline" href="{{ route('transparency') }}">Laporan</a>
    </p>
    <div class="panel">
        <h2>Campaign Menunggu Verifikasi</h2>
        <div class="table-scroll">
            <table>
                <tr><th>Campaign</th><th>Penggalang</th><th>Target</th><th>Keputusan</th></tr>
                @forelse($pending as $campaign)
                    <tr>
                        <td><a href="{{ route('campaign.show', $campaign) }}">{{ $campaign->title }}</a></td>
                        <td>{{ $campaign->user->name }}</td>
                        <td>Rp {{ number_format($campaign->target, 0, ',', '.') }}</td>
                        <td class="actions">
                            <form method="POST" action="{{ route('admin.approve', $campaign) }}">@csrf<button class="btn small">Setujui</button></form>
                            <form method="POST" action="{{ route('admin.reject', $campaign) }}">@csrf<button class="btn small outline">Tolak</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4">Tidak ada antrian.</td></tr>
                @endforelse
            </table>
        </div>
        {{ $pending->links() }}
    </div>
</div>
@endsection
