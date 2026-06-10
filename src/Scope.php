<?php

declare(strict_types=1);

namespace Tiden;

/** Mutable context (tags, user, extra, breadcrumbs) merged into every event. */
final class Scope
{
    /** @var array<string,string> */
    private array $tags = [];

    /** @var array<string,mixed> */
    private array $extra = [];

    /** @var array<string,mixed> */
    private array $user = [];

    /** @var Breadcrumb[] */
    private array $breadcrumbs = [];

    private ?string $level = null;

    public function __construct(private readonly int $maxBreadcrumbs = 100) {}

    public function setTag(string $key, string $value): void
    {
        $this->tags[$key] = $value;
    }

    public function setExtra(string $key, mixed $value): void
    {
        $this->extra[$key] = $value;
    }

    /** @param array<string,mixed> $user */
    public function setUser(array $user): void
    {
        $this->user = $user;
    }

    public function setLevel(?string $level): void
    {
        $this->level = $level;
    }

    public function addBreadcrumb(Breadcrumb $breadcrumb): void
    {
        $this->breadcrumbs[] = $breadcrumb;
        $overflow = count($this->breadcrumbs) - $this->maxBreadcrumbs;
        if ($overflow > 0) {
            $this->breadcrumbs = array_slice($this->breadcrumbs, $overflow);
        }
    }

    public function clear(): void
    {
        $this->tags = [];
        $this->extra = [];
        $this->user = [];
        $this->breadcrumbs = [];
        $this->level = null;
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    public function applyTo(array $event): array
    {
        if ($this->tags !== []) {
            $event['tags'] = array_merge($event['tags'] ?? [], $this->tags);
        }
        if ($this->extra !== []) {
            $event['extra'] = array_merge($event['extra'] ?? [], $this->extra);
        }
        if ($this->user !== []) {
            $event['user'] = array_merge($event['user'] ?? [], $this->user);
        }
        if ($this->level !== null && ! isset($event['level'])) {
            $event['level'] = $this->level;
        }
        if ($this->breadcrumbs !== []) {
            $event['breadcrumbs'] = [
                'values' => array_map(static fn (Breadcrumb $b): array => $b->toArray(), $this->breadcrumbs),
            ];
        }

        return $event;
    }
}
