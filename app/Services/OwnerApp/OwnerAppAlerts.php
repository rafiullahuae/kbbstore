<?php

declare(strict_types=1);

namespace App\Services\OwnerApp;

use App\Mail\OwnerAppSecurityAlert;
use App\Models\AdminUser;
use App\Services\Mail\MailConfigurator;
use App\Support\AdminRoles;
use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the Full Admins when the owner app's guard has done something they
 * must know about (Lane SEC):
 *
 *   - a member locked for 24 hours, or until a Full Admin unlocks them;
 *   - a phone signed out for good because of wrong PINs.
 *
 * Through the shop's own mailer (MailConfigurator::MAILER, configured under
 * Store → Mail), AFTER the response: the guess that tripped the lock gets its
 * 423 at once, and a slow mail relay never holds a request open. Nothing here
 * may throw — a failed alert must not turn a refusal into a 500.
 */
final class OwnerAppAlerts
{
    public static function memberLocked(int $memberId, int $level, string $kind, string $ip): void
    {
        $who = self::memberName($memberId);
        $how = $level >= OwnerAppAuth::ADMIN_LEVEL ? 'until a Full Admin unlocks it' : 'for 24 hours';
        $where = $kind === 'enrol' ? 'signing in a new phone with their email' : 'unlocking an enrolled phone';

        self::later('Owner app: '.$who.' is locked '.$how, [
            'Too many wrong PINs were entered for '.$who.' in the owner app, while '.$where.'.',
            'Their access is now locked '.$how.'.',
            'Last attempt: '.StoreTime::now()->format('j M Y, H:i').' from '.($ip !== '' ? $ip : 'an unknown connection').'.',
            'If this was not them, change their PIN. To restore access: Admin → Platform → Users & Roles → Owner app → Unlock now.',
        ]);
    }

    public static function deviceRevoked(int $deviceId, string $ip): void
    {
        $row = DB::table('owner_app_devices')->where('id', $deviceId)->first(['name', 'member_id']);
        $who = self::memberName((int) ($row->member_id ?? 0));
        $device = (string) ($row->name ?? 'a phone');

        self::later('Owner app: a phone of '.$who.' was signed out after wrong PINs', [
            'The phone "'.$device.'" of '.$who.' was signed out of the owner app for good after too many wrong PINs.',
            'It needs the email and PIN again before it can be used.',
            'Last attempt: '.StoreTime::now()->format('j M Y, H:i').' from '.($ip !== '' ? $ip : 'an unknown connection').'.',
            'If the phone is lost, change their PIN: Admin → Platform → Users & Roles → Owner app.',
        ]);
    }

    /** @return list<string> */
    public static function recipients(): array
    {
        return AdminUser::query()->orderBy('id')->get()
            ->filter(fn (AdminUser $u) => AdminRoles::isFull($u) && str_contains((string) $u->email, '@'))
            ->map(fn (AdminUser $u) => trim((string) $u->email))
            ->unique()->values()->all();
    }

    /** @var list<array{0:string,1:list<string>}> */
    private static array $pending = [];

    /**
     * Buffered for the request and sent from app()->terminating() — after the
     * response, as OwnerAppEvents sends its pushes. Armed ONCE per container:
     * terminating callbacks are not cleared after they run, so a callback
     * per alert would resend every earlier alert at every later terminate in
     * any long-lived process.
     *
     * @param list<string> $lines
     */
    private static function later(string $subject, array $lines): void
    {
        self::$pending[] = [$subject, $lines];

        try {
            $app = app();
            if (! $app->bound('owner-app.alerts.armed')) {
                $app->instance('owner-app.alerts.armed', true);
                $app->terminating(static fn () => self::flush());
            }
        } catch (\Throwable) {
        }
    }

    /** Send what is buffered. Returns how many emails went. */
    public static function flush(): int
    {
        $todo = self::$pending;
        self::$pending = [];
        $sent = 0;

        foreach ($todo as [$subject, $lines]) {
            $sent += self::send($subject, $lines);
        }

        return $sent;
    }

    /** Drop anything buffered (tests; a long-lived worker between jobs). */
    public static function forget(): void
    {
        self::$pending = [];
    }

    /** @return list<array{0:string,1:list<string>}> */
    public static function pending(): array
    {
        return self::$pending;
    }

    /** @param list<string> $lines */
    public static function send(string $subject, array $lines): int
    {
        $sent = 0;

        try {
            foreach (self::recipients() as $to) {
                try {
                    rescue(static fn () => app(\App\Services\Mail\MailLog::class)->labelNext('ownerapp.security'), null, false);
                    Mail::mailer(MailConfigurator::MAILER)->to($to)->send(new OwnerAppSecurityAlert($subject, $lines));
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('owner app alert not sent', ['exception' => class_basename($e)]);
                }
            }
        } catch (\Throwable) {
        }

        return $sent;
    }

    private static function memberName(int $memberId): string
    {
        $name = DB::table('owner_app_members as m')->join('admin_users as u', 'u.id', '=', 'm.admin_user_id')
            ->where('m.id', $memberId)->value('u.name');

        return trim((string) $name) !== '' ? trim((string) $name) : 'a member';
    }
}
