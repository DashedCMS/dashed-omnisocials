# Changelog

All notable changes to `dashed-omnisocials` will be documented in this file.

## v4.1.3 - 2026-05-08

### Fixed
- `SocialPostStatusSyncer` herkent nu Omnisocials' echte statusnamen. Voorheen accepteerde de match alleen `'posted'`/`'scheduled'`/`'draft'`/`'failed'`, terwijl Omnisocials `'published'`/`'completed'` gebruikt voor succes en `'pending'`/`'processing'`/`'posting'` als transient state. Daardoor werd de post na succesvolle publicatie nooit op `posted` gezet en bleef de `post_url` leeg. Nu mappen we `published`/`completed`/`posted` → `applyPosted`, en `pending`/`processing`/`posting`/`draft` → `noop:pending` (volgende sync-run probeert opnieuw).
- `applyPosted` valt nu ook terug op het top-level `url`-veld uit de Omnisocials payload als `published_urls` leeg is, zodat single-channel posts hun `post_url` krijgen zodra Omnisocials 'completed' rapporteert.

## v4.1.2 - 2026-04-27

### Added
- `SocialPostStatusSyncer::applyPosted()` vult bij succes per kanaal een timestamp in de nieuwe `posted_at_per_channel` JSON kolom op `dashed__social_posts` (kanalen die in `errors` zitten worden overgeslagen). Gebruikt door de Resultaat-sectie in dashed-marketing's SocialPostResource om per kanaal de posted-at tijd te tonen. Vereist dashed-marketing v4.16.0+ migratie.
