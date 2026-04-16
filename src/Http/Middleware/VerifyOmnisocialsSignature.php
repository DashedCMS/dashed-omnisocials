<?php

namespace Dashed\DashedOmnisocials\Http\Middleware;

use Closure;
use Dashed\DashedCore\Models\Customsetting;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyOmnisocialsSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Omnisocials-Signature');
        if (! $signature) {
            return response()->json(['error' => 'Missing signature'], 401);
        }

        $secret = Customsetting::get('omnisocials_webhook_secret');
        if (! $secret) {
            return response()->json(['error' => 'Webhook secret not configured'], 401);
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);
        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        return $next($request);
    }
}
