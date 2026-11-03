<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Handover;
use App\Models\LostSighting;
use App\Models\User;
use App\Services\TextMasker;
use App\Support\PublicMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $conversations = Conversation::with([
            'claimRequest.post:id,item_name,user_id',
            'claimRequest.post.author:id,first_name,last_name,profile_picture_path',
            'claimRequest.claimant:id,first_name,last_name,profile_picture_path',
        ])
            ->whereNull('closed_at')
            ->whereHas('claimRequest', function ($query) use ($request): void {
                $query->where('status', 'approved')
                    ->where('author_decision', 'approved')
                    ->where(function ($participants) use ($request): void {
                        $participants->where('claimant_id', $request->user()->id)
                            ->orWhereHas('post', fn ($post) => $post->where('user_id', $request->user()->id));
                    });
            })
            ->latest()
            ->get()
            ->map(function (Conversation $conversation) use ($request): array {
                $claim = $conversation->claimRequest;
                $author = $claim->post->author;
                $otherUser = $request->user()->id === $author->id
                    ? $claim->claimant
                    : $author;

                return [
                    'id' => $conversation->id,
                    'post_id' => $claim->post_id,
                    'item_name' => $claim->post->item_name,
                    'other_user_id' => $otherUser->id,
                    'other_first_name' => $otherUser->first_name,
                    'other_last_name' => $otherUser->last_name,
                    'other_profile_picture_url' => $otherUser->profile_picture_path
                        ? PublicMediaUrl::forPath($otherUser->profile_picture_path)
                        : null,
                    'last_message_at' => $conversation->messages()->latest()->value('created_at'),
                ];
            });

        return response()->json(['data' => $conversations]);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request->user());
        $messages = $conversation->messages()
            ->with('sender:id,first_name,last_name,profile_picture_path')
            ->oldest()
            ->paginate(50)
            ->through(fn ($message): array => [
                'id' => $message->id,
                'body' => $message->body,
                'sender_id' => $message->sender_id,
                'sender_first_name' => $message->sender->first_name,
                'sender_last_name' => $message->sender->last_name,
                'sender_profile_picture_url' => $message->sender->profile_picture_path
                    ? PublicMediaUrl::forPath($message->sender->profile_picture_path)
                    : null,
                'created_at' => $message->created_at,
            ]);

        return response()->json($messages);
    }

    public function send(Request $request, Conversation $conversation, TextMasker $masker): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request->user());
        abort_if($conversation->closed_at || $conversation->created_at->lt(now()->subDays(7)), 409, 'This conversation is closed.');
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => $masker->mask($data['body']),
        ]);
        $claim = $conversation->claimRequest;
        $recipient = $request->user()->id === $claim->claimant_id
            ? $claim->post->author
            : $claim->claimant;
        $recipient->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'new_message',
            'data' => ['title' => 'New CampusLF message', 'body' => 'You have a new message about '.$claim->post->item_name],
        ]);

        return response()->json([
            'id' => $message->id,
            'body' => $message->body,
            'sender_id' => $request->user()->id,
            'sender_first_name' => $request->user()->first_name,
            'sender_last_name' => $request->user()->last_name,
            'sender_profile_picture_url' => $request->user()->profile_picture_path
                ? PublicMediaUrl::forPath($request->user()->profile_picture_path)
                : null,
            'created_at' => $message->created_at,
        ], 201);
    }

    public function handovers(Request $request): JsonResponse
    {
        $user = $request->user();
        $handovers = Handover::with([
            'claimRequest.post.author:id,first_name',
            'claimRequest.claimant:id,first_name',
            'claimRequest.conversation:id,claim_id',
            'sighting.post.author:id,first_name',
            'sighting.user:id,first_name',
        ])
            ->where(function ($query) use ($user): void {
                $query->whereHas('claimRequest', function ($claim) use ($user): void {
                    $claim->where('status', 'approved')
                        ->where('author_decision', 'approved')
                        ->where(function ($participants) use ($user): void {
                            $participants->where('claimant_id', $user->id)
                                ->orWhereHas('post', fn ($post) => $post->where('user_id', $user->id));
                        });
                })->orWhereHas('sighting', function ($sighting) use ($user): void {
                    $sighting->where('answer', 'yes')
                        ->where('author_decision', 'approved')
                        ->where('admin_decision', 'approved')
                        ->where(function ($participants) use ($user): void {
                            $participants->where('user_id', $user->id)
                                ->orWhereHas('post', fn ($post) => $post->where('user_id', $user->id));
                        });
                });
            })
            ->latest()
            ->paginate(25)
            ->through(function (Handover $handover) use ($user): array {
                $claim = $handover->claimRequest;
                $sighting = $handover->sighting;
                $post = $claim?->post ?? $sighting?->post;
                $owner = $claim?->claimant ?? $post?->author;
                $finder = $claim?->post?->author ?? $sighting?->user;
                $isOwner = $owner?->id === $user->id;

                return [
                    'id' => $handover->id,
                    'type' => $post?->type ?? 'unknown',
                    'item_name' => $post?->item_name ?? 'TMC item',
                    'other_first_name' => ($isOwner ? $finder : $owner)?->first_name ?? 'TMC member',
                    'sighting_id' => $sighting?->id,
                    'conversation_id' => $claim?->conversation?->id,
                    'owner_confirmed_at' => $handover->owner_confirmed_at,
                    'finder_confirmed_at' => $handover->finder_confirmed_at,
                    'my_confirmation_at' => $isOwner
                        ? $handover->owner_confirmed_at
                        : $handover->finder_confirmed_at,
                    'completed' => $handover->owner_confirmed_at !== null
                        && $handover->finder_confirmed_at !== null,
                ];
            });

        return response()->json($handovers);
    }

    public function startSightingHandover(Request $request, LostSighting $sighting): JsonResponse
    {
        $sighting->loadMissing(['post', 'user']);
        abort_unless(
            $sighting->answer === 'yes'
                && $sighting->author_decision === 'approved'
                && $sighting->admin_decision === 'approved'
                && in_array($request->user()->id, [$sighting->post->user_id, $sighting->user_id], true),
            404,
        );
        abort_unless(in_array($sighting->post->status, ['handover_pending', 'retrieved'], true), 409, 'This lost-item evidence is not approved for a handover.');
        $handover = Handover::firstOrCreate(['sighting_id' => $sighting->id]);

        return response()->json([
            'id' => $handover->id,
            'owner_confirmed_at' => $handover->owner_confirmed_at,
            'finder_confirmed_at' => $handover->finder_confirmed_at,
            'message' => 'Handover is ready. Each participant can confirm the exchange in the app.',
        ], $handover->wasRecentlyCreated ? 201 : 200);
    }

    public function createHandover(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request->user());
        abort_if($conversation->closed_at, 409, 'This conversation is closed.');
        $claim = $conversation->claimRequest;
        abort_unless(
            $claim->status === 'approved' && $claim->author_decision === 'approved',
            409,
            'The administrator and item poster must both approve this claim before handover.',
        );
        $handover = Handover::firstOrCreate(['claim_id' => $claim->id]);

        return response()->json([
            'id' => $handover->id,
            'owner_confirmed_at' => $handover->owner_confirmed_at,
            'finder_confirmed_at' => $handover->finder_confirmed_at,
            'message' => 'Handover is ready. Each participant can confirm the exchange in the app.',
        ], $handover->wasRecentlyCreated ? 201 : 200);
    }

    public function confirmHandover(Request $request, Handover $handover): JsonResponse
    {
        $claim = $handover->claimRequest;
        $sighting = $handover->sighting;
        $conversation = null;
        if ($claim) {
            $conversation = Conversation::where('claim_id', $claim->id)->firstOrFail();
            $this->authorizeParticipant($conversation, $request->user());
            abort_unless($claim->author_decision === 'approved', 409, 'The item poster must agree before handover can proceed.');
        } else {
            abort_unless(
                $sighting
                    && $sighting->answer === 'yes'
                    && $sighting->author_decision === 'approved'
                    && $sighting->admin_decision === 'approved'
                    && in_array($request->user()->id, [$sighting->post->user_id, $sighting->user_id], true),
                404,
            );
        }
        DB::transaction(function () use ($request, $handover, $claim, $sighting, $conversation): void {
            $locked = Handover::whereKey($handover->id)->lockForUpdate()->firstOrFail();
            $ownerId = $claim?->claimant_id ?? $sighting?->post?->user_id;
            if ($request->user()->id === $ownerId) {
                if ($locked->owner_confirmed_at === null) {
                    $locked->update(['owner_confirmed_at' => now()]);
                }
            } else {
                if ($locked->finder_confirmed_at === null) {
                    $locked->update(['finder_confirmed_at' => now()]);
                }
            }
            $locked = $locked->fresh();
            if ($locked->owner_confirmed_at && $locked->finder_confirmed_at) {
                ($claim?->post ?? $sighting?->post)?->update(['status' => 'retrieved']);
                $conversation?->update(['closed_at' => now()]);
            }
        });

        return response()->json([
            'message' => $handover->fresh()->owner_confirmed_at !== null && $handover->fresh()->finder_confirmed_at !== null
                ? 'Both participants confirmed. The item is marked as retrieved.'
                : 'Your confirmation was recorded. Waiting for the other participant.',
            'retrieved' => $conversation
                ? $conversation->fresh()->closed_at !== null
                : $handover->fresh()->owner_confirmed_at !== null && $handover->fresh()->finder_confirmed_at !== null,
        ]);
    }

    private function authorizeParticipant(Conversation $conversation, User $user): void
    {
        $conversation->loadMissing('claimRequest.post');
        $claim = $conversation->claimRequest;
        abort_unless(
            $claim->status === 'approved'
                && $claim->author_decision === 'approved'
                && in_array($user->id, [$claim->claimant_id, $claim->post->user_id], true),
            404,
        );
    }
}
