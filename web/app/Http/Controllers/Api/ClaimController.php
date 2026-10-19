<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClaimRequest;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClaimController extends Controller
{
    public function store(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->type === 'found' && in_array($post->status, ['active', 'claim_pending'], true), 409, 'This found item is not accepting claims.');
        abort_if($post->user_id === $request->user()->id, 403, 'You cannot claim your own post.');

        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1', 'max:3'],
            'answers.*' => ['required', 'string', 'max:1000'],
        ]);
        $questions = $post->verificationQuestions()->count();
        abort_if(count($data['answers']) !== $questions, 422, 'Answer every verification question.');

        $claim = DB::transaction(function () use ($request, $post, $data): ClaimRequest {
            $dailyLimit = (int) DB::table('settings')->where('key', 'claims_per_day')->value('value') ?: 5;
            abort_if(ClaimRequest::where('claimant_id', $request->user()->id)->whereDate('created_at', today())->count() >= $dailyLimit, 429, 'Daily claim limit reached.');
            $attempt = ClaimRequest::where('post_id', $post->id)
                ->where('claimant_id', $request->user()->id)
                ->count() + 1;
            $limit = (int) DB::table('settings')->where('key', 'claim_attempts')->value('value') ?: 3;
            abort_if($attempt > $limit, 429, 'The claim attempt limit has been reached.');
            abort_if(ClaimRequest::where('post_id', $post->id)
                ->where('claimant_id', $request->user()->id)
                ->where('status', 'pending')
                ->exists(), 409, 'You already have a claim waiting for review.');

            $claim = ClaimRequest::create([
                'post_id' => $post->id,
                'claimant_id' => $request->user()->id,
                'answers' => array_map(fn (string $answer): string => mb_strtolower(trim($answer)), $data['answers']),
                'attempt_no' => $attempt,
            ]);
            $post->update(['status' => 'claim_pending']);

            return $claim;
        });

        return response()->json([
            'message' => 'Your claim is waiting for administrator review.',
            'claim_id' => $claim->id,
            'attempt_no' => $claim->attempt_no,
        ], 201);
    }
}
