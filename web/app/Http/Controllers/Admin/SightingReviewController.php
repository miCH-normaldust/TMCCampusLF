<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Handover;
use App\Models\LostSighting;
use App\Models\Post;
use App\Models\PostRetrievalRequest;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SightingReviewController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
        ]);
        $applyStatusFilter = static function ($query) use ($filters): void {
            match ($filters['status'] ?? null) {
                'pending' => $query->where('admin_decision', 'pending')
                    ->where('author_decision', '!=', 'rejected'),
                'approved' => $query->where('admin_decision', 'approved'),
                'rejected' => $query->where(fn ($statusQuery) => $statusQuery
                    ->where('author_decision', 'rejected')
                    ->orWhere('admin_decision', 'rejected')),
                default => null,
            };
        };
        $proofPostIds = LostSighting::query()
            ->select('post_id')
            ->where('answer', 'yes')
            ->where($applyStatusFilter)
            ->groupBy('post_id')
            ->orderByRaw('MAX(created_at) DESC')
            ->paginate(15, ['post_id'])
            ->withQueryString();
        $proofPosts = Post::with([
            'author:id,first_name,last_name',
            'sightings' => fn ($query) => $query
                ->where('answer', 'yes')
                ->where($applyStatusFilter)
                ->with('user:id,first_name,last_name')
                ->latest(),
        ])
            ->whereIn('id', $proofPostIds->pluck('post_id'))
            ->get()
            ->keyBy('id');
        $proofPostIds->setCollection($proofPostIds->getCollection()->map(
            fn ($entry) => $proofPosts->get($entry->post_id),
        ));

        return view('admin.sightings', [
            'proofPosts' => $proofPostIds,
            'status' => $filters['status'] ?? '',
            'retrievalRequests' => PostRetrievalRequest::with([
                'post:id,item_name,type,status,user_id',
                'user:id,first_name,last_name,email',
            ])
                ->where('status', 'pending')
                ->whereHas('post', fn ($query) => $query->where('type', 'lost'))
                ->latest()
                ->get(),
        ]);
    }

    public function decide(Request $request, LostSighting $sighting): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        DB::transaction(function () use ($request, $sighting, $data): void {
            $post = Post::whereKey($sighting->post_id)->lockForUpdate()->firstOrFail();
            $sighting = LostSighting::with(['user', 'post.author'])
                ->whereKey($sighting->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(
                $sighting->answer === 'yes'
                    && $sighting->author_decision === 'approved'
                    && $sighting->admin_decision === 'pending',
                409,
                'This evidence has already been reviewed.',
            );

            $post->sightings()
                ->whereNotNull('owner_decision_batch_id')
                ->update([
                    'owner_decision_batch_id' => null,
                    'owner_decision_batch_winner_id' => null,
                ]);

            $approved = $data['decision'] === 'approve';
            if ($approved) {
                abort_if(
                    $post->sightings()
                        ->where('id', '!=', $sighting->id)
                        ->where('admin_decision', 'approved')
                        ->exists(),
                    409,
                    'Another evidence submission for this item has already been approved.',
                );
            }
            $sighting->update([
                'admin_decision' => $approved ? 'approved' : 'rejected',
                'admin_reason' => $data['reason'],
            ]);
            if ($approved) {
                $otherSightings = LostSighting::with('user')
                    ->where('post_id', $post->id)
                    ->where('answer', 'yes')
                    ->where('id', '!=', $sighting->id)
                    ->where('admin_decision', 'pending')
                    ->whereIn('author_decision', ['pending', 'approved'])
                    ->lockForUpdate()
                    ->get();
                foreach ($otherSightings as $otherSighting) {
                    $otherSighting->update([
                        'admin_decision' => 'rejected',
                        'admin_reason' => 'Another evidence submission for this item was approved.',
                    ]);
                    $otherSighting->user->notifications()->create([
                        'id' => (string) Str::uuid(),
                        'type' => 'sighting_owner_reviewed',
                        'data' => [
                            'title' => 'Your evidence was not approved',
                            'body' => 'Another evidence submission for this item was approved.',
                            'sighting_id' => $otherSighting->id,
                            'post_id' => $post->id,
                        ],
                    ]);
                }
            }
            $conversation = null;
            $post->update([
                'status' => $approved ? 'handover_pending' : 'active',
            ]);
            if ($approved) {
                Handover::firstOrCreate(
                    ['sighting_id' => $sighting->id],
                    ['status' => 'awaiting_participants', 'admin_approved_at' => now()],
                );
                $conversation = Conversation::firstOrCreate(['sighting_id' => $sighting->id]);
            }
            foreach ([$post->author, $sighting->user] as $recipient) {
                $recipient->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => $approved ? 'sighting_approved' : 'sighting_rejected',
                    'data' => [
                        'title' => $approved ? 'Sighting approved' : 'Sighting not approved',
                        'body' => $data['reason'],
                        'sighting_id' => $sighting->id,
                        'post_id' => $sighting->post_id,
                        'conversation_id' => $conversation?->id,
                    ],
                ]);
            }
            DB::table('audit_logs')->insert([
                'admin_id' => $request->user('admin')->id,
                'action' => 'sighting_'.$data['decision'],
                'target_type' => LostSighting::class,
                'target_id' => $sighting->id,
                'reason' => $data['reason'],
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'Sighting review saved.');
    }
}
