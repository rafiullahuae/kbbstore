<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Verifies WordPress password hashes so imported customers keep their existing
 * passwords. Without this, all 3,712 customers would need a reset email on
 * launch day, which costs orders and generates support load.
 *
 * Two formats are handled because WordPress changed scheme recently:
 *
 *   $P$ / $H$   legacy phpass portable hashes (WordPress < 6.8)
 *   $wp$2y$     modern WordPress bcrypt, where the password is pre-hashed with
 *               HMAC-SHA384 and base64-encoded before bcrypt is applied
 *
 * Production runs WordPress 7.1, so most hashes are expected to be the modern
 * format, but older accounts may still carry phpass. Both paths are supported.
 *
 * NOTE: confirm the actual prefix on one real hash during the migration dry run
 * before trusting this in production. The survey deliberately did not export
 * password hashes, so this has not been verified against your data.
 */
final class WordPressHasher
{
    private const ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function check(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        // Modern WordPress bcrypt wrapper.
        if (str_starts_with($hash, '$wp$')) {
            return password_verify($this->preHash($password), substr($hash, 3));
        }

        // Plain bcrypt, as used by some migrations and plugins.
        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$')) {
            return password_verify($password, $hash) || password_verify($this->preHash($password), $hash);
        }

        // Legacy phpass portable hash.
        if (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$')) {
            return hash_equals($hash, $this->cryptPrivate($password, $hash));
        }

        // Very old WordPress stored a bare MD5.
        if (strlen($hash) === 32 && ctype_xdigit($hash)) {
            return hash_equals($hash, md5($password));
        }

        return false;
    }

    private function preHash(string $password): string
    {
        return base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
    }

    /** phpass portable hash implementation. */
    private function cryptPrivate(string $password, string $setting): string
    {
        $output = '*0';
        if (substr($setting, 0, 2) === $output) {
            $output = '*1';
        }

        $id = substr($setting, 0, 3);
        if ($id !== '$P$' && $id !== '$H$') {
            return $output;
        }

        $countLog2 = strpos(self::ITOA64, $setting[3]);
        if ($countLog2 < 7 || $countLog2 > 30) {
            return $output;
        }

        $count = 1 << $countLog2;
        $salt = substr($setting, 4, 8);
        if (strlen($salt) !== 8) {
            return $output;
        }

        $hash = md5($salt . $password, true);
        do {
            $hash = md5($hash . $password, true);
        } while (--$count);

        return substr($setting, 0, 12) . $this->encode64($hash, 16);
    }

    private function encode64(string $input, int $count): string
    {
        $output = '';
        $i = 0;
        do {
            $value = ord($input[$i++]);
            $output .= self::ITOA64[$value & 0x3f];
            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }
            $output .= self::ITOA64[($value >> 6) & 0x3f];
            if ($i++ >= $count) {
                break;
            }
            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }
            $output .= self::ITOA64[($value >> 12) & 0x3f];
            if ($i++ >= $count) {
                break;
            }
            $output .= self::ITOA64[($value >> 18) & 0x3f];
        } while ($i < $count);

        return $output;
    }
}
