<?php

use Illuminate\Support\Facades\Route;
use Dashed\DashedOmnisocials\Http\Middleware\VerifyOmnisocialsSignature;
use Dashed\DashedOmnisocials\Http\Controllers\HandleOmnisocialsWebhookController;

Route::post('/dashed/omnisocials/webhook', [HandleOmnisocialsWebhookController::class, 'handle'])
    ->middleware(VerifyOmnisocialsSignature::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('omnisocials.webhook');
