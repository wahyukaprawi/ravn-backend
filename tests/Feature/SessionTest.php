<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionTest extends TestCase
{
    public function test_a_user_can_list_and_revoke_their_own_sessions(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password-123'),
        ]);

        $first = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password-123',
            'device_name' => 'device-one',
        ])->json('token');

        $second = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password-123',
            'device_name' => 'device-two',
        ])->json('token');

        $list = $this->withToken($second)->getJson('/api/v1/me/sessions');
        $list->assertOk();
        $this->assertCount(2, $list->json('sessions'));

        $otherId = PersonalAccessToken::query()
            ->where('name', 'device-one')
            ->value('id');

        $this->withToken($second)->deleteJson("/api/v1/me/sessions/{$otherId}")->assertOk();

        $this->assertNull(PersonalAccessToken::query()->find($otherId));

        $this->freshRequest();
        $this->withToken($first)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($second)->getJson('/api/v1/me')->assertOk();
    }

    public function test_a_user_cannot_revoke_another_users_session(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $victimToken = $owner->createToken('victim');

        $response = $this->actingAs($intruder)
            ->deleteJson("/api/v1/me/sessions/{$victimToken->accessToken->getKey()}");

        $response->assertNotFound();
        $this->assertNotNull($victimToken->accessToken->fresh());
    }
}
