<?php

namespace Dashed\DashedOmnisocials\Client;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Dashed\DashedCore\Models\Customsetting;
use Illuminate\Http\Client\RequestException;
use Dashed\DashedOmnisocials\Exceptions\OmnisocialsApiException;

class OmnisocialsClient
{
    /**
     * Seconden wachten op een schrijfactie. Omnisocials haalt bij het plaatsen
     * de media op; een video duurt daardoor ruim langer dan de 30 seconden van
     * een gewone aanroep. PublishSocialPostJob::$timeout moet hierboven liggen.
     */
    public const PUBLICEREN_TIMEOUT = 150;

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
        return $this->post('/media/upload-from-url', ['url' => $url]);
    }

    public function createPost(array $payload): array
    {
        return $this->post('/posts/create', $payload);
    }

    public function publishPost(array $payload): array
    {
        return $this->post('/posts/create-and-publish', $payload);
    }

    public function retryFailedPlatforms(string $postId): array
    {
        return $this->post("/posts/{$postId}/retry-failed-platforms");
    }

    public function getPostAnalytics(string $postId): array
    {
        return $this->get("/analytics/posts/{$postId}");
    }

    public function getPost(string $postId): array
    {
        return $this->get("/posts/{$postId}");
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
                if ($exception instanceof RequestException) {
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

    /**
     * Een schrijfactie gaat alleen opnieuw als Omnisocials hem zeker niet heeft
     * uitgevoerd (429). Na een time-out of serverfout kan de post er toch staan:
     * nog een keer insturen zette elke videostory dubbel online.
     */
    private function schrijfRequest(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout(self::PUBLICEREN_TIMEOUT)
            ->retry(
                3,
                fn (int $attempt, \Throwable $exception) => $exception instanceof RequestException
                    ? ((int) ($exception->response->header('Retry-After') ?: 5)) * 1000
                    : 0,
                fn (\Throwable $exception) => $exception instanceof RequestException
                    && $exception->response->status() === 429,
                throw: false
            );
    }

    private function get(string $endpoint): array
    {
        return $this->handleResponse($this->request()->get($endpoint));
    }

    private function post(string $endpoint, array $data = []): array
    {
        return $this->handleResponse($this->schrijfRequest()->post($endpoint, $data));
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
