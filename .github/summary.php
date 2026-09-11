<?php

/**
 * Turn a safe-migrations JSON report into the block a reviewer reads on the run.
 *
 * Built from the report the lint already produced rather than from a second
 * pass, and deliberately short: the annotations carry the detail onto the diff,
 * so this is the one line somebody reads before opening anything.
 *
 * Usage: php summary.php <report.json>
 */

$path = $argv[1] ?? 'safe-migrations.json';
$raw = is_readable($path) ? file_get_contents($path) : false;
$report = $raw === false ? null : json_decode($raw, true);

if (! is_array($report)) {
    fwrite(STDERR, "could not read {$path}\n");
    exit(2);
}

$summary = $report['summary'];
$target = $report['target'];
$files = $report['files'];

$out = "### safe-migrations\n\n";
$out .= "{$files} migration(s) against {$target['version']}\n\n";

if ($summary['total'] === 0) {
    $out .= "Nothing that will lock a table or break a rolling deploy.\n";
} else {
    $parts = [];
    if ($summary['blocking']) {
        $parts[] = "{$summary['blocking']} that will lock a table";
    }
    if ($summary['rolling']) {
        $parts[] = "{$summary['rolling']} that will break a rolling deploy";
    }
    if ($summary['notice']) {
        $parts[] = "{$summary['notice']} worth reading";
    }

    $out .= '**'.implode(', ', $parts).".** Each one is annotated on the diff.\n";
}

// Worth saying out loud. Without a version the rules assumed the oldest
// behaviour, so a finding here may be describing a problem the reader's
// database stopped having years ago.
if (! $target['versioned']) {
    $out .= "\nNo server version was given, so every version dependent rule assumed the oldest behaviour.\n";
}

echo $out;
