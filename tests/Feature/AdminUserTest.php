<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\UserMfaRecoveryCode;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    protected function password(): string
    {
        return 'correct-password-123';
    }

    protected function enrolAndLogin(User $user): string
    {
        $secret = app(Google2FA::class)->generateSecretKey();

        $user->mfaMethods()->create([
            'type' => 'totp',
            'secret' => $secret,
            'confirmed_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $this->password(),
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
            'device_name' => 'phpunit',
        ]);

        $response->assertOk();

        return $response->json('token');
    }

    protected function admin(string $role = 'super_admin'): User
    {
        return User::factory()->create([
            'password' => Hash::make($this->password()),
        ])->assignRole($role);
    }

    public function test_a_user_without_the_permission_gets_403(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->getJson('/api/v1/admin/users')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_an_hr_admin_cannot_grant_the_super_admin_role(): void
    {
        $hr = $this->admin('hr_admin');
        $target = User::factory()->create();

        $token = $this->enrolAndLogin($hr);

        $this->withToken($token)
            ->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['super_admin']])
            ->assertStatus(422);

        $this->assertFalse($target->fresh()->hasRole('super_admin'));
    }

    public function test_a_role_change_writes_an_audit_row_and_revokes_tokens(): void
    {
        $super = $this->admin('super_admin');
        $target = User::factory()->create();
        $target->createToken('old-device');

        $token = $this->enrolAndLogin($super);

        $this->withToken($token)
            ->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['manager']])
            ->assertOk();

        $this->assertTrue($target->fresh()->hasRole('manager'));
        $this->assertSame(0, $target->tokens()->count());

        $log = AuditLog::query()->where('action', 'user.assign_roles')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($super->id, $log->actor_id);
        $this->assertSame($target->id, $log->subject_id);
        $this->assertContains('manager', $log->after['roles']);
    }

    public function test_deactivation_revokes_the_token_immediately(): void
    {
        $super = $this->admin('super_admin');
        $target = User::factory()->create([
            'password' => Hash::make($this->password()),
        ]);

        $token = $this->enrolAndLogin($super);

        $targetToken = $this->postJson('/api/v1/auth/login', [
            'email' => $target->email,
            'password' => $this->password(),
            'device_name' => 'phpunit',
        ])->json('token');

        $this->withToken($token)
            ->postJson("/api/v1/admin/users/{$target->id}/deactivate")
            ->assertOk();

        $this->assertSame(User::STATUS_INACTIVE, $target->fresh()->status);

        $this->freshRequest();

        $this->withToken($targetToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_reactivation_does_not_restore_revoked_tokens(): void
    {
        $super = $this->admin('super_admin');
        $target = User::factory()->create([
            'password' => Hash::make($this->password()),
        ]);

        $superToken = $this->enrolAndLogin($super);

        $targetToken = $this->postJson('/api/v1/auth/login', [
            'email' => $target->email,
            'password' => $this->password(),
            'device_name' => 'phpunit',
        ])->json('token');

        $this->withToken($superToken)->postJson("/api/v1/admin/users/{$target->id}/deactivate")->assertOk();
        $this->withToken($superToken)->postJson("/api/v1/admin/users/{$target->id}/reactivate")->assertOk();

        $this->freshRequest();

        $this->assertSame(User::STATUS_ACTIVE, $target->fresh()->status);
        $this->withToken($targetToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_deactivating_the_last_active_super_admin_is_rejected(): void
    {
        $onlySuper = $this->admin('super_admin');
        $token = $this->enrolAndLogin($onlySuper);

        $this->withToken($token)
            ->postJson("/api/v1/admin/users/{$onlySuper->id}/deactivate")
            ->assertStatus(422);

        $this->assertSame(User::STATUS_ACTIVE, $onlySuper->fresh()->status);
    }

    public function test_demoting_the_last_active_super_admin_is_rejected(): void
    {
        $onlySuper = $this->admin('super_admin');
        $token = $this->enrolAndLogin($onlySuper);

        $this->withToken($token)
            ->putJson("/api/v1/admin/users/{$onlySuper->id}/roles", ['roles' => ['manager']])
            ->assertStatus(422);

        $this->assertTrue($onlySuper->fresh()->hasRole('super_admin'));
    }

    public function test_a_super_admin_cannot_deactivate_their_own_account_even_with_another_super_admin(): void
    {
        $first = $this->admin('super_admin');
        $second = $this->admin('super_admin');

        $token = $this->enrolAndLogin($first);

        $this->withToken($token)
            ->postJson("/api/v1/admin/users/{$first->id}/deactivate")
            ->assertStatus(422)
            ->assertJsonPath('error.details.user.0', 'You cannot deactivate your own account.');

        $this->assertSame(User::STATUS_ACTIVE, $first->fresh()->status);
        $this->assertSame(User::STATUS_ACTIVE, $second->fresh()->status);
    }

    public function test_a_super_admin_cannot_remove_their_own_super_admin_role(): void
    {
        $first = $this->admin('super_admin');
        $this->admin('super_admin');

        $token = $this->enrolAndLogin($first);

        $this->withToken($token)
            ->putJson("/api/v1/admin/users/{$first->id}/roles", ['roles' => ['manager']])
            ->assertStatus(422)
            ->assertJsonPath('error.details.roles.0', 'You cannot remove your own Super Admin access.');

        $this->assertTrue($first->fresh()->hasRole('super_admin'));
    }

    public function test_a_second_super_admin_allows_deactivation_of_the_first(): void
    {
        $first = $this->admin('super_admin');
        $second = $this->admin('super_admin');

        $token = $this->enrolAndLogin($second);

        $this->withToken($token)
            ->postJson("/api/v1/admin/users/{$first->id}/deactivate")
            ->assertOk();

        $this->assertSame(User::STATUS_INACTIVE, $first->fresh()->status);
    }

    public function test_duplicate_normalized_email_produces_one_account(): void
    {
        $super = $this->admin('super_admin');
        $token = $this->enrolAndLogin($super);

        $first = $this->withToken($token)->postJson('/api/v1/admin/users', [
            'name' => 'Dup One',
            'email' => 'Dup@Example.test',
        ]);

        $first->assertCreated();

        $second = $this->withToken($token)->postJson('/api/v1/admin/users', [
            'name' => 'Dup Two',
            'email' => 'dup@example.test',
        ]);

        $second->assertStatus(422);

        $this->assertSame(1, User::query()->where('email', 'dup@example.test')->count());
    }

    public function test_revoking_sessions_returns_the_number_revoked(): void
    {
        $super = $this->admin('super_admin');
        $target = User::factory()->create();
        $target->createToken('a');
        $target->createToken('b');

        $token = $this->enrolAndLogin($super);

        $response = $this->withToken($token)
            ->postJson("/api/v1/admin/users/{$target->id}/revoke-sessions");

        $response->assertOk();
        $this->assertSame(2, $response->json('revoked'));
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $target->id)->count());
    }

    public function test_a_super_admin_can_reset_another_users_mfa_with_a_fresh_session(): void
    {
        $super = $this->admin('super_admin');
        $target = User::factory()->create();
        $target->createToken('target-device');

        $target->mfaMethods()->create([
            'type' => 'totp',
            'secret' => app(Google2FA::class)->generateSecretKey(),
            'confirmed_at' => now(),
        ]);

        UserMfaRecoveryCode::query()->create([
            'user_id' => $target->id,
            'code_hash' => Hash::make('RECOVERY01'),
            'created_at' => now(),
        ]);

        $token = $this->enrolAndLogin($super);

        $this->withToken($token)
            ->postJson("/api/v1/admin/users/{$target->id}/reset-mfa")
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertSame(0, $target->mfaMethods()->count());
        $this->assertSame(0, $target->mfaRecoveryCodes()->count());
        $this->assertSame(0, $target->tokens()->count());

        $log = AuditLog::query()->where('action', 'user.reset_mfa')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($super->id, $log->actor_id);
        $this->assertSame($target->id, $log->subject_id);
        $this->assertSame(1, $log->before['methods']);
        $this->assertSame(1, $log->before['recovery_code_count']);
        $this->assertSame(0, $log->after['methods']);
    }

    public function test_mfa_reset_requires_the_dedicated_permission(): void
    {
        $hr = $this->admin('hr_admin');
        $target = User::factory()->create();

        $token = $this->enrolAndLogin($hr);

        $this->withToken($token)
            ->postJson("/api/v1/admin/users/{$target->id}/reset-mfa")
            ->assertForbidden();
    }

    public function test_mfa_reset_requires_a_fresh_mfa_session(): void
    {
        $super = $this->admin('super_admin');
        $target = User::factory()->create();

        $this->actingAs($super)
            ->postJson("/api/v1/admin/users/{$target->id}/reset-mfa")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'MFA_REQUIRED');
    }

    public function test_a_super_admin_cannot_reset_their_own_mfa_through_the_admin_endpoint(): void
    {
        $super = $this->admin('super_admin');
        $this->admin('super_admin');

        $token = $this->enrolAndLogin($super);

        $this->withToken($token)
            ->postJson("/api/v1/admin/users/{$super->id}/reset-mfa")
            ->assertStatus(422);
    }
}
