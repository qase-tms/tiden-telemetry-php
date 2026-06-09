<?php

declare(strict_types=1);

namespace Tiden\Transport;

/** Captures envelopes in memory instead of sending them. For tests / dry-runs. */
final class NullTransport implements TransportInterface
{
    /** @var list<string> */
    public array $envelopes = [];

    public function send(string $envelope): void
    {
        $this->envelopes[] = $envelope;
    }

    public function last(): ?string
    {
        return $this->envelopes[count($this->envelopes) - 1] ?? null;
    }
}
