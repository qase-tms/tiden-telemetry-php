<?php

declare(strict_types=1);

namespace Tiden;

/** Turns throwables/messages into the event payload. */
final class EventNormalizer
{
    public function __construct(private readonly Options $options)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function fromException(\Throwable $e, string $level = 'error'): array
    {
        // Walk the cause chain. getPrevious() goes outermost -> root cause;
        // exception.values are ordered with the root cause FIRST.
        $chain = [];
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            $chain[] = $cur;
        }

        $values = [];
        foreach (array_reverse($chain) as $ex) {
            $values[] = [
                'type' => $ex::class,
                'value' => $ex->getMessage(),
                'stacktrace' => ['frames' => StacktraceBuilder::fromThrowable($ex)],
            ];
        }

        return $this->base($level) + ['exception' => ['values' => $values]];
    }

    /**
     * @return array<string,mixed>
     */
    public function fromMessage(string $message, string $level = 'info'): array
    {
        return $this->base($level) + ['message' => $message];
    }

    /**
     * @return array<string,mixed>
     */
    private function base(string $level): array
    {
        $event = [
            'event_id' => self::uuid4(),
            'timestamp' => microtime(true),
            'platform' => 'php',
            'level' => $level,
            'sdk' => ['name' => 'tiden.php', 'version' => Client::VERSION],
        ];
        if ($this->options->release !== null) {
            $event['release'] = $this->options->release;
        }
        if ($this->options->environment !== null) {
            $event['environment'] = $this->options->environment;
        }

        return $event;
    }

    /** 32-char hex UUIDv4 (no dashes), the event_id shape the edge stores. */
    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return bin2hex($b);
    }
}
