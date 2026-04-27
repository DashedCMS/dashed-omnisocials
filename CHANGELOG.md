# Changelog

All notable changes to `dashed-omnisocials` will be documented in this file.

## v4.1.2 - 2026-04-27

### Added
- `SocialPostStatusSyncer::applyPosted()` vult bij succes per kanaal een timestamp in de nieuwe `posted_at_per_channel` JSON kolom op `dashed__social_posts` (kanalen die in `errors` zitten worden overgeslagen). Gebruikt door de Resultaat-sectie in dashed-marketing's SocialPostResource om per kanaal de posted-at tijd te tonen. Vereist dashed-marketing v4.16.0+ migratie.
