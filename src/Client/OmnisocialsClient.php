<?php

namespace Dashed\DashedOmnisocials\Client;

use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedOmnisocials\Exceptions\OmnisocialsApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OmnisocialsClient
{
    private string $apiKey;

    private string $baseUrl;

    public function __construct(?string $siteId = null)
    {
        $this->apiKey = (string) Customsetting::get('omnisocials_api_key', $siteId);
        $this->baseUrl = config('dashed-omnisocials.base_url', 'https://api.omnisocials.com/v1');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function getAccounts(): array
    {
        return $this->get('/accounts');
    }

    public function uploadMediaFromUrl(string $url): array
    {
        return $this->post('/media/from-url', ['url' => $url]);
    }

    public function createPost(array $payload): array
    {
        return $this->post('/posts', $payload);
    }

    public function publishPost(array $payload): array
    {
        return $this->post('/posts/publish', $payload);
    }

    public function retryFailedPlatforms(string $postId): array
    {
        return $this->post("/posts/{$postId}/retry-failed-platforms");
    }

    public function getPostAnalytics(string $postId): array
    {
        return $this->get("/analytics/posts/{$postId}");
    }

    public function getWebhooks(): array
    {
        return $this->get('/webhooks');
    }

    public function createWebhook(array $payload): array
    {
        return $this->post('/webhooks', $payload);
    }

    public function updateWebhook(string $webhookId, array $payload): array
    {
        return $this->patch("/webhooks/{$webhookId}", $payload);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout(30)
            ->retry(3, function (int $attempt, \Throwable $exception) {
                if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                    $response = $exception->response;

                    if ($response->status() === 429) {
                        $retryAfter = (int) ($response->header('Retry-After') ?: 5);

                        return $retryAfter * 1000;
                    }

                    if ($response->status() >= 500) {
                        return $attempt * 2000;
                    }

                    return false;
                }

                return $attempt * 2000;
            }, throw: false);
    }

    private function get(string $endpoint): array
    {
        return $this->handleResponse($this->request()->get($endpoint));
    }

    private function post(string $endpoint, array $data = []): array
    {
        return $this->handleResponse($this->request()->post($endpoint, $data));
    }

    private function patch(string $endpoint, array $data = []): array
    {
        return $this->handleResponse($this->request()->patch($endpoint, $data));
    }

    private function handleResponse(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        throw OmnisocialsApiException::fromResponse($response);
    }
}
