<?php

namespace App\Modules\Mining\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Mining\MiningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Hardware agents report worker statistics with their device token (Bearer mdt_...). */
class MiningAgentController extends Controller
{
    public function stats(Request $request, MiningService $mining): JsonResponse
    {
        $device = $mining->deviceForToken((string) $request->bearerToken());
        abort_if($device === null, 401, 'Invalid agent token.');

        $data = $request->validate([
            'workers' => ['required', 'array', 'min:1', 'max:256'],
            'workers.*.name' => ['required', 'string', 'max:100'],
            'workers.*.hashrate' => ['required', 'numeric', 'min:0'],
            'workers.*.temperature' => ['nullable', 'numeric', 'between:-50,200'],
            'workers.*.accepted' => ['nullable', 'integer', 'min:0'],
            'workers.*.rejected' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(['stored' => $mining->ingestStats($device, $data['workers'])]);
    }
}
