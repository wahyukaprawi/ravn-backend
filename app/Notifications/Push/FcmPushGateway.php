<?php

namespace App\Notifications\Push;

use App\Models\DeviceToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FcmPushGateway implements PushGateway
{
    /**
     * @param  array{project_id?: string|null, credentials_path?: string|null, endpoint?: string|null, token_uri?: string|null}  $config
     */
    public function __construct(private readonly array $config) {}

    public function send(DeviceToken $device, array $payload): void
    {
        $credentials = $this->credentials();
        $accessToken = $this->accessToken($credentials);

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post($this->messagesEndpoint(), [
                    'message' => [
                        'token' => $device->token,
                        'notification' => [
                            'title' => $payload['title'],
                            'body' => $payload['body'],
                        ],
                        'data' => array_map('strval', $payload['data']),
                        'android' => ['priority' => 'high'],
                        'apns' => ['headers' => ['apns-priority' => '10']],
                    ],
                ]);
        } catch (ConnectionException) {
            throw new TransientDeliveryException('provider_unavailable');
        }

        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $reason = (string) ($response->json('error.details.0.errorCode') ?? $response->json('error.status') ?? '');

        if (in_array($reason, ['UNREGISTERED', 'INVALID_ARGUMENT', 'SENDER_ID_MISMATCH', 'THIRD_PARTY_AUTH_ERROR'], true)) {
            throw new PermanentDeliveryException('invalid_token');
        }

        if ($status === 429) {
            throw new RateLimitedException((int) ($response->header('Retry-After') ?: 60));
        }

        if ($status === 401) {
            Cache::forget($this->cacheKey($credentials));

            throw new TransientDeliveryException('provider_auth');
        }

        if ($status >= 500) {
            throw new TransientDeliveryException('provider_unavailable');
        }

        throw new PermanentDeliveryException('provider_rejected');
    }

    /**
     * @return array{client_email: string, private_key: string, token_uri: string}
     */
    protected function credentials(): array
    {
        $path = (string) ($this->config['credentials_path'] ?? '');

        if ($path === '' || ! is_readable($path)) {
            throw new PermanentDeliveryException('push_not_configured');
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['client_email'], $decoded['private_key'])) {
            throw new PermanentDeliveryException('push_not_configured');
        }

        return [
            'client_email' => (string) $decoded['client_email'],
            'private_key' => (string) $decoded['private_key'],
            'token_uri' => (string) ($decoded['token_uri'] ?? $this->config['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        ];
    }

    /**
     * @param  array{client_email: string, private_key: string, token_uri: string}  $credentials
     */
    protected function accessToken(array $credentials): string
    {
        $cacheKey = $this->cacheKey($credentials);
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $header = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64Url((string) json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $credentials['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = '';

        if (! openssl_sign($header.'.'.$claims, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new PermanentDeliveryException('push_not_configured');
        }

        try {
            $response = Http::asForm()->post($credentials['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $header.'.'.$claims.'.'.$this->base64Url($signature),
            ]);
        } catch (ConnectionException) {
            throw new TransientDeliveryException('provider_unavailable');
        }

        if ($response->status() === 429) {
            throw new RateLimitedException((int) ($response->header('Retry-After') ?: 60));
        }

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new TransientDeliveryException('provider_auth');
        }

        $accessToken = $response->json('access_token');
        $ttl = max(60, (int) $response->json('expires_in', 3600) - 60);

        Cache::put($cacheKey, $accessToken, now()->addSeconds($ttl));

        return $accessToken;
    }

    /**
     * @param  array{client_email: string, private_key: string, token_uri: string}  $credentials
     */
    protected function cacheKey(array $credentials): string
    {
        return 'fcm:access:'.sha1($credentials['client_email'].'|'.(string) ($this->config['project_id'] ?? ''));
    }

    protected function messagesEndpoint(): string
    {
        $projectId = (string) ($this->config['project_id'] ?? '');

        if ($projectId === '') {
            throw new PermanentDeliveryException('push_not_configured');
        }

        return rtrim((string) ($this->config['endpoint'] ?? 'https://fcm.googleapis.com/v1'), '/')
            .'/projects/'.$projectId.'/messages:send';
    }

    protected function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
