<?php

declare(strict_types=1);

namespace Tiden;

use Tiden\Transport\TransportInterface;

/**
 * Static entry point. `Sdk::init([...])` once at bootstrap, then capture from
 * anywhere. Optionally installs global handlers so uncaught exceptions, PHP
 * errors (as breadcrumbs), and fatal shutdown errors are reported automatically.
 *
 * Framework bridges (e.g. Laravel) typically pass `captureGlobals: false` and
 * wire capture through the framework's own exception handler instead.
 */
final class Sdk
{
    private static ?Client $client = null;
    private static ?Scope $scope = null;
    private static bool $handlersRegistered = false;

    /**
     * @param array<string,mixed>|Options $options
     */
    public static function init(array|Options $options, bool $captureGlobals = true): void
    {
        $opts = $options instanceof Options ? $options : Options::fromArray($options);
        self::$client = Client::create($opts);
        self::$scope = new Scope($opts->maxBreadcrumbs);

        if ($captureGlobals) {
            self::registerHandlers();
        }
    }

    /** For tests / advanced wiring: inject a client + transport directly. */
    public static function bind(Client $client, ?Scope $scope = null): void
    {
        self::$client = $client;
        self::$scope = $scope ?? new Scope();
    }

    public static function captureException(\Throwable $e): ?string
    {
        return self::$client?->captureException($e, self::$scope);
    }

    public static function captureMessage(string $message, string $level = 'info'): ?string
    {
        return self::$client?->captureMessage($message, $level, self::$scope);
    }

    public static function addBreadcrumb(Breadcrumb $breadcrumb): void
    {
        self::$scope?->addBreadcrumb($breadcrumb);
    }

    /** @param callable(Scope): void $callback */
    public static function configureScope(callable $callback): void
    {
        if (self::$scope !== null) {
            $callback(self::$scope);
        }
    }

    public static function getClient(): ?Client
    {
        return self::$client;
    }

    /** Test/reset hook. */
    public static function close(): void
    {
        self::$client = null;
        self::$scope = null;
    }

    private static function registerHandlers(): void
    {
        if (self::$handlersRegistered) {
            return;
        }
        self::$handlersRegistered = true;

        $previousExceptionHandler = null;
        $previousExceptionHandler = set_exception_handler(
            static function (\Throwable $e) use (&$previousExceptionHandler): void {
                self::captureException($e);
                if ($previousExceptionHandler !== null) {
                    ($previousExceptionHandler)($e);
                }
            }
        );

        // PHP warnings/notices become breadcrumbs; returning false lets the normal
        // handler run too. The throwing fatals are caught by the shutdown function.
        set_error_handler(
            static function (int $severity, string $message, string $file = '', int $line = 0): bool {
                self::addBreadcrumb(new Breadcrumb(
                    message: $message,
                    category: 'php',
                    level: self::severityToLevel($severity),
                    type: 'error',
                    data: ['file' => $file, 'line' => $line, 'severity' => $severity],
                ));

                return false;
            }
        );

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if ($err !== null && in_array($err['type'], $fatal, true)) {
                self::captureException(new \ErrorException(
                    $err['message'],
                    0,
                    $err['type'],
                    $err['file'],
                    $err['line'],
                ));
            }
        });
    }

    private static function severityToLevel(int $severity): string
    {
        return match ($severity) {
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warning',
            E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED => 'info',
            default => 'error',
        };
    }
}
