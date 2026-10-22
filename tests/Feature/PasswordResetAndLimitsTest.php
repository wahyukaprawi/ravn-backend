<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetAndLimitsTest extends TestCase
{
    public function test_repeated_login_attempts_return_429_with_retry_after(): void
    {
        User::factory()->create([
            'email' => 'throttle@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $last = null;

        foreach (range(1, 6) as $ignored) {
            $last = $this->postJson('/api/v1/auth/login', [
                'email' => 'throttle@example.test',
                'password' => 'wrong-password',
                'device_name' => 'phpunit',
            ]);
        }

        $last->assertStatus(429);
        $last->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertNotNull($last->headers->get('Retry-After'));
    }

    public function test_repeated_activation_resend_attempts_return_429(): void
    {
        $last = null;

        foreach (range(1, 5) as $ignored) {
            $last = $this->postJson('/api/v1/auth/activation/resend', [
                'email' => 'someone@example.test',
            ]);
        }

        $last->assertStatus(429);
    }

    public function test_password_reset_token_is_single_use(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.test',
            'password' => Hash::make('old-password-123'),
        ]);

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'reset@example.test',
        ])->assertOk();

        $record = DB::table('password_reset_tokens')->where('email', 'reset@example.test')->first();
        $this->assertNotNull($record);

        $plain = 'known-reset-token-value';
        DB::table('password_reset_tokens')->where('email', 'reset@example.test')->update([
            'token' => Hash::make($plain),
            'created_at' => now(),
        ]);

        $first = $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'reset@example.test',
            'token' => $plain,
            'password' => 'new-password-1234',
            'password_confirmation' => 'new-password-1234',
            'device_name' => 'phpunit',
        ]);
        $first->assertOk();

        $second = $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'reset@example.test',
            'token' => $plain,
            'password' => 'another-password-123',
            'password_confirmation' => 'another-password-123',
            'device_name' => 'phpunit',
        ]);
        $second->assertStatus(422);

        $this->assertTrue(Hash::check('new-password-1234', $user->fresh()->password));
    }

    public function test_an_expired_reset_token_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'expired-reset@example.test',
            'password' => Hash::make('old-password-123'),
        ]);

        $plain = 'expired-token-value';

        DB::table('password_reset_tokens')->insert([
            'email' => 'expired-reset@example.test',
            'token' => Hash::make($plain),
            'created_at' => now()->subHours(2),
        ]);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'expired-reset@example.test',
            'token' => $plain,
            'password' => 'new-password-1234',
            'password_confirmation' => 'new-password-1234',
            'device_name' => 'phpunit',
        ]);

        $response->assertStatus(422);
    }

    public function test_forgot_returns_a_generic_response_for_unknown_addresses(): void
    {
        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'ghost@example.test',
        ]);

        $response->assertOk();
        $this->assertStringNotContainsString('ghost@example.test', $response->getContent());
    }
}
