<?php
// RSVP backend. Every request is a JSON POST with an "action".
//
// Guest actions (shared password from the invitation):
//   login  { password }                    -> unlocks the session
//   search { query }                       -> households whose guests match a last name
//   submit { id, guests, children, notes } -> saves one household's response, emails a notification
//
// Admin actions (separate admin password, used by /rsvp/admin/):
//   admin_login  { password }
//   admin_list                             -> every household, full detail
//   admin_save   { id?, expected_updated_at, household } -> adds (no id) or replaces a household
//   admin_delete { id, expected_updated_at }
//
// The guest list and config live outside the web root (see README), so the
// FTP deploy never overwrites them and they can't be downloaded directly.

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/../../rsvp-data';
const DATA_FILE = DATA_DIR . '/rsvps.json';
const CONFIG_FILE = DATA_DIR . '/config.php';
const MAX_RESULTS = 10;
const MAX_NOTES_LENGTH = 1000;
const MAX_GUESTS_PER_HOUSEHOLD = 12;
const MAX_CHILDREN = 20;
const MAX_NAME_LENGTH = 100;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function load_config(): ?array {
    if (!is_file(CONFIG_FILE)) return null;
    $config = require CONFIG_FILE;
    return is_array($config) ? $config : null;
}

// Lowercases, strips accents and punctuation so "García-López" and "garcia lopez" compare equal.
function normalize(string $s): string {
    if (class_exists('Normalizer')) {
        $s = Normalizer::normalize($s, Normalizer::FORM_D);
        $s = preg_replace('/\p{Mn}/u', '', $s);
    } elseif (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($converted !== false) $s = $converted;
    }
    $s = mb_strtolower($s, 'UTF-8');
    $s = str_replace(["'", '’', '.'], '', $s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return trim($s);
}

// "Alex & Jordan Smith", or "Pat Smith & Casey Nguyen" when last names differ.
function household_label(array $guests): string {
    $lasts = array_values(array_unique(array_map(fn($g) => $g['last'], $guests)));
    $names = count($lasts) === 1
        ? array_map(fn($g) => $g['first'], $guests)
        : array_map(fn($g) => trim($g['first'] . ' ' . $g['last']), $guests);
    $names = array_values(array_filter($names, fn($n) => $n !== ''));
    $joined = count($names) <= 2
        ? implode(' & ', $names)
        : implode(', ', array_slice($names, 0, -1)) . ' & ' . end($names);
    return count($lasts) === 1 ? trim($joined . ' ' . $lasts[0]) : $joined;
}

function guest_matches(array $guest, string $query): bool {
    $last = normalize($guest['last']);
    $full = normalize($guest['first'] . ' ' . $guest['last']);
    if (str_starts_with($last, $query) || str_starts_with($full, $query)) return true;
    // Each part of a compound last name ("garcia lopez") is searchable on its own.
    foreach (explode(' ', $last) as $part) {
        if ($part !== '' && str_starts_with($part, $query)) return true;
    }
    return false;
}

// Shape sent to the guest page: only what the form needs.
function public_household(string $id, array $h): array {
    return [
        'id' => $id,
        'label' => household_label($h['guests']),
        'guests' => array_map(fn($g) => [
            'name' => trim($g['first'] . ' ' . $g['last']),
            'attending' => $g['attending'] ?? null,
        ], $h['guests']),
        'max_children' => (int)($h['max_children'] ?? 0),
        'children_attending' => (int)($h['children_attending'] ?? 0),
        'notes' => $h['notes'] ?? '',
        'responded' => (bool)($h['responded'] ?? false),
    ];
}

// Shape sent to the admin page: everything, with names kept separate for editing.
function admin_household(string $id, array $h): array {
    return [
        'id' => $id,
        'label' => household_label($h['guests']),
        'guests' => array_map(fn($g) => [
            'first' => $g['first'],
            'last' => $g['last'],
            'attending' => $g['attending'] ?? null,
        ], $h['guests']),
        'max_children' => (int)($h['max_children'] ?? 0),
        'children_attending' => (int)($h['children_attending'] ?? 0),
        'notes' => $h['notes'] ?? '',
        'responded' => (bool)($h['responded'] ?? false),
        'updated_at' => $h['updated_at'] ?? null,
    ];
}

function rsvp_totals(array $data): array {
    $t = ['households' => 0, 'responded' => 0, 'attending' => 0, 'declined' => 0, 'pending' => 0, 'children' => 0];
    foreach ($data['households'] as $h) {
        $t['households']++;
        if (!empty($h['responded'])) $t['responded']++;
        foreach ($h['guests'] as $g) {
            $a = $g['attending'] ?? null;
            if ($a === true) $t['attending']++;
            elseif ($a === false) $t['declined']++;
            else $t['pending']++;
        }
        $t['children'] += (int)($h['children_attending'] ?? 0);
    }
    return $t;
}

// Reads the data file under a shared lock, so a read never sees a write that's halfway done.
function read_data(): array {
    $fp = is_file(DATA_FILE) ? @fopen(DATA_FILE, 'r') : false;
    if ($fp === false) respond(500, ['error' => 'RSVPs are not set up yet. Please try again later.']);
    try {
        flock($fp, LOCK_SH);
        $data = json_decode(stream_get_contents($fp), true);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    if (!is_array($data) || !isset($data['households'])) respond(500, ['error' => 'Something went wrong on our end. Please try again later.']);
    return $data;
}

// Opens the data file under an exclusive lock so simultaneous submissions can't clobber each other.
function with_locked_data(callable $fn): mixed {
    $fp = is_file(DATA_FILE) ? @fopen(DATA_FILE, 'r+') : false;
    if ($fp === false) respond(500, ['error' => 'RSVPs are not set up yet. Please try again later.']);
    try {
        flock($fp, LOCK_EX);
        $data = json_decode(stream_get_contents($fp), true);
        if (!is_array($data) || !isset($data['households'])) {
            respond(500, ['error' => 'Something went wrong on our end. Please try again later.']);
        }
        return $fn($data, function (array $updated) use ($fp) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            fflush($fp);
        });
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// Emails a summary of one household's response. Failures are ignored: the RSVP is already saved.
function send_notification(array $h, bool $was_responded, array $totals): void {
    $config = load_config();
    $to = trim((string)($config['notify_email'] ?? ''));
    $from = trim((string)($config['from_email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) return;

    $label = household_label($h['guests']);
    $lines = [$label . ($was_responded ? ' changed their RSVP:' : ' RSVP\'d:'), ''];
    foreach ($h['guests'] as $g) {
        $lines[] = '  ' . trim($g['first'] . ' ' . $g['last']) . ': ' . ($g['attending'] ? 'attending' : 'not attending');
    }
    $max = (int)($h['max_children'] ?? 0);
    if ($max > 0) $lines[] = "  Children attending: {$h['children_attending']} (of $max invited)";
    if (($h['notes'] ?? '') !== '') array_push($lines, '', 'Note: ' . $h['notes']);
    $kids = $totals['children'] === 1 ? '1 child' : "{$totals['children']} children";
    array_push($lines, '',
        "Totals so far: {$totals['attending']} attending (+$kids), "
        . "{$totals['declined']} declined, {$totals['pending']} not yet responded.");

    // Names come from the guest list; strip line breaks so they can't inject email headers.
    $subject = preg_replace('/[\r\n]+/', ' ', ($was_responded ? 'RSVP changed: ' : 'RSVP: ') . $label);
    $headers = implode("\r\n", [
        "From: Wedding RSVP <$from>",
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    @mail($to, mb_encode_mimeheader($subject, 'UTF-8', 'Q'), implode("\n", $lines), $headers, '-f' . $from);
}

// Validates a household sent from the admin page and returns it in storage shape.
function clean_admin_household(mixed $in): array {
    if (!is_array($in) || !is_array($in['guests'] ?? null)) respond(400, ['error' => 'Invalid request']);
    $guests = [];
    foreach ($in['guests'] as $g) {
        $first = trim((string)($g['first'] ?? ''));
        $last = trim((string)($g['last'] ?? ''));
        $attending = $g['attending'] ?? null;
        if ($first === '' || $last === '') respond(400, ['error' => 'Every guest needs a first and last name.']);
        if (mb_strlen($first) > MAX_NAME_LENGTH || mb_strlen($last) > MAX_NAME_LENGTH) respond(400, ['error' => 'That name is too long.']);
        if (!in_array($attending, [true, false, null], true)) respond(400, ['error' => 'Invalid attendance value.']);
        $guests[] = ['first' => $first, 'last' => $last, 'attending' => $attending];
    }
    if (count($guests) === 0) respond(400, ['error' => 'A household needs at least one guest.']);
    if (count($guests) > MAX_GUESTS_PER_HOUSEHOLD) respond(400, ['error' => 'That household has too many guests.']);

    $max = $in['max_children'] ?? 0;
    $children = $in['children_attending'] ?? 0;
    if (!is_int($max) || $max < 0 || $max > MAX_CHILDREN) respond(400, ['error' => 'Children invited must be between 0 and ' . MAX_CHILDREN . '.']);
    if (!is_int($children) || $children < 0 || $children > $max) respond(400, ['error' => 'Children attending can\'t be more than children invited.']);

    $notes = trim((string)($in['notes'] ?? ''));
    if (mb_strlen($notes) > MAX_NOTES_LENGTH) respond(400, ['error' => 'That note is too long.']);

    $anyone = in_array(true, array_column($guests, 'attending'), true);
    return [
        'guests' => $guests,
        'max_children' => $max,
        'children_attending' => $anyone ? $children : 0,
        // A household counts as responded once every guest has an answer.
        'responded' => !in_array(null, array_column($guests, 'attending'), true),
        'notes' => $notes,
    ];
}

// Stops an admin edit from overwriting a change (such as a guest's RSVP) made after the page loaded it.
function require_unchanged(array $data, string $id, mixed $expected): void {
    if (!isset($data['households'][$id])) respond(404, ['error' => 'That household no longer exists. Reload the page.']);
    if (($data['households'][$id]['updated_at'] ?? null) !== $expected) {
        respond(409, ['error' => 'This household changed since you loaded the page (a guest may have just RSVP\'d). Reload, then make your change again.']);
    }
}

function next_household_id(array $data): string {
    $max = 0;
    foreach (array_keys($data['households']) as $id) {
        if (preg_match('/^h(\d+)$/', (string)$id, $m)) $max = max($max, (int)$m[1]);
    }
    return sprintf('h%03d', $max + 1);
}

function check_password(string $given, string $expected): void {
    if ($expected === '' || !hash_equals($expected, $given)) {
        sleep(1); // slows down guessing
        respond(401, ['error' => "That password didn't work."]);
    }
}

// --- Request handling ---

// weekly-report.php includes this file for the helpers above and stops here.
if (defined('RSVP_HELPERS_ONLY')) return;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['error' => 'Method not allowed']);
// Requiring a JSON body means other sites can't submit to this endpoint with a plain HTML form.
if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) respond(415, ['error' => 'Expected JSON']);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) respond(400, ['error' => 'Invalid request']);

session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

$action = $input['action'] ?? '';

if ($action === 'login') {
    $config = load_config();
    if ($config === null) respond(500, ['error' => 'RSVPs are not set up yet. Please try again later.']);
    // Forgiving comparison: guests shouldn't fail over capitalization or a stray space.
    $given = mb_strtolower(trim((string)($input['password'] ?? '')), 'UTF-8');
    $expected = mb_strtolower(trim((string)($config['password'] ?? '')), 'UTF-8');
    if ($expected === '' || !hash_equals($expected, $given)) {
        sleep(1);
        respond(401, ['error' => "That password didn't work. Check your invitation and try again."]);
    }
    session_regenerate_id(true);
    $_SESSION['rsvp_ok'] = true;
    respond(200, ['ok' => true]);
}

if ($action === 'status') {
    respond(200, ['authenticated' => !empty($_SESSION['rsvp_ok'])]);
}

if ($action === 'admin_login') {
    $config = load_config();
    if ($config === null) respond(500, ['error' => 'RSVPs are not set up yet: config.php is missing.']);
    if (trim((string)($config['admin_password'] ?? '')) === '') respond(500, ['error' => 'No admin_password is set in config.php.']);
    check_password((string)($input['password'] ?? ''), (string)$config['admin_password']);
    session_regenerate_id(true);
    $_SESSION['rsvp_admin'] = true;
    respond(200, ['ok' => true]);
}

if ($action === 'admin_status') {
    respond(200, ['authenticated' => !empty($_SESSION['rsvp_admin'])]);
}

if ($action === 'admin_logout') {
    unset($_SESSION['rsvp_admin']);
    respond(200, ['ok' => true]);
}

if (str_starts_with($action, 'admin_')) {
    if (empty($_SESSION['rsvp_admin'])) respond(401, ['error' => 'Please log in again.', 'needs_admin_login' => true]);

    if ($action === 'admin_list') {
        $data = read_data();
        $households = [];
        foreach ($data['households'] as $id => $h) $households[] = admin_household((string)$id, $h);
        usort($households, fn($a, $b) => strcasecmp($a['label'], $b['label']));
        respond(200, ['households' => $households]);
    }

    if ($action === 'admin_save') {
        $id = (string)($input['id'] ?? '');
        $clean = clean_admin_household($input['household'] ?? null);
        $expected = $input['expected_updated_at'] ?? null;

        $saved = with_locked_data(function (array $data, callable $save) use ($id, $clean, $expected) {
            if ($id === '') {
                $id = next_household_id($data);
                $existing = [];
            } else {
                require_unchanged($data, $id, $expected);
                $existing = $data['households'][$id];
            }
            // Merge so any extra fields you add to the JSON by hand are kept.
            $data['households'][$id] = array_merge($existing, $clean, ['updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
            $save($data);
            return admin_household($id, $data['households'][$id]);
        });
        respond(200, ['household' => $saved]);
    }

    if ($action === 'admin_delete') {
        $id = (string)($input['id'] ?? '');
        $expected = $input['expected_updated_at'] ?? null;
        with_locked_data(function (array $data, callable $save) use ($id, $expected) {
            require_unchanged($data, $id, $expected);
            unset($data['households'][$id]);
            $save($data);
        });
        respond(200, ['ok' => true]);
    }

    respond(400, ['error' => 'Unknown action']);
}

if (empty($_SESSION['rsvp_ok'])) respond(401, ['error' => 'Please enter the password from your invitation.', 'needs_login' => true]);

if ($action === 'search') {
    $query = normalize((string)($input['query'] ?? ''));
    if (mb_strlen($query) < 2) respond(400, ['error' => 'Please enter at least two letters of your last name.']);

    $data = read_data();

    $matches = [];
    foreach ($data['households'] as $id => $h) {
        foreach ($h['guests'] as $g) {
            if (guest_matches($g, $query)) {
                $matches[] = public_household((string)$id, $h);
                break;
            }
        }
    }
    usort($matches, fn($a, $b) => strcasecmp($a['label'], $b['label']));
    respond(200, ['households' => array_slice($matches, 0, MAX_RESULTS)]);
}

if ($action === 'submit') {
    $id = (string)($input['id'] ?? '');
    $answers = $input['guests'] ?? null;
    $children = $input['children'] ?? 0;
    $notes = trim((string)($input['notes'] ?? ''));

    if (!is_array($answers)) respond(400, ['error' => 'Invalid request']);
    if (mb_strlen($notes) > MAX_NOTES_LENGTH) respond(400, ['error' => 'Your note is a little too long. Please shorten it.']);

    $result = with_locked_data(function (array $data, callable $save) use ($id, $answers, $children, $notes) {
        if (!isset($data['households'][$id])) respond(404, ['error' => "We couldn't find that invitation. Please search again."]);
        $h = &$data['households'][$id];
        $was_responded = !empty($h['responded']);

        // Only attendance can change; names and the guest count come from the guest list.
        if (count($answers) !== count($h['guests'])) respond(400, ['error' => 'Invalid request']);
        foreach ($h['guests'] as $i => &$g) {
            if (!is_bool($answers[$i] ?? null)) respond(400, ['error' => 'Please let us know whether each guest is attending.']);
            $g['attending'] = $answers[$i];
        }
        unset($g);

        $max = (int)($h['max_children'] ?? 0);
        if (!is_int($children) || $children < 0 || $children > $max) respond(400, ['error' => 'Invalid number of children.']);
        $anyone = in_array(true, $answers, true);
        $h['children_attending'] = $anyone ? $children : 0;
        $h['notes'] = $notes;
        $h['responded'] = true;
        $h['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');

        $save($data);
        return ['household' => $h, 'was_responded' => $was_responded, 'totals' => rsvp_totals($data)];
    });

    // Sent after the file is unlocked so a slow mail server never holds up other guests.
    send_notification($result['household'], $result['was_responded'], $result['totals']);
    respond(200, ['household' => public_household($id, $result['household'])]);
}

respond(400, ['error' => 'Unknown action']);
