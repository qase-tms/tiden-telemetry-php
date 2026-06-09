<?php

declare(strict_types=1);

namespace Tiden;

/** Builds stack frames from a Throwable. */
final class StacktraceBuilder
{
    /**
     * Frames are ordered oldest (outermost) first, with the crash frame LAST —
     * the order the UI expects. Each frame pairs a call-site file/line with
     * the function on the stack at that point; the innermost frame uses the
     * throwable's own file/line (the actual throw location).
     *
     * @return list<array<string,mixed>>
     */
    public static function fromThrowable(\Throwable $e): array
    {
        $trace = $e->getTrace();

        $frames = [];
        foreach (array_reverse($trace) as $t) {
            $frames[] = self::frame(
                is_string($t['file'] ?? null) ? $t['file'] : null,
                is_int($t['line'] ?? null) ? $t['line'] : null,
                self::functionName($t),
            );
        }

        // Innermost frame: the throw site itself.
        $frames[] = self::frame($e->getFile(), $e->getLine(), self::functionName($trace[0] ?? []));

        return $frames;
    }

    /** @param array<string,mixed> $t */
    private static function functionName(array $t): ?string
    {
        if (!isset($t['function']) || !is_string($t['function'])) {
            return null;
        }
        if (isset($t['class']) && is_string($t['class'])) {
            return $t['class'] . (is_string($t['type'] ?? null) ? $t['type'] : '::') . $t['function'];
        }

        return $t['function'];
    }

    /** @return array<string,mixed> */
    private static function frame(?string $file, ?int $line, ?string $function): array
    {
        $frame = [];
        if ($function !== null) {
            $frame['function'] = $function;
        }
        if ($file !== null) {
            $frame['filename'] = self::relative($file);
            $frame['abs_path'] = $file;
        }
        if ($line !== null) {
            $frame['lineno'] = $line;
        }
        // Heuristic: vendor code is not "in app".
        $frame['in_app'] = $file !== null && !str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);

        return $frame;
    }

    private static function relative(string $file): string
    {
        $cwd = getcwd();
        if ($cwd !== false && str_starts_with($file, $cwd . DIRECTORY_SEPARATOR)) {
            return substr($file, strlen($cwd) + 1);
        }

        return $file;
    }
}
