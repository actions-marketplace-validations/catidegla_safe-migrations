<?php

declare(strict_types=1);

namespace Catidegla\SafeMigrations;

/**
 * One thing worth knowing about one migration.
 *
 * Carries what it saw, why that matters on this database at this version, and
 * what to do instead. The last of those is the part most linters leave out and
 * the part that decides whether anybody acts: a warning that names a problem
 * and stops teaches people to add an ignore comment.
 */
final class Finding
{
    /** Will take a lock that stops reads or writes on a table with rows in it. */
    public const BLOCKING = 'blocking';

    /** Safe for the database, breaks the application during a rolling deploy. */
    public const ROLLING = 'rolling';

    /** Worth knowing before it runs, not dangerous by itself. */
    public const NOTICE = 'notice';

    public function __construct(
        public readonly string $severity,
        public readonly string $rule,
        public readonly string $file,
        public readonly int $line,
        public readonly string $summary,
        public readonly string $because,
        public readonly string $instead,
        public readonly string $source = '',
    ) {}

    public function isBlocking(): bool
    {
        return $this->severity === self::BLOCKING;
    }

    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'rule' => $this->rule,
            'file' => $this->file,
            'line' => $this->line,
            'summary' => $this->summary,
            'because' => $this->because,
            'instead' => $this->instead,
            'source' => $this->source,
        ];
    }
}
