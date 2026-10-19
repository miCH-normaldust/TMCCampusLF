<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPortalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_admin_pages_render_for_an_authenticated_admin(): void
    {
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
            ->assertSee('/build/assets/app-')
            ->assertDontSee('127.0.0.1:5173');

        foreach ([
            'admin.community-feed.index',
            'admin.claims.index',
            'admin.handovers.index',
            'admin.feedback.index',
        ] as $route) {
            $this->get(route($route))->assertOk();
        }
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
