<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_normalizes_email_hashes_password_and_signs_in(): void
    {
        $response = $this->post(route('register.submit'), [
            'name' => 'Donatur Baru',
            'email' => '  NEW@EXAMPLE.COM  ',
            'password' => 'password-aman',
            'password_confirmation' => 'password-aman',
            'role' => 'donor',
        ]);

        $response->assertRedirect(route('dashboard'));
        $user = User::sole();
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame('donor', $user->role);
        $this->assertTrue(Hash::check('password-aman', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_cannot_create_an_admin_or_duplicate_normalized_email(): void
    {
        $this->createUser();
        $this->post(route('register.submit'), [
            'name' => 'Akun Tidak Valid',
            'email' => '  DONOR@EXAMPLE.COM ',
            'password' => 'password-aman',
            'password_confirmation' => 'password-aman',
            'role' => 'admin',
        ])->assertSessionHasErrors(['email', 'role']);

        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    public function test_registration_rejects_passwords_that_bcrypt_cannot_store_safely(): void
    {
        foreach ([str_repeat('é', 40), "password\0terlarang"] as $password) {
            $this->post(route('register.submit'), [
                'name' => 'Donatur Baru',
                'email' => 'new@example.com',
                'password' => $password,
                'password_confirmation' => $password,
                'role' => 'donor',
            ])->assertSessionHasErrors('password');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_normalizes_email_and_returns_to_the_intended_page(): void
    {
        $user = $this->createUser();
        $this->get(route('profile'))->assertRedirect(route('login'));

        $this->post(route('login.submit'), [
            'email' => ' DONOR@EXAMPLE.COM ',
            'password' => 'password-aman',
            'remember' => '1',
        ])->assertRedirect(route('profile'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotEmpty($user->fresh()->remember_token);
    }

    public function test_login_is_locked_after_five_failures_and_recovers_after_a_minute(): void
    {
        $user = $this->createUser();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.submit'), [
                'email' => $user->email,
                'password' => 'password-salah',
            ])->assertSessionHasErrors(['email' => 'Email atau password salah.']);
        }

        $response = $this->post(route('login.submit'), [
            'email' => $user->email,
            'password' => 'password-aman',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Terlalu banyak percobaan', session('errors')->first('email'));
        $this->assertGuest();

        $this->travel(61)->seconds();
        $this->post(route('login.submit'), [
            'email' => $user->email,
            'password' => 'password-aman',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejects_malformed_input_without_server_error(): void
    {
        $this->post(route('login.submit'), [
            'email' => ['donor@example.com'],
            'password' => ['password-aman'],
        ])->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
    }

    public function test_logout_invalidates_the_session(): void
    {
        $this->actingAs($this->createUser())
            ->withSession(['private_value' => 'must disappear'])
            ->post(route('logout'))
            ->assertRedirect(route('home'))
            ->assertSessionMissing('private_value');

        $this->assertGuest();
        $this->get(route('profile'))->assertRedirect(route('login'));
    }

    public function test_profile_update_normalizes_email_preserves_password_and_rejects_role_escalation(): void
    {
        $user = $this->createUser(['email_verified_at' => now()]);
        $oldPassword = $user->password;

        $this->actingAs($user)->from(route('profile'))->post(route('profile.update'), [
            'name' => 'Nama Baru',
            'email' => ' NEW@EXAMPLE.COM ',
            'role' => 'admin',
            'password' => '',
            'password_confirmation' => '',
            'current_password' => '',
        ])->assertRedirect(route('profile'))->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Nama Baru', $user->name);
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertSame($oldPassword, $user->password);
        $this->assertSame('donor', $user->role);
    }

    public function test_profile_rejects_another_users_normalized_email(): void
    {
        $user = $this->createUser();
        $this->createUser(['email' => 'other@example.com']);

        $this->actingAs($user)->post(route('profile.update'), [
            'name' => 'Nama Baru',
            'email' => ' OTHER@EXAMPLE.COM ',
        ])->assertSessionHasErrors('email');

        $this->assertSame('donor@example.com', $user->fresh()->email);
    }

    public function test_password_change_requires_the_correct_current_password(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        foreach ([null, 'password-salah'] as $currentPassword) {
            $this->post(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'current_password' => $currentPassword,
                'password' => 'password-baru',
                'password_confirmation' => 'password-baru',
            ])->assertSessionHasErrors('current_password');
        }

        $this->assertTrue(Hash::check('password-aman', $user->fresh()->password));
    }

    public function test_password_change_requires_confirmation_and_rotates_remember_token(): void
    {
        $user = $this->createUser(['remember_token' => 'old-token']);
        $this->actingAs($user)->post(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'password-aman',
            'password' => 'password-baru',
            'password_confirmation' => 'tidak-sama',
        ])->assertSessionHasErrors('password');

        $this->from(route('profile'))->post(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'password-aman',
            'password' => 'password-baru',
            'password_confirmation' => 'password-baru',
        ])->assertRedirect(route('profile'))->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('password-baru', $user->password));
        $this->assertNotSame('old-token', $user->remember_token);
        $this->assertAuthenticatedAs($user);
    }

    public function test_account_forms_render_and_guests_cannot_update_a_profile(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Ingat saya');
        $this->get(route('register'))->assertOk()->assertSee('Jenis Akun');
        $this->post(route('profile.update'), [])->assertRedirect(route('login'));
        $this->actingAs($this->createUser())->get(route('profile'))->assertOk()->assertSee('Ganti Password');
    }

    private function createUser(array $attributes = []): User
    {
        $user = (new User)->forceFill(array_merge([
            'name' => 'Donatur',
            'email' => 'donor@example.com',
            'password' => 'password-aman',
            'role' => 'donor',
        ], $attributes));
        $user->save();

        return $user;
    }
}
