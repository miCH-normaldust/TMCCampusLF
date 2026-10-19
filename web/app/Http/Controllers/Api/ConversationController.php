<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Handover;
use App\Models\LostSighting;
use App\Models\MeetupLocation;
use App\Models\User;
use App\Services\TextMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $conversations = Conversation::with([
            'claimRequest.post:id,item_name,user_id',
            'claimRequest.post.author:id,first_name',
            'claimRequest.claimant:id,first_name',
        ])
            ->whereNull('closed_at')
            ->whereHas('claimRequest', function ($query) use ($request): void {
                $query->where('status', 'approved')->where(function ($participants) use ($request): void {
                    $participants->where('claimant_id', $request->user()->id)
                        ->orWhereHas('post', fn ($post) => $post->where('user_id', $request->user()->id));
                });
            })
            ->latest()
            ->get()
            ->map(function (Conversation $conversation) use ($request): array {
                $claim = $conversation->claimRequest;
                $author = $claim->post->author;

                return [
                    'id' => $conversation->id,
                    'post_id' => $claim->post_id,
                    'item_name' => $claim->post->item_name,
                    'other_first_name' => $request->user()->id === $author->id
                        ? $claim->claimant->first_name
                        : $author->first_name,
                    'location_id' => $claim->location_id,
                    'last_message_at' => $conversation->messages()->latest()->value('created_at'),
                ];
            });

        return response()->json(['data' => $conversations]);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request->user());
        $messages = $conversation->messages()
            ->with('sender:id,first_name')
            ->oldest()
            ->paginate(50)
            ->through(fn ($message): array => [
                'id' => $message->id,
                'body' => $message->body,
                'sender_id' => $message->sender_id,
                'sender_first_name' => $message->sender->first_name,
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
            'created_at' => $message->created_at,
        ], 201);
    }

    public function locations(): JsonResponse
    {
        return response()->json([
            'data' => MeetupLocation::where('is_active', true)->orderBy('name')->get([
                'id', 'name', 'description', 'operating_hours',
            ]),
        ]);
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
            'location:id,name',
        ])
            ->where(function ($query) use ($user): void {
                $query->whereHas('claimRequest', function ($claim) use ($user): void {
                    $claim->where('status', 'approved')
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
                    'location' => $handover->location?->name,
                    'expires_at' => $handover->expires_at,
                    'owner_confirmed_at' => $handover->owner_confirmed_at,
                    'finder_confirmed_at' => $handover->finder_confirmed_at,
                    'verified' => $handover->verified_by !== null,
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
        abort_unless(in_array($sighting->post->status, ['approved_for_meetup', 'retrieved'], true), 409, 'This lost-item sighting is not approved for a handover.');

        $handover = $sighting->handover;
        abort_if($handover?->verified_by !== null, 409, 'This handover has already been verified.');
        if ($handover && $handover->expires_at->isFuture()) {
            return response()->json([
                'id' => $handover->id,
                'code' => $handover->code_encrypted,
                'expires_at' => $handover->expires_at,
                'location_id' => $handover->location_id,
                'message' => 'Show this code to the other party at your selected TMC meet-up location.',
            ]);
        }

        $data = $request->validate([
            'location_id' => ['nullable', 'exists:meetup_locations,id'],
        ]);
        $locationId = $data['location_id'] ?? $handover?->location_id;
        abort_unless($locationId, 422, 'Choose an active TMC meet-up location first.');
        $location = MeetupLocation::whereKey($locationId)->where('is_active', true)->firstOrFail();
        $code = (string) random_int(10000000, 99999999);
        $validMinutes = (int) DB::table('settings')->where('key', 'handover_code_minutes')->value('value') ?: 30;
        $lookup = hash_hmac('sha256', $code, (string) config('app.key'));
        $handover = Handover::updateOrCreate(
            ['sighting_id' => $sighting->id],
            [
                'claim_id' => null,
                'code_hash' => Hash::make($code),
                'code_encrypted' => $code,
                'code_lookup' => $lookup,
                'expires_at' => now()->addMinutes($validMinutes),
                'owner_confirmed_at' => null,
                'finder_confirmed_at' => null,
                'verified_by' => null,
                'location_id' => $location->id,
            ],
        );

        return response()->json([
            'id' => $handover->id,
            'code' => $code,
            'expires_at' => $handover->expires_at,
            'location_id' => $handover->location_id,
            'message' => 'Show this code to the other party at your selected TMC meet-up location.',
        ], 201);
    }

    public function chooseLocation(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request->user());
        $data = $request->validate(['location_id' => ['required', 'exists:meetup_locations,id']]);
        $location = MeetupLocation::whereKey($data['location_id'])->where('is_active', true)->firstOrFail();
        $conversation->claimRequest->update(['location_id' => $location->id]);

        return response()->json(['message' => 'Meet-up location saved.', 'location' => $location]);
    }

    public function createHandover(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request->user());
        abort_if($conversation->closed_at, 409, 'This conversation is closed.');
        $claim = $conversation->claimRequest;
        abort_unless($claim->location_id && MeetupLocation::whereKey($claim->location_id)->where('is_active', true)->exists(), 422, 'Choose an active TMC meet-up location first.');

        $handover = Handover::where('claim_id', $claim->id)->first();
        if ($handover && $handover->expires_at->isFuture()) {
            return response()->json([
                'id' => $handover->id,
                'code' => $handover->code_encrypted,
                'expires_at' => $handover->expires_at,
                'location_id' => $handover->location_id,
            ]);
        }

        $code = (string) random_int(10000000, 99999999);
        $validMinutes = (int) DB::table('settings')->where('key', 'handover_code_minutes')->value('value') ?: 30;
        $lookup = hash_hmac('sha256', $code, (string) config('app.key'));
        $handover = Handover::updateOrCreate(
            ['claim_id' => $claim->id],
            [
                'code_hash' => Hash::make($code),
                'code_encrypted' => $code,
                'code_lookup' => $lookup,
                'expires_at' => now()->addMinutes($validMinutes),
                'owner_confirmed_at' => null,
                'finder_confirmed_at' => null,
                'verified_by' => null,
                'location_id' => $claim->location_id,
            ],
        );

        return response()->json([
            'id' => $handover->id,
            'code' => $code,
            'expires_at' => $handover->expires_at,
            'location_id' => $handover->location_id,
            'message' => 'Show this code to the other party at your selected TMC meet-up location.',
        ], 201);
    }

    public function confirmHandover(Request $request, Handover $handover): JsonResponse
    {
        $claim = $handover->claimRequest;
        $sighting = $handover->sighting;
        $conversation = null;
        if ($claim) {
            $conversation = Conversation::where('claim_id', $claim->id)->firstOrFail();
            $this->authorizeParticipant($conversation, $request->user());
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
        $data = $request->validate(['code' => ['required', 'digits:8']]);
        abort_if($handover->expires_at->isPast(), 409, 'This handover code has expired.');
        abort_unless(Hash::check($data['code'], $handover->code_hash), 422, 'The handover code is incorrect.');

        DB::transaction(function () use ($request, $handover, $claim, $sighting, $conversation): void {
            $locked = Handover::whereKey($handover->id)->lockForUpdate()->firstOrFail();
            $ownerId = $claim?->claimant_id ?? $sighting?->post?->user_id;
            if ($request->user()->id === $ownerId) {
                $locked->update(['owner_confirmed_at' => now()]);
            } else {
                $locked->update(['finder_confirmed_at' => now()]);
            }
            $locked = $locked->fresh();
            if ($locked->owner_confirmed_at && $locked->finder_confirmed_at) {
                ($claim?->post ?? $sighting?->post)?->update(['status' => 'retrieved']);
                $conversation?->update(['closed_at' => now()]);
            }
        });

        return response()->json([
            'message' => 'Your confirmation was recorded.',
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
                && in_array($user->id, [$claim->claimant_id, $claim->post->user_id], true),
            404,
        );
    }
}
