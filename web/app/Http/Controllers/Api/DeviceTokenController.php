<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['fcm_token' => ['required', 'string', 'max:500']]);
        DeviceToken::updateOrCreate(
            ['fcm_token' => $data['fcm_token']],
            ['user_id' => $request->user()->id],
        );

        return response()->json(['message' => 'Notification device registered.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['fcm_token' => ['required', 'string', 'max:500']]);
        $request->user()->deviceTokens()->where('fcm_token', $data['fcm_token'])->delete();

        return response()->json(['message' => 'Notification device removed.']);
    }
}
