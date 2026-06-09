<?php

declare(strict_types=1);

namespace Tiden\Transport;

/** Delivers a serialized envelope. Implementations MUST NOT throw. */
interface TransportInterface
{
    public function send(string $envelope): void;
}
