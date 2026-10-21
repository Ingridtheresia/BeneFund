@extends('layouts.app')
@section('title', 'Ajukan Penyaluran Donasi')
@section('content')
<div class="wrap section">
    <div class="panel" style="max-width:820px;margin:auto">
        <span class="tag">{{ $campaign->title }}</span>
        <h1 style="margin-top:16px">Ajukan Penyaluran Donasi</h1>
        <p>Admin melakukan transfer bank secara manual di luar aplikasi, kemudian mengunggah bukti. Penerima memeriksa dana di rekeningnya dan mengonfirmasi penerimaan.</p>
        <p class="muted">Dana tersedia untuk pengajuan: <strong>Rp {{ number_format($campaign->available, 0, ',', '.') }}</strong>. Saldo mengikuti pencatatan transaksi aplikasi; pengajuan ini tidak mengirim uang secara otomatis.</p>
        @if($campaign->available < 1000)<p class="alert" role="status">Dana tersedia belum mencukupi untuk pengajuan minimal Rp 1.000.</p>@endif
        <form method="POST" action="{{ route('disbursements.store', $campaign) }}">
            @csrf
            <input type="hidden" name="request_key" value="{{ old('request_key', $requestKey ?? (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="field"><label for="disbursement-title">Tujuan Penyaluran</label><input id="disbursement-title" name="title" maxlength="150" value="{{ old('title') }}" required placeholder="Contoh: Bantuan kebutuhan keluarga tahap pertama"></div>
            <div class="field"><label for="disbursement-amount">Nominal (Rp)</label><input id="disbursement-amount" type="number" name="amount" min="1000" max="{{ max(0, $campaign->available) }}" step="1" value="{{ old('amount') }}" required></div>
            <div class="field"><label for="recipient-label">Nama Penerima untuk Laporan Publik</label><input id="recipient-label" name="recipient_label" maxlength="150" value="{{ old('recipient_label') }}" required aria-describedby="recipient-label-note"><small class="muted" id="recipient-label-note">Gunakan nama kelompok atau sebutan yang aman dipublikasikan, misalnya “Keluarga penerima bantuan Desa Mekar”.</small></div>
            <div class="field"><label for="disbursement-description">Keterangan untuk Laporan Publik</label><textarea id="disbursement-description" name="description" maxlength="2000" aria-describedby="description-note">{{ old('description') }}</textarea><small class="muted" id="description-note">Jelaskan penggunaan dana. Hindari nomor rekening, alamat lengkap, dan data pribadi.</small></div>
            <hr>
            <h2>Data Penerima dan Rekening</h2>
            <p class="muted">Bagian ini hanya tersedia bagi pengaju, penerima, dan admin.</p>
            <div class="field"><label for="recipient-email">Email Akun BeneFund Penerima</label><input id="recipient-email" type="email" name="recipient_email" value="{{ old('recipient_email') }}" required aria-describedby="recipient-email-note"><small class="muted" id="recipient-email-note">Penerima harus sudah memiliki akun BeneFund dan berbeda dari pengaju. Penerima dapat membuka menu Penyaluran Donasi setelah masuk.</small></div>
            <div class="field"><label for="recipient-bank">Bank Penerima</label><input id="recipient-bank" name="recipient_bank" maxlength="100" list="recipient-banks" value="{{ old('recipient_bank') }}" required><datalist id="recipient-banks"><option value="BCA"><option value="BNI"><option value="BRI"><option value="Mandiri"><option value="Bank Syariah Indonesia"><option value="CIMB Niaga"><option value="Permata"><option value="BTN"></datalist></div>
            <div class="field"><label for="recipient-account-name">Nama Pemilik Rekening</label><input id="recipient-account-name" name="recipient_account_name" maxlength="150" value="{{ old('recipient_account_name') }}" required autocomplete="off"></div>
            <div class="field"><label for="recipient-account-number">Nomor Rekening</label><input id="recipient-account-number" type="text" inputmode="numeric" pattern="[0-9]{6,30}" name="recipient_account_number" minlength="6" maxlength="30" value="{{ old('recipient_account_number') }}" required autocomplete="off" aria-describedby="account-number-note"><small class="muted" id="account-number-note">6–30 digit angka. Pastikan bank, nama, dan nomor rekening sesuai sebelum mengajukan.</small></div>
            <div class="actions"><button class="btn" type="submit" @disabled($campaign->available < 1000)>Ajukan Penyaluran</button><a class="btn outline" href="{{ route('campaign.show', $campaign) }}">Kembali ke Campaign</a></div>
        </form>
    </div>
</div>
@endsection
