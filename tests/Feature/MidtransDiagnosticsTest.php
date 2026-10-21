<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MidtransDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT_URL = 'https://app.sandbox.midtrans.com/snap/snap.js';

    private const STATUS_URL = 'https://api.sandbox.midtrans.com/v2/BF-DIAGNOSTIC-NEVER-CREATED/status';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'midtrans.server_key' => 'Mid-server-sandbox-diagnostic-test',
            'midtrans.client_key' => 'Mid-client-sandbox-diagnostic-test',
            'midtrans.ca_bundle' => null,
            'app.url' => 'http://localhost',
        ]);
        Http::preventStrayRequests();
    }

    public function test_doctor_accepts_keys_without_sb_prefix_when_sandbox_authentication_succeeds(): void
    {
        Http::fake([
            self::SCRIPT_URL => Http::response('window.snap = {};'),
            self::STATUS_URL => Http::response(['status_code' => '404']),
        ]);

        $this->assertSame(0, Artisan::call('midtrans:doctor'));
        $output = Artisan::output();
        $this->assertStringContainsString('autentikasi Server Key Sandbox berhasil', $output);
        $this->assertStringContainsString('Webhook otomatis memerlukan URL publik', $output);
        $this->assertStringNotContainsString(config('midtrans.server_key'), $output);
        $this->assertStringNotContainsString(config('midtrans.client_key'), $output);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === self::STATUS_URL
            && $request->hasHeader('Authorization', 'Basic '.base64_encode(config('midtrans.server_key').':')));
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_doctor_accepts_http_404_for_the_nonexistent_diagnostic_order(): void
    {
        Http::fake([
            self::SCRIPT_URL => Http::response('script'),
            self::STATUS_URL => Http::response(['status_code' => '404'], 404),
        ]);

        $this->assertSame(0, Artisan::call('midtrans:doctor'));
    }

    public function test_missing_keys_fail_before_any_network_request(): void
    {
        config(['midtrans.server_key' => '', 'midtrans.client_key' => null]);
        Http::fake();

        $this->assertSame(1, Artisan::call('midtrans:doctor'));
        $this->assertStringContainsString('Isi MIDTRANS_SERVER_KEY dan MIDTRANS_CLIENT_KEY', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_offline_mode_checks_local_settings_without_claiming_authentication(): void
    {
        Http::fake();

        $this->assertSame(0, Artisan::call('midtrans:doctor', ['--offline' => true]));
        $this->assertStringContainsString('koneksi dan autentikasi belum diverifikasi', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_doctor_explains_unauthorized_credentials_without_outputting_response_secrets(): void
    {
        Http::fake([
            self::SCRIPT_URL => Http::response('script'),
            self::STATUS_URL => Http::response(['message' => 'secret-provider-response'], 401),
        ]);

        $this->assertSame(1, Artisan::call('midtrans:doctor'));
        $output = Artisan::output();
        $this->assertStringContainsString('Autentikasi Sandbox ditolak', $output);
        $this->assertStringNotContainsString('secret-provider-response', $output);
    }

    public function test_doctor_identifies_ca_failures_without_outputting_exception_details(): void
    {
        Http::fake([self::SCRIPT_URL => Http::failedConnection('cURL error 60: private-certificate-details')]);

        $this->assertSame(1, Artisan::call('midtrans:doctor'));
        $output = Artisan::output();
        $this->assertStringContainsString('Verifikasi sertifikat HTTPS gagal (cURL 60)', $output);
        $this->assertStringContainsString('MIDTRANS_CA_BUNDLE', $output);
        $this->assertStringNotContainsString('private-certificate-details', $output);
    }

    public function test_doctor_distinguishes_a_connection_failure_from_a_ca_failure(): void
    {
        Http::fake([self::SCRIPT_URL => Http::failedConnection('cURL error 7: private-network-details')]);

        $this->assertSame(1, Artisan::call('midtrans:doctor'));
        $output = Artisan::output();
        $this->assertStringContainsString('Periksa internet, DNS, firewall, atau proxy', $output);
        $this->assertStringNotContainsString('cURL 60', $output);
        $this->assertStringNotContainsString('private-network-details', $output);
    }

    public function test_invalid_ca_bundle_fails_even_in_offline_mode(): void
    {
        config(['midtrans.ca_bundle' => base_path('does-not-exist-benefund-ca.pem')]);
        Http::fake();

        $this->assertSame(1, Artisan::call('midtrans:doctor', ['--offline' => true]));
        $this->assertStringContainsString('MIDTRANS_CA_BUNDLE tidak valid', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_missing_fee_migration_is_reported_before_network_requests(): void
    {
        Schema::table('donations', fn (Blueprint $table) => $table->dropColumn('admin_fee'));
        Http::fake();

        $this->assertSame(1, Artisan::call('midtrans:doctor'));
        $this->assertStringContainsString('Jalankan php artisan migrate', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_unexpected_gateway_response_does_not_count_as_successful_authentication(): void
    {
        Http::fake([
            self::SCRIPT_URL => Http::response('script'),
            self::STATUS_URL => Http::response(['status_code' => '500', 'message' => 'private-response']),
        ]);

        $this->assertSame(1, Artisan::call('midtrans:doctor'));
        $output = Artisan::output();
        $this->assertStringContainsString('Respons pemeriksaan autentikasi belum sesuai', $output);
        $this->assertStringNotContainsString('private-response', $output);
    }

    public function test_script_failure_stops_before_server_key_authentication(): void
    {
        Http::fake([self::SCRIPT_URL => Http::response('unavailable', 503)]);

        $this->assertSame(1, Artisan::call('midtrans:doctor'));
        $this->assertStringContainsString('Skrip checkout Sandbox belum dapat diakses (HTTP 503)', Artisan::output());
        Http::assertSentCount(1);
    }
}
