<?php

declare(strict_types=1);

namespace Catidegla\SafeMigrations;

/**
 * Reading a Laravel migration without running it.
 *
 * Running it is the obvious alternative and it is the wrong one: a migration
 * is arbitrary PHP, and a linter that executes what it is inspecting can be
 * made to do anything by the file it is inspecting. This reads tokens.
 *
 * token_get_all ships with PHP, so this needs no parser dependency and the
 * package stays installable anywhere PHP runs. What it gives up is a real
 * syntax tree, which means the parser is looking for the shapes Laravel
 * migrations actually take rather than understanding PHP. Where it cannot tell,
 * it says so rather than guessing, and the rules downstream are written to
 * cope with an operation whose table it never worked out.
 */
final class Parser
{
    /** Blueprint methods that add a column, mapped to the type they add. */
    private const COLUMN_METHODS = [
        'bigIncrements', 'bigInteger', 'binary', 'boolean', 'char', 'date', 'dateTime',
        'dateTimeTz', 'decimal', 'double', 'enum', 'float', 'foreignId', 'foreignIdFor',
        'foreignUlid', 'foreignUuid', 'geometry', 'id', 'increments', 'integer',
        'ipAddress', 'json', 'jsonb', 'longText', 'macAddress', 'mediumIncrements',
        'mediumInteger', 'mediumText', 'morphs', 'nullableMorphs', 'nullableTimestamps',
        'nullableUlidMorphs', 'nullableUuidMorphs', 'rememberToken', 'set', 'smallIncrements',
        'smallInteger', 'softDeletes', 'softDeletesTz', 'string', 'text', 'time', 'timeTz',
        'timestamp', 'timestampTz', 'timestamps', 'timestampsTz', 'tinyIncrements',
        'tinyInteger', 'tinyText', 'ulid', 'ulidMorphs', 'unsignedBigInteger',
        'unsignedDecimal', 'unsignedInteger', 'unsignedMediumInteger', 'unsignedSmallInteger',
        'unsignedTinyInteger', 'uuid', 'uuidMorphs', 'year',
    ];

    private const INDEX_METHODS = ['index', 'unique', 'fullText', 'fullText', 'spatialIndex', 'primary'];

    /** Modifiers worth remembering, because a rule turns on one of them. */
    private const MODIFIERS = [
        'nullable', 'default', 'change', 'unique', 'index', 'after', 'first',
        'useCurrent', 'storedAs', 'virtualAs', 'comment', 'charset', 'collation',
        'algorithm', 'constrained', 'cascadeOnDelete', 'nullOnDelete', 'onDelete', 'onUpdate',
    ];

    /**
     * @return Operation[]
     */
    public function parse(string $code): array
    {
        $tokens = @token_get_all($code);

        if ($tokens === false) {
            return [];
        }

        // Drop whitespace and comments. A migration's meaning does not depend
        // on either, and carrying them makes every lookahead below harder to
        // read for no gain.
        $meaningful = [];
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $meaningful[] = $token;
        }

        $lines = explode("\n", $code);
        $operations = [];
        $currentTable = null;

        // Which Schema:: call we are inside, rather than a brace depth.
        //
        // Counting braces looks more rigorous and is harder to get right: the
        // counter has to come back down at exactly the right closing brace,
        // and when it does not, every operation after the first Schema::create
        // in the file is silently treated as happening on a brand new table.
        // That is the one distinction this tool cannot afford to get wrong,
        // since it decides whether a rule speaks at all. Each Schema:: call
        // simply replaces the context, which is how migrations are written.
        $insideCreate = false;

        for ($i = 0; $i < count($meaningful); $i++) {
            $token = $meaningful[$i];

            if (! is_array($token)) {
                continue;
            }

            $line = $token[2];
            $text = $token[1];

            // Schema::create / table / drop / rename, which set the context
            // every operation after them belongs to.
            if ($token[0] === T_STRING && in_array($text, ['create', 'table', 'drop', 'dropIfExists', 'rename'], true)
                && $this->precededByStatic($meaningful, $i, 'Schema')) {

                $argument = $this->firstStringArgument($meaningful, $i);

                if ($text === 'create') {
                    $insideCreate = true;
                    $currentTable = $argument;
                    $operations[] = new Operation(
                        Operation::CREATE_TABLE, $argument, null, null, [], $line,
                        $this->lineAt($lines, $line), true,
                    );
                } elseif ($text === 'table') {
                    $insideCreate = false;
                    $currentTable = $argument;
                } elseif ($text === 'drop' || $text === 'dropIfExists') {
                    $operations[] = new Operation(
                        Operation::DROP_TABLE, $argument, null, null, [], $line,
                        $this->lineAt($lines, $line), false,
                    );
                } elseif ($text === 'rename') {
                    $operations[] = new Operation(
                        Operation::RENAME_TABLE, $argument, null, null, [], $line,
                        $this->lineAt($lines, $line), false,
                    );
                }

                continue;
            }

            // DB::statement and friends: raw SQL this cannot read.
            if ($token[0] === T_STRING && in_array($text, ['statement', 'unprepared'], true)
                && $this->precededByStatic($meaningful, $i, 'DB')) {
                $operations[] = new Operation(
                    Operation::RAW_STATEMENT, $currentTable, null, null, [], $line,
                    $this->lineAt($lines, $line), false,
                );
                continue;
            }

            // A write through the query builder inside a migration, which is a
            // backfill and belongs to a different rule than any DDL.
            if ($token[0] === T_STRING && in_array($text, ['update', 'insert', 'delete', 'truncate', 'upsert'], true)
                && $this->precededByObjectOperator($meaningful, $i)) {
                $operations[] = new Operation(
                    Operation::DATA_CHANGE, $currentTable, null, null, [], $line,
                    $this->lineAt($lines, $line), false,
                );
                continue;
            }

            if ($token[0] !== T_STRING || ! $this->precededByObjectOperator($meaningful, $i)) {
                continue;
            }

            $modifiers = $this->modifiersAfter($meaningful, $i);

            if (in_array($text, self::COLUMN_METHODS, true)) {
                $column = $this->firstStringArgument($meaningful, $i);

                $operations[] = new Operation(
                    in_array('change', $modifiers, true) ? Operation::CHANGE_COLUMN : Operation::ADD_COLUMN,
                    $currentTable, $column, $text, $modifiers, $line,
                    $this->lineAt($lines, $line), $insideCreate,
                );
                continue;
            }

            if (in_array($text, self::INDEX_METHODS, true)) {
                $operations[] = new Operation(
                    Operation::ADD_INDEX, $currentTable, $this->firstStringArgument($meaningful, $i),
                    $text, $modifiers, $line, $this->lineAt($lines, $line), $insideCreate,
                );
                continue;
            }

            $mapped = match ($text) {
                'dropColumn', 'dropSoftDeletes', 'dropRememberToken', 'dropMorphs' => Operation::DROP_COLUMN,
                'renameColumn' => Operation::RENAME_COLUMN,
                'dropIndex', 'dropUnique', 'dropPrimary', 'dropFullText', 'dropSpatialIndex' => Operation::DROP_INDEX,
                'foreign' => Operation::ADD_FOREIGN_KEY,
                default => null,
            };

            if ($mapped !== null) {
                $operations[] = new Operation(
                    $mapped, $currentTable, $this->firstStringArgument($meaningful, $i),
                    $text, $modifiers, $line, $this->lineAt($lines, $line), $insideCreate,
                );
            }
        }

        return $operations;
    }

    /**
     * A foreignId with constrained() is a foreign key as well as a column.
     *
     * Reported as both rather than as one, because the two carry different
     * risks and a reader who is told only about the column will not think
     * about the lock on the referenced table.
     */
    public function expand(array $operations): array
    {
        $out = [];

        foreach ($operations as $operation) {
            $out[] = $operation;

            if ($operation->kind === Operation::ADD_COLUMN && $operation->hasModifier('constrained')) {
                $out[] = new Operation(
                    Operation::ADD_FOREIGN_KEY, $operation->table, $operation->column,
                    'constrained', $operation->modifiers, $operation->line,
                    $operation->source, $operation->insideCreate,
                );
            }
        }

        return $out;
    }

    private function precededByObjectOperator(array $tokens, int $i): bool
    {
        $previous = $tokens[$i - 1] ?? null;

        return is_array($previous)
            && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
    }

    private function precededByStatic(array $tokens, int $i, string $class): bool
    {
        $arrow = $tokens[$i - 1] ?? null;
        $name = $tokens[$i - 2] ?? null;

        return is_array($arrow) && $arrow[0] === T_DOUBLE_COLON
            && is_array($name) && $name[0] === T_STRING
            && str_ends_with($name[1], $class);
    }

    /** The first string literal inside the call that starts at $i. */
    private function firstStringArgument(array $tokens, int $i): ?string
    {
        for ($j = $i + 1; $j < min($i + 8, count($tokens)); $j++) {
            $token = $tokens[$j];

            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                return trim($token[1], "'\"");
            }

            // An array of columns, as index(['a','b']) takes. The first is
            // enough to name the operation.
            if ($token === ')') {
                return null;
            }
        }

        return null;
    }

    /**
     * The chained calls that follow, which is where nullable and default live.
     *
     * Stops at a semicolon, because everything after that belongs to the next
     * statement and carrying modifiers across would attribute a default to a
     * column that never had one.
     */
    private function modifiersAfter(array $tokens, int $i): array
    {
        $modifiers = [];
        $depth = 0;

        for ($j = $i + 1; $j < count($tokens); $j++) {
            $token = $tokens[$j];

            if ($token === ';') {
                break;
            }

            if ($token === '(') {
                $depth++;
                continue;
            }

            if ($token === ')') {
                $depth--;
                continue;
            }

            if (is_array($token) && $token[0] === T_STRING
                && $this->precededByObjectOperator($tokens, $j)
                && in_array($token[1], self::MODIFIERS, true)) {
                $modifiers[] = $token[1];
            }
        }

        return array_values(array_unique($modifiers));
    }

    private function lineAt(array $lines, int $line): string
    {
        return trim($lines[$line - 1] ?? '');
    }
}
