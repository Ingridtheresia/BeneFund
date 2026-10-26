<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\User;
use App\Services\DisbursementService;
use App\Services\MidtransService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class NotificationEventsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $donor;

    private User $recipient;

    private User $admin;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        config(['midtrans.server_key' => 'test-server-key', 'midtrans.client_key' => 'test-client-key']);
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->owner = $this->user('fundraiser');
        $this->donor = $this->user();
        $this->recipient = $this->user();
        $this->admin = $this->user('admin');
        $this->campaign = Campaign::create($this->campaignFields() + ['user_id' => $this->owner->id, 'status' => 'approved']);
    }

    public function test_verified_payment_notifies_donor_owner_and_admin_once_and_keeps_anonymous_identity_private(): void
    {
        $unrelated = $this->user();
        $donation = $this->donation(['anonymous' => true]);
        $this->sync($donation);
        $this->sync($donation);

        $this->assertSame(3, DB::table('notifications')->count());
        $this->assertSame('donation.status', $this->donor->notifications()->sole()->data['kind']);
        $this->assertSame(route('payments.show', $donation), $this->donor->notifications()->sole()->data['url']);
        foreach ([$this->owner, $this->admin] as $user) {
            $data = $user->notifications()->sole()->data;
            $this->assertSame('funds.received', $data['kind']);
            $this->assertStringContainsString('Rp100.000', $data['message']);
            $this->assertSame(route('campaign.show', $this->campaign), $data['url']);
            $this->assertStringNotContainsString($this->donor->name, json_encode($data));
            $this->assertStringNotContainsString($this->donor->email, json_encode($data));
        }
        $this->assertSame(0, $unrelated->notifications()->count());
    }

    public function test_failed_and_expired_payments_notify_donor_without_claiming_funds_received(): void
    {
        foreach (['expire' => 'Kedaluwarsa', 'deny' => 'Gagal'] as $status => $label) {
            $this->sync($this->donation(), ['transaction_status' => $status]);
            $this->assertTrue($this->donor->notifications()->get()->contains(fn ($item) => str_contains($item->data['message'], $label)));
        }

        $this->assertSame(2, $this->donor->notifications()->count());
        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_partial_refund_changes_notify_even_when_status_is_unchanged_and_replays_are_silent(): void
    {
        $donation = $this->donation();
        $this->sync($donation);
        $this->sync($donation, ['transaction_status' => 'partial_refund', 'refund_amount' => '20000.00']);
        $this->sync($donation, ['transaction_status' => 'partial_refund', 'refund_amount' => '20000.00']);
        $this->sync($donation, ['transaction_status' => 'partial_refund', 'refund_amount' => '30000.00']);
        $this->sync($donation, ['transaction_status' => 'partial_refund', 'refund_amount' => '10000.00']);

        $this->assertSame(9, DB::table('notifications')->count());
        $this->assertSame(3, $this->donor->notifications()->count());
        $this->assertSame(2, $this->owner->notifications()->get()->where('data.kind', 'funds.adjusted')->count());
        $this->assertTrue($this->donor->notifications()->get()->contains(fn ($item) => str_contains($item->data['message'], 'Rp30.000')));
    }

    public function test_unverified_or_ignored_statuses_do_not_create_notifications(): void
    {
        $donation = $this->donation();
        $this->sync($donation, ['transaction_status' => 'pending']);
        $this->assertSame(0, DB::table('notifications')->count());
        try {
            $this->sync($donation, ['gross_amount' => '1.00']);
            $this->fail('Invalid amount must be rejected.');
        } catch (ValidationException) {
            $this->assertSame('pending', $donation->fresh()->status);
            $this->assertSame(0, DB::table('notifications')->count());
        }
        $this->sync($donation);
        $this->sync($donation, ['transaction_status' => 'pending']);
        $this->assertSame(3, DB::table('notifications')->count());
    }

    public function test_database_rollback_removes_payment_and_its_notifications_together(): void
    {
        $donation = $this->donation();
        try {
            DB::transaction(function () use ($donation) {
                $this->sync($donation);
                $this->assertSame(3, DB::table('notifications')->count());
                throw new RuntimeException('Force transaction rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Force transaction rollback', $exception->getMessage());
        }

        $this->assertSame('pending', $donation->fresh()->status);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_user_with_multiple_roles_in_the_activity_receives_only_one_notification(): void
    {
        $donation = $this->donation(['user_id' => $this->admin->id]);
        $this->sync($donation);

        $this->assertSame(2, DB::table('notifications')->count());
        $this->assertSame(1, $this->admin->notifications()->count());
    }

    public function test_disbursement_lifecycle_notifies_participants_and_admin_with_no_duplicate_retries(): void
    {
        $this->donation(['status' => 'paid', 'paid_at' => now()]);
        $unrelated = $this->user();
        $service = app(DisbursementService::class);
        $fields = $this->disbursementFields();
        $disbursement = $service->request($this->campaign, $this->owner, $fields);
        $service->request($this->campaign, $this->owner, $fields);
        $service->approve($disbursement, $this->admin);
        $service->approve($disbursement, $this->admin);
        $transfer = ['transfer_reference' => 'NOTIFICATION-TEST'];
        $service->transfer($disbursement, $this->admin, $transfer, UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'));
        $service->transfer($disbursement, $this->admin, $transfer, UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'));
        $service->receive($disbursement, $this->recipient);
        $service->receive($disbursement, $this->recipient);

        $this->assertSame(12, DB::table('notifications')->count());
        foreach ([$this->owner, $this->recipient, $this->admin] as $user) {
            $activities = $user->notifications()->get()->pluck('data');
            $this->assertCount(4, $activities);
            $this->assertContains('disbursement.requested', $activities->pluck('kind'));
            $this->assertContains('disbursement.approved', $activities->pluck('kind'));
            $this->assertContains('disbursement.transferred', $activities->pluck('kind'));
            $this->assertContains('disbursement.received', $activities->pluck('kind'));
            $proof = $activities->firstWhere('kind', 'disbursement.transferred');
            $this->assertSame('Bukti transfer diterima', $proof['title']);
            $this->assertStringContainsString('mengonfirmasi', $proof['message']);
            $this->assertSame(route('disbursements.show', $disbursement), $proof['url']);
            $this->assertStringNotContainsString('1234567890123456', json_encode($activities));
            $this->assertStringNotContainsString('Nama Rekening Rahasia', json_encode($activities));
            $this->assertStringNotContainsString('NOTIFICATION-TEST', json_encode($activities));
        }
        $this->assertSame(0, $unrelated->notifications()->count());
    }

    public function test_rejected_and_cancelled_disbursements_notify_without_exposing_private_reasons(): void
    {
        $this->donation(['status' => 'paid', 'paid_at' => now()]);
        $service = app(DisbursementService::class);
        $rejected = $service->request($this->campaign, $this->owner, $this->disbursementFields());
        $service->reject($rejected, $this->admin, 'Catatan internal rahasia');
        $service->reject($rejected, $this->admin, 'Catatan internal rahasia');
        $cancelled = $service->request($this->campaign, $this->owner, $this->disbursementFields());
        $service->cancel($cancelled, $this->owner, 'Alasan pembatalan rahasia');
        $service->cancel($cancelled, $this->owner, 'Alasan pembatalan rahasia');

        $this->assertSame(12, DB::table('notifications')->count());
        $data = json_encode(DB::table('notifications')->pluck('data'));
        $this->assertStringNotContainsString('Catatan internal rahasia', $data);
        $this->assertStringNotContainsString('Alasan pembatalan rahasia', $data);
    }

    public function test_disbursement_notification_deduplicates_admin_who_is_also_requester(): void
    {
        $this->donation(['status' => 'paid', 'paid_at' => now()]);
        $disbursement = app(DisbursementService::class)->request($this->campaign, $this->admin, $this->disbursementFields());

        $this->assertSame(3, DB::table('notifications')->count());
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame(1, $this->recipient->notifications()->count());
        $this->assertSame(1, $this->owner->notifications()->count());
        $ownerUrl = $this->owner->notifications()->sole()->data['url'];
        $this->assertSame(route('campaign.show', $this->campaign), $ownerUrl);
        $this->actingAs($this->owner)->get($ownerUrl)->assertOk();
        $this->get(route('disbursements.show', $disbursement))->assertForbidden();
    }

    public function test_database_rollback_removes_disbursement_its_reservation_and_notifications_together(): void
    {
        $this->donation(['status' => 'paid', 'paid_at' => now()]);
        try {
            DB::transaction(function () {
                app(DisbursementService::class)->request($this->campaign, $this->owner, $this->disbursementFields());
                $this->assertSame(3, DB::table('notifications')->count());
                $this->assertSame(50000, $this->campaign->fresh()->reserved);
                throw new RuntimeException('Force disbursement rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Force disbursement rollback', $exception->getMessage());
        }

        $this->assertDatabaseCount('disbursements', 0);
        $this->assertDatabaseCount('disbursement_events', 0);
        $this->assertSame(0, $this->campaign->fresh()->reserved);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_campaign_submission_and_moderation_notify_the_correct_accounts(): void
    {
        $fields = $this->campaignFields(['title' => 'Campaign baru']);
        $this->actingAs($this->owner)->post(route('manage.store'), $fields)->assertRedirect();
        $campaign = Campaign::where('title', 'Campaign baru')->sole();
        $this->put(route('manage.update', $campaign), $fields)->assertRedirect();
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame('campaign.submitted', $this->admin->notifications()->sole()->data['kind']);
        $this->assertSame(0, $this->owner->notifications()->count());

        $this->actingAs($this->admin)->post(route('admin.approve', $campaign))->assertRedirect();
        $this->assertSame('campaign.approved', $this->owner->notifications()->sole()->data['kind']);
        $this->post(route('admin.approve', $campaign))->assertSessionHasErrors('campaign');
        $this->assertSame(1, $this->owner->notifications()->count());

        $this->actingAs($this->owner)->put(route('manage.update', $campaign), $fields)->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.reject', $campaign))->assertRedirect();
        $this->assertSame(2, $this->owner->notifications()->count());
        $this->assertTrue($this->owner->notifications()->get()->contains(fn ($item) => $item->data['kind'] === 'campaign.rejected'));
    }

    private function user(string $role = 'donor'): User
    {
        return User::create(['name' => 'Pengguna '.Str::random(8), 'email' => Str::uuid().'@example.test', 'password' => 'Password123!', 'role' => $role]);
    }

    private function campaignFields(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Bantuan pendidikan', 'category' => 'Pendidikan',
            'description' => 'Penggalangan bantuan pendidikan dengan laporan penggunaan dana untuk kebutuhan sekolah.',
            'target' => 1000000, 'deadline' => today()->addDays(30)->format('Y-m-d'),
        ], $overrides);
    }

    private function donation(array $overrides = []): Donation
    {
        return Donation::create(array_replace([
            'user_id' => $this->donor->id, 'campaign_id' => $this->campaign->id,
            'amount' => 100000, 'method' => 'qris', 'anonymous' => false,
            'status' => 'pending', 'order_id' => 'BF-'.Str::uuid(),
        ], $overrides));
    }

    private function sync(Donation $donation, array $overrides = []): void
    {
        $payload = array_replace([
            'order_id' => $donation->order_id, 'status_code' => '200',
            'gross_amount' => number_format($donation->total_amount, 2, '.', ''),
            'transaction_status' => 'settlement', 'transaction_id' => 'txn-'.$donation->id,
            'fraud_status' => 'accept', 'payment_type' => 'qris',
        ], $overrides);
        // Each call represents a fresh gateway response, including later refunds.
        // Laravel appends fake callbacks, so replace the test client before reusing a URL.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([config('midtrans.api_url').'/'.$donation->order_id.'/status' => Http::response($payload)]);
        app(MidtransService::class)->sync($donation);
    }

    private function disbursementFields(): array
    {
        return [
            'request_key' => (string) Str::uuid(), 'recipient_email' => $this->recipient->email,
            'recipient_label' => 'Penerima pendidikan', 'title' => 'Bantuan perlengkapan sekolah',
            'recipient_bank' => 'BCA', 'recipient_account_name' => 'Nama Rekening Rahasia',
            'recipient_account_number' => '1234567890123456', 'amount' => 50000,
        ];
    }
}
