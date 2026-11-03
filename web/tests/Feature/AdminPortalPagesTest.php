<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Category;
use App\Models\Location;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Tests\TestCase;

class AdminPortalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_uses_the_campus_pastel_palette(): void
    {
        $this->app->make(Vite::class)->useHotFile(storage_path('framework/testing/missing-vite-hot-file'));

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('class="admin-login"', false)
            ->assertSee('class="login-card"', false)
            ->assertSee('var(--campus-primary)', false)
            ->assertSee('campuslf-admin-theme')
            ->assertDontSee('#7a0f18')
            ->assertDontSee('#a31621');
    }

    public function test_new_admin_pages_render_for_an_authenticated_admin(): void
    {
        $this->app->make(Vite::class)->useHotFile(storage_path('framework/testing/missing-vite-hot-file'));
        $admin = User::create([
            'first_name' => 'Portal',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-PAGES',
            'email' => 'admin-pages@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin, 'admin');

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Admin Home')
            ->assertSee('Community Feed')
            ->assertSee('Open Community Feed')
            ->assertDontSee('Browse all listings')
            ->assertDontSee('Item name or description')
            ->assertSee('Transactions')
            ->assertSee('Monitoring and Maintenance')
            ->assertSee('Feedback')
            ->assertSee('/build/assets/app-')
            ->assertDontSee('127.0.0.1:5173');

        foreach ([
            'admin.community-feed.index',
            'admin.handovers.history',
            'admin.claims.index',
            'admin.handovers.index',
            'admin.sightings.index',
            'admin.feedback.index',
            'admin.settings.index',
        ] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('admin.claims.index'))
            ->assertOk()
            ->assertSee('Found-item mark-as-retrieved requests');
        $this->get(route('admin.sightings.index'))
            ->assertOk()
            ->assertSee('Lost-item mark-as-retrieved requests');

        $this->get(route('admin.community-feed.index'))
            ->assertOk()
            ->assertSee('Browse community posts')
            ->assertSee('Posts')
            ->assertSee('People')
            ->assertSee('Category');

        $this->get(route('admin.community-feed.index', ['view' => 'people']))
            ->assertOk()
            ->assertSee('People are listed alphabetically by last name.')
            ->assertSee('Name or email')
            ->assertDontSee('Browse community posts');
    }

    public function test_admin_home_counts_found_posts_separately_from_pending_claims(): void
    {
        $admin = User::create([
            'first_name' => 'Found',
            'last_name' => 'Post Admin',
            'tmc_id_no' => 'ADMIN-FOUND-POST-COUNT',
            'email' => 'admin-found-post-count@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Found Count Category', 'is_active' => true]);
        $location = Location::create(['name' => 'Found Count Location', 'is_active' => true]);
        $this->createFeedPost($admin, $category, $location, 'found', 'active', 'First Found Post');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('<small>Found item posts</small><strong>1</strong>', false)
            ->assertSee(route('admin.community-feed.index', ['type' => 'found']), false)
            ->assertSee('<small>Found item claims</small><strong>0</strong>', false);
    }

    public function test_admin_home_shortcuts_keep_the_requested_order(): void
    {
        $admin = User::create([
            'first_name' => 'Portal',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-SHORTCUT-USAGE',
            'email' => 'admin-shortcuts@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($admin, 'admin');

        $this->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('settings-limits-grid')
            ->assertSee('settings-catalog-grid');
        $this->put(route('admin.settings.update'), [
            'posts_per_day' => 5,
            'claims_per_day' => 5,
            'claim_attempts' => 3,
            'report_threshold' => 3,
            'archive_days' => 30,
            'comments_per_day' => 30,
            'reports_per_day' => 5,
            'blocked_words' => '',
            'disclaimer' => 'CampusLF connects campus members with their lost items.',
        ])->assertRedirect();

        $this->assertDatabaseHas('admin_route_usage', [
            'admin_id' => $admin->id,
            'route_name' => 'admin.settings.index',
            'visits' => 1,
            'interactions' => 1,
        ]);

        $dashboard = $this->get(route('admin.dashboard'))
            ->assertOk();
        $shortcutStart = strpos($dashboard->getContent(), '<div class="shortcut-grid">');
        $this->assertNotFalse($shortcutStart);
        $shortcutMarkup = substr($dashboard->getContent(), $shortcutStart);
        $positions = array_map(
            fn (string $label): int|false => strpos($shortcutMarkup, '<strong>'.$label.'</strong>'),
            ['Users', 'Reports', 'Activity', 'Feedback'],
        );
        $this->assertTrue($positions[0] < $positions[1] && $positions[1] < $positions[2] && $positions[2] < $positions[3]);
        $this->assertStringNotContainsString('Found Item Claims', $shortcutMarkup);
    }

    public function test_admin_community_feed_applies_post_filters(): void
    {
        $admin = User::create([
            'first_name' => 'Feed',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-FEED-FILTER',
            'email' => 'admin-feed-filter@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Feed Bag', 'is_active' => true]);
        $otherCategory = Category::create(['name' => 'Feed Phone', 'is_active' => true]);
        $location = Location::create(['name' => 'Feed Library', 'is_active' => true]);

        $lostPost = $this->createFeedPost($admin, $category, $location, 'lost', 'active', 'Lost Wallet');
        $this->createFeedPost($admin, $category, $location, 'found', 'active', 'Found Wallet');
        $this->createFeedPost($admin, $category, $location, 'lost', 'hidden', 'Hidden Wallet');
        $this->createFeedPost($admin, $otherCategory, $location, 'lost', 'active', 'Lost Phone');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.community-feed.index', [
                'type' => 'lost',
                'status' => 'active',
                'category_id' => $category->id,
                'search' => 'Wallet',
                'sort' => 'oldest',
            ]))
            ->assertOk()
            ->assertSee('Apply filters')
            ->assertSee('Lost Wallet')
            ->assertDontSee('Found Wallet')
            ->assertDontSee('Hidden Wallet')
            ->assertDontSee('Lost Phone');

        $this->assertSame('lost', $lostPost->fresh()->type);
    }

    private function createFeedPost(
        User $author,
        Category $category,
        Location $location,
        string $type,
        string $status,
        string $itemName
    ): Post {
        return Post::create([
            'user_id' => $author->id,
            'type' => $type,
            'item_name' => $itemName,
            'description' => 'A feed test post for '.$itemName,
            'category_id' => $category->id,
            'color' => 'Black',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => $status,
        ]);
    }

    public function test_admin_created_users_require_gmail_and_can_specify_other_type(): void
    {
        $admin = User::create([
            'first_name' => 'Portal',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-USER-FORM',
            'email' => 'admin-user-form@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($admin, 'admin');

        $details = [
            'first_name' => 'New',
            'last_name' => 'Member',
            'tmc_id_no' => 'TMC-NEW-MEMBER',
            'email' => 'new-member@example.com',
            'user_type' => 'other',
            'user_type_other' => 'Visiting researcher',
            'department' => 'CCS',
            'password' => 'LongTestPassword!123',
            'password_confirmation' => 'LongTestPassword!123',
        ];
        $this->post(route('admin.users.store'), $details)
            ->assertSessionHasErrors('email');

        $details['email'] = 'new-member@gmail.com';
        $this->post(route('admin.users.store'), $details)
            ->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', [
            'email' => 'new-member@gmail.com',
            'user_type' => 'other',
            'user_type_other' => 'Visiting researcher',
            'department' => 'CCS',
        ]);
    }
}
