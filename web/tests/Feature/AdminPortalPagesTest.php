<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Category;
use App\Models\ClaimRequest;
use App\Models\LostSighting;
use App\Models\Location;
use App\Models\MeetupLocation;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\UploadedFile;
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

    public function test_expired_admin_form_redirects_to_login_instead_of_showing_page_expired(): void
    {
        $request = Request::create('/admin/logout', 'POST');
        $response = $this->app->make(ExceptionHandler::class)
            ->render($request, new TokenMismatchException('CSRF token mismatch.'));

        $this->assertSame(route('admin.login'), $response->headers->get('Location'));
        $this->assertSame(
            'Your admin session expired. Please sign in again.',
            $this->app->make('session')->driver()->get('status'),
        );
    }

    public function test_authenticated_admin_can_still_log_out_with_post(): void
    {
        $admin = User::create([
            'first_name' => 'Portal',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-LOGOUT',
            'email' => 'admin-logout@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('admin');
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'event' => 'admin_logout',
        ]);
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

    public function test_lost_item_proof_images_open_in_a_pop_up_viewer(): void
    {
        $this->app->make(Vite::class)->useHotFile(storage_path('framework/testing/missing-vite-hot-file'));
        $admin = User::create([
            'first_name' => 'Proof',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-PROOF-VIEWER',
            'email' => 'admin-proof-viewer@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $owner = User::create([
            'first_name' => 'Proof',
            'last_name' => 'Owner',
            'tmc_id_no' => 'USER-PROOF-OWNER',
            'email' => 'proof-owner@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $witness = User::create([
            'first_name' => 'Proof',
            'last_name' => 'Witness',
            'tmc_id_no' => 'USER-PROOF-WITNESS',
            'email' => 'proof-witness@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Proof Category', 'is_active' => true]);
        $location = Location::create(['name' => 'Proof Location', 'is_active' => true]);
        $post = $this->createFeedPost($owner, $category, $location, 'lost', 'active', 'Lost Umbrella');
        LostSighting::create([
            'post_id' => $post->id,
            'user_id' => $witness->id,
            'answer' => 'yes',
            'evidence_text' => 'Seen near the library entrance.',
            'evidence_image' => 'proofs/umbrella.jpg',
            'author_decision' => 'approved',
            'admin_decision' => 'pending',
        ]);
        $proofUrl = url('/api/v1/media?path=proofs%2Fumbrella.jpg');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.sightings.index'))
            ->assertOk()
            ->assertSee('View image')
            ->assertSee('post_id='.$post->id, false)
            ->assertSee('View original post')
            ->assertSee('data-proof-src="'.$proofUrl.'"', false)
            ->assertSee('proof-image-modal', false)
            ->assertSee('image-modal-body', false)
            ->assertDontSee('<img src="'.$proofUrl.'"', false);
    }

    public function test_admin_claims_and_lost_item_proofs_are_grouped_by_origin_post(): void
    {
        $this->app->make(Vite::class)->useHotFile(storage_path('framework/testing/missing-vite-hot-file'));
        $admin = User::create([
            'first_name' => 'Grouped',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-GROUPED-POSTS',
            'email' => 'admin-grouped-posts@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $owner = User::create([
            'first_name' => 'Grouped',
            'last_name' => 'Owner',
            'tmc_id_no' => 'USER-GROUPED-OWNER',
            'email' => 'grouped-owner@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $member = User::create([
            'first_name' => 'Grouped',
            'last_name' => 'Member',
            'tmc_id_no' => 'USER-GROUPED-MEMBER',
            'email' => 'grouped-member@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Grouped Category', 'is_active' => true]);
        $location = Location::create(['name' => 'Grouped Location', 'is_active' => true]);
        $foundPost = $this->createFeedPost($owner, $category, $location, 'found', 'active', 'Grouped Notebook');
        $lostPost = $this->createFeedPost($owner, $category, $location, 'lost', 'active', 'Grouped Umbrella');

        ClaimRequest::create([
            'post_id' => $foundPost->id,
            'claimant_id' => $member->id,
            'answers' => ['blue cover'],
            'attempt_no' => 1,
            'status' => 'pending',
            'author_decision' => 'pending',
        ]);
        ClaimRequest::create([
            'post_id' => $foundPost->id,
            'claimant_id' => $member->id,
            'answers' => ['blue cover'],
            'attempt_no' => 2,
            'status' => 'pending',
            'author_decision' => 'pending',
        ]);
        foreach ([
            ['pending', 'pending'],
            ['approved', 'pending'],
            ['approved', 'approved'],
        ] as [$authorDecision, $adminDecision]) {
            LostSighting::create([
                'post_id' => $lostPost->id,
                'user_id' => $member->id,
                'answer' => 'yes',
                'evidence_text' => 'Seen near the library.',
                'author_decision' => $authorDecision,
                'admin_decision' => $adminDecision,
            ]);
        }

        $this->actingAs($admin, 'admin');

        $claimsResponse = $this->get(route('admin.claims.index'))->assertOk();
        $this->assertSame(1, substr_count($claimsResponse->getContent(), 'data-claim-group="'.$foundPost->id.'"'));
        $this->assertSame(2, substr_count($claimsResponse->getContent(), 'data-claim-id='));

        $proofsResponse = $this->get(route('admin.sightings.index'))->assertOk();
        $this->assertSame(1, substr_count($proofsResponse->getContent(), 'data-proof-group="'.$lostPost->id.'"'));
        $this->assertSame(3, substr_count($proofsResponse->getContent(), 'data-sighting-id='));
        $this->assertStringContainsString('Awaiting owner review', $proofsResponse->getContent());
        $this->assertStringContainsString('Awaiting administrator review', $proofsResponse->getContent());
        $this->assertStringContainsString('Approved by administrator', $proofsResponse->getContent());

        $pendingProofsResponse = $this->get(route('admin.sightings.index', ['status' => 'pending']))->assertOk();
        $this->assertSame(2, substr_count($pendingProofsResponse->getContent(), 'data-sighting-id='));
        $approvedProofsResponse = $this->get(route('admin.sightings.index', ['status' => 'approved']))->assertOk();
        $this->assertSame(1, substr_count($approvedProofsResponse->getContent(), 'data-sighting-id='));
        $rejectedProofsResponse = $this->get(route('admin.sightings.index', ['status' => 'rejected']))->assertOk();
        $this->assertSame(0, substr_count($rejectedProofsResponse->getContent(), 'data-sighting-id='));
    }

    public function test_admin_home_counts_posts_and_claims_for_their_destination_pages(): void
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
        $foundPost = $this->createFeedPost($admin, $category, $location, 'found', 'active', 'First Found Post');
        $this->createFeedPost($admin, $category, $location, 'lost', 'active', 'First Lost Post');
        $claimant = User::create([
            'first_name' => 'TMC',
            'last_name' => 'Claimant',
            'tmc_id_no' => 'TMC-COUNT-CLAIMANT',
            'email' => 'claimant-count@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        ClaimRequest::create([
            'post_id' => $foundPost->id,
            'claimant_id' => $claimant->id,
            'answers' => ['private answer'],
            'attempt_no' => 1,
            'status' => 'approved',
            'author_decision' => 'pending',
        ]);
        foreach ([
            ['pending', 'pending'],
            ['approved', 'approved'],
            ['rejected', 'rejected'],
        ] as $index => [$ownerDecision, $adminDecision]) {
            LostSighting::create([
                'post_id' => Post::where('type', 'lost')->firstOrFail()->id,
                'user_id' => $claimant->id,
                'answer' => 'yes',
                'evidence_text' => 'Evidence state '.($index + 1),
                'author_decision' => $ownerDecision,
                'admin_decision' => $adminDecision,
            ]);
        }
        LostSighting::create([
            'post_id' => Post::where('type', 'lost')->firstOrFail()->id,
            'user_id' => $claimant->id,
            'answer' => 'no',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('<small>Found item posts</small><strong>1</strong>', false)
            ->assertSee(route('admin.community-feed.index', ['type' => 'found']), false)
            ->assertSee('<small>Lost item posts</small><strong>1</strong>', false)
            ->assertSee(route('admin.community-feed.index', ['type' => 'lost']), false)
            ->assertSee('<small>Found item claims</small><strong>1</strong>', false)
            ->assertSee('<small>Lost item proofs</small><strong>3</strong>', false);
    }

    public function test_deleted_report_actions_remain_available_in_report_history(): void
    {
        $admin = User::create([
            'first_name' => 'Report',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-REPORT-HISTORY',
            'email' => 'admin-report-history@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $reporter = User::create([
            'first_name' => 'Report',
            'last_name' => 'Member',
            'tmc_id_no' => 'TMC-REPORT-MEMBER',
            'email' => 'report-member@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Report History Category', 'is_active' => true]);
        $location = Location::create(['name' => 'Report History Location', 'is_active' => true]);
        $post = $this->createFeedPost($reporter, $category, $location, 'found', 'active', 'Removed report post');
        $report = Report::create([
            'post_id' => $post->id,
            'reporter_id' => $reporter->id,
            'reason' => 'This item should not be listed.',
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.reports.decide', $report), [
                'action' => 'delete',
                'reason' => 'Confirmed prohibited listing.',
            ])->assertRedirect();

        $this->assertDatabaseHas('reports', [
            'id' => $report->id,
            'post_id' => null,
            'status' => 'actioned',
            'action' => 'delete',
        ]);
        $this->get(route('admin.reports.index', ['status' => 'actioned']))
            ->assertOk()
            ->assertSee('Post no longer available')
            ->assertSee('Confirmed prohibited listing.')
            ->assertSee('Delete');
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
            ->assertSee('I Found It per day')
            ->assertSee('settings-catalog-grid');
        $this->put(route('admin.settings.update'), [
            'posts_per_day' => 5,
            'claims_per_day' => 5,
            'found_it_per_day' => 4,
            'claim_attempts' => 3,
            'proof_attempts' => 3,
            'handover_reschedule_attempts' => 2,
            'report_threshold' => 3,
            'archive_days' => 30,
            'comments_per_day' => 30,
            'reports_per_day' => 5,
            'blocked_words' => '',
            'disclaimer' => 'CampusLF connects campus members with their lost items.',
        ])->assertRedirect();
        $this->assertDatabaseHas('settings', [
            'key' => 'found_it_per_day',
            'value' => '4',
        ]);

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

    public function test_admin_can_manage_handover_places_from_settings(): void
    {
        $admin = User::create([
            'first_name' => 'Places',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-HANDOVER-PLACES',
            'email' => 'admin-handover-places@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($admin, 'admin');

        $this->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Handover places');

        $this->post(route('admin.settings.handover-locations.store'), [
            'name' => 'North Campus Desk',
            'description' => 'Near the north entrance.',
            'operating_hours' => 'Weekdays, 9:00 AM - 4:00 PM',
        ])->assertRedirect();

        $place = MeetupLocation::where('name', 'North Campus Desk')->firstOrFail();
        $this->assertTrue($place->is_active);
        $this->assertSame('Near the north entrance.', $place->description);
        $this->assertSame('Weekdays, 9:00 AM - 4:00 PM', $place->operating_hours);

        $this->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('North Campus Desk')
            ->assertSee('Active');

        $this->patch(route('admin.settings.handover-locations.status', $place))
            ->assertRedirect();
        $this->assertFalse($place->fresh()->is_active);

        $this->post(route('admin.settings.handover-locations.store'), [
            'name' => 'North Campus Desk',
        ])->assertSessionHasErrors('name');

        $this->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('North Campus Desk')
            ->assertSee('Inactive');

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'handover_location_created',
            'reason' => 'North Campus Desk',
        ]);
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
            ->assertSessionHasErrors('tmc_id_no');
        $this->assertDatabaseMissing('users', ['tmc_id_no' => 'TMC-NEW-MEMBER']);

        DB::table('tmc_masterlist')->insert([
            'tmc_id_no' => 'TMC-NEW-MEMBER',
            'full_name' => 'New Member',
            'email' => 'new-member@gmail.com',
            'user_type' => 'other',
            'user_type_other' => 'Visiting researcher',
            'department' => 'CCS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->post(route('admin.users.store'), $details)
            ->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', [
            'email' => 'new-member@gmail.com',
            'user_type' => 'other',
            'user_type_other' => 'Visiting researcher',
            'department' => 'CCS',
        ]);
    }

    public function test_admin_created_user_details_must_match_the_masterlist(): void
    {
        $admin = User::create([
            'first_name' => 'Portal',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-MASTERLIST-CHECK',
            'email' => 'admin-masterlist-check@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        DB::table('tmc_masterlist')->insert([
            'tmc_id_no' => 'TMC-MASTERLIST-MEMBER',
            'full_name' => 'Masterlist Member',
            'email' => 'masterlist-member@gmail.com',
            'user_type' => 'student',
            'user_type_other' => null,
            'department' => 'CCS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.store'), [
                'first_name' => 'Unlisted',
                'last_name' => 'Person',
                'tmc_id_no' => 'TMC-MASTERLIST-MEMBER',
                'email' => 'masterlist-member@gmail.com',
                'user_type' => 'student',
                'department' => 'CCS',
                'password' => 'LongTestPassword!123',
                'password_confirmation' => 'LongTestPassword!123',
            ])
            ->assertSessionHasErrors('first_name');

        $this->assertDatabaseMissing('users', ['tmc_id_no' => 'TMC-MASTERLIST-MEMBER']);
    }

    public function test_masterlist_import_accepts_excel_other_header_and_updates_existing_records(): void
    {
        $admin = User::create([
            'first_name' => 'Portal',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-MASTERLIST-CSV',
            'email' => 'admin-masterlist-csv@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        DB::table('tmc_masterlist')->insert([
            'tmc_id_no' => 'TMC-CSV-UPDATE',
            'full_name' => 'Old Member Name',
            'email' => 'old-member@gmail.com',
            'user_type' => 'student',
            'user_type_other' => null,
            'department' => 'CCS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $csv = "\xEF\xBB\xBFtmc_id_no,full_name,email,user_type,other,department\r\n"
            ."TMC-CSV-UPDATE,Updated Member Name,updated-member@gmail.com,other,Visiting Faculty,CAS\r\n"
            ."TMC-CSV-NEW,New Member Name,new-member@gmail.com,student,,BSED\r\n\r\n";

        $this->actingAs($admin, 'admin')
            ->post(route('admin.masterlist.import'), [
                'masterlist' => UploadedFile::fake()->createWithContent('masterlist.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('2 valid row(s) will be added or updated.')
            ->assertSee('Other: Visiting Faculty');

        $this->assertDatabaseHas('tmc_masterlist', [
            'tmc_id_no' => 'TMC-CSV-UPDATE',
            'full_name' => 'Old Member Name',
            'email' => 'old-member@gmail.com',
        ]);

        $this->post(route('admin.masterlist.confirm'))
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('tmc_masterlist', [
            'tmc_id_no' => 'TMC-CSV-UPDATE',
            'full_name' => 'Updated Member Name',
            'email' => 'updated-member@gmail.com',
            'user_type' => 'other',
            'user_type_other' => 'Visiting Faculty',
            'department' => 'CAS',
        ]);
        $this->assertDatabaseHas('tmc_masterlist', [
            'tmc_id_no' => 'TMC-CSV-NEW',
            'full_name' => 'New Member Name',
            'email' => 'new-member@gmail.com',
            'department' => 'BSED',
        ]);
    }

    public function test_masterlist_import_rejects_email_assigned_to_a_different_tmc_id(): void
    {
        $admin = $this->createMasterlistAdmin();
        DB::table('tmc_masterlist')->insert([
            'tmc_id_no' => 'TMC-CSV-EXISTING',
            'full_name' => 'Existing Member',
            'email' => 'member@gmail.com',
            'user_type' => 'student',
            'department' => 'CCS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $csv = "tmc_id_no,full_name,email,user_type,other,department\r\n"
            ."TMC-CSV-NEW,New Member,member@gmail.com,student,,CCS\r\n";

        $this->actingAs($admin, 'admin')
            ->from(route('admin.users.index'))
            ->post(route('admin.masterlist.import'), [
                'masterlist' => UploadedFile::fake()->createWithContent('masterlist.csv', $csv),
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('masterlist');

        $this->assertDatabaseMissing('tmc_masterlist', ['tmc_id_no' => 'TMC-CSV-NEW']);
    }

    public function test_masterlist_confirmation_handles_email_conflict_created_after_preview(): void
    {
        $admin = $this->createMasterlistAdmin();
        $csv = "tmc_id_no,full_name,email,user_type,other,department\r\n"
            ."TMC-CSV-NEW,New Member,new-member@gmail.com,student,,CCS\r\n";

        $this->actingAs($admin, 'admin')
            ->post(route('admin.masterlist.import'), [
                'masterlist' => UploadedFile::fake()->createWithContent('masterlist.csv', $csv),
            ])
            ->assertOk();

        DB::table('tmc_masterlist')->insert([
            'tmc_id_no' => 'TMC-CSV-OTHER',
            'full_name' => 'Other Member',
            'email' => 'new-member@gmail.com',
            'user_type' => 'student',
            'department' => 'CAS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post(route('admin.masterlist.confirm'))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('masterlist')
            ->assertSessionHas('masterlist_import_preview');

        $this->assertDatabaseMissing('tmc_masterlist', ['tmc_id_no' => 'TMC-CSV-NEW']);
    }

    public function test_direct_get_to_masterlist_confirmation_redirects_with_guidance(): void
    {
        $this->actingAs($this->createMasterlistAdmin(), 'admin')
            ->get('/admin/masterlist/confirm')
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('masterlist');
    }

    private function createMasterlistAdmin(): User
    {
        return User::create([
            'first_name' => 'Portal',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-MASTERLIST-CSV',
            'email' => 'admin-masterlist-csv@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
