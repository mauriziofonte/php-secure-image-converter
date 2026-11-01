<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Fixtures\Log;

use Psr\Log\AbstractLogger;

/**
 * Logger that keeps every record in memory. It extends AbstractLogger and declares log() so that the same class
 * satisfies psr/log 1, 2 and 3 (plan section 4).
 */
final class MemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<mixed>}>
     */
    private $records = [];

    /**
     * @param mixed        $level
     * @param mixed        $message a string; the library never logs Stringable objects, which are recorded as ""
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => is_string($message) ? $message : '', 'context' => $context];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * Messages of the records at one level.
     *
     * @return list<string>
     */
    public function getMessages(string $level): array
    {
        $messages = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $messages[] = $record['message'];
            }
        }

        return $messages;
    }
}
