<?php

namespace Dashed\DashedOmnisocials\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Dashed\DashedMarketing\Models\SocialPost;
use Dashed\DashedOmnisocials\Services\SocialPostStatusSyncer;

class SyncSocialPostStatusesCommand extends Command
{
    protected $signature = 'dashed-omnisocials:sync-post-statuses
        {--post= : Sync a single post by internal id}
        {--limit=100 : Maximum number of posts to process in this run}
        {--dump-payload : Print het volledige Omnisocials response-payload (last_sync_payload) per post na sync}';

    protected $description = 'Poll Omnisocials API for status updates on pending social posts';

    public function handle(SocialPostStatusSyncer $syncer): int
    {
        // Selecteer posts die nog moeten worden gepolld:
        // - alles in een transient state (scheduled/publishing/partially_posted)
        // - én alles wat al op 'posted' staat maar nog geen post_url heeft
        //   (Omnisocials levert published_urls/url soms pas een sync-cycle later
        //   aan, vooral bij multi-channel; we polleren door totdat we 'm hebben).
        $query = SocialPost::withoutGlobalScopes()
            ->whereNotNull('external_id')
            ->where(function ($q) {
                $q->whereIn('status', ['scheduled', 'publishing', 'partially_posted'])
                    ->orWhere(function ($qq) {
                        $qq->where('status', 'posted')
                            ->where(function ($qqq) {
                                $qqq->whereNull('post_url')->orWhere('post_url', '');
                            });
                    });
            })
            ->orderByRaw('last_status_sync_at IS NULL DESC')
            ->orderBy('last_status_sync_at');

        if ($postId = $this->option('post')) {
            $query = SocialPost::withoutGlobalScopes()
                ->whereNotNull('external_id')
                ->where('id', $postId);
        }

        $limit = (int) $this->option('limit');
        $posts = $query->limit($limit)->get();

        if ($posts->isEmpty()) {
            $this->info('No pending posts to sync.');

            return self::SUCCESS;
        }

        $this->info("Syncing {$posts->count()} post(s)");

        $stats = ['updated:posted' => 0, 'updated:url' => 0, 'updated:partially_posted' => 0, 'updated:failed' => 0, 'updated:scheduled' => 0, 'noop:pending' => 0, 'noop:already-posted' => 0, 'noop:already-failed' => 0, 'noop:unknown-status' => 0, 'error:api' => 0, 'skipped:no-external-id' => 0];

        $dumpPayload = (bool) $this->option('dump-payload');

        foreach ($posts as $post) {
            try {
                $result = $syncer->syncPost($post);
                $stats[$result] = ($stats[$result] ?? 0) + 1;
                $this->line("  #{$post->id} -> {$result}");

                if ($dumpPayload) {
                    $post->refresh();
                    $payload = $post->external_data['last_sync_payload'] ?? null;
                    $this->line("  payload status=" . ($payload['status'] ?? 'NULL'));
                    $this->line("  payload top-level keys=" . implode(', ', array_keys($payload ?? [])));
                    $this->line("  payload published_urls=" . json_encode($payload['published_urls'] ?? null));
                    $this->line("  payload urls=" . json_encode($payload['urls'] ?? null));
                    $this->line("  payload accounts shape=" . json_encode(array_map(
                        fn ($a) => is_array($a) ? array_keys($a) : gettype($a),
                        array_slice($payload['accounts'] ?? [], 0, 3)
                    )));
                    $this->line("  payload accounts sample=" . substr(json_encode($payload['accounts'] ?? []), 0, 600));
                    $this->line("  post post_url after sync=" . ($post->post_url ?: 'NULL'));
                }
            } catch (\Throwable $e) {
                Log::error("[omnisocials:sync] exception for post #{$post->id}", [
                    'message' => $e->getMessage(),
                ]);
                $this->error("  #{$post->id} -> exception: {$e->getMessage()}");
                $stats['error:api']++;
            }
        }

        $this->newLine();
        foreach ($stats as $key => $count) {
            if ($count > 0) {
                $this->line("  {$key}: {$count}");
            }
        }

        return self::SUCCESS;
    }
}
