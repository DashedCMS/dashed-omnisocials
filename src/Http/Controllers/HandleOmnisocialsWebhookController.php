<?php

namespace Dashed\DashedOmnisocials\Http\Controllers;

use Dashed\DashedOmnisocials\Jobs\ProcessOmnisocialsWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class HandleOmnisocialsWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        ProcessOmnisocialsWebhookJob::dispatch($request->all());

        return response()->json(['ok' => true]);
    }
}
