<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ClaimRequest;
use App\Models\Conversation;
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

    public function test_claim_is_single_per_user_and_editable_only_during_the_first_hour(): void
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
            ->assertJsonPath('editable', true);
        $claimId = $first->json('claim_id');
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

        $this->postJson("/api/v1/posts/{$post->id}/claims", [
            'answers' => ['updated answer'],
        ])->assertOk()
            ->assertJsonPath('claim_id', $claimId)
            ->assertJsonPath('updated', true);
        $this->assertDatabaseCount('claim_requests', 1);

        \App\Models\ClaimRequest::whereKey($claimId)->update([
            'created_at' => now()->subHours(2),
        ]);
        $this->postJson("/api/v1/posts/{$post->id}/claims", [
            'answers' => ['too late'],
        ])->assertConflict();
        $this->assertDatabaseCount('claim_requests', 1);
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
        $admin = $this->createUser('Claims', 'Admin', 'claims-limit-admin', 'admin');

        Sanctum::actingAs($claimant);
        $first = $this->post("/api/v1/posts/{$post->id}/claims", [
            'answers' => ['blue initials'],
            'evidence_images' => [UploadedFile::fake()->image('ownership-1.jpg')],
        ])->assertCreated();
        $firstId = $first->json('claim_id');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.claims.decide', $firstId), [
                'decision' => 'reject',
                'reason' => 'The evidence does not match.',
            ])->assertRedirect();

        Sanctum::actingAs($claimant);
        $second = $this->post("/api/v1/posts/{$post->id}/claims", [
            'answers' => ['updated blue initials'],
            'evidence_images' => [UploadedFile::fake()->image('ownership-2.jpg')],
        ])->assertCreated();
        $secondId = $second->json('claim_id');
        $this->assertSame(2, $second->json('attempt_no'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.claims.decide', $secondId), [
                'decision' => 'reject',
                'reason' => 'The new evidence does not match.',
            ])->assertRedirect();

        Sanctum::actingAs($claimant);
        $this->post("/api/v1/posts/{$post->id}/claims", [
            'answers' => ['third attempt'],
            'evidence_images' => [UploadedFile::fake()->image('ownership-3.jpg')],
        ])->assertStatus(429);
        $this->assertDatabaseCount('claim_requests', 2);
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
        ])->assertCreated();
        $sightingId = $submit->json('sighting_id');
        $this->postJson("/api/v1/posts/{$post->id}/sightings", [
            'answer' => 'yes',
            'evidence_text' => 'Updated: seen near the library.',
        ])->assertOk()
            ->assertJsonPath('updated', true);
        $this->assertDatabaseCount('lost_sightings', 1);
        LostSighting::whereKey($sightingId)->update([
            'created_at' => now()->subHours(2),
        ]);
        $this->postJson("/api/v1/posts/{$post->id}/sightings", [
            'answer' => 'yes',
            'evidence_text' => 'A late change.',
        ])->assertConflict();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $owner->id,
            'type' => 'lost_sighting',
        ]);

        $this->actingAs($this->createUser('Admin', 'Member', 'evidence-admin', 'admin'), 'admin')
            ->get(route('admin.sightings.index'))
            ->assertOk()
            ->assertSee('Submitted evidence awaiting owner review')
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
        $this->patchJson("/api/v1/sightings/{$firstId}", ['decision' => 'reject'])->assertOk();

        Sanctum::actingAs($finder);
        $second = $this->post('/api/v1/posts/'.$post->id.'/sightings', [
            'answer' => 'yes',
            'evidence_text' => 'I found it by the library again.',
            'evidence_image' => UploadedFile::fake()->image('proof-2.jpg'),
        ])->assertCreated();
        $secondId = $second->json('sighting_id');
        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/sightings/{$secondId}", ['decision' => 'reject'])->assertOk();

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
