<?php

declare(strict_types=1);

namespace Catidegla\SafeMigrations;

/**
 * Putting findings in front of somebody.
 *
 * Every finding prints three lines: what it saw, why that matters here, and
 * what to do instead. The third is the one that decides whether anything
 * changes. A linter that names a problem and stops has told a developer under
 * deadline pressure to go and research it, and what they will actually do is
 * add an ignore comment.
 */
final class Reporter
{
    private const TINT = [
        Finding::BLOCKING => '31',
        Finding::ROLLING => '33',
        Finding::NOTICE => '2',
    ];

    private const MARK = [
        Finding::BLOCKING => 'lock',
        Finding::ROLLING => 'deploy',
        Finding::NOTICE => 'note',
    ];

    public function __construct(private readonly bool $colour = false) {}

    private function paint(string $code, string $text): string
    {
        return $this->colour ? "\033[{$code}m{$text}\033[0m" : $text;
    }

    /** @param Finding[] $findings */
    public function terminal(array $findings, Target $target, int $fileCount): string
    {
        $out = ["\n  " . $this->paint('2', "{$fileCount} migration(s) against {$target->label()}") . "\n"];

        if ($findings === []) {
            $out[] = '  ' . $this->paint('32', 'Nothing here will lock a table or break a rolling deploy.') . "\n";

            if (! $target->isVersioned()) {
                $out[] = '  ' . $this->paint('2', 'No version was configured, so the rules assumed the oldest behaviour. Setting one will quieten some of them.') . "\n";
            }

            return implode('', $out);
        }

        $file = null;

        foreach ($findings as $finding) {
            if ($finding->file !== $file) {
                $file = $finding->file;
                $out[] = "\n  " . $this->paint('1', $file) . "\n";
            }

            $tag = $this->paint(self::TINT[$finding->severity], str_pad(self::MARK[$finding->severity], 6));

            $out[] = sprintf("    %s %s  %s\n", $tag, $this->paint('2', "line {$finding->line}"), $finding->summary);

            if ($finding->source !== '') {
                $out[] = '           ' . $this->paint('2', $finding->source) . "\n";
            }

            $out[] = '           ' . $this->paint('2', $finding->because) . "\n";
            $out[] = '           ' . $this->paint('36', 'instead: ') . $finding->instead . "\n";
            $out[] = '           ' . $this->paint('2', "safe-migrations-ignore: {$finding->rule}") . "\n\n";
        }

        $summary = Linter::summarise($findings);

        $out[] = sprintf(
            "  %s\n",
            $summary['clean']
                ? $this->paint('32', "{$summary['notice']} note(s), nothing blocking")
                : $this->paint('31', "{$summary['blocking']} that will lock a table, {$summary['rolling']} that will break a rolling deploy"),
        );

        if (! $target->isVersioned()) {
            $out[] = '  ' . $this->paint('2', 'No database version was configured, so every version dependent rule assumed the oldest behaviour.') . "\n";
        }

        return implode('', $out) . "\n";
    }

    /** @param Finding[] $findings */
    public function json(array $findings, Target $target, int $fileCount): string
    {
        return json_encode([
            'target' => ['driver' => $target->driver, 'version' => $target->label(), 'versioned' => $target->isVersioned()],
            'files' => $fileCount,
            'summary' => Linter::summarise($findings),
            'findings' => array_map(fn (Finding $f) => $f->toArray(), $findings),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * GitHub's annotation format, so findings land on the diff itself.
     *
     * Worth having because a warning on the changed line is read and the same
     * warning in a log is not.
     */
    public function annotations(array $findings): string
    {
        $out = [];

        foreach ($findings as $finding) {
            $level = $finding->severity === Finding::NOTICE ? 'notice' : 'warning';
            $message = str_replace(["\n", '%'], [' ', '%25'], "{$finding->summary}. {$finding->because}. Instead: {$finding->instead}");

            $out[] = "::{$level} file={$finding->file},line={$finding->line},title=safe-migrations {$finding->rule}::{$message}";
        }

        return implode("\n", $out) . "\n";
    }
}
