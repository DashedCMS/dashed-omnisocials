<?php

use Dashed\DashedOmnisocials\Support\PostPayload;

it('vertaalt het posttype naar het Omnisocials-type', function () {
    expect(PostPayload::type('story'))->toBe('story');
    expect(PostPayload::type('reel'))->toBe('reel');
    expect(PostPayload::type('post'))->toBe('post');
    expect(PostPayload::type(null))->toBe('post');
    expect(PostPayload::type('onbekend'))->toBe('post');
});

it('stuurt alle afbeeldingen als slides in volgorde', function () {
    $media = PostPayload::mediaUrls(
        ['https://cdn.test/beeld.jpg', 'https://cdn.test/video.mp4'],
        [],
        ['instagram' => '9:16', 'facebook' => '1:1'],
    );

    expect($media)->toBe(['default' => ['https://cdn.test/beeld.jpg', 'https://cdn.test/video.mp4']]);
});

it('voegt per platform een ratio-afbeelding toe als die afwijkt van de eerste', function () {
    $media = PostPayload::mediaUrls(
        ['https://cdn.test/a.jpg'],
        ['4:5' => 'https://cdn.test/a-45.jpg', '1:1' => 'https://cdn.test/a.jpg'],
        ['instagram' => '4:5', 'facebook' => '1:1'],
    );

    expect($media)->toBe([
        'default' => ['https://cdn.test/a.jpg'],
        'instagram' => ['https://cdn.test/a-45.jpg'],
    ]);
});

it('valt zonder afbeeldingen terug op de 1:1 ratio-afbeelding', function () {
    $media = PostPayload::mediaUrls([], ['1:1' => 'https://cdn.test/vierkant.jpg'], ['facebook' => '1:1']);

    expect($media)->toBe(['default' => ['https://cdn.test/vierkant.jpg']]);
});

it('geeft een lege lijst zonder media', function () {
    expect(PostPayload::mediaUrls([], [], ['instagram' => '4:5']))->toBe([]);
});
