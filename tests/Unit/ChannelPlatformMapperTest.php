<?php

use Dashed\DashedOmnisocials\Support\ChannelPlatformMapper;

it('maps instagram_feed to instagram', function () {
    expect(ChannelPlatformMapper::toOmnisocials('instagram_feed'))->toBe('instagram');
});

it('maps instagram_reels to instagram', function () {
    expect(ChannelPlatformMapper::toOmnisocials('instagram_reels'))->toBe('instagram');
});

it('maps linkedin_company to linkedin_page', function () {
    expect(ChannelPlatformMapper::toOmnisocials('linkedin_company'))->toBe('linkedin_page');
});

it('marks google_business as unsupported', function () {
    expect(ChannelPlatformMapper::isUnsupported('google_business'))->toBeTrue();
    expect(ChannelPlatformMapper::isSupported('google_business'))->toBeFalse();
});

it('returns null for unknown slugs', function () {
    expect(ChannelPlatformMapper::toOmnisocials('nonexistent'))->toBeNull();
});

it('maps every slug in the static table', function () {
    $all = ChannelPlatformMapper::all();
    expect($all)->toHaveCount(13);

    foreach ($all as $slug => $key) {
        expect(ChannelPlatformMapper::isSupported($slug))->toBeTrue();
        expect(ChannelPlatformMapper::toOmnisocials($slug))->toBe($key);
    }
});

it('returns a ratio for every mapped slug', function () {
    foreach (ChannelPlatformMapper::all() as $slug => $key) {
        expect(ChannelPlatformMapper::defaultRatio($slug))->toBeString();
    }
});
