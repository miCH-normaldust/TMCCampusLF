<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Post;
use App\Models\PostRetrievalRequest;
use App\Models\User;
use App\Models\VerificationQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostRetrievalRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_request_retrieved_status_and_admin_can_approve_it(): void
    {
        $owner = $this->createUser('Owner', 'Member', 'retrieval-owner');
        $admin = $this->createUser('Portal', 'Admin', 'retrieval-admin', 'admin');
        $post = $this->createPost($owner, 'lost', 'Blue wallet');

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/v1/posts/{$post->id}/retrieval-request", [
            'reason' => 'I found my wallet after creating the post.',
        ])->assertCreated()
            ->assertJsonPath('status', 'pending');

        $requestId = $response->json('retrieval_request_id');
        $this->assertDatabaseHas('post_retrieval_requests', [
            'id' => $requestId,
            'post_id' => $post->id,
            'user_id' => $owner->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $admin->id,
            'type' => 'post_retrieval_requested',
        ]);
        $this->getJson("/api/v1/my-posts/{$post->id}/edit-data")
            ->assertStatus(409);
        $this->getJson('/api/v1/my-posts')
            ->assertOk()
            ->assertJsonPath('data.0.retrieval_request_status', 'pending');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.sightings.index'))
            ->assertOk()
            ->assertSee('Lost-item mark-as-retrieved requests')
            ->assertSee('Blue wallet')
            ->assertSee('Approve and mark retrieved');

        $this->post(route('admin.retrieval-requests.decide', $requestId), [
            'decision' => 'approve',
            'reason' => 'Owner confirmed recovery.',
        ])->assertRedirect();

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'status' => 'retrieved',
        ]);
        $this->assertDatabaseHas('post_retrieval_requests', [
            'id' => $requestId,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $owner->id,
            'type' => 'post_retrieval_approved',
        ]);
    }

    public function test_found_post_retrieval_requests_appear_in_claims_queue_and_pending_claims_block_requests(): void
    {
        $owner = $this->createUser('Owner', 'Member', 'retrieval-guard');
        $foundPost = $this->createPost($owner, 'found', 'Found backpack');
        $lostPost = $this->createPost($owner, 'lost', 'Lost backpack');
        $lostRequestPost = $this->createPost($owner, 'lost', 'Lost-item request marker');
        $claimant = $this->createUser('Claimant', 'Member', 'retrieval-claimant');
        $lostPost->claimRequests()->create([
            'claimant_id' => $claimant->id,
            'answers' => ['A private detail'],
            'attempt_no' => 1,
            'status' => 'pending',
        ]);
        Sanctum::actingAs($owner);

        $foundRequestId = $this->postJson("/api/v1/posts/{$foundPost->id}/retrieval-request")
            ->assertCreated()
            ->json('retrieval_request_id');
        $this->postJson("/api/v1/posts/{$lostRequestPost->id}/retrieval-request")
            ->assertCreated();
        $this->postJson("/api/v1/posts/{$lostPost->id}/retrieval-request")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This post has a claim or sighting being reviewed and cannot be marked retrieved yet.');

        $this->actingAs($this->createUser('Portal', 'Admin', 'retrieval-queue-admin', 'admin'), 'admin')
            ->get(route('admin.claims.index'))
            ->assertOk()
            ->assertSee('Found-item mark-as-retrieved requests')
            ->assertSee('Found backpack')
            ->assertDontSee('Lost-item request marker');
        $this->get(route('admin.sightings.index'))
            ->assertOk()
            ->assertSee('Lost-item mark-as-retrieved requests')
            ->assertSee('Lost-item request marker')
            ->assertDontSee('Found backpack');
        $this->post(route('admin.retrieval-requests.decide', $foundRequestId), [
            'decision' => 'approve',
        ])->assertRedirect();
        $this->assertDatabaseHas('posts', [
            'id' => $foundPost->id,
            'status' => 'retrieved',
        ]);
    }

    public function test_admin_can_reject_a_retrieval_request_without_changing_post_status(): void
    {
        $owner = $this->createUser('Owner', 'Member', 'retrieval-rejected-owner');
        $admin = $this->createUser('Portal', 'Admin', 'retrieval-rejected-admin', 'admin');
        $post = $this->createPost($owner, 'lost', 'Lost keys');

        Sanctum::actingAs($owner);
        $requestId = $this->postJson("/api/v1/posts/{$post->id}/retrieval-request")
            ->assertCreated()
            ->json('retrieval_request_id');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.retrieval-requests.decide', $requestId), [
                'decision' => 'reject',
                'reason' => 'Please provide more information.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('post_retrieval_requests', [
            'id' => $requestId,
            'status' => 'rejected',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $owner->id,
            'type' => 'post_retrieval_rejected',
        ]);
    }

    public function test_only_the_owner_can_load_private_edit_data_for_an_active_post(): void
    {
        $owner = $this->createUser('Owner', 'Member', 'edit-data-owner');
        $other = $this->createUser('Other', 'Member', 'edit-data-other');
        $post = $this->createPost($owner, 'found', 'Found umbrella');
        VerificationQuestion::create([
            'post_id' => $post->id,
            'question' => 'What is the mark on the handle?',
            'answer' => 'Silver star',
        ]);

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/my-posts/{$post->id}/edit-data")
            ->assertOk()
            ->assertJsonPath('item_name', 'Found umbrella')
            ->assertJsonPath('questions.0.question', 'What is the mark on the handle?')
            ->assertJsonPath('questions.0.answer', 'Silver star');

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/my-posts/{$post->id}/edit-data")
            ->assertNotFound();
    }

    private function createUser(string $firstName, string $lastName, string $suffix, string $role = 'user'): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'tmc_id_no' => strtoupper($suffix),
            'email' => $suffix.'@gmail.com',
            'password' => 'LongTestPassword!123',
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function createPost(User $owner, string $type, string $name): Post
    {
        $category = Category::firstOrCreate(
            ['name' => 'Retrieval Test Items'],
            ['is_active' => true],
        );
        $location = Location::firstOrCreate(
            ['name' => 'Retrieval Test Campus'],
            ['is_active' => true],
        );

        return Post::create([
            'user_id' => $owner->id,
            'type' => $type,
            'item_name' => $name,
            'description' => 'Details for '.$name,
            'category_id' => $category->id,
            'color' => 'Blue',
            'location_id' => $location->id,
            'event_date' => today(),
            'custody' => $type === 'found' ? 'with_me' : null,
            'status' => 'active',
        ]);
    }
}
