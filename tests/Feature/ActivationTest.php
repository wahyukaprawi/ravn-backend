<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ActivationTokenService;
use Tests\TestCase;

class ActivationTest extends TestCase
{
    public function test_an_invited_user_activates_once_and_receives_a_token(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $created = $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name' => 'New Hire',
            'email' => 'new.hire@example.test',
        ]);

        $created->assertCreated();
        $plain = $created->json('activation_token');
        $this->assertNotEmpty($plain);

        $response = $this->postJson('/api/v1/auth/activate', [
            'token' => $plain,
            'password' => 'brand-new-password-123',
            'password_confirmation' => 'brand-new-password-123',
            'device_name' => 'phpunit',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));

        $user = User::query()->where('email', 'new.hire@example.test')->first();
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertNotNull($user->activated_at);
    }

    public function test_an_activation_token_cannot_be_used_twice(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $plain = $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name' => 'Once Only',
            'email' => 'once@example.test',
        ])->json('activation_token');

        $first = $this->postJson('/api/v1/auth/activate', [
            'token' => $plain,
            'password' => 'brand-new-password-123',
            'password_confirmation' => 'brand-new-password-123',
            'device_name' => 'phpunit',
        ]);
        $first->assertOk();

        $second = $this->postJson('/api/v1/auth/activate', [
            'token' => $plain,
            'password' => 'another-password-1234',
            'password_confirmation' => 'another-password-1234',
            'device_name' => 'phpunit',
        ]);
        $second->assertStatus(422);
        $this->assertSame('VALIDATION_FAILED', $second->json('error.code'));
    }

    public function test_an_expired_activation_token_is_rejected(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $plain = $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name' => 'Slow Starter',
            'email' => 'slow@example.test',
        ])->json('activation_token');

        $service = app(ActivationTokenService::class);
        $token = $service->findUsable($plain);
        $token->forceFill(['expires_at' => now()->subMinute()])->save();

        $response = $this->postJson('/api/v1/auth/activate', [
            'token' => $plain,
            'password' => 'brand-new-password-123',
            'password_confirmation' => 'brand-new-password-123',
            'device_name' => 'phpunit',
        ]);

        $response->assertStatus(422);
    }

    public function test_changing_the_email_invalidates_the_previous_activation_token(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $created = $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name' => 'Email Change',
            'email' => 'first@example.test',
        ]);

        $plain = $created->json('activation_token');
        $user = User::query()->where('email', 'first@example.test')->first();

        $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$user->id}", [
            'email' => 'second@example.test',
        ])->assertOk();

        $response = $this->postJson('/api/v1/auth/activate', [
            'token' => $plain,
            'password' => 'brand-new-password-123',
            'password_confirmation' => 'brand-new-password-123',
            'device_name' => 'phpunit',
        ]);

        $response->assertStatus(422);
    }

    public function test_resend_returns_a_generic_response_for_unknown_addresses(): void
    {
        $response = $this->postJson('/api/v1/auth/activation/resend', [
            'email' => 'nobody@example.test',
        ]);

        $response->assertOk();
        $this->assertStringNotContainsString('nobody@example.test', $response->getContent());
    }

    public function test_activation_requires_a_password_of_at_least_twelve_characters(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $plain = $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name' => 'Weak Password',
            'email' => 'weak@example.test',
        ])->json('activation_token');

        $response = $this->postJson('/api/v1/auth/activate', [
            'token' => $plain,
            'password' => 'short',
            'password_confirmation' => 'short',
            'device_name' => 'phpunit',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('password', $response->json('error.details'));
    }
}
