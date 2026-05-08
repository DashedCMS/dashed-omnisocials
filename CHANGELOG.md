# Changelog

All notable changes to `dashed-omnisocials` will be documented in this file.

## v4.1.7 - 2026-05-08

### Changed
- `SyncSocialPostStatusesCommand` query selecteert nu álle posts met `status='posted'` (mits external_id), en filtert daarna in PHP de posts weg waarvan iedere channel-slug uit `channels` al een URL in `published_urls` heeft. Voorheen werden alleen posts met een leeg algemeen `post_url`-veld opgepikt, waardoor multi-channel posts waarvan bv. de Facebook-URL al binnen was maar de Instagram-URL nog niet, niet meer werden gepolld.
- `SocialPostStatusSyncer::applyPosted()` voor reeds-posted posts: merged nu nieuwe URLs uit Omnisocials in `published_urls` (zonder bestaande te overschrijven), en zet `post_url` zodra die nog leeg was. Loggen we welke nieuwe channel-keys zijn aangevuld.

## v4.1.6 - 2026-05-08

### Changed
- `SocialPostStatusSyncer::applyPosted()` schrijft `published_urls` nu zowel onder Omnisocials' platform-keys (`facebook`, `instagram`, ...) als onder de bijbehorende channel-slugs uit `SocialPost.channels` (`facebook_page`, `instagram_feed`, `facebook_group`, ...). Voorheen kon het edit-form van `dashed-marketing` v4.19.0+ — dat bindt op `published_urls.{channel_slug}` — de URL niet ophalen omdat de keys niet matchten. Resolutie via `ChannelPlatformMapper`.

## v4.1.5 - 2026-05-08

### Changed
- Defensieve URL-extractie in `SocialPostStatusSyncer::applyPosted()`. Naast `data.published_urls` worden nu ook `data.urls` en URLs in `data.accounts` (zowel array-of-objects, dict-per-platform, als directe URL-strings per platform) meegenomen. Single-channel `data.url` blijft de laatste fallback.
- Wanneer een post `published`/`completed` is maar geen URL kan worden gevonden in een herkende vorm, logt de syncer nu de top-level keys, accounts shape en raw `published_urls`/`urls` zodat we direct kunnen zien waar Omnisocials de URL plaatst zonder de hele payload terug te halen.

### Added
- `--dump-payload` flag op `php artisan dashed-omnisocials:sync-post-statuses` print per gesynced post de status, top-level keys, `published_urls`, `urls`, een sample van de `accounts`-structuur en de uiteindelijke `post_url`. Bedoeld voor debugging op productie.

## v4.1.4 - 2026-05-08

### Changed
- Sync-command pickt nu ook posts op die al `posted` zijn maar nog geen `post_url` hebben. Voorheen was de query strikt `whereIn('status', ['scheduled', 'publishing', 'partially_posted'])` waardoor een post die snel op `posted` belandde maar zonder URL, nooit meer werd gepolld.
- `SocialPostStatusSyncer::applyPosted()` heeft een nieuwe code-path: voor reeds-`posted` posts zonder `post_url` schrijft de syncer alleen de URL (en `published_urls` als beschikbaar) bij — `posted_at` en `posted_at_per_channel` blijven onaangeroerd. Nieuwe stat-key `updated:url` in CLI-output van het sync-command.

## v4.1.3 - 2026-05-08

### Fixed
- `SocialPostStatusSyncer` herkent nu Omnisocials' echte statusnamen. Voorheen accepteerde de match alleen `'posted'`/`'scheduled'`/`'draft'`/`'failed'`, terwijl Omnisocials `'published'`/`'completed'` gebruikt voor succes en `'pending'`/`'processing'`/`'posting'` als transient state. Daardoor werd de post na succesvolle publicatie nooit op `posted` gezet en bleef de `post_url` leeg. Nu mappen we `published`/`completed`/`posted` → `applyPosted`, en `pending`/`processing`/`posting`/`draft` → `noop:pending` (volgende sync-run probeert opnieuw).
- `applyPosted` valt nu ook terug op het top-level `url`-veld uit de Omnisocials payload als `published_urls` leeg is, zodat single-channel posts hun `post_url` krijgen zodra Omnisocials 'completed' rapporteert.

## v4.1.2 - 2026-04-27

### Added
- `SocialPostStatusSyncer::applyPosted()` vult bij succes per kanaal een timestamp in de nieuwe `posted_at_per_channel` JSON kolom op `dashed__social_posts` (kanalen die in `errors` zitten worden overgeslagen). Gebruikt door de Resultaat-sectie in dashed-marketing's SocialPostResource om per kanaal de posted-at tijd te tonen. Vereist dashed-marketing v4.16.0+ migratie.
