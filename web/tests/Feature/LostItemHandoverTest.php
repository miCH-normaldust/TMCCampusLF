<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ClaimRequest;
use App\Models\Conversation;
use App\Models\Location;
use App\Models\LostSighting;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LostItemHandoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_lost_sighting_can_be_handed_over_and_confirmed_by_both_participants(): void
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

        Sanctum::actingAs($finder);
        $this->getJson('/api/v1/handovers')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'lost')
            ->assertJsonPath('data.0.item_name', 'Blue notebook')
            ->assertJsonPath('data.0.other_first_name', 'Item')
            ->assertJsonPath('data.0.my_confirmation_at', null)
            ->assertJsonPath('data.0.completed', false);

        $outsider = $this->createUser('Outside', 'Member', 'outsider-handover');
        Sanctum::actingAs($outsider);
        $this->postJson("/api/v1/handovers/{$handoverId}/confirm")->assertNotFound();

        Sanctum::actingAs($finder);
        $this->postJson("/api/v1/handovers/{$handoverId}/confirm")
            ->assertOk()
            ->assertJsonPath('retrieved', false);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/handovers/{$handoverId}/confirm")
            ->assertOk()
            ->assertJsonPath('retrieved', true);

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => 'retrieved']);
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
            ->assertSee('Finder');

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
            ->assertDontSee('Meet-up location')
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
            'status' => 'approved',
            'author_decision' => 'pending',
        ]);
        $conversation = Conversation::create(['claim_id' => $claim->id]);

        Sanctum::actingAs($claimant);
        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertNotFound();
        Sanctum::actingAs($author);
        $this->getJson('/api/v1/claims/awaiting-post-author')
            ->assertOk()
            ->assertJsonPath('data.0.id', $claim->id)
            ->assertJsonPath('data.0.claimant_first_name', 'Item');

        $this->patchJson("/api/v1/claims/{$claim->id}/post-author-decision", [
            'decision' => 'approve',
        ])->assertOk()
            ->assertJsonPath('decision', 'approve');
        $this->assertDatabaseHas('handovers', ['claim_id' => $claim->id]);

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

        $this->postJson("/api/v1/handovers/{$handoverId}/confirm")
            ->assertOk()
            ->assertJsonPath('retrieved', false);

        Sanctum::actingAs($author);
        $this->postJson("/api/v1/handovers/{$handoverId}/confirm")
            ->assertOk()
            ->assertJsonPath('retrieved', true);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => 'retrieved']);
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
        ])->assertOk()
            ->assertJsonPath('decision', 'reject');

        $this->assertDatabaseHas('claim_requests', [
            'id' => $claim->id,
            'author_decision' => 'rejected',
        ]);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => 'active']);
        $this->assertDatabaseMissing('handovers', ['claim_id' => $claim->id]);
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
