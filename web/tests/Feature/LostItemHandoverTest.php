<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\LostSighting;
use App\Models\MeetupLocation;
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
        $owner = User::create([
            'first_name' => 'Item',
            'last_name' => 'Owner',
            'tmc_id_no' => 'TMC-LOST-OWNER',
            'email' => 'lost-owner@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $finder = User::create([
            'first_name' => 'Item',
            'last_name' => 'Finder',
            'tmc_id_no' => 'TMC-LOST-FINDER',
            'email' => 'lost-finder@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Accessories', 'is_active' => true]);
        $location = Location::create(['name' => 'Library', 'is_active' => true]);
        $meetup = MeetupLocation::create(['name' => 'TMC Main Office', 'is_active' => true]);
        $post = Post::create([
            'user_id' => $owner->id,
            'type' => 'lost',
            'item_name' => 'Blue notebook',
            'description' => 'Lost on campus.',
            'category_id' => $category->id,
            'color' => 'Blue',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'approved_for_meetup',
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
        $handover = $this->postJson("/api/v1/sightings/{$sighting->id}/handover", [
            'location_id' => $meetup->id,
        ])->assertCreated()
            ->assertJsonPath('location_id', $meetup->id);
        $handoverId = $handover->json('id');
        $code = $handover->json('code');

        Sanctum::actingAs($finder);
        $this->getJson('/api/v1/handovers')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'lost')
            ->assertJsonPath('data.0.item_name', 'Blue notebook')
            ->assertJsonPath('data.0.other_first_name', 'Item');

        $this->postJson("/api/v1/handovers/{$handoverId}/confirm", ['code' => $code])
            ->assertOk()
            ->assertJsonPath('retrieved', false);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/handovers/{$handoverId}/confirm", ['code' => $code])
            ->assertOk()
            ->assertJsonPath('retrieved', true);

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => 'retrieved']);
        $this->assertDatabaseHas('handovers', [
            'id' => $handoverId,
            'sighting_id' => $sighting->id,
            'claim_id' => null,
        ]);

        $admin = User::create([
            'first_name' => 'Portal',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-LOST-HANDOVER',
            'email' => 'lost-handover-admin@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($admin, 'admin')
            ->post(route('admin.handovers.verify'), ['code' => $code])
            ->assertRedirect()
            ->assertSessionHas('status', 'Handover verified and recorded in the audit trail.');
        $this->assertDatabaseHas('handovers', [
            'id' => $handoverId,
            'verified_by' => $admin->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.handovers.index'))
            ->assertOk()
            ->assertSee('Blue notebook')
            ->assertSee('Lost')
            ->assertSee('Owner')
            ->assertSee('Finder');
    }
}
