# Omnisocials Webhook naar Polling Migration Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Vervang de inkomende Omnisocials webhook volledig door een scheduled polling command die elke 15 minuten de status van pending social posts opvraagt via `GET /v1/posts/{id}` en de resultaten lokaal opslaat.

**Architecture:** Breid `OmnisocialsClient` uit met een `getPost()` method. Introduceer een nieuwe `SocialPostStatusSyncer` service die de logica uit `ProcessOmnisocialsWebhookJob` overneemt en nu op basis van API-response werkt. Bouw een `SyncSocialPostStatusesCommand` die alle pending posts in batch afwerkt. Schrap de webhook route, middleware, controller en job nadat alle logica is verplaatst.

**Tech Stack:** Laravel 12, PHP 8.4, Filament 4, Omnisocials API v1.

---

## File Structure

**Modify:**
- `packages/dashed/dashed-omnisocials/src/Client/OmnisocialsClient.php` - nieuwe `getPost()` method
- `packages/dashed/dashed-omnisocials/src/Adapters/OmnisocialsPublishAdapter.php` - `getStatus()` refactor naar API-call of verwijderen ten gunste van syncer
- `packages/dashed/dashed-omnisocials/src/DashedOmnisocialsServiceProvider.php` - remove `hasRoutes('webhooks')`, register nieuwe command, schedule entry
- `packages/dashed/dashed-omnisocials/src/Commands/` (naast bestaande) - nieuwe `SyncSocialPostStatusesCommand`
- `packages/dashed/dashed-omnisocials/src/Filament/Pages/OmnisocialsSettingsPage.php` - optioneel: verwijder `omnisocials_webhook_secret` field (alleen als zeker geen ander systeem het gebruikt)

**Create:**
- `packages/dashed/dashed-omnisocials/src/Services/SocialPostStatusSyncer.php` - centrale status-update service
- `packages/dashed/dashed-omnisocials/src/Commands/SyncSocialPostStatusesCommand.php` - scheduled polling command
- `packages/dashed/dashed-omnisocials/database/migrations/2026_04_21_100000_add_last_status_sync_to_social_posts.php` - nieuwe `last_status_sync_at` kolom

**Delete:**
- `packages/dashed/dashed-omnisocials/routes/webhooks.php`
- `packages/dashed/dashed-omnisocials/src/Http/Controllers/HandleOmnisocialsWebhookController.php`
- `packages/dashed/dashed-omnisocials/src/Http/Middleware/VerifyOmnisocialsSignature.php`
- `packages/dashed/dashed-omnisocials/src/Jobs/ProcessOmnisocialsWebhookJob.php`

## Testing Notes

`dashed-omnisocials` heeft geen Pest test setup. Validatie gebeurt via:
1. Local smoke test: `php artisan dashed-omnisocials:sync-post-statuses --post=123 --dry-run` voor een test-post
2. Tinker: `app(SocialPostStatusSyncer::class)->syncPost($post)` met een post die een bekende externalstatus heeft
3. Log-inspectie: command logt naar `storage/logs/laravel.log` met prefix `[omnisocials:sync]`
4. Productie: na deploy, tail `storage/logs/laravel.log` en wacht op het eerste schedule-tick

---

## Task 1: Add `getPost` method to OmnisocialsClient

**Files:**
- Modify: `src/Client/OmnisocialsClient.php`

- [ ] **Step 1: Voeg `getPost` method toe**

Plaats direct na `getPostAnalytics()`:

```php
public function getPost(string $postId): array
{
    return $this->get("/posts/{$postId}");
}
```

- [ ] **Step 2: Syntax check**

```bash
cd packages/dashed/dashed-omnisocials
php -l src/Client/OmnisocialsClient.php
```

- [ ] **Step 3: NIET COMMITTEN**

---

## Task 2: Add `last_status_sync_at` column to social_posts

**Files:**
- Create: `database/migrations/2026_04_21_100000_add_last_status_sync_to_social_posts.php`

- [ ] **Step 1: Schrijf migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashed__social_posts', function (Blueprint $table) {
            $table->timestamp('last_status_sync_at')->nullable()->after('analytics_synced_at');
            $table->index(['status', 'last_status_sync_at']);
        });
    }

    public function down(): void
    {
        Schema::table('dashed__social_posts', function (Blueprint $table) {
            $table->dropIndex(['status', 'last_status_sync_at']);
            $table->dropColumn('last_status_sync_at');
        });
    }
};
```

- [ ] **Step 2: Run migration vanuit monorepo root**

```bash
cd /Users/robinvanmaasakker/Documents/Websites/Laravel/dashed-cms
php artisan migrate
```

Verwacht: "Migrating: 2026_04_21_100000_add_last_status_sync_to_social_posts ... DONE"

- [ ] **Step 3: NIET COMMITTEN**

---

## Task 3: Create SocialPostStatusSyncer service

**Files:**
- Create: `src/Services/SocialPostStatusSyncer.php`

- [ ] **Step 1: Schrijf de service**

```php
<?php

namespace Dashed\DashedOmnisocials\Services;

use Dashed\DashedMarketing\Models\SocialPost;
use Dashed\DashedOmnisocials\Client\OmnisocialsClient;
use Dashed\DashedOmnisocials\Exceptions\OmnisocialsApiException;
use Dashed\DashedOmnisocials\Jobs\RetryFailedPlatformsJob;
use Illuminate\Support\Facades\Log;

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
        $publishedUrls = $data['published_urls'] ?? [];

        $result = match ($externalStatus) {
            'posted' => $this->applyPosted($post, $data, $errors, $publishedUrls),
            'failed' => $this->applyFailed($post, $data),
            'scheduled', 'draft' => 'noop:pending',
            default => $this->applyUnknown($post, $externalStatus, $data),
        };

        $post->update(['last_status_sync_at' => now()]);

        return $result;
    }

    private function applyPosted(SocialPost $post, array $data, array $errors, array $publishedUrls): string
    {
        if (! empty($errors)) {
            $failedPlatforms = array_keys($errors);

            $post->update([
                'status' => 'partially_posted',
                'posted_at' => $post->posted_at ?? now(),
                'post_url' => $post->post_url ?? $this->firstUrl($publishedUrls),
                'failed_platforms' => $failedPlatforms,
                'external_data' => array_merge($post->external_data ?? [], [
                    'last_sync_payload' => $data,
                ]),
            ]);

            RetryFailedPlatformsJob::dispatch($post)->delay(now()->addMinute());

            Log::info("[omnisocials:sync] post #{$post->id} partially posted", ['failed' => $failedPlatforms]);

            return 'updated:partially_posted';
        }

        if ($post->status === 'posted') {
            return 'noop:already-posted';
        }

        $post->update([
            'status' => 'posted',
            'posted_at' => $post->posted_at ?? now(),
            'post_url' => $post->post_url ?? $this->firstUrl($publishedUrls),
            'external_data' => array_merge($post->external_data ?? [], [
                'last_sync_payload' => $data,
            ]),
        ]);

        Log::info("[omnisocials:sync] post #{$post->id} marked posted");

        return 'updated:posted';
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

        if (is_array($first)) {
            return $first['url'] ?? null;
        }

        return is_string($first) ? $first : null;
    }
}
```

- [ ] **Step 2: Syntax check**

```bash
php -l src/Services/SocialPostStatusSyncer.php
```

- [ ] **Step 3: NIET COMMITTEN**

---

## Task 4: Create SyncSocialPostStatusesCommand

**Files:**
- Create: `src/Commands/SyncSocialPostStatusesCommand.php`

- [ ] **Step 1: Schrijf de command**

```php
<?php

namespace Dashed\DashedOmnisocials\Commands;

use Dashed\DashedMarketing\Models\SocialPost;
use Dashed\DashedOmnisocials\Services\SocialPostStatusSyncer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncSocialPostStatusesCommand extends Command
{
    protected $signature = 'dashed-omnisocials:sync-post-statuses
        {--post= : Sync a single post by internal id}
        {--limit=100 : Maximum number of posts to process in this run}';

    protected $description = 'Poll Omnisocials API for status updates on pending social posts';

    public function handle(SocialPostStatusSyncer $syncer): int
    {
        $query = SocialPost::withoutGlobalScopes()
            ->whereNotNull('external_id')
            ->whereIn('status', ['scheduled', 'publishing', 'partially_posted'])
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

        $stats = ['updated:posted' => 0, 'updated:partially_posted' => 0, 'updated:failed' => 0, 'noop:pending' => 0, 'noop:already-posted' => 0, 'noop:already-failed' => 0, 'noop:unknown-status' => 0, 'error:api' => 0, 'skipped:no-external-id' => 0];

        foreach ($posts as $post) {
            try {
                $result = $syncer->syncPost($post);
                $stats[$result] = ($stats[$result] ?? 0) + 1;
                $this->line("  #{$post->id} -> {$result}");
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
```

- [ ] **Step 2: Registreer command in Service Provider**

Open `src/DashedOmnisocialsServiceProvider.php`. Vind de `->hasCommands([...])` call en voeg toe:

```php
SyncSocialPostStatusesCommand::class,
```

Vergeet niet de `use` import bovenaan:

```php
use Dashed\DashedOmnisocials\Commands\SyncSocialPostStatusesCommand;
```

- [ ] **Step 3: Smoke test**

```bash
cd /Users/robinvanmaasakker/Documents/Websites/Laravel/dashed-cms
php artisan dashed-omnisocials:sync-post-statuses --limit=5
```

Verwacht: command runt, geeft stats-regels, geen uncaught exceptions. Als er geen pending posts zijn: "No pending posts to sync." (ook goed).

- [ ] **Step 4: NIET COMMITTEN**

---

## Task 5: Schedule command every 15 minutes

**Files:**
- Modify: `src/DashedOmnisocialsServiceProvider.php`

- [ ] **Step 1: Voeg schedule-entry toe**

In `packageBooted()` (of waar `$schedule->command(...)` al staat voor `refresh-analytics`), voeg toe:

```php
$schedule->command('dashed-omnisocials:sync-post-statuses')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();
```

- [ ] **Step 2: Verifieer via artisan schedule:list**

```bash
php artisan schedule:list
```

Verwacht: regel met `dashed-omnisocials:sync-post-statuses` elke 15 minuten.

- [ ] **Step 3: NIET COMMITTEN**

---

## Task 6: Delete webhook infrastructure

**Files:**
- Delete: `routes/webhooks.php`
- Delete: `src/Http/Controllers/HandleOmnisocialsWebhookController.php`
- Delete: `src/Http/Middleware/VerifyOmnisocialsSignature.php`
- Delete: `src/Jobs/ProcessOmnisocialsWebhookJob.php`
- Modify: `src/DashedOmnisocialsServiceProvider.php` - remove `->hasRoutes('webhooks')`

- [ ] **Step 1: Verwijder route file**

```bash
cd packages/dashed/dashed-omnisocials
rm routes/webhooks.php
rmdir routes 2>/dev/null || true
```

- [ ] **Step 2: Verwijder controller en middleware**

```bash
rm src/Http/Controllers/HandleOmnisocialsWebhookController.php
rm src/Http/Middleware/VerifyOmnisocialsSignature.php
# Check of de Http/ subdirectories leeg zijn en verwijder ze indien zo
rmdir src/Http/Controllers 2>/dev/null || true
rmdir src/Http/Middleware 2>/dev/null || true
rmdir src/Http 2>/dev/null || true
```

- [ ] **Step 3: Verwijder de job**

```bash
rm src/Jobs/ProcessOmnisocialsWebhookJob.php
```

Check of er andere Jobs in `src/Jobs/` staan. Zo niet, directory mag blijven staan want er komen waarschijnlijk nog jobs.

- [ ] **Step 4: Update ServiceProvider**

Verwijder de regel `->hasRoutes('webhooks')` uit de `configurePackage()` method.

- [ ] **Step 5: Verwijder Customsetting key voor webhook secret (optioneel, alleen als gebruiker akkoord)**

De waarde `omnisocials_webhook_secret` blijft in de DB staan maar wordt niet meer gelezen. Als de gebruiker hem wil opruimen:

```php
// In een tinker sessie
\Dashed\DashedCore\Models\Customsetting::where('name', 'omnisocials_webhook_secret')->delete();
```

Stap overslaan zonder expliciete goedkeuring van gebruiker.

- [ ] **Step 6: Zoek naar dangling references**

```bash
cd /Users/robinvanmaasakker/Documents/Websites/Laravel/dashed-cms
grep -r "HandleOmnisocialsWebhookController\|VerifyOmnisocialsSignature\|ProcessOmnisocialsWebhookJob\|omnisocials.webhook" packages/dashed/ --include="*.php"
```

Verwacht: geen hits buiten het dashed-omnisocials package. Als hits wel bestaan, ruim op.

- [ ] **Step 7: Check composer autoloader**

```bash
cd /Users/robinvanmaasakker/Documents/Websites/Laravel/dashed-cms
composer dump-autoload
```

Geen classmap fouten verwacht.

- [ ] **Step 8: Smoke test**

```bash
php artisan route:list | grep omnisocials
```

Verwacht: geen route meer met `omnisocials.webhook`. De POST endpoint is weg.

- [ ] **Step 9: NIET COMMITTEN**

---

## Task 7: Optional OmnisocialsPublishAdapter cleanup

**Files:**
- Modify: `src/Adapters/OmnisocialsPublishAdapter.php`

- [ ] **Step 1: Beoordeel of `getStatus()` nog waarde heeft**

De huidige `getStatus()` leest `$post->external_data['status']`. Aangezien dat veld nu wordt gevuld door `SocialPostStatusSyncer::applyPosted/applyFailed/applyUnknown` blijft het werken. Laat de method staan als die ergens gebruikt wordt; anders verwijder.

```bash
cd /Users/robinvanmaasakker/Documents/Websites/Laravel/dashed-cms
grep -rn "->getStatus(" packages/dashed/ --include="*.php"
```

Als geen hits buiten de adapter zelf: verwijder de method. Anders laten staan.

- [ ] **Step 2: NIET COMMITTEN**

---

## Task 8: Release dashed-omnisocials patch+minor

**Files:**
- Git tag op dashed-omnisocials master

- [ ] **Step 1: Verifieer state**

```bash
cd packages/dashed/dashed-omnisocials
git status
git log --oneline -3
git describe --tags --abbrev=0
```

Laatste tag is v4.0.7 volgens Explore. Nieuwe minor v4.1.0 voor polling feature (breaking change: webhook endpoint verdwijnt).

- [ ] **Step 2: Voorgestelde commit-split (6 commits)**

1. `feat(client): add getPost endpoint`
2. `feat(schema): add last_status_sync_at to social_posts`
3. `feat(sync): introduce SocialPostStatusSyncer service`
4. `feat(command): add sync-post-statuses polling command on every 15 min schedule`
5. `chore(webhook): remove incoming webhook route, controller, middleware, job`
6. `refactor(adapter): simplify getStatus or remove (depending on task 7)`

Vraag expliciete goedkeuring voor push en tag conform userfeedback-memory.

- [ ] **Step 3: Na goedkeuring uitvoeren**

Per commit: `git add <files>` en `git commit -m "..."`.

Daarna:

```bash
git push origin master
git tag v4.1.0
git push origin v4.1.0
cd ../../..
composer update dashed/dashed-omnisocials --no-scripts
```

- [ ] **Step 4: Belangrijk - Omnisocials dashboard**

Herinner de gebruiker dat na deploy:
- De webhook in het Omnisocials dashboard uitgezet kan worden (optioneel - de endpoint is weg dus calls returnen 404, geen schade)
- Op prod moet de Laravel scheduler cron actief zijn voor `php artisan schedule:run` (normaliter elke minuut)
- Voor oudere posts zonder `external_id` blijft status handmatig, het polling systeem raakt die niet aan

---

## Self-review notes

Scope: volledig vervangen van incoming webhook door outgoing polling. Rate-limiting impact: 15 min schedule, limit 100 posts per run = max 400 API calls per uur in pathological case. In praktijk typisch <10 pending posts, dus <1 call/min.

Backwards compat: geen. Dit is een minor version bump met een verwijderd endpoint. Consumer projecten die Omnisocials als webhook consumer hebben geregistreerd moeten handmatig de webhook in het Omnisocials dashboard uitschakelen.

Testing: handmatig. `dashed-omnisocials` heeft geen Pest setup. Smoke test via `--post=` optie op de command voor gerichte single-post sync.

Openstaande vraag: wat te doen met posts die >7 dagen in `scheduled` status blijven (Omnisocials API error of verloren post)? Voor nu: blijven polling doen tot gebruiker handmatig status aanpast. Later eventueel max-age filter toevoegen.
