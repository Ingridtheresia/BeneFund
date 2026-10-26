<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_read_or_change_notifications(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->getJson(route('notifications.status'))->assertUnauthorized();
        $this->postJson(route('notifications.read-all'))->assertUnauthorized();
        $this->postJson(route('notifications.read', Str::uuid()))->assertUnauthorized();
    }

    public function test_inbox_and_status_only_expose_current_users_notifications(): void
    {
        $user = $this->user();
        $own = $this->notification($user, 'Dana masuk milik Anda');
        $this->notification($this->user(), 'Notifikasi pengguna lain');

        $this->actingAs($user)->get(route('notifications.index'))->assertOk()
            ->assertSee('Dana masuk milik Anda')->assertDontSee('Notifikasi pengguna lain')
            ->assertSee(route('notifications.read', $own->id), false);
        $this->getJson(route('notifications.status'))->assertOk()
            ->assertExactJson(['unread_count' => 1, 'latest_id' => $own->id])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_reading_is_scoped_to_owner_and_idempotent(): void
    {
        $user = $this->user();
        $own = $this->notification($user);
        $other = $this->notification($this->user());
        $this->actingAs($user)->postJson(route('notifications.read', $other->id))->assertNotFound();
        $this->assertNull($other->fresh()->read_at);
        $this->postJson(route('notifications.read', $own->id))->assertOk()->assertJson(['unread_count' => 0]);
        $readAt = $own->fresh()->read_at;
        $this->travel(5)->minutes();
        $this->postJson(route('notifications.read', $own->id))->assertOk();
        $this->assertTrue($readAt->equalTo($own->fresh()->read_at));
    }

    public function test_mark_all_only_updates_current_users_unread_items(): void
    {
        $user = $this->user();
        $this->notification($user);
        $this->notification($user);
        $other = $this->notification($this->user());

        $this->actingAs($user)->post(route('notifications.read-all'))->assertRedirect(route('notifications.index'));
        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_unread_filter_and_pagination_work_and_escape_notification_text(): void
    {
        $user = $this->user();
        $read = $this->notification($user, 'Sudah dibaca');
        $read->markAsRead();
        $this->notification($user, '<script>alert(1)</script>');
        $this->actingAs($user)->get(route('notifications.index', ['filter' => 'unread']))
            ->assertOk()->assertDontSee('Sudah dibaca')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);

        foreach (range(1, 16) as $i) {
            $this->notification($user, 'Pembaruan '.$i);
        }
        $response = $this->get(route('notifications.index', ['filter' => 'unread']));
        $response->assertOk()->assertViewHas('notifications', fn ($items) => $items->count() === 15 && $items->total() === 17)
            ->assertSee('filter=unread', false);
        $this->getJson(route('notifications.index', ['filter' => 'invalid']))->assertUnprocessable();
    }

    public function test_empty_state_and_nav_badge_render_for_authenticated_user(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get(route('notifications.index'))->assertOk()->assertSee('Belum ada notifikasi');
        $this->notification($user);
        $this->get(route('dashboard'))->assertOk()->assertSee('Notifikasi, 1 belum dibaca')
            ->assertSee(asset('js/notifications.js'), false);
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Pengguna', 'email' => Str::uuid().'@example.test',
            'password' => 'Password123!', 'role' => 'donor',
        ]);
    }

    private function notification(User $user, string $title = 'Pembaruan transaksi'): DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => 'test',
            'data' => ['kind' => 'test', 'title' => $title, 'message' => 'Status transaksi diperbarui.', 'url' => route('dashboard')],
        ]);
    }
}
