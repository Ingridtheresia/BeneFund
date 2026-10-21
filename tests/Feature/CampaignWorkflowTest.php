<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'fundraiser'): User
    {
        return User::create([
            'name' => 'Pengguna '.Str::random(8),
            'email' => Str::random(12).'@example.test',
            'password' => 'Password123!',
            'role' => $role,
        ]);
    }

    private function fields(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Bantu Pendidikan Anak '.Str::random(6),
            'category' => 'Pendidikan',
            'location' => 'Bandung',
            'description' => 'Program bantuan pendidikan untuk kebutuhan sekolah anak dan laporan penyaluran terbuka.',
            'target' => 1000000,
            'deadline' => today()->addDays(20)->format('Y-m-d'),
            'image_url' => 'https://example.test/education.jpg',
        ], $overrides);
    }

    private function campaign(?User $owner = null, array $overrides = []): Campaign
    {
        return Campaign::create($this->fields($overrides) + [
            'user_id' => ($owner ?? $this->user())->id,
            'status' => 'approved',
        ]);
    }

    private function donation(Campaign $campaign, array $overrides = []): Donation
    {
        return Donation::create(array_replace([
            'user_id' => $this->user('donor')->id,
            'campaign_id' => $campaign->id,
            'amount' => 100000,
            'method' => 'qris',
            'status' => 'paid',
            'paid_at' => now(),
            'anonymous' => false,
        ], $overrides));
    }

    public function test_campaign_management_and_admin_are_protected_by_role(): void
    {
        $this->get(route('manage.campaigns'))->assertRedirect(route('login'));
        $this->actingAs($this->user('donor'))->get(route('manage.campaigns'))->assertForbidden();
        $this->get(route('admin'))->assertForbidden();
    }

    public function test_campaign_submission_assigns_owner_and_requires_admin_verification(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $fields = $this->fields(['status' => 'approved', 'user_id' => $other->id]);

        $this->actingAs($owner)->post(route('manage.store'), $fields)->assertRedirect(route('manage.campaigns'));

        $campaign = Campaign::sole();
        $this->assertSame($owner->id, $campaign->user_id);
        $this->assertSame('pending', $campaign->status);
        $this->get(route('manage.campaigns'))->assertOk()->assertSee($campaign->title);
    }

    public function test_campaign_fields_reject_invalid_values_and_oversized_database_strings(): void
    {
        $this->actingAs($this->user())->post(route('manage.store'), $this->fields([
            'category' => 'Invalid',
            'target' => -100,
            'description' => 'Singkat',
            'deadline' => today()->format('Y-m-d'),
            'image_url' => 'https://example.test/'.str_repeat('a', 250),
        ]))->assertSessionHasErrors(['category', 'target', 'description', 'deadline', 'image_url']);
        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_another_fundraiser_cannot_change_or_spend_campaign_funds(): void
    {
        $campaign = $this->campaign();
        $this->actingAs($this->user())->get(route('manage.edit', $campaign))->assertForbidden();
        $this->put(route('manage.update', $campaign), $this->fields())->assertForbidden();
        $this->post(route('manage.updates', $campaign), ['title' => 'Berita', 'body' => 'Kegiatan'])->assertForbidden();
        $this->post(route('manage.expenses', $campaign), ['title' => 'Buku', 'amount' => 1, 'spent_at' => today()->format('Y-m-d')])->assertForbidden();
        $this->assertDatabaseCount('campaign_updates', 0);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_editing_an_approved_campaign_requires_verification_again(): void
    {
        $owner = $this->user();
        $campaign = $this->campaign($owner);
        $this->actingAs($owner)->put(route('manage.update', $campaign), $this->fields(['title' => 'Judul diperbarui']))
            ->assertRedirect(route('manage.campaigns'));
        $this->assertSame('pending', $campaign->fresh()->status);
        $this->get(route('campaign.show', $campaign))->assertOk()->assertSee('Pratinjau');
        $this->get(route('manage.edit', $campaign))->assertOk();
        auth()->logout();
        $this->get(route('campaign.show', $campaign))->assertNotFound();
        $this->get(route('campaigns'))->assertDontSee('Judul diperbarui');
    }

    public function test_admin_can_only_moderate_pending_campaigns_with_a_valid_deadline(): void
    {
        $campaign = $this->campaign(null, ['status' => 'pending']);
        $admin = $this->user('admin');
        $this->actingAs($admin)->get(route('admin'))->assertOk()->assertSee($campaign->title);
        $this->post(route('admin.approve', $campaign))->assertSessionHasNoErrors();
        $this->assertSame('approved', $campaign->fresh()->status);
        $this->post(route('admin.reject', $campaign))->assertSessionHasErrors('campaign');
        $this->assertSame('approved', $campaign->fresh()->status);

        $expired = $this->campaign(null, ['status' => 'pending', 'deadline' => today()->subDay()->format('Y-m-d')]);
        $this->post(route('admin.approve', $expired))->assertSessionHasErrors('campaign');
        $this->assertSame('pending', $expired->fresh()->status);
        $this->post(route('admin.reject', $expired))->assertSessionHasNoErrors();
        $this->assertSame('rejected', $expired->fresh()->status);
    }

    public function test_news_only_publishes_updates_from_approved_campaigns(): void
    {
        $approved = $this->campaign();
        $pending = $this->campaign(null, ['status' => 'pending']);
        $rejected = $this->campaign(null, ['status' => 'rejected']);
        $approved->updates()->create(['title' => 'Berita publik', 'body' => 'Kegiatan terbuka']);
        $pending->updates()->create(['title' => 'Berita rahasia pending', 'body' => 'Kegiatan privat']);
        $rejected->updates()->create(['title' => 'Berita rahasia rejected', 'body' => 'Kegiatan privat']);

        $this->get(route('news'))->assertOk()->assertSee('Berita publik')->assertDontSee('Berita rahasia');
    }

    public function test_expenses_only_use_settled_net_funds_and_cannot_exceed_the_balance(): void
    {
        $owner = $this->user();
        $campaign = $this->campaign($owner);
        $this->donation($campaign, ['amount' => 100000]);
        $this->donation($campaign, ['amount' => 100000, 'status' => 'partially_refunded', 'refunded_amount' => 25000]);
        $this->donation($campaign, ['amount' => 900000, 'status' => 'pending', 'paid_at' => null]);
        $this->donation($campaign, ['amount' => 900000, 'status' => 'refunded', 'refunded_amount' => 900000, 'paid_at' => null]);
        $payload = ['title' => 'Beli buku', 'amount' => 175000, 'spent_at' => today()->format('Y-m-d')];

        $this->actingAs($owner)->post(route('manage.expenses', $campaign), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('expenses', ['campaign_id' => $campaign->id, 'amount' => 175000]);
        $this->post(route('manage.expenses', $campaign), array_replace($payload, ['amount' => 1]))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('expenses', 1);
        $this->assertSame(175000, $campaign->fresh()->raised);
        $this->assertSame(0, $campaign->fresh()->available);
    }

    public function test_private_campaigns_and_invalid_dates_cannot_create_ledger_entries(): void
    {
        $owner = $this->user();
        $campaign = $this->campaign($owner, ['status' => 'pending']);
        $this->donation($campaign);
        $this->actingAs($owner)->post(route('manage.updates', $campaign), ['title' => 'Berita', 'body' => 'Kegiatan'])
            ->assertSessionHasErrors('campaign');
        $this->post(route('manage.expenses', $campaign), ['title' => 'Buku', 'amount' => 50000, 'spent_at' => today()->format('Y-m-d')])
            ->assertSessionHasErrors('campaign');
        $campaign->update(['status' => 'approved']);
        $this->post(route('manage.expenses', $campaign), ['title' => ['invalid'], 'amount' => 50000, 'spent_at' => today()->addDay()->format('Y-m-d')])
            ->assertSessionHasErrors(['title', 'spent_at']);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('campaign_updates', 0);
        $this->post(route('manage.updates', $campaign), ['title' => 'Kegiatan terbit', 'body' => 'Bantuan telah disalurkan.'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('campaign_updates', 1);
    }

    public function test_public_reports_and_top_donors_respect_visibility_anonymity_and_refunds(): void
    {
        $campaign = $this->campaign();
        $private = $this->campaign(null, ['status' => 'pending']);
        $named = $this->user('donor');
        $anonymous = $this->user('donor');
        $this->donation($campaign, ['user_id' => $named->id, 'amount' => 100000, 'status' => 'partially_refunded', 'refunded_amount' => 20000]);
        $this->donation($campaign, ['user_id' => $anonymous->id, 'amount' => 50000, 'anonymous' => true]);
        $this->donation($private, ['amount' => 500000]);
        $campaign->expenses()->create(['title' => 'Buku', 'amount' => 30000, 'spent_at' => today()]);
        $private->expenses()->create(['title' => 'Pengeluaran privat', 'amount' => 40000, 'spent_at' => today()]);

        $this->get(route('home'))->assertOk()->assertViewHas('total', 130000)->assertViewHas('donors', 2);
        $this->get(route('transparency'))->assertOk()->assertViewHas('raised', 130000)->assertViewHas('spent', 30000)->assertDontSee($private->title);
        $this->get(route('top'))->assertOk()->assertSee($named->name)->assertDontSee($anonymous->name)
            ->assertViewHas('donors', fn ($donors) => $donors->count() === 1 && (int) $donors->first()->total === 80000);
    }

    public function test_funding_totals_are_loaded_once_for_a_campaign_collection(): void
    {
        $campaign = $this->campaign(null, ['target' => 100000]);
        $this->donation($campaign, ['amount' => 200000, 'status' => 'partially_refunded', 'refunded_amount' => 50000]);
        $campaign->expenses()->create(['title' => 'Buku', 'amount' => 25000, 'spent_at' => today()]);
        $cards = Campaign::withFundingTotals()->get();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame(150000, $cards->first()->raised);
        $this->assertSame(25000, $cards->first()->spent);
        $this->assertSame(125000, $cards->first()->available);
        $this->assertSame(100, $cards->first()->percent);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_campaign_filter_pagination_keeps_the_search_parameters(): void
    {
        $owner = $this->user();
        for ($index = 0; $index < 11; $index++) {
            $this->campaign($owner, ['title' => 'Pencarian Sekolah '.$index]);
        }
        $this->campaign($owner, ['title' => 'Pencarian rahasia', 'status' => 'pending']);
        $this->campaign($owner, ['title' => 'Pencarian sosial', 'category' => 'Sosial']);

        $this->get(route('campaigns', ['q' => 'Pencarian', 'category' => 'Pendidikan']))->assertOk()
            ->assertViewHas('campaigns', fn ($campaigns) => $campaigns->total() === 11 && $campaigns->count() === 9 && str_contains($campaigns->nextPageUrl(), 'q=Pencarian'));
        $this->get(route('campaigns', ['q' => ['invalid']]))->assertSessionHasErrors('q');
    }

    public function test_favorites_are_private_to_each_user_and_hide_unapproved_campaigns(): void
    {
        $campaign = $this->campaign();
        $private = $this->campaign(null, ['status' => 'pending']);
        $donor = $this->user('donor');
        $other = $this->user('donor');
        $this->actingAs($donor)->post(route('favorite', $campaign))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('favorites', ['user_id' => $donor->id, 'campaign_id' => $campaign->id]);
        $this->get(route('favorites'))->assertOk()->assertSee($campaign->title);
        $this->post(route('favorite', $private))->assertNotFound();
        $this->actingAs($other)->get(route('favorites'))->assertOk()->assertDontSee($campaign->title);
        $this->actingAs($donor)->post(route('favorite', $campaign));
        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_dashboard_totals_include_all_donations_and_net_refunds(): void
    {
        $campaign = $this->campaign();
        $donor = $this->user('donor');
        for ($index = 0; $index < 10; $index++) {
            $this->donation($campaign, ['user_id' => $donor->id]);
        }
        $this->donation($campaign, ['user_id' => $donor->id, 'amount' => 50000, 'status' => 'partially_refunded', 'refunded_amount' => 25000]);
        $this->actingAs($donor)->get(route('dashboard'))->assertOk()
            ->assertViewHas('donatedTotal', 1025000)
            ->assertViewHas('donationCount', 11)
            ->assertViewHas('donations', fn ($donations) => $donations->count() === 8);
    }

    public function test_campaign_detail_paginates_updates_and_expenses_and_expiry_is_immutable(): void
    {
        $campaign = $this->campaign(null, ['deadline' => today()->subDay()->format('Y-m-d')]);
        for ($index = 0; $index < 17; $index++) {
            $campaign->updates()->create(['title' => 'Berita '.$index, 'body' => 'Kegiatan']);
            $campaign->expenses()->create(['title' => 'Buku '.$index, 'amount' => 100, 'spent_at' => today()]);
        }
        $this->get(route('campaign.show', $campaign))->assertOk()
            ->assertViewHas('updates', fn ($updates) => $updates->count() === 10 && $updates->total() === 17)
            ->assertViewHas('expenses', fn ($expenses) => $expenses->count() === 15 && $expenses->total() === 17)
            ->assertSee('Donasi telah berakhir.');
        $this->assertFalse($campaign->is_open);
        $this->assertSame('00:00:00', $campaign->deadline->format('H:i:s'));
    }
}
