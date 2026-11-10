<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Post;
use App\Models\User;
use App\Models\UserModerationRestriction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_user_profile_and_apply_reasoned_scoped_bans(): void
    {
        $this->app->make(Vite::class)->useHotFile(storage_path('framework/testing/missing-vite-hot-file'));
        $admin = $this->createUser('Profile', 'Admin', 'profile-admin', 'admin');
        $user = $this->createUser('Campus', 'Member', 'profile-member');
        $recipient = $this->createUser('Other', 'Member', 'profile-recipient');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee(route('admin.users.show', $user), false)
            ->assertDontSee('>Suspend</button>', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSee('Moderation action')
            ->assertSee('Lost-item posting')
            ->assertSee('Messaging')
            ->assertDontSee('name="status" value="suspended"', false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.moderate', $user), [
                'action' => 'ban',
                'reason' => 'Repeated policy violations.',
                'scopes' => ['posting_lost', 'posting_found', 'comments', 'reactions', 'messaging'],
                'messaging_target' => 'specific',
                'messaging_user_id' => $recipient->id,
            ])
            ->assertRedirect(route('admin.users.show', $user));

        foreach (['posting_lost', 'posting_found', 'comments', 'reactions'] as $scope) {
            $this->assertDatabaseHas('user_moderation_restrictions', [
                'user_id' => $user->id,
                'scope' => $scope,
                'target_user_id' => null,
                'reason' => 'Repeated policy violations.',
                'lifted_at' => null,
            ]);
        }
        $this->assertDatabaseHas('user_moderation_restrictions', [
            'user_id' => $user->id,
            'scope' => 'messaging',
            'target_user_id' => $recipient->id,
            'reason' => 'Repeated policy violations.',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'type' => 'account_moderation',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'target_id' => $user->id,
            'action' => 'user_banned',
        ]);
    }

    public function test_admin_warning_and_suspension_require_reasons_and_are_audited(): void
    {
        $admin = $this->createUser('Status', 'Admin', 'status-admin', 'admin');
        $user = $this->createUser('Status', 'Member', 'status-member');
        $this->actingAs($admin, 'admin');

        $this->post(route('admin.users.moderate', $user), ['action' => 'warn'])
            ->assertSessionHasErrors('reason');
        $this->post(route('admin.users.moderate', $user), [
            'action' => 'warn',
            'reason' => 'Please follow the community guidelines.',
        ])->assertRedirect();
        $this->post(route('admin.users.moderate', $user), [
            'action' => 'suspend',
            'reason' => 'Repeated violations after a warning.',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'target_id' => $user->id,
            'action' => 'user_warned',
            'reason' => 'Please follow the community guidelines.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'target_id' => $user->id,
            'action' => 'user_suspended',
            'reason' => 'Repeated violations after a warning.',
        ]);
        $this->app->make(Vite::class)->useHotFile(storage_path('framework/testing/missing-vite-hot-file'));
        $this->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSee('Please follow the community guidelines.')
            ->assertSee('Repeated violations after a warning.');
        $this->post(route('admin.users.moderate', $user), [
            'action' => 'restore',
            'reason' => 'The suspension period is complete.',
        ])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'active']);
    }

    public function test_scoped_restrictions_are_enforced_without_blocking_unrestricted_actions(): void
    {
        $owner = $this->createUser('Post', 'Owner', 'restriction-owner');
        $banned = $this->createUser('Restricted', 'Member', 'restriction-member');
        $recipient = $this->createUser('Restricted', 'Recipient', 'restriction-recipient');
        $otherRecipient = $this->createUser('Other', 'Recipient', 'restriction-other-recipient');
        $category = Category::create(['name' => 'Restriction Category', 'is_active' => true]);
        $location = Location::create(['name' => 'Restriction Location', 'is_active' => true]);
        $post = Post::create([
            'user_id' => $owner->id,
            'type' => 'lost',
            'item_name' => 'Lost keys',
            'description' => 'A set of keys.',
            'category_id' => $category->id,
            'color' => 'Silver',
            'location_id' => $location->id,
            'event_date' => today(),
            'status' => 'active',
        ]);
        UserModerationRestriction::create([
            'user_id' => $banned->id,
            'created_by' => $owner->id,
            'scope' => 'posting_lost',
            'reason' => 'Lost post restriction.',
        ]);
        UserModerationRestriction::create([
            'user_id' => $banned->id,
            'created_by' => $owner->id,
            'scope' => 'comments',
            'reason' => 'Comment restriction.',
        ]);
        UserModerationRestriction::create([
            'user_id' => $banned->id,
            'created_by' => $owner->id,
            'scope' => 'reactions',
            'reason' => 'Reaction restriction.',
        ]);
        UserModerationRestriction::create([
            'user_id' => $banned->id,
            'created_by' => $owner->id,
            'target_user_id' => $recipient->id,
            'scope' => 'messaging',
            'reason' => 'Direct message restriction.',
        ]);

        Sanctum::actingAs($banned);
        $this->postJson('/api/v1/posts', [
            'type' => 'lost',
            'item_name' => 'Lost wallet',
            'description' => 'A brown wallet.',
            'category_id' => $category->id,
            'color' => 'Brown',
            'location_id' => $location->id,
            'event_date' => today()->toDateString(),
        ])->assertForbidden();
        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'I saw this.'])
            ->assertForbidden();
        $this->postJson("/api/v1/posts/{$post->id}/reactions", ['type' => 'like'])
            ->assertForbidden();
        $this->postJson("/api/v1/posts/{$post->id}/reactions", ['type' => 'none'])
            ->assertOk();
        $this->postJson('/api/v1/conversations/direct', ['recipient_id' => $recipient->id])
            ->assertForbidden();
        $this->postJson('/api/v1/conversations/direct', ['recipient_id' => $otherRecipient->id])
            ->assertOk();
    }

    public function test_lifting_a_restriction_requires_a_reason_and_restores_the_scope(): void
    {
        $admin = $this->createUser('Lift', 'Admin', 'lift-admin', 'admin');
        $user = $this->createUser('Lift', 'Member', 'lift-member');
        $restriction = UserModerationRestriction::create([
            'user_id' => $user->id,
            'created_by' => $admin->id,
            'scope' => 'comments',
            'reason' => 'Original ban reason.',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.restrictions.lift', [$user, $restriction]), [
                'reason' => 'The restriction period is complete.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_moderation_restrictions', [
            'id' => $restriction->id,
            'lifted_by' => $admin->id,
            'lift_reason' => 'The restriction period is complete.',
        ]);
    }

    private function createUser(string $firstName, string $lastName, string $key, string $role = 'user'): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'tmc_id_no' => strtoupper($key),
            'email' => $key.'@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
