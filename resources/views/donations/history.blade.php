@extends('layouts.app')
@section('title', 'Riwayat Transaksi')
@section('content')
<div class="wrap section">
    <h1>Riwayat Transaksi</h1>
    <div class="panel table-scroll">
        <table>
            <thead><tr><th>Tanggal / Order</th><th>Campaign</th><th>Donatur</th><th>Nominal</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse($donations as $d)
                <tr>
                    <td>{{ $d->created_at->format('d M Y H:i') }}<br><small>{{ $d->order_id ?? 'Data demo / transaksi lama' }}</small></td>
                    <td>{{ $d->campaign->title }}</td>
                    <td>{{ $d->anonymous ? 'Anonim' : $d->user->name }}</td>
                    <td>Donasi: Rp {{ number_format($d->amount, 0, ',', '.') }}
                        <br><small>Admin: {{ $d->admin_fee > 0 ? 'Rp '.number_format($d->admin_fee, 0, ',', '.') : 'Gratis' }}</small>
                        <br><small>Total: Rp {{ number_format($d->total_amount, 0, ',', '.') }}</small>
                        @if($d->refunded_amount > 0)<br><small>Dikembalikan: Rp {{ number_format($d->refunded_amount, 0, ',', '.') }}</small>@endif
                    </td>
                    <td>{{ $d->status_label }}<br><small>{{ $d->payment_channel_label }}</small></td>
                    <td>
                        @if(in_array($d->status, ['paid', 'partially_refunded'], true) && $d->net_amount > 0)
                            <a href="{{ route('certificates.show', $d) }}">Sertifikat</a><br>
                        @endif
                        <a href="{{ route('payments.show', $d) }}">{{ $d->status === 'pending' ? 'Bayar / Periksa Status' : 'Detail' }}</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">Belum ada transaksi donasi.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <br>{{ $donations->links() }}
</div>
@endsection
