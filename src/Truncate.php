<?php

declare(strict_types=1);

namespace Tiden;

/**
 * Byte-bounded string cuts that never split a UTF-8 sequence. Uses mb_strcut
 * when mbstring is loaded; the package does not require it, so a pure-PHP
 * fallback trims a trailing partial sequence instead.
 */
final class Truncate
{
    /** Returns the longest prefix of $s that is at most $bytes bytes and ends on a character boundary. */
    public static function utf8(string $s, int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        if (strlen($s) <= $bytes) {
            return $s;
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($s, 0, $bytes, 'UTF-8');
        }

        return self::cutBytes($s, $bytes);
    }

    /**
     * The fallback used without mbstring. Public so it is tested on hosts that have mbstring.
     *
     * @internal
     */
    public static function cutBytes(string $s, int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        if (strlen($s) <= $bytes) {
            return $s;
        }

        $cut = substr($s, 0, $bytes);
        // Walk back over continuation bytes (10xxxxxx) to the last lead byte, at most 3.
        $i = $bytes - 1;
        $continuation = 0;
        while ($i >= 0 && $continuation < 3 && (ord($cut[$i]) & 0xC0) === 0x80) {
            $i--;
            $continuation++;
        }
        if ($i < 0) {
            return $cut;
        }
        $lead = ord($cut[$i]);
        $need = match (true) {
            $lead < 0x80 => 1,
            ($lead & 0xE0) === 0xC0 => 2,
            ($lead & 0xF0) === 0xE0 => 3,
            ($lead & 0xF8) === 0xF0 => 4,
            default => 1, // invalid lead byte: leave it, JSON encoding substitutes it
        };
        if ($continuation + 1 < $need) {
            return substr($cut, 0, $i); // drop the incomplete trailing sequence
        }

        return $cut;
    }
}
