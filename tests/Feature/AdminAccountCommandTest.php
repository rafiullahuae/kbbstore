<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Setting;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\Hash;

/**
 * =============================================================================
 * THE WAY BACK INTO A SHOP WHOSE PASSWORD HAS BEEN FORGOTTEN
 * =============================================================================
 *
 * `php artisan kbb:admin` lists the administrator accounts and sets one's
 * password. It exists because there was no way back in at all: the admin login
 * has no "forgot password" link, a fresh install sends no mail, and an owner
 * who has forgotten which address he signed up with could not even find that
 * out.
 *
 * The answer before this was a `php artisan tinker --execute=` line pasted out
 * of a chat window, which is not an answer -- it is a thing that has to be got
 * exactly right by somebody already locked out. It broke twice on the way here,
 * both times because a terminal joined the pasted block into one line and the
 * shell silently did something else.
 *
 * Every test below is a way that command could hand back an account that looks
 * fine and cannot sign in.
 */
it('lists the accounts and says where to sign in', function () {
    AdminUser::query()->delete();

    AdminUser::create([
        'name' => 'Owner', 'email' => 'owner@example.com',
        'password' => 'irrelevant-here', 'role' => 'owner',
    ]);

    Setting::query()->updateOrInsert(['key' => 'admin_path'], ['value' => 'back-office']);

    $this->artisan('kbb:admin')
        ->expectsOutputToContain('owner@example.com')
        ->expectsOutputToContain('back-office')
        ->assertExitCode(0);
});

it('actually hashes the password it stores', function () {
    /*
     * The single thing this command must never get wrong. AdminUser casts
     * `password` to 'hashed', so assigning plaintext is correct -- and if that
     * cast were ever removed, the assignment would store the password in CLEAR
     * and the command would report success, the owner would sign in
     * successfully, and nothing would look wrong until the database leaked.
     *
     * The command asserts it at runtime too (Hash::check before reporting
     * success). This pins that the two agree.
     *
     * MUTATION: remove 'password' => 'hashed' from AdminUser::casts(). Red,
     * five of the eight tests at once, because the check below is against what
     * sign-in actually does.
     *
     * NOT a mutation: hashing the value here first, with Hash::make(), before
     * assigning it. That was written down as one and then measured, and it is
     * green -- Laravel 11's cast is idempotent
     * (HasAttributes::castAttributeAsHashedString skips a value for which
     * Hash::isHashed() is true), so the double hash never happens. Worth
     * recording, because "assigning an already-hashed value double-hashes it"
     * is a widely believed thing about this cast, and a comment claiming a red
     * that is actually green is worse than no comment.
     */
    AdminUser::query()->delete();

    AdminUser::create([
        'name' => 'Owner', 'email' => 'owner@example.com',
        'password' => 'old-password-here', 'role' => 'owner',
    ]);

    $this->artisan('kbb:admin', ['--email' => 'owner@example.com', '--password' => 'BrandNewPass1!'])
        ->assertExitCode(0);

    $user = AdminUser::query()->where('email', 'owner@example.com')->firstOrFail();

    expect(Hash::check('BrandNewPass1!', $user->password))->toBeTrue(
        'the stored value does not verify against the password that was set, so the owner cannot sign in '
        .'with the password the command just told him it had set'
    );

    expect($user->password)->not->toBe('BrandNewPass1!', 'the password was stored in clear');
});

it('leaves the role alone when only the password is being reset', function () {
    /*
     * A password reset is not a promotion. Resetting a support account's
     * password must not silently make it an owner -- that is a privilege
     * escalation performed by the recovery tool, on an account whose password
     * the operator has just chosen.
     */
    AdminUser::query()->delete();

    AdminUser::create([
        'name' => 'Support', 'email' => 'support@example.com',
        'password' => 'whatever', 'role' => 'support',
    ]);

    $this->artisan('kbb:admin', ['--email' => 'support@example.com', '--password' => 'BrandNewPass1!'])
        ->assertExitCode(0);

    expect(AdminUser::query()->where('email', 'support@example.com')->value('role'))->toBe('support');
});

it('refuses a role that is not a role, before asking for anything else', function () {
    /*
     * AdminCapabilities fails CLOSED: a role not on its list reaches no
     * capability at all, not a reduced set. So storing 'superuser' would not
     * make a weaker account, it would make an account that can log in and then
     * see nothing -- and the operator would have no reason to suspect the role
     * he typed was the cause.
     */
    AdminUser::query()->delete();

    AdminUser::create([
        'name' => 'Owner', 'email' => 'owner@example.com',
        'password' => 'whatever', 'role' => 'owner',
    ]);

    $this->artisan('kbb:admin', [
        '--email' => 'owner@example.com',
        '--role' => 'superuser',
        '--password' => 'BrandNewPass1!',
    ])
        ->expectsOutputToContain("There is no role called 'superuser'.")
        ->assertExitCode(1);

    // And nothing was written.
    $user = AdminUser::query()->where('email', 'owner@example.com')->firstOrFail();

    expect($user->role)->toBe('owner');
    expect(Hash::check('BrandNewPass1!', $user->password))->toBeFalse(
        'the password was changed even though the command failed'
    );
});

it('will not set a password the admin screens would reject', function () {
    /*
     * A console route that accepts weaker passwords than the front door is a
     * back door in the other sense. Same rule, same minimum.
     */
    AdminUser::query()->delete();

    AdminUser::create([
        'name' => 'Owner', 'email' => 'owner@example.com',
        'password' => 'whatever', 'role' => 'owner',
    ]);

    $this->artisan('kbb:admin', ['--email' => 'owner@example.com', '--password' => 'short'])
        ->assertExitCode(1);

    expect(Hash::check('short', AdminUser::query()->where('email', 'owner@example.com')->value('password')))
        ->toBeFalse();
});

it('refuses an address it does not know, rather than creating one by surprise', function () {
    /*
     * --create is opt-in on purpose. A typo in the address, silently creating a
     * SECOND owner account, is how a recovery tool turns one locked-out owner
     * into two accounts and a mystery.
     */
    AdminUser::query()->delete();

    AdminUser::create([
        'name' => 'Owner', 'email' => 'owner@example.com',
        'password' => 'whatever', 'role' => 'owner',
    ]);

    $this->artisan('kbb:admin', ['--email' => 'typo@example.com', '--password' => 'BrandNewPass1!'])
        ->assertExitCode(1);

    expect(AdminUser::query()->count())->toBe(1);
});

it('creates an account on request, and writes the address in one case', function () {
    /*
     * AdminAuthController hands the typed address straight to Auth::attempt(),
     * so the lookup is whatever the column's collation says: case-insensitive
     * on MySQL, case-SENSITIVE on SQLite. An account created as NEW@Example.COM
     * therefore signs in on the shop and not in the tests, which is the worst
     * direction for that disagreement to run.
     */
    AdminUser::query()->delete();

    $this->artisan('kbb:admin', [
        '--email' => 'NEW@Example.COM',
        '--create' => true,
        '--role' => 'manager',
        '--name' => 'Second Admin',
        '--password' => 'BrandNewPass1!',
    ])->assertExitCode(0);

    $user = AdminUser::query()->firstOrFail();

    expect($user->email)->toBe('new@example.com');
    expect($user->role)->toBe('manager');
    expect($user->name)->toBe('Second Admin');
    expect(Hash::check('BrandNewPass1!', $user->password))->toBeTrue();
});

it('names every role the capability map knows, and no others', function () {
    /*
     * The help text is what an operator reads at 2am. A list that has drifted
     * from AdminCapabilities::ROLES either hides a role that works or offers
     * one that fails closed.
     */
    $src = (string) file_get_contents(base_path('app/Console/Commands/AdminAccount.php'));

    preg_match("/--role=[^:]*:[^}]*?'([^']*owner[^']*)'/", $src, $m);

    expect($m)->not->toBe([], 'the --role option no longer documents which roles exist');

    $named = array_map('trim', explode(',', $m[1]));

    expect($named)->toBe(
        AdminCapabilities::ROLES,
        'the roles offered by kbb:admin have drifted from AdminCapabilities::ROLES'
    );
});
