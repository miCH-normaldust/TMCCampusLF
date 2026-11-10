<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomPostCatalogValuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_can_use_custom_category_and_location_values(): void
    {
        $user = $this->createUser();
        $regularCategory = Category::create(['name' => 'Electronics', 'is_active' => true]);
        $regularLocation = Location::create(['name' => 'Library', 'is_active' => true]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/posts', [
            'type' => 'lost',
            'item_name' => 'Handmade notebook',
            'description' => 'A blue notebook with a fabric cover.',
            'category_other' => 'Handmade stationery',
            'color' => 'Blue',
            'location_other' => 'North garden bench',
            'event_date' => today()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.category', 'Handmade stationery')
            ->assertJsonPath('data.category_other', 'Handmade stationery')
            ->assertJsonPath('data.location', 'North garden bench')
            ->assertJsonPath('data.location_other', 'North garden bench');

        $postId = $response->json('data.id');
        $otherCategory = Category::where('name', 'Other')->firstOrFail();
        $otherLocation = Location::where('name', 'Other')->firstOrFail();
        $this->assertDatabaseHas('posts', [
            'id' => $postId,
            'category_id' => $otherCategory->id,
            'custom_category' => 'Handmade stationery',
            'location_id' => $otherLocation->id,
            'custom_location' => 'North garden bench',
        ]);

        $this->getJson("/api/v1/my-posts/{$postId}/edit-data")
            ->assertOk()
            ->assertJsonPath('category_other', 'Handmade stationery')
            ->assertJsonPath('location_other', 'North garden bench');

        $this->putJson("/api/v1/posts/{$postId}", [
            'type' => 'lost',
            'item_name' => 'Handmade notebook',
            'description' => 'A blue notebook with a fabric cover.',
            'category_id' => $regularCategory->id,
            'color' => 'Blue',
            'location_id' => $regularLocation->id,
            'event_date' => today()->toDateString(),
        ])->assertOk()
            ->assertJsonPath('data.category', 'Electronics')
            ->assertJsonPath('data.category_other', null)
            ->assertJsonPath('data.location', 'Library')
            ->assertJsonPath('data.location_other', null);

        $this->assertDatabaseHas('posts', [
            'id' => $postId,
            'category_id' => $regularCategory->id,
            'custom_category' => null,
            'location_id' => $regularLocation->id,
            'custom_location' => null,
        ]);
    }

    public function test_custom_category_and_location_are_required_when_catalog_ids_are_missing(): void
    {
        Sanctum::actingAs($this->createUser());

        $this->postJson('/api/v1/posts', [
            'type' => 'lost',
            'item_name' => 'Notebook',
            'description' => 'A notebook.',
            'color' => 'Blue',
            'event_date' => today()->toDateString(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'category_other', 'location_id', 'location_other']);
    }

    private function createUser(): User
    {
        return User::create([
            'first_name' => 'Custom',
            'last_name' => 'Poster',
            'tmc_id_no' => 'CUSTOM-POSTER',
            'email' => 'custom-poster@gmail.com',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
