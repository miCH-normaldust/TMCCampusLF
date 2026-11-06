<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ClaimRequest;
use App\Models\Conversation;
use App\Models\Handover;
use App\Models\Location;
use App\Models\LostSighting;
use App\Models\MeetupLocation;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LostItemHandoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_handover_lists_can_filter_by_status_and_post_type(): void
    {
        [$owner, $finder, $category, $location] = $this->createParticipants();
        $lostPost = Post::create([
            'user_id' => $owner->id,
            'type' => 'lost',
            'item_name' => 'Lost type filter item',
            'description' => 'Lost on campus.',
            'category_id' => $category->id,
            'color' => 'Blue',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'handover_pending',
        ]);
        $sighting = LostSighting::create([
            'post_id' => $lostPost->id,
            'user_id' => $finder->id,
            'answer' => 'yes',
            'author_decision' => 'approved',
            'admin_decision' => 'approved',
        ]);
        $foundPost = Post::create([
            'user_id' => $owner->id,
            'type' => 'found',
            'item_name' => 'Found type filter item',
            'description' => 'Found on campus.',
            'category_id' => $category->id,
            'color' => 'Black',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'handover_pending',
        ]);
        $claim = ClaimRequest::create([
            'post_id' => $foundPost->id,
            'claimant_id' => $finder->id,
            'answers' => ['blue cover'],
            'attempt_no' => 1,
            'status' => 'approved',
            'author_decision' => 'approved',
        ]);
        $completedLostPost = Post::create([
            'user_id' => $owner->id,
            'type' => 'lost',
            'item_name' => 'Completed lost filter item',
            'description' => 'Lost on campus.',
            'category_id' => $category->id,
            'color' => 'Green',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'retrieved',
        ]);
        $completedSighting = LostSighting::create([
            'post_id' => $completedLostPost->id,
            'user_id' => $finder->id,
            'answer' => 'yes',
            'author_decision' => 'approved',
            'admin_decision' => 'approved',
        ]);
        $unsuccessfulFoundPost = Post::create([
            'user_id' => $owner->id,
            'type' => 'found',
            'item_name' => 'Unsuccessful found filter item',
            'description' => 'Found on campus.',
            'category_id' => $category->id,
            'color' => 'White',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'active',
        ]);
        $unsuccessfulClaim = ClaimRequest::create([
            'post_id' => $unsuccessfulFoundPost->id,
            'claimant_id' => $finder->id,
            'answers' => ['white label'],
            'attempt_no' => 1,
            'status' => 'rejected',
            'author_decision' => 'rejected',
        ]);
        Handover::create(['sighting_id' => $sighting->id, 'status' => 'scheduled']);
        Handover::create(['claim_id' => $claim->id, 'status' => 'awaiting_participants']);
        Handover::create(['sighting_id' => $completedSighting->id, 'status' => 'completed']);
        Handover::create(['claim_id' => $unsuccessfulClaim->id, 'status' => 'unsuccessful']);
        $admin = $this->createUser('Portal', 'Admin', 'admin-handover-filters', 'admin');

        $this->actingAs($admin, 'admin');

        $this->get(route('admin.handovers.index', ['status' => 'scheduled', 'type' => 'lost']))
            ->assertOk()
            ->assertSee('Lost type filter item')
            ->assertSee('Lost')
            ->assertDontSee('Found type filter item');
        $this->get(route('admin.handovers.index', ['status' => 'awaiting_participants', 'type' => 'found']))
            ->assertOk()
            ->assertSee('Found type filter item')
            ->assertSee('Found')
            ->assertDontSee('Lost type filter item');
        $this->get(route('admin.handovers.history', ['status' => 'completed', 'type' => 'lost']))
            ->assertOk()
            ->assertSee('Completed lost filter item')
            ->assertDontSee('Found type filter item');
        $this->get(route('admin.handovers.history', ['status' => 'unsuccessful', 'type' => 'found']))
            ->assertOk()
            ->assertSee('Unsuccessful found filter item')
            ->assertDontSee('Completed lost filter item');
    }

    public function test_lost_handover_button_is_available_to_participants_only(): void
    {
        [$owner, $finder, $category, $location] = $this->createParticipants();
        $outsider = $this->createUser('Outside', 'Member', 'outside-handover-feed');
        $post = Post::create([
            'user_id' => $owner->id,
            'type' => 'lost',
            'item_name' => 'Blue notebook',
            'description' => 'Lost on campus.',
            'category_id' => $category->id,
            'color' => 'Blue',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'handover_pending',
        ]);
        LostSighting::create([
            'post_id' => $post->id,
            'user_id' => $finder->id,
            'answer' => 'yes',
            'author_decision' => 'approved',
            'admin_decision' => 'approved',
        ]);

        foreach ([$owner, $finder] as $participant) {
            Sanctum::actingAs($participant);
            $this->getJson('/api/v1/posts')
                ->assertOk()
                ->assertJsonPath('data.0.is_handover_participant', true);
        }

        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/posts')
            ->assertOk()
            ->assertJsonPath('data.0.is_handover_participant', false);
    }

    public function test_approved_lost_sighting_requires_admin_and_user_agreements_before_scheduled_retrieval(): void
    {
        [$owner, $finder, $category, $location] = $this->createParticipants();
        $post = Post::create([
            'user_id' => $owner->id,
            'type' => 'lost',
            'item_name' => 'Blue notebook',
            'description' => 'Lost on campus.',
            'category_id' => $category->id,
            'color' => 'Blue',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'handover_pending',
        ]);
        $sighting = LostSighting::create([
            'post_id' => $post->id,
            'user_id' => $finder->id,
            'answer' => 'yes',
            'evidence_text' => 'Found near the library.',
            'author_decision' => 'approved',
            'admin_decision' => 'approved',
        ]);

        Sanctum::actingAs($owner);
        $handover = $this->postJson("/api/v1/sightings/{$sighting->id}/handover")
            ->assertCreated()
            ->assertJsonMissingPath('code')
            ->assertJsonMissingPath('location_id');
        $handoverId = $handover->json('id');

        \Illuminate\Support\Facades\DB::table('handovers')
            ->where('id', $handoverId)
            ->update(['status' => 'awaiting_agreements']);
        $migration = require database_path('migrations/2026_11_05_000003_normalize_legacy_handover_agreements_status.php');
        $migration->up();
        $this->assertDatabaseHas('handovers', [
            'id' => $handoverId,
            'status' => 'awaiting_participants',
        ]);

        Sanctum::actingAs($finder);
        $this->getJson('/api/v1/handovers')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'lost')
            ->assertJsonPath('data.0.item_name', 'Blue notebook')
            ->assertJsonPath('data.0.other_first_name', 'Item')
            ->assertJsonPath('data.0.my_confirmation_at', null)
            ->assertJsonPath('data.0.completed', false)
            ->assertJsonPath('data.0.status', 'awaiting_participants')
            ->assertJsonPath('data.0.receipt_code', null)
            ->assertJsonPath('data.0.transaction_reference', 'HL-'.str_pad((string) $handoverId, 8, '0', STR_PAD_LEFT))
            ->assertJsonPath('data.0.other_participant_name', $owner->first_name.' '.$owner->last_name);

        $outsider = $this->createUser('Outside', 'Member', 'outsider-handover');
        Sanctum::actingAs($outsider);
        $this->postJson("/api/v1/handovers/{$handoverId}/confirm")->assertNotFound();

        $this->assertDatabaseHas('handovers', [
            'id' => $handoverId,
            'sighting_id' => $sighting->id,
            'claim_id' => null,
        ]);

        $admin = $this->createUser('Portal', 'Admin', 'admin-lost-handover', 'admin');
        $this->actingAs($admin, 'admin')
            ->get(route('admin.handovers.index'))
            ->assertOk()
            ->assertSee('Blue notebook')
            ->assertSee('Owner')
            ->assertSee('Holder');
        $this->agreeScheduleAndComplete($admin, $handoverId, $owner, $finder);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => 'retrieved']);

        $this->get(route('admin.handovers.history'))
            ->assertOk()
            ->assertSee('Blue notebook')
            ->assertSee(route('admin.handovers.history.show', $handoverId));

        $this->get(route('admin.handovers.history.show', $handoverId))
            ->assertOk()
            ->assertSee('ORIGINAL CAMPUSLF POST')
            ->assertSee('Blue notebook')
            ->assertSee('Item Owner')
            ->assertSee('Item Finder')
            ->assertSee('Security Office')
            ->assertDontSee('Desk verified');
    }

    public function test_found_claim_requires_post_author_agreement_before_both_can_confirm(): void
    {
        [$author, $claimant, $category, $location] = $this->createParticipants();
        $post = Post::create([
            'user_id' => $author->id,
            'type' => 'found',
            'item_name' => 'Blue notebook',
            'description' => 'Found on campus.',
            'category_id' => $category->id,
            'color' => 'Blue',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'claim_pending',
        ]);
        $claim = ClaimRequest::create([
            'post_id' => $post->id,
            'claimant_id' => $claimant->id,
            'answers' => ['Has a handwritten label inside.'],
            'attempt_no' => 1,
            'status' => 'pending',
            'author_decision' => 'pending',
        ]);
        $conversation = Conversation::create(['claim_id' => $claim->id]);

        Sanctum::actingAs($claimant);
        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertNotFound();
        Sanctum::actingAs($author);
        $this->getJson('/api/v1/claims/awaiting-post-author')
            ->assertOk()
            ->assertJsonPath('data.0.id', $claim->id)
            ->assertJsonPath('data.0.claimant_first_name', 'Item')
            ->assertJsonPath('data.0.answers.0', 'Has a handwritten label inside.');

        $this->patchJson("/api/v1/claims/{$claim->id}/post-author-decision", [
            'decision' => 'approve',
            'reason' => 'The claimant provided sufficient ownership evidence.',
        ])->assertOk()
            ->assertJsonPath('decision', 'approve');
        $this->assertDatabaseMissing('handovers', ['claim_id' => $claim->id]);
        $admin = $this->createUser('Portal', 'Admin', 'admin-found-handover', 'admin');
        $this->actingAs($admin, 'admin')
            ->post(route('admin.claims.decide', $claim->id), [
                'decision' => 'approve',
            ])->assertRedirect();
        $this->assertDatabaseHas('handovers', ['claim_id' => $claim->id]);
        Sanctum::actingAs($claimant);
        $this->postJson("/api/v1/posts/{$post->id}/claims", [
            'answers' => ['a new claim'],
        ])->assertConflict();

        $author->update(['profile_picture_path' => 'profiles/author.png']);
        $conversation->messages()->create([
            'sender_id' => $author->id,
            'body' => 'The item is ready.',
        ]);
        Sanctum::actingAs($claimant);
        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.other_user_id', $author->id)
            ->assertJsonPath('data.0.other_first_name', $author->first_name)
            ->assertJsonPath(
                'data.0.other_profile_picture_url',
                url('/api/v1/media?path='.rawurlencode('profiles/author.png')),
            );
        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.sender_id', $author->id)
            ->assertJsonPath('data.0.sender_first_name', $author->first_name)
            ->assertJsonPath(
                'data.0.sender_profile_picture_url',
                url('/api/v1/media?path='.rawurlencode('profiles/author.png')),
            );
        $handoverList = $this->getJson('/api/v1/handovers')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'found')
            ->assertJsonPath('data.0.completed', false);
        $handoverId = $handoverList->json('data.0.id');
        $this->agreeScheduleAndComplete($admin, $handoverId, $claimant, $author);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => 'retrieved']);
    }

    private function agreeScheduleAndComplete(
        User $admin,
        int $handoverId,
        User $owner,
        User $holder,
    ): void {
        $location = MeetupLocation::create([
            'name' => 'Security Office',
            'description' => 'Campus security counter',
            'operating_hours' => 'Weekdays, 8:00 AM - 5:00 PM',
            'is_active' => true,
        ]);
        MeetupLocation::create([
            'name' => 'Inactive Handover Place',
            'is_active' => false,
        ]);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.handovers.index'))
            ->assertOk()
            ->assertSee('Awaiting participant agreements')
            ->assertSee('<th>Schedule</th>', false)
            ->assertSee('<th>Place</th>', false)
            ->assertSee('Waiting for both agreements')
            ->assertDontSee(route('admin.handovers.manage', $handoverId))
            ->assertDontSee('Set place and schedule')
            ->assertDontSee('TMC: Approved');
        $this->get(route('admin.handovers.manage', $handoverId))
            ->assertOk()
            ->assertDontSee('Set schedule and place')
            ->assertSee('Scheduling is available after both participants approve.');
        $this->get(route('admin.handovers.receipt', $handoverId))->assertNotFound();

        Handover::whereKey($handoverId)->update(['status' => 'awaiting_admin']);
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/handovers')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'awaiting_admin')
            ->assertJsonPath('data.0.my_confirmation_at', null)
            ->assertJsonPath('data.0.receipt_code', null);
        $this->postJson("/api/v1/handovers/{$handoverId}/reschedule", [
            'requested_date' => now()->addDays(4)->toDateString(),
            'reason' => 'Need a different date.',
        ])->assertStatus(409);

        Sanctum::actingAs($holder);
        $this->postJson("/api/v1/handovers/{$handoverId}/confirm")
            ->assertOk()
            ->assertJsonPath('status', 'awaiting_participants');
        $holderHandover = $this->getJson('/api/v1/handovers')->assertOk();
        $this->assertNotNull($holderHandover->json('data.0.my_confirmation_at'));
        $this->actingAs($admin, 'admin')
            ->post(route('admin.handovers.schedule', $handoverId), [
                'location_id' => $location->id,
                'scheduled_at' => now()->addDays(2)->setTime(10, 0)->toDateTimeString(),
            ])
            ->assertStatus(409);
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/handovers/{$handoverId}/confirm")
            ->assertOk()
            ->assertJsonPath('status', 'awaiting_schedule');
        $this->actingAs($admin, 'admin')
            ->get(route('admin.handovers.index'))
            ->assertOk()
            ->assertSee('Both participants agreed')
            ->assertSee(route('admin.handovers.manage', $handoverId));
        $this->get(route('admin.handovers.manage', $handoverId))
            ->assertOk()
            ->assertSee('Set schedule and place')
            ->assertSee('Security Office')
            ->assertDontSee('Inactive Handover Place')
            ->assertDontSee('Scheduling is available after both participants approve.');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.handovers.schedule', $handoverId), [
                'location_id' => $location->id,
                'scheduled_at' => now()->addDays(2)->setTime(10, 0)->toDateTimeString(),
            ])
            ->assertRedirect();
        $this->actingAs($admin, 'admin')
            ->get(route('admin.handovers.receipt', $handoverId))
            ->assertOk()
            ->assertSee('HANDOVER RECEIPT')
            ->assertSee('Scheduled')
            ->assertSee('Transaction reference: <strong>HL-'.str_pad((string) $handoverId, 8, '0', STR_PAD_LEFT).'</strong>', false);
        $this->get(route('admin.handovers.index'))
            ->assertOk()
            ->assertSee('Confirm retrieval')
            ->assertSee(route('admin.handovers.receipt', [
                'handover' => $handoverId,
                'participant' => 'owner',
            ]))
            ->assertSee(route('admin.handovers.receipt', [
                'handover' => $handoverId,
                'participant' => 'finder',
            ]))
            ->assertSee('handover-confirm-modal')
            ->assertSee('owner_code')
            ->assertSee('finder_code');

        Sanctum::actingAs($owner);
        $handover = $this->getJson('/api/v1/handovers')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'scheduled')
            ->assertJsonPath('data.0.transaction_reference', 'HL-'.str_pad((string) $handoverId, 8, '0', STR_PAD_LEFT))
            ->assertJsonPath('data.0.owner_name', $owner->first_name.' '.$owner->last_name)
            ->assertJsonPath('data.0.other_participant_name', $holder->first_name.' '.$holder->last_name);
        $ownerCode = $handover->json('data.0.receipt_code');
        $this->assertMatchesRegularExpression('/^[0-9]{16}$/', $ownerCode);
        $this->assertSame('Owner', $handover->json('data.0.receipt_code_role'));
        Sanctum::actingAs($holder);
        $holderHandover = $this->getJson('/api/v1/handovers')
            ->assertOk()
            ->assertJsonPath('data.0.receipt_code_role', 'Other participant')
            ->assertJsonPath('data.0.transaction_reference', 'HL-'.str_pad((string) $handoverId, 8, '0', STR_PAD_LEFT));
        $finderCode = $holderHandover->json('data.0.receipt_code');
        $this->assertMatchesRegularExpression('/^[0-9]{16}$/', $finderCode);
        $this->assertNotSame($ownerCode, $finderCode);

        $ownerNotification = $owner->notifications()->where('type', 'handover_scheduled')->latest()->firstOrFail();
        $finderNotification = $holder->notifications()->where('type', 'handover_scheduled')->latest()->firstOrFail();
        $this->assertSame($ownerCode, $ownerNotification->data['verification_code']);
        $this->assertSame('owner', $ownerNotification->data['verification_role']);
        $this->assertSame($finderCode, $finderNotification->data['verification_code']);
        $this->assertSame('other participant', $finderNotification->data['verification_role']);
        $this->assertNotSame($ownerNotification->data['verification_code'], $finderNotification->data['verification_code']);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.handovers.receipt', $handoverId))
            ->assertOk()
            ->assertDontSee($ownerCode)
            ->assertDontSee($finderCode)
            ->assertSee('Both codes must be presented')
            ->assertSee($owner->first_name.' '.$owner->last_name)
            ->assertSee($holder->first_name.' '.$holder->last_name)
            ->assertSee('Security Office')
            ->assertSee('HL-'.str_pad((string) $handoverId, 8, '0', STR_PAD_LEFT));
        $ownerReceipt = $this->get(route('admin.handovers.receipt', [
            'handover' => $handoverId,
            'participant' => 'owner',
        ]))
            ->assertOk()
            ->assertSee('Verification code')
            ->assertSee($ownerCode)
            ->assertDontSee($finderCode);
        $finderReceipt = $this->get(route('admin.handovers.receipt', [
            'handover' => $handoverId,
            'participant' => 'finder',
        ]))
            ->assertOk()
            ->assertSee('Verification code')
            ->assertSee($finderCode)
            ->assertDontSee($ownerCode);
        $this->assertSame(
            str_replace($ownerCode, '[verification code]', $ownerReceipt->getContent()),
            str_replace($finderCode, '[verification code]', $finderReceipt->getContent()),
        );
        $this->get(route('admin.handovers.receipt', [
            'handover' => $handoverId,
            'participant' => 'unknown',
        ]))->assertNotFound();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.handovers.complete', $handoverId), [
                'owner_code' => $ownerCode,
            ])
            ->assertSessionHasErrors(['finder_code']);
        $this->post(route('admin.handovers.complete', $handoverId), [
            'owner_code' => $ownerCode,
            'finder_code' => $ownerCode,
        ])->assertSessionHasErrors(['owner_code']);
        $this->post(route('admin.handovers.complete', $handoverId), [
            'owner_code' => $ownerCode,
            'finder_code' => str_repeat('0', 16),
        ])
            ->assertUnprocessable();
        $this->post(route('admin.handovers.complete', $handoverId), [
            'owner_code' => $finderCode,
            'finder_code' => $ownerCode,
        ])->assertUnprocessable();
        $this->post(route('admin.handovers.complete', $handoverId), [
            'owner_code' => $ownerCode,
            'finder_code' => $finderCode,
        ])
            ->assertRedirect();

        $this->assertDatabaseHas('handovers', [
            'id' => $handoverId,
            'status' => 'completed',
        ]);
    }

    public function test_migration_rotates_a_shared_code_into_private_participant_codes(): void
    {
        [$owner, $finder, $category, $location] = $this->createParticipants();
        $post = Post::create([
            'user_id' => $owner->id,
            'type' => 'lost',
            'item_name' => 'Legacy shared-code item',
            'description' => 'Lost on campus.',
            'category_id' => $category->id,
            'color' => 'Blue',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'handover_pending',
        ]);
        $sighting = LostSighting::create([
            'post_id' => $post->id,
            'user_id' => $finder->id,
            'answer' => 'yes',
            'author_decision' => 'approved',
            'admin_decision' => 'approved',
        ]);
        $handover = Handover::create([
            'sighting_id' => $sighting->id,
            'status' => 'scheduled',
            'owner_confirmed_at' => now(),
            'finder_confirmed_at' => now(),
            'scheduled_at' => now()->addDay(),
            'owner_code_hash' => Hash::make('1234567890123456'),
            'owner_code_encrypted' => Crypt::encryptString('1234567890123456'),
            'owner_code_lookup' => hash_hmac('sha256', '1234567890123456', config('app.key')),
            'finder_code_hash' => Hash::make('6543210987654321'),
            'finder_code_encrypted' => Crypt::encryptString('6543210987654321'),
            'finder_code_lookup' => hash_hmac('sha256', '6543210987654321', config('app.key')),
        ]);

        $migration = require database_path('migrations/2026_11_05_000004_split_handover_verification_codes.php');
        $migration->down();
        foreach ([$owner, $finder] as $participant) {
            $participant->notifications()->create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => 'handover_scheduled',
                'data' => [
                    'title' => 'Handover receipt ready',
                    'body' => 'Receipt code: 1234567890123456',
                    'handover_id' => $handover->id,
                ],
            ]);
        }
        $migration->up();

        Sanctum::actingAs($owner);
        $ownerCode = $this->getJson('/api/v1/handovers')
            ->assertOk()
            ->json('data.0.receipt_code');
        Sanctum::actingAs($finder);
        $finderCode = $this->getJson('/api/v1/handovers')
            ->assertOk()
            ->json('data.0.receipt_code');

        $this->assertNotSame('1234567890123456', $ownerCode);
        $this->assertNotSame('1234567890123456', $finderCode);
        $this->assertNotSame($ownerCode, $finderCode);
        foreach ([$owner, $finder] as $participant) {
            $notification = $participant->notifications()->where('type', 'handover_scheduled')->firstOrFail();
            $this->assertStringNotContainsString('1234567890123456', $notification->data['body']);
            $this->assertStringContainsString('Inbox > Handovers', $notification->data['body']);
        }
    }

    public function test_post_author_can_decline_an_admin_approved_found_claim_without_creating_handover(): void
    {
        [$author, $claimant, $category, $location] = $this->createParticipants();
        $post = Post::create([
            'user_id' => $author->id,
            'type' => 'found',
            'item_name' => 'Blue notebook',
            'description' => 'Found on campus.',
            'category_id' => $category->id,
            'color' => 'Blue',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'claim_pending',
        ]);
        $claim = ClaimRequest::create([
            'post_id' => $post->id,
            'claimant_id' => $claimant->id,
            'answers' => ['Incorrect answer.'],
            'attempt_no' => 1,
            'status' => 'approved',
            'author_decision' => 'pending',
        ]);

        Sanctum::actingAs($author);
        $this->patchJson("/api/v1/claims/{$claim->id}/post-author-decision", [
            'decision' => 'reject',
            'reason' => 'The answers did not match the item details.',
        ])->assertOk()
            ->assertJsonPath('decision', 'reject');

        $this->assertDatabaseHas('claim_requests', [
            'id' => $claim->id,
            'status' => 'rejected',
            'author_decision' => 'rejected',
            'author_decision_reason' => 'The answers did not match the item details.',
        ]);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => 'active']);
        $this->assertDatabaseMissing('handovers', ['claim_id' => $claim->id]);
    }

    public function test_participants_can_confirm_handover_with_a_legacy_status(): void
    {
        [$owner, $finder, $category, $location] = $this->createParticipants();
        $post = Post::create([
            'user_id' => $owner->id,
            'type' => 'lost',
            'item_name' => 'Legacy-status necklace',
            'description' => 'Lost on campus.',
            'category_id' => $category->id,
            'color' => 'Silver',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'handover_pending',
        ]);
        $sighting = LostSighting::create([
            'post_id' => $post->id,
            'user_id' => $finder->id,
            'answer' => 'yes',
            'author_decision' => 'approved',
            'admin_decision' => 'approved',
        ]);
        $handover = Handover::create([
            'sighting_id' => $sighting->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/handovers/{$handover->id}/confirm")
            ->assertOk()
            ->assertJsonPath('status', 'awaiting_participants');

        Sanctum::actingAs($finder);
        $this->postJson("/api/v1/handovers/{$handover->id}/confirm")
            ->assertOk()
            ->assertJsonPath('status', 'awaiting_schedule');

        $handover->refresh();
        $this->assertNotNull($handover->owner_confirmed_at);
        $this->assertNotNull($handover->finder_confirmed_at);
        $this->assertSame('awaiting_schedule', $handover->status);
    }

    public function test_rejected_reschedule_preserves_schedule_and_allows_configured_attempts(): void
    {
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(
            ['key' => 'handover_reschedule_attempts'],
            ['value' => '2'],
        );
        [$owner, $finder, $category, $location] = $this->createParticipants();
        $post = Post::create([
            'user_id' => $owner->id,
            'type' => 'lost',
            'item_name' => 'Blue notebook',
            'description' => 'Lost on campus.',
            'category_id' => $category->id,
            'color' => 'Blue',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'handover_pending',
        ]);
        $sighting = LostSighting::create([
            'post_id' => $post->id,
            'user_id' => $finder->id,
            'answer' => 'yes',
            'author_decision' => 'approved',
            'admin_decision' => 'approved',
        ]);
        $meetupLocation = MeetupLocation::create([
            'name' => 'Security Office',
            'is_active' => true,
        ]);
        $originalScheduledAt = now()->addDays(3)->setTime(10, 0);
        $handover = Handover::create([
            'sighting_id' => $sighting->id,
            'status' => 'scheduled',
            'admin_approved_at' => now(),
            'owner_confirmed_at' => now(),
            'finder_confirmed_at' => now(),
            'scheduled_at' => $originalScheduledAt,
            'location_id' => $meetupLocation->id,
            'expires_at' => $originalScheduledAt,
        ]);
        $requestedDate = now()->addDays(5)->toDateString();

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/handovers/{$handover->id}/reschedule", [
            'requested_date' => $requestedDate,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('handovers', [
            'id' => $handover->id,
            'status' => 'scheduled',
        ]);
        $this->postJson("/api/v1/handovers/{$handover->id}/reschedule", [
            'requested_date' => $requestedDate,
            'reason' => 'I cannot attend the scheduled date.',
        ])->assertCreated()
            ->assertJsonPath('status', 'reschedule_requested');
        $this->getJson('/api/v1/handovers')
            ->assertOk()
            ->assertJsonPath('data.0.receipt_code', null)
            ->assertJsonPath('data.0.reschedule_reason', 'I cannot attend the scheduled date.');

        $admin = $this->createUser('Portal', 'Admin', 'admin-reschedule-limit', 'admin');
        $this->actingAs($admin, 'admin')
            ->get(route('admin.handovers.index'))
            ->assertOk()
            ->assertSee('Review date change')
            ->assertSee(route('admin.handovers.manage', $handover));
        $this->get(route('admin.handovers.manage', $handover))
            ->assertOk()
            ->assertSee('Review reschedule request');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.handovers.reschedule', $handover), [
                'decision' => 'reject',
                'reason' => 'Please attend the original date.',
            ])->assertRedirect();

        $this->assertDatabaseHas('handovers', [
            'id' => $handover->id,
            'status' => 'scheduled',
            'reschedule_attempts' => 1,
            'scheduled_at' => $originalScheduledAt->toDateTimeString(),
            'location_id' => $meetupLocation->id,
            'expires_at' => $originalScheduledAt->toDateTimeString(),
        ]);
        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'status' => 'handover_pending',
        ]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/handovers/{$handover->id}/reschedule", [
            'requested_date' => now()->addDays(6)->toDateString(),
            'reason' => 'I still cannot attend.',
        ])->assertCreated()
            ->assertJsonPath('status', 'reschedule_requested')
            ->assertJsonPath('attempts_used', 2)
            ->assertJsonPath('attempt_limit', 2);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.handovers.reschedule', $handover), [
                'decision' => 'reject',
                'reason' => 'The original date remains available.',
            ])->assertRedirect();

        $this->assertDatabaseHas('handovers', [
            'id' => $handover->id,
            'status' => 'scheduled',
            'reschedule_attempts' => 2,
            'scheduled_at' => $originalScheduledAt->toDateTimeString(),
            'location_id' => $meetupLocation->id,
            'expires_at' => $originalScheduledAt->toDateTimeString(),
            'requested_date' => null,
            'reschedule_reason' => null,
        ]);
        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'status' => 'handover_pending',
        ]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/handovers/{$handover->id}/reschedule", [
            'requested_date' => now()->addDays(7)->toDateString(),
            'reason' => 'I cannot attend any of the earlier dates.',
        ])->assertStatus(429)
            ->assertJsonPath('message', 'You have used all 2 reschedule attempts for this handover.');
        $this->assertDatabaseHas('handovers', [
            'id' => $handover->id,
            'status' => 'scheduled',
            'reschedule_attempts' => 2,
            'scheduled_at' => $originalScheduledAt->toDateTimeString(),
        ]);
    }

    public function test_scheduled_handover_expires_at_its_scheduled_time(): void
    {
        [$owner, $finder, $category, $location] = $this->createParticipants();
        $post = Post::create([
            'user_id' => $owner->id,
            'type' => 'lost',
            'item_name' => 'Expired handover item',
            'description' => 'Lost on campus.',
            'category_id' => $category->id,
            'color' => 'Blue',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'handover_pending',
        ]);
        $sighting = LostSighting::create([
            'post_id' => $post->id,
            'user_id' => $finder->id,
            'answer' => 'yes',
            'author_decision' => 'approved',
            'admin_decision' => 'approved',
        ]);
        $handover = Handover::create([
            'sighting_id' => $sighting->id,
            'status' => 'scheduled',
            'owner_confirmed_at' => now()->subDay(),
            'finder_confirmed_at' => now()->subDay(),
            'scheduled_at' => now()->addHour(),
        ]);

        $this->travel(2)->hours();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/handovers')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'unsuccessful')
            ->assertJsonPath('data.0.receipt_code', null);

        $this->assertDatabaseHas('handovers', [
            'id' => $handover->id,
            'status' => 'unsuccessful',
            'failure_reason' => 'The scheduled handover time passed without completion.',
        ]);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => 'active']);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $owner->id,
            'type' => 'handover_unsuccessful',
        ]);
    }

    /**
     * @return array{User, User, Category, Location}
     */
    private function createParticipants(): array
    {
        $owner = $this->createUser('Item', 'Owner', 'owner-handover');
        $finder = $this->createUser('Item', 'Finder', 'finder-handover');
        $category = Category::create(['name' => 'Accessories', 'is_active' => true]);
        $location = Location::create(['name' => 'Library', 'is_active' => true]);

        return [$owner, $finder, $category, $location];
    }

    private function createUser(
        string $firstName,
        string $lastName,
        string $identifier,
        string $role = 'user',
    ): User {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'tmc_id_no' => strtoupper('TMC-'.$identifier),
            'email' => $identifier.'@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
