<?php
// Emails the RSVP CSV (same columns as the admin page's download) to notify_email.
// Run by a Hostinger cron job every hour on Sundays (0 * * * 0); it only sends at noon Eastern,
// so daylight saving time and the server's own time zone don't matter.
//
//   php weekly-report.php          -> sends only if it's Sunday, 12:xx in New York
//   php weekly-report.php --now    -> sends immediately (for testing)

declare(strict_types=1);

// Command line only: a web visitor must not be able to trigger it.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const RSVP_HELPERS_ONLY = true;
require __DIR__ . '/api.php';

$now = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
if (!in_array('--now', $argv, true) && $now->format('w H') !== '0 12') exit;

$config = load_config();
$to = trim((string)($config['notify_email'] ?? ''));
$from = trim((string)($config['from_email'] ?? ''));
if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "notify_email or from_email is missing in config.php\n");
    exit(1);
}

$data = read_data();
$households = $data['households'];
uasort($households, fn($a, $b) => strcasecmp(household_label($a['guests']), household_label($b['guests'])));

// Mirrors the admin page's CSV: one row per guest, household columns on the first row only.
// Every cell is quoted, and values a spreadsheet would treat as a formula are defused (guests write the notes).
$cell = function (mixed $v): string {
    $s = (string)$v;
    if (preg_match('/^[=+\-@\t\r]/', $s)) $s = "'" . $s;
    return '"' . str_replace('"', '""', $s) . '"';
};
$rows = [['Household', 'First name', 'Last name', 'Attending', 'Children invited', 'Children attending', 'Note', 'Last updated (UTC)']];
foreach ($households as $h) {
    $label = household_label($h['guests']);
    foreach (array_values($h['guests']) as $i => $g) {
        $a = $g['attending'] ?? null;
        $rows[] = [
            $label, $g['first'], $g['last'],
            $a === true ? 'Yes' : ($a === false ? 'No' : ''),
            $i === 0 ? (int)($h['max_children'] ?? 0) : '', $i === 0 ? (int)($h['children_attending'] ?? 0) : '',
            $i === 0 ? ($h['notes'] ?? '') : '', $i === 0 ? ($h['updated_at'] ?? '') : '',
        ];
    }
}
$csv = "\u{FEFF}" . implode("\r\n", array_map(fn($r) => implode(',', array_map($cell, $r)), $rows));

$t = rsvp_totals($data);
$kids = $t['children'] === 1 ? '1 child' : "{$t['children']} children";
$body = implode("\n", [
    'Weekly RSVP summary. The full list is attached as a CSV.',
    '',
    "{$t['attending']} attending (+$kids), {$t['declined']} declined, {$t['pending']} not yet responded.",
    "{$t['responded']} of {$t['households']} households have responded.",
]);

$date = $now->format('Y-m-d');
$boundary = 'rsvp-' . bin2hex(random_bytes(12));
$message = implode("\r\n", [
    "--$boundary",
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    '',
    $body,
    "--$boundary",
    "Content-Type: text/csv; charset=UTF-8; name=\"rsvps-$date.csv\"",
    'Content-Transfer-Encoding: base64',
    "Content-Disposition: attachment; filename=\"rsvps-$date.csv\"",
    '',
    rtrim(chunk_split(base64_encode($csv), 76, "\r\n")),
    "--$boundary--",
    '',
]);
$headers = implode("\r\n", [
    "From: Wedding RSVP <$from>",
    'MIME-Version: 1.0',
    "Content-Type: multipart/mixed; boundary=\"$boundary\"",
]);

if (!mail($to, "Weekly RSVP summary: $date", $message, $headers, '-f' . $from)) {
    fwrite(STDERR, "mail() failed\n");
    exit(1);
}
