<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserModerationRestriction;

class UserRestrictionService
{
    public function assertCanPost(User $user, string $type): void
    {
        $this->assertAllowed($user, 'posting_'.$type, 'You are not allowed to publish '.$type.' posts.');
    }

    public function assertCanComment(User $user): void
    {
        $this->assertAllowed($user, 'comments', 'You are not allowed to comment.');
    }

    public function assertCanReact(User $user): void
    {
        $this->assertAllowed($user, 'reactions', 'You are not allowed to react to posts.');
    }

    public function assertCanMessage(User $user, ?int $recipientId = null): void
    {
        $query = UserModerationRestriction::query()
            ->where('user_id', $user->id)
            ->where('scope', 'messaging')
            ->whereNull('lifted_at');

        if ($recipientId !== null) {
            $query->where(function ($restrictions) use ($recipientId): void {
                $restrictions->whereNull('target_user_id')
                    ->orWhere('target_user_id', $recipientId);
            });
        } else {
            $query->whereNull('target_user_id');
        }

        abort_if($query->exists(), 403, 'You are not allowed to send messages to this recipient.');
    }

    private function assertAllowed(User $user, string $scope, string $message): void
    {
        abort_if(
            UserModerationRestriction::query()
                ->where('user_id', $user->id)
                ->where('scope', $scope)
                ->whereNull('lifted_at')
                ->exists(),
            403,
            $message,
        );
    }
}
