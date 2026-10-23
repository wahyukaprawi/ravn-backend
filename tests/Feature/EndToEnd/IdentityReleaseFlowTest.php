<?php

namespace Tests\Feature\EndToEnd;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\ActivationTokenService;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class IdentityReleaseFlowTest extends TestCase
{
    protected function adminWithMfa(): array
    {
        $admin = User::factory()->superAdmin()->create([
            'password' => bcrypt('release-admin-password-123'),
        ]);

        $secret = app(Google2FA::class)->generateSecretKey();

        $admin->mfaMethods()->create([
            'type' => 'totp',
            'secret' => $secret,
            'confirmed_at' => now(),
        ]);

        return [$admin, $secret];
    }

    public function test_e2e_01_activation_login_mfa_session_revocation_and_deactivation(): void
    {
        [$admin, $secret] = $this->adminWithMfa();

        $adminLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'release-admin-password-123',
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
            'device_name' => 'release-e2e-admin',
        ])->assertOk();

        $adminToken = $adminLogin->json('token');
        $this->assertNotEmpty($adminToken);

        $invite = $this->withToken($adminToken)->postJson('/api/v1/admin/users', [
            'name' => 'Release Candidate',
            'email' => 'release.candidate@example.test',
        ])->assertCreated();

        $userId = $invite->json('data.id');
        $firstToken = $invite->json('activation_token');

        $activation = app(ActivationTokenService::class)->findUsable($firstToken);
        $this->assertNotNull($activation);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $activation->expires_at->timestamp, 5);

        $activation->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/v1/auth/activate', [
            'token' => $firstToken,
            'password' => 'release-candidate-password-123',
            'password_confirmation' => 'release-candidate-password-123',
            'device_name' => 'release-e2e-employee',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $reissued = $this->withToken($adminToken)
            ->postJson("/api/v1/admin/users/{$userId}/reissue-invitation")
            ->assertOk();

        $secondToken = $reissued->json('activation_token');
        $this->assertNotSame($firstToken, $secondToken);

        $this->postJson('/api/v1/auth/activate', [
            'token' => $firstToken,
            'password' => 'release-candidate-password-123',
            'password_confirmation' => 'release-candidate-password-123',
            'device_name' => 'release-e2e-employee',
        ])->assertStatus(422);

        $activated = $this->postJson('/api/v1/auth/activate', [
            'token' => $secondToken,
            'password' => 'release-candidate-password-123',
            'password_confirmation' => 'release-candidate-password-123',
            'device_name' => 'release-e2e-employee',
        ])->assertOk();

        $employeeToken = $activated->json('token');
        $this->assertNotEmpty($employeeToken);

        $this->freshRequest()->withToken($employeeToken)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('email', 'release.candidate@example.test')
            ->assertJsonPath('status', User::STATUS_ACTIVE);

        $this->freshRequest()->withToken($employeeToken)->postJson('/api/v1/auth/logout')->assertOk();
        $this->freshRequest()->withToken($employeeToken)->getJson('/api/v1/me')->assertUnauthorized();

        $relogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'release.candidate@example.test',
            'password' => 'release-candidate-password-123',
            'device_name' => 'release-e2e-employee-2',
        ])->assertOk();

        $secondEmployeeToken = $relogin->json('token');

        $this->freshRequest()->withToken($adminToken)
            ->postJson("/api/v1/admin/users/{$userId}/revoke-sessions")
            ->assertOk()
            ->assertJsonPath('revoked', 1);

        $this->freshRequest()->withToken($secondEmployeeToken)->getJson('/api/v1/me')->assertUnauthorized();

        $this->freshRequest()->withToken($adminToken)
            ->postJson("/api/v1/admin/users/{$userId}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.status', User::STATUS_INACTIVE);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'release.candidate@example.test',
            'password' => 'release-candidate-password-123',
            'device_name' => 'release-e2e-employee-3',
        ])->assertUnauthorized();

        foreach ([
            'user.invite',
            'auth.activate',
            'auth.login',
            'user.revoke_sessions',
            'user.deactivate',
        ] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->exists(), "Missing audit action {$action}");
        }
    }
}
