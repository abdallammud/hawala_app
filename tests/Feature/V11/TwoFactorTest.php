<?php

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Settings::set('security.two_factor_required', true);
});

/** A code the authenticator app would show, $steps 30-second steps from now. */
function otp(string $secret, int $steps = 0): string
{
    $g = new Google2FA;

    return $g->oathTotp($secret, $g->getTimestamp() + $steps);
}

function tfUser(array $attrs = []): User
{
    return User::factory()->create(array_merge(['password' => 'secret-pass-1', 'is_active' => true], $attrs));
}

/** @return array{0: User, 1: string, 2: list<string>} the user, their secret, their recovery codes */
function enrolled(array $attrs = []): array
{
    $user = tfUser($attrs);
    $service = app(TwoFactorService::class);
    $secret = $service->beginSetup($user);
    $codes = $service->confirm($user, otp($secret));
    $user->forceFill(['two_factor_last_step' => null])->save();

    return [$user->fresh(), $secret, $codes];
}

/** Give an existing user a working 2FA, so they are not sent to set it up. */
function withTwoFactor(User $user): User
{
    $service = app(TwoFactorService::class);
    $service->confirm($user, otp($service->beginSetup($user)));

    return $user->fresh();
}

function passwordLogin(User $user)
{
    return test()->post('/login', ['login' => $user->email, 'password' => 'secret-pass-1']);
}

// --- Setting up at first login -------------------------------------------------------

it('sends a user without 2FA to set it up after they log in', function () {
    $user = tfUser();

    passwordLogin($user)->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);

    $this->get('/dashboard')->assertRedirect(route('two-factor.setup'));
    $this->get('/transfers')->assertRedirect(route('two-factor.setup'));
    $this->getJson('/dashboard')->assertForbidden();
});

it('lets a user who has not set it up log out, and see the setup page', function () {
    $this->actingAs(tfUser())->get('/two-factor/setup')->assertOk()
        ->assertSee('Scan this code in the app')->assertSee('<svg', false);

    $this->post('/logout')->assertRedirect('/login');
});

it('keeps the secret encrypted at rest and generates it once', function () {
    $user = tfUser();

    $this->actingAs($user)->get('/two-factor/setup');
    $secret = $user->fresh()->two_factor_secret;
    $this->get('/two-factor/setup');

    expect($secret)->toHaveLength(32)
        ->and($user->fresh()->two_factor_secret)->toBe($secret)
        ->and(DB::table('users')->where('id', $user->id)->value('two_factor_secret'))->not->toContain($secret);
});

it('refuses a wrong code and turns 2FA on with the right one', function () {
    $user = tfUser();
    $this->actingAs($user)->get('/two-factor/setup');
    $secret = $user->fresh()->two_factor_secret;

    $this->post('/two-factor/setup', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->post('/two-factor/setup', ['code' => 'abcdef'])->assertSessionHasErrors('code');
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $this->post('/two-factor/setup', ['code' => otp($secret)])->assertRedirect(route('two-factor.codes'));

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
    $this->get('/dashboard')->assertOk();
});

it('shows eight recovery codes once and stores only their hashes', function () {
    $user = tfUser();
    $this->actingAs($user)->get('/two-factor/setup');
    $secret = $user->fresh()->two_factor_secret;

    $page = $this->followingRedirects()->post('/two-factor/setup', ['code' => otp($secret)]);
    $page->assertOk()->assertSee('Save your recovery codes');

    $stored = $user->fresh()->two_factor_recovery_codes;
    expect($stored)->toHaveCount(8)->and($stored[0])->toHaveLength(64);

    // Nothing to show on a later visit.
    $this->flushSession();
    $this->actingAs($user->fresh())->get('/two-factor/recovery-codes')->assertRedirect(route('profile.edit'));
});

it('does not hold anyone up when 2FA is not required', function () {
    Settings::set('security.two_factor_required', false);

    $this->actingAs(tfUser())->get('/dashboard')->assertOk();
});

// --- Logging in ----------------------------------------------------------------------

it('asks for a code after the password and signs in only when it is right', function () {
    [$user, $secret] = enrolled();

    passwordLogin($user)->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();
    $this->get('/dashboard')->assertRedirect('/login');

    $this->get('/two-factor-challenge')->assertOk()->assertSee('Enter your code');
    $this->post('/two-factor-challenge', ['code' => otp($secret)])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('rejects a wrong code and stays signed out', function () {
    [$user] = enrolled();

    passwordLogin($user);
    $this->post('/two-factor-challenge', ['code' => '123456'])->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('locks the challenge after five wrong codes, even for the right one', function () {
    [$user, $secret] = enrolled();

    passwordLogin($user);

    foreach (range(1, 5) as $_) {
        $this->post('/two-factor-challenge', ['code' => '111111']);
    }

    $this->post('/two-factor-challenge', ['code' => otp($secret)])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('never accepts the same code twice', function () {
    [$user, $secret] = enrolled();
    $code = otp($secret);

    passwordLogin($user);
    $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect(route('dashboard'));
    $this->post('/logout');

    passwordLogin($user);
    $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();

    // The next code from the app is fine.
    $this->post('/two-factor-challenge', ['code' => otp($secret, 1)])->assertRedirect(route('dashboard'));
});

it('lets a recovery code in exactly once', function () {
    [$user, , $codes] = enrolled();

    passwordLogin($user);
    $this->post('/two-factor-challenge', ['recovery_code' => strtoupper($codes[0])])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);

    expect(app(TwoFactorService::class)->recoveryCodesLeft($user->fresh()))->toBe(7)
        ->and(Activity::where('description', 'Signed in with a recovery code')->count())->toBe(1);

    $this->post('/logout');
    passwordLogin($user);
    $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();
});

it('forgets a half-finished login after ten minutes', function () {
    [$user, $secret] = enrolled();

    passwordLogin($user);
    $this->travel(11)->minutes();

    $this->get('/two-factor-challenge')->assertRedirect('/login');
    $this->post('/two-factor-challenge', ['code' => otp($secret)])->assertRedirect('/login');
    $this->assertGuest();
});

it('has no challenge page without a password step first', function () {
    $this->get('/two-factor-challenge')->assertRedirect('/login');
    $this->post('/two-factor-challenge', ['code' => '123456'])->assertRedirect('/login');
});

it('turns away an account deactivated between the password and the code', function () {
    [$user, $secret] = enrolled();

    passwordLogin($user);
    $user->update(['is_active' => false]);

    $this->post('/two-factor-challenge', ['code' => otp($secret)])->assertRedirect('/login');
    $this->assertGuest();
});

it('still checks the password first, and blocks deactivated users before any code', function () {
    [$user] = enrolled();

    $this->post('/login', ['login' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('login');
    $this->assertGuest();

    $user->update(['is_active' => false]);
    passwordLogin($user)->assertSessionHasErrors('login');
    $this->get('/two-factor-challenge')->assertRedirect('/login');
});

it('logs in without a code when 2FA is not required and not set up', function () {
    Settings::set('security.two_factor_required', false);
    $user = tfUser();

    passwordLogin($user)->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
    $this->get('/dashboard')->assertOk();
});

// --- Profile --------------------------------------------------------------------------

it('makes new recovery codes after a password check, and the old ones stop working', function () {
    [$user, , $codes] = enrolled();

    $this->actingAs($user)->post('/two-factor/recovery-codes', ['current_password' => 'nope'])->assertSessionHasErrors('current_password');

    $this->post('/two-factor/recovery-codes', ['current_password' => 'secret-pass-1'])->assertRedirect(route('two-factor.codes'));

    $service = app(TwoFactorService::class);
    expect($service->useRecoveryCode($user->fresh(), $codes[0]))->toBeFalse()
        ->and($service->recoveryCodesLeft($user->fresh()))->toBe(8);
});

it('only lets a user switch 2FA off when the company does not require it', function () {
    [$user] = enrolled();

    $this->actingAs($user)->delete('/two-factor', ['current_password' => 'secret-pass-1'])->assertForbidden();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    Settings::set('security.two_factor_required', false);

    $this->delete('/two-factor', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    $this->delete('/two-factor', ['current_password' => 'secret-pass-1'])->assertRedirect(route('profile.edit'));
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse()->and($user->fresh()->two_factor_secret)->toBeNull();
});

it('shows the 2FA state on the profile', function () {
    [$user] = enrolled();

    $this->actingAs($user)->get('/profile')->assertOk()
        ->assertSee('Two-factor authentication')->assertSee('8 recovery codes left');
});

// --- Admin reset ------------------------------------------------------------------------

it('lets an administrator reset someone’s 2FA so they enrol again', function () {
    [$user] = enrolled();
    $admin = withTwoFactor(makeAdmin());

    $this->actingAs($admin)->get("/users/{$user->id}/edit")->assertOk()->assertSee('Reset two-factor');
    $this->post("/users/{$user->id}/two-factor/reset")->assertRedirect();

    $fresh = $user->fresh();
    expect($fresh->hasTwoFactorEnabled())->toBeFalse()
        ->and($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull()
        ->and(Activity::where('description', 'like', 'Two-factor authentication reset%')->count())->toBe(1);

    // Their next login goes straight to setup again.
    auth()->logout();
    $this->flushSession();
    passwordLogin($user)->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
    $this->get('/dashboard')->assertRedirect(route('two-factor.setup'));
});

it('does not let people reset their own, or reset without the permission', function () {
    [$user] = enrolled();
    $admin = withTwoFactor(makeAdmin());
    $clerk = withTwoFactor(tfUser());
    $clerk->givePermissionTo('users.view');

    $this->actingAs($admin)->post("/users/{$admin->id}/two-factor/reset")->assertForbidden();
    $this->actingAs($clerk)->post("/users/{$user->id}/two-factor/reset")->assertForbidden();

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('keeps a super admin’s 2FA out of an ordinary admin’s reach', function () {
    [$super] = enrolled();
    $super->assignRole('super-admin');
    $admin = withTwoFactor(makeAdmin());

    $this->actingAs($admin)->post("/users/{$super->id}/two-factor/reset")->assertForbidden();
    expect($super->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $otherSuper = withTwoFactor(makeSuperAdmin());
    $this->actingAs($otherSuper)->post("/users/{$super->id}/two-factor/reset")->assertRedirect();
    expect($super->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

// --- Idle timeout --------------------------------------------------------------------------

it('signs a user out after the idle time and sends them to log in', function () {
    Settings::set('security.two_factor_required', false);
    Settings::set('security.idle_timeout_minutes', 15);
    $user = tfUser();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $this->travel(14)->minutes();
    $this->get('/dashboard')->assertOk();   // still within 15 minutes of the last request

    $this->travel(16)->minutes();
    $this->get('/dashboard')->assertRedirect('/login?idle=1');
    $this->assertGuest();

    $this->get('/login?idle=1')->assertOk()->assertSee('signed out because you were inactive');
});

it('counts the keepalive ping as activity', function () {
    Settings::set('security.two_factor_required', false);
    Settings::set('security.idle_timeout_minutes', 15);

    $this->actingAs(tfUser())->get('/dashboard');

    $this->travel(10)->minutes();
    $this->post('/session/keepalive')->assertNoContent();

    $this->travel(10)->minutes();
    $this->get('/dashboard')->assertOk();
});

it('answers an idle API-style request with 401, not a redirect', function () {
    Settings::set('security.two_factor_required', false);
    $user = tfUser();

    $this->actingAs($user)->get('/dashboard');
    $this->travel(20)->minutes();

    $this->postJson('/session/keepalive')->assertUnauthorized();
});

it('never times out when the setting is 0', function () {
    Settings::set('security.two_factor_required', false);
    Settings::set('security.idle_timeout_minutes', 0);

    $this->actingAs(tfUser())->get('/dashboard');
    $this->travel(100)->minutes();

    $this->get('/dashboard')->assertOk()->assertDontSee('Stay signed in');
});

it('puts the idle guard on pages only while a timeout is set', function () {
    Settings::set('security.two_factor_required', false);
    $user = tfUser();

    Settings::set('security.idle_timeout_minutes', 15);
    $this->actingAs($user)->get('/dashboard')->assertSee('Stay signed in')->assertSee('session/keepalive');

    Settings::set('security.idle_timeout_minutes', 0);
    $this->get('/dashboard')->assertDontSee('Stay signed in');
});

// --- Settings ------------------------------------------------------------------------------

it('saves the security settings and keeps the timeout inside the session lifetime', function () {
    $super = withTwoFactor(makeSuperAdmin());
    $payload = fn (array $o = []) => array_merge([
        'fees_mode' => 'whole', 'fees_min_fee' => '0', 'general_company_name' => 'Blue Star', 'general_timezone' => 'Africa/Mogadishu',
        'security_two_factor_required' => '1', 'security_idle_timeout_minutes' => '20',
    ], $o);

    $this->actingAs($super)->get('/setup/settings')->assertOk()->assertSee('Log out after inactivity');

    $this->put('/setup/settings', $payload(['security_idle_timeout_minutes' => (string) (config('session.lifetime') + 1)]))
        ->assertSessionHasErrors('security_idle_timeout_minutes');
    $this->put('/setup/settings', $payload(['security_idle_timeout_minutes' => '-1']))
        ->assertSessionHasErrors('security_idle_timeout_minutes');
});

// --- Break-glass ------------------------------------------------------------------------------

it('lets the server operator clear anyone’s 2FA from the command line', function () {
    [$user] = enrolled();

    $this->artisan('hawala:reset-two-factor', ['email' => $user->email])->assertSuccessful();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse()->and($user->fresh()->two_factor_secret)->toBeNull();

    $this->artisan('hawala:reset-two-factor', ['email' => 'nobody@example.com'])->assertFailed();
});
