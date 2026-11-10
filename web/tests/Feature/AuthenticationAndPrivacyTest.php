<?php

namespace Tests\Feature;

use App\Mail\OneTimeCode;
use App\Models\Category;
use App\Models\Location;
use App\Models\OtpCode;
use App\Models\Post;
use App\Models\PostImage;
use App\Models\User;
use App\Models\VerificationQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class AuthenticationAndPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_sign_in_through_the_mobile_api(): void
    {
        User::create([
            'first_name' => 'Portal',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-1',
            'email' => 'admin@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/v1/login', [
            'email' => 'admin@tmc.test',
            'password' => 'LongTestPassword!123',
        ])->assertForbidden()->assertExactJson(['message' => 'Admins use the web portal.']);
    }

    public function test_user_account_cannot_sign_in_through_the_admin_portal(): void
    {
        User::create([
            'first_name' => 'TMC',
            'last_name' => 'Member',
            'tmc_id_no' => 'TMC-100',
            'email' => 'member@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->post('/admin/login', [
            'email' => 'member@tmc.test',
            'password' => 'LongTestPassword!123',
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('email');
    }

    public function test_admin_can_reset_password_with_a_one_time_email_code(): void
    {
        Mail::fake();
        $admin = User::create([
            'first_name' => 'Recovery',
            'last_name' => 'Admin',
            'tmc_id_no' => 'ADMIN-RECOVERY',
            'email' => 'campuslflostfound@gmail.com',
            'password' => 'OldLongPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->post(route('admin.password.email'), ['email' => $admin->email])
            ->assertRedirect(route('admin.password.request', ['reset' => 1, 'email' => $admin->email]));
        Mail::assertSent(OneTimeCode::class, fn (OneTimeCode $mail): bool => $mail->hasTo($admin->email) && $mail->purpose === 'password_reset');
        $code = Mail::sent(OneTimeCode::class)->first()->code;

        $this->post(route('admin.password.update'), [
            'email' => $admin->email,
            'code' => $code,
            'password' => 'NewLongPassword!456',
            'password_confirmation' => 'NewLongPassword!456',
        ])->assertRedirect(route('admin.login'));

        $this->assertTrue(Hash::check('NewLongPassword!456', $admin->fresh()->password));
    }

    public function test_invalid_email_otp_is_rejected_and_attempt_is_counted(): void
    {
        $user = User::create([
            'first_name' => 'TMC',
            'last_name' => 'Member',
            'tmc_id_no' => 'TMC-101',
            'email' => 'member2@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
        ]);
        $otp = OtpCode::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make('123456'),
            'purpose' => 'registration',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/v1/verify-otp', [
            'email' => $user->email,
            'code' => '654321',
        ])->assertUnprocessable()->assertJsonPath('message', 'The code is incorrect.');

        $this->assertDatabaseHas('otp_codes', ['id' => $otp->id, 'attempts' => 1]);
    }

    public function test_registration_does_not_require_a_security_challenge(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
        DB::table('settings')->updateOrInsert(
            ['key' => 'tmc_email_domain'],
            ['value' => 'gmail.com', 'created_at' => now(), 'updated_at' => now()],
        );
        $this->postJson('/api/v1/register', [
            'first_name' => 'TMC',
            'last_name' => 'Member',
            'tmc_id_no' => 'TMC-CAPTCHA-1',
            'email' => 'captcha-member@gmail.com',
            'user_type' => 'student',
            'department' => 'CCS',
            'password' => 'LongTestPassword!123',
            'password_confirmation' => 'LongTestPassword!123',
            'terms_accepted' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'captcha-member@gmail.com']);
        $this->getJson('/api/v1/captcha')->assertNotFound();
    }

    public function test_registration_does_not_expose_mail_transport_errors_or_keep_partial_account(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $email = 'mail-failure@gmail.com';
        Mail::shouldReceive('to')
            ->once()
            ->with($email)
            ->andThrow(new TransportException('SMTP credentials must not be returned to the client.'));

        $this->postJson('/api/v1/register', [
            'first_name' => 'Mail',
            'last_name' => 'Failure',
            'tmc_id_no' => 'TMC-MAIL-FAILURE',
            'email' => $email,
            'user_type' => 'student',
            'department' => 'CCS',
            'password' => 'LongTestPassword!123',
            'password_confirmation' => 'LongTestPassword!123',
            'terms_accepted' => true,
        ])->assertStatus(503)
            ->assertExactJson([
                'message' => 'Email delivery is unavailable. Please contact the administrator or try again later.',
            ])
            ->assertDontSee('SMTP credentials');

        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->assertDatabaseMissing('otp_codes', [
            'purpose' => 'registration',
        ]);
    }

    public function test_local_registration_falls_back_to_log_and_otp_can_still_be_verified(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        config([
            'app.env' => 'local',
            'mail.otp_failure_fallback' => true,
        ]);
        $email = 'local-mail-fallback@gmail.com';
        $loggedCode = null;
        $logMailer = \Mockery::mock();
        $logMailer->shouldReceive('to')->once()->with($email)->andReturnSelf();
        $logMailer->shouldReceive('send')
            ->once()
            ->with(\Mockery::on(function (OneTimeCode $mail) use (&$loggedCode): bool {
                $loggedCode = $mail->code;

                return $mail->purpose === 'registration';
            }));

        Mail::shouldReceive('to')
            ->once()
            ->with($email)
            ->andThrow(new TransportException('SMTP authentication failed.'));
        Mail::shouldReceive('mailer')->once()->with('log')->andReturn($logMailer);

        $this->postJson('/api/v1/register', [
            'first_name' => 'Local',
            'last_name' => 'Fallback',
            'tmc_id_no' => 'TMC-LOCAL-FALLBACK',
            'email' => $email,
            'user_type' => 'student',
            'department' => 'CCS',
            'password' => 'LongTestPassword!123',
            'password_confirmation' => 'LongTestPassword!123',
            'terms_accepted' => true,
        ])->assertCreated()
            ->assertJsonPath('message', 'Gmail delivery is unavailable. For local testing, find the verification code in web/storage/logs/laravel.log.');

        $this->assertNotNull($loggedCode);
        $this->postJson('/api/v1/verify-otp', [
            'email' => $email,
            'code' => $loggedCode,
        ])->assertOk()->assertJsonPath('status', 'pending');
    }

    public function test_password_reset_mail_failure_uses_local_fallback(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        config([
            'app.env' => 'local',
            'mail.otp_failure_fallback' => true,
        ]);
        $user = User::create([
            'first_name' => 'Local',
            'last_name' => 'Reset',
            'tmc_id_no' => 'TMC-LOCAL-RESET',
            'email' => 'local-reset@gmail.com',
            'password' => 'OldLongPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $loggedCode = null;
        $logMailer = \Mockery::mock();
        $logMailer->shouldReceive('to')->once()->with($user->email)->andReturnSelf();
        $logMailer->shouldReceive('send')
            ->once()
            ->with(\Mockery::on(function (OneTimeCode $mail) use (&$loggedCode): bool {
                $loggedCode = $mail->code;

                return $mail->purpose === 'password_reset';
            }));

        Mail::shouldReceive('to')
            ->once()
            ->with($user->email)
            ->andThrow(new TransportException('SMTP authentication failed.'));
        Mail::shouldReceive('mailer')->once()->with('log')->andReturn($logMailer);

        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Gmail delivery is unavailable. For local testing, find the password reset code in web/storage/logs/laravel.log.',
            );

        $this->assertNotNull($loggedCode);
        $this->postJson('/api/v1/forgot-password/verify-otp', [
            'email' => $user->email,
            'code' => $loggedCode,
        ])->assertOk()->assertJsonStructure(['reset_token']);
    }

    public function test_registered_user_can_receive_verify_code_and_sign_in(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
        DB::table('tmc_masterlist')->insert([
            'tmc_id_no' => 'TMC-VERIFY-1',
            'full_name' => 'Verified Member',
            'email' => 'verified-member@gmail.com',
            'user_type' => 'student',
            'department' => 'CCS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/register', [
            'first_name' => 'Verified',
            'last_name' => 'Member',
            'tmc_id_no' => 'TMC-VERIFY-1',
            'email' => 'verified-member@gmail.com',
            'user_type' => 'student',
            'department' => 'CCS',
            'password' => 'LongTestPassword!123',
            'password_confirmation' => 'LongTestPassword!123',
            'terms_accepted' => true,
        ])->assertCreated();

        Mail::assertSent(OneTimeCode::class, fn (OneTimeCode $mail): bool => $mail->hasTo('verified-member@gmail.com') && $mail->purpose === 'registration');
        $this->postJson('/api/v1/login', [
            'email' => 'verified-member@gmail.com',
            'password' => 'LongTestPassword!123',
        ])->assertForbidden()->assertJsonPath('message', 'Verify your TMC email before signing in.');

        $this->postJson('/api/v1/resend-otp', [
            'email' => 'verified-member@gmail.com',
        ])->assertOk();
        Mail::assertSentTimes(OneTimeCode::class, 2);
        $verificationEmail = Mail::sent(OneTimeCode::class)->last();
        $this->assertInstanceOf(OneTimeCode::class, $verificationEmail);

        $this->postJson('/api/v1/verify-otp', [
            'email' => 'verified-member@gmail.com',
            'code' => $verificationEmail->code,
        ])->assertOk()->assertJsonPath('status', 'active');

        $this->postJson('/api/v1/login', [
            'email' => 'verified-member@gmail.com',
            'password' => 'LongTestPassword!123',
        ])->assertOk()->assertJsonStructure(['token', 'user' => ['email']]);
    }

    public function test_password_reset_requires_a_verified_account_and_changes_password_after_otp(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
        $user = User::create([
            'first_name' => 'TMC',
            'last_name' => 'Member',
            'tmc_id_no' => 'TMC-RESET-1',
            'email' => 'reset-member@gmail.com',
            'password' => 'OldLongPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/v1/forgot-password', [
            'email' => 'unknown-member@gmail.com',
        ])->assertNotFound()
            ->assertJsonPath('message', 'No verified user account is connected to this Gmail address.');

        $this->postJson('/api/v1/forgot-password', [
            'email' => $user->email,
        ])->assertOk();

        Mail::assertSent(OneTimeCode::class, fn (OneTimeCode $mail): bool => $mail->hasTo($user->email) && $mail->purpose === 'password_reset');
        $resetEmail = Mail::sent(OneTimeCode::class)->first();
        $this->assertInstanceOf(OneTimeCode::class, $resetEmail);

        $this->postJson('/api/v1/forgot-password/verify-otp', [
            'email' => $user->email,
            'code' => '000000',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('otp_codes', [
            'user_id' => $user->id,
            'purpose' => 'password_reset',
            'attempts' => 1,
        ]);

        $resetToken = $this->postJson('/api/v1/forgot-password/verify-otp', [
            'email' => $user->email,
            'code' => $resetEmail->code,
        ])->assertOk()->json('reset_token');

        $this->postJson('/api/v1/forgot-password/reset', [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'NewLongPassword!456',
            'password_confirmation' => 'DifferentLongPassword!789',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/forgot-password/reset', [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'NewLongPassword!456',
            'password_confirmation' => 'NewLongPassword!456',
        ])->assertOk()->assertJsonPath('message', 'Password changed. You can now sign in.');

        $this->assertTrue(Hash::check('NewLongPassword!456', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('otp_codes', [
            'user_id' => $user->id,
            'purpose' => 'password_reset',
        ]);
        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'OldLongPassword!123',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'NewLongPassword!456',
        ])->assertOk();
    }

    public function test_password_reset_otp_and_reset_token_expire(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
        $user = User::create([
            'first_name' => 'TMC',
            'last_name' => 'Member',
            'tmc_id_no' => 'TMC-RESET-2',
            'email' => 'reset-member2@gmail.com',
            'password' => 'OldLongPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertOk();
        $otp = OtpCode::where('user_id', $user->id)->where('purpose', 'password_reset')->firstOrFail();
        $otp->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/v1/forgot-password/verify-otp', [
            'email' => $user->email,
            'code' => Mail::sent(OneTimeCode::class)->first()->code,
        ])->assertUnprocessable();

        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertOk();
        $freshCode = Mail::sent(OneTimeCode::class)->last()->code;
        $resetToken = $this->postJson('/api/v1/forgot-password/verify-otp', [
            'email' => $user->email,
            'code' => $freshCode,
        ])->assertOk()->json('reset_token');

        $this->travel(11)->minutes();
        $this->postJson('/api/v1/forgot-password/reset', [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'NewLongPassword!456',
            'password_confirmation' => 'NewLongPassword!456',
        ])->assertUnprocessable();
    }

    public function test_registration_ignores_obsolete_captcha_fields(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->postJson('/api/v1/register', [
            'first_name' => 'TMC',
            'last_name' => 'Member',
            'tmc_id_no' => 'TMC-NO-CAPTCHA',
            'email' => 'captcha-member2@gmail.com',
            'user_type' => 'student',
            'department' => 'CCS',
            'password' => 'LongTestPassword!123',
            'password_confirmation' => 'LongTestPassword!123',
            'terms_accepted' => true,
            'captcha_token' => 'obsolete-token',
            'captcha_answer' => '0',
        ])->assertCreated();
    }

    public function test_registration_requires_gmail_department_and_other_user_type_details(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $details = [
            'first_name' => 'TMC',
            'last_name' => 'Member',
            'tmc_id_no' => 'TMC-NO-CAPTCHA-3',
            'email' => 'custom-member@example.com',
            'user_type' => 'other',
            'department' => 'OTHER',
            'password' => 'LongTestPassword!123',
            'password_confirmation' => 'LongTestPassword!123',
            'terms_accepted' => true,
        ];

        $this->postJson('/api/v1/register', $details)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_type_other', 'department']);

        $details['user_type_other'] = 'Visiting researcher';
        $details['department'] = 'CCS';
        $this->postJson('/api/v1/register', $details)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $details['email'] = 'custom-member@gmail.com';
        $this->postJson('/api/v1/register', $details)->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'custom-member@gmail.com',
            'user_type' => 'other',
            'user_type_other' => 'Visiting researcher',
            'department' => 'CCS',
        ]);
    }

    public function test_public_post_response_includes_owner_photos_but_not_verification_answers(): void
    {
        $user = User::create([
            'first_name' => 'Finder',
            'last_name' => 'One',
            'tmc_id_no' => 'TMC-102',
            'email' => 'finder@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Accessory', 'is_active' => true]);
        $location = Location::create(['name' => 'Library', 'is_active' => true]);
        $post = Post::create([
            'user_id' => $user->id,
            'type' => 'found',
            'item_name' => 'Blue bottle',
            'description' => 'Found near the library.',
            'category_id' => $category->id,
            'color' => 'Blue',
            'location_id' => $location->id,
            'event_date' => today(),
            'custody' => 'with_me',
            'status' => 'active',
        ]);
        PostImage::create([
            'post_id' => $post->id,
            'path' => 'posts/owner-photo.webp',
            'alt_text' => 'Blue bottle on table',
        ]);
        VerificationQuestion::create([
            'post_id' => $post->id,
            'question' => 'What sticker is on it?',
            'answer' => 'gold star',
        ]);

        $response = $this->getJson('/api/v1/posts/'.$post->id)
            ->assertOk()
            ->assertJsonFragment(['question' => 'What sticker is on it?'])
            ->assertJsonMissingPath('data.verification_questions.0.answer')
            ->assertDontSee('gold star');
        $this->assertSame('Blue bottle on table', $response->json('data.images.0.alt_text'));
        $this->assertStringContainsString('api/v1/media?expires=', $response->json('data.images.0.url'));
        $this->assertStringContainsString('path=posts%2Fowner-photo.webp', $response->json('data.images.0.url'));
    }
}
