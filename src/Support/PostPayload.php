<?php

namespace Dashed\DashedOmnisocials\Support;

/**
 * Pure vertaling van een SocialPost naar de velden van de Omnisocials-API,
 * los van Eloquent en Storage zodat hij zonder app te testen is.
 */
class PostPayload
{
    /** Omnisocials kent post (feed), story en reel; alles anders is een feedpost. */
    public static function type(?string $socialPostType): string
    {
        return match ($socialPostType) {
            'story' => 'story',
            'reel' => 'reel',
            default => 'post',
        };
    }

    /**
     * @param  array<int, string>  $absoluteImages  alle media van de post, absolute URL's, in volgorde
     * @param  array<string, string>  $absoluteRatioImages  ratio => absolute URL
     * @param  array<string, string>  $platformRatios  Omnisocials-platform => gewenste ratio
     * @return array<string, array<int, string>>
     */
    public static function mediaUrls(array $absoluteImages, array $absoluteRatioImages, array $platformRatios): array
    {
        $slides = array_values(array_filter($absoluteImages, fn ($url) => is_string($url) && $url !== ''));

        if ($slides === [] && isset($absoluteRatioImages['1:1'])) {
            $slides = [$absoluteRatioImages['1:1']];
        }

        if ($slides === []) {
            return [];
        }

        // Elke afbeelding is een slide (story) of een item in een carrousel (post).
        $media = ['default' => $slides];
        $eerste = $slides[0];

        foreach ($platformRatios as $platform => $ratio) {
            $platformUrl = $absoluteRatioImages[$ratio] ?? null;

            if ($platformUrl && $platformUrl !== $eerste) {
                $media[$platform] = [$platformUrl];
            }
        }

        return $media;
    }
}
