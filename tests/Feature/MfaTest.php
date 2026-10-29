<?php

namespace Tests\Feature;

use App\Filament\Auth\RavnAppAuthentication;
use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\UserMfaMethod;
use App\Services\MfaService;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaTest extends TestCase
{
    protected function codeFor(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    protected function codeAtCounter(string $secret, int $counter): string
    {
        return app(Google2FA::class)->oathTotp($secret, $counter);
    }

    protected function counterNow(): int
    {
        return (int) (time() / 30);
    }

    public function test_totp_enrolment_confirms_with_a_valid_code_and_returns_recovery_codes(): void
    {
        $user = User::factory()->hrAdmin()->create();

        $enrol = $this->actingAs($user)->postJson('/api/v1/auth/mfa/enroll');
        $enrol->assertOk();

        $secret = $enrol->json('secret');
        $this->assertNotEmpty($secret);

        $confirm = $this->actingAs($user)->postJson('/api/v1/auth/mfa/confirm', [
            'code' => $this->codeFor($secret),
        ]);

        $confirm->assertOk();
        $this->assertCount(MfaService::RECOVERY_CODE_COUNT, $confirm->json('recovery_codes'));

        $method = UserMfaMethod::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($method->confirmed_at);
    }

    public function test_an_invalid_totp_code_is_rejected(): void
    {
        $user = User::factory()->hrAdmin()->create();

        $secret = $this->actingAs($user)->postJson('/api/v1/auth/mfa/enroll')->json('secret');

        $response = $this->actingAs($user)->postJson('/api/v1/auth/mfa/confirm', [
            'code' => '000000',
        ]);

        $response->assertStatus(422);
        $this->assertNull(UserMfaMethod::query()->where('user_id', $user->id)->first()->confirmed_at);
    }

    public function test_a_totp_code_cannot_be_replayed_across_the_same_window(): void
    {
        $user = User::factory()->hrAdmin()->create();

        $secret = $this->actingAs($user)->postJson('/api/v1/auth/mfa/enroll')->json('secret');
        $counter = $this->counterNow();
        $code = $this->codeAtCounter($secret, $counter);
        $this->actingAs($user)->postJson('/api/v1/auth/mfa/confirm', ['code' => $code])->assertOk();

        $replayed = $this->codeAtCounter($secret, $counter);

        $this->actingAs($user)->postJson('/api/v1/auth/mfa/verify', ['code' => $replayed])->assertStatus(422);

        $next = $this->codeAtCounter($secret, $counter + 1);
        $this->actingAs($user)->postJson('/api/v1/auth/mfa/verify', ['code' => $next])->assertOk();
    }

    public function test_mfa_step_up_is_rate_limited_after_repeated_invalid_codes(): void
    {
        $user = User::factory()->hrAdmin()->create();

        $secret = $this->actingAs($user)->postJson('/api/v1/auth/mfa/enroll')->json('secret');
        $this->actingAs($user)->postJson('/api/v1/auth/mfa/confirm', [
            'code' => $this->codeFor($secret),
        ])->assertOk();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($user)
                ->postJson('/api/v1/auth/mfa/step-up', ['code' => 'not-a-code'])
                ->assertStatus(422);
        }

        $this->actingAs($user)
            ->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->codeFor($secret)])
            ->assertStatus(429);
    }

    public function test_recovery_codes_are_single_use(): void
    {
        $user = User::factory()->hrAdmin()->create();

        $secret = $this->actingAs($user)->postJson('/api/v1/auth/mfa/enroll')->json('secret');
        $codes = $this->actingAs($user)->postJson('/api/v1/auth/mfa/confirm', [
            'code' => $this->codeFor($secret),
        ])->json('recovery_codes');

        $this->actingAs($user)->postJson('/api/v1/auth/mfa/verify', ['code' => $codes[0]])->assertOk();
        $this->actingAs($user)->postJson('/api/v1/auth/mfa/verify', ['code' => $codes[0]])->assertStatus(422);
    }

    public function test_mfa_enrolled_admin_can_log_in_with_a_totp_code(): void
    {
        $user = User::factory()->hrAdmin()->create([
            'email' => 'mfa.admin@example.test',
            'password' => bcrypt('correct-password'),
        ]);

        $secret = $this->actingAs($user)->postJson('/api/v1/auth/mfa/enroll')->json('secret');
        $this->actingAs($user)->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->codeFor($secret)])->assertOk();

        $withoutCode = $this->postJson('/api/v1/auth/login', [
            'email' => 'mfa.admin@example.test',
            'password' => 'correct-password',
            'device_name' => 'phpunit',
        ]);
        $withoutCode->assertUnauthorized();

        $withCode = $this->postJson('/api/v1/auth/login', [
            'email' => 'mfa.admin@example.test',
            'password' => 'correct-password',
            'code' => $this->codeAtCounter($secret, $this->counterNow() + 1),
            'device_name' => 'phpunit',
        ]);
        $withCode->assertOk();
        $this->assertNotEmpty($withCode->json('token'));
    }

    public function test_unenrolled_admin_can_bootstrap_mfa_and_rotate_to_a_full_session(): void
    {
        $user = User::factory()->hrAdmin()->create([
            'email' => 'bootstrap.admin@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'bootstrap.admin@example.test',
            'password' => 'correct-password',
            'device_name' => 'phpunit',
        ]);

        $login->assertOk()
            ->assertJsonPath('mfa_enrollment_required', true)
            ->assertJsonPath('user.email', 'bootstrap.admin@example.test');

        $enrollmentToken = $login->json('token');
        $this->assertNotEmpty($enrollmentToken);

        $this->withToken($enrollmentToken)->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'MFA_REQUIRED');

        $this->withToken($enrollmentToken)->postJson('/api/v1/auth/mfa/verify', ['code' => '123456'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'MFA_REQUIRED');

        $secret = $this->withToken($enrollmentToken)
            ->postJson('/api/v1/auth/mfa/enroll')
            ->assertOk()
            ->json('secret');

        $confirm = $this->withToken($enrollmentToken)->postJson('/api/v1/auth/mfa/confirm', [
            'code' => $this->codeFor($secret),
        ]);

        $confirm->assertOk()
            ->assertJsonPath('user.email', 'bootstrap.admin@example.test');

        $this->assertCount(MfaService::RECOVERY_CODE_COUNT, $confirm->json('recovery_codes'));

        $fullToken = $confirm->json('token');
        $this->assertNotEmpty($fullToken);

        $this->freshRequest();
        $this->withToken($fullToken)->getJson('/api/v1/me')->assertOk();

        $this->freshRequest();
        $this->withToken($enrollmentToken)->getJson('/api/v1/me')->assertUnauthorized();

        $fullTokenRecord = PersonalAccessToken::query()
            ->where('tokenable_id', $user->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($fullTokenRecord?->mfa_verified_at);
    }

    public function test_mfa_enrollment_token_expires_after_the_bootstrap_window(): void
    {
        $user = User::factory()->superAdmin()->create([
            'email' => 'expiring.admin@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'expiring.admin@example.test',
            'password' => 'correct-password',
            'device_name' => 'phpunit',
        ])->json('token');

        $this->travel(11)->minutes();

        $this->freshRequest();
        $this->withToken($token)->postJson('/api/v1/auth/mfa/enroll')->assertUnauthorized();
    }

    public function test_sensitive_admin_action_requires_fresh_mfa(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $target = User::factory()->create();

        $this->actingAs($admin)->postJson("/api/v1/admin/users/{$target->id}/deactivate")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'MFA_REQUIRED');
    }

    public function test_mfa_secrets_and_recovery_codes_never_appear_in_audit_rows(): void
    {
        $user = User::factory()->hrAdmin()->create();

        $secret = $this->actingAs($user)->postJson('/api/v1/auth/mfa/enroll')->json('secret');
        $codes = $this->actingAs($user)->postJson('/api/v1/auth/mfa/confirm', [
            'code' => $this->codeFor($secret),
        ])->json('recovery_codes');

        foreach (AuditLog::query()->get() as $log) {
            $payload = json_encode([$log->before, $log->after]);

            $this->assertStringNotContainsString($secret, $payload);
            foreach ($codes as $code) {
                $this->assertStringNotContainsString($code, $payload);
            }
        }

        $stored = UserMfaMethod::query()->where('user_id', $user->id)->first()->getRawOriginal('secret');
        $this->assertStringNotContainsString($secret, $stored);
    }

    public function test_filament_and_api_share_bcrypt_recovery_codes(): void
    {
        $user = User::factory()->hrAdmin()->create();

        $secret = app(Google2FA::class)->generateSecretKey();
        $user->mfaMethods()->create([
            'type' => 'totp',
            'secret' => $secret,
            'confirmed_at' => now(),
        ]);

        $codes = app(MfaService::class)->generateRecoveryCodes($user);

        $this->assertCount(MfaService::RECOVERY_CODE_COUNT, $codes);

        $hashes = $user->mfaRecoveryCodes()->pluck('code_hash');

        foreach ($codes as $index => $code) {
            $this->assertStringStartsWith('$2y$', $hashes[$index]);
            $this->assertTrue(Hash::check($code, $hashes[$index]));
        }

        $filament = app(RavnAppAuthentication::class);

        $this->assertTrue($filament->verifyRecoveryCode($codes[0], $user));
        $this->assertFalse($filament->verifyRecoveryCode('NOT-A-REAL-CODE', $user));

        $this->actingAs($user)
            ->postJson('/api/v1/auth/mfa/verify', ['code' => $codes[1]])
            ->assertOk();

        $this->assertSame(1, $user->mfaRecoveryCodes()->whereNotNull('used_at')->count());
    }
}
