<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Disbursement;
use App\Models\Donation;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Database\Eloquent\Builder;

class ActivityNotificationService
{
    // Call these hooks inside the transaction that records the activity. Database
    // notifications then commit or roll back together with the financial record.
    public function donationChanged(Donation $donation, string $previousStatus): void
    {
        if (! $donation->wasChanged(['status', 'refunded_amount'])) {
            return;
        }

        $campaign = $donation->campaign;
        $message = 'Donasi '.$this->rupiah($donation->amount).' untuk '.$campaign->title.': '.$donation->status_label.'.';
        if ($donation->refunded_amount > 0) {
            $message .= ' Total pengembalian '.$this->rupiah($donation->refunded_amount).'.';
        }
        $this->send([$donation->user_id], false, [
            'kind' => 'donation.status', 'title' => 'Status donasi diperbarui',
            'message' => $message, 'url' => route('payments.show', $donation),
        ]);

        $received = $donation->status === 'paid';
        $adjusted = in_array($donation->status, ['refunded', 'partially_refunded'], true)
            || in_array($previousStatus, ['paid', 'partially_refunded'], true);
        if ($received || $adjusted) {
            $this->send([$campaign->user_id], true, [
                'kind' => $received ? 'funds.received' : 'funds.adjusted',
                'title' => $received ? 'Dana donasi masuk' : 'Dana campaign disesuaikan',
                'message' => $received
                    ? 'Donasi '.$this->rupiah($donation->net_amount).' untuk '.$campaign->title.' telah diverifikasi.'
                    : 'Status donasi untuk '.$campaign->title.': '.$donation->status_label.'. Kontribusi bersih saat ini '.$this->rupiah($donation->net_amount).'.',
                'url' => route('campaign.show', $campaign),
            ], [$donation->user_id]);
        }
    }

    public function disbursementChanged(Disbursement $disbursement): void
    {
        [$title, $explanation] = match ($disbursement->status) {
            'requested' => ['Pengajuan penyaluran baru', 'Pengajuan menunggu persetujuan admin.'],
            'approved' => ['Penyaluran disetujui', 'Pengajuan disetujui dan menunggu transfer admin.'],
            'rejected' => ['Penyaluran ditolak', 'Pengajuan ditolak dan cadangan dana dilepas.'],
            'cancelled' => ['Penyaluran dibatalkan', 'Pengajuan dibatalkan dan cadangan dana dilepas.'],
            'transferred' => ['Bukti transfer diterima', 'Admin telah mengunggah bukti transfer. Penerima perlu memeriksa rekening dan mengonfirmasi dana diterima.'],
            'received' => ['Dana diterima penerima', 'Penerima telah mengonfirmasi dana masuk ke rekeningnya.'],
        };

        $participants = [$disbursement->requested_by, $disbursement->recipient_user_id];
        $activity = [
            'kind' => 'disbursement.'.$disbursement->status, 'title' => $title,
            'message' => 'Penyaluran '.$this->rupiah($disbursement->amount).' untuk '.$disbursement->campaign->title.'. '.$explanation,
            'url' => route('disbursements.show', $disbursement),
        ];
        $this->send($participants, true, $activity);

        // Admins may submit on behalf of another campaign owner. That owner can
        // see the public campaign tracker, but cannot open private proof details.
        $owner = $disbursement->campaign->user;
        if ($owner && $owner->role !== 'admin' && ! in_array($owner->id, $participants, true)) {
            $this->send([$owner->id], false, array_replace($activity, [
                'url' => route('campaign.show', $disbursement->campaign),
            ]));
        }
    }

    public function campaignSubmitted(Campaign $campaign): void
    {
        $this->send([], true, [
            'kind' => 'campaign.submitted', 'title' => 'Campaign menunggu verifikasi',
            'message' => $campaign->title.' telah diajukan untuk ditinjau.',
            'url' => route('admin'),
        ]);
    }

    public function campaignModerated(Campaign $campaign): void
    {
        $approved = $campaign->status === 'approved';
        $this->send([$campaign->user_id], false, [
            'kind' => 'campaign.'.$campaign->status,
            'title' => $approved ? 'Campaign disetujui' : 'Campaign ditolak',
            'message' => $campaign->title.($approved ? ' telah disetujui dan dapat menerima donasi.' : ' ditolak. Periksa dan perbarui campaign sebelum mengajukan kembali.'),
            'url' => route('manage.edit', $campaign),
        ]);
    }

    private function send(array $userIds, bool $includeAdmins, array $activity, array $exclude = []): void
    {
        User::query()->where(function (Builder $query) use ($userIds, $includeAdmins) {
            $query->whereIn('id', $userIds);
            if ($includeAdmins) {
                $query->orWhere('role', 'admin');
            }
        })->whereNotIn('id', $exclude)->select('id')->chunkById(200, function ($users) use ($activity) {
            foreach ($users as $user) {
                $user->notify(new ActivityNotification($activity));
            }
        });
    }

    private function rupiah(int $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }
}
