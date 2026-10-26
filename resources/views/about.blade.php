@extends('layouts.app')
@section('title', 'Tentang Kami')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/about.css') }}?v={{ filemtime(public_path('css/about.css')) }}">
@endpush
@section('content')
    <section class="about-hero" aria-labelledby="about-title">
        <div class="wrap about-hero-grid">
            <div>
                <p class="eyebrow">TENTANG BENEFUND</p>
                <h1 id="about-title">Kebaikan yang terhubung.<br><span>Dampak yang terlihat.</span></h1>
                <p class="about-intro">BeneFund adalah aplikasi penggalangan donasi yang mempertemukan kepedulian donatur dengan kebutuhan masyarakat melalui campaign sosial dan laporan penggunaan dana yang terbuka.</p>
                <div class="actions">
                    <a class="btn" href="{{ route('campaigns') }}">Jelajahi Campaign <span aria-hidden="true">→</span></a>
                    <a class="text-link" href="{{ route('transparency') }}">Lihat Transparansi</a>
                </div>
            </div>
            <aside class="about-purpose" aria-labelledby="about-purpose-title">
                <span class="about-heart" aria-hidden="true">♥</span>
                <h2 id="about-purpose-title">Every Gift,<br> A Real Impact.</h2>
                <p>Setiap kontribusi berhak memiliki cerita yang dapat diikuti, dari donasi masuk hingga penyaluran dikonfirmasi penerima.</p>
            </aside>
        </div>
    </section>

    <div class="wrap about-content">
        <section class="section" aria-labelledby="about-goals-title">
            <div class="about-section-heading">
                <p class="eyebrow">TUJUAN KAMI</p>
                <h2 id="about-goals-title">Memudahkan bantuan, membangun kepercayaan</h2>
                <p class="muted">Kami menghadirkan alur donasi yang mudah dipahami sekaligus memberi ruang untuk memantau perkembangannya.</p>
            </div>
            <div class="about-goals">
                <article class="panel">
                    <span class="about-number" aria-hidden="true">01</span>
                    <h3>Menghubungkan kepedulian</h3>
                    <p>Membantu donatur menemukan campaign sesuai kepeduliannya serta membantu penggalang menyampaikan kebutuhan dan tujuan bantuan.</p>
                </article>
                <article class="panel">
                    <span class="about-number" aria-hidden="true">02</span>
                    <h3>Membuka informasi dana</h3>
                    <p>Memperlihatkan dana masuk, dana dalam pengajuan, dana keluar, serta konfirmasi penerima agar penggunaan donasi dapat ditelusuri.</p>
                </article>
                <article class="panel">
                    <span class="about-number" aria-hidden="true">03</span>
                    <h3>Mendukung akuntabilitas</h3>
                    <p>Menghubungkan persetujuan campaign, verifikasi status pembayaran, bukti transfer, dan riwayat penyaluran dalam satu aplikasi.</p>
                </article>
            </div>
        </section>

        <section class="section about-features-section" aria-labelledby="about-features-title">
            <div class="section-title">
                <div>
                    <p class="eyebrow">FITUR UTAMA</p>
                    <h2 id="about-features-title">Satu tempat untuk mengikuti kebaikan</h2>
                </div>
            </div>
            <div class="about-features">
                <article class="about-feature">
                    <h3>Campaign sosial</h3>
                    <p>Temukan campaign melalui pencarian dan kategori. Penggalang dapat mengajukan campaign untuk ditinjau admin sebelum ditampilkan kepada publik.</p>
                </article>
                <article class="about-feature">
                    <h3>Pilihan pembayaran</h3>
                    <p>Pilih QRIS, transfer bank, atau e-wallet. Nominal donasi, biaya admin, dan total pembayaran ditampilkan sebelum checkout.</p>
                </article>
                <article class="about-feature">
                    <h3>Notifikasi aktivitas</h3>
                    <p>Ikuti informasi dana masuk, perubahan status transaksi, dan perkembangan penyaluran melalui notifikasi pada akun Anda.</p>
                </article>
                <article class="about-feature">
                    <h3>Penyaluran terlacak</h3>
                    <p>Pengajuan ditinjau admin, transfer dicatat beserta buktinya, lalu penerima mengonfirmasi dana yang telah diterima.</p>
                </article>
                <article class="about-feature">
                    <h3>Transparansi dan berita</h3>
                    <p>Periksa ringkasan saldo dan laporan penyaluran publik, serta ikuti berita kegiatan yang diterbitkan penggalang.</p>
                </article>
                <article class="about-feature">
                    <h3>Riwayat dan sertifikat</h3>
                    <p>Donatur dapat melihat riwayat pribadi, menyimpan campaign favorit, memilih donasi anonim, dan mencetak sertifikat donasi yang telah terverifikasi.</p>
                </article>
            </div>
        </section>

        <section class="section" aria-labelledby="about-steps-title">
            <div class="about-section-heading">
                <p class="eyebrow">CARA KERJA</p>
                <h2 id="about-steps-title">Dari niat baik hingga kabar penerimaan</h2>
            </div>
            <ol class="about-steps">
                <li><h3>Temukan tujuan</h3><p>Baca kebutuhan, target, tenggat, dan pembaruan campaign sebelum berdonasi.</p></li>
                <li><h3>Selesaikan donasi</h3><p>Masuk ke akun Anda, pilih nominal dan pembayaran, lalu ikuti instruksi checkout.</p></li>
                <li><h3>Pantau perkembangannya</h3><p>Periksa status donasi, notifikasi, dan laporan penyaluran hingga konfirmasi penerima.</p></li>
            </ol>
            <div class="about-payment-note" role="note">
                <strong>Pembayaran dalam lingkungan uji coba</strong>
                <p>BeneFund saat ini menggunakan Midtrans Sandbox untuk simulasi pembayaran. Penyaluran dicatat setelah admin melakukan transfer secara terpisah; bukti transfer dan konfirmasi penerima tersedia dalam alur penyaluran.</p>
            </div>
        </section>

        <section class="section about-developer-section" aria-labelledby="about-developer-title">
            <div class="panel about-developer">
                <div>
                    <p class="eyebrow">INFORMASI PENGEMBANG</p>
                    <h2 id="about-developer-title">Tim Pengembang BeneFund</h2>
                    <p>BeneFund dikembangkan dengan fokus pada kemudahan antarmuka, integrasi alur donasi, serta keterbukaan laporan penyaluran. Pekerjaan pengembangan mencakup perancangan pengalaman pengguna, pengembangan aplikasi, dan pengujian fitur.</p>
                    <p class="muted">Kode sumber dan riwayat kontribusi pengembang dapat dilihat pada repositori proyek BeneFund.</p>
                    <a class="text-link" href="https://github.com/Ingridtheresia/BeneFund">Lihat Repositori BeneFund <span aria-hidden="true">↗</span></a>
                </div>
                <div class="about-developer-invite">
                    <h3>Ambil bagian dalam kebaikan</h3>
                    <p>Mulai dari campaign yang dekat dengan kepedulian Anda.</p>
                    <a class="btn" href="{{ route('campaigns') }}">Temukan Campaign</a>
                </div>
            </div>
        </section>
    </div>
@endsection
