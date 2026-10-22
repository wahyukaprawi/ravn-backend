<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_wrong_password_and_unknown_email_return_the_same_generic_401(): void
    {
        User::factory()->create([
            'email' => 'known@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'known@example.test',
            'password' => 'wrong-password',
            'device_name' => 'phpunit',
        ]);

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.test',
            'password' => 'whatever-password',
            'device_name' => 'phpunit',
        ]);

        $wrongPassword->assertUnauthorized();
        $unknownEmail->assertUnauthorized();

        $this->assertSame($wrongPassword->json('error.code'), $unknownEmail->json('error.code'));
        $this->assertSame($wrongPassword->json('error.message'), $unknownEmail->json('error.message'));
        $this->assertSame('INVALID_CREDENTIALS', $wrongPassword->json('error.code'));
    }

    public function test_correct_credentials_return_a_token_and_update_last_login(): void
    {
        $user = User::factory()->create([
            'email' => 'active@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'active@example.test',
            'password' => 'correct-password',
            'device_name' => 'phpunit',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('active@example.test', $response->json('user.email'));
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_email_is_normalized_before_lookup(): void
    {
        $user = User::factory()->create([
            'email' => 'mixed@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => '  MIXED@Example.Test ',
            'password' => 'correct-password',
            'device_name' => 'phpunit',
        ]);

        $response->assertOk();
        $this->assertSame($user->id, $response->json('user.id'));
    }

    public function test_pending_activation_users_cannot_log_in(): void
    {
        $user = User::factory()->pendingActivation()->create([
            'email' => 'pending@example.test',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'pending@example.test',
            'password' => 'any-password',
            'device_name' => 'phpunit',
        ]);

        $response->assertUnauthorized();
        $this->assertSame('INVALID_CREDENTIALS', $response->json('error.code'));
        $this->assertStringNotContainsString('activation', mb_strtolower($response->getContent()));
        $this->assertStringNotContainsString('status', mb_strtolower($response->getContent()));
    }

    public function test_inactive_users_cannot_log_in(): void
    {
        User::factory()->inactive()->create([
            'email' => 'inactive@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@example.test',
            'password' => 'correct-password',
            'device_name' => 'phpunit',
        ]);

        $response->assertUnauthorized();
        $this->assertSame('INVALID_CREDENTIALS', $response->json('error.code'));
    }

    public function test_failed_login_is_recorded_in_the_audit_log_without_the_password(): void
    {
        User::factory()->create([
            'email' => 'audited@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'audited@example.test',
            'password' => 'the-wrong-password',
            'device_name' => 'phpunit',
        ])->assertUnauthorized();

        $log = AuditLog::query()->where('action', 'auth.login')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('failure', $log->outcome);
        $this->assertStringNotContainsString('the-wrong-password', json_encode($log->after));
        $this->assertStringNotContainsString('the-wrong-password', json_encode($log->before));
    }

    public function test_authenticated_user_can_read_their_own_profile(): void
    {
        $user = User::factory()->hrAdmin()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertOk();
        $this->assertSame($user->email, $response->json('email'));
        $this->assertContains('hr_admin', $response->json('roles'));
        $this->assertContains('users.create', $response->json('permissions'));
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create([
            'email' => 'logout@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'logout@example.test',
            'password' => 'correct-password',
            'device_name' => 'phpunit',
        ])->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->freshRequest();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }
}
