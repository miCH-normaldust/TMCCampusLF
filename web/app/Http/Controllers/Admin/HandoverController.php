<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Handover;
use App\Models\MeetupLocation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\View\View;

class HandoverController extends Controller
{
    public function index(): View
    {
        return view('admin.handovers', [
            'handovers' => Handover::with($this->relations())
                ->whereNotIn('status', ['completed', 'unsuccessful'])
                ->latest()
                ->paginate(25),
            'locations' => MeetupLocation::where('is_active', true)->orderBy('name')->get(),
            'rescheduleLimit' => (int) DB::table('settings')
                ->where('key', 'handover_reschedule_attempts')
                ->value('value') ?: 2,
        ]);
    }

    public function approve(Request $request, Handover $handover): RedirectResponse
    {
        abort_unless($handover->status === 'awaiting_admin', 409, 'This handover is no longer awaiting administrator approval.');

        DB::transaction(function () use ($request, $handover): void {
            $locked = Handover::whereKey($handover->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'awaiting_admin', 409, 'This handover has already been reviewed.');
            $locked->update([
                'status' => 'awaiting_participants',
                'admin_approved_at' => now(),
            ]);
            $this->notifyParticipants($locked, 'handover_admin_approved', 'Handover approved by TMC', 'Both participants must agree before TMC schedules the handover.');
            $this->audit($request, 'handover_admin_approved', $locked, null);
        });

        return back()->with('status', 'Handover approved. Waiting for both users to agree.');
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
            $code = $this->newReceiptCode();
            $location = MeetupLocation::findOrFail($data['location_id']);
            $locked->update([
                'status' => 'scheduled',
                'location_id' => $location->id,
                'scheduled_at' => $data['scheduled_at'],
                'code_hash' => Hash::make($code),
                'code_encrypted' => Crypt::encryptString($code),
                'code_lookup' => hash_hmac('sha256', $code, config('app.key')),
                'expires_at' => now()->parse($data['scheduled_at'])->endOfDay(),
                'reschedule_requested_at' => null,
                'requested_date' => null,
                'reschedule_reason' => null,
            ]);
            $this->notifyParticipants(
                $locked,
                'handover_scheduled',
                'Handover receipt ready',
                'Your handover is scheduled for '.$locked->scheduled_at->format('M j, Y g:i A').' at '.$location->name.'. Receipt code: '.$code,
                ['handover_id' => $locked->id],
            );
            $this->audit($request, 'handover_scheduled', $locked, 'Scheduled at '.$location->name.' for '.$locked->scheduled_at->toIso8601String());
        });

        return back()->with('status', 'Handover scheduled and a receipt was issued to both participants.');
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

        return back()->with('status', 'Reschedule request declined. The existing handover date remains scheduled.');
    }

    public function complete(Request $request, Handover $handover): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:16']]);
        abort_unless($handover->status === 'scheduled', 409, 'Only scheduled handovers can be completed.');
        abort_unless(
            $handover->code_hash && Hash::check($data['code'], $handover->code_hash),
            422,
            'The receipt code is invalid.',
        );

        DB::transaction(function () use ($request, $handover, $data): void {
            $locked = Handover::whereKey($handover->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $locked->status === 'scheduled'
                    && $locked->code_hash
                    && Hash::check($data['code'], $locked->code_hash),
                409,
                'This handover is not available for completion.',
            );
            $locked->update([
                'status' => 'completed',
                'completed_at' => now(),
                'owner_confirmed_at' => $locked->owner_confirmed_at ?? now(),
                'finder_confirmed_at' => $locked->finder_confirmed_at ?? now(),
            ]);
            ($locked->claimRequest?->post ?? $locked->sighting?->post)?->update(['status' => 'retrieved']);
            $this->notifyParticipants($locked, 'handover_completed', 'Item retrieval confirmed', 'TMC confirmed that the item was retrieved from the scheduled handover location.');
            $this->audit($request, 'handover_completed', $locked, 'Receipt code verified.');
        });

        return back()->with('status', 'Item retrieval confirmed and the origin post is marked retrieved.');
    }

    public function receipt(Handover $handover): View
    {
        abort_unless($handover->status === 'scheduled' && $handover->code_encrypted, 404);
        $handover->load($this->relations());

        return view('admin.handover-receipt', [
            'handover' => $handover,
            'code' => Crypt::decryptString($handover->code_encrypted),
        ]);
    }

    public function history(): View
    {
        return view('admin.handover-history', [
            'handovers' => Handover::with($this->relations())
                ->whereIn('status', ['completed', 'unsuccessful'])
                ->latest('updated_at')
                ->paginate(20),
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

    private function newReceiptCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 9999999999999999), 16, '0', STR_PAD_LEFT);
            $lookup = hash_hmac('sha256', $code, config('app.key'));
        } while (Handover::where('code_lookup', $lookup)->exists());

        return $code;
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
