<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MidtransPaymentTest extends TestCase
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

    public function test_donation_starts_pending_and_does_not_call_the_gateway(): void
    {
        Http::fake();
        $donor = $this->user();
        $campaign = $this->campaign();

        $response = $this->actingAs($donor)->post("/campaigns/{$campaign->id}/donate", [
            'amount' => 15000,
            'method' => 'qris',
            'message' => 'Semoga bermanfaat.',
            'anonymous' => true,
            'status' => 'paid',
            'user_id' => $campaign->user_id,
        ]);

        $response->assertRedirect();
        $donation = Donation::sole();
        $response->assertRedirect(route('payments.show', $donation));
        $this->assertSame($donor->id, $donation->user_id);
        $this->assertSame('pending', $donation->status);
        $this->assertNull($donation->paid_at);
        $this->assertTrue($donation->anonymous);
        $this->assertStringStartsWith('BF-', $donation->order_id);
        Http::assertNothingSent();
    }

    public function test_expired_and_unapproved_campaigns_cannot_accept_donations(): void
    {
        $this->actingAs($this->user());

        foreach ([
            ['deadline' => now()->subDay()->toDateString()],
            ['status' => 'pending'],
            ['status' => 'rejected'],
        ] as $attributes) {
            $campaign = $this->campaign($attributes);
            $this->postJson("/campaigns/{$campaign->id}/donate", [
                'amount' => 10000,
                'method' => 'qris',
            ])->assertUnprocessable();
        }

        $this->assertDatabaseCount('donations', 0);
    }

    public function test_nullable_anonymous_input_is_normalized_to_false(): void
    {
        $campaign = $this->campaign();

        $this->actingAs($this->user())->post("/campaigns/{$campaign->id}/donate", [
            'amount' => 10000,
            'method' => 'qris',
            'anonymous' => null,
        ])->assertRedirect();

        $this->assertFalse(Donation::sole()->anonymous);
    }

    public function test_donation_validates_amount_and_supported_payment_method(): void
    {
        $campaign = $this->campaign();
        $this->actingAs($this->user());

        foreach ([
            ['amount' => 999, 'method' => 'qris'],
            ['amount' => 1000.5, 'method' => 'qris'],
            ['amount' => 10000, 'method' => 'cash'],
        ] as $payload) {
            $this->postJson("/campaigns/{$campaign->id}/donate", $payload)
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('donations', 0);
    }

    public function test_viewing_payment_does_not_create_a_snap_transaction(): void
    {
        Http::fake();
        $donation = $this->donation();

        $this->actingAs($donation->user)->get("/payments/{$donation->id}")->assertOk();

        $this->assertNull($donation->fresh()->snap_token);
        Http::assertNothingSent();
    }

    public function test_checkout_creates_a_sandbox_snap_transaction_with_server_owned_amount(): void
    {
        Http::fake([
            self::SNAP_URL => Http::response([
                'token' => 'sandbox-token-1',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/sandbox-token-1',
            ]),
        ]);
        $donation = $this->donation();

        $this->actingAs($donation->user)->postJson("/payments/{$donation->id}/checkout", [
            'amount' => 1,
            'order_id' => 'attacker-order',
        ])->assertOk();

        $donation->refresh();
        $this->assertSame('sandbox-token-1', $donation->snap_token);
        $this->assertSame('https://app.sandbox.midtrans.com/snap/v4/redirection/sandbox-token-1', $donation->snap_redirect_url);
        $this->assertSame('pending', $donation->status);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::SNAP_URL
            && $request->hasHeader('Authorization', 'Basic '.base64_encode(self::SERVER_KEY.':'))
            && $request['transaction_details']['order_id'] === $donation->order_id
            && $request['transaction_details']['gross_amount'] === (int) $donation->amount);
        Http::assertSentCount(1);
    }

    public function test_repeated_checkout_reuses_the_existing_snap_transaction(): void
    {
        Http::fake();
        $donation = $this->donation([
            'snap_token' => 'existing-token',
            'snap_redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/existing-token',
        ]);

        $this->actingAs($donation->user)->postJson("/payments/{$donation->id}/checkout")->assertOk();
        $this->post("/payments/{$donation->id}/checkout")
            ->assertRedirect($donation->snap_redirect_url);

        Http::assertNothingSent();
    }

    public function test_browser_checkout_redirects_to_the_gateway(): void
    {
        $redirectUrl = 'https://app.sandbox.midtrans.com/snap/v4/redirection/new-token';
        Http::fake([self::SNAP_URL => Http::response(['token' => 'new-token', 'redirect_url' => $redirectUrl])]);
        $donation = $this->donation();

        $this->actingAs($donation->user)->post("/payments/{$donation->id}/checkout")
            ->assertRedirect($redirectUrl);
    }

    public function test_checkout_reports_missing_configuration_without_marking_paid(): void
    {
        config(['midtrans.server_key' => '', 'midtrans.client_key' => '']);
        Http::fake();
        $donation = $this->donation();

        $this->actingAs($donation->user)->postJson("/payments/{$donation->id}/checkout")
            ->assertStatus(503);

        $this->assertSame('pending', $donation->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_gateway_failure_returns_a_generic_error_without_leaking_gateway_content(): void
    {
        Http::fake([self::SNAP_URL => Http::response(['error_messages' => ['PRIVATE_GATEWAY_ERROR']], 500)]);
        $donation = $this->donation();

        $this->actingAs($donation->user)->postJson("/payments/{$donation->id}/checkout")
            ->assertStatus(502)
            ->assertDontSee('PRIVATE_GATEWAY_ERROR')
            ->assertDontSee(self::SERVER_KEY);

        $this->assertSame('pending', $donation->fresh()->status);
        $this->assertNull($donation->fresh()->snap_token);
    }

    public function test_legacy_donation_keeps_the_same_allocated_order_after_checkout_connection_failures(): void
    {
        $orders = [];
        Http::fake(function (Request $request) use (&$orders) {
            $orders[] = $request['transaction_details']['order_id'];

            return Http::failedConnection();
        });
        $donation = $this->donation(['order_id' => null]);
        $this->actingAs($donation->user);

        $this->postJson("/payments/{$donation->id}/checkout")->assertStatus(502);
        $order = $donation->fresh()->order_id;
        $this->assertNotNull($order);
        $this->assertStringStartsWith('BF-', $order);
        $this->postJson("/payments/{$donation->id}/checkout")->assertStatus(502);

        $this->assertSame($order, $donation->fresh()->order_id);
        $this->assertSame([$order, $order], $orders);
        $this->assertSame('pending', $donation->fresh()->status);
        $this->assertNull($donation->fresh()->snap_token);
    }

    public function test_other_donors_cannot_access_or_mutate_payments_or_certificates(): void
    {
        Http::fake();
        $donation = $this->donation(['status' => 'paid', 'paid_at' => now()]);
        $this->actingAs($this->user());

        $this->get("/payments/{$donation->id}")->assertForbidden();
        $this->postJson("/payments/{$donation->id}/checkout")->assertForbidden();
        $this->postJson("/payments/{$donation->id}/confirm")->assertForbidden();
        $this->postJson("/payments/{$donation->id}/status")->assertForbidden();
        $this->get("/certificates/{$donation->id}")->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_guests_cannot_access_payment_actions(): void
    {
        Http::fake();
        $donation = $this->donation();

        $this->get("/payments/{$donation->id}")->assertRedirect(route('login'));
        $this->postJson("/payments/{$donation->id}/checkout")->assertUnauthorized();
        $this->postJson("/payments/{$donation->id}/confirm")->assertUnauthorized();
        $this->postJson("/payments/{$donation->id}/status")->assertUnauthorized();
        $this->get("/certificates/{$donation->id}")->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    public function test_unpaid_donation_cannot_produce_a_certificate(): void
    {
        $donation = $this->donation();

        $this->actingAs($donation->user)->get("/certificates/{$donation->id}")->assertForbidden();
    }

    public function test_confirmation_ignores_forged_browser_payment_status(): void
    {
        $donation = $this->donation();
        $this->fakeStatus($donation, ['transaction_status' => 'pending', 'status_code' => '201']);

        $this->actingAs($donation->user)->postJson("/payments/{$donation->id}/confirm", [
            'status' => 'paid',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'gross_amount' => '1.00',
        ])->assertOk();

        $this->assertSame('pending', $donation->fresh()->status);
        $this->assertNull($donation->fresh()->paid_at);
        $this->assertStatusRequest($donation);
    }

    public function test_status_refresh_uses_the_authenticated_midtrans_status_endpoint(): void
    {
        $donation = $this->donation();
        $this->fakeStatus($donation);

        $this->actingAs($donation->user)->postJson("/payments/{$donation->id}/status")->assertOk();

        $donation->refresh();
        $this->assertSame('paid', $donation->status);
        $this->assertNotNull($donation->paid_at);
        $this->assertNotNull($donation->last_checked_at);
        $this->assertSame('settlement', $donation->gateway_status);
        $this->assertSame('gateway-transaction-'.$donation->id, $donation->gateway_transaction_id);
        $this->assertStatusRequest($donation);
    }

    public function test_status_refresh_rejects_a_gateway_amount_mismatch(): void
    {
        $donation = $this->donation();
        $this->fakeStatus($donation, ['gross_amount' => '99999.00']);

        $this->actingAs($donation->user)->postJson("/payments/{$donation->id}/status")
            ->assertUnprocessable();

        $this->assertSame('pending', $donation->fresh()->status);
        $this->assertNull($donation->fresh()->paid_at);
    }

    public function test_status_refresh_rejects_a_gateway_order_mismatch(): void
    {
        $donation = $this->donation();
        $this->fakeStatus($donation, ['order_id' => 'BF-another-order']);

        $this->actingAs($donation->user)->postJson("/payments/{$donation->id}/status")
            ->assertUnprocessable();

        $this->assertSame('pending', $donation->fresh()->status);
    }

    public function test_malformed_successful_gateway_status_responses_return_a_safe_error(): void
    {
        $donation = $this->donation();
        $this->actingAs($donation->user);

        foreach (['"unexpected scalar response"', '<html>PRIVATE_GATEWAY_ERROR</html>'] as $body) {
            Http::fake([$this->statusUrl($donation) => Http::response($body, 200)]);

            $this->postJson("/payments/{$donation->id}/status")
                ->assertStatus(502)
                ->assertDontSee('PRIVATE_GATEWAY_ERROR')
                ->assertDontSee(self::SERVER_KEY);

            $this->assertSame('pending', $donation->fresh()->status);
            $this->assertNull($donation->fresh()->paid_at);
            $this->assertNull($donation->fresh()->last_checked_at);
        }
    }

    public function test_notification_is_public_but_requires_a_valid_signature(): void
    {
        Http::fake();
        $donation = $this->donation();
        $payload = $this->notification($donation);
        $payload['signature_key'] = str_repeat('0', 128);

        $this->postJson('/payments/midtrans/notification', $payload)->assertForbidden();

        $this->assertSame('pending', $donation->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_notification_is_csrf_exempt_while_other_post_routes_remain_protected(): void
    {
        // Enable the real CSRF check, which Laravel normally skips while testing.
        $this->app->instance('env', 'production');
        $donation = $this->donation();
        $this->fakeStatus($donation);

        $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();
        $this->actingAs($donation->user)->post('/logout')->assertStatus(419);

        $this->assertSame('paid', $donation->fresh()->status);
    }

    public function test_notification_validates_required_signed_fields(): void
    {
        Http::fake();

        $this->postJson('/payments/midtrans/notification', [
            'transaction_status' => 'settlement',
        ])->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_signed_notification_for_unknown_order_is_not_found(): void
    {
        Http::fake();
        $payload = [
            'order_id' => 'BF-unknown-order',
            'status_code' => '200',
            'gross_amount' => '100000.00',
        ];
        $payload['signature_key'] = $this->signature($payload);

        $this->postJson('/payments/midtrans/notification', $payload)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_valid_notification_uses_server_status_instead_of_payload_status(): void
    {
        $donation = $this->donation();
        $this->fakeStatus($donation, ['transaction_status' => 'pending', 'status_code' => '201']);

        $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();

        $this->assertSame('pending', $donation->fresh()->status);
        $this->assertNull($donation->fresh()->paid_at);
        $this->assertStatusRequest($donation);
    }

    public function test_signed_notification_rejects_an_authoritative_amount_mismatch(): void
    {
        $donation = $this->donation();
        $this->fakeStatus($donation, ['gross_amount' => '100001.00']);

        $this->postJson('/payments/midtrans/notification', $this->notification($donation))
            ->assertUnprocessable();

        $this->assertSame('pending', $donation->fresh()->status);
    }

    public function test_settlement_without_fraud_status_marks_the_donation_paid(): void
    {
        $donation = $this->donation();
        $status = $this->gatewayStatus($donation);
        unset($status['fraud_status']);
        Http::fake([$this->statusUrl($donation) => Http::response($status)]);

        $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();

        $this->assertSame('paid', $donation->fresh()->status);
        $this->assertSame((int) $donation->amount, $donation->campaign->fresh()->raised);
    }

    public function test_capture_requires_an_accepted_fraud_status(): void
    {
        foreach ([null, 'challenge', 'deny'] as $fraudStatus) {
            $donation = $this->donation();
            $status = $this->gatewayStatus($donation, ['transaction_status' => 'capture']);
            if ($fraudStatus === null) {
                unset($status['fraud_status']);
            } else {
                $status['fraud_status'] = $fraudStatus;
            }
            Http::fake([$this->statusUrl($donation) => Http::response($status)]);

            $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();

            $this->assertNotSame('paid', $donation->fresh()->status);
            $this->assertNull($donation->fresh()->paid_at);
        }

        $donation = $this->donation();
        $this->fakeStatus($donation, ['transaction_status' => 'capture', 'fraud_status' => 'accept']);
        $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();
        $this->assertSame('paid', $donation->fresh()->status);
    }

    public function test_replayed_settlement_does_not_duplicate_or_change_paid_at(): void
    {
        $this->freezeTime();
        $donation = $this->donation();
        $this->fakeStatus($donation);
        $payload = $this->notification($donation);

        $this->postJson('/payments/midtrans/notification', $payload)->assertOk();
        $paidAt = $donation->fresh()->paid_at->toDateTimeString();
        $this->travel(5)->minutes();
        $this->postJson('/payments/midtrans/notification', $payload)->assertOk();

        $this->assertSame($paidAt, $donation->fresh()->paid_at->toDateTimeString());
        $this->assertDatabaseCount('donations', 1);
        $this->assertSame((int) $donation->amount, $donation->campaign->fresh()->raised);
    }

    public function test_old_pending_notification_cannot_downgrade_a_paid_donation(): void
    {
        $donation = $this->donation(['status' => 'paid', 'paid_at' => now()->subHour()]);
        $paidAt = $donation->paid_at->toDateTimeString();
        $this->fakeStatus($donation, ['transaction_status' => 'pending', 'status_code' => '201']);

        $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();

        $this->assertSame('paid', $donation->fresh()->status);
        $this->assertSame($paidAt, $donation->fresh()->paid_at->toDateTimeString());
    }

    public function test_authoritative_settlement_reversal_removes_funding_and_certificate_access(): void
    {
        $donation = $this->donation([
            'status' => 'paid',
            'gateway_status' => 'settlement',
            'paid_at' => now()->subMinute(),
        ]);
        $this->assertSame(100000, $donation->campaign->raised);
        $this->fakeStatus($donation, ['transaction_status' => 'deny', 'status_code' => '202']);

        $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();

        $this->assertSame('failed', $donation->fresh()->status);
        $this->assertSame('deny', $donation->fresh()->gateway_status);
        $this->assertNull($donation->fresh()->paid_at);
        $this->assertSame(0, $donation->campaign->fresh()->raised);
        $this->actingAs($donation->user)->get("/certificates/{$donation->id}")->assertForbidden();
    }

    public function test_authoritative_capture_cancellation_removes_funding_and_certificate_access(): void
    {
        $donation = $this->donation([
            'status' => 'paid',
            'gateway_status' => 'capture',
            'paid_at' => now()->subMinute(),
        ]);
        $this->fakeStatus($donation, ['transaction_status' => 'cancel']);

        $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();

        $this->assertSame('cancelled', $donation->fresh()->status);
        $this->assertSame('cancel', $donation->fresh()->gateway_status);
        $this->assertNull($donation->fresh()->paid_at);
        $this->assertSame(0, $donation->campaign->fresh()->raised);
        $this->actingAs($donation->user)->get("/certificates/{$donation->id}")->assertForbidden();
    }

    public function test_stale_settlement_cannot_resurrect_a_failed_cancelled_or_expired_donation(): void
    {
        foreach (['failed' => 'deny', 'cancelled' => 'cancel', 'expired' => 'expire'] as $status => $gateway) {
            $donation = $this->donation(['status' => $status, 'gateway_status' => $gateway]);
            $this->fakeStatus($donation);

            $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();

            $this->assertSame($status, $donation->fresh()->status);
            $this->assertSame($gateway, $donation->fresh()->gateway_status);
            $this->assertNull($donation->fresh()->paid_at);
            $this->assertSame(0, $donation->campaign->fresh()->raised);
            $this->actingAs($donation->user)->get("/certificates/{$donation->id}")->assertForbidden();
        }
    }

    public function test_terminal_gateway_statuses_are_mapped_without_creating_paid_donations(): void
    {
        foreach (['expire' => 'expired', 'cancel' => 'cancelled', 'deny' => 'failed', 'failure' => 'failed'] as $gateway => $local) {
            $donation = $this->donation();
            $this->fakeStatus($donation, ['transaction_status' => $gateway]);

            $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();

            $this->assertSame($local, $donation->fresh()->status);
            $this->assertNull($donation->fresh()->paid_at);
            $this->assertSame(0, $donation->campaign->fresh()->raised);
        }
    }

    public function test_refund_removes_the_donation_from_raised_funds_and_clears_paid_at(): void
    {
        $donation = $this->donation(['status' => 'paid', 'paid_at' => now()->subHour()]);
        $this->fakeStatus($donation, [
            'transaction_status' => 'refund',
            'refund_amount' => '100000.00',
        ]);

        $this->postJson('/payments/midtrans/notification', $this->notification($donation))->assertOk();

        $this->assertSame('refunded', $donation->fresh()->status);
        $this->assertNull($donation->fresh()->paid_at);
        $this->assertSame(0, $donation->campaign->fresh()->raised);
    }

    public function test_partial_refund_counts_only_the_net_donation_and_is_replay_safe(): void
    {
        $donation = $this->donation(['status' => 'paid', 'paid_at' => now()->subHour()]);
        $this->fakeStatus($donation, [
            'transaction_status' => 'partial_refund',
            'refund_amount' => '25000.00',
        ]);
        $payload = $this->notification($donation);

        $this->postJson('/payments/midtrans/notification', $payload)->assertOk();
        $this->postJson('/payments/midtrans/notification', $payload)->assertOk();

        $this->assertSame('partially_refunded', $donation->fresh()->status);
        $this->assertSame(25000, (int) $donation->fresh()->refunded_amount);
        $this->assertNotNull($donation->fresh()->paid_at);
        $this->assertSame(75000, $donation->campaign->fresh()->raised);
    }

    public function test_reconciliation_command_verifies_pending_payments_without_a_webhook(): void
    {
        $donation = $this->donation();
        $this->fakeStatus($donation);

        $this->artisan('midtrans:sync')->assertExitCode(0);

        $this->assertSame('paid', $donation->fresh()->status);
        $this->assertNotNull($donation->fresh()->paid_at);
        $this->assertStatusRequest($donation);
    }

    public function test_reconciliation_command_fails_when_midtrans_is_not_configured(): void
    {
        config(['midtrans.server_key' => '', 'midtrans.client_key' => '']);
        Http::fake();
        $donation = $this->donation();

        $this->artisan('midtrans:sync')->assertExitCode(1);

        $this->assertSame('pending', $donation->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_reconciliation_command_rejects_invalid_limits_before_calling_midtrans(): void
    {
        Http::fake();
        $this->donation();

        foreach (['0', '1001', 'not-a-number'] as $limit) {
            $this->artisan('midtrans:sync', ['--limit' => $limit])->assertExitCode(1);
        }

        Http::assertNothingSent();
    }

    public function test_reconciliation_all_option_includes_paid_donations_and_updates_net_funding(): void
    {
        $donation = $this->donation(['status' => 'paid', 'paid_at' => now()->subHour()]);
        $this->fakeStatus($donation, [
            'transaction_status' => 'partial_refund',
            'refund_amount' => '25000.00',
        ]);

        $this->artisan('midtrans:sync')->assertExitCode(0);
        Http::assertNothingSent();
        $this->artisan('midtrans:sync', ['--all' => true])->assertExitCode(0);

        $this->assertSame('partially_refunded', $donation->fresh()->status);
        $this->assertSame(25000, (int) $donation->fresh()->refunded_amount);
        $this->assertSame(75000, $donation->campaign->fresh()->raised);
        Http::assertSentCount(1);
    }

    private function user(array $attributes = []): User
    {
        return User::create(array_replace([
            'name' => 'Donor Test',
            'email' => Str::uuid().'@example.test',
            'password' => 'password-for-test',
            'role' => 'donor',
        ], $attributes));
    }

    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::create(array_replace([
            'user_id' => $this->user(['role' => 'fundraiser'])->id,
            'title' => 'Bantuan Pendidikan',
            'category' => 'Pendidikan',
            'location' => 'Jakarta',
            'description' => 'Program bantuan pendidikan untuk anak-anak.',
            'target' => 1000000,
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'approved',
        ], $attributes));
    }

    private function donation(array $attributes = []): Donation
    {
        return Donation::create(array_replace([
            'user_id' => $this->user()->id,
            'campaign_id' => $this->campaign()->id,
            'amount' => 100000,
            'method' => 'qris',
            'anonymous' => false,
            'status' => 'pending',
            'order_id' => 'BF-'.Str::uuid(),
        ], $attributes));
    }

    private function gatewayStatus(Donation $donation, array $attributes = []): array
    {
        return array_replace([
            'order_id' => $donation->order_id,
            'status_code' => '200',
            'gross_amount' => number_format((int) $donation->amount, 2, '.', ''),
            'transaction_status' => 'settlement',
            'transaction_id' => 'gateway-transaction-'.$donation->id,
            'fraud_status' => 'accept',
            'payment_type' => 'qris',
        ], $attributes);
    }

    private function notification(Donation $donation): array
    {
        $payload = $this->gatewayStatus($donation);
        $payload['signature_key'] = $this->signature($payload);

        return $payload;
    }

    private function signature(array $payload): string
    {
        return hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::SERVER_KEY);
    }

    private function statusUrl(Donation $donation): string
    {
        return 'https://api.sandbox.midtrans.com/v2/'.$donation->order_id.'/status';
    }

    private function fakeStatus(Donation $donation, array $attributes = []): void
    {
        Http::fake([$this->statusUrl($donation) => Http::response($this->gatewayStatus($donation, $attributes))]);
    }

    private function assertStatusRequest(Donation $donation): void
    {
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === $this->statusUrl($donation)
            && $request->hasHeader('Authorization', 'Basic '.base64_encode(self::SERVER_KEY.':')));
    }
}
