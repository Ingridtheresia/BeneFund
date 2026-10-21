<?php

namespace App\Console\Commands;

use App\Exceptions\PaymentGatewayException;
use App\Services\MidtransService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DiagnoseMidtrans extends Command
{
    protected $signature = 'midtrans:doctor {--offline : Check local configuration without contacting Midtrans}';

    protected $description = 'Check Midtrans Sandbox configuration, HTTPS, and authentication without creating a transaction';

    public function handle(MidtransService $midtrans): int
    {
        $this->info('Diagnostik Midtrans Sandbox (tanpa membuat transaksi).');
        $ready = true;
        if (! $midtrans->configured()) {
            $this->error('Isi MIDTRANS_SERVER_KEY dan MIDTRANS_CLIENT_KEY dari dashboard Sandbox, lalu jalankan php artisan config:clear.');
            $ready = false;
        } else {
            // Key prefixes are not proof of their environment; verify server authentication below.
            $this->line('Server Key dan Client Key tersedia; nilainya tidak ditampilkan.');
        }

        try {
            if (! Schema::hasColumns('donations', ['admin_fee', 'payment_channel'])) {
                $this->error('Migrasi pembayaran belum lengkap. Jalankan php artisan migrate.');
                $ready = false;
            } else {
                $this->line('Kolom biaya admin dan pilihan pembayaran tersedia.');
            }
        } catch (Throwable) {
            $this->error('Database belum dapat diperiksa. Periksa konfigurasi database dan jalankan php artisan migrate.');
            $ready = false;
        }

        try {
            // Also validate an optional CA bundle when the offline mode is selected.
            $midtrans->transport();
        } catch (PaymentGatewayException) {
            $this->error('MIDTRANS_CA_BUNDLE tidak valid. Gunakan path file PEM yang tersedia dan dapat dibaca.');
            $ready = false;
        }

        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if (in_array($host, ['', 'localhost', '127.0.0.1', '::1', '[::1]'], true) || str_ends_with($host, '.localhost')) {
            $this->warn('APP_URL masih lokal. Webhook otomatis memerlukan URL publik/tunnel HTTPS; tombol Periksa Status tetap dapat digunakan.');
        }

        if (! $ready) {
            return self::FAILURE;
        }
        if ($this->option('offline')) {
            $this->warn('Pemeriksaan offline selesai; koneksi dan autentikasi belum diverifikasi.');

            return self::SUCCESS;
        }

        try {
            $script = $midtrans->transport()->get(config('midtrans.script_url'));
            if ($script->status() !== 200) {
                $this->error('Skrip checkout Sandbox belum dapat diakses (HTTP '.$script->status().').');

                return self::FAILURE;
            }
            $this->line('HTTPS dan skrip checkout Sandbox dapat diakses.');

            $response = $midtrans->transport()->withBasicAuth(config('midtrans.server_key'), '')
                ->get(rtrim(config('midtrans.api_url'), '/').'/BF-DIAGNOSTIC-NEVER-CREATED/status');
            if ($response->status() === 401 || $response->status() === 403) {
                $this->error('Autentikasi Sandbox ditolak. Salin Server Key dari dashboard Sandbox akun yang digunakan, lalu jalankan php artisan config:clear.');

                return self::FAILURE;
            }
            $data = $response->json();
            if ($response->status() !== 404 && ! ($response->status() === 200 && is_array($data) && (string) ($data['status_code'] ?? '') === '404')) {
                $this->error('Respons pemeriksaan autentikasi belum sesuai (HTTP '.$response->status().'). Coba lagi atau periksa layanan Midtrans.');

                return self::FAILURE;
            }
            $this->info('Koneksi HTTPS dan autentikasi Server Key Sandbox berhasil. Tidak ada transaksi dibuat.');
            $this->line('Untuk menguji pembayaran, buat donasi lalu gunakan simulator sesuai bank atau e-wallet yang dipilih.');
            $this->line('Client Key tersedia; kecocokannya diperiksa saat membuka checkout Snap.');

            return self::SUCCESS;
        } catch (ConnectionException $exception) {
            if (str_contains($exception->getMessage(), 'cURL error 60')) {
                $this->error('Verifikasi sertifikat HTTPS gagal (cURL 60). Periksa trusted certificate Windows atau isi MIDTRANS_CA_BUNDLE dengan file CA PEM yang valid.');
            } else {
                $this->error('Koneksi HTTPS ke Midtrans gagal. Periksa internet, DNS, firewall, atau proxy, lalu coba lagi.');
            }
        } catch (PaymentGatewayException) {
            $this->error('MIDTRANS_CA_BUNDLE tidak valid. Gunakan path file PEM yang tersedia dan dapat dibaca.');
        } catch (Throwable) {
            $this->error('Diagnostik Midtrans belum dapat diselesaikan. Periksa konfigurasi dan coba lagi.');
        }

        return self::FAILURE;
    }
}
