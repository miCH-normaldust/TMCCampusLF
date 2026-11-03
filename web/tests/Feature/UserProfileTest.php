<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_and_update_optional_profile_details(): void
    {
        $user = User::create([
            'first_name' => 'Campus',
            'last_name' => 'Member',
            'tmc_id_no' => 'PROFILE-1',
            'email' => 'profile@gmail.com',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('bio', null)
            ->assertJsonPath('profile_picture_url', null)
            ->assertJsonPath('current_address', null)
            ->assertJsonPath('permanent_address', null)
            ->assertJsonPath('contact_number', null)
            ->assertJsonPath('contact_email', null);

        $this->putJson('/api/v1/profile', [
            'first_name' => 'Campus',
            'last_name' => 'Member',
            'profile_status' => 'Student',
            'bio' => 'Finding lost items together.',
            'current_address' => 'Current campus address',
            'permanent_address' => 'Home address',
            'contact_number' => '09171234567',
            'contact_email' => 'contact@gmail.com',
        ])->assertOk()
            ->assertJsonPath('profile_status', 'Student')
            ->assertJsonPath('bio', 'Finding lost items together.')
            ->assertJsonPath('current_address', 'Current campus address')
            ->assertJsonPath('permanent_address', 'Home address')
            ->assertJsonPath('contact_number', '09171234567')
            ->assertJsonPath('contact_email', 'contact@gmail.com');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bio' => 'Finding lost items together.',
            'contact_email' => 'contact@gmail.com',
        ]);

        $this->putJson('/api/v1/profile', [
            'first_name' => 'Campus',
            'last_name' => 'Member',
            'profile_status' => '',
            'bio' => '',
            'current_address' => '',
            'permanent_address' => '',
            'contact_number' => '',
            'contact_email' => '',
        ])->assertOk()
            ->assertJsonPath('profile_status', null)
            ->assertJsonPath('bio', null)
            ->assertJsonPath('current_address', null)
            ->assertJsonPath('permanent_address', null)
            ->assertJsonPath('contact_number', null)
            ->assertJsonPath('contact_email', null);

        Storage::fake('public');
        $response = $this->post('/api/v1/profile', [
            'first_name' => 'Campus',
            'last_name' => 'Member',
            'profile_status' => 'Student',
            'bio' => 'Finding lost items together.',
            'current_address' => 'Current campus address',
            'permanent_address' => 'Home address',
            'contact_number' => '09171234567',
            'contact_email' => 'contact@gmail.com',
            'profile_picture' => UploadedFile::fake()->image('profile.gif'),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertNotNull($response->json('profile_picture_url'));
        $this->assertStringContainsString('/api/v1/media?path=', $response->json('profile_picture_url'));

        $user->refresh();
        $this->assertNotNull($user->profile_picture_path);
        $this->assertStringEndsWith('.png', $user->profile_picture_path);
        Storage::disk('public')->assertExists($user->profile_picture_path);
    }

    public function test_profile_image_storage_allows_flutter_web_cross_origin_requests(): void
    {
        Storage::fake('public');
        $path = 'profiles/2026/01/'.str_repeat('a', 8).'.png';
        Storage::disk('public')->put($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/pWQAAAAASUVORK5CYII=',
        ));

        $this->withHeader('Origin', 'http://localhost:59581')
            ->get('/api/v1/media?path='.rawurlencode($path))
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_public_member_profile_and_timeline_only_expose_visible_member_data(): void
    {
        $member = User::create([
            'first_name' => 'Public',
            'last_name' => 'Member',
            'tmc_id_no' => 'PUBLIC-PROFILE-1',
            'email' => 'public-profile@gmail.com',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'profile_status' => 'Student',
            'bio' => 'Finding items together.',
            'department' => 'Information Technology',
            'current_address' => 'Current campus address',
            'permanent_address' => 'Home address',
            'contact_number' => '09171234567',
            'contact_email' => 'contact@gmail.com',
            'profile_picture_path' => 'profiles/member.png',
            'email_verified_at' => now(),
        ]);
        $otherMember = User::create([
            'first_name' => 'Other',
            'last_name' => 'Member',
            'tmc_id_no' => 'PUBLIC-PROFILE-2',
            'email' => 'other-public-profile@gmail.com',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Public Profile Category', 'is_active' => true]);
        $location = Location::create(['name' => 'Public Profile Location', 'is_active' => true]);
        $visiblePost = $this->createPost($member, $category, $location, 'active', 'Visible item');
        $this->createPost($member, $category, $location, 'archived', 'Archived item');
        $this->createPost($otherMember, $category, $location, 'active', 'Other member item');
        Sanctum::actingAs($otherMember);

        $profileResponse = $this->getJson("/api/v1/people/{$member->id}")
            ->assertOk()
            ->assertJsonPath('id', $member->id)
            ->assertJsonPath('bio', 'Finding items together.')
            ->assertJsonPath('profile_status', 'Student')
            ->assertJsonPath('department', 'Information Technology')
            ->assertJsonPath('current_address', 'Current campus address')
            ->assertJsonPath('permanent_address', 'Home address')
            ->assertJsonPath('contact_number', '09171234567')
            ->assertJsonPath('contact_email', 'contact@gmail.com');
        $this->assertStringContainsString(
            '/api/v1/media?path=',
            $profileResponse->json('profile_picture_url'),
        );

        $postsResponse = $this->getJson("/api/v1/people/{$member->id}/posts")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visiblePost->id)
            ->assertJsonPath('data.0.author_id', $member->id)
            ->assertJsonPath('data.0.author_first_name', 'Public')
            ->assertJsonPath('data.0.author_last_name', 'Member');
        $this->assertStringContainsString(
            '/api/v1/media?path=',
            $postsResponse->json('data.0.author_profile_picture_url'),
        );
    }

    public function test_own_posts_can_be_filtered_without_exposing_other_users_posts(): void
    {
        $owner = User::create([
            'first_name' => 'Campus',
            'last_name' => 'Member',
            'tmc_id_no' => 'PROFILE-2',
            'email' => 'profile2@gmail.com',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $otherUser = User::create([
            'first_name' => 'Another',
            'last_name' => 'Member',
            'tmc_id_no' => 'PROFILE-3',
            'email' => 'profile3@gmail.com',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Accessories', 'is_active' => true]);
        $location = Location::create(['name' => 'Library', 'is_active' => true]);
        $active = $this->createPost($owner, $category, $location, 'active', 'Blue bag');
        $retrieved = $this->createPost($owner, $category, $location, 'retrieved', 'Red wallet');
        $this->createPost($owner, $category, $location, 'archived', 'Old book');
        $this->createPost($otherUser, $category, $location, 'active', 'Other member bag');
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/my-posts?status=active&category_id='.$category->id.'&search=Blue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.category_id', $category->id);

        $this->getJson('/api/v1/my-posts?status=retrieved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $retrieved->id);
    }

    public function test_community_feed_filters_and_sorts_posts_and_people_alphabetically(): void
    {
        $user = User::create([
            'first_name' => 'Zoe',
            'last_name' => 'Young',
            'tmc_id_no' => 'PROFILE-PEOPLE-1',
            'email' => 'zoe@gmail.com',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        User::create([
            'first_name' => 'Amy',
            'last_name' => 'Adams',
            'tmc_id_no' => 'PROFILE-PEOPLE-2',
            'email' => 'amy@gmail.com',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Community Feed Items', 'is_active' => true]);
        $location = Location::create(['name' => 'Library', 'is_active' => true]);
        $older = $this->createPost($user, $category, $location, 'active', 'Older item');
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $newer = $this->createPost($user, $category, $location, 'active', 'Newer item');
        $pending = $this->createPost($user, $category, $location, 'handover_pending', 'Pending item');
        $this->getJson('/api/v1/posts')->assertUnauthorized();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/posts?status=active&category_id='.$category->id.'&sort=oldest')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('data.1.id', $newer->id)
            ->assertJsonPath('data.0.comments_count', 0)
            ->assertJsonPath('data.0.reactions_count', 0)
            ->assertJsonPath('data.0.is_mine', true)
            ->assertJsonStructure(['data' => [['created_at']]]);

        $this->getJson('/api/v1/posts?status=handover_pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $pending->id);

        $this->getJson('/api/v1/posts?search=Zoe')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/v1/people')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Amy Adams')
            ->assertJsonPath('data.1.name', 'Zoe Young')
            ->assertJsonMissingPath('data.0.email');
    }

    public function test_own_posts_can_be_sorted_oldest_or_most_recent_first(): void
    {
        $owner = User::create([
            'first_name' => 'Sort',
            'last_name' => 'Member',
            'tmc_id_no' => 'PROFILE-SORT',
            'email' => 'profile-sort@gmail.com',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Sorted Items', 'is_active' => true]);
        $location = Location::create(['name' => 'Sorting Library', 'is_active' => true]);
        $older = $this->createPost($owner, $category, $location, 'active', 'Older post');
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $newer = $this->createPost($owner, $category, $location, 'active', 'Newer post');
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/my-posts?sort=oldest')
            ->assertOk()
            ->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('data.1.id', $newer->id);
        $this->getJson('/api/v1/my-posts?sort=latest')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    private function createPost(User $user, Category $category, Location $location, string $status, string $name): Post
    {
        return Post::create([
            'user_id' => $user->id,
            'type' => 'lost',
            'item_name' => $name,
            'description' => 'A description for '.$name,
            'category_id' => $category->id,
            'color' => 'Blue',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => $status,
        ]);
    }
}
