<?php

namespace Tests\Feature\Notifications;

use App\Models\AuditLog;
use App\Models\DeviceToken;

class DeviceTokenTest extends NotificationTestCase
{
    public function test_a_user_registers_a_device_token_without_exposing_it(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->employee))
            ->postJson('/api/v1/devices', [
                'token' => 'token-a',
                'platform' => 'android',
                'device_id' => 'device-1',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.device_id', 'device-1')
            ->assertJsonPath('data.active', true)
            ->assertJsonMissingPath('data.token');

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $this->employee->getKey(),
            'token' => 'token-a',
            'platform' => 'android',
            'active' => true,
        ]);
    }

    public function test_re_registration_rotates_the_previous_token_for_the_same_device(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($this->employee)];

        $this->withHeaders($headers)->postJson('/api/v1/devices', [
            'token' => 'token-old',
            'platform' => 'android',
            'device_id' => 'device-1',
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/v1/devices', [
            'token' => 'token-new',
            'platform' => 'android',
            'device_id' => 'device-1',
        ])->assertCreated();

        $this->assertFalse(DeviceToken::query()->where('token', 'token-old')->firstOrFail()->active);
        $this->assertTrue(DeviceToken::query()->where('token', 'token-new')->firstOrFail()->active);
        $this->assertSame(1, DeviceToken::query()
            ->where('user_id', $this->employee->getKey())
            ->where('active', true)
            ->count());
    }

    public function test_a_user_can_revoke_their_own_device(): void
    {
        $device = $this->registerDevice($this->employee);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->employee))
            ->deleteJson('/api/v1/devices/'.$device->getKey())
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertFalse($device->fresh()->active);
    }

    public function test_a_user_cannot_revoke_another_users_device(): void
    {
        $device = $this->registerDevice($this->employee);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->other))
            ->deleteJson('/api/v1/devices/'.$device->getKey())
            ->assertNotFound();

        $this->assertTrue($device->fresh()->active);
    }

    public function test_device_registration_and_revocation_are_audited_without_the_token(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($this->employee)];

        $this->withHeaders($headers)->postJson('/api/v1/devices', [
            'token' => 'token-secret-value',
            'platform' => 'android',
            'device_id' => 'device-1',
        ])->assertCreated();

        $device = DeviceToken::query()->where('token', 'token-secret-value')->firstOrFail();

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/devices/'.$device->getKey())
            ->assertOk();

        $registered = AuditLog::query()->where('action', 'device.registered')->latest('id')->firstOrFail();
        $revoked = AuditLog::query()->where('action', 'device.revoked')->latest('id')->firstOrFail();

        $this->assertStringNotContainsString('token-secret-value', (string) json_encode($registered->after));
        $this->assertStringNotContainsString('token-secret-value', (string) json_encode($revoked->after));
    }

    public function test_logout_can_revoke_the_installation_token(): void
    {
        $device = $this->registerDevice($this->employee);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->employee))
            ->postJson('/api/v1/auth/logout', ['device_id' => 'device-1'])
            ->assertOk();

        $this->assertFalse($device->fresh()->active);
    }

    public function test_device_registration_validates_platform_and_token(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($this->employee)];

        $this->withHeaders($headers)->postJson('/api/v1/devices', [
            'token' => '',
            'platform' => 'windows',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['token', 'platform']]]);
    }

    public function test_device_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/devices', [
            'token' => 'token-a',
            'platform' => 'android',
        ])->assertUnauthorized();
    }
}
