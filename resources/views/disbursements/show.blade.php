@extends('layouts.app')
@section('title', 'Detail Penyaluran Donasi')
@section('content')
@php
    $isAdmin = auth()->user()->role === 'admin';
    $isRequester = auth()->id() === $disbursement->requested_by;
    $isRecipient = auth()->id() === $disbursement->recipient_user_id;
@endphp
<div class="wrap section" data-disbursement-live="detail" data-status-url="{{ route('disbursements.status', $disbursement) }}" data-initial-status="{{ $disbursement->status }}">
    <div class="section-title"><h1>Detail Penyaluran Donasi</h1><a class="btn outline" href="{{ route('disbursements.index') }}">Semua Penyaluran</a></div>
    <div class="alert" data-status-changed hidden role="status">Status penyaluran berubah. <a class="text-link" href="{{ route('disbursements.show', $disbursement) }}">Muat ulang detail</a> untuk melihat bukti dan tindakan terbaru.</div>
    <div class="two">
        <div class="stack">
            <section class="panel">
                <span class="tag" data-current-status>{{ $disbursement->status_label }}</span>
                <h2 style="margin-top:16px">{{ $disbursement->title }}</h2>
                <p><a class="text-link" href="{{ route('campaign.show', $disbursement->campaign) }}">{{ $disbursement->campaign->title }}</a></p>
                <h2 style="color:var(--red)">Rp {{ number_format($disbursement->amount, 0, ',', '.') }}</h2>
                <p>Penerima publik: <strong>{{ $disbursement->recipient_label }}</strong></p>
                @if($disbursement->description)<p style="white-space:pre-line">{{ $disbursement->description }}</p>@endif
                <p class="muted" style="overflow-wrap:anywhere">Referensi: {{ $disbursement->reference }}</p>
                @if($disbursement->reason)<p style="white-space:pre-line"><strong>Alasan {{ $disbursement->status === 'rejected' ? 'penolakan' : 'pembatalan' }}:</strong> {{ $disbursement->reason }}</p>@endif
                <p class="muted">Transfer dilakukan admin secara manual di luar aplikasi. Bukti transfer menunjukkan laporan admin; dana baru berstatus diterima setelah penerima mengonfirmasi.</p>
            </section>
            <section class="panel">
                <h2>Rekening Penerima</h2>
                <dl>
                    <dt>Bank</dt><dd style="margin:0 0 12px">{{ $disbursement->recipient_bank }}</dd>
                    <dt>Nama pemilik rekening</dt><dd style="margin:0 0 12px">{{ $disbursement->recipient_account_name }}</dd>
                    <dt>Nomor rekening</dt><dd style="margin:0 0 12px;overflow-wrap:anywhere">{{ $disbursement->recipient_account_number }}</dd>
                    <dt>Akun penerima konfirmasi</dt><dd style="margin:0">{{ $disbursement->recipient->name }} · {{ $disbursement->recipient->email }}</dd>
                </dl>
                @if($disbursement->transfer_reference)<p>Referensi transfer: <strong style="overflow-wrap:anywhere">{{ $disbursement->transfer_reference }}</strong></p>@endif
                @if($disbursement->transferred_at)<p>Transfer dilaporkan: {{ $disbursement->transferred_at->format('d M Y H:i') }}</p>@endif
                @if($disbursement->proof_path)<p><a class="btn outline" href="{{ route('disbursements.proof', $disbursement) }}">Unduh Bukti Transfer</a></p>@endif
                @if($disbursement->received_at)<p>Dikonfirmasi penerima: <strong>{{ $disbursement->received_at->format('d M Y H:i') }}</strong></p>@endif
                <p class="muted"><small>Data rekening dan bukti ini hanya tersedia bagi pihak terkait serta admin.</small></p>
            </section>
            <section class="panel">
                <h2>Riwayat Status</h2>
                <ol>
                    @foreach($disbursement->events as $event)
                        <li style="margin-bottom:14px"><strong>{{ $event->status_label }}</strong><br><small class="muted">{{ $event->created_at->format('d M Y H:i') }}@if($event->actor_role) · {{ ucfirst($event->actor_role) }}@endif</small>@if($event->note)<p style="white-space:pre-line;margin:8px 0">{{ $event->note }}</p>@endif</li>
                    @endforeach
                </ol>
            </section>
            <section class="panel">
                <h3>Bagikan ke Penerima</h3>
                <p class="muted">Penerima juga dapat membuka penyaluran ini dari dashboard setelah masuk dengan akun yang dituju.</p>
                <label for="disbursement-share-url">Tautan Detail</label>
                <input id="disbursement-share-url" type="url" value="{{ route('disbursements.show', $disbursement) }}" readonly>
                <button class="btn outline" type="button" data-copy-disbursement-url style="margin-top:12px">Salin Tautan</button>
                <p class="muted" data-copy-feedback role="status" aria-live="polite" style="margin-top:12px"></p>
            </section>
        </div>
        <aside class="stack" style="align-content:start">
            <section class="panel">
                <h2>Tindakan</h2>
                @if($isAdmin && !$isRecipient && $disbursement->status === 'requested')
                    <p>Periksa tujuan penyaluran dan rekening penerima sebelum menyetujui.</p>
                    <form method="POST" action="{{ route('disbursements.approve', $disbursement) }}">@csrf<button class="btn" type="submit">Setujui Pengajuan</button></form>
                    <hr>
                    <form method="POST" action="{{ route('disbursements.reject', $disbursement) }}">@csrf<div class="field"><label for="reject-reason">Alasan Penolakan</label><textarea id="reject-reason" name="reason" maxlength="1000" required>{{ old('reason') }}</textarea></div><button class="btn outline" type="submit">Tolak Pengajuan</button></form>
                @elseif($isAdmin && !$isRecipient && $disbursement->status === 'approved')
                    <p>Lakukan transfer dari bank Anda ke rekening penerima yang tercantum. Setelah transfer berhasil, catat referensi dan unggah bukti di bawah.</p>
                    <form method="POST" action="{{ route('disbursements.transfer', $disbursement) }}" enctype="multipart/form-data">
                        @csrf
                        <div class="field"><label for="transfer-reference">Nomor / Referensi Transfer</label><input id="transfer-reference" name="transfer_reference" maxlength="120" value="{{ old('transfer_reference') }}" required></div>
                        <div class="field"><label for="transferred-at">Waktu Transfer</label><input id="transferred-at" type="datetime-local" name="transferred_at" value="{{ old('transferred_at', now()->format('Y-m-d\TH:i')) }}" max="{{ now()->format('Y-m-d\TH:i') }}"></div>
                        <div class="field"><label for="transfer-proof">Bukti Transfer</label><input id="transfer-proof" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf" required aria-describedby="transfer-proof-note"><small class="muted" id="transfer-proof-note">JPG, PNG, WebP, atau PDF. Maksimal 5 MB. Bukti hanya dapat diakses pihak terkait.</small></div>
                        <button class="btn" type="submit">Catat Transfer & Unggah Bukti</button>
                    </form>
                @elseif($isRecipient && $disbursement->status === 'transferred')
                    <p>Periksa mutasi rekening Anda. Konfirmasi hanya setelah dana <strong>Rp {{ number_format($disbursement->amount, 0, ',', '.') }}</strong> benar-benar masuk.</p>
                    @if($disbursement->proof_path)<p><a class="text-link" href="{{ route('disbursements.proof', $disbursement) }}">Unduh bukti dari admin</a></p>@endif
                    <form method="POST" action="{{ route('disbursements.receive', $disbursement) }}">
                        @csrf
                        <label style="line-height:1.7"><input type="checkbox" name="confirmed" value="1" required @checked(old('confirmed'))> Saya telah memeriksa rekening dan dana tersebut sudah saya terima.</label>
                        <button class="btn" type="submit" style="margin-top:16px">Konfirmasi Dana Diterima</button>
                    </form>
                @elseif($disbursement->status === 'requested')
                    <p>Menunggu pemeriksaan admin.</p>
                @elseif($disbursement->status === 'approved')
                    <p>Pengajuan disetujui. Menunggu admin melakukan transfer dan mengunggah bukti.</p>
                @elseif($disbursement->status === 'transferred')
                    <p>Transfer sudah dilaporkan admin. Menunggu penerima memeriksa rekening dan mengonfirmasi dari akunnya sendiri.</p>
                @elseif($disbursement->status === 'received')
                    <p>Penerima telah mengonfirmasi dana masuk ke rekeningnya.</p>
                @else
                    <p>Pengajuan ini {{ strtolower($disbursement->status_label) }}.</p>
                @endif
                @if(($isRequester || $isAdmin) && in_array($disbursement->status, ['requested', 'approved'], true))
                    <hr>
                    <form method="POST" action="{{ route('disbursements.cancel', $disbursement) }}">@csrf<div class="field"><label for="cancel-reason">Alasan Pembatalan</label><textarea id="cancel-reason" name="reason" maxlength="1000" required>{{ old('reason') }}</textarea></div><button class="btn outline" type="submit">Batalkan Pengajuan</button></form>
                @endif
            </section>
            @if(isset($fundingSummary))
                <section class="panel"><h3>Dana Campaign</h3><p>Tersedia: <strong data-funding="available">Rp {{ number_format($fundingSummary['available'], 0, ',', '.') }}</strong></p><p>Dalam pengajuan: <strong data-funding="reserved">Rp {{ number_format($fundingSummary['reserved'], 0, ',', '.') }}</strong></p><p>Dikonfirmasi diterima: <strong data-funding="confirmed">Rp {{ number_format($fundingSummary['confirmed'], 0, ',', '.') }}</strong></p></section>
            @endif
        </aside>
    </div>
    <p class="muted" data-live-feedback role="status" aria-live="polite" style="margin-top:20px">Status diperbarui otomatis setiap 10 detik selama halaman aktif.</p>
    <noscript><p class="muted">Muat ulang halaman untuk melihat perubahan status terbaru.</p></noscript>
</div>
<script src="{{ asset('js/disbursements.js') }}?v={{ filemtime(public_path('js/disbursements.js')) }}" defer></script>
@endsection
