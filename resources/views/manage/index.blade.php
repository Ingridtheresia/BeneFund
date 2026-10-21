@extends('layouts.app')
@section('title', 'Kelola Campaign')
@section('content')
<div class="wrap section">
    <div class="section-title"><h1>{{ auth()->user()->role === 'admin' ? 'Semua Campaign' : 'Campaign Saya' }}</h1><a class="btn" href="{{ route('manage.create') }}">+ Buat Campaign</a></div>
    <div class="panel table-scroll">
        <table>
            <tr><th>Campaign</th><th>Target</th><th>Terkumpul</th><th>Status</th><th>Aksi</th></tr>
            @forelse($campaigns as $campaign)
                <tr>
                    <td>{{ $campaign->title }}</td>
                    <td>Rp {{ number_format($campaign->target, 0, ',', '.') }}</td>
                    <td>Rp {{ number_format($campaign->raised, 0, ',', '.') }}</td>
                    <td>{{ $campaign->status }}</td>
                    <td><a href="{{ route('manage.edit', $campaign) }}">Edit</a> &bull; <a href="{{ route('campaign.show', $campaign) }}">Detail</a></td>
                </tr>
            @empty
                <tr><td colspan="5">Belum ada campaign.</td></tr>
            @endforelse
        </table>
    </div>
    {{ $campaigns->links() }}
</div>
@endsection
