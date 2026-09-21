<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Models\Setting;
use App\Support\AdminCapabilities;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Support\Facades\Validator;

/**
 * Look at, and get back into, the administrator accounts.
 *
 *     php artisan kbb:admin                                  list them, and say where to sign in
 *     php artisan kbb:admin --email=you@example.com          set that account's password (prompts)
 *     php artisan kbb:admin --email=you@example.com --create make it if it is not there
 *     php artisan kbb:admin --email=you@example.com --role=manager
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Because there was no way back in. The admin login has no "forgot password"
 * link, nothing sends mail on a fresh install, and the owner who has forgotten
 * which address he set up with cannot even find that out. The answer until now
 * was a `php artisan tinker --execute=` one-liner pasted from a chat window —
 * which is not an answer, it is a thing that has to be got exactly right by
 * someone who is already locked out and not having a good day. It broke twice
 * on the way here, both times because a terminal joined a pasted block into one
 * line.
 *
 * So: one command, with its own prompts, that cannot be pasted wrong.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ────────────────────────────────────────
 *
 * It does not take the new password as an argument by default, and prompts for
 * it instead. A password on a command line is written to ~/.bash_history and is
 * visible in `ps` to every other account on the box for as long as PHP runs —
 * on shared hosting that is a real reader, not a theoretical one. `--password`
 * exists for scripting and says so when used.
 *
 * It is a console command and nothing else: no route, no controller, nothing
 * reachable over HTTP. Whoever can run it already has the application's files
 * and .env, so it grants no access that was not already held. That is the line
 * this stays on the right side of — a recovery route that a visitor can reach
 * is not a recovery route, it is the front door left open.
 */
class AdminAccount extends Command
{
    protected $signature = 'kbb:admin
                            {--email= : The account to act on. Omit to just list them}
                            {--password= : Set this password instead of being asked. Ends up in your shell history}
                            {--role= : One of '.'owner, manager, support, editor'.'}
                            {--create : Create the account if that address has none}
                            {--name= : Display name, used only when creating}';

    protected $description = 'List the administrator accounts, or set one\'s password';

    public function handle(): int
    {
        $email = trim((string) $this->option('email'));

        if ($email === '') {
            return $this->listAccounts();
        }

        $user = AdminUser::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();

        if ($user === null && ! $this->option('create')) {
            $this->error("No administrator account has the address {$email}.");
            $this->line('');
            $this->line('Run `php artisan kbb:admin` on its own to see the addresses that do exist,');
            $this->line('or add --create to make this one.');

            return self::FAILURE;
        }

        $role = $this->resolveRole($user);

        if ($role === null) {
            return self::FAILURE;
        }

        $password = $this->resolvePassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $creating = $user === null;

        if ($creating) {
            $user = new AdminUser;

            /*
             * Stored lower-cased, and only on create -- an existing row's
             * address is data this command has no business rewriting.
             *
             * AdminAuthController passes the typed address straight into
             * Auth::attempt(), so the lookup is whatever the column's collation
             * says. On MySQL (utf8mb4_*_ci) that is case-insensitive and a
             * mixed-case row signs in fine; on SQLite `=` is case-SENSITIVE,
             * so a row written as NEW@Example.COM could be typed correctly and
             * refused. Writing it down in one case makes the account behave the
             * same on both, which matters because the tests run on one and the
             * shop runs on the other.
             *
             * It also matches the login rate-limiter, which already keys on
             * Str::lower($email).
             */
            $user->email = mb_strtolower($email);
            $user->name = trim((string) $this->option('name')) ?: 'Administrator';
        }

        $user->role = $role;
        $user->password = $password;   // AdminUser casts this to 'hashed'
        $user->save();

        /*
         * Asserted rather than assumed. The cast is what hashes the value, and
         * a cast that were ever removed would store the password in clear and
         * report success either way -- the one failure here that must not be
         * silent, because the row would still let the owner in and nothing
         * would look wrong until the database leaked.
         */
        if (! Hash::check($password, (string) $user->getAuthPassword())) {
            $this->error('The password was not stored correctly. Nothing about this account is trustworthy now;');
            $this->error('check that AdminUser still casts `password` to `hashed`.');

            return self::FAILURE;
        }

        $this->info($creating
            ? "Created {$user->email} as {$user->role}."
            : "Password set for {$user->email} ({$user->role}).");

        $this->line('');
        $this->line('Sign in at  '.$this->signInUrl());

        return self::SUCCESS;
    }

    private function listAccounts(): int
    {
        $users = AdminUser::query()->orderBy('id')->get(['id', 'name', 'email', 'role']);

        if ($users->isEmpty()) {
            $this->warn('There are no administrator accounts at all.');
            $this->line('');
            $this->line('Make one:  php artisan kbb:admin --email=you@example.com --create');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'name', 'email', 'role'],
            $users->map(fn (AdminUser $u) => [$u->id, $u->name, $u->email, $u->role])->all()
        );

        $this->line('Sign in at  '.$this->signInUrl());
        $this->line('');
        $this->line('Forgotten the password?  php artisan kbb:admin --email='.$users->first()->email);

        return self::SUCCESS;
    }

    /**
     * The role to store, or null if the one asked for is not a role.
     */
    private function resolveRole(?AdminUser $user): ?string
    {
        $given = trim((string) $this->option('role'));

        if ($given === '') {
            // Leave an existing account's role alone; a password reset is not
            // a promotion. A new one gets the column's own default.
            return $user?->role ?: 'owner';
        }

        /*
         * Checked against AdminCapabilities::ROLES, which is the vocabulary
         * EnforceAdminCapability actually reads. A role that is not on that
         * list is not a weaker account -- it is an account that can reach
         * nothing at all, because the capability map fails closed. Storing one
         * would look like success and lock the owner out one screen later.
         */
        if (! in_array($given, AdminCapabilities::ROLES, true)) {
            $this->error("There is no role called '{$given}'.");
            $this->line('Roles: '.implode(', ', AdminCapabilities::ROLES));

            return null;
        }

        return $given;
    }

    /**
     * The new password, or null if it was refused.
     */
    private function resolvePassword(): ?string
    {
        $given = (string) $this->option('password');

        if ($given !== '') {
            $this->warn('--password was given on the command line, so it is now in your shell history.');
            $this->warn('Prefer running this without it and typing the password when asked.');
        } else {
            $given = (string) $this->secret('New password');

            if ($given === '') {
                $this->error('No password given.');

                return null;
            }

            if ($given !== (string) $this->secret('Type it again')) {
                $this->error('The two did not match.');

                return null;
            }
        }

        /*
         * The same rule the admin screens apply, so a password set here is one
         * the account's own "change password" form would also accept. A console
         * back door that takes weaker passwords than the front door is a back
         * door in the other sense too.
         */
        $check = Validator::make(
            ['password' => $given],
            ['password' => ['required', 'string', PasswordRule::min(8)]]
        );

        if ($check->fails()) {
            foreach ($check->errors()->all() as $message) {
                $this->error($message);
            }

            return null;
        }

        return $given;
    }

    private function signInUrl(): string
    {
        $path = 'admin';

        try {
            $path = (string) (Setting::query()->where('key', 'admin_path')->value('value') ?: 'admin');
        } catch (\Throwable $e) {
            // A database that cannot answer still leaves the rest of this
            // command useful; 'admin' is the column's own default.
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
