@php
    $trackerSummary = $fundingSummary ?? [
        'raised' => $campaign->raised,
        'spent' => $campaign->spent,
        'reserved' => $campaign->reserved,
        'available' => $campaign->available,
    ];
@endphp
<section class="panel" id="penyaluran" data-disbursement-live="campaign" data-status-url="{{ route('campaign.disbursements', $campaign) }}">
    <h2>Lacak Penyaluran Donasi</h2>
    <p class="muted"><small>Menampilkan hingga 20 pengajuan penyaluran terbaru.</small></p>
    <p class="muted">Transfer dilaporkan admin setelah mengirim dana secara manual. Status <strong>Diterima penerima</strong> muncul setelah penerima mengonfirmasi di akunnya.</p>
    <div class="stats" style="margin:20px 0">
        <div class="stat"><strong data-funding="raised">Rp {{ number_format($trackerSummary['raised'] ?? 0, 0, ',', '.') }}</strong>Dana terkumpul</div>
        <div class="stat"><strong data-funding="reserved">Rp {{ number_format($trackerSummary['reserved'] ?? 0, 0, ',', '.') }}</strong>Dalam pengajuan</div>
        <div class="stat"><strong data-funding="spent">Rp {{ number_format($trackerSummary['spent'] ?? 0, 0, ',', '.') }}</strong>Keluar / tercatat</div>
        <div class="stat"><strong data-funding="confirmed">{{ isset($trackerSummary['confirmed']) ? 'Rp '.number_format($trackerSummary['confirmed'], 0, ',', '.') : '—' }}</strong>Dikonfirmasi diterima</div>
        <div class="stat"><strong data-funding="available">Rp {{ number_format($trackerSummary['available'] ?? 0, 0, ',', '.') }}</strong>Tersedia</div>
    </div>
    <p class="muted"><small>Dana keluar mencakup transfer yang dilaporkan dan pembukuan manual. Konfirmasi penerima ditampilkan secara terpisah. Rincian rekening dan bukti transfer hanya dapat diakses pihak terkait.</small></p>
    <div data-disbursement-list>
        @forelse(($disbursements ?? collect()) as $disbursement)
            <article style="padding:20px 0;border-top:1px solid var(--border)">
                <span class="tag">{{ $disbursement->status_label }}</span>
                <h3 style="margin-top:12px">{{ $disbursement->title }}</h3>
                <p><strong>Rp {{ number_format($disbursement->amount, 0, ',', '.') }}</strong> · Penerima: {{ $disbursement->recipient_label }}</p>
                @if($disbursement->description)<p style="white-space:pre-line">{{ $disbursement->description }}</p>@endif
                <p class="muted" style="overflow-wrap:anywhere"><small>Referensi: {{ $disbursement->reference }}</small></p>
                <ol aria-label="Riwayat status {{ $disbursement->title }}">
                    @foreach($disbursement->events as $event)
                        <li>{{ $event->status_label }} <small class="muted">· {{ $event->created_at->format('d M Y H:i') }}</small></li>
                    @endforeach
                </ol>
            </article>
        @empty
            <p>Belum ada pengajuan penyaluran untuk campaign ini.</p>
        @endforelse
    </div>
    <p class="muted" data-live-feedback role="status" aria-live="polite"><small>Status diperbarui otomatis setiap 10 detik selama halaman aktif.</small></p>
    <noscript><p class="muted">Status ditampilkan saat halaman dibuka. Muat ulang halaman untuk melihat perubahan terbaru.</p></noscript>
</section>
@once
    <script src="{{ asset('js/disbursements.js') }}?v={{ filemtime(public_path('js/disbursements.js')) }}" defer></script>
@endonce
