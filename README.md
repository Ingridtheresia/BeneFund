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
