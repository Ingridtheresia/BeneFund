<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Disbursement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DisbursementService
{
    public function request(Campaign $campaign, User $actor, array $fields): Disbursement
    {
        try {
            return DB::transaction(function () use ($campaign, $actor, $fields) {
                $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
                $this->authorizeCampaign($locked, $actor);
                $existing = Disbursement::where('request_key', $fields['request_key'])->first();
                $recipient = User::whereRaw('LOWER(email) = ?', [Str::lower(trim($fields['recipient_email']))])->first();
                if (! $recipient) {
                    $this->invalid('recipient_email', 'Penerima harus memiliki akun BeneFund terlebih dahulu.');
                }
                if ($recipient->id === $actor->id) {
                    $this->invalid('recipient_email', 'Gunakan akun penerima yang berbeda dari pengaju agar penerimaan dapat dikonfirmasi secara terpisah.');
                }
                $snapshot = [
                    'campaign_id' => $locked->id, 'recipient_user_id' => $recipient->id,
                    'recipient_label' => $fields['recipient_label'], 'title' => $fields['title'],
                    'description' => $fields['description'] ?? null, 'recipient_bank' => $fields['recipient_bank'],
                    'recipient_account_name' => $fields['recipient_account_name'],
                    'recipient_account_number' => $fields['recipient_account_number'], 'amount' => (int) $fields['amount'],
                ];
                if ($existing) {
                    abort_unless($existing->requested_by === $actor->id, 403);
                    foreach ($snapshot as $field => $value) {
                        if ($existing->{$field} !== $value) {
                            $this->invalid('request_key', 'Pengajuan dengan nomor ini sudah tersimpan dengan data berbeda. Muat ulang formulir untuk membuat pengajuan baru.');
                        }
                    }

                    return $existing;
                }
                $this->approvedCampaign($locked);
                if ($snapshot['amount'] > $locked->available) {
                    $this->invalid('amount', 'Nominal melebihi saldo yang tersedia setelah pengeluaran dan dana yang sedang dicadangkan.');
                }
                $disbursement = Disbursement::create($snapshot + [
                    'public_id' => (string) Str::uuid(), 'request_key' => $fields['request_key'],
                    'requested_by' => $actor->id, 'status' => 'requested', 'requested_at' => now(),
                ]);
                $this->event($disbursement, $actor);

                return $disbursement;
            }, 3);
        } catch (QueryException $exception) {
            // Campaign locks serialize normal retries. The unique request key also
            // prevents the same request being inserted concurrently in two campaigns.
            if (Disbursement::where('request_key', $fields['request_key'])->exists()) {
                $this->invalid('request_key', 'Nomor pengajuan sudah digunakan. Muat ulang formulir sebelum mengajukan kembali.');
            }
            throw $exception;
        }
    }

    public function approve(Disbursement $disbursement, User $actor): Disbursement
    {
        $this->admin($actor);

        return $this->locked($disbursement, function (Disbursement $locked, Campaign $campaign) use ($actor) {
            abort_if($actor->id === $locked->recipient_user_id, 403, 'Penerima tidak dapat menyetujui penyaluran untuk dirinya sendiri.');
            if ($locked->status === 'approved') {
                return $locked;
            }
            $this->state($locked, ['requested']);
            $this->approvedCampaign($campaign);
            $this->checkReservedBalance($campaign);
            $locked->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $actor->id]);
            $this->event($locked, $actor);

            return $locked;
        });
    }

    public function reject(Disbursement $disbursement, User $actor, string $reason): Disbursement
    {
        $this->admin($actor);

        return $this->locked($disbursement, function (Disbursement $locked) use ($actor, $reason) {
            if ($locked->status === 'rejected') {
                return $locked;
            }
            $this->state($locked, Disbursement::RESERVED_STATUSES);
            $locked->update(['status' => 'rejected', 'rejected_at' => now(), 'reason' => $reason]);
            $this->event($locked, $actor, $reason);

            return $locked;
        });
    }

    public function cancel(Disbursement $disbursement, User $actor, string $reason): Disbursement
    {
        return $this->locked($disbursement, function (Disbursement $locked) use ($actor, $reason) {
            abort_unless($actor->role === 'admin' || $locked->requested_by === $actor->id, 403);
            if ($locked->status === 'cancelled') {
                return $locked;
            }
            $this->state($locked, Disbursement::RESERVED_STATUSES);
            $locked->update(['status' => 'cancelled', 'cancelled_at' => now(), 'reason' => $reason]);
            $this->event($locked, $actor, $reason);

            return $locked;
        });
    }

    public function transfer(Disbursement $disbursement, User $actor, array $fields, UploadedFile $proof): Disbursement
    {
        $this->admin($actor);
        $path = null;
        try {
            return $this->locked($disbursement, function (Disbursement $locked, Campaign $campaign) use ($actor, $fields, $proof, &$path) {
                abort_if($actor->id === $locked->recipient_user_id, 403, 'Penerima tidak dapat mencatat transfer untuk dirinya sendiri.');
                if (in_array($locked->status, ['transferred', 'received'], true)) {
                    return $locked;
                }
                $this->state($locked, ['approved']);
                $this->approvedCampaign($campaign);
                $this->checkReservedBalance($campaign);
                if (Disbursement::where('transfer_reference', $fields['transfer_reference'])->whereKeyNot($locked->id)->exists()) {
                    $this->invalid('transfer_reference', 'Referensi transfer sudah digunakan pada penyaluran lain.');
                }
                $path = $proof->store('disbursement-proofs', 'local');
                abort_unless(is_string($path) && $path !== '', 503, 'Bukti transfer belum dapat disimpan. Silakan coba lagi.');
                $transferredAt = isset($fields['transferred_at']) ? CarbonImmutable::parse($fields['transferred_at']) : now()->toImmutable();
                $expense = $campaign->expenses()->create([
                    'title' => $locked->title, 'amount' => $locked->amount,
                    'description' => 'Penyaluran kepada '.$locked->recipient_label.'. '.$locked->description,
                    'spent_at' => $transferredAt->toDateString(),
                ]);
                $locked->update([
                    'status' => 'transferred', 'transferred_at' => $transferredAt,
                    'transferred_by' => $actor->id, 'transfer_reference' => $fields['transfer_reference'],
                    'proof_path' => $path, 'expense_id' => $expense->id,
                ]);
                $this->event($locked, $actor);

                return $locked;
            }, 1);
        } catch (Throwable $exception) {
            if (is_string($path) && $path !== '') {
                Storage::disk('local')->delete($path);
            }
            if ($exception instanceof QueryException && Disbursement::where('transfer_reference', $fields['transfer_reference'])->exists()) {
                $this->invalid('transfer_reference', 'Referensi transfer sudah digunakan pada penyaluran lain.');
            }
            throw $exception;
        }
    }

    public function receive(Disbursement $disbursement, User $actor): Disbursement
    {
        return $this->locked($disbursement, function (Disbursement $locked) use ($actor) {
            abort_unless($locked->recipient_user_id === $actor->id, 403, 'Hanya akun penerima yang dapat mengonfirmasi penerimaan.');
            if ($locked->status === 'received') {
                return $locked;
            }
            $this->state($locked, ['transferred']);
            $locked->update(['status' => 'received', 'received_at' => now()]);
            $this->event($locked, $actor);

            return $locked;
        });
    }

    public function authorizeCampaign(Campaign $campaign, User $actor): void
    {
        abort_unless(in_array($actor->role, ['fundraiser', 'admin'], true)
            && ($actor->role === 'admin' || $campaign->user_id === $actor->id), 403);
    }

    private function locked(Disbursement $disbursement, Closure $operation, int $attempts = 3): Disbursement
    {
        return DB::transaction(function () use ($disbursement, $operation) {
            $campaign = Campaign::whereKey($disbursement->campaign_id)->lockForUpdate()->firstOrFail();
            $locked = Disbursement::whereKey($disbursement->id)->lockForUpdate()->firstOrFail();

            return $operation($locked, $campaign);
        }, $attempts);
    }

    private function checkReservedBalance(Campaign $campaign): void
    {
        if ($campaign->available < 0) {
            $this->invalid('amount', 'Saldo campaign tidak lagi mencukupi seluruh dana yang dicadangkan. Tinjau pengembalian donasi atau batalkan pengajuan sebelum melanjutkan.');
        }
    }

    private function approvedCampaign(Campaign $campaign): void
    {
        if ($campaign->status !== 'approved') {
            $this->invalid('campaign', 'Campaign harus disetujui sebelum mengajukan atau memproses penyaluran.');
        }
    }

    private function state(Disbursement $disbursement, array $allowed): void
    {
        if (! in_array($disbursement->status, $allowed, true)) {
            $this->invalid('status', 'Perubahan status tidak diizinkan pada tahap penyaluran ini.');
        }
    }

    private function admin(User $actor): void
    {
        abort_unless($actor->role === 'admin', 403);
    }

    private function event(Disbursement $disbursement, User $actor, ?string $note = null): void
    {
        $disbursement->events()->create([
            'status' => $disbursement->status, 'actor_id' => $actor->id,
            'actor_role' => $actor->role, 'note' => $note, 'created_at' => now(),
        ]);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
