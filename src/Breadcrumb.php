<?php

declare(strict_types=1);

namespace Tiden;

/** A trail entry attached to subsequent events (navigation, query, log, ...). */
final class Breadcrumb
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly string $message,
        public readonly string $category = 'default',
        public readonly string $level = 'info',
        public readonly ?string $type = null,
        public readonly array $data = [],
        public readonly ?float $timestamp = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $a = [
            'timestamp' => $this->timestamp ?? microtime(true),
            'category' => $this->category,
            'level' => $this->level,
            'message' => $this->message,
        ];
        if ($this->type !== null) {
            $a['type'] = $this->type;
        }
        if ($this->data !== []) {
            $a['data'] = $this->data;
        }

        return $a;
    }
}
