<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_donation_form_offers_labeled_native_channel_choices_with_local_logos(): void
    {
        $response = $this->actingAs($this->user())->get(route('campaign.show', $this->campaign()));
        $response->assertOk()->assertSee('type="radio"', false)->assertSee('name="payment_channel"', false);

        foreach (config('payments.methods') as $method) {
            foreach ($method['channels'] as $channel => $details) {
                $response->assertSee('id="channel-'.$channel.'"', false)
                    ->assertSee('for="channel-'.$channel.'"', false)
                    ->assertSee(asset($details['logo']), false)->assertSee($details['label']);
                $this->assertFileExists(public_path($details['logo']));
                if (str_ends_with($details['logo'], '.svg')) {
                    $this->assertStringStartsWith('<svg', ltrim(file_get_contents(public_path($details['logo']))));
                } else {
                    $this->assertSame(IMAGETYPE_PNG, getimagesize(public_path($details['logo']))[2]);
                }
            }
        }
    }

    public function test_invalid_old_channel_is_replaced_by_valid_channel_in_selected_method(): void
    {
        $response = $this->actingAs($this->user())->withSession([
            '_old_input' => ['method' => 'bank', 'payment_channel' => 'gopay'],
        ])->get(route('campaign.show', $this->campaign()));

        $response->assertOk()->assertSee('value="bca_va" data-method="bank" checked', false)
            ->assertDontSee('value="gopay" data-method="ewallet" checked', false);
    }

    public static function channels(): array
    {
        return [
            ['bank', 'bca_va', 'bca.png'], ['bank', 'bni_va', 'bni.png'],
            ['bank', 'bri_va', 'bri.png'], ['bank', 'echannel', 'mandiri.png'],
            ['bank', 'permata_va', 'permata.png'], ['bank', 'cimb_va', 'cimb.svg'],
            ['ewallet', 'gopay', 'gopay.png'], ['ewallet', 'shopeepay', 'shopeepay.png'],
            ['qris', 'other_qris', 'qris.png'],
        ];
    }

    #[DataProvider('channels')]
    public function test_payment_summary_shows_the_selected_channel_logo(string $method, string $channel, string $logo): void
    {
        $user = $this->user();
        $donation = Donation::create([
            'user_id' => $user->id, 'campaign_id' => $this->campaign()->id,
            'amount' => 100000, 'method' => $method, 'payment_channel' => $channel,
            'admin_fee' => config('payments.methods.'.$method.'.fee'), 'status' => 'pending',
        ]);

        $this->actingAs($user)->get(route('payments.show', $donation))->assertOk()
            ->assertSee(asset('images/payments/'.$logo), false)
            ->assertSee($donation->payment_channel_label);
    }

    public function test_legacy_payment_without_channel_still_renders(): void
    {
        $user = $this->user();
        $donation = Donation::create([
            'user_id' => $user->id, 'campaign_id' => $this->campaign()->id,
            'amount' => 100000, 'method' => 'bank', 'status' => 'paid',
        ]);

        $this->actingAs($user)->get(route('payments.show', $donation))->assertOk()
            ->assertSee('Transfer Bank');
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Donor', 'email' => Str::uuid().'@example.test',
            'password' => 'password-for-tests', 'role' => 'donor',
        ]);
    }

    private function campaign(): Campaign
    {
        return Campaign::create([
            'user_id' => $this->user()->id, 'title' => 'Bantuan Pendidikan',
            'category' => 'Pendidikan', 'description' => 'Bantuan pendidikan.',
            'target' => 1000000, 'deadline' => now()->addMonth(), 'status' => 'approved',
        ]);
    }
}
