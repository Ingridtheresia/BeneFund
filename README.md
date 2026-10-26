# BeneFund

Aplikasi donasi Laravel 12 dengan role donor, fundraiser, dan admin. Pembayaran menggunakan **Midtrans Snap Sandbox** dan simulator resmi Midtrans.

## Menjalankan di Windows / XAMPP

PHP 8.2+ dengan cURL, OpenSSL, mbstring, dan PDO MySQL atau SQLite diperlukan.

```powershell
cd C:\xampp\htdocs\BeneFund-main
composer install
# Hanya jika belum memiliki .env:
Copy-Item .env.example .env
php artisan key:generate
```

Untuk database MySQL, buat database `benefund` lalu sesuaikan `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD` di `.env`. Untuk SQLite, gunakan `DB_CONNECTION=sqlite` dan buat file `database/database.sqlite`.

```powershell
php artisan migrate
# Opsional untuk instalasi baru: tambahkan akun dan campaign demo.
php artisan db:seed
php artisan serve
```

Gunakan `APP_URL=http://localhost:8000` untuk Artisan Serve. Jika memakai Apache XAMPP langsung, URL default proyek ini adalah `http://localhost/BeneFund-main/public`; samakan `APP_URL` dengan URL yang benar-benar dibuka. Timezone default `Asia/Jakarta`.

Jika aplikasi sudah terpasang, cukup jalankan `php artisan migrate` untuk migrasi tambahan. Migrasi pembayaran menambahkan kolom, tanpa menghapus transaksi yang ada. Donasi demo/lama yang sudah `paid` tetap dipertahankan dan dikenali lewat `order_id` kosong. Donasi lama yang masih pending mendapat order Midtrans saat checkout.

Akun dari seeder, password `Password123!`:

| Role | Email |
| --- | --- |
| Admin | admin@benefund.test |
| Fundraiser | fundraiser@benefund.test |
| Donor | donor@benefund.test |

Data donasi `paid` yang dibuat seeder adalah contoh lokal, bukan transaksi Midtrans.

## Konfigurasi Midtrans Sandbox

1. Buka [dashboard Midtrans Sandbox](https://dashboard.sandbox.midtrans.com/), kemudian **Settings → Access Keys**.
2. Salin **Server Key** dan **Client Key** dari akun Sandbox milik Anda ke `.env`:

```dotenv
MIDTRANS_SERVER_KEY=SB-Mid-server-isi-dengan-key-anda
MIDTRANS_CLIENT_KEY=SB-Mid-client-isi-dengan-key-anda
```

3. Jalankan `php artisan config:clear` setelah mengubah `.env`.
4. Aktifkan kanal pembayaran yang dipakai di **Snap Preferences**. Pemetaan aplikasi: QRIS → `other_qris`, transfer bank → `bank_transfer`, e-wallet → GoPay / ShopeePay. Ketersediaan kanal mengikuti akun merchant Sandbox.
   Untuk donasi baru, kanal dikunci sesuai bank/e-wallet yang dipilih di formulir (misalnya `bca_va` atau `gopay`). Key harus berasal dari environment Sandbox pada dashboard; aplikasi tidak menentukan keabsahannya hanya dari awalan key.
5. Untuk notifikasi otomatis, isi **Payment Notification URL** pada pengaturan notifikasi Midtrans:

```text
https://domain-publik-anda/payments/midtrans/notification
```

Midtrans harus dapat menjangkau URL tersebut dari internet. Untuk pengembangan lokal gunakan tunnel HTTPS yang mengarah ke server Laravel, lalu gunakan URL publik yang sama untuk `APP_URL` dan pengaturan webhook. Jika aplikasi berada dalam subdirektori, sertakan subdirektori itu sebelum `/payments/midtrans/notification`. Pastikan tunnel mengarah ke folder `public` / server aplikasi.

Server Key hanya digunakan di backend. Checkout dan API status sengaja dikunci ke domain Sandbox; aplikasi ini tidak memproses pembayaran produksi.

## Pilihan pembayaran dan biaya admin

| Metode | Pilihan | Biaya admin |
| --- | --- | --- |
| Bank | BCA, BNI, BRI, Mandiri Bill Payment, Permata, CIMB Niaga | Rp4.500 |
| E-wallet | GoPay, ShopeePay | Rp1.500 |
| QRIS | QRIS Midtrans | Gratis |

Biaya admin aplikasi ditambahkan di luar nominal donasi dan dihitung ulang oleh server. Contoh donasi Rp100.000: total bank Rp104.500, e-wallet Rp101.500, QRIS Rp100.000. Nominal yang masuk ke saldo campaign, peringkat donatur, dan sertifikat tetap Rp100.000. Biaya ini adalah ketentuan aplikasi, bukan daftar tarif merchant Midtrans.

Bank dan e-wallet wajib dipilih sesuai metode. Pilihan serta biaya tersimpan pada setiap transaksi; harga dari formulir atau JavaScript tidak dipercaya sebagai nominal tagihan. Transaksi lama tetap memiliki biaya yang telah disepakati, termasuk sesi Snap yang masih aktif. Buat donasi baru untuk menggunakan pilihan dan biaya baru.

## Menguji dengan simulator

1. Daftar/login sebagai donor dan pilih campaign approved yang belum melewati deadline.
2. Isi nominal minimal Rp1.000, metode pembayaran, pesan opsional, dan opsi anonim.
3. Klik **Bayar via Midtrans**. Snap membuka QRIS, virtual account, atau e-wallet sesuai pilihan. Bila JavaScript tidak tersedia, tombol membuka halaman checkout Midtrans.
4. Selesaikan transaksi melalui [panduan dan simulator resmi Midtrans](https://docs.midtrans.com/docs/testing-payment-on-sandbox): untuk QRIS gunakan URL gambar QR dari Snap; untuk transfer bank gunakan nomor VA dan simulator bank yang sesuai; untuk e-wallet ikuti simulator yang dibuka Snap.
   Halaman pembayaran menyediakan tautan langsung ke simulator kanal pilihan. Untuk QRIS dan GoPay/ShopeePay desktop, klik kanan gambar QR di Snap lalu salin **alamat gambar**, dan tempelkan pada **QR Code Image URL** di simulator. Kolom tersebut bukan untuk Order ID. Untuk Mandiri, masukkan **biller/company code** dan **bill key** pada kolom yang sesuai. Jika diminta nominal, gunakan **total pembayaran** termasuk biaya admin.
5. Kembali ke halaman donasi. Status diperiksa otomatis selama lima menit, atau klik **Periksa Status Pembayaran**. Sertifikat tersedia setelah verifikasi server berhasil.

Status sukses dari JavaScript atau query string tidak mengubah status donasi. Backend memverifikasi signature SHA-512 notifikasi, lalu mengambil status terkini dari API Midtrans dan mencocokkan order, nominal IDR, serta status fraud. Webhook publik dikecualikan dari CSRF; aksi pengguna tetap menggunakan CSRF dan otorisasi.

Notifikasi berulang diproses tanpa menggandakan donasi atau mengganti tanggal lunas. Sistem menangani pending, settlement/capture, expiry, pembatalan, kegagalan/reversal, refund penuh, dan refund sebagian. Dana bersih serta sertifikat menyesuaikan jumlah refund; sertifikat dicabut untuk refund penuh atau reversal. Refund diproses di Midtrans; aplikasi menyinkronkan hasilnya, bukan menyediakan tombol untuk mengirim refund.

Verifikasi nominal gateway menggunakan total donasi ditambah biaya admin. Pengembalian dana mengurangi donasi terlebih dahulu dengan batas minimum nol, baru sisa biaya admin; pengembalian sebesar seluruh tagihan ditandai refund penuh. Sertifikat tidak tersedia jika kontribusi bersih sudah nol.

Jika belum memakai tunnel, tombol pemeriksaan status tetap bekerja karena server lokal menghubungi Midtrans. Rekonsiliasi juga dapat dijalankan dari terminal:

```powershell
php artisan midtrans:sync
# Sertakan transaksi lunas untuk memeriksa refund/reversal:
php artisan midtrans:sync --all --limit=100
```

Untuk otomatisasi lokal, jalankan `php artisan schedule:work` di terminal terpisah. Scheduler memeriksa pending setiap lima menit dan transaksi lunas setiap jam. Pada server, gunakan Laravel scheduler melalui Task Scheduler/cron. Webhook tetap menjadi jalur utama pembaruan segera.

## Jika Sandbox atau simulator belum bekerja

```powershell
php artisan config:clear
php artisan midtrans:doctor
# Periksa key dan skema lokal tanpa menghubungi internet:
php artisan midtrans:doctor --offline
```

Perintah diagnostik tidak membuat tagihan dan tidak menampilkan key. Pemeriksaan online mencakup akses HTTPS ke Snap dan autentikasi Server Key terhadap order diagnostik yang tidak ada. Status Midtrans `404` untuk order tersebut adalah hasil yang diharapkan; HTTP `401/403` menandakan masalah kredensial. Client Key harus diambil dari akun/environment yang sama; pengujian API status hanya memverifikasi Server Key.

Pada XAMPP Windows, `cURL error 60 / unable to get local issuer certificate` berarti PHP tidak dapat memverifikasi sertifikat HTTPS. Koneksi Midtrans sekarang memakai penyimpanan sertifikat tepercaya Windows jika didukung cURL. Pemeriksaan sertifikat dan hostname tetap aktif. Jika runtime membutuhkan CA bundle khusus, isi jalur absolut berkas sertifikat tepercaya pada `.env`, misalnya:

```dotenv
MIDTRANS_CA_BUNDLE=C:/xampp/apache/bin/curl-ca-bundle.crt
```

Pastikan berkas tersebut ada dan dapat dibaca, lalu jalankan `php artisan config:clear` dan diagnostik kembali. Untuk PHP CLI dan Apache yang memakai konfigurasi berbeda, periksa konfigurasi runtime yang digunakan masing-masing. Jangan menonaktifkan verifikasi TLS.

Localhost dapat membuat checkout dan memeriksa status karena koneksi dimulai dari aplikasi ke Midtrans. Webhook dari Midtrans memerlukan URL publik; jika masih lokal, gunakan tombol pemeriksaan status atau polling setelah menjalankan simulator. Tautan simulator baru dapat membayar setelah Snap menghasilkan nomor VA, kode tagihan, atau URL QR yang valid.

## Fitur backend

### Salurkan Donasi: transfer admin, bukti, dan konfirmasi penerima

Fitur ini mencatat penyaluran yang dilakukan admin melalui bank di luar aplikasi. Pencatatan transfer tidak menjalankan API payout atau memindahkan dana secara otomatis. Lingkungan Midtrans yang digunakan proyek tetap Sandbox.

1. Penerima membuat akun BeneFund terlebih dahulu. Akun penerima harus berbeda dari pengaju.
2. Penggalang membuka campaign miliknya yang disetujui, lalu memilih **Salurkan Donasi**. Isi tujuan, nominal, nama penerima untuk publik, email akun penerima, serta data rekening.
3. Pengajuan berstatus **Diajukan**. Sistem langsung mencadangkan saldo sehingga dana yang sama tidak dapat diajukan berkali-kali. Admin memeriksa lalu menyetujui atau menolak.
4. Setelah disetujui, admin melakukan transfer dari banknya, kemudian mencatat referensi transfer dan mengunggah bukti JPG/PNG/WebP/PDF maksimal 5 MB. Status menjadi **Transfer dilaporkan**. Saldo dipindahkan dari cadangan ke pembukuan pengeluaran, tepat satu kali.
5. Penerima membuka **Dashboard → Penyaluran Donasi** atau tautan detail yang dibagikan pengaju. Setelah memeriksa mutasi rekening, penerima menekan **Konfirmasi Dana Diterima**. Hanya akun penerima yang dapat melakukan konfirmasi; admin tidak dapat mengonfirmasi atas namanya.

Status penyaluran dan ringkasan saldo diperiksa otomatis setiap 10 detik selama halaman aktif. Halaman campaign dan transparansi membedakan dana masuk, dana dalam pengajuan, dana keluar yang dicatat, dana yang dikonfirmasi diterima, dan dana tersedia. Pelacak campaign menampilkan 20 penyaluran terbaru; portal peserta/admin menyediakan daftar dengan pagination.

Nomor rekening serta nama pemilik rekening disimpan terenkripsi. Bukti transfer berada pada penyimpanan privat dan hanya dapat diunduh pengaju, penerima, atau admin. Laporan publik hanya menampilkan nama publik, tujuan, nominal, status, dan waktu perubahan. Alasan penolakan/pembatalan serta catatan internal tidak dikirim ke endpoint publik.

Pengajuan yang ditolak atau dibatalkan sebelum transfer melepaskan cadangan. Penyaluran setelah transfer tidak dapat dibatalkan lewat perubahan status biasa. Refund donasi tetap mengurangi dana bersih; sistem menolak pemrosesan baru jika cadangan tidak lagi tertutup oleh saldo. Seluruh perubahan status dicatat dalam riwayat yang tidak dapat diedit melalui aplikasi.

Menu **Catat Pengeluaran** lama diganti alur pengajuan ini. Pembukuan lama tetap terbaca dan tidak dianggap sudah dikonfirmasi penerima. Endpoint pengeluaran lama menolak entri baru agar persetujuan dan bukti tidak dapat dilewati.

Untuk memperbarui instalasi:

```powershell
php artisan migrate
php artisan view:clear
php artisan test
```

Pengajuan dan perubahan status mengirim notifikasi dalam aplikasi kepada pihak terkait. Notifikasi tidak mengirim email atau WhatsApp otomatis. Bukti unggahan adalah laporan admin; status penerimaan berasal dari konfirmasi penerima, bukan verifikasi otomatis oleh bank.

### Notifikasi akun

Ikon lonceng tersedia setelah login, termasuk pada navigasi ponsel. Buka **Notifikasi** untuk melihat aktivitas terbaru, memfilter yang belum dibaca, menandai satu notifikasi dibaca, atau menandai semuanya. Jumlah yang belum dibaca diperiksa setiap 30 detik selama halaman aktif. Jika daftar sedang terbuka, pembaruan menampilkan tautan untuk memuat ulang daftar tanpa mengganggu pembacaan.

| Aktivitas | Penerima notifikasi |
| --- | --- |
| Status donasi berubah, termasuk gagal, kedaluwarsa, refund, dan reversal | Donatur terkait |
| Dana donasi terverifikasi masuk atau saldo donasi disesuaikan | Pemilik campaign dan admin |
| Penyaluran diajukan, disetujui, ditolak, dibatalkan, bukti transfer diunggah, atau dana dikonfirmasi diterima | Pengaju, penerima, admin, serta pemilik campaign |
| Campaign diajukan atau diperbarui | Admin |
| Campaign disetujui atau ditolak | Pemilik campaign |

Notifikasi hanya dibuat untuk aktivitas baru setelah fitur dipasang. Status pembayaran mengikuti verifikasi backend Midtrans. Pengulangan status yang sama tidak membuat notifikasi baru; perubahan jumlah refund tetap menghasilkan pembaruan. Notifikasi dan transaksi disimpan dalam transaksi database yang sama, sehingga keduanya dibatalkan jika proses gagal. Satu akun yang merangkap beberapa pihak tidak mendapat notifikasi ganda untuk satu aktivitas.

Notifikasi hanya dapat dibaca dan ditandai oleh akun penerimanya. Isi notifikasi tidak memuat rekening, bukti privat, catatan internal, atau identitas donatur anonim. Pemilik campaign yang bukan peserta penyaluran mendapat tautan pelacak campaign; akses bukti tetap mengikuti otorisasi peserta/admin.

### Tentang dan identitas pembayaran

Halaman **Tentang Kami** memuat informasi aplikasi, tujuan, fitur utama, cara kerja, serta Tim Pengembang BeneFund dan tautan repositori proyek. Logo QRIS, enam bank, GoPay, dan ShopeePay ditampilkan pada pilihan kanal dan ringkasan pembayaran. Aset resmi disimpan lokal; sumbernya dicatat di `public/images/payments/README.md`. Nama kanal tetap tersedia sebagai teks dan pilihan dapat digunakan dengan keyboard maupun tanpa JavaScript.

Catatan tahapan Planning → Eksekusi → Monitoring → Selesai tersedia di [laporan pengembangan Waterfall](docs/WATERFALL-2026-10-26.md).

- Autentikasi dengan password hash, session regeneration, remember me, pembatasan percobaan login, normalisasi email, dan validasi role saat registrasi.
- Profil serta perubahan password dengan verifikasi password saat ini.
- Campaign baru/perubahan menunggu persetujuan admin; hanya pemilik atau admin yang dapat mengelola campaign.
- Pencarian/filter campaign, favorit, berita kegiatan, laporan transparansi, dan peringkat donatur yang menghormati anonimitas.
- Pencatatan penyaluran dana dengan pemeriksaan saldo bersih dan transaksi database/lock untuk mencegah pengeluaran bersamaan melebihi saldo.
- Riwayat pribadi, dashboard donor/fundraiser, daftar pengguna dan transaksi untuk admin.
- Sertifikat HTML yang dapat dicetak atau disimpan sebagai PDF melalui browser.

Pencatatan penyaluran adalah buku laporan, bukan transfer dana dari Midtrans ke rekening penerima. Refund/reversal setelah dana dicatat tersalurkan dapat membuat saldo laporan negatif; pengeluaran baru ditolak sampai saldo cukup.

## Verifikasi

```powershell
php artisan test
php artisan route:list
php artisan view:cache
```

Tests menggunakan SQLite in-memory dan HTTP fake, sehingga tidak mengubah database aplikasi atau mengirim transaksi Midtrans. Pengujian mencakup akun/profil, persetujuan dan akses campaign, privasi berita/favorit, saldo penyaluran, serta checkout, webhook, pemalsuan status, replay, refund, reversal, dan kegagalan jaringan. Uji transaksi langsung memerlukan kredensial Sandbox milik Anda.

Pengujian notifikasi juga mencakup penerima yang berhak, isolasi antar akun, pengulangan webhook/status, perubahan refund, rollback, filter, pagination, serta penanda dibaca. Pengujian presentasi memeriksa seluruh logo kanal dan halaman Tentang.

Uji browser tersedia pada `tests/Browser/smoke.cjs`. Dengan paket Playwright dan Chromium tersedia pada runtime Node, jalankan:

```powershell
node tests/Browser/smoke.cjs
```

Jika diperlukan, isi `PLAYWRIGHT_MODULE` dengan lokasi paket Playwright, `PHP_BINARY` dengan lokasi PHP, dan `BROWSER_EXECUTABLE` dengan lokasi Chrome/Chromium. Pengujian membuat SQLite `browser-*.sqlite` sendiri di `storage/framework/testing`, memblokir jaringan eksternal, dan memeriksa pergantian metode/biaya, logo, notifikasi, navigasi, 24 kombinasi halaman/lebar layar, serta formulir tanpa JavaScript. Tangkapan layar tersedia di `storage/framework/testing/browser-output`; `BROWSER_TEST_OUTPUT` dapat digunakan untuk mengubah lokasi hasil.
