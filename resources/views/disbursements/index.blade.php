@extends('layouts.app')
@section('title', 'Penyaluran Donasi')
@section('content')
<div class="wrap section">
    <div class="section-title">
        <h1>Penyaluran Donasi</h1>
        <a class="btn outline" href="{{ route('dashboard') }}">Dashboard</a>
    </div>
    @if(auth()->user()->role === 'admin')
        <p class="muted">Tinjau pengajuan, lakukan transfer secara manual, lalu unggah bukti. Penerima mengonfirmasi dana melalui akunnya sendiri.</p>
    @else
        <p class="muted">Lihat pengajuan Anda dan dana yang ditujukan kepada Anda. Jika dana sudah masuk ke rekening Anda, buka detail untuk mengonfirmasi penerimaan.</p>
    @endif
    <div class="panel table-scroll">
        <table>
            <thead><tr><th>Pengajuan</th><th>Campaign / Penerima</th><th>Nominal</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
                @forelse($disbursements as $disbursement)
                    <tr>
                        <td>{{ $disbursement->title }}<br><small class="muted">{{ $disbursement->created_at->format('d M Y H:i') }}</small></td>
                        <td><a href="{{ route('campaign.show', $disbursement->campaign) }}">{{ $disbursement->campaign->title }}</a><br><small>{{ $disbursement->recipient_label }}</small>
                            @if(auth()->id() === $disbursement->recipient_user_id)<br><span class="tag">Anda penerima</span>@endif
                        </td>
                        <td>Rp {{ number_format($disbursement->amount, 0, ',', '.') }}</td>
                        <td><span class="tag">{{ $disbursement->status_label }}</span></td>
                        <td><a class="text-link" href="{{ route('disbursements.show', $disbursement) }}">{{ $disbursement->status === 'transferred' && auth()->id() === $disbursement->recipient_user_id ? 'Konfirmasi Penerimaan' : 'Lihat Detail' }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5">Belum ada penyaluran yang dapat Anda akses.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:24px">{{ $disbursements->links() }}</div>
    <p class="muted" style="margin-top:20px">Untuk mengajukan penyaluran, buka campaign yang Anda kelola dan pilih <strong>Salurkan Donasi</strong>.</p>
</div>
@endsection
