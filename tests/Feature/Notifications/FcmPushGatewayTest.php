<?php

namespace Tests\Feature\Notifications;

use App\Models\DeviceToken;
use App\Models\User;
use App\Notifications\Push\FcmPushGateway;
use App\Notifications\Push\PermanentDeliveryException;
use App\Notifications\Push\RateLimitedException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FcmPushGatewayTest extends TestCase
{
    protected string $credentialsPath;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->credentialsPath = $this->syntheticCredentials();

        config([
            'notifications.push.fcm' => [
                'project_id' => 'ravn-test',
                'credentials_path' => $this->credentialsPath,
                'endpoint' => 'https://fcm.googleapis.com/v1',
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->credentialsPath);

        parent::tearDown();
    }

    public function test_fcm_gateway_sends_the_message_and_reuses_the_access_token(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'expires_in' => 3600,
            ]),
            'https://fcm.googleapis.com/v1/projects/ravn-test/messages:send' => Http::response([
                'name' => 'projects/ravn-test/messages/1',
            ]),
        ]);

        $gateway = new FcmPushGateway((array) config('notifications.push.fcm'));
        $device = $this->device();
        $payload = [
            'title' => 'Ravn',
            'body' => 'You have a new notification.',
            'data' => ['notification_id' => '7', 'type' => 'leave.approved'],
        ];

        $gateway->send($device, $payload);
        $gateway->send($device, $payload);

        Http::assertSent(function ($request) use ($device): bool {
            if ($request->url() !== 'https://fcm.googleapis.com/v1/projects/ravn-test/messages:send') {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer access-token')
                && $request['message']['token'] === $device->token
                && $request['message']['notification']['title'] === 'Ravn'
                && $request['message']['notification']['body'] === 'You have a new notification.'
                && $request['message']['data'] === ['notification_id' => '7', 'type' => 'leave.approved'];
        });

        Http::assertSentCount(3);

        $oauthRequests = Http::recorded(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token');
        $this->assertCount(1, $oauthRequests);
    }

    public function test_an_unregistered_fcm_token_is_a_permanent_failure(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/v1/projects/ravn-test/messages:send' => Http::response([
                'error' => [
                    'status' => 'NOT_FOUND',
                    'details' => [['errorCode' => 'UNREGISTERED']],
                ],
            ], 404),
        ]);

        $gateway = new FcmPushGateway((array) config('notifications.push.fcm'));

        try {
            $gateway->send($this->device(), $this->payload());
            $this->fail('Expected a permanent delivery exception.');
        } catch (PermanentDeliveryException $exception) {
            $this->assertSame('invalid_token', $exception->errorCode);
        }
    }

    public function test_fcm_rate_limits_honour_retry_after(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/v1/projects/ravn-test/messages:send' => Http::response([
                'error' => ['status' => 'RESOURCE_EXHAUSTED'],
            ], 429, ['Retry-After' => '45']),
        ]);

        $gateway = new FcmPushGateway((array) config('notifications.push.fcm'));

        try {
            $gateway->send($this->device(), $this->payload());
            $this->fail('Expected a rate-limit exception.');
        } catch (RateLimitedException $exception) {
            $this->assertSame(45, $exception->retryAfter);
        }
    }

    public function test_missing_fcm_credentials_are_a_permanent_configuration_failure(): void
    {
        $gateway = new FcmPushGateway([
            'project_id' => 'ravn-test',
            'credentials_path' => '/missing/credentials.json',
        ]);

        try {
            $gateway->send($this->device(), $this->payload());
            $this->fail('Expected a permanent delivery exception.');
        } catch (PermanentDeliveryException $exception) {
            $this->assertSame('push_not_configured', $exception->errorCode);
        }
    }

    protected function device(): DeviceToken
    {
        $user = User::factory()->create();

        return DeviceToken::query()->create([
            'user_id' => $user->getKey(),
            'token' => 'fcm-device-token',
            'platform' => 'android',
            'device_id' => 'device-1',
            'active' => true,
            'last_used_at' => now(),
        ]);
    }

    /**
     * @return array{title: string, body: string, data: array<string, string>}
     */
    protected function payload(): array
    {
        return [
            'title' => 'Ravn',
            'body' => 'You have a new notification.',
            'data' => ['notification_id' => '7', 'type' => 'leave.approved'],
        ];
    }

    protected function syntheticCredentials(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));

        $path = tempnam(sys_get_temp_dir(), 'ravn-fcm-');
        $this->assertNotFalse($path);

        file_put_contents($path, (string) json_encode([
            'client_email' => 'ravn-test@example.test',
            'private_key' => $privateKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        return $path;
    }
}
