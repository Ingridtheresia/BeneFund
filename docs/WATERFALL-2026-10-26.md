# Pengembangan BeneFund — 26 Oktober 2026

## 1. Planning

Lingkup: notifikasi aktivitas, halaman Tentang, dan identitas kanal pembayaran. Pemeriksaan awal menemukan alur pembayaran Midtrans Sandbox dan penyaluran manual sudah tersedia, sedangkan notifikasi akun belum tersedia dan halaman Tentang hanya berisi ringkasan singkat.

Kriteria penerimaan:

- Aktivitas pembayaran terverifikasi, bukti transfer, status penyaluran, serta moderasi campaign menghasilkan notifikasi bagi pihak terkait.
- Notifikasi tersimpan, memiliki penanda belum dibaca, dapat difilter dan ditandai dibaca, serta terisolasi antar akun.
- Pembaruan status berulang tidak menggandakan notifikasi. Kegagalan transaksi membatalkan notifikasi terkait.
- Halaman Tentang menjelaskan aplikasi, tujuan, fitur utama, cara kerja, dan pengembang berdasarkan informasi proyek.
- Semua kanal pembayaran memiliki logo resmi lokal, nama yang terbaca, pilihan dengan keyboard, serta dukungan tanpa JavaScript.
- Perubahan diuji secara otomatis dan tampilan diperiksa pada layar ponsel sampai desktop.

## 2. Eksekusi

### Notifikasi

Menambahkan tabel database Laravel `notifications`, layanan aktivitas, serta pusat notifikasi pada `/notifications`. Ikon lonceng dan tautan Dashboard membuka pusat notifikasi. Jumlah belum dibaca diperbarui setiap 30 detik ketika halaman aktif. Daftar menggunakan pagination 15 item dan filter semua/belum dibaca.

Perubahan donasi mengikuti hasil verifikasi layanan Midtrans, termasuk refund sebagian dan reversal. Seluruh tahap penyaluran mengirim notifikasi, termasuk saat bukti transfer diterima sistem dan saat penerima mengonfirmasi dana. Bukti unggahan tidak dianggap sebagai konfirmasi penerimaan dana. Pengajuan serta keputusan campaign juga terhubung.

Penerima dibatasi donatur, pemilik campaign, peserta penyaluran, atau admin sesuai aktivitas. Notifikasi tidak memuat identitas donatur anonim, data rekening, bukti privat, atau catatan internal. Notifikasi dan perubahan bisnis memakai transaksi database yang sama. Migrasi tambahan telah diterapkan pada database lokal tanpa menghapus data lama.

### Tentang

Halaman khusus memuat deskripsi BeneFund, tiga tujuan, enam fitur utama, tiga langkah penggunaan, konteks pembayaran Sandbox, dan Tim Pengembang BeneFund dengan tautan repositori. Nama pribadi atau kontak pengembang yang tidak terverifikasi tidak ditambahkan.

### Pembayaran

QRIS, BCA, BNI, BRI, Mandiri, Permata, CIMB Niaga, GoPay, dan ShopeePay ditampilkan dengan logo resmi lokal. Kartu pilihan memakai radio native dan label teks. Ringkasan pembayaran menunjukkan logo kanal yang dipilih. Validasi metode/kanal dan penghitungan biaya oleh server tetap digunakan.

## 3. Monitoring

Pengujian backend menggunakan SQLite sementara dan respons HTTP simulasi. Uji browser menggunakan database SQLite terpisah, akun uji, serta jaringan eksternal yang diblokir. Tidak ada pembayaran nyata yang dikirim.

| Pemeriksaan | Cakupan |
| --- | --- |
| Regresi aplikasi | Autentikasi, campaign, pembayaran, webhook, akuntansi, dan penyaluran yang sudah ada |
| Notifikasi aktivitas | Dana masuk, refund, reversal, status penyaluran, bukti, campaign, penerima, deduplikasi, privasi, dan rollback |
| Pusat notifikasi | Login wajib, isolasi akun, penanda dibaca idempoten, tandai semua, filter, pagination, dan pelolosan karakter HTML |
| Tentang dan logo | Konten utama, tautan, seluruh kanal, aset lokal, dan transaksi lama tanpa kanal |
| Browser | Pergantian QRIS → Bank → BNI → E-Wallet → ShopeePay, biaya, logo termuat, pembaruan jumlah notifikasi setelah 30 detik, penanda dibaca, pagination, serta formulir tanpa JavaScript |
| Responsif | Tentang, campaign, pembayaran, dan notifikasi pada lebar 320, 375, 768, 1024, 1280, dan 1440 piksel; tidak ada luapan horizontal |

Temuan dan perbaikan: simulasi respons refund pada tes perlu direset antar permintaan agar respons terbaru dipakai. Penerima notifikasi penyaluran juga dilengkapi untuk pemilik campaign jika pengajuan dibuat admin; tautannya mengikuti hak akses. Pemeriksaan browser dan inspeksi tangkapan layar memastikan navigasi ponsel, kartu logo, serta daftar notifikasi dapat digunakan.

## 4. Selesai

Pengujian backend akhir: **166 lulus, 1.246 assertion**. Pemeriksaan format PHP, sintaks JavaScript, konsistensi patch, serta kompilasi seluruh template Blade lulus. Uji browser akhir lulus, termasuk 24 kombinasi halaman/lebar layar dan pembaruan notifikasi dengan interval sebenarnya. Tangkapan layar telah diperiksa, dan logo CIMB diperjelas memakai SVG horizontal resmi dari situs bank. Seluruh kriteria penerimaan pada tahap Planning telah diperiksa dan tahap pengembangan dinyatakan selesai untuk lingkup ini.

Fitur berlaku bagi aktivitas baru; notifikasi lama tidak direkonstruksi. Saluran yang diimplementasikan adalah notifikasi dalam aplikasi. Pembayaran menggunakan Midtrans Sandbox dan pengujian gateway memakai respons simulasi; uji transaksi Sandbox langsung tetap bergantung pada konfigurasi merchant.

Tangkapan layar hasil pengujian berada di `storage/framework/testing/browser-output`. Jalankan `php artisan migrate` saat menerapkan kode pada instalasi lain, lalu ikuti perintah verifikasi pada README.
