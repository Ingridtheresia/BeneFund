<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use App\Models\Campaign;
use App\Models\Donation;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MidtransService
{
    public function configured(): bool
    {
        return is_string(config('midtrans.server_key')) && filled(config('midtrans.server_key'))
            && is_string(config('midtrans.client_key')) && filled(config('midtrans.client_key'));
    }

    public function transport(): PendingRequest
    {
        $options = ['verify' => true, 'allow_redirects' => false];
        $bundle = config('midtrans.ca_bundle');
        if (filled($bundle)) {
            if (! is_string($bundle) || ! is_file($bundle) || ! is_readable($bundle)) {
                throw new PaymentGatewayException(503, 'File sertifikat MIDTRANS_CA_BUNDLE tidak ditemukan atau tidak dapat dibaca.');
            }
            $options['verify'] = $bundle;
        } elseif (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            // XAMPP's OpenSSL build may have no CA bundle. Use Windows' trusted
            // roots while keeping peer and hostname verification enabled.
            $options['curl'] = [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA];
        }

        return Http::withOptions($options)->acceptJson()->asJson()->connectTimeout(5)->timeout(15);
    }

    private function request(): PendingRequest
    {
        if (blank(config('midtrans.server_key'))) {
            throw new PaymentGatewayException(503, 'Midtrans Sandbox belum dikonfigurasi. Isi MIDTRANS_SERVER_KEY dan MIDTRANS_CLIENT_KEY pada .env.');
        }

        return $this->transport()->withBasicAuth(config('midtrans.server_key'), '');
    }

    public function checkout(Donation $donation): Donation
    {
        if (! $this->configured()) {
            throw new PaymentGatewayException(503, 'Midtrans Sandbox belum dikonfigurasi. Isi MIDTRANS_SERVER_KEY dan MIDTRANS_CLIENT_KEY pada .env.');
        }

        // Allocate legacy orders before the network transaction so a timeout cannot
        // orphan a gateway order when the database transaction is rolled back.
        if (! $donation->order_id) {
            Donation::whereKey($donation->id)->whereNull('order_id')->where('status', 'pending')
                ->update(['order_id' => 'BF-'.Str::uuid()]);
        }

        // Serialize requests for the same donation: double-clicks reuse the same token.
        return DB::transaction(function () use ($donation) {
            $locked = Donation::whereKey($donation->id)->lockForUpdate()->firstOrFail();
            $campaign = Campaign::whereKey($locked->campaign_id)->firstOrFail();
            abort_unless($locked->status === 'pending', 422, 'Transaksi ini tidak dapat dibayar kembali.');

            if ($locked->snap_token) {
                abort_if($locked->expires_at?->isPast(), 422, 'Sesi pembayaran telah kedaluwarsa. Silakan buat donasi baru.');

                return $locked;
            }

            abort_unless($campaign->status === 'approved' && $campaign->deadline->copy()->endOfDay()->isFuture(), 422, 'Campaign tidak menerima donasi saat ini.');
            $locked->load('user');
            $hours = (int) config('midtrans.expiry_hours', 24);
            $items = [[
                'id' => (string) $campaign->id, 'price' => $locked->amount, 'quantity' => 1,
                'name' => Str::limit($campaign->title, 45, ''),
            ]];
            if ($locked->admin_fee > 0) {
                $items[] = ['id' => 'admin-fee', 'price' => $locked->admin_fee, 'quantity' => 1, 'name' => 'Biaya admin pembayaran'];
            }
            $channels = $locked->payment_channel ? [$locked->payment_channel] : match ($locked->method) {
                'qris' => ['other_qris'], 'bank' => ['bank_transfer'], 'ewallet' => ['gopay', 'shopeepay'],
            };
            if ($locked->payment_channel && ! array_key_exists($locked->payment_channel, config('payments.methods.'.$locked->method.'.channels', []))) {
                throw ValidationException::withMessages(['payment_channel' => 'Pilihan bank atau e-wallet tidak cocok dengan metode pembayaran.']);
            }
            $payload = [
                'transaction_details' => ['order_id' => $locked->order_id, 'gross_amount' => $locked->total_amount],
                'item_details' => $items,
                'customer_details' => ['first_name' => Str::limit($locked->user->name, 100, ''), 'email' => $locked->user->email],
                'enabled_payments' => $channels,
                'callbacks' => ['finish' => route('payments.finish', $locked)],
                'expiry' => ['start_time' => now()->format('Y-m-d H:i:s O'), 'unit' => 'hours', 'duration' => $hours],
                'page_expiry' => ['unit' => 'hours', 'duration' => $hours],
            ];

            try {
                // Never automatically retry a charge-creation request after a timeout.
                $response = $this->request()->post(config('midtrans.snap_url'), $payload);
            } catch (ConnectionException $exception) {
                throw new PaymentGatewayException(502, $this->connectionMessage($exception));
            }
            $data = $response->json();
            $redirect = is_array($data) ? ($data['redirect_url'] ?? null) : null;
            if (! $response->successful() || ! is_string($data['token'] ?? null) || ! filled($data['token']) || ! $this->validRedirect($redirect)) {
                Log::warning('Midtrans checkout failed', ['donation_id' => $locked->id, 'http_status' => $response->status()]);
                if ($response->status() === 401 || $response->status() === 403) {
                    throw new PaymentGatewayException(502, 'Kredensial ditolak Midtrans Sandbox. Periksa Server Key, Client Key, dan environment akun Midtrans.');
                }
                throw new PaymentGatewayException(502, 'Sesi pembayaran Midtrans belum dapat dibuat. Periksa kredensial dan aktivasi metode pembayaran Sandbox. Jika tetap gagal, buat donasi baru.');
            }
            $locked->update([
                'snap_token' => $data['token'], 'snap_redirect_url' => $redirect,
                'expires_at' => now()->addHours($hours),
            ]);

            return $locked;
        });
    }

    private function validRedirect(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_HOST) === 'app.sandbox.midtrans.com'
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS);
    }

    public function validSignature(array $payload): bool
    {
        $key = config('midtrans.server_key');
        if (blank($key)) {
            throw new PaymentGatewayException(503, 'Midtrans Sandbox belum dikonfigurasi.');
        }
        $expected = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$key);

        return hash_equals($expected, $payload['signature_key']);
    }

    public function sync(Donation $donation): Donation
    {
        if (! $donation->order_id) {
            return $donation->fresh();
        }
        try {
            $response = $this->request()->get(config('midtrans.api_url').'/'.rawurlencode($donation->order_id).'/status');
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayException(502, $this->connectionMessage($exception));
        }
        $data = $response->json();
        // Snap has no Core API status until a customer selects a payment method.
        if ($response->status() === 404 || ($response->successful() && is_array($data) && (string) ($data['status_code'] ?? '') === '404')) {
            $donation->update(['last_checked_at' => now()]);

            return $donation->fresh();
        }
        if (! $response->successful() || ! is_array($data) || ! isset($data['transaction_status'])) {
            Log::warning('Midtrans status lookup failed', ['donation_id' => $donation->id, 'http_status' => $response->status()]);
            throw new PaymentGatewayException(502, 'Status Midtrans belum dapat diperiksa. Silakan coba lagi.');
        }
        if (Validator::make($data, [
            'order_id' => 'required|string|max:64', 'transaction_status' => 'required|string|max:40',
            'status_code' => 'required|string|size:3', 'gross_amount' => 'required',
            'transaction_id' => 'nullable|string|max:255', 'fraud_status' => 'nullable|string|max:40',
            'payment_type' => 'nullable|string|max:40', 'currency' => 'nullable|string|size:3',
        ])->fails()) {
            throw new PaymentGatewayException(502, 'Respons status Midtrans tidak valid. Silakan coba lagi.');
        }

        return $this->applyStatus($donation, $data);
    }

    private function rupiah(mixed $value): ?int
    {
        // Compare exact IDR values without floating point rounding or scientific notation.
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^\d{1,12}(?:\.0{1,2})?$/D', (string) $value)) {
            return null;
        }

        return (int) explode('.', (string) $value)[0];
    }

    private function applyStatus(Donation $donation, array $data): Donation
    {
        if (($data['order_id'] ?? null) !== $donation->order_id || $this->rupiah($data['gross_amount'] ?? null) !== $donation->total_amount) {
            throw ValidationException::withMessages(['payment' => 'Order atau nominal Midtrans tidak cocok dengan donasi.']);
        }
        if (isset($data['currency']) && $data['currency'] !== 'IDR') {
            throw ValidationException::withMessages(['payment' => 'Mata uang transaksi harus IDR.']);
        }

        return DB::transaction(function () use ($donation, $data) {
            // The same campaign lock also protects expense balance calculations.
            Campaign::whereKey($donation->campaign_id)->lockForUpdate()->firstOrFail();
            $locked = Donation::whereKey($donation->id)->lockForUpdate()->firstOrFail();
            $gateway = $data['transaction_status'];
            $fraud = $data['fraud_status'] ?? null;
            $successful = (string) ($data['status_code'] ?? '') === '200';
            $status = match ($gateway) {
                'settlement' => $successful && ($fraud === null || $fraud === 'accept') ? 'paid' : 'pending',
                'capture' => $successful && $fraud === 'accept' ? 'paid' : 'pending',
                'expire' => 'expired', 'cancel' => 'cancelled', 'deny', 'failure' => 'failed',
                'refund', 'chargeback' => 'refunded', 'partial_refund', 'partial_chargeback' => 'partially_refunded',
                default => 'pending',
            };

            $changes = ['last_checked_at' => now()];
            // Pending replays cannot undo payments. Verified reversal (settlement ->
            // deny) and cancellation of a capture must remove the funding, however.
            $reversal = $locked->status === 'paid' && ($gateway === 'deny'
                || ($gateway === 'cancel' && $locked->gateway_status === 'capture'));
            if ($locked->status === 'refunded'
                || ($locked->status === 'partially_refunded' && ! in_array($status, ['refunded', 'partially_refunded'], true))
                || ($locked->status === 'paid' && ! $reversal && ! in_array($status, ['paid', 'refunded', 'partially_refunded'], true))
                || (in_array($locked->status, ['expired', 'cancelled', 'failed'], true) && $status !== $locked->status)) {
                $locked->update($changes);

                return $locked;
            }

            if ($status === 'partially_refunded') {
                $refund = $this->rupiah($data['refund_amount'] ?? null);
                if ($refund === null || $refund < 1 || $refund > $locked->total_amount) {
                    throw ValidationException::withMessages(['payment' => 'Nominal pengembalian Midtrans tidak valid.']);
                }
                if ($refund < $locked->refunded_amount) {
                    $locked->update($changes);

                    return $locked;
                }
                $changes['refunded_amount'] = $refund;
                if ($refund === $locked->total_amount) {
                    $status = 'refunded';
                }
            }
            if ($status === 'refunded') {
                $changes['refunded_amount'] = $locked->total_amount;
                $changes['paid_at'] = null;
            } elseif (in_array($status, ['paid', 'partially_refunded'], true)) {
                $changes['paid_at'] = $locked->paid_at ?? $this->settledAt($data);
            } else {
                $changes['paid_at'] = null;
            }

            $transactionId = $data['transaction_id'] ?? $locked->gateway_transaction_id;
            if ($locked->gateway_transaction_id && $transactionId !== $locked->gateway_transaction_id) {
                throw ValidationException::withMessages(['payment' => 'Identitas transaksi Midtrans tidak cocok.']);
            }
            $locked->update($changes + [
                'status' => $status, 'gateway_status' => $gateway,
                'gateway_fraud_status' => $fraud, 'gateway_transaction_id' => $transactionId,
                'payment_type' => $data['payment_type'] ?? $locked->payment_type,
            ]);

            return $locked;
        });
    }

    private function settledAt(array $data): Carbon
    {
        try {
            return isset($data['settlement_time'])
                ? Carbon::parse($data['settlement_time'], 'Asia/Jakarta')->setTimezone(config('app.timezone'))
                : now();
        } catch (\Throwable) {
            return now();
        }
    }

    private function connectionMessage(ConnectionException $exception): string
    {
        if (str_contains($exception->getMessage(), 'cURL error 60')) {
            return 'Sertifikat HTTPS Midtrans belum dapat diverifikasi. Pengelola perlu menjalankan php artisan midtrans:doctor dan memeriksa MIDTRANS_CA_BUNDLE.';
        }

        return 'Koneksi Midtrans terputus. Coba periksa status pembayaran beberapa saat lagi.';
    }
}
