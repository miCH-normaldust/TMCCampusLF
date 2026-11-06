<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ClaimRequest;
use App\Models\Location;
use App\Models\LostSighting;
use App\Models\Post;
use App\Models\PostRetrievalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InboxSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_feeds_include_all_received_and_sent_submissions_and_successful_retrievals(): void
    {
        $owner = $this->createUser('Inbox', 'Owner');
        $finder = $this->createUser('Inbox', 'Finder');
        $otherOwner = $this->createUser('Other', 'Owner');
        $category = Category::create(['name' => 'Inbox items', 'is_active' => true]);
        $location = Location::create(['name' => 'Inbox location', 'is_active' => true]);

        $ownerFoundPost = $this->createPost($owner, $category, $location, 'found');
        $incomingClaim = ClaimRequest::create([
            'post_id' => $ownerFoundPost->id,
            'claimant_id' => $finder->id,
            'answers' => ['matches'],
            'attempt_no' => 1,
            'status' => 'rejected',
            'author_decision' => 'rejected',
        ]);

        $ownerLostPost = $this->createPost($owner, $category, $location, 'lost');
        $incomingEvidence = LostSighting::create([
            'post_id' => $ownerLostPost->id,
            'user_id' => $finder->id,
            'answer' => 'yes',
            'evidence_text' => 'Recognized the item.',
            'author_decision' => 'approved',
            'admin_decision' => 'approved',
        ]);

        $otherFoundPost = $this->createPost($otherOwner, $category, $location, 'found');
        $outgoingClaim = ClaimRequest::create([
            'post_id' => $otherFoundPost->id,
            'claimant_id' => $owner->id,
            'answers' => ['my identifying detail'],
            'attempt_no' => 1,
            'status' => 'pending',
            'author_decision' => 'pending',
        ]);

        $otherLostPost = $this->createPost($otherOwner, $category, $location, 'lost');
        $outgoingEvidence = LostSighting::create([
            'post_id' => $otherLostPost->id,
            'user_id' => $owner->id,
            'answer' => 'yes',
            'evidence_text' => 'I found the item.',
            'author_decision' => 'pending',
            'admin_decision' => 'pending',
        ]);

        $retrieval = PostRetrievalRequest::create([
            'post_id' => $ownerLostPost->id,
            'user_id' => $owner->id,
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/received-claims')
            ->assertOk()
            ->assertJsonPath('data.0.id', $incomingClaim->id);
        $this->getJson('/api/v1/received-evidence')
            ->assertOk()
            ->assertJsonPath('data.0.id', $incomingEvidence->id);
        $this->getJson('/api/v1/my-claims')
            ->assertOk()
            ->assertJsonPath('data.0.id', $outgoingClaim->id)
            ->assertJsonPath('data.0.answers.0', 'my identifying detail');
        $this->getJson('/api/v1/my-sightings')
            ->assertOk()
            ->assertJsonPath('data.0.id', $outgoingEvidence->id)
            ->assertJsonPath('data.0.evidence_text', 'I found the item.');
        $this->getJson('/api/v1/retrieval-history')
            ->assertOk()
            ->assertJsonPath('data.0.id', $retrieval->id)
            ->assertJsonPath('data.0.history_kind', 'retrieval_request')
            ->assertJsonPath('data.0.status', 'completed');
    }

    private function createUser(string $firstName, string $lastName): User
    {
        $identifier = strtolower($firstName.'-'.$lastName.'-'.uniqid());

        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'tmc_id_no' => strtoupper('TMC-'.$identifier),
            'email' => $identifier.'@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
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
            'item_name' => 'Inbox workflow item',
            'description' => 'A sample item for inbox workflow tests.',
            'category_id' => $category->id,
            'color' => 'Blue',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'active',
        ]);
    }
}
