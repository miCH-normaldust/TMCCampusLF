<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Handover;
use App\Models\LostSighting;
use App\Models\User;
use App\Services\TextMasker;
use App\Services\HandoverExpiry;
use App\Support\PublicMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $conversations = Conversation::with([
            'claimRequest.post:id,item_name,user_id',
            'claimRequest.post.author:id,first_name,last_name,profile_picture_path',
            'claimRequest.claimant:id,first_name,last_name,profile_picture_path',
            'sighting.post:id,item_name,user_id',
            'sighting.post.author:id,first_name,last_name,profile_picture_path',
            'sighting.user:id,first_name,last_name,profile_picture_path',
            'supportUser:id,first_name,last_name',
            'supportSighting:id,post_id',
            'supportSighting.post:id,item_name',
            'userOne:id,first_name,last_name,profile_picture_path',
            'userTwo:id,first_name,last_name,profile_picture_path',
        ])
            ->whereNull('closed_at')
            ->where('status', '!=', 'declined')
            ->where(function ($query) use ($request): void {
                $query->whereHas('claimRequest', function ($claim) use ($request): void {
                    $claim->where('status', 'approved')
                        ->where('author_decision', 'approved')
                        ->where(function ($participants) use ($request): void {
                            $participants->where('claimant_id', $request->user()->id)
                                ->orWhereHas('post', fn ($post) => $post->where('user_id', $request->user()->id));
                        });
                })->orWhereHas('sighting', function ($sighting) use ($request): void {
                    $sighting->where('answer', 'yes')
                        ->where('author_decision', 'approved')
                        ->where('admin_decision', 'approved')
                        ->where(function ($participants) use ($request): void {
                            $participants->where('user_id', $request->user()->id)
                                ->orWhereHas('post', fn ($post) => $post->where('user_id', $request->user()->id));
                        });
                })->orWhere('support_user_id', $request->user()->id)
                ->orWhere(function ($direct) use ($request): void {
                    $direct->whereNotNull('user_one_id')
                        ->where(function ($p) use ($request): void {
                            $p->where('user_one_id', $request->user()->id)
                              ->orWhere('user_two_id', $request->user()->id);
                        });
                });
            })
            ->latest('updated_at')
            ->get()
            ->map(fn (Conversation $conversation): array => $this->formatConversationPayload($conversation, $request->user()));

        return response()->json(['data' => $conversations]);
    }

    public function startDirectChat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id', 'different:user_id'],
        ]);
        $recipientId = (int) $data['recipient_id'];
        $userOneId = min($request->user()->id, $recipientId);
        $userTwoId = max($request->user()->id, $recipientId);

        $conversation = Conversation::with([
            'userOne:id,first_name,last_name,profile_picture_path',
            'userTwo:id,first_name,last_name,profile_picture_path',
        ])
            ->where('user_one_id', $userOneId)
            ->where('user_two_id', $userTwoId)
            ->first();

        if (! $conversation) {
            $conversation = Conversation::create([
                'user_one_id' => $userOneId,
                'user_two_id' => $userTwoId,
                'requested_by' => $request->user()->id,
                'status' => 'pending',
            ]);
            $conversation->loadMissing([
                'userOne:id,first_name,last_name,profile_picture_path',
                'userTwo:id,first_name,last_name,profile_picture_path',
            ]);
        } elseif ($conversation->status === 'declined') {
            $conversation->update([
                'requested_by' => $request->user()->id,
                'status' => 'pending',
                'closed_at' => null,
            ]);
        }

        return response()->json($this->formatConversationPayload($conversation, $request->user()));
    }

    public function acceptRequest(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request->user());
        abort_unless(
            $conversation->user_one_id !== null
                && $conversation->status === 'pending'
                && $conversation->requested_by !== $request->user()->id,
            403,
            'This message request cannot be accepted.',
        );

        $conversation->update(['status' => 'accepted']);
        $conversation->loadMissing([
            'userOne:id,first_name,last_name,profile_picture_path',
            'userTwo:id,first_name,last_name,profile_picture_path',
        ]);

        $requesterId = $conversation->requested_by;
        $requester = User::find($requesterId);
        $requester?->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'message_request_accepted',
            'data' => [
                'title' => 'Message request accepted',
                'body' => $request->user()->first_name.' accepted your message request.',
                'conversation_id' => $conversation->id,
            ],
        ]);

        return response()->json($this->formatConversationPayload($conversation, $request->user()));
    }

    public function declineRequest(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request->user());
        abort_unless(
            $conversation->user_one_id !== null
                && $conversation->status === 'pending'
                && $conversation->requested_by !== $request->user()->id,
            403,
            'This message request cannot be declined.',
        );

        $conversation->update(['status' => 'declined', 'closed_at' => now()]);

        return response()->json(['message' => 'Message request declined.']);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request->user());
        $messages = $conversation->messages()
            ->with('sender:id,first_name,last_name,profile_picture_path,role')
            ->oldest()
            ->paginate(50)
            ->through(fn ($message): array => [
                'id' => $message->id,
                'body' => $message->body,
                'sender_id' => $message->sender_id,
                'sender_first_name' => $message->sender->first_name,
                'sender_last_name' => $message->sender->last_name,
                'sender_role' => $message->sender->role,
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
        abort_if(
            $conversation->closed_at
                || $conversation->status === 'declined'
                || ($conversation->support_user_id === null
                    && $conversation->user_one_id === null
                    && $conversation->created_at->lt(now()->subDays(7))),
            409,
            'This conversation is closed.',
        );

        if ($conversation->user_one_id !== null && $conversation->status === 'pending') {
            abort_unless(
                $conversation->requested_by === $request->user()->id,
                403,
                'You must accept this message request before replying.',
            );
        }

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => $masker->mask($data['body']),
        ]);
        $conversation->touch();
        if ($conversation->user_one_id !== null) {
            $recipientId = $conversation->user_one_id === $request->user()->id
                ? $conversation->user_two_id
                : $conversation->user_one_id;
            $recipient = User::find($recipientId);
            $recipients = $recipient ? collect([$recipient]) : collect();
            $title = $conversation->status === 'pending'
                ? 'New message request'
                : 'New message from '.$request->user()->first_name;
            $topic = $request->user()->first_name;
        } elseif ($conversation->support_user_id !== null) {
            $recipients = User::where('role', 'admin')->where('status', 'active')->get();
            $title = 'New admin support message';
            $topic = $conversation->supportSighting?->post?->item_name ?? 'CampusLF';
        } else {
            $claim = $conversation->claimRequest;
            if ($claim) {
                $recipient = $request->user()->id === $claim->claimant_id
                    ? $claim->post->author
                    : $claim->claimant;
                $recipients = collect([$recipient]);
                $topic = $claim->post->item_name;
            } else {
                $sighting = $conversation->sighting;
                $recipient = $request->user()->id === $sighting->user_id
                    ? $sighting->post->author
                    : $sighting->user;
                $recipients = collect([$recipient]);
                $topic = $sighting->post->item_name;
            }
            $title = 'New CampusLF message';
        }
        foreach ($recipients as $recipient) {
            $recipient->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'new_message',
                'data' => [
                    'title' => $title,
                    'body' => 'You have a new message from '.$request->user()->first_name,
                    'conversation_id' => $conversation->id,
                ],
            ]);
        }

        return response()->json([
            'id' => $message->id,
            'body' => $message->body,
            'sender_id' => $request->user()->id,
            'sender_first_name' => $request->user()->first_name,
            'sender_last_name' => $request->user()->last_name,
            'sender_role' => $request->user()->role,
            'sender_profile_picture_url' => $request->user()->profile_picture_path
                ? PublicMediaUrl::forPath($request->user()->profile_picture_path)
                : null,
            'created_at' => $message->created_at,
        ], 201);
    }

    public function startAdminChat(
        Request $request,
        LostSighting $sighting,
        TextMasker $masker,
    ): JsonResponse {
        $sighting->loadMissing('post');
        abort_unless(
            $sighting->answer === 'yes'
                && $sighting->post->user_id === $request->user()->id,
            404,
        );
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);
        $conversation = Conversation::firstOrCreate([
            'support_user_id' => $request->user()->id,
            'support_sighting_id' => $sighting->id,
        ]);
        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => $masker->mask($data['body']),
        ]);
        $conversation->touch();

        foreach (User::where('role', 'admin')->where('status', 'active')->get() as $admin) {
            $admin->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'admin_chat_message',
                'data' => [
                    'title' => 'Evidence review message',
                    'body' => 'A post owner sent a message about '.$sighting->post->item_name.'.',
                    'conversation_id' => $conversation->id,
                    'sighting_id' => $sighting->id,
                    'post_id' => $sighting->post_id,
                ],
            ]);
        }

        return response()->json([
            'id' => $conversation->id,
            'type' => 'admin',
            'item_name' => 'Admin support · '.$sighting->post->item_name,
            'other_first_name' => 'TMC',
            'other_last_name' => 'administrators',
            'other_user_id' => null,
            'message' => [
                'id' => $message->id,
                'body' => $message->body,
                'sender_id' => $message->sender_id,
                'sender_first_name' => $request->user()->first_name,
                'sender_last_name' => $request->user()->last_name,
                'sender_role' => $request->user()->role,
                'created_at' => $message->created_at,
            ],
        ], 201);
    }

    public function handovers(Request $request, HandoverExpiry $expiry): JsonResponse
    {
        $expiry->expirePastDue();
        $user = $request->user();
        $handovers = Handover::with([
            'claimRequest.post.author:id,first_name,last_name',
            'claimRequest.claimant:id,first_name,last_name',
            'claimRequest.conversation:id,claim_id',
            'sighting.post.author:id,first_name,last_name',
            'sighting.user:id,first_name,last_name',
            'sighting.conversation:id,sighting_id',
            'location:id,name,description,operating_hours',
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
                $otherParticipant = $isOwner ? $finder : $owner;

                return [
                    'id' => $handover->id,
                    'transaction_reference' => 'HL-'.str_pad((string) $handover->id, 8, '0', STR_PAD_LEFT),
                    'type' => $post?->type ?? 'unknown',
                    'item_name' => $post?->item_name ?? 'TMC item',
                    'owner_name' => trim(($owner?->first_name ?? '').' '.($owner?->last_name ?? '')),
                    'other_participant_name' => trim(($otherParticipant?->first_name ?? '').' '.($otherParticipant?->last_name ?? '')),
                    'other_first_name' => $otherParticipant?->first_name ?? 'TMC member',
                    'sighting_id' => $sighting?->id,
                    'conversation_id' => $claim?->conversation?->id
                        ?? $sighting?->conversation?->id,
                    'owner_confirmed_at' => $handover->owner_confirmed_at,
                    'finder_confirmed_at' => $handover->finder_confirmed_at,
                    'my_confirmation_at' => $isOwner
                        ? $handover->owner_confirmed_at
                        : $handover->finder_confirmed_at,
                    'completed' => $handover->owner_confirmed_at !== null
                        && $handover->finder_confirmed_at !== null
                        && $handover->status === 'completed',
                    'status' => $handover->status,
                    'scheduled_at' => $handover->scheduled_at,
                    'location' => $handover->location?->name,
                    'location_description' => $handover->location?->description,
                    'receipt_code' => ($isOwner
                        ? $handover->owner_code_encrypted
                        : $handover->finder_code_encrypted) !== null
                        && $handover->status === 'scheduled'
                        && $handover->owner_confirmed_at !== null
                        && $handover->finder_confirmed_at !== null
                            ? Crypt::decryptString(
                                $isOwner
                                    ? $handover->owner_code_encrypted
                                    : $handover->finder_code_encrypted,
                            )
                            : null,
                    'receipt_code_role' => $isOwner ? 'Owner' : 'Other participant',
                    'reschedule_attempts' => $handover->reschedule_attempts,
                    'reschedule_limit' => (int) DB::table('settings')
                        ->where('key', 'handover_reschedule_attempts')
                        ->value('value') ?: 2,
                    'requested_date' => $handover->requested_date?->toDateString(),
                    'reschedule_reason' => $handover->reschedule_reason,
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
        $handover = Handover::firstOrCreate(
            ['sighting_id' => $sighting->id],
            ['status' => 'awaiting_participants', 'admin_approved_at' => now()],
        );

        return response()->json([
            'id' => $handover->id,
            'owner_confirmed_at' => $handover->owner_confirmed_at,
            'finder_confirmed_at' => $handover->finder_confirmed_at,
            'message' => 'Handover created. Both participants must approve it before administrators can schedule it.',
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
        $handover = Handover::firstOrCreate(
            ['claim_id' => $claim->id],
            ['status' => 'awaiting_participants', 'admin_approved_at' => now()],
        );

        return response()->json([
            'id' => $handover->id,
            'owner_confirmed_at' => $handover->owner_confirmed_at,
            'finder_confirmed_at' => $handover->finder_confirmed_at,
            'message' => 'Handover created. Both participants must approve it before administrators can schedule it.',
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
        abort_unless(
            $this->canReceiveParticipantAgreement($handover),
            409,
            'Both participants can approve the handover before it is scheduled.',
        );
        DB::transaction(function () use ($request, $handover, $claim, $sighting, $conversation): void {
            $locked = Handover::whereKey($handover->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $this->canReceiveParticipantAgreement($locked),
                409,
                'Both participants can approve the handover before it is scheduled.',
            );
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
                $locked->update(['status' => 'awaiting_schedule']);
            } else {
                $locked->update(['status' => 'awaiting_participants']);
            }
        });

        return response()->json([
            'message' => $handover->fresh()->status === 'awaiting_schedule'
                ? 'Both participants agreed. TMC administrators will now schedule the handover.'
                : 'Your agreement was recorded. Waiting for the other participant.',
            'status' => $handover->fresh()->status,
            'retrieved' => false,
        ]);
    }

    private function canReceiveParticipantAgreement(Handover $handover): bool
    {
        return ! in_array(
            $handover->status,
            ['scheduled', 'completed', 'unsuccessful', 'reschedule_requested'],
            true,
        )
            && $handover->scheduled_at === null
            && $handover->completed_at === null
            && $handover->unsuccessful_at === null
            && ! ($handover->owner_confirmed_at && $handover->finder_confirmed_at);
    }

    public function requestHandoverReschedule(
        Request $request,
        Handover $handover,
        TextMasker $masker,
        HandoverExpiry $expiry,
    ): JsonResponse {
        $expiry->expirePastDue();
        $handover->refresh();
        $claim = $handover->claimRequest;
        $sighting = $handover->sighting;
        $ownerId = $claim?->claimant_id ?? $sighting?->post?->user_id;
        $finderId = $claim?->post?->user_id ?? $sighting?->user_id;
        abort_unless(in_array($request->user()->id, [$ownerId, $finderId], true), 404);
        abort_unless($handover->status === 'scheduled', 409, 'Only a scheduled handover can be rescheduled.');

        $data = $request->validate([
            'requested_date' => ['required', 'date', 'after:today'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $limit = (int) DB::table('settings')->where('key', 'handover_reschedule_attempts')->value('value') ?: 2;
        $failed = DB::transaction(function () use ($request, $handover, $data, $limit, $masker, $ownerId, $finderId): bool {
            $locked = Handover::whereKey($handover->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'scheduled', 409, 'This handover is no longer scheduled.');
            $attempts = $locked->reschedule_attempts + 1;
            $exhausted = $attempts >= $limit;
            $locked->update([
                'reschedule_attempts' => $attempts,
                'status' => $exhausted ? 'unsuccessful' : 'reschedule_requested',
                'reschedule_requested_at' => now(),
                'requested_date' => $data['requested_date'],
                'reschedule_reason' => $masker->mask($data['reason']),
                'unsuccessful_at' => $exhausted ? now() : null,
                'failure_reason' => $exhausted
                    ? 'The reschedule attempt limit was reached.'
                    : null,
            ]);
            if ($exhausted) {
                ($locked->claimRequest?->post ?? $locked->sighting?->post)?->update(['status' => 'active']);
            }

            $participants = User::whereIn('id', [$ownerId, $finderId])->get();
            foreach ($participants as $participant) {
                $participant->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => $exhausted ? 'handover_unsuccessful' : 'handover_reschedule_requested',
                    'data' => [
                        'title' => $exhausted ? 'Handover ended' : 'Reschedule request sent',
                        'body' => $exhausted
                            ? 'The handover could not be rescheduled within the allowed attempts. The item is active again.'
                            : 'A reschedule request was sent to TMC administrators.',
                        'handover_id' => $locked->id,
                    ],
                ]);
            }
            foreach (User::where('role', 'admin')->where('status', 'active')->get() as $admin) {
                $admin->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => $exhausted ? 'handover_unsuccessful' : 'handover_reschedule_requested',
                    'data' => [
                        'title' => $exhausted ? 'Handover attempt limit reached' : 'Handover reschedule requested',
                        'body' => $data['reason'],
                        'handover_id' => $locked->id,
                    ],
                ]);
            }

            return $exhausted;
        });

        return response()->json([
            'message' => $failed
                ? 'The reschedule limit was reached. This handover is unsuccessful and the post is active again.'
                : 'Your reschedule request was sent to TMC administrators.',
            'status' => $failed ? 'unsuccessful' : 'reschedule_requested',
        ], 201);
    }

    private function authorizeParticipant(Conversation $conversation, User $user): void
    {
        if ($conversation->user_one_id !== null) {
            abort_unless(
                in_array($user->id, [$conversation->user_one_id, $conversation->user_two_id], true)
                    && $conversation->status !== 'declined',
                404,
            );

            return;
        }

        if ($conversation->support_user_id !== null) {
            abort_unless($conversation->support_user_id === $user->id, 404);

            return;
        }
        $conversation->loadMissing('claimRequest.post');
        $claim = $conversation->claimRequest;
        if (! $claim) {
            $conversation->loadMissing('sighting.post');
            $sighting = $conversation->sighting;
            abort_unless(
                $sighting
                    && $sighting->author_decision === 'approved'
                    && $sighting->admin_decision === 'approved'
                    && in_array($user->id, [$sighting->post->user_id, $sighting->user_id], true),
                404,
            );

            return;
        }
        abort_unless(
            $claim->status === 'approved'
                && $claim->author_decision === 'approved'
                && in_array($user->id, [$claim->claimant_id, $claim->post->user_id], true),
            404,
        );
    }

    private function formatConversationPayload(Conversation $conversation, User $user): array
    {
        $claim = $conversation->claimRequest;
        $sighting = $conversation->sighting;
        $post = $claim?->post ?? $sighting?->post;

        if ($conversation->user_one_id !== null) {
            $otherUser = $conversation->user_one_id === $user->id
                ? $conversation->userTwo
                : $conversation->userOne;
            $itemName = 'Direct Message';
            $conversationType = 'direct';
        } elseif ($conversation->support_user_id !== null) {
            $otherUser = null;
            $itemName = $conversation->supportSighting?->post?->item_name
                ? 'Admin support · '.$conversation->supportSighting->post->item_name
                : 'TMC admin support';
            $conversationType = 'admin';
        } else {
            $isOwner = $claim
                ? $user->id === $post?->user_id
                : $user->id === $post?->user_id;
            $otherUser = $claim
                ? ($isOwner ? $claim->claimant : $post?->author)
                : ($isOwner ? $sighting->user : $post?->author);
            $itemName = $post?->item_name ?? 'TMC item';
            $conversationType = $claim ? 'claim' : 'evidence';
        }

        return [
            'id' => $conversation->id,
            'type' => $conversationType,
            'status' => $conversation->status ?? 'accepted',
            'requested_by' => $conversation->requested_by,
            'is_request' => $conversation->status === 'pending' && $conversation->requested_by !== $user->id,
            'is_pending_sender' => $conversation->status === 'pending' && $conversation->requested_by === $user->id,
            'post_id' => $post?->id ?? $conversation->supportSighting?->post_id,
            'item_name' => $itemName,
            'other_user_id' => $otherUser?->id,
            'other_first_name' => $otherUser?->first_name ?? 'TMC',
            'other_last_name' => $otherUser?->last_name ?? 'administrators',
            'other_profile_picture_url' => $otherUser?->profile_picture_path
                ? PublicMediaUrl::forPath($otherUser->profile_picture_path)
                : null,
            'last_message_at' => $conversation->messages()->latest()->value('created_at'),
        ];
    }
}
