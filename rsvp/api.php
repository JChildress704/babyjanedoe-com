<?php
// RSVP backend. Every request is a JSON POST with an "action":
//   login  { password }                 -> unlocks the session
//   search { query }                    -> households whose guests match a last name
//   submit { id, guests, children, notes } -> saves one household's response
//
// The guest list and config live outside the web root (see README), so the
// FTP deploy never overwrites them and they can't be downloaded directly.

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/../../rsvp-data';
const DATA_FILE = DATA_DIR . '/rsvps.json';
const CONFIG_FILE = DATA_DIR . '/config.php';
const MAX_RESULTS = 10;
const MAX_NOTES_LENGTH = 1000;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
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
    $lasts = array_unique(array_map(fn($g) => $g['last'], $guests));
    $names = count($lasts) === 1
        ? array_map(fn($g) => $g['first'], $guests)
        : array_map(fn($g) => trim($g['first'] . ' ' . $g['last']), $guests);
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

// Shape sent to the browser: only what the form needs.
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

// --- Request handling ---

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['error' => 'Method not allowed']);
// Requiring a JSON body means other sites can't submit to this endpoint with a plain HTML form.
if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) respond(415, ['error' => 'Expected JSON']);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) respond(400, ['error' => 'Invalid request']);

session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

$action = $input['action'] ?? '';

if ($action === 'login') {
    if (!is_file(CONFIG_FILE)) respond(500, ['error' => 'RSVPs are not set up yet. Please try again later.']);
    $config = require CONFIG_FILE;
    // Forgiving comparison: guests shouldn't fail over capitalization or a stray space.
    $given = mb_strtolower(trim((string)($input['password'] ?? '')), 'UTF-8');
    $expected = mb_strtolower(trim((string)$config['password']), 'UTF-8');
    if ($expected === '' || !hash_equals($expected, $given)) {
        sleep(1); // slows down guessing
        respond(401, ['error' => "That password didn't work. Check your invitation and try again."]);
    }
    session_regenerate_id(true);
    $_SESSION['rsvp_ok'] = true;
    respond(200, ['ok' => true]);
}

if ($action === 'status') {
    respond(200, ['authenticated' => !empty($_SESSION['rsvp_ok'])]);
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

    $saved = with_locked_data(function (array $data, callable $save) use ($id, $answers, $children, $notes) {
        if (!isset($data['households'][$id])) respond(404, ['error' => "We couldn't find that invitation. Please search again."]);
        $h = &$data['households'][$id];

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
        return public_household($id, $h);
    });
    respond(200, ['household' => $saved]);
}

respond(400, ['error' => 'Unknown action']);
