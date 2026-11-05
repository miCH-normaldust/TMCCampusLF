<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('handovers', function ($table): void {
            $table->string('owner_code_hash')->nullable();
            $table->text('owner_code_encrypted')->nullable();
            $table->char('owner_code_lookup', 64)->nullable()->unique();
            $table->string('finder_code_hash')->nullable();
            $table->text('finder_code_encrypted')->nullable();
            $table->char('finder_code_lookup', 64)->nullable()->unique();
        });

        foreach (DB::table('handovers')
            ->whereNotNull('code_encrypted')
            ->whereIn('status', ['scheduled', 'reschedule_requested'])
            ->get() as $handover) {
            $ownerCode = $this->newCode();
            $finderCode = $this->newCode([$ownerCode]);
            DB::table('handovers')->where('id', $handover->id)->update([
                'owner_code_hash' => Hash::make($ownerCode),
                'owner_code_encrypted' => Crypt::encryptString($ownerCode),
                'owner_code_lookup' => $this->lookup($ownerCode),
                'finder_code_hash' => Hash::make($finderCode),
                'finder_code_encrypted' => Crypt::encryptString($finderCode),
                'finder_code_lookup' => $this->lookup($finderCode),
            ]);

            $this->removeLegacyCodesFromNotifications((int) $handover->id);
        }

        Schema::table('handovers', function ($table): void {
            $table->dropUnique(['code_lookup']);
            $table->dropColumn(['code_hash', 'code_encrypted', 'code_lookup']);
        });
    }

    public function down(): void
    {
        Schema::table('handovers', function ($table): void {
            $table->string('code_hash')->nullable();
            $table->text('code_encrypted')->nullable();
            $table->char('code_lookup', 64)->nullable()->unique();
        });

        foreach (DB::table('handovers')->whereNotNull('owner_code_encrypted')->get() as $handover) {
            $code = Crypt::decryptString($handover->owner_code_encrypted);
            DB::table('handovers')->where('id', $handover->id)->update([
                'code_hash' => Hash::make($code),
                'code_encrypted' => $handover->owner_code_encrypted,
                'code_lookup' => $this->lookup($code),
            ]);
        }

        Schema::table('handovers', function ($table): void {
            $table->dropUnique(['owner_code_lookup']);
            $table->dropUnique(['finder_code_lookup']);
            $table->dropColumn([
                'owner_code_hash',
                'owner_code_encrypted',
                'owner_code_lookup',
                'finder_code_hash',
                'finder_code_encrypted',
                'finder_code_lookup',
            ]);
        });
    }

    /**
     * @param  list<string>  $reservedCodes
     */
    private function newCode(array $reservedCodes = []): string
    {
        do {
            $code = str_pad((string) random_int(0, 9999999999999999), 16, '0', STR_PAD_LEFT);
            $lookup = $this->lookup($code);
            $alreadyUsed = in_array($code, $reservedCodes, true)
                || DB::table('handovers')
                    ->where('owner_code_lookup', $lookup)
                    ->orWhere('finder_code_lookup', $lookup)
                    ->exists();
        } while ($alreadyUsed);

        return $code;
    }

    private function lookup(string $code): string
    {
        return hash_hmac('sha256', $code, config('app.key'));
    }

    private function removeLegacyCodesFromNotifications(int $handoverId): void
    {
        $handover = DB::table('handovers')->where('id', $handoverId)->first();
        $ownerId = null;
        $finderId = null;

        if ($handover->claim_id !== null) {
            $claim = DB::table('claim_requests')->where('id', $handover->claim_id)->first();
            $ownerId = $claim?->claimant_id;
            $finderId = $claim
                ? DB::table('posts')->where('id', $claim->post_id)->value('user_id')
                : null;
        } elseif ($handover->sighting_id !== null) {
            $sighting = DB::table('lost_sightings')->where('id', $handover->sighting_id)->first();
            $ownerId = $sighting
                ? DB::table('posts')->where('id', $sighting->post_id)->value('user_id')
                : null;
            $finderId = $sighting?->user_id;
        }

        $notifications = DB::table('notifications')
            ->where('type', 'handover_scheduled')
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', array_filter([$ownerId, $finderId]))
            ->get();

        foreach ($notifications as $notification) {
            $data = is_array($notification->data)
                ? $notification->data
                : json_decode((string) $notification->data, true);
            if (! is_array($data) || (int) ($data['handover_id'] ?? 0) !== $handoverId) {
                continue;
            }

            $data['body'] = $handover->status === 'scheduled'
                ? 'Your previous shared verification code was replaced. Find your new personal code in Inbox > Handovers.'
                : 'Your previous shared verification code was replaced. Your new personal code will be available in Inbox > Handovers after the schedule is confirmed.';
            unset($data['verification_code']);
            DB::table('notifications')->where('id', $notification->id)->update([
                'data' => json_encode($data, JSON_THROW_ON_ERROR),
            ]);
        }
    }
};
