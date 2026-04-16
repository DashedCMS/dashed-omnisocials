<?php

namespace Dashed\DashedOmnisocials\Support;

class ChannelPlatformMapper
{
    private const MAP = [
        'instagram_feed' => 'instagram',
        'instagram_reels' => 'instagram',
        'instagram_story' => 'instagram',
        'facebook_page' => 'facebook',
        'facebook_group' => 'facebook',
        'linkedin_personal' => 'linkedin',
        'linkedin_company' => 'linkedin_page',
        'tiktok' => 'tiktok',
        'youtube_shorts' => 'youtube',
        'pinterest' => 'pinterest',
        'x' => 'x',
        'threads' => 'threads',
        'bluesky' => 'bluesky',
    ];

    private const UNSUPPORTED = ['google_business'];

    public static function toOmnisocials(string $dashedSlug): ?string
    {
        return self::MAP[$dashedSlug] ?? null;
    }

    public static function isSupported(string $dashedSlug): bool
    {
        return isset(self::MAP[$dashedSlug]);
    }

    public static function isUnsupported(string $dashedSlug): bool
    {
        return in_array($dashedSlug, self::UNSUPPORTED, true);
    }

    public static function all(): array
    {
        return self::MAP;
    }

    public static function defaultRatio(string $dashedSlug): string
    {
        return match ($dashedSlug) {
            'instagram_feed' => '4:5',
            'instagram_reels', 'instagram_story', 'tiktok', 'youtube_shorts' => '9:16',
            'pinterest' => '2:3',
            default => '1:1',
        };
    }
}
