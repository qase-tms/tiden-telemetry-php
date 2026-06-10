<?php

declare(strict_types=1);

namespace Tiden;

/**
 * SDK configuration. Construct directly or via Options::fromArray() (the shape
 * the framework bridges pass through from config files).
 */
final class Options
{
    public readonly Dsn $dsn;

    /** @var (callable(array<string,mixed>): (array<string,mixed>|null))|null */
    private $beforeSend;

    /**
     * @param  (callable(array<string,mixed>): (array<string,mixed>|null))|null  $beforeSend  Last-chance hook to
     *                                                                                        mutate or drop an event; return null to drop.
     */
    public function __construct(
        string $dsn,
        public readonly ?string $release = null,
        public readonly ?string $environment = null,
        public readonly bool $sendDefaultPii = false,
        public readonly int $maxBreadcrumbs = 100,
        ?callable $beforeSend = null,
    ) {
        $this->dsn = Dsn::parse($dsn);
        $this->beforeSend = $beforeSend;
    }

    /** @param array<string,mixed> $o */
    public static function fromArray(array $o): self
    {
        $dsn = $o['dsn'] ?? null;
        if (! is_string($dsn) || $dsn === '') {
            throw new \InvalidArgumentException('Tiden: "dsn" is required');
        }

        return new self(
            dsn: $dsn,
            release: isset($o['release']) ? (string) $o['release'] : null,
            environment: isset($o['environment']) ? (string) $o['environment'] : null,
            sendDefaultPii: (bool) ($o['send_default_pii'] ?? false),
            maxBreadcrumbs: (int) ($o['max_breadcrumbs'] ?? 100),
            beforeSend: isset($o['before_send']) && is_callable($o['before_send']) ? $o['before_send'] : null,
        );
    }

    /** @return (callable(array<string,mixed>): (array<string,mixed>|null))|null */
    public function beforeSend(): ?callable
    {
        return $this->beforeSend;
    }
}
