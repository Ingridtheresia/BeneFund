<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Disbursement;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DisbursementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $recipient;

    private User $admin;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->owner = $this->user('fundraiser');
        $this->recipient = $this->user('donor', ['name' => 'Nama Privat Penerima']);
        $this->admin = $this->user('admin');
        $this->campaign = $this->campaign($this->owner);
        $this->donation($this->campaign, 100000);
    }

    private function user(string $role = 'donor', array $overrides = []): User
    {
        return User::create(array_replace([
            'name' => 'Pengguna '.Str::random(10),
            'email' => strtolower(Str::random(12)).'@example.test',
            'password' => 'Password123!',
            'role' => $role,
        ], $overrides));
    }

    private function campaign(User $owner, array $overrides = []): Campaign
    {
        return Campaign::create(array_replace([
            'user_id' => $owner->id,
            'title' => 'Bantuan Pendidikan '.Str::random(6),
            'category' => 'Pendidikan',
            'description' => 'Pengadaan perlengkapan sekolah dengan penyaluran dan penerimaan yang terdokumentasi.',
            'target' => 1000000,
            'deadline' => today()->addDays(20),
            'status' => 'approved',
        ], $overrides));
    }

    private function donation(Campaign $campaign, int $amount, string $status = 'paid'): Donation
    {
        return Donation::create([
            'user_id' => $this->user()->id,
            'campaign_id' => $campaign->id,
            'amount' => $amount,
            'method' => 'qris',
            'status' => $status,
            'paid_at' => $status === 'paid' ? now() : null,
            'anonymous' => false,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'request_key' => (string) Str::uuid(),
            'recipient_email' => $this->recipient->email,
            'recipient_label' => 'Penerima Bantuan Sekolah',
            'title' => 'Penyaluran perlengkapan sekolah',
            'description' => 'Bantuan tahap pertama untuk kebutuhan sekolah.',
            'recipient_bank' => 'BCA',
            'recipient_account_name' => 'Nama Rekening Privat',
            'recipient_account_number' => '1234567890123456',
            'amount' => 60000,
        ], $overrides);
    }

    private function requestDisbursement(array $overrides = []): Disbursement
    {
        $payload = $this->payload($overrides);
        $this->actingAs($this->owner)
            ->postJson(route('disbursements.store', $this->campaign), $payload)
            ->assertCreated();

        return Disbursement::where('request_key', $payload['request_key'])->firstOrFail();
    }

    private function approve(Disbursement $disbursement): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('disbursements.approve', $disbursement))
            ->assertOk();
    }

    private function transferPayload(array $overrides = []): array
    {
        return array_replace([
            'proof' => UploadedFile::fake()->create('bukti-transfer.pdf', 32, 'application/pdf'),
            'transfer_reference' => 'TRF-'.Str::upper(Str::random(12)),
            'transferred_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ], $overrides);
    }

    private function transfer(Disbursement $disbursement, array $overrides = []): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('disbursements.transfer', $disbursement), $this->transferPayload($overrides))
            ->assertOk();
    }

    public function test_guests_cannot_create_or_read_private_disbursements(): void
    {
        $this->get(route('disbursements.index'))->assertRedirect(route('login'));
        $this->getJson(route('disbursements.create', $this->campaign))->assertUnauthorized();
        $this->postJson(route('disbursements.store', $this->campaign), $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('disbursements', 0);
    }

    public function test_only_campaign_owner_fundraiser_or_admin_can_request_disbursement(): void
    {
        foreach ([$this->user(), $this->user('fundraiser')] as $outsider) {
            $this->actingAs($outsider)->getJson(route('disbursements.create', $this->campaign))->assertForbidden();
            $this->postJson(route('disbursements.store', $this->campaign), $this->payload())->assertForbidden();
        }

        $this->actingAs($this->admin)
            ->postJson(route('disbursements.store', $this->campaign), $this->payload())
            ->assertCreated();
        $this->assertSame($this->admin->id, Disbursement::sole()->requested_by);
    }

    public function test_request_reserves_funds_and_encrypts_the_recipient_bank_snapshot(): void
    {
        $disbursement = $this->requestDisbursement([
            'recipient_email' => '  '.strtoupper($this->recipient->email).'  ',
            'status' => 'received',
            'requested_by' => $this->admin->id,
            'recipient_user_id' => $this->owner->id,
            'expense_id' => 1234,
        ]);

        $this->assertSame('requested', $disbursement->status);
        $this->assertSame($this->owner->id, $disbursement->requested_by);
        $this->assertSame($this->recipient->id, $disbursement->recipient_user_id);
        $this->assertSame('1234567890123456', $disbursement->recipient_account_number);
        $this->assertSame('Nama Rekening Privat', $disbursement->recipient_account_name);
        $this->assertTrue(Str::isUuid($disbursement->public_id));
        $this->assertNull($disbursement->expense_id);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame(40000, $this->campaign->fresh()->available);

        $row = DB::table('disbursements')->where('id', $disbursement->id)->first();
        $this->assertNotSame('1234567890123456', $row->recipient_account_number);
        $this->assertNotSame('Nama Rekening Privat', $row->recipient_account_name);
        $this->assertStringNotContainsString('1234567890123456', $disbursement->toJson());
        $this->assertStringNotContainsString('Nama Rekening Privat', $disbursement->toJson());
    }

    public function test_unapproved_campaigns_cannot_request_or_publish_disbursements(): void
    {
        foreach (['pending', 'rejected'] as $status) {
            $this->campaign->update(['status' => $status]);
            $this->actingAs($this->owner)
                ->postJson(route('disbursements.store', $this->campaign), $this->payload())
                ->assertUnprocessable();
            $this->getJson(route('campaign.disbursements', $this->campaign))->assertNotFound();
        }
        $this->assertDatabaseCount('disbursements', 0);
    }

    public function test_request_requires_valid_existing_distinct_recipient_and_bank_data(): void
    {
        $cases = [
            ['recipient_email', 'nobody@example.test'],
            ['recipient_email', $this->owner->email],
            ['recipient_email', 'invalid-email'],
            ['recipient_account_number', '12345'],
            ['recipient_account_number', '123456abc'],
            ['recipient_account_number', str_repeat('1', 31)],
            ['recipient_account_name', ''],
            ['recipient_label', str_repeat('a', 151)],
            ['amount', 999],
            ['amount', 1000.5],
            ['request_key', 'not-a-uuid'],
        ];

        foreach ($cases as [$field, $value]) {
            $this->actingAs($this->owner)
                ->postJson(route('disbursements.store', $this->campaign), $this->payload([$field => $value]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('disbursements', 0);
        $this->assertSame(100000, $this->campaign->fresh()->available);
    }

    public function test_available_funds_exclude_unpaid_donations_and_existing_expenses(): void
    {
        $this->donation($this->campaign, 900000, 'pending');
        $this->campaign->expenses()->create(['title' => 'Pengeluaran sebelumnya', 'amount' => 30000, 'spent_at' => today()]);

        $this->actingAs($this->owner)
            ->postJson(route('disbursements.store', $this->campaign), $this->payload(['amount' => 70001]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
        $this->requestDisbursement(['amount' => 70000]);
        $this->assertSame(0, $this->campaign->fresh()->available);
    }

    public function test_request_retry_is_idempotent_but_changed_payload_cannot_reuse_the_key(): void
    {
        $payload = $this->payload();
        $this->actingAs($this->owner)->postJson(route('disbursements.store', $this->campaign), $payload)->assertCreated();
        $disbursement = Disbursement::sole();
        $events = $disbursement->events()->count();
        $this->postJson(route('disbursements.store', $this->campaign), $payload)->assertSuccessful();
        $this->postJson(route('disbursements.store', $this->campaign), array_replace($payload, ['amount' => 50000]))
            ->assertUnprocessable();

        $this->assertDatabaseCount('disbursements', 1);
        $this->assertSame($events, $disbursement->events()->count());
        $this->assertSame(40000, $this->campaign->fresh()->available);
    }

    public function test_another_requester_cannot_reuse_an_existing_request_key(): void
    {
        $disbursement = $this->requestDisbursement();
        $anotherOwner = $this->user('fundraiser');
        $anotherCampaign = $this->campaign($anotherOwner);
        $this->donation($anotherCampaign, 100000);

        $this->actingAs($anotherOwner)
            ->postJson(route('disbursements.store', $anotherCampaign), $this->payload(['request_key' => $disbursement->request_key]))
            ->assertForbidden();
        $this->assertDatabaseCount('disbursements', 1);
    }

    public function test_pending_requests_reserve_the_balance_and_the_legacy_expense_endpoint_cannot_bypass_approval(): void
    {
        $this->requestDisbursement(['amount' => 70000]);
        $this->actingAs($this->owner)
            ->postJson(route('disbursements.store', $this->campaign), $this->payload(['amount' => 30001]))
            ->assertUnprocessable();
        $this->postJson(route('manage.expenses', $this->campaign), [
            'title' => 'Tidak boleh melewati reservasi', 'amount' => 30001, 'spent_at' => today()->format('Y-m-d'),
        ])->assertUnprocessable()->assertJsonValidationErrors('disbursement');
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('disbursements', 1);

        $this->postJson(route('manage.expenses', $this->campaign), [
            'title' => 'Pengeluaran dalam saldo', 'amount' => 30000, 'spent_at' => today()->format('Y-m-d'),
        ])->assertUnprocessable()->assertJsonValidationErrors('disbursement');
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame(30000, $this->campaign->fresh()->available);
    }

    public function test_outsiders_cannot_read_private_details_status_or_proof(): void
    {
        $disbursement = $this->requestDisbursement();
        foreach ([$this->user(), $this->user('fundraiser')] as $outsider) {
            $this->actingAs($outsider);
            foreach (['show', 'status', 'proof'] as $action) {
                $this->getJson(route('disbursements.'.$action, $disbursement))->assertForbidden();
            }
            $this->get(route('disbursements.index'))->assertOk()->assertDontSee($disbursement->title);
        }
    }

    public function test_approval_requires_admin_and_records_an_immutable_actor_snapshot(): void
    {
        $disbursement = $this->requestDisbursement();
        foreach ([$this->owner, $this->recipient, $this->user('fundraiser')] as $user) {
            $this->actingAs($user)->postJson(route('disbursements.approve', $disbursement))->assertForbidden();
        }

        $this->approve($disbursement);
        $this->assertSame('approved', $disbursement->fresh()->status);
        $events = $disbursement->events()->count();
        $this->postJson(route('disbursements.approve', $disbursement))->assertOk();
        $this->assertSame($events, $disbursement->events()->count());
        $this->assertTrue($disbursement->events()->where('status', 'approved')->where('actor_id', $this->admin->id)->where('actor_role', 'admin')->exists());
        $this->assertSame(40000, $this->campaign->fresh()->available);
    }

    public function test_an_admin_recipient_cannot_approve_or_record_own_incoming_transfer(): void
    {
        $adminRecipient = $this->user('admin');
        $disbursement = $this->requestDisbursement(['recipient_email' => $adminRecipient->email]);
        $this->actingAs($adminRecipient)->postJson(route('disbursements.approve', $disbursement))->assertForbidden();
        $this->approve($disbursement);
        $this->actingAs($adminRecipient)
            ->postJson(route('disbursements.transfer', $disbursement), $this->transferPayload())
            ->assertForbidden();
        $this->assertSame('approved', $disbursement->fresh()->status);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_rejection_requires_a_reason_and_releases_reserved_funds_once(): void
    {
        $disbursement = $this->requestDisbursement();
        $this->actingAs($this->owner)->postJson(route('disbursements.reject', $disbursement), ['reason' => 'Ditolak'])->assertForbidden();
        $this->approve($disbursement);
        $this->postJson(route('disbursements.reject', $disbursement))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(route('disbursements.reject', $disbursement), ['reason' => 'Dokumen belum lengkap'])->assertOk();
        $this->assertSame('rejected', $disbursement->fresh()->status);
        $this->assertSame(100000, $this->campaign->fresh()->available);
        $events = $disbursement->events()->count();
        $this->postJson(route('disbursements.reject', $disbursement), ['reason' => 'Dokumen belum lengkap'])->assertOk();
        $this->assertSame($events, $disbursement->events()->count());
        $this->assertDatabaseCount('expenses', 0);
        $this->postJson(route('disbursements.approve', $disbursement))->assertUnprocessable();
    }

    public function test_requester_or_admin_can_cancel_before_transfer_and_release_the_reservation(): void
    {
        $first = $this->requestDisbursement(['amount' => 40000]);
        $second = $this->requestDisbursement(['amount' => 40000]);
        $this->approve($second);
        $this->actingAs($this->recipient)->postJson(route('disbursements.cancel', $first), ['reason' => 'Rencana penyaluran berubah'])->assertForbidden();
        $this->actingAs($this->owner)->postJson(route('disbursements.cancel', $first), ['reason' => 'Rencana penyaluran berubah'])->assertOk();
        $events = $first->events()->count();
        $this->postJson(route('disbursements.cancel', $first), ['reason' => 'Rencana penyaluran berubah'])->assertOk();
        $this->assertSame($events, $first->events()->count());
        $this->actingAs($this->admin)->postJson(route('disbursements.cancel', $second), ['reason' => 'Rencana penyaluran berubah'])->assertOk();
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('cancelled', $second->fresh()->status);
        $this->assertSame(100000, $this->campaign->fresh()->available);
    }

    public function test_transfer_requires_prior_approval_and_an_admin_actor(): void
    {
        $disbursement = $this->requestDisbursement();
        $this->actingAs($this->admin)
            ->postJson(route('disbursements.transfer', $disbursement), $this->transferPayload())
            ->assertUnprocessable();
        $this->approve($disbursement);
        $this->actingAs($this->owner)
            ->postJson(route('disbursements.transfer', $disbursement), $this->transferPayload())
            ->assertForbidden();
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('disbursement-proofs'));
    }

    public function test_transfer_rejects_missing_invalid_or_oversized_proof_reference_and_future_dates(): void
    {
        $disbursement = $this->requestDisbursement();
        $this->approve($disbursement);
        $cases = [
            ['proof', null],
            ['proof', UploadedFile::fake()->create('malware.php', 1, 'application/x-httpd-php')],
            ['proof', UploadedFile::fake()->create('too-large.pdf', 5121, 'application/pdf')],
            ['transfer_reference', ''],
            ['transfer_reference', str_repeat('a', 121)],
            ['transferred_at', now()->addDay()->format('Y-m-d H:i:s')],
        ];

        foreach ($cases as [$field, $value]) {
            $this->postJson(route('disbursements.transfer', $disbursement), $this->transferPayload([$field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame('approved', $disbursement->fresh()->status);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('disbursement-proofs'));
    }

    public function test_transfer_creates_one_expense_and_keeps_proof_private_without_double_deducting(): void
    {
        $disbursement = $this->requestDisbursement();
        $this->approve($disbursement);
        $this->transfer($disbursement, ['transfer_reference' => 'BANK-REF-123456']);
        $disbursement->refresh();

        $this->assertSame('transferred', $disbursement->status);
        $this->assertSame('BANK-REF-123456', $disbursement->transfer_reference);
        $this->assertNotNull($disbursement->transferred_at);
        $this->assertNotNull($disbursement->expense_id);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertSame(60000, (int) $disbursement->expense->amount);
        $this->assertSame($this->campaign->id, $disbursement->expense->campaign_id);
        $this->assertSame(40000, $this->campaign->fresh()->available);
        Storage::disk('local')->assertExists($disbursement->proof_path);
        Storage::disk('public')->assertMissing($disbursement->proof_path);
    }

    public function test_transfer_retry_cannot_create_duplicate_expenses_files_or_audit_events(): void
    {
        $disbursement = $this->requestDisbursement();
        $this->approve($disbursement);
        $this->transfer($disbursement, ['transfer_reference' => 'BANK-IDEMPOTENT']);
        $events = $disbursement->events()->count();
        $files = Storage::disk('local')->allFiles('disbursement-proofs');
        $this->transfer($disbursement, ['transfer_reference' => 'BANK-IDEMPOTENT']);

        $this->assertDatabaseCount('expenses', 1);
        $this->assertSame($events, $disbursement->events()->count());
        $this->assertSame($files, Storage::disk('local')->allFiles('disbursement-proofs'));
        $this->assertSame(40000, $this->campaign->fresh()->available);
    }

    public function test_a_bank_reference_cannot_be_claimed_by_two_disbursements(): void
    {
        $first = $this->requestDisbursement(['amount' => 40000]);
        $second = $this->requestDisbursement(['amount' => 40000]);
        $this->approve($first);
        $this->approve($second);
        $this->transfer($first, ['transfer_reference' => 'BANK-UNIQUE-REFERENCE']);
        $files = Storage::disk('local')->allFiles('disbursement-proofs');

        $this->postJson(route('disbursements.transfer', $second), $this->transferPayload(['transfer_reference' => 'BANK-UNIQUE-REFERENCE']))
            ->assertUnprocessable()->assertJsonValidationErrors('transfer_reference');
        $this->assertSame('approved', $second->fresh()->status);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertSame($files, Storage::disk('local')->allFiles('disbursement-proofs'));
    }

    public function test_a_later_loss_of_funding_blocks_transfer_without_leaving_expense_or_proof(): void
    {
        $disbursement = $this->requestDisbursement();
        $this->approve($disbursement);
        $events = $disbursement->events()->count();
        $this->campaign->donations()->update(['status' => 'refunded']);

        $this->postJson(route('disbursements.transfer', $disbursement), $this->transferPayload())->assertUnprocessable();
        $this->assertSame('approved', $disbursement->fresh()->status);
        $this->assertNull($disbursement->expense_id);
        $this->assertNull($disbursement->proof_path);
        $this->assertSame($events, $disbursement->events()->count());
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('disbursement-proofs'));
    }

    public function test_database_failure_after_proof_upload_rolls_back_the_ledger_and_removes_the_orphan_file(): void
    {
        $disbursement = $this->requestDisbursement();
        $this->approve($disbursement);
        $events = $disbursement->events()->count();
        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();
        $isolatedDispatcher = clone $originalDispatcher;
        $connection->setEventDispatcher($isolatedDispatcher);
        $isolatedDispatcher->listen(QueryExecuted::class, static function (QueryExecuted $query): void {
            if (preg_match('/^insert\s+into\s+["`]?expenses["`]?\s*/i', ltrim($query->sql))) {
                throw new RuntimeException('Simulated ledger storage failure');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->admin)->postJson(route('disbursements.transfer', $disbursement), $this->transferPayload());
            $this->fail('The simulated ledger failure must interrupt the transfer.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated ledger storage failure', $exception->getMessage());
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        $this->assertSame('approved', $disbursement->fresh()->status);
        $this->assertNull($disbursement->proof_path);
        $this->assertNull($disbursement->expense_id);
        $this->assertSame($events, $disbursement->events()->count());
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('disbursement-proofs'));
        $this->assertSame(40000, $this->campaign->fresh()->available);
    }

    public function test_only_the_designated_recipient_can_explicitly_confirm_after_transfer(): void
    {
        $disbursement = $this->requestDisbursement();
        $this->actingAs($this->recipient)->postJson(route('disbursements.receive', $disbursement), ['confirmed' => true])->assertUnprocessable();
        $this->approve($disbursement);
        $this->transfer($disbursement);

        foreach ([$this->owner, $this->admin, $this->user()] as $outsider) {
            $this->actingAs($outsider)->postJson(route('disbursements.receive', $disbursement), ['confirmed' => true])->assertForbidden();
        }
        $this->actingAs($this->recipient)->postJson(route('disbursements.receive', $disbursement))->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->postJson(route('disbursements.receive', $disbursement), ['confirmed' => false])->assertUnprocessable();
        $this->assertSame('transferred', $disbursement->fresh()->status);
    }

    public function test_recipient_confirmation_is_recorded_once_without_a_second_expense(): void
    {
        $disbursement = $this->requestDisbursement();
        $this->approve($disbursement);
        $this->transfer($disbursement);
        $this->actingAs($this->recipient)->postJson(route('disbursements.receive', $disbursement), ['confirmed' => true])->assertOk();
        $disbursement->refresh();
        $this->assertSame('received', $disbursement->status);
        $this->assertNotNull($disbursement->received_at);
        $events = $disbursement->events()->count();
        $this->postJson(route('disbursements.receive', $disbursement), ['confirmed' => true])->assertOk();
        $this->assertSame($events, $disbursement->events()->count());
        $this->assertTrue($disbursement->events()->where('status', 'received')->where('actor_id', $this->recipient->id)->exists());
        $this->assertDatabaseCount('expenses', 1);
        $this->assertSame(40000, $this->campaign->fresh()->available);
    }

    public function test_transferred_or_received_disbursements_cannot_be_cancelled_or_rejected(): void
    {
        $disbursement = $this->requestDisbursement();
        $this->approve($disbursement);
        $this->transfer($disbursement);
        foreach (['transferred', 'received'] as $status) {
            if ($status === 'received') {
                $this->actingAs($this->recipient)->postJson(route('disbursements.receive', $disbursement), ['confirmed' => true])->assertOk();
            }
            $this->actingAs($this->admin)->postJson(route('disbursements.reject', $disbursement), ['reason' => 'Tidak boleh membatalkan dana terkirim'])->assertUnprocessable();
            $this->postJson(route('disbursements.cancel', $disbursement), ['reason' => 'Tidak boleh membatalkan dana terkirim'])->assertUnprocessable();
            $this->assertSame($status, $disbursement->fresh()->status);
        }
        $this->assertDatabaseCount('expenses', 1);
        $this->assertSame(40000, $this->campaign->fresh()->available);
    }

    public function test_proof_download_is_limited_to_parties_and_only_after_upload(): void
    {
        $disbursement = $this->requestDisbursement();
        $this->actingAs($this->recipient)->get(route('disbursements.proof', $disbursement))->assertNotFound();
        $this->approve($disbursement);
        $this->transfer($disbursement);

        foreach ([$this->owner, $this->recipient, $this->admin] as $party) {
            $this->actingAs($party)->get(route('disbursements.proof', $disbursement))->assertOk()->assertHeader('content-disposition');
            $this->getJson(route('disbursements.status', $disbursement))->assertOk();
            $this->get(route('disbursements.show', $disbursement))->assertOk();
        }
        $this->actingAs($this->user())->getJson(route('disbursements.proof', $disbursement))->assertForbidden();
    }

    public function test_public_feed_exposes_status_and_amount_without_recipient_pii_or_private_evidence(): void
    {
        $disbursement = $this->requestDisbursement(['description' => 'Pembelian perlengkapan sekolah tahap pertama']);
        $this->approve($disbursement);
        $this->transfer($disbursement, ['transfer_reference' => 'REF-PRIVATE-7X9Q']);
        $disbursement->refresh();
        $this->app['auth']->forgetGuards();
        $response = $this->getJson(route('campaign.disbursements', $this->campaign))->assertOk();
        $response->assertSee('Penerima Bantuan Sekolah')->assertSee('transferred')->assertSee('60000')
            ->assertSee('Pembelian perlengkapan sekolah tahap pertama');
        $publicJson = json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach ([
            $this->recipient->email,
            'Nama Rekening Privat',
            'Nama Privat Penerima',
            '1234567890123456',
            'REF-PRIVATE-7X9Q',
            $disbursement->proof_path,
            route('disbursements.proof', $disbursement),
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $publicJson);
        }
        foreach (['recipient_account_number', 'recipient_account_name', 'recipient_email', 'proof_path', 'proof_url', 'transfer_reference', 'actor_id', 'note'] as $privateKey) {
            $this->assertStringNotContainsString('"'.$privateKey.'"', $publicJson);
        }
    }

    public function test_public_rejection_timeline_keeps_the_internal_reason_private(): void
    {
        $disbursement = $this->requestDisbursement();
        $this->actingAs($this->admin)->postJson(route('disbursements.reject', $disbursement), [
            'reason' => 'Dokumen rekening privat tidak sesuai ID-PRIVAT-7162',
        ])->assertOk();

        $response = $this->getJson(route('campaign.disbursements', $this->campaign))->assertOk();
        $response->assertSee('rejected')->assertDontSee('ID-PRIVAT-7162');
        $publicJson = json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('"reason"', $publicJson);
        $this->assertStringNotContainsString('"note"', $publicJson);
        $this->assertStringNotContainsString($this->recipient->email, $publicJson);
    }
}
