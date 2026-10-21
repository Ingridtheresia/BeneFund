@extends('layouts.app')
@section('title', $campaign->title)
@section('content')
<div class="wrap section">
    <div class="two">
        <div class="stack">
            <img class="cover" src="{{ $campaign->image_url ?: asset('images/campaign-placeholder.svg') }}" alt="Foto {{ $campaign->title }}">
            <div class="panel">
                <span class="tag">{{ $campaign->category }}</span>
                <h1>{{ $campaign->title }}</h1>
                <p class="muted">{{ $campaign->location ?: 'Indonesia' }} &bull; Penggalang: {{ $campaign->user->name }}</p>
                @if($campaign->status !== 'approved')
                    <p role="status">Status: <strong>{{ $campaign->status }}</strong>. Pratinjau ini hanya tersedia bagi pemilik dan admin. Perubahan membutuhkan verifikasi sebelum campaign ditampilkan kepada publik.</p>
                @endif
                <p style="white-space:pre-line;line-height:1.8">{{ $campaign->description }}</p>
            </div>
            <div class="panel">
                <h2>Update Kegiatan</h2>
                @forelse($updates as $update)
                    <h3>{{ $update->title }}</h3>
                    <small class="muted">{{ $update->created_at->format('d M Y') }}</small>
                    <p style="white-space:pre-line">{{ $update->body }}</p>
                    <hr>
                @empty
                    <p>Belum ada update.</p>
                @endforelse
                {{ $updates->links() }}
            </div>
            @include('disbursements.public-tracker')
            <div class="panel">
                <h2>Catatan Pembukuan Manual</h2>
                <p class="muted">Catatan pengeluaran dari pembukuan. Catatan ini tidak menyatakan bahwa penerima sudah mengonfirmasi dana. Lihat pelacakan penyaluran di atas untuk memeriksa konfirmasi penerima.</p>
                <div class="table-scroll">
                    <table>
                        <tr><th>Tanggal</th><th>Keterangan</th><th>Nominal</th></tr>
                        @forelse($expenses as $expense)
                            <tr>
                                <td>{{ $expense->spent_at->format('d M Y') }}</td>
                                <td>{{ $expense->title }} @if($expense->description)&mdash; {{ $expense->description }}@endif</td>
                                <td>Rp {{ number_format($expense->amount, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3">Belum ada catatan pembukuan manual.</td></tr>
                        @endforelse
                    </table>
                </div>
                {{ $expenses->links() }}
            </div>
            @auth
                @if(auth()->id() === $campaign->user_id || auth()->user()->role === 'admin')
                    <div class="panel">
                        <h2>Kelola Campaign</h2>
                        <p><a href="{{ route('manage.edit', $campaign) }}">Edit campaign dan ajukan verifikasi</a></p>
                        @if($campaign->status === 'approved')
                            <form method="POST" action="{{ route('manage.updates', $campaign) }}">
                                @csrf
                                <div class="field"><label for="update-title">Judul Update</label><input id="update-title" name="title" maxlength="150" required></div>
                                <div class="field"><label for="update-body">Isi Update</label><textarea id="update-body" name="body" maxlength="5000" required></textarea></div>
                                <button class="btn">Terbitkan Update</button>
                            </form>
                            <hr>
                            <h3>Salurkan Dana ke Penerima</h3>
                            <p>Ajukan penyaluran untuk diperiksa admin. Admin melakukan transfer manual dan mengunggah bukti, lalu penerima mengonfirmasi dana dari akunnya.</p>
                            <div class="actions">
                                <a class="btn" href="{{ route('disbursements.create', $campaign) }}">Salurkan Donasi</a>
                                <a class="btn outline" href="{{ route('disbursements.index') }}">Kelola Penyaluran</a>
                            </div>
                        @else
                            <p>Update kegiatan dan pengajuan penyaluran tersedia setelah campaign disetujui.</p>
                        @endif
                    </div>
                @endif
            @endauth
        </div>
        <aside>
            <div class="panel">
                <h2>Donasi Sekarang</h2>
                <h2 style="color:var(--red)">Rp {{ number_format($campaign->raised, 0, ',', '.') }}</h2>
                <div class="progress"><span style="width:{{ $campaign->percent }}%"></span></div>
                <p class="muted">{{ $campaign->percent }}% dari Rp {{ number_format($campaign->target, 0, ',', '.') }}</p>
                <p>Deadline: {{ $campaign->deadline->format('d M Y') }}</p>
                @auth
                    @if($campaign->is_open)
                        @php
                            $paymentMethods = config('payments.methods', []);
                            $requestedMethod = old('method', 'qris');
                            $selectedMethod = is_string($requestedMethod) && isset($paymentMethods[$requestedMethod]) ? $requestedMethod : 'qris';
                            $selectedChannel = old('payment_channel', array_key_first($paymentMethods[$selectedMethod]['channels'] ?? []));
                            $estimatedDonation = max(0, (int) old('amount', 100000));
                            $estimatedFee = (int) ($paymentMethods[$selectedMethod]['fee'] ?? 0);
                        @endphp
                        <form method="POST" action="{{ route('donations.store', $campaign) }}" id="donation-form">
                            @csrf
                            <div class="field"><label for="donation-amount">Nominal Donasi (Rp)</label><input id="donation-amount" type="number" name="amount" value="{{ old('amount', 100000) }}" min="1000" max="1000000000" step="1" required></div>
                            <div class="field">
                                <label for="donation-method">Metode Pembayaran</label>
                                <select id="donation-method" name="method" required aria-describedby="donation-fee-note">
                                    @foreach($paymentMethods as $method => $details)
                                        <option value="{{ $method }}" @selected($selectedMethod === $method)>{{ $details['label'] }} — {{ $details['fee'] > 0 ? 'admin Rp '.number_format($details['fee'], 0, ',', '.') : 'gratis biaya admin' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field" id="donation-channel-field">
                                <label for="donation-channel" id="donation-channel-label">Bank / E-Wallet / QRIS</label>
                                <select id="donation-channel" name="payment_channel" required aria-describedby="donation-channel-note">
                                    @foreach($paymentMethods as $method => $details)
                                        <optgroup label="{{ $details['label'] }}" data-method="{{ $method }}">
                                            @foreach($details['channels'] as $channel => $channelDetails)
                                                <option value="{{ $channel }}" data-method="{{ $method }}" @selected($selectedChannel === $channel)>{{ $channelDetails['label'] }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                                <small class="muted" id="donation-channel-note">Pilih bank atau e-wallet yang sesuai dengan metode pembayaran.</small>
                            </div>
                            <div aria-live="polite" aria-atomic="true" style="padding:16px;background:var(--bg, #fff7f7);border-radius:10px;margin-bottom:16px">
                                <div style="display:flex;justify-content:space-between;gap:12px"><span>Donasi</span><span id="donation-estimate">Rp {{ number_format($estimatedDonation, 0, ',', '.') }}</span></div>
                                <div style="display:flex;justify-content:space-between;gap:12px;margin-top:8px"><span>Biaya admin</span><span id="donation-fee">{{ $estimatedFee > 0 ? 'Rp '.number_format($estimatedFee, 0, ',', '.') : 'Gratis' }}</span></div>
                                <hr>
                                <div style="display:flex;justify-content:space-between;gap:12px"><strong>Total Pembayaran</strong><strong id="donation-total">Rp {{ number_format($estimatedDonation + $estimatedFee, 0, ',', '.') }}</strong></div>
                            </div>
                            <p class="muted" id="donation-fee-note">Biaya admin ditambahkan di luar nominal donasi. Bank Rp 4.500, e-wallet Rp 1.500, dan QRIS gratis. Pembayaran melalui Midtrans Sandbox.</p>
                            <noscript><p class="muted">Rincian di atas mengikuti pilihan awal. Total akhir sesuai nominal dan metode pilihan Anda ditampilkan pada halaman pembayaran.</p></noscript>
                            <div class="field"><label for="donation-message">Pesan Dukungan</label><textarea id="donation-message" name="message" maxlength="500">{{ old('message') }}</textarea></div>
                            <label><input type="checkbox" name="anonymous" value="1" style="width:auto" @checked(old('anonymous'))> Sembunyikan nama saya</label>
                            <br><br><button class="btn" style="width:100%">Lanjut Donasi &rarr;</button>
                        </form>
                        <script type="application/json" id="donation-payment-methods">@json($paymentMethods)</script>
                        <script src="{{ asset('js/donation-form.js') }}?v={{ filemtime(public_path('js/donation-form.js')) }}" defer></script>
                    @else
                        <p>Donasi sedang tidak tersedia.</p>
                    @endif
                    @if($campaign->status === 'approved')
                        <form method="POST" action="{{ route('favorite', $campaign) }}">
                            @csrf<br><button class="btn outline" style="width:100%">Simpan / Hapus Favorit</button>
                        </form>
                    @endif
                @else
                    @if($campaign->is_open)
                        <a class="btn" href="{{ route('login') }}">Masuk untuk Berdonasi</a>
                    @else
                        <p>Donasi telah berakhir.</p>
                    @endif
                @endauth
            </div>
        </aside>
    </div>
</div>
@endsection
