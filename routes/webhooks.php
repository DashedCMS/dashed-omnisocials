<?php

use Dashed\DashedOmnisocials\Http\Controllers\HandleOmnisocialsWebhookController;
use Dashed\DashedOmnisocials\Http\Middleware\VerifyOmnisocialsSignature;
use Illuminate\Support\Facades\Route;

Route::post('/dashed/omnisocials/webhook', [HandleOmnisocialsWebhookController::class, 'handle'])
    ->middleware(VerifyOmnisocialsSignature::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('omnisocials.webhook');
