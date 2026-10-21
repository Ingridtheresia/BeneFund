<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Disbursement;
use App\Models\Donation;
use App\Models\User;
use App\Services\DisbursementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DisbursementAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_raised_funds_only_include_paid_net_donations_with_each_refund_clamped_to_zero(): void
    {
        $campaign = $this->campaign();
        $this->donation($campaign, ['amount' => 100000]);
        $this->donation($campaign, ['amount' => 50000, 'status' => 'partially_refunded', 'refunded_amount' => 10000]);
        $this->donation($campaign, ['amount' => 20000, 'status' => 'partially_refunded', 'refunded_amount' => 25000]);
        $this->donation($campaign, ['amount' => 700000, 'status' => 'pending']);
        $this->donation($campaign, ['amount' => 90000, 'status' => 'refunded', 'refunded_amount' => 90000]);
        $campaign->expenses()->create(['title' => 'Laporan pengeluaran lama', 'amount' => 10000, 'spent_at' => now()->toDateString()]);
        $expected = ['raised' => 140000, 'spent' => 10000, 'reserved' => 0, 'available' => 130000, 'confirmed' => 0];

        $this->assertSame($expected, $campaign->fresh()->fundingSummary());
        $this->assertSame($expected, Campaign::withFundingTotals()->findOrFail($campaign->id)->fundingSummary());
    }

    public function test_preloaded_funding_summaries_do_not_query_each_campaign_separately(): void
    {
        foreach (range(1, 4) as $index) {
            $this->donation($this->campaign(), ['amount' => $index * 10000]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();

        $summaries = Campaign::withFundingTotals()->orderBy('id')->get()->map(fn (Campaign $campaign) => $campaign->fundingSummary());

        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame([10000, 20000, 30000, 40000], $summaries->pluck('available')->all());
    }

    public function test_reserved_transferred_and_received_states_share_one_authoritative_expense_ledger(): void
    {
        $campaign = $this->campaign();
        $this->donation($campaign, ['amount' => 140000]);
        $campaign->expenses()->create(['title' => 'Pengeluaran lama', 'amount' => 10000, 'spent_at' => now()->toDateString()]);
        $this->disbursement($campaign, 20000, 'requested');
        $this->disbursement($campaign, 15000, 'approved');
        $transferred = $this->disbursement($campaign, 30000, 'transferred');
        $received = $this->disbursement($campaign, 10000, 'received');
        $this->disbursement($campaign, 7000, 'cancelled');
        $this->disbursement($campaign, 8000, 'rejected');
        $expected = ['raised' => 140000, 'spent' => 50000, 'reserved' => 35000, 'available' => 55000, 'confirmed' => 10000];

        $this->assertSame($expected, $campaign->fresh()->fundingSummary());
        $this->assertSame($expected, Campaign::withFundingTotals()->findOrFail($campaign->id)->fundingSummary());
        $this->assertDatabaseCount('expenses', 3);
        $this->assertSame($transferred->id, $transferred->expense->disbursement->id);
        $this->assertSame($campaign->id, $received->expense->campaign->id);
        $this->assertSame(30000, $transferred->expense->amount);
        $this->assertSame(10000, $received->expense->amount);
    }

    public function test_partial_refunds_expose_a_reservation_deficit_and_block_additional_allocations(): void
    {
        $campaign = $this->campaign();
        $donation = $this->donation($campaign, ['amount' => 100000]);
        $disbursement = $this->disbursement($campaign, 80000, 'approved');
        $donation->forceFill(['status' => 'partially_refunded', 'refunded_amount' => 30000])->save();
        $this->assertSame(['raised' => 70000, 'spent' => 0, 'reserved' => 80000, 'available' => -10000, 'confirmed' => 0], $campaign->fresh()->fundingSummary());

        foreach ([
            fn () => $this->disbursement($campaign, 1000),
            fn () => app(DisbursementService::class)->transfer($disbursement, $this->user('admin'), [
                'transfer_reference' => 'REF-INSUFFICIENT', 'transferred_at' => now()->toDateTimeString(),
            ], UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf')),
        ] as $allocate) {
            try {
                $allocate();
                $this->fail('Alokasi harus ditolak setelah saldo tidak mencukupi dana cadangan.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('amount', $exception->errors());
            }
        }

        $this->assertSame('approved', $disbursement->fresh()->status);
        $this->assertDatabaseCount('disbursements', 1);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('disbursement-proofs'));
        app(DisbursementService::class)->cancel($disbursement, $campaign->user, 'Tinjau saldo setelah pengembalian donasi.');
        $this->assertSame(70000, $campaign->fresh()->available);
    }

    public function test_the_legacy_expense_endpoint_requires_the_disbursement_workflow_even_for_an_admin(): void
    {
        $campaign = $this->campaign();
        $this->donation($campaign, ['amount' => 100000]);
        $legacyExpense = $campaign->expenses()->create(['title' => 'Pengeluaran lama', 'amount' => 10000, 'spent_at' => now()->toDateString()]);
        $payload = ['title' => 'Transfer langsung', 'amount' => 20000, 'spent_at' => now()->toDateString()];

        foreach ([$campaign->user, $this->user('admin')] as $actor) {
            $this->actingAs($actor)->postJson("/manage/campaigns/{$campaign->id}/expenses", $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('disbursement');
        }
        $this->actingAs($this->user('fundraiser'))->postJson("/manage/campaigns/{$campaign->id}/expenses", $payload)
            ->assertForbidden();

        $this->assertDatabaseCount('expenses', 1);
        $this->assertSame(10000, $legacyExpense->fresh()->amount);
        $this->assertSame(90000, $campaign->fresh()->available);
    }

    public function test_public_transparency_only_includes_approved_campaigns_and_paginates_the_feed(): void
    {
        foreach (range(1, 21) as $index) {
            $this->donation($this->campaign(['title' => 'Campaign publik '.$index]), ['amount' => 10000]);
        }
        $private = $this->campaign(['title' => 'Campaign belum disetujui', 'status' => 'pending']);
        $this->donation($private, ['amount' => 900000]);
        $private->expenses()->create(['title' => 'Laporan privat', 'amount' => 800000, 'spent_at' => now()->toDateString()]);

        $this->getJson('/transparency/status')->assertOk()->assertJsonCount(20, 'campaigns')
            ->assertJsonPath('summary', ['raised' => 210000, 'spent' => 0, 'reserved' => 0, 'available' => 210000, 'confirmed' => 0])
            ->assertJsonStructure(['checked_at'])
            ->assertDontSee('Campaign belum disetujui');
        $this->getJson('/transparency/status?page=2')->assertOk()->assertJsonCount(1, 'campaigns')
            ->assertJsonPath('campaigns.0.raised', 10000)
            ->assertJsonPath('campaigns.0.available', 10000)
            ->assertDontSee('Campaign belum disetujui');
    }

    public function test_the_public_campaign_status_feed_hides_unapproved_campaigns_even_from_the_owner(): void
    {
        $campaign = $this->campaign(['status' => 'pending']);

        $this->getJson("/campaigns/{$campaign->id}/disbursements/status")->assertNotFound();
        $this->actingAs($campaign->user)->getJson("/campaigns/{$campaign->id}/disbursements/status")->assertNotFound();
    }

    public function test_public_global_summaries_exclude_private_campaign_expenses_reserves_and_receipts(): void
    {
        $campaign = $this->campaign();
        $this->donation($campaign);
        $campaign->expenses()->create(['title' => 'Pengeluaran lama', 'amount' => 10000, 'spent_at' => now()->toDateString()]);
        $this->disbursement($campaign, 20000);
        $this->disbursement($campaign, 30000, 'received');
        $private = $this->campaign(['title' => 'Catatan campaign privat']);
        $this->donation($private, ['amount' => 900000]);
        $this->disbursement($private, 50000);
        $this->disbursement($private, 70000, 'received');
        $private->update(['status' => 'pending']);

        $expected = ['raised' => 100000, 'spent' => 40000, 'reserved' => 20000, 'available' => 40000, 'confirmed' => 30000];
        $this->getJson('/transparency/status')->assertOk()->assertJsonPath('summary', $expected)
            ->assertJsonCount(1, 'campaigns')->assertJsonPath('campaigns.0.id', $campaign->id)
            ->assertJsonPath('campaigns.0.confirmed', 30000)->assertDontSee('Catatan campaign privat');
        $this->get('/transparency')->assertOk()->assertViewHas('summary', $expected);
        $this->get("/campaigns/{$campaign->id}")->assertOk()->assertViewHas('fundingSummary', $expected)
            ->assertViewHas('disbursements', fn ($disbursements) => $disbursements->count() === 2);
    }

    private function user(string $role = 'donor'): User
    {
        return User::create([
            'name' => 'Pengguna '.$role, 'email' => Str::uuid().'@example.test',
            'password' => 'password-test', 'role' => $role,
        ]);
    }

    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::create(array_replace([
            'user_id' => $this->user('fundraiser')->id, 'title' => 'Bantuan Pendidikan', 'category' => 'Pendidikan',
            'description' => 'Bantuan kebutuhan pendidikan anak-anak di daerah terpencil.', 'target' => 1000000,
            'deadline' => now()->addMonth()->toDateString(), 'status' => 'approved',
        ], $attributes));
    }

    private function donation(Campaign $campaign, array $attributes = []): Donation
    {
        $refund = (int) ($attributes['refunded_amount'] ?? 0);
        unset($attributes['refunded_amount']);
        $donation = $campaign->donations()->create(array_replace([
            'user_id' => $this->user()->id, 'amount' => 100000, 'method' => 'qris',
            'status' => 'paid', 'paid_at' => now(),
        ], $attributes));
        $donation->forceFill(['refunded_amount' => $refund])->save();

        return $donation;
    }

    private function disbursement(Campaign $campaign, int $amount, string $status = 'requested'): Disbursement
    {
        $service = app(DisbursementService::class);
        $recipient = $this->user();
        $disbursement = $service->request($campaign, $campaign->user, [
            'request_key' => (string) Str::uuid(), 'recipient_email' => $recipient->email,
            'recipient_label' => 'Penerima bantuan sekolah', 'title' => 'Penyaluran bantuan pendidikan',
            'description' => 'Bantuan kebutuhan sekolah tahap pertama.', 'recipient_bank' => 'BCA',
            'recipient_account_name' => 'Penerima Bantuan', 'recipient_account_number' => '1234567890', 'amount' => $amount,
        ]);
        $admin = $this->user('admin');
        if (in_array($status, ['approved', 'transferred', 'received'], true)) {
            $service->approve($disbursement, $admin);
        }
        if (in_array($status, ['transferred', 'received'], true)) {
            $service->transfer($disbursement, $admin, [
                'transfer_reference' => 'REF-'.Str::uuid(), 'transferred_at' => now()->toDateTimeString(),
            ], UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf'));
        }
        if ($status === 'received') {
            $service->receive($disbursement, $recipient);
        } elseif ($status === 'rejected') {
            $service->reject($disbursement, $admin, 'Pengajuan perlu diperbaiki.');
        } elseif ($status === 'cancelled') {
            $service->cancel($disbursement, $campaign->user, 'Pengajuan dibatalkan penggalang.');
        }

        return $disbursement->fresh();
    }
}
