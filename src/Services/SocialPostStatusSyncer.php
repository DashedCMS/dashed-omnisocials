<?php

namespace Dashed\DashedOmnisocials\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Dashed\DashedMarketing\Models\SocialPost;
use Dashed\DashedOmnisocials\Client\OmnisocialsClient;
use Dashed\DashedOmnisocials\Jobs\RetryFailedPlatformsJob;
use Dashed\DashedOmnisocials\Support\ChannelPlatformMapper;
use Dashed\DashedOmnisocials\Exceptions\OmnisocialsApiException;

class SocialPostStatusSyncer
{
    public function syncPost(SocialPost $post): string
    {
        if (! $post->external_id) {
            return 'skipped:no-external-id';
        }

        $client = new OmnisocialsClient($post->site_id);

        try {
            $response = $client->getPost($post->external_id);
        } catch (OmnisocialsApiException $e) {
            Log::warning("[omnisocials:sync] API error for post #{$post->id}", [
                'external_id' => $post->external_id,
                'error' => $e->getMessage(),
            ]);
            $post->update(['last_status_sync_at' => now()]);

            return 'error:api';
        }

        $data = $response['data'] ?? $response;
        $externalStatus = $data['status'] ?? null;
        $errors = $data['errors'] ?? [];
        $publishedUrls = $this->normalizePublishedUrls($data['published_urls'] ?? []);

        // Omnisocials gebruikt 'published'/'completed' voor een succesvolle
        // post; 'posting'/'processing'/'pending' zijn transient states die
        // we behandelen als 'nog wachten' (we polling-en gewoon door bij de
        // volgende run). 'posted' staat hier ook in voor de zekerheid mocht
        // de API in de toekomst die alias gebruiken.
        $result = match ($externalStatus) {
            'published', 'completed', 'posted' => $this->applyPosted($post, $data, $errors, $publishedUrls),
            'failed' => $this->applyFailed($post, $data),
            'scheduled' => $this->applyScheduled($post, $data),
            'draft', 'pending', 'processing', 'posting' => 'noop:pending',
            default => $this->applyUnknown($post, $externalStatus, $data),
        };

        $post->update(['last_status_sync_at' => now()]);

        return $result;
    }

    private function applyPosted(SocialPost $post, array $data, array $errors, array $publishedUrls): string
    {
        // Probeer URLs uit alle bekende plekken te halen waar Omnisocials ze
        // mogelijk levert. Bij verschillende account-types ziet de payload
        // er anders uit; we vallen achtereenvolgens terug op:
        //   1. data.published_urls (already normalized hierboven)
        //   2. data.urls (alternatieve key)
        //   3. data.accounts[].(published_url|post_url|url|permalink)
        //   4. data.url (single-channel top-level)
        // De per-channel mapping (voor `published_urls` veld op de SocialPost)
        // wordt opgebouwd uit alles wat we vinden.
        $publishedUrls = $this->mergePublishedUrls(
            $publishedUrls,
            $this->normalizePublishedUrls($data['urls'] ?? []),
            $this->extractUrlsFromAccounts($data['accounts'] ?? [])
        );

        // Omnisocials levert URLs terug onder platform-keys (facebook,
        // instagram, ...) terwijl SocialPost.channels channel-type slugs
        // gebruikt (facebook_page, facebook_group, instagram_feed, ...).
        // Spiegel iedere platform-URL ook onder de matchende channel-slugs
        // zodat de admin-form (die op channel-slug bindt) de URL correct
        // pre-vult, en eventuele tools die nog op platform-niveau lezen
        // blijven werken.
        $publishedUrls = $this->expandUrlsToChannelSlugs($publishedUrls, $post);

        $resolvedUrl = $post->post_url
            ?? $this->firstUrl($publishedUrls)
            ?? (is_string($data['url'] ?? null) && $data['url'] !== '' ? $data['url'] : null);

        // Als we hier zijn (status=published/completed) maar geen URL hebben
        // gevonden in een herkende shape, log de top-level keys + accounts
        // shape zodat we kunnen zien waar Omnisocials de URL nu plaatst.
        if (! $resolvedUrl) {
            Log::info("[omnisocials:sync] post #{$post->id} posted maar geen URL gevonden", [
                'top_level_keys' => array_keys($data),
                'accounts_sample' => $this->sampleAccountsShape($data['accounts'] ?? []),
                'published_urls_raw' => $data['published_urls'] ?? null,
                'urls_raw' => $data['urls'] ?? null,
            ]);
        }

        if (! empty($errors)) {
            $failedPlatforms = array_keys($errors);

            $update = [
                'status' => 'partially_posted',
                'posted_at' => $post->posted_at ?? now(),
                'posted_at_per_channel' => $this->buildPostedAtPerChannel($post, $failedPlatforms),
                'post_url' => $resolvedUrl,
                'failed_platforms' => $failedPlatforms,
                'external_data' => array_merge($post->external_data ?? [], [
                    'last_sync_payload' => $data,
                ]),
            ];

            if (! empty($publishedUrls)) {
                $update['published_urls'] = $publishedUrls;
            }

            $post->update($update);

            RetryFailedPlatformsJob::dispatch($post)->delay(now()->addMinute());

            Log::info("[omnisocials:sync] post #{$post->id} partially posted", ['failed' => $failedPlatforms]);

            return 'updated:partially_posted';
        }

        // Already-posted maar nog ontbrekende URLs: vul aan zodra Omnisocials
        // ze heeft. Werkt zowel voor het algemene post_url als voor nieuwe
        // channel-slugs die nog niet in published_urls stonden. posted_at /
        // posted_at_per_channel laten we onaangeroerd zodat de oorspronkelijke
        // tijdstempels behouden blijven.
        if ($post->status === 'posted') {
            $existingUrls = is_array($post->published_urls) ? $post->published_urls : [];
            $mergedUrls = $this->mergePublishedUrls($existingUrls, $publishedUrls);
            $newKeys = array_diff(array_keys($mergedUrls), array_keys($existingUrls));
            $needsUrlUpdate = ! $post->post_url && $resolvedUrl;

            if (! empty($newKeys) || $needsUrlUpdate) {
                $update = [
                    'external_data' => array_merge($post->external_data ?? [], [
                        'last_sync_payload' => $data,
                    ]),
                ];

                if (! empty($newKeys)) {
                    $update['published_urls'] = $mergedUrls;
                }

                if ($needsUrlUpdate) {
                    $update['post_url'] = $resolvedUrl;
                }

                $post->update($update);

                Log::info("[omnisocials:sync] post #{$post->id} backfilled URLs", [
                    'new_keys' => array_values($newKeys),
                    'post_url_set' => $needsUrlUpdate,
                ]);

                return 'updated:url';
            }

            return 'noop:already-posted';
        }

        $update = [
            'status' => 'posted',
            'posted_at' => $post->posted_at ?? now(),
            'posted_at_per_channel' => $this->buildPostedAtPerChannel($post, []),
            'post_url' => $resolvedUrl,
            'external_data' => array_merge($post->external_data ?? [], [
                'last_sync_payload' => $data,
            ]),
        ];

        if (! empty($publishedUrls)) {
            $update['published_urls'] = $publishedUrls;
        }

        $post->update($update);

        Log::info("[omnisocials:sync] post #{$post->id} marked posted");

        return 'updated:posted';
    }

    private function buildPostedAtPerChannel(SocialPost $post, array $failedPlatforms): array
    {
        $existing = is_array($post->posted_at_per_channel) ? $post->posted_at_per_channel : [];
        $channels = is_array($post->channels) ? $post->channels : [];
        $now = now()->toIso8601String();

        foreach ($channels as $slug) {
            if (in_array($slug, $failedPlatforms, true)) {
                continue;
            }
            if (empty($existing[$slug])) {
                $existing[$slug] = $now;
            }
        }

        return $existing;
    }

    private function applyScheduled(SocialPost $post, array $data): string
    {
        $scheduleAt = $data['schedule_at'] ?? $data['scheduled_at'] ?? null;
        $update = [
            'status' => 'scheduled',
            'external_data' => array_merge($post->external_data ?? [], [
                'last_sync_payload' => $data,
            ]),
        ];

        if ($scheduleAt) {
            try {
                $update['scheduled_at'] = Carbon::parse($scheduleAt);
            } catch (\Throwable $e) {
                Log::warning("[omnisocials:sync] post #{$post->id} invalid schedule_at", ['value' => $scheduleAt]);
            }
        }

        $post->update($update);

        return 'updated:scheduled';
    }

    private function applyFailed(SocialPost $post, array $data): string
    {
        if ($post->status === 'publish_failed') {
            return 'noop:already-failed';
        }

        $post->update([
            'status' => 'publish_failed',
            'external_data' => array_merge($post->external_data ?? [], [
                'last_sync_payload' => $data,
            ]),
        ]);

        Log::warning("[omnisocials:sync] post #{$post->id} marked failed", ['errors' => $data['errors'] ?? null]);

        return 'updated:failed';
    }

    private function applyUnknown(SocialPost $post, ?string $externalStatus, array $data): string
    {
        Log::info("[omnisocials:sync] post #{$post->id} unknown external status", [
            'external_status' => $externalStatus,
        ]);

        $post->update([
            'external_data' => array_merge($post->external_data ?? [], [
                'last_sync_payload' => $data,
            ]),
        ]);

        return 'noop:unknown-status';
    }

    private function firstUrl(array $publishedUrls): ?string
    {
        if (empty($publishedUrls)) {
            return null;
        }

        $first = reset($publishedUrls);

        return is_string($first) ? $first : null;
    }

    /**
     * Spiegelt platform-keys (facebook, instagram, ...) naar de channel-slugs
     * die op SocialPost.channels staan (facebook_page, facebook_group,
     * instagram_feed, ...). De originele platform-keys blijven bestaan zodat
     * tools die op platform-niveau lezen niet breken.
     *
     * @param  array<string, string>  $urls
     * @return array<string, string>
     */
    private function expandUrlsToChannelSlugs(array $urls, SocialPost $post): array
    {
        $channels = is_array($post->channels) ? $post->channels : [];
        if (empty($channels) || empty($urls)) {
            return $urls;
        }

        foreach ($channels as $slug) {
            if (! is_string($slug) || $slug === '' || isset($urls[$slug])) {
                continue;
            }

            $platform = ChannelPlatformMapper::toOmnisocials($slug);
            if ($platform && isset($urls[$platform]) && is_string($urls[$platform])) {
                $urls[$slug] = $urls[$platform];
            }
        }

        return $urls;
    }

    /**
     * Voegt meerdere URL-bronnen samen tot één per-channel dict. Latere
     * bronnen overschrijven eerdere alleen als de eerdere geen URL had.
     *
     * @param  array<string, string>  ...$sources
     * @return array<string, string>
     */
    private function mergePublishedUrls(array ...$sources): array
    {
        $merged = [];
        foreach ($sources as $source) {
            foreach ($source as $key => $url) {
                if (! isset($merged[$key]) || $merged[$key] === '') {
                    $merged[$key] = $url;
                }
            }
        }

        return $merged;
    }

    /**
     * Pakt URLs uit een Omnisocials `accounts` array. Per account kijken we
     * naar veldnamen die we in vergelijkbare APIs zijn tegengekomen:
     * `published_url`, `post_url`, `permalink`, `url`. De key in de
     * resulterende dict is bij voorkeur het platform (instagram, facebook),
     * met een fallback op het account-id zodat we nooit een collision krijgen.
     *
     * @param  mixed  $accounts
     * @return array<string, string>
     */
    private function extractUrlsFromAccounts(mixed $accounts): array
    {
        if (! is_array($accounts) || empty($accounts)) {
            return [];
        }

        $urls = [];
        foreach ($accounts as $platformKey => $account) {
            // Een account kan op verschillende manieren zijn opgemaakt:
            //   array van objecten:  [{ "platform": "facebook", "url": "..." }, ...]
            //   dict per platform:    { "facebook": { "url": "..." }, ... }
            //   dict met booleans:    { "facebook": true, ... }   (geen URL hier)
            //   string url direct:    { "facebook": "https://..." }
            if (is_string($account) && str_starts_with($account, 'http')) {
                $urls[(string) $platformKey] = $account;

                continue;
            }

            if (! is_array($account)) {
                continue;
            }

            $url = null;
            foreach (['published_url', 'post_url', 'permalink', 'url', 'link'] as $field) {
                if (isset($account[$field]) && is_string($account[$field]) && $account[$field] !== '') {
                    $url = $account[$field];

                    break;
                }
            }

            if (! $url) {
                continue;
            }

            $key = (string) ($account['platform'] ?? $account['channel'] ?? $platformKey);
            if ($key === '' || isset($urls[$key])) {
                $key = (string) ($account['id'] ?? count($urls));
            }

            $urls[$key] = $url;
        }

        return $urls;
    }

    /**
     * Bouwt een korte representatie van de accounts-array voor debug-logging
     * zonder de hele payload (kan groot zijn). Per account: platform/id +
     * de top-level keys zodat we direct zien welke veldnamen aanwezig zijn.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sampleAccountsShape(mixed $accounts): array
    {
        if (! is_array($accounts) || empty($accounts)) {
            return [];
        }

        $sample = [];
        foreach (array_slice($accounts, 0, 3) as $account) {
            if (! is_array($account)) {
                $sample[] = ['type' => gettype($account)];

                continue;
            }
            $sample[] = [
                'platform' => $account['platform'] ?? null,
                'id' => $account['id'] ?? null,
                'keys' => array_keys($account),
            ];
        }

        return $sample;
    }

    private function normalizePublishedUrls(mixed $raw): array
    {
        if (! is_array($raw) || empty($raw)) {
            return [];
        }

        $normalized = [];

        foreach ($raw as $key => $value) {
            $url = match (true) {
                is_string($value) => $value,
                is_array($value) && isset($value['url']) => (string) $value['url'],
                is_array($value) && isset($value[0]) && is_array($value[0]) && isset($value[0]['url']) => (string) $value[0]['url'],
                default => null,
            };

            if ($url !== null && $url !== '') {
                $normalized[(string) $key] = $url;
            }
        }

        return $normalized;
    }
}
