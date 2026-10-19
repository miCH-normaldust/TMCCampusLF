<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FeedbackController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'in:suggestion,bug,experience,other'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
        ]);
        abort_if(
            DB::table('feedback')->where('user_id', $request->user()->id)->whereDate('created_at', today())->count() >= 5,
            429,
            'You have reached the daily feedback limit.',
        );
        $id = DB::transaction(function () use ($request, $data): int {
            $id = DB::table('feedback')->insertGetId([
                'user_id' => $request->user()->id,
                'category' => $data['category'],
                'message' => $data['message'],
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach (User::where('role', 'admin')->where('status', 'active')->get() as $admin) {
                $admin->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'new_feedback',
                    'data' => [
                        'title' => 'New member feedback',
                        'body' => 'A TMC member submitted '.$data['category'].' feedback.',
                        'feedback_id' => $id,
                    ],
                ]);
            }

            return $id;
        });

        return response()->json(['id' => $id, 'message' => 'Thank you. Your feedback was sent to TMC administrators.'], 201);
    }
}
