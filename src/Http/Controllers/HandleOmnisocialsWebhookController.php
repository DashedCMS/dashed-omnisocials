<?php

namespace Dashed\DashedOmnisocials\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedOmnisocials\Jobs\ProcessOmnisocialsWebhookJob;

class HandleOmnisocialsWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        ProcessOmnisocialsWebhookJob::dispatch($request->all());

        return response()->json(['ok' => true]);
    }
}
