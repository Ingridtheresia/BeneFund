<?php

namespace App\Console\Commands;

use App\Models\Donation;
use App\Services\MidtransService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncMidtransPayments extends Command
{
    protected $signature = 'midtrans:sync {--limit=50 : Maximum orders to check} {--all : Include settled and partially refunded orders}';

    protected $description = 'Reconcile donation statuses with Midtrans Sandbox (also works without a public webhook URL)';

    public function handle(MidtransService $midtrans): int
    {
        if (! $midtrans->configured()) {
            $this->error('Isi MIDTRANS_SERVER_KEY dan MIDTRANS_CLIENT_KEY Sandbox pada .env terlebih dahulu.');

            return self::FAILURE;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('--limit harus berupa angka antara 1 dan 1000.');

            return self::FAILURE;
        }
        $statuses = $this->option('all') ? ['pending', 'paid', 'partially_refunded'] : ['pending'];
        $donations = Donation::whereNotNull('order_id')->whereIn('status', $statuses)
            ->orderBy('last_checked_at')->orderBy('id')->limit($limit)->get();
        $failed = 0;
        foreach ($donations as $donation) {
            try {
                $updated = $midtrans->sync($donation);
                $this->line($updated->order_id.': '.$updated->status);
            } catch (\Throwable $exception) {
                $failed++;
                Log::warning('Midtrans reconciliation failed', ['donation_id' => $donation->id, 'exception' => get_class($exception)]);
                $this->warn($donation->order_id.': pemeriksaan gagal; periksa konfigurasi/koneksi dan log aplikasi.');
            }
        }
        $this->info(($donations->count() - $failed).' transaksi diperiksa, '.$failed.' gagal.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
