<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\LostSighting;
use App\Models\Post;
use App\Models\User;
use App\Services\ImageReencoder;
use App\Services\TextMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InteractionController extends Controller
{
    public function comments(Post $post): JsonResponse
    {
        $comments = $post->comments()
            ->with('author:id,first_name')
            ->latest()
            ->paginate(30)
            ->through(fn (Comment $comment): array => [
                'id' => $comment->id,
                'body' => $comment->body,
                'author_first_name' => $comment->author->first_name,
                'created_at' => $comment->created_at,
            ]);

        return response()->json($comments);
    }

    public function comment(Request $request, Post $post, TextMasker $masker): JsonResponse
    {
        abort_unless(in_array($post->status, ['active', 'claim_pending', 'approved_for_meetup'], true), 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $limit = (int) DB::table('settings')->where('key', 'comments_per_day')->value('value') ?: 30;
        abort_if($request->user()->comments()->whereDate('created_at', today())->count() >= $limit, 429, 'Daily comment limit reached.');
        $comment = $post->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $masker->mask($data['body']),
        ]);

        return response()->json([
            'id' => $comment->id,
            'body' => $comment->body,
            'author_first_name' => $request->user()->first_name,
            'created_at' => $comment->created_at,
        ], 201);
    }

    public function deleteComment(Request $request, Comment $comment): JsonResponse
    {
        abort_unless($comment->user_id === $request->user()->id, 403);
        $comment->delete();

        return response()->json(['message' => 'Comment deleted.']);
    }

    public function react(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:like,haha,angry,sad,none']]);
        if ($data['type'] === 'none') {
            $post->reactions()->where('user_id', $request->user()->id)->delete();

            return response()->json(['reaction' => null]);
        }

        $reaction = $post->reactions()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['type' => $data['type']],
        );

        return response()->json(['reaction' => $reaction->type]);
    }

    public function report(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $limit = (int) DB::table('settings')->where('key', 'reports_per_day')->value('value') ?: 5;
        abort_if($request->user()->reportRecords()->whereDate('created_at', today())->count() >= $limit, 429, 'Daily report limit reached.');
        abort_if(
            $request->user()->reportRecords()->where('post_id', $post->id)->exists(),
            409,
            'You have already reported this post.',
        );

        DB::transaction(function () use ($request, $post, $data): void {
            $inserted = DB::table('reports')->insertOrIgnore([
                'post_id' => $post->id,
                'reporter_id' => $request->user()->id,
                'reason' => $data['reason'],
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            abort_if($inserted === 0, 409, 'You have already reported this post.');

            $threshold = (int) DB::table('settings')->where('key', 'report_threshold')->value('value') ?: 3;
            $reports = DB::table('reports')->where('post_id', $post->id)->where('status', 'pending')->count();
            if ($reports >= $threshold && $post->status !== 'hidden') {
                $post->update(['status' => 'hidden', 'hidden_reason' => 'Automatically hidden after reaching the report threshold.']);
                $post->author->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'post_hidden',
                    'data' => [
                        'title' => 'Your post is under review',
                        'body' => 'Your post was hidden after receiving multiple reports and is awaiting TMC administrator review.',
                        'post_id' => $post->id,
                    ],
                ]);
            }
        });

        return response()->json(['message' => 'Report sent for administrator review.'], 201);
    }

    public function sighting(Request $request, Post $post, ImageReencoder $images, TextMasker $masker): JsonResponse
    {
        abort_unless($post->type === 'lost' && $post->status === 'active', 409, 'This lost post is not accepting sightings.');
        abort_unless($post->user_id !== $request->user()->id, 403, 'You cannot submit a sighting for your own post.');
        $data = $request->validate([
            'answer' => ['required', 'in:yes,no'],
            'evidence_text' => ['required_if:answer,yes', 'nullable', 'string', 'max:2000'],
            'evidence_image' => ['nullable', 'required_if:answer,yes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $imagePath = isset($data['evidence_image']) ? $images->store($data['evidence_image']) : null;
        $sighting = $post->sightings()->create([
            'user_id' => $request->user()->id,
            'answer' => $data['answer'],
            'evidence_text' => isset($data['evidence_text']) ? $masker->mask($data['evidence_text']) : null,
            'evidence_image' => $imagePath,
        ]);
        if ($data['answer'] === 'yes') {
            $post->author->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'lost_sighting',
                'data' => ['title' => 'Someone saw your item', 'body' => 'A TMC member sent evidence for your lost item.'],
            ]);
        }

        return response()->json([
            'message' => $data['answer'] === 'yes' ? 'Evidence sent to the post author for review.' : 'Your answer was recorded.',
            'sighting_id' => $sighting->id,
        ], 201);
    }

    public function sightings(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->user_id === $request->user()->id, 403);

        return response()->json([
            'data' => $post->sightings()->with(['user:id,first_name', 'handover:id,sighting_id'])
                ->where('answer', 'yes')
                ->latest()
                ->get()
                ->map(fn (LostSighting $sighting): array => [
                    'id' => $sighting->id,
                    'user_first_name' => $sighting->user->first_name,
                    'evidence_text' => $sighting->evidence_text,
                    'evidence_image' => $sighting->evidence_image ? asset('storage/'.$sighting->evidence_image) : null,
                    'author_decision' => $sighting->author_decision,
                    'admin_decision' => $sighting->admin_decision,
                    'handover_id' => $sighting->handover?->id,
                    'created_at' => $sighting->created_at,
                ]),
        ]);
    }

    public function decideSighting(Request $request, LostSighting $sighting): JsonResponse
    {
        $sighting->load('post');
        abort_unless($sighting->post->user_id === $request->user()->id, 403);
        abort_unless($sighting->answer === 'yes' && $sighting->author_decision === 'pending', 409, 'This evidence has already been reviewed.');
        $data = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $sighting->update(['author_decision' => $data['decision'] === 'approve' ? 'approved' : 'rejected']);
        $admins = User::where('role', 'admin')->where('status', 'active')->get();
        foreach ($admins as $admin) {
            $admin->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'sighting_review',
                'data' => [
                    'title' => 'Lost-item evidence needs review',
                    'body' => $data['decision'] === 'approve'
                        ? 'The post author approved a sighting for '.$sighting->post->item_name.'.'
                        : 'The post author rejected a sighting.',
                    'sighting_id' => $sighting->id,
                ],
            ]);
        }

        return response()->json(['message' => 'Your evidence review was recorded.']);
    }
}
