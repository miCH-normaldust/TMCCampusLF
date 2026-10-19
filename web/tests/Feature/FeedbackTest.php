<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_feedback_is_saved_and_notifies_active_administrators(): void
    {
        $member = User::create([
            'first_name' => 'TMC',
            'last_name' => 'Member',
            'tmc_id_no' => 'TMC-FEEDBACK',
            'email' => 'feedback-member@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $admin = User::create([
            'first_name' => 'Portal',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-FEEDBACK',
            'email' => 'feedback-admin@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($member);

        $response = $this->postJson('/api/v1/feedback', [
            'category' => 'suggestion',
            'message' => 'Please add a way to filter by item category.',
        ])->assertCreated()
            ->assertJsonPath('message', 'Thank you. Your feedback was sent to TMC administrators.');

        $this->assertDatabaseHas('feedback', [
            'id' => $response->json('id'),
            'user_id' => $member->id,
            'category' => 'suggestion',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $admin->id,
            'type' => 'new_feedback',
        ]);
    }
}
