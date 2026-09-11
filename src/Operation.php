<?php

declare(strict_types=1);

namespace Catidegla\SafeMigrations;

/**
 * One thing a migration does to one table.
 *
 * Deliberately coarse. The parser reads PHP, not SQL, and it can see that
 * `$table->string('email')->nullable()` adds a nullable string column without
 * knowing anything about how Laravel will render that for a given driver. So
 * an operation records what was written and the rules decide what it means,
 * which keeps the knowledge about database versions in one place rather than
 * spread through a parser.
 */
final class Operation
{
    public const ADD_COLUMN = 'add_column';
    public const DROP_COLUMN = 'drop_column';
    public const RENAME_COLUMN = 'rename_column';
    public const CHANGE_COLUMN = 'change_column';
    public const ADD_INDEX = 'add_index';
    public const DROP_INDEX = 'drop_index';
    public const ADD_FOREIGN_KEY = 'add_foreign_key';
    public const DROP_TABLE = 'drop_table';
    public const RENAME_TABLE = 'rename_table';
    public const CREATE_TABLE = 'create_table';
    public const RAW_STATEMENT = 'raw_statement';
    public const DATA_CHANGE = 'data_change';

    /**
     * @param string $kind one of the constants above
     * @param string|null $table the table it touches, where the parser could tell
     * @param string|null $column the column, where there is one
     * @param string[] $modifiers chained calls: nullable, default, unique, change, index
     * @param int $line the line in the migration file, so a finding can point at it
     * @param string $source the line as written, for the evidence a reader needs
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $table,
        public readonly ?string $column = null,
        public readonly ?string $type = null,
        public readonly array $modifiers = [],
        public readonly int $line = 0,
        public readonly string $source = '',
        public readonly bool $insideCreate = false,
    ) {}

    public function hasModifier(string $name): bool
    {
        return in_array($name, $this->modifiers, true);
    }

    public function isNullable(): bool
    {
        return $this->hasModifier('nullable');
    }

    public function hasDefault(): bool
    {
        return $this->hasModifier('default');
    }

    /**
     * Whether this operation happens while the table is being created.
     *
     * The single most important distinction in the whole tool. Every rule here
     * is about a table that already exists and already has rows and readers.
     * A brand new table has neither, so nothing in a CREATE TABLE can lock
     * anybody out of anything, and a linter that warns about it is the kind
     * that teaches people to pass --force.
     */
    public function isOnExistingTable(): bool
    {
        return ! $this->insideCreate;
    }

    public function describe(): string
    {
        return match ($this->kind) {
            self::ADD_COLUMN => "adds {$this->column}",
            self::DROP_COLUMN => "drops {$this->column}",
            self::RENAME_COLUMN => "renames {$this->column}",
            self::CHANGE_COLUMN => "changes {$this->column}",
            self::ADD_INDEX => 'adds an index',
            self::DROP_INDEX => 'drops an index',
            self::ADD_FOREIGN_KEY => 'adds a foreign key',
            self::DROP_TABLE => "drops {$this->table}",
            self::RENAME_TABLE => "renames {$this->table}",
            self::CREATE_TABLE => "creates {$this->table}",
            self::RAW_STATEMENT => 'runs raw SQL',
            self::DATA_CHANGE => 'changes data',
            default => $this->kind,
        };
    }
}
