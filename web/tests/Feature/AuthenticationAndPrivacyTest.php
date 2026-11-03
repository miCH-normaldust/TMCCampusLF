<?php

namespace Tests\Feature;

use App\Mail\OneTimeCode;
use App\Models\Category;
use App\Models\Location;
use App\Models\OtpCode;
use App\Models\Post;
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

        $this->withSession(['admin_login_captcha_answer' => '7'])
            ->post('/admin/login', [
                'email' => 'member@tmc.test',
                'password' => 'LongTestPassword!123',
                'captcha_answer' => '7',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('email');
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

    public function test_registration_accepts_the_current_database_backed_security_challenge(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
        DB::table('settings')->updateOrInsert(
            ['key' => 'tmc_email_domain'],
            ['value' => 'gmail.com', 'created_at' => now(), 'updated_at' => now()],
        );
        $challenge = $this->getJson('/api/v1/captcha')->assertOk()->json();
        preg_match('/(\d+) \+ (\d+)/', $challenge['question'], $numbers);

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
            'captcha_token' => $challenge['token'],
            'captcha_answer' => (string) ((int) $numbers[1] + (int) $numbers[2]),
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'captcha-member@gmail.com']);
        $this->assertDatabaseMissing('captcha_challenges', [
            'token_hash' => hash('sha256', $challenge['token']),
        ]);
    }

    public function test_registration_does_not_expose_mail_transport_errors_or_keep_partial_account(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $email = 'mail-failure@gmail.com';
        Mail::shouldReceive('to')
            ->once()
            ->with($email)
            ->andThrow(new TransportException('SMTP credentials must not be returned to the client.'));

        $challenge = $this->getJson('/api/v1/captcha')->assertOk()->json();
        preg_match('/(\d+) \+ (\d+)/', $challenge['question'], $numbers);

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
            'captcha_token' => $challenge['token'],
            'captcha_answer' => (string) ((int) $numbers[1] + (int) $numbers[2]),
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

        $challenge = $this->getJson('/api/v1/captcha')->assertOk()->json();
        preg_match('/(\d+) \+ (\d+)/', $challenge['question'], $numbers);

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
            'captcha_token' => $challenge['token'],
            'captcha_answer' => (string) ((int) $numbers[1] + (int) $numbers[2]),
        ])->assertCreated()
            ->assertJsonPath('message', 'Gmail delivery is unavailable. For local testing, find the verification code in web/storage/logs/laravel.log.');

        $this->assertNotNull($loggedCode);
        $this->postJson('/api/v1/verify-otp', [
            'email' => $email,
            'code' => $loggedCode,
        ])->assertOk()->assertJsonPath('status', 'pending');
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

        $challenge = $this->getJson('/api/v1/captcha')->assertOk()->json();
        preg_match('/(\d+) \+ (\d+)/', $challenge['question'], $numbers);
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
            'captcha_token' => $challenge['token'],
            'captcha_answer' => (string) ((int) $numbers[1] + (int) $numbers[2]),
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

    public function test_incorrect_security_answer_consumes_the_challenge_and_prompts_refresh(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $challenge = $this->getJson('/api/v1/captcha')->assertOk()->json();
        $details = [
            'first_name' => 'TMC',
            'last_name' => 'Member',
            'tmc_id_no' => 'TMC-CAPTCHA-2',
            'email' => 'captcha-member2@gmail.com',
            'user_type' => 'student',
            'department' => 'CCS',
            'password' => 'LongTestPassword!123',
            'password_confirmation' => 'LongTestPassword!123',
            'terms_accepted' => true,
            'captcha_token' => $challenge['token'],
            'captcha_answer' => '0',
        ];

        $this->postJson('/api/v1/register', $details)
            ->assertUnprocessable()
            ->assertJsonPath('errors.captcha_answer.0', 'The security answer is incorrect. Please try the new challenge.');
        $this->postJson('/api/v1/register', $details)
            ->assertUnprocessable()
            ->assertJsonPath('errors.captcha_answer.0', 'The security check expired. Please request a new challenge.');
    }

    public function test_registration_requires_gmail_department_and_other_user_type_details(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $challenge = $this->getJson('/api/v1/captcha')->assertOk()->json();
        $details = [
            'first_name' => 'TMC',
            'last_name' => 'Member',
            'tmc_id_no' => 'TMC-CAPTCHA-3',
            'email' => 'custom-member@example.com',
            'user_type' => 'other',
            'department' => 'OTHER',
            'password' => 'LongTestPassword!123',
            'password_confirmation' => 'LongTestPassword!123',
            'terms_accepted' => true,
            'captcha_token' => $challenge['token'],
            'captcha_answer' => '0',
        ];

        preg_match('/(\d+) \+ (\d+)/', $challenge['question'], $numbers);
        $details['captcha_answer'] = (string) ((int) $numbers[1] + (int) $numbers[2]);
        $this->postJson('/api/v1/register', $details)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_type_other', 'department']);

        $details['user_type_other'] = 'Visiting researcher';
        $details['department'] = 'CCS';
        $challenge = $this->getJson('/api/v1/captcha')->json();
        $details['captcha_token'] = $challenge['token'];
        preg_match('/(\d+) \+ (\d+)/', $challenge['question'], $numbers);
        $details['captcha_answer'] = (string) ((int) $numbers[1] + (int) $numbers[2]);
        $this->postJson('/api/v1/register', $details)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $details['email'] = 'custom-member@gmail.com';
        $challenge = $this->getJson('/api/v1/captcha')->json();
        $details['captcha_token'] = $challenge['token'];
        preg_match('/(\d+) \+ (\d+)/', $challenge['question'], $numbers);
        $details['captcha_answer'] = (string) ((int) $numbers[1] + (int) $numbers[2]);

        $this->postJson('/api/v1/register', $details)->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'custom-member@gmail.com',
            'user_type' => 'other',
            'user_type_other' => 'Visiting researcher',
            'department' => 'CCS',
        ]);
    }

    public function test_public_post_response_never_contains_verification_answers(): void
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
        VerificationQuestion::create([
            'post_id' => $post->id,
            'question' => 'What sticker is on it?',
            'answer' => 'gold star',
        ]);

        $this->getJson('/api/v1/posts/'.$post->id)
            ->assertOk()
            ->assertJsonFragment(['question' => 'What sticker is on it?'])
            ->assertJsonMissingPath('data.verification_questions.0.answer')
            ->assertDontSee('gold star');
    }
}
