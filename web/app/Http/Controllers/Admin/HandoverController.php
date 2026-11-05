<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Handover;
use App\Models\MeetupLocation;
use App\Models\User;
use App\Services\HandoverExpiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Illuminate\Support\Carbon;

class HandoverController extends Controller
{
    public function index(Request $request, HandoverExpiry $expiry): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:awaiting_admin,awaiting_participants,awaiting_schedule,reschedule_requested,scheduled'],
            'type' => ['nullable', 'in:lost,found'],
        ]);
        $expiry->expirePastDue();
        $handovers = Handover::with($this->relations())
            ->whereNotIn('status', ['completed', 'unsuccessful'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where(function ($typeQuery) use ($type): void {
                $typeQuery
                    ->whereHas('claimRequest.post', fn ($postQuery) => $postQuery->where('type', $type))
                    ->orWhereHas('sighting.post', fn ($postQuery) => $postQuery->where('type', $type));
            }))
            ->latest();

        return view('admin.handovers', [
            'handovers' => $handovers->paginate(25)->withQueryString(),
            'status' => $filters['status'] ?? '',
            'type' => $filters['type'] ?? '',
        ]);
    }

    public function manage(Handover $handover, HandoverExpiry $expiry): View
    {
        $expiry->expirePastDue();
        $handover->refresh();
        abort_unless(
            in_array($handover->status, ['awaiting_admin', 'awaiting_participants', 'awaiting_schedule', 'reschedule_requested'], true),
            404,
        );
        $handover->load($this->relations());
        $participantsApproved = $handover->owner_confirmed_at !== null
            && $handover->finder_confirmed_at !== null
            && in_array($handover->status, ['awaiting_schedule', 'reschedule_requested'], true);

        return view('admin.handover-manage', [
            'handover' => $handover,
            'locations' => MeetupLocation::where('is_active', true)->orderBy('name')->get(),
            'participantsApproved' => $participantsApproved,
            'rescheduleLimit' => (int) DB::table('settings')
                ->where('key', 'handover_reschedule_attempts')
                ->value('value') ?: 2,
        ]);
    }

    public function schedule(Request $request, Handover $handover): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:meetup_locations,id'],
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);
        abort_unless(
            in_array($handover->status, ['awaiting_schedule', 'reschedule_requested'], true)
                && $handover->owner_confirmed_at !== null
                && $handover->finder_confirmed_at !== null,
            409,
            'Both participants must agree before a handover can be scheduled.',
        );
        abort_unless(
            MeetupLocation::whereKey($data['location_id'])->where('is_active', true)->exists(),
            422,
            'Select an active handover location.',
        );

        DB::transaction(function () use ($request, $handover, $data): void {
            $locked = Handover::whereKey($handover->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                in_array($locked->status, ['awaiting_schedule', 'reschedule_requested'], true)
                    && $locked->owner_confirmed_at !== null
                    && $locked->finder_confirmed_at !== null,
                409,
                'Both participants must agree before a handover can be scheduled.',
            );
            $codes = $this->newReceiptCodes();
            $location = MeetupLocation::findOrFail($data['location_id']);
            $locked->update([
                'status' => 'scheduled',
                'location_id' => $location->id,
                'scheduled_at' => $data['scheduled_at'],
                'owner_code_hash' => Hash::make($codes['owner']),
                'owner_code_encrypted' => Crypt::encryptString($codes['owner']),
                'owner_code_lookup' => $this->codeLookup($codes['owner']),
                'finder_code_hash' => Hash::make($codes['finder']),
                'finder_code_encrypted' => Crypt::encryptString($codes['finder']),
                'finder_code_lookup' => $this->codeLookup($codes['finder']),
                'expires_at' => Carbon::parse($data['scheduled_at']),
                'reschedule_requested_at' => null,
                'requested_date' => null,
                'reschedule_reason' => null,
            ]);
            $this->notifyHandoverScheduled($locked, $codes, $location);
            $this->audit($request, 'handover_scheduled', $locked, 'Scheduled at '.$location->name.' for '.$locked->scheduled_at->toIso8601String());
        });

        return redirect()->route('admin.handovers.index')
            ->with('status', 'Handover scheduled and a receipt was issued to both participants.');
    }

    public function decideReschedule(Request $request, Handover $handover): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        abort_unless($handover->status === 'reschedule_requested', 409, 'There is no reschedule request awaiting review.');

        if ($data['decision'] === 'approve') {
            return $this->schedule($request, $handover);
        }

        DB::transaction(function () use ($request, $handover, $data): void {
            $locked = Handover::whereKey($handover->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'reschedule_requested', 409, 'This reschedule request has already been reviewed.');
            $locked->update([
                'status' => 'scheduled',
                'reschedule_requested_at' => null,
                'requested_date' => null,
                'reschedule_reason' => null,
            ]);
            $this->notifyParticipants($locked, 'handover_reschedule_rejected', 'Reschedule request not approved', $data['reason'] ?: 'Your current handover date remains in place.');
            $this->audit($request, 'handover_reschedule_rejected', $locked, $data['reason'] ?? null);
        });

        return redirect()->route('admin.handovers.index')
            ->with('status', 'Reschedule request declined. The existing handover date remains scheduled.');
    }

    public function complete(Request $request, Handover $handover, HandoverExpiry $expiry): RedirectResponse
    {
        $expiry->expirePastDue();
        $handover->refresh();
        $data = $request->validate([
            'owner_code' => ['required', 'digits:16', 'different:finder_code'],
            'finder_code' => ['required', 'digits:16'],
        ]);
        abort_unless($handover->status === 'scheduled', 409, 'Only scheduled handovers can be completed.');

        DB::transaction(function () use ($request, $handover, $data): void {
            $locked = Handover::whereKey($handover->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $locked->status === 'scheduled',
                409,
                'This handover is not available for completion.',
            );
            abort_unless(
                $locked->owner_code_hash
                    && $locked->finder_code_hash
                    && Hash::check($data['owner_code'], $locked->owner_code_hash)
                    && Hash::check($data['finder_code'], $locked->finder_code_hash),
                422,
                'Both participant verification codes must be valid.',
            );
            $locked->update([
                'status' => 'completed',
                'completed_at' => now(),
                'owner_confirmed_at' => $locked->owner_confirmed_at ?? now(),
                'finder_confirmed_at' => $locked->finder_confirmed_at ?? now(),
            ]);
            ($locked->claimRequest?->post ?? $locked->sighting?->post)?->update(['status' => 'retrieved']);
            $this->notifyParticipants($locked, 'handover_completed', 'Item retrieval confirmed', 'TMC confirmed that the item was retrieved from the scheduled handover location.');
            $this->audit($request, 'handover_completed', $locked, 'Both participant verification codes verified.');
        });

        return back()->with('status', 'Item retrieval confirmed and the origin post is marked retrieved.');
    }

    public function receipt(Request $request, Handover $handover, HandoverExpiry $expiry): View
    {
        $expiry->expirePastDue();
        $handover->refresh();
        $participant = $request->query('participant');
        abort_if($participant !== null && ! in_array($participant, ['owner', 'finder'], true), 404);
        abort_unless(
            $handover->status === 'scheduled'
                && $handover->owner_confirmed_at !== null
                && $handover->finder_confirmed_at !== null
                && $handover->owner_code_encrypted
                && $handover->finder_code_encrypted,
            404,
        );
        $handover->load($this->relations());

        return view('admin.handover-receipt', [
            'handover' => $handover,
            'verificationCode' => match ($participant) {
                'owner' => Crypt::decryptString($handover->owner_code_encrypted),
                'finder' => Crypt::decryptString($handover->finder_code_encrypted),
                default => null,
            },
        ]);
    }

    public function history(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:completed,unsuccessful'],
            'type' => ['nullable', 'in:lost,found'],
        ]);
        $handovers = Handover::with($this->relations())
            ->whereIn('status', ['completed', 'unsuccessful'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where(function ($typeQuery) use ($type): void {
                $typeQuery
                    ->whereHas('claimRequest.post', fn ($postQuery) => $postQuery->where('type', $type))
                    ->orWhereHas('sighting.post', fn ($postQuery) => $postQuery->where('type', $type));
            }))
            ->latest('updated_at');

        return view('admin.handover-history', [
            'handovers' => $handovers->paginate(20)->withQueryString(),
            'status' => $filters['status'] ?? '',
            'type' => $filters['type'] ?? '',
        ]);
    }

    public function historyShow(Handover $handover): View
    {
        abort_unless(in_array($handover->status, ['completed', 'unsuccessful'], true), 404);
        $handover->load($this->relations() + [
            'claimRequest.post.category:id,name',
            'claimRequest.post.location:id,name',
            'claimRequest.post.images',
            'sighting.post.category:id,name',
            'sighting.post.location:id,name',
            'sighting.post.images',
        ]);

        return view('admin.handover-history-detail', compact('handover'));
    }

    private function relations(): array
    {
        return [
            'claimRequest.post:id,item_name,user_id,type,category_id,location_id,event_date,status,description',
            'claimRequest.post.author:id,first_name,last_name',
            'claimRequest.claimant:id,first_name,last_name',
            'sighting.post:id,item_name,user_id,type,category_id,location_id,event_date,status,description',
            'sighting.post.author:id,first_name,last_name',
            'sighting.user:id,first_name,last_name',
            'location:id,name,description,operating_hours',
        ];
    }

    /**
     * @return array{owner: string, finder: string}
     */
    private function newReceiptCodes(): array
    {
        $ownerCode = $this->newReceiptCode();
        do {
            $finderCode = $this->newReceiptCode();
        } while ($finderCode === $ownerCode);

        return ['owner' => $ownerCode, 'finder' => $finderCode];
    }

    private function newReceiptCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 9999999999999999), 16, '0', STR_PAD_LEFT);
            $lookup = $this->codeLookup($code);
        } while (Handover::where('owner_code_lookup', $lookup)
            ->orWhere('finder_code_lookup', $lookup)
            ->exists());

        return $code;
    }

    private function codeLookup(string $code): string
    {
        return hash_hmac('sha256', $code, config('app.key'));
    }

    /**
     * @param  array{owner: string, finder: string}  $codes
     */
    private function notifyHandoverScheduled(
        Handover $handover,
        array $codes,
        MeetupLocation $location,
    ): void {
        $handover->loadMissing(['claimRequest.post', 'sighting.post']);
        $claim = $handover->claimRequest;
        $sighting = $handover->sighting;
        $participants = [
            $claim?->claimant_id ?? $sighting?->post?->user_id => ['role' => 'owner', 'code' => $codes['owner']],
            $claim?->post?->user_id ?? $sighting?->user_id => ['role' => 'other participant', 'code' => $codes['finder']],
        ];
        $scheduledAt = $handover->scheduled_at?->format('M j, Y g:i A');

        foreach (User::whereIn('id', array_keys($participants))->get() as $participant) {
            $verification = $participants[$participant->id];
            $participant->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'handover_scheduled',
                'data' => [
                    'title' => 'Handover receipt ready',
                    'body' => "Your handover is scheduled for {$scheduledAt} at {$location->name}. Your {$verification['role']} verification code is {$verification['code']}. TMC requires both participant codes to confirm retrieval.",
                    'handover_id' => $handover->id,
                    'verification_code' => $verification['code'],
                    'verification_role' => $verification['role'],
                ],
            ]);
        }
    }

    private function notifyParticipants(
        Handover $handover,
        string $type,
        string $title,
        string $body,
        array $extra = [],
    ): void {
        $claim = $handover->claimRequest;
        $sighting = $handover->sighting;
        $ids = [
            $claim?->claimant_id ?? $sighting?->post?->user_id,
            $claim?->post?->user_id ?? $sighting?->user_id,
        ];
        foreach (User::whereIn('id', array_filter($ids))->get() as $participant) {
            $participant->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => $type,
                'data' => ['title' => $title, 'body' => $body] + $extra,
            ]);
        }
    }

    private function audit(Request $request, string $action, Handover $handover, ?string $reason): void
    {
        DB::table('audit_logs')->insert([
            'admin_id' => $request->user('admin')->id,
            'action' => $action,
            'target_type' => Handover::class,
            'target_id' => $handover->id,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
