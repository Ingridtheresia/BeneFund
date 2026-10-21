<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentFeesTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-test';

    private const SNAP_URL = 'https://app.sandbox.midtrans.com/snap/v1/transactions';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'midtrans.server_key' => self::SERVER_KEY,
            'midtrans.client_key' => 'SB-Mid-client-test',
            'midtrans.is_production' => false,
        ]);
        Http::preventStrayRequests();
    }

    public static function paymentChannels(): array
    {
        return [
            'BCA VA' => ['bank', 'bca_va', 4500],
            'BNI VA' => ['bank', 'bni_va', 4500],
            'BRI VA' => ['bank', 'bri_va', 4500],
            'Mandiri bill payment' => ['bank', 'echannel', 4500],
            'Permata VA' => ['bank', 'permata_va', 4500],
            'CIMB VA' => ['bank', 'cimb_va', 4500],
            'GoPay' => ['ewallet', 'gopay', 1500],
            'ShopeePay' => ['ewallet', 'shopeepay', 1500],
            'QRIS' => ['qris', 'other_qris', 0],
        ];
    }

    #[DataProvider('paymentChannels')]
    public function test_selected_channel_charges_the_server_fee_and_restricts_checkout(string $method, string $channel, int $fee): void
    {
        Http::fake([self::SNAP_URL => Http::response([
            'token' => 'sandbox-selected-channel',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/sandbox-selected-channel',
        ])]);
        $campaign = $this->campaign();
        $this->actingAs($this->user())->post("/campaigns/{$campaign->id}/donate", [
            'amount' => 100000,
            'method' => $method,
            'payment_channel' => $channel,
            'admin_fee' => 1,
            'total_amount' => 1,
        ])->assertRedirect();

        $donation = Donation::sole();
        $this->assertSame(100000, $donation->amount);
        $this->assertSame($fee, $donation->admin_fee);
        $this->assertSame(100000 + $fee, $donation->total_amount);
        $this->assertSame($channel, $donation->payment_channel);
        $this->assertSame(0, $campaign->fresh()->raised);

        $this->postJson("/payments/{$donation->id}/checkout", [
            'amount' => 1, 'admin_fee' => 0, 'total_amount' => 1, 'payment_channel' => 'untrusted',
        ])->assertOk();

        Http::assertSent(function (Request $request) use ($channel, $fee): bool {
            $items = $request['item_details'];

            return $request->url() === self::SNAP_URL
                && $request['enabled_payments'] === [$channel]
                && $request['transaction_details']['gross_amount'] === 100000 + $fee
                && $items[0]['price'] === 100000
                && count($items) === ($fee > 0 ? 2 : 1)
                && ($fee === 0 || $items[1]['price'] === $fee)
                && array_sum(array_map(fn (array $item) => $item['price'] * $item['quantity'], $items)) === 100000 + $fee;
        });
        Http::assertSentCount(1);
    }

    public static function invalidChannels(): array
    {
        return [
            'bank requires a choice' => ['bank', null],
            'wallet requires a choice' => ['ewallet', null],
            'bank cannot choose wallet' => ['bank', 'gopay'],
            'wallet cannot choose bank' => ['ewallet', 'bca_va'],
            'QRIS cannot choose wallet' => ['qris', 'gopay'],
            'unknown bank' => ['bank', 'fake_bank'],
        ];
    }

    #[DataProvider('invalidChannels')]
    public function test_missing_or_incompatible_channels_cannot_create_a_donation(string $method, ?string $channel): void
    {
        Http::fake();
        $campaign = $this->campaign();

        $this->actingAs($this->user())->postJson("/campaigns/{$campaign->id}/donate", [
            'amount' => 100000, 'method' => $method, 'payment_channel' => $channel,
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_channel');

        $this->assertDatabaseCount('donations', 0);
        Http::assertNothingSent();
    }

    public function test_qris_defaults_to_the_free_qris_channel(): void
    {
        $campaign = $this->campaign();

        $this->actingAs($this->user())->post("/campaigns/{$campaign->id}/donate", [
            'amount' => 100000, 'method' => 'qris', 'admin_fee' => 4500,
        ])->assertRedirect();

        $donation = Donation::sole();
        $this->assertSame('other_qris', $donation->payment_channel);
        $this->assertSame(0, $donation->admin_fee);
        $this->assertSame(100000, $donation->total_amount);
    }

    public function test_signed_notification_and_authoritative_status_must_include_the_charged_fee(): void
    {
        $donation = $this->donation();
        Http::fake();
        $principalOnly = $this->gatewayStatus($donation, ['gross_amount' => '100000.00']);
        $principalOnly['signature_key'] = $this->signature($principalOnly);

        $this->postJson('/payments/midtrans/notification', $principalOnly)->assertUnprocessable();
        $this->assertSame('pending', $donation->fresh()->status);
        Http::assertNothingSent();

        $this->fakeStatus($donation, ['gross_amount' => '100000.00']);
        $this->actingAs($donation->user)->postJson("/payments/{$donation->id}/status")->assertUnprocessable();
        $this->assertSame('pending', $donation->fresh()->status);

        $this->fakeStatus($donation);
        $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();
        $this->assertSame('paid', $donation->fresh()->status);
        $this->assertSame(100000, $donation->campaign->fresh()->raised);
    }

    public function test_refunds_never_turn_admin_fees_into_funding_or_negative_campaign_balances(): void
    {
        $donation = $this->donation();
        $this->fakeStatus($donation);
        $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();
        $this->assertFundingTotals($donation, 100000);

        $this->fakeStatus($donation, ['transaction_status' => 'partial_refund', 'refund_amount' => '25000.00']);
        $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();
        $this->assertFundingTotals($donation, 75000);

        $this->fakeStatus($donation, ['transaction_status' => 'partial_refund', 'refund_amount' => '102000.00']);
        $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();
        $this->assertSame('partially_refunded', $donation->fresh()->status);
        $this->assertSame(102000, $donation->fresh()->refunded_amount);
        $this->assertFundingTotals($donation, 0);
        $this->actingAs($donation->user)->get("/certificates/{$donation->id}")->assertForbidden();
        $this->postJson("/payments/{$donation->id}/status")->assertOk()
            ->assertJsonPath('redirect_url', route('payments.show', $donation));

        $this->fakeStatus($donation, ['transaction_status' => 'partial_refund', 'refund_amount' => '104500.00']);
        $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();
        $this->assertSame('refunded', $donation->fresh()->status);
        $this->assertSame(104500, $donation->fresh()->refunded_amount);
        $this->assertNull($donation->fresh()->paid_at);
        $this->assertFundingTotals($donation, 0);
    }

    public function test_refund_cannot_exceed_the_total_charged_amount(): void
    {
        $donation = $this->donation(['status' => 'paid', 'paid_at' => now()]);
        $this->fakeStatus($donation, ['transaction_status' => 'partial_refund', 'refund_amount' => '104501.00']);

        $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertUnprocessable();

        $this->assertSame('paid', $donation->fresh()->status);
        $this->assertSame(0, $donation->fresh()->refunded_amount);
        $this->assertSame(100000, $donation->campaign->fresh()->raised);
    }

    public function test_full_refund_records_the_entire_payment_including_the_fee(): void
    {
        $donation = $this->donation(['status' => 'paid', 'paid_at' => now()]);
        $this->fakeStatus($donation, ['transaction_status' => 'refund', 'refund_amount' => '104500.00']);

        $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();

        $this->assertSame('refunded', $donation->fresh()->status);
        $this->assertSame(104500, $donation->fresh()->refunded_amount);
        $this->assertNull($donation->fresh()->paid_at);
        $this->assertSame(0, $donation->fresh()->net_amount);
        $this->assertSame(0, $donation->campaign->fresh()->raised);
    }

    public function test_expense_limit_uses_net_donations_without_fees_and_clamps_refunded_principal(): void
    {
        $campaign = $this->campaign();
        $this->donation([
            'campaign_id' => $campaign->id, 'status' => 'partially_refunded', 'paid_at' => now(),
            'amount' => 100000, 'refunded_amount' => 102000,
        ]);
        $this->donation([
            'campaign_id' => $campaign->id, 'status' => 'partially_refunded', 'paid_at' => now(),
            'method' => 'ewallet', 'payment_channel' => 'gopay', 'admin_fee' => 1500,
            'amount' => 70000, 'refunded_amount' => 20000,
        ]);
        $this->assertSame(50000, $campaign->fresh()->raised);
        $this->actingAs($campaign->user);
        $expense = ['title' => 'Beli perlengkapan', 'spent_at' => now()->toDateString()];

        $this->postJson("/manage/campaigns/{$campaign->id}/expenses", $expense + ['amount' => 50001])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->post("/manage/campaigns/{$campaign->id}/expenses", $expense + ['amount' => 50000])
            ->assertRedirect();

        $this->assertSame(0, $campaign->fresh()->available);
        $this->assertDatabaseCount('expenses', 1);
    }

    public function test_existing_donations_keep_zero_fees_and_their_existing_gateway_payment_options(): void
    {
        Http::fake([self::SNAP_URL => Http::response([
            'token' => 'legacy-token',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/legacy-token',
        ])]);
        $donation = Donation::create([
            'user_id' => $this->user()->id, 'campaign_id' => $this->campaign()->id,
            'amount' => 100000, 'method' => 'bank', 'status' => 'pending', 'order_id' => 'BF-'.Str::uuid(),
        ])->fresh();
        $this->assertNull($donation->payment_channel);
        $this->assertSame(0, $donation->admin_fee);
        $this->assertSame(100000, $donation->total_amount);

        $this->actingAs($donation->user)->postJson("/payments/{$donation->id}/checkout")->assertOk();

        Http::assertSent(fn (Request $request) => $request['transaction_details']['gross_amount'] === 100000
            && $request['enabled_payments'] === ['bank_transfer']
            && count($request['item_details']) === 1);
    }

    private function assertFundingTotals(Donation $donation, int $expected): void
    {
        $campaign = $donation->campaign->fresh();
        $this->assertSame($expected, $donation->fresh()->net_amount);
        $this->assertSame($expected, $campaign->raised);
        $this->assertSame($expected, Campaign::withFundingTotals()->findOrFail($campaign->id)->raised);
        $this->get('/')->assertOk()->assertViewHas('total', fn ($total) => (int) $total === $expected);
        $this->get('/transparency')->assertOk()->assertViewHas('raised', fn ($total) => (int) $total === $expected);
        $this->get("/campaigns/{$campaign->id}")->assertOk()
            ->assertViewHas('campaign', fn (Campaign $item) => $item->raised === $expected);
        $this->get('/top-donors')->assertOk()
            ->assertViewHas('donors', fn ($donors) => (int) $donors->sum('total') === $expected);
        $this->actingAs($donation->user)->get('/dashboard')->assertOk()
            ->assertViewHas('donatedTotal', fn ($total) => (int) $total === $expected);
        $this->actingAs($this->user(['role' => 'admin']))->get('/admin')->assertOk()
            ->assertViewHas('total', fn ($total) => (int) $total === $expected);
    }

    private function user(array $attributes = []): User
    {
        return User::create(array_replace([
            'name' => 'Donor Test', 'email' => Str::uuid().'@example.test',
            'password' => 'password-for-test', 'role' => 'donor',
        ], $attributes));
    }

    private function campaign(): Campaign
    {
        return Campaign::create([
            'user_id' => $this->user(['role' => 'fundraiser'])->id,
            'title' => 'Bantuan Pendidikan', 'category' => 'Pendidikan', 'location' => 'Jakarta',
            'description' => 'Program bantuan pendidikan untuk anak-anak.', 'target' => 1000000,
            'deadline' => now()->addMonth()->toDateString(), 'status' => 'approved',
        ]);
    }

    private function donation(array $attributes = []): Donation
    {
        return Donation::create(array_replace([
            'user_id' => $this->user()->id, 'campaign_id' => $this->campaign()->id,
            'amount' => 100000, 'admin_fee' => 4500, 'method' => 'bank', 'payment_channel' => 'bca_va',
            'anonymous' => false, 'status' => 'pending', 'order_id' => 'BF-'.Str::uuid(),
        ], $attributes));
    }

    private function gatewayStatus(Donation $donation, array $attributes = []): array
    {
        return array_replace([
            'order_id' => $donation->order_id, 'status_code' => '200',
            'gross_amount' => number_format($donation->total_amount, 2, '.', ''),
            'transaction_status' => 'settlement', 'transaction_id' => 'gateway-transaction-'.$donation->id,
            'fraud_status' => 'accept', 'payment_type' => 'bank_transfer',
        ], $attributes);
    }

    private function signature(array $payload): string
    {
        return hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::SERVER_KEY);
    }

    private function notification(Donation $donation): array
    {
        $payload = $this->gatewayStatus($donation);
        $payload['signature_key'] = $this->signature($payload);

        return $payload;
    }

    private function fakeStatus(Donation $donation, array $attributes = []): void
    {
        // Replace previous status stubs so sequential settlement/refund responses
        // exercise each transition rather than reusing Laravel's first URL stub.
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$donation->order_id.'/status' => Http::response($this->gatewayStatus($donation, $attributes)),
        ]);
    }
}
