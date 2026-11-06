<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ClaimRequest;
use App\Models\Conversation;
use App\Models\Handover;
use App\Models\Location;
use App\Models\LostSighting;
use App\Models\Post;
use App\Models\PostImage;
use App\Models\User;
use App\Models\VerificationQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EngagementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_approval_rejects_other_claims_for_the_same_post(): void
    {
        [$owner, $firstClaimant, $category, $location] = $this->createParticipants();
        $secondClaimant = $this->createUser('Second', 'Claimant', 'second-claimant');
        $post = $this->createPost($owner, $category, $location, 'found');
        $approvedClaim = ClaimRequest::create([
            'post_id' => $post->id,
            'claimant_id' => $firstClaimant->id,
            'answers' => ['matches'],
            'attempt_no' => 1,
            'status' => 'pending',
            'author_decision' => 'pending',
        ]);
        $otherClaim = ClaimRequest::create([
            'post_id' => $post->id,
            'claimant_id' => $secondClaimant->id,
            'answers' => ['does not match'],
            'attempt_no' => 1,
            'status' => 'pending',
            'author_decision' => 'pending',
        ]);

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/claims/{$approvedClaim->id}/post-author-decision", [
            'decision' => 'approve',
            'reason' => 'The identifying details match.',
        ])->assertOk()
            ->assertJsonPath('decision', 'approve');

        $this->assertDatabaseHas('claim_requests', [
            'id' => $approvedClaim->id,
            'status' => 'pending',
            'author_decision' => 'approved',
        ]);
        $this->assertDatabaseHas('claim_requests', [
            'id' => $otherClaim->id,
            'status' => 'rejected',
            'author_decision' => 'rejected',
            'author_decision_reason' => 'Another claim was approved for this item.',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $secondClaimant->id,
            'type' => 'claim_owner_rejected',
        ]);
        $admin = $this->createUser('Claims', 'Status', 'claim-status-admin', 'admin');
        $this->actingAs($admin, 'admin')
            ->get(route('admin.claims.index'))
            ->assertOk()
            ->assertSee('Approved by owner · Awaiting admin')
            ->assertSee('Rejected');
        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/claims/{$approvedClaim->id}/post-author-decision/undo")
            ->assertOk();
        $this->assertDatabaseHas('claim_requests', [
            'id' => $approvedClaim->id,
            'status' => 'pending',
            'author_decision' => 'pending',
            'owner_decision_batch_id' => null,
        ]);
        $this->assertDatabaseHas('claim_requests', [
            'id' => $otherClaim->id,
            'status' => 'pending',
            'author_decision' => 'pending',
            'owner_decision_batch_id' => null,
        ]);
        $this->patchJson("/api/v1/claims/{$approvedClaim->id}/post-author-decision", [
            'decision' => 'approve',
            'reason' => 'The identifying details still match.',
        ])->assertOk();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.claims.decide', $approvedClaim->id), [
                'decision' => 'approve',
                'reason' => 'The claim was verified.',
            ])->assertRedirect();
        $this->assertDatabaseHas('claim_requests', [
            'id' => $approvedClaim->id,
            'owner_decision_batch_id' => null,
        ]);
        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/claims/{$approvedClaim->id}/post-author-decision/undo")
            ->assertConflict();
    }

    public function test_owner_can_nudge_admin_about_a_claim_until_final_review(): void
    {
        [$owner, $claimant, $category, $location] = $this->createParticipants();
        $post = $this->createPost($owner, $category, $location, 'found');
        $claim = ClaimRequest::create([
            'post_id' => $post->id,
            'claimant_id' => $claimant->id,
            'answers' => ['matches'],
            'attempt_no' => 1,
            'status' => 'pending',
            'author_decision' => 'approved',
            'owner_decision_batch_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $claim->update(['owner_decision_batch_winner_id' => $claim->id]);
        $admin = $this->createUser('Claim', 'Admin', 'claim-nudge-admin', 'admin');

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/v1/claims/{$claim->id}/admin-conversation", [
            'body' => 'Please review this approved claim.',
        ])->assertCreated()
            ->assertJsonPath('type', 'admin')
            ->assertJsonPath('item_name', 'Admin support · '.$post->item_name);

        $conversationId = $response->json('id');
        $this->assertDatabaseHas('conversations', [
            'id' => $conversationId,
            'support_user_id' => $owner->id,
            'support_claim_id' => $claim->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $admin->id,
            'type' => 'admin_chat_message',
        ]);
        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversationId)
            ->assertJsonPath('data.0.type', 'admin');
        $this->actingAs($admin, 'admin')
            ->get(route('admin.chats.index', ['conversation' => $conversationId]))
            ->assertOk()
            ->assertSee('Please review this approved claim.');
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/claims/awaiting-post-author')
            ->assertOk()
            ->assertJsonPath('data.0.id', $claim->id);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.claims.decide', $claim->id), [
                'decision' => 'reject',
                'reason' => 'The submitted evidence does not establish ownership.',
            ])->assertRedirect();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/claims/awaiting-post-author')
            ->assertOk()
            ->assertJsonPath('data.0.id', $claim->id)
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.review_reason', 'The submitted evidence does not establish ownership.')
            ->assertJsonPath('data.0.can_undo_owner_decision', false);

        $approvedPost = $this->createPost($owner, $category, $location, 'found');
        $finallyApprovedClaim = ClaimRequest::create([
            'post_id' => $approvedPost->id,
            'claimant_id' => $claimant->id,
            'answers' => ['matches'],
            'attempt_no' => 1,
            'status' => 'pending',
            'author_decision' => 'approved',
            'owner_decision_batch_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $finallyApprovedClaim->update([
            'owner_decision_batch_winner_id' => $finallyApprovedClaim->id,
        ]);
        $rejectedSibling = ClaimRequest::create([
            'post_id' => $approvedPost->id,
            'claimant_id' => $this->createUser('Second', 'Claimant', 'approved-post-sibling')->id,
            'answers' => ['does not match'],
            'attempt_no' => 1,
            'status' => 'pending',
            'author_decision' => 'pending',
        ]);
        $this->actingAs($admin, 'admin')
            ->post(route('admin.claims.decide', $finallyApprovedClaim->id), [
                'decision' => 'approve',
                'reason' => 'The claim was verified.',
            ])->assertRedirect();
        Sanctum::actingAs($owner);
        $remainingClaims = $this->getJson('/api/v1/claims/awaiting-post-author')
            ->assertOk()
            ->json('data');
        $this->assertContains($claim->id, array_column($remainingClaims, 'id'));
        $this->assertNotContains($finallyApprovedClaim->id, array_column($remainingClaims, 'id'));
        $this->assertContains($rejectedSibling->id, array_column($remainingClaims, 'id'));
    }

    public function test_claim_waits_for_owner_decision_and_can_be_retried_after_rejection(): void
    {
        [$owner, $claimant, $category, $location] = $this->createParticipants();
        $post = $this->createPost($owner, $category, $location, 'found');
        VerificationQuestion::create([
            'post_id' => $post->id,
            'question' => 'What is written inside?',
            'answer' => 'Private label',
        ]);
        Sanctum::actingAs($claimant);

        $first = $this->post("/api/v1/posts/{$post->id}/claims", [
            'answers' => ['blue initials'],
            'evidence_images' => [UploadedFile::fake()->image('ownership.jpg')],
        ])->assertCreated()
            ->assertJsonPath('attempt_no', 1)
            ->assertJsonPath('attempts_used', 1)
            ->assertJsonPath('can_submit_another', false);
        $claimId = $first->json('claim_id');
        $this->assertDatabaseHas('claim_requests', [
            'id' => $claimId,
            'post_id' => $post->id,
            'claimant_id' => $claimant->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'status' => 'claim_pending',
        ]);
        $admin = $this->createUser('Claims', 'Review', 'claims-origin-review-admin', 'admin');
        $this->actingAs($admin, 'admin')
            ->get(route('admin.claims.index'))
            ->assertOk()
            ->assertSee('post_id='.$post->id, false)
            ->assertSee('View original post')
            ->assertSee($claimant->first_name.' '.$claimant->last_name)
            ->assertSee('Awaiting owner')
            ->assertSee('View claim');
        $this->get(route('admin.claims.show', $claimId))
            ->assertOk()
            ->assertSee('View original post');
        $this->get(route('admin.community-feed.index', ['view' => 'posts', 'post_id' => $post->id]))
            ->assertOk()
            ->assertSee($post->item_name);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $owner->id,
            'type' => 'claim_pending',
        ]);
        $this->assertSame(
            'blue initials',
            $this->getJson("/api/v1/posts/{$post->id}/my-claim")
                ->assertOk()
                ->json('data.answers.0'),
        );
        $this->assertFalse(
            $this->getJson("/api/v1/posts/{$post->id}/my-claim")
                ->assertOk()
                ->json('data.can_submit_another'),
        );
        $this->getJson('/api/v1/posts?type=found')
            ->assertOk()
            ->assertJsonPath('data.0.has_submitted_claim', true);

        $this->postJson("/api/v1/posts/{$post->id}/claims", [
            'answers' => ['duplicate attempt'],
            'evidence_images' => [UploadedFile::fake()->image('ownership-duplicate.jpg')],
        ])->assertConflict();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.claims.decide', $claimId), [
                'decision' => 'reject',
                'reason' => 'The evidence does not match.',
            ])->assertConflict();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/claims/awaiting-post-author')
            ->assertOk()
            ->assertJsonPath('data.0.id', $claimId)
            ->assertJsonPath('data.0.post_id', $post->id)
            ->assertJsonPath('data.0.answers.0', 'blue initials')
            ->assertJsonPath('data.0.verification_questions.0.answer', 'Private label');
        $this->patchJson("/api/v1/claims/{$claimId}/post-author-decision", [
            'decision' => 'reject',
            'reason' => 'The answer does not match the label.',
        ])->assertOk()
            ->assertJsonPath('decision', 'reject');
        $this->assertDatabaseHas('claim_requests', [
            'id' => $claimId,
            'status' => 'rejected',
            'author_decision_reason' => 'The answer does not match the label.',
        ]);
        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'status' => 'active',
        ]);
        $this->getJson('/api/v1/posts?type=found')
            ->assertOk()
            ->assertJsonPath('data.0.has_submitted_claim', false);

        Sanctum::actingAs($claimant);
        $this->getJson("/api/v1/posts/{$post->id}/my-claim")
            ->assertOk()
            ->assertJsonPath('data.can_submit_another', true)
            ->assertJsonPath('data.attempts_used', 1)
            ->assertJsonPath('data.author_decision_reason', 'The answer does not match the label.');

        $retry = $this->post("/api/v1/posts/{$post->id}/claims", [
            'answers' => ['corrected answer'],
            'evidence_images' => [UploadedFile::fake()->image('ownership-retry.jpg')],
        ])->assertCreated()
            ->assertJsonPath('attempt_no', 2)
            ->assertJsonPath('attempts_used', 2)
            ->assertJsonPath('can_submit_another', false);
        $this->assertNotSame($claimId, $retry->json('claim_id'));
        $this->assertDatabaseCount('claim_requests', 2);
        $this->getJson('/api/v1/posts?type=found')
            ->assertOk()
            ->assertJsonPath('data.0.has_submitted_claim', true);
        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'status' => 'claim_pending',
        ]);
    }

    public function test_pending_claimant_can_edit_answers_and_evidence_without_creating_an_attempt(): void
    {
        Storage::fake('public');
        [$owner, $claimant, $category, $location] = $this->createParticipants();
        $post = $this->createPost($owner, $category, $location, 'found');
        VerificationQuestion::create([
            'post_id' => $post->id,
            'question' => 'What is written inside?',
            'answer' => 'Private label',
        ]);
        Sanctum::actingAs($claimant);

        $submission = $this->post("/api/v1/posts/{$post->id}/claims", [
            'answers' => ['blue initials'],
            'evidence_images' => [UploadedFile::fake()->image('ownership-original.jpg')],
        ])->assertCreated()
            ->assertJsonPath('editable', true);
        $claimId = $submission->json('claim_id');
        $claim = ClaimRequest::findOrFail($claimId);
        $originalImagePath = $claim->evidenceImages()->value('path');
        Storage::disk('public')->assertExists($originalImagePath);

        Sanctum::actingAs($this->createUser('Other', 'Claimant', 'other-claim-edit'));
        $this->post("/api/v1/posts/{$post->id}/claims/{$claimId}", [
            '_method' => 'PATCH',
            'answers' => ['unauthorized answer'],
        ])->assertNotFound();

        Sanctum::actingAs($claimant);
        $this->post("/api/v1/posts/{$post->id}/claims/{$claimId}", [
            '_method' => 'PATCH',
            'answers' => ['updated blue initials'],
            'evidence_images' => [UploadedFile::fake()->image('ownership-updated.jpg')],
        ])->assertOk()
            ->assertJsonPath('updated', true)
            ->assertJsonPath('editable', true);

        $claim->refresh();
        $updatedImagePath = $claim->evidenceImages()->value('path');
        $this->assertSame(1, $claim->attempt_no);
        $this->assertSame(
            'updated blue initials',
            $this->getJson("/api/v1/posts/{$post->id}/my-claim")
                ->assertOk()
                ->assertJsonPath('data.editable', true)
                ->json('data.answers.0'),
        );
        $this->assertDatabaseCount('claim_requests', 1);
        $this->assertDatabaseCount('claim_evidence_images', 1);
        $this->assertNotSame($originalImagePath, $updatedImagePath);
        Storage::disk('public')->assertExists($updatedImagePath);
        Storage::disk('public')->assertMissing($originalImagePath);

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/claims/{$claimId}/post-author-decision", [
            'decision' => 'approve',
            'reason' => 'The identifying marks match.',
        ])->assertOk();
        Sanctum::actingAs($claimant);
        $this->post("/api/v1/posts/{$post->id}/claims/{$claimId}", [
            '_method' => 'PATCH',
            'answers' => ['edit after owner decision'],
        ])->assertConflict();
    }

    public function test_claim_resubmissions_stop_at_the_configured_per_item_attempt_limit(): void
    {
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(
            ['key' => 'claim_attempts'],
            ['value' => '2'],
        );
        [$owner, $claimant, $category, $location] = $this->createParticipants();
        $post = $this->createPost($owner, $category, $location, 'found');
        VerificationQuestion::create([
            'post_id' => $post->id,
            'question' => 'What is written inside?',
            'answer' => 'Private label',
        ]);
        Sanctum::actingAs($claimant);
        $first = $this->post("/api/v1/posts/{$post->id}/claims", [
            'answers' => ['blue initials'],
            'evidence_images' => [UploadedFile::fake()->image('ownership-1.jpg')],
        ])->assertCreated();
        $firstId = $first->json('claim_id');

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/claims/{$firstId}/post-author-decision", [
            'decision' => 'reject',
            'reason' => 'The evidence does not match.',
        ])->assertOk();

        Sanctum::actingAs($claimant);
        $second = $this->post("/api/v1/posts/{$post->id}/claims", [
            'answers' => ['updated blue initials'],
            'evidence_images' => [UploadedFile::fake()->image('ownership-2.jpg')],
        ])->assertCreated();
        $secondId = $second->json('claim_id');
        $this->assertSame(2, $second->json('attempt_no'));

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/claims/{$secondId}/post-author-decision", [
            'decision' => 'reject',
            'reason' => 'The new evidence does not match.',
        ])->assertOk();

        Sanctum::actingAs($claimant);
        $this->post("/api/v1/posts/{$post->id}/claims", [
            'answers' => ['third attempt'],
            'evidence_images' => [UploadedFile::fake()->image('ownership-3.jpg')],
        ])->assertStatus(429);
        $this->assertDatabaseCount('claim_requests', 2);
    }

    public function test_claimant_can_submit_again_after_an_unsuccessful_handover_reopens_the_post(): void
    {
        [$owner, $claimant, $category, $location] = $this->createParticipants();
        $post = $this->createPost($owner, $category, $location, 'found');
        VerificationQuestion::create([
            'post_id' => $post->id,
            'question' => 'What is written inside?',
            'answer' => 'Private label',
        ]);
        $claim = ClaimRequest::create([
            'post_id' => $post->id,
            'claimant_id' => $claimant->id,
            'answers' => ['private label'],
            'attempt_no' => 1,
            'status' => 'approved',
            'author_decision' => 'approved',
        ]);
        Handover::create([
            'claim_id' => $claim->id,
            'status' => 'unsuccessful',
            'failure_reason' => 'The scheduled handover did not occur.',
            'unsuccessful_at' => now(),
        ]);

        Sanctum::actingAs($claimant);
        $this->getJson("/api/v1/posts/{$post->id}/my-claim")
            ->assertOk()
            ->assertJsonPath('data.handover_unsuccessful', true)
            ->assertJsonPath('data.can_submit_another', true);
        $this->post("/api/v1/posts/{$post->id}/claims", [
            'answers' => ['updated private label'],
            'evidence_images' => [UploadedFile::fake()->image('ownership-after-handover.jpg')],
        ])->assertCreated()
            ->assertJsonPath('attempt_no', 2);
    }

    public function test_lost_evidence_is_visible_to_its_owner_and_can_open_an_admin_chat(): void
    {
        [$owner, $finder, $category, $location] = $this->createParticipants();
        $post = $this->createPost($owner, $category, $location, 'lost');
        Sanctum::actingAs($finder);

        $submit = $this->post("/api/v1/posts/{$post->id}/sightings", [
            'answer' => 'yes',
            'evidence_text' => 'Seen near the library.',
            'evidence_image' => UploadedFile::fake()->image('evidence.jpg'),
        ])->assertCreated()
            ->assertJsonPath('attempts_used', 1)
            ->assertJsonPath('can_submit_another', true);
        $sightingId = $submit->json('sighting_id');
        $this->travel(1)->seconds();
        $second = $this->postJson("/api/v1/posts/{$post->id}/sightings", [
            'answer' => 'yes',
            'evidence_text' => 'Updated: seen near the library.',
        ])->assertCreated()
            ->assertJsonPath('attempts_used', 2)
            ->assertJsonPath('can_submit_another', true);
        $secondId = $second->json('sighting_id');
        $this->assertNotSame($sightingId, $secondId);
        $this->assertDatabaseCount('lost_sightings', 2);
        LostSighting::where('post_id', $post->id)
            ->where('user_id', $finder->id)
            ->update(['created_at' => now()->subHours(2)]);
        $this->postJson("/api/v1/posts/{$post->id}/sightings", [
            'answer' => 'yes',
            'evidence_text' => 'A late change.',
        ])->assertConflict();
        $this->assertDatabaseCount('lost_sightings', 2);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $owner->id,
            'type' => 'lost_sighting',
        ]);

        $this->actingAs($this->createUser('Admin', 'Member', 'evidence-admin', 'admin'), 'admin')
            ->get(route('admin.sightings.index'))
            ->assertOk()
            ->assertSee('Awaiting owner review')
            ->assertSee('Updated: seen near the library.');

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/my-evidence')
            ->assertOk()
            ->assertJsonPath('data.0.id', $sightingId)
            ->assertJsonPath('data.0.author_decision', 'pending');

        $this->postJson("/api/v1/sightings/{$sightingId}/admin-conversation", [
            'body' => 'Please review this evidence.',
        ])->assertCreated();
        $supportConversationId = Conversation::where('support_user_id', $owner->id)->value('id');
        $this->actingAs($this->createUser('Admin', 'Responder', 'support-admin', 'admin'), 'admin')
            ->get(route('admin.chats.index', ['conversation' => $supportConversationId]))
            ->assertOk()
            ->assertSee('Please review this evidence.');

        $admin = User::where('tmc_id_no', 'TMC-SUPPORT-ADMIN')->firstOrFail();
        $this->post(route('admin.chats.send', $supportConversationId), [
            'body' => 'We will review the evidence.',
        ])->assertRedirect();
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $supportConversationId,
            'sender_id' => $admin->id,
            'body' => 'We will review the evidence.',
        ]);

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/sightings/{$sightingId}", [
            'decision' => 'approve',
            'reason' => 'The evidence clearly identifies the item.',
        ])->assertOk();
        $admin = $this->createUser('Admin', 'Reviewer', 'review-admin', 'admin');
        $this->actingAs($admin, 'admin')
            ->post(route('admin.sightings.decide', $sightingId), [
                'decision' => 'approve',
                'reason' => 'The evidence supports the sighting.',
            ])->assertRedirect();

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonFragment(['type' => 'evidence'])
            ->assertJsonFragment(['type' => 'admin']);
        $this->getJson("/api/v1/conversations/{$supportConversationId}/messages")
            ->assertOk()
            ->assertJsonPath('data.1.sender_role', 'admin')
            ->assertJsonPath('data.1.body', 'We will review the evidence.');
        $this->getJson('/api/v1/handovers')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'lost');
    }

    public function test_owner_evidence_decisions_resolve_the_selected_proof_and_reject_siblings(): void
    {
        [$owner, $finder, $category, $location] = $this->createParticipants();
        $secondFinder = $this->createUser('Second', 'Finder', 'second-finder');
        $post = $this->createPost($owner, $category, $location, 'lost');
        $approvedEvidence = LostSighting::create([
            'post_id' => $post->id,
            'user_id' => $finder->id,
            'answer' => 'yes',
            'evidence_text' => 'I found this item.',
            'author_decision' => 'pending',
            'admin_decision' => 'pending',
        ]);
        $otherEvidence = LostSighting::create([
            'post_id' => $post->id,
            'user_id' => $secondFinder->id,
            'answer' => 'yes',
            'evidence_text' => 'I also found this item.',
            'author_decision' => 'pending',
            'admin_decision' => 'pending',
        ]);

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/sightings/{$approvedEvidence->id}", [
            'decision' => 'approve',
            'reason' => 'The photo and description match the item.',
        ])->assertOk();

        $this->assertDatabaseHas('lost_sightings', [
            'id' => $approvedEvidence->id,
            'author_decision' => 'approved',
            'author_decision_reason' => 'The photo and description match the item.',
            'admin_decision' => 'pending',
        ]);
        $this->assertDatabaseHas('lost_sightings', [
            'id' => $otherEvidence->id,
            'author_decision' => 'rejected',
            'author_decision_reason' => 'Another evidence submission for this item was approved.',
            'admin_decision' => 'pending',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $secondFinder->id,
            'type' => 'sighting_owner_reviewed',
        ]);
        $admin = $this->createUser('Evidence', 'Status', 'evidence-status-admin', 'admin');
        $this->actingAs($admin, 'admin')
            ->get(route('admin.sightings.index'))
            ->assertOk()
            ->assertSee('Owner approved · Awaiting administrator review')
            ->assertSee('The photo and description match the item.')
            ->assertSee('Another evidence submission for this item was approved.')
            ->assertSee('Rejected');
        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/sightings/{$approvedEvidence->id}/undo-owner-decision")
            ->assertOk();
        $this->assertDatabaseHas('lost_sightings', [
            'id' => $approvedEvidence->id,
            'author_decision' => 'pending',
            'admin_decision' => 'pending',
            'owner_decision_batch_id' => null,
        ]);
        $this->assertDatabaseHas('lost_sightings', [
            'id' => $otherEvidence->id,
            'author_decision' => 'pending',
            'admin_decision' => 'pending',
            'owner_decision_batch_id' => null,
        ]);

        $rejectionPost = $this->createPost($owner, $category, $location, 'lost');
        $rejectedEvidence = LostSighting::create([
            'post_id' => $rejectionPost->id,
            'user_id' => $finder->id,
            'answer' => 'yes',
            'evidence_text' => 'This evidence does not match.',
            'author_decision' => 'pending',
            'admin_decision' => 'pending',
        ]);
        $this->patchJson("/api/v1/sightings/{$rejectedEvidence->id}", [
            'decision' => 'reject',
            'reason' => 'The photo does not show the claimed feature.',
        ])->assertOk();
        $this->assertDatabaseHas('lost_sightings', [
            'id' => $rejectedEvidence->id,
            'author_decision' => 'rejected',
            'author_decision_reason' => 'The photo does not show the claimed feature.',
            'admin_decision' => 'pending',
        ]);
    }

    public function test_admin_approval_rejects_other_pending_evidence_for_the_same_post(): void
    {
        [$owner, $finder, $category, $location] = $this->createParticipants();
        $secondFinder = $this->createUser('Second', 'Finder', 'admin-review-second-finder');
        $post = $this->createPost($owner, $category, $location, 'lost');
        $approvedEvidence = LostSighting::create([
            'post_id' => $post->id,
            'user_id' => $finder->id,
            'answer' => 'yes',
            'evidence_text' => 'Owner approved evidence.',
            'author_decision' => 'approved',
            'admin_decision' => 'pending',
        ]);
        $approvedEvidence->update([
            'owner_decision_batch_id' => '11111111-1111-4111-8111-111111111111',
            'owner_decision_batch_winner_id' => $approvedEvidence->id,
        ]);
        $otherEvidence = LostSighting::create([
            'post_id' => $post->id,
            'user_id' => $secondFinder->id,
            'answer' => 'yes',
            'evidence_text' => 'Another owner-approved evidence.',
            'author_decision' => 'approved',
            'admin_decision' => 'pending',
        ]);
        $admin = $this->createUser('Evidence', 'Admin', 'evidence-final-review', 'admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.sightings.decide', $approvedEvidence->id), [
                'decision' => 'approve',
                'reason' => 'The evidence is valid.',
            ])->assertRedirect();

        $this->assertDatabaseHas('lost_sightings', [
            'id' => $approvedEvidence->id,
            'author_decision' => 'approved',
            'admin_decision' => 'approved',
        ]);
        $this->assertDatabaseHas('lost_sightings', [
            'id' => $otherEvidence->id,
            'author_decision' => 'approved',
            'admin_decision' => 'rejected',
        ]);
        $this->assertDatabaseHas('lost_sightings', [
            'id' => $approvedEvidence->id,
            'owner_decision_batch_id' => null,
        ]);
        Sanctum::actingAs($owner);
        $evidenceInReview = $this->getJson('/api/v1/my-evidence')
            ->assertOk()
            ->json('data');
        $this->assertNotContains($approvedEvidence->id, array_column($evidenceInReview, 'id'));
        $this->assertContains($otherEvidence->id, array_column($evidenceInReview, 'id'));
        $postEvidenceInReview = $this->getJson("/api/v1/posts/{$post->id}/sightings")
            ->assertOk()
            ->json('data');
        $this->assertNotContains($approvedEvidence->id, array_column($postEvidenceInReview, 'id'));
        $this->patchJson("/api/v1/sightings/{$approvedEvidence->id}/undo-owner-decision")
            ->assertConflict();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $secondFinder->id,
            'type' => 'sighting_owner_reviewed',
        ]);
    }

    public function test_lost_evidence_resubmissions_stop_at_the_configured_per_item_attempt_limit(): void
    {
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(
            ['key' => 'proof_attempts'],
            ['value' => '2'],
        );
        [$owner, $finder, $category, $location] = $this->createParticipants();
        $post = $this->createPost($owner, $category, $location, 'lost');

        Sanctum::actingAs($finder);
        $first = $this->post('/api/v1/posts/'.$post->id.'/sightings', [
            'answer' => 'yes',
            'evidence_text' => 'I found it by the library.',
            'evidence_image' => UploadedFile::fake()->image('proof-1.jpg'),
        ])->assertCreated();
        $firstId = $first->json('sighting_id');
        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/sightings/{$firstId}", [
            'decision' => 'reject',
            'reason' => 'This evidence is unclear.',
        ])->assertOk();

        Sanctum::actingAs($finder);
        $second = $this->post('/api/v1/posts/'.$post->id.'/sightings', [
            'answer' => 'yes',
            'evidence_text' => 'I found it by the library again.',
            'evidence_image' => UploadedFile::fake()->image('proof-2.jpg'),
        ])->assertCreated();
        $secondId = $second->json('sighting_id');
        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/sightings/{$secondId}", [
            'decision' => 'reject',
            'reason' => 'This evidence does not match the item.',
        ])->assertOk();

        Sanctum::actingAs($finder);
        $this->post('/api/v1/posts/'.$post->id.'/sightings', [
            'answer' => 'yes',
            'evidence_text' => 'A third attempt.',
            'evidence_image' => UploadedFile::fake()->image('proof-3.jpg'),
        ])->assertStatus(429);
        $this->assertDatabaseCount('lost_sightings', 2);
    }

    public function test_owner_post_photos_are_hidden_until_the_matching_evidence_is_approved(): void
    {
        Storage::fake('public');
        [$owner, $member, $category, $location] = $this->createParticipants();
        $foundPost = $this->createPost($owner, $category, $location, 'found');
        $foundPath = 'posts/2026/11/found-item.jpg';
        Storage::disk('public')->put($foundPath, 'found-image');
        PostImage::create([
            'post_id' => $foundPost->id,
            'path' => $foundPath,
            'alt_text' => 'Found item',
        ]);
        $claim = ClaimRequest::create([
            'post_id' => $foundPost->id,
            'claimant_id' => $member->id,
            'answers' => ['a private answer'],
            'attempt_no' => 1,
            'status' => 'approved',
            'author_decision' => 'pending',
        ]);

        Sanctum::actingAs($member);
        $this->getJson("/api/v1/posts/{$foundPost->id}")
            ->assertOk()
            ->assertJsonPath('data.images', []);
        $claim->update(['author_decision' => 'approved']);
        $foundImageUrl = $this->getJson("/api/v1/posts/{$foundPost->id}")
            ->assertOk()
            ->json('data.images.0.url');
        $this->assertIsString($foundImageUrl);
        $this->withServerVariables(['HTTP_HOST' => 'phone.local'])
            ->get($foundImageUrl)
            ->assertOk();
        $this->get('/api/v1/media?path='.rawurlencode($foundPath))->assertNotFound();

        $lostPost = $this->createPost($owner, $category, $location, 'lost');
        $lostPath = 'posts/2026/11/lost-item.jpg';
        Storage::disk('public')->put($lostPath, 'lost-image');
        PostImage::create([
            'post_id' => $lostPost->id,
            'path' => $lostPath,
            'alt_text' => 'Lost item',
        ]);
        $sighting = LostSighting::create([
            'post_id' => $lostPost->id,
            'user_id' => $member->id,
            'answer' => 'yes',
            'evidence_text' => 'I found the item.',
            'author_decision' => 'pending',
            'admin_decision' => 'pending',
        ]);
        $this->getJson("/api/v1/posts/{$lostPost->id}")
            ->assertOk()
            ->assertJsonPath('data.images', []);
        $sighting->update(['author_decision' => 'approved']);
        $this->getJson("/api/v1/posts/{$lostPost->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.images');
    }

    public function test_comments_support_one_level_replies_and_reactions_are_heart_toggles(): void
    {
        [$owner, $member, $category, $location] = $this->createParticipants();
        $post = $this->createPost($owner, $category, $location, 'lost');
        Sanctum::actingAs($member);

        $root = $this->postJson("/api/v1/posts/{$post->id}/comments", [
            'body' => 'I saw this near the library.',
        ])->assertCreated();
        $rootId = $root->json('id');
        $reply = $this->postJson("/api/v1/posts/{$post->id}/comments", [
            'body' => 'Thank you for the detail.',
            'parent_id' => $rootId,
        ])->assertCreated();
        $this->getJson("/api/v1/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.id', $rootId)
            ->assertJsonPath('data.0.replies.0.body', 'Thank you for the detail.');
        $this->postJson("/api/v1/posts/{$post->id}/comments", [
            'body' => 'Nested replies are not allowed.',
            'parent_id' => $reply->json('id'),
        ])->assertUnprocessable();

        $this->postJson("/api/v1/posts/{$post->id}/reactions", ['type' => 'like'])
            ->assertOk()
            ->assertJsonPath('reactions_count', 1);
        $this->postJson("/api/v1/posts/{$post->id}/reactions", ['type' => 'none'])
            ->assertOk()
            ->assertJsonPath('reactions_count', 0);
        $this->postJson("/api/v1/posts/{$post->id}/reactions", ['type' => 'haha'])
            ->assertUnprocessable();
    }

    private function createParticipants(): array
    {
        return [
            $this->createUser('Post', 'Owner', 'workflow-owner'),
            $this->createUser('Campus', 'Member', 'workflow-member'),
            Category::create(['name' => 'Workflow items', 'is_active' => true]),
            Location::create(['name' => 'Workflow library', 'is_active' => true]),
        ];
    }

    private function createPost(
        User $owner,
        Category $category,
        Location $location,
        string $type,
    ): Post {
        return Post::create([
            'user_id' => $owner->id,
            'type' => $type,
            'item_name' => 'Workflow item',
            'description' => 'A sample item for workflow tests.',
            'category_id' => $category->id,
            'color' => 'Blue',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'active',
        ]);
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
