<?php
/*
 * Case review form handler for carbonmonoxidekills.com (form: content/_case_form.html).
 *
 * POST only. Validates the fields, emails them to the team and keeps a copy in cmk-private/,
 * which .htaccess closes to the web, so an enquiry is never lost if email delivery fails.
 * Success and failure both end in a 303 redirect, so a page refresh never re-sends the form.
 */
declare(strict_types=1);

const RECIPIENT = '';                                   // team inbox; set before go-live
const SENDER = 'noreply@carbonmonoxidekills.com';       // must be on this domain or the host rejects it
const FORM_PAGE = '/review.html';
const THANKS_PAGE = '/thank-you.html';
const STORE_DIR = __DIR__ . '/cmk-private/case-reviews';
const MAX_PER_HOUR = 5;                                  // per IP address
const MIN_SECONDS = 4;                                   // humans need longer than this to fill the form

const READING_BY = ['', 'Fire department', 'Heating engineer', 'Energy company', 'Other', 'No reading was taken'];
const MEDICAL = ['ER', 'Doctor', 'Both ER and doctor', 'No'];
const BLOOD = ['Yes', 'No', 'Not sure'];
const DELAY = ['', 'Less than 1 hour', '1 to 3 hours', '3 to 6 hours', '6 to 12 hours', 'More than 12 hours', 'Not sure'];
const STATES = ['Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut', 'Delaware',
    'District of Columbia', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky',
    'Louisiana', 'Maine', 'Maryland', 'Massachusetts', 'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana',
    'Nebraska', 'Nevada', 'New Hampshire', 'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota', 'Ohio',
    'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah',
    'Vermont', 'Virginia', 'Washington', 'West Virginia', 'Wisconsin', 'Wyoming'];

function back(string $error): never
{
    header('Location: ' . FORM_PAGE . '?error=' . rawurlencode($error) . '#case-form', true, 303);
    exit;
}

function done(): never
{
    header('Location: ' . THANKS_PAGE, true, 303);
    exit;
}

/** First $max characters; byte-based if the host lacks mbstring. */
function cut(string $s, int $max): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

/** One line of trimmed text, control characters removed, cut to $max characters. */
function line(string $key, int $max): string
{
    $v = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '';
    return cut(trim($v), $max);
}

/** Multi-line text: keeps line breaks, removes other control characters. */
function block(string $key, int $max): string
{
    $v = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $v) ?? '';
    return cut(trim($v), $max);
}

function choice(string $key, array $allowed): ?string
{
    $v = line($key, 100);
    return in_array($v, $allowed, true) ? $v : null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . FORM_PAGE . '#case-form', true, 303);
    exit;
}

// Spam: bots fill the hidden field or submit within seconds. They get the normal thank-you page and nothing is sent.
$started = (int) line('started', 20);
if (line('website', 200) !== '' || ($started > 0 && time() - intdiv($started, 1000) < MIN_SECONDS)) {
    done();
}

if (!is_dir(STORE_DIR) && !mkdir(STORE_DIR, 0700, true) && !is_dir(STORE_DIR)) {
    error_log('case-review: cannot create ' . STORE_DIR);
}

// Rate limit per IP, counted in a small file next to the stored enquiries.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateFile = STORE_DIR . '/rate-' . hash('sha256', $ip) . '.txt';
$recent = [];
if (is_file($rateFile)) {
    $recent = array_filter(array_map('intval', file($rateFile, FILE_IGNORE_NEW_LINES) ?: []), fn($t) => $t > time() - 3600);
}
if (count($recent) >= MAX_PER_HOUR) {
    back('rate');
}

$f = [
    'Name' => line('name', 120),
    'Street address' => line('street', 200),
    'City' => line('city', 100),
    'State' => choice('state', STATES),
    'ZIP code' => line('zip', 10),
    'Telephone' => line('phone', 30),
    'Date of incident' => line('incident_date', 10),
    'Believed cause' => block('cause', 2000),
    'Believed responsible' => line('responsible', 300),
    'CO reading at scene (ppm)' => line('ppm', 10),
    'Reading taken by' => choice('reading_by', READING_BY),
    'Reading taken by (other)' => line('reading_by_other', 200),
    'Other evidence' => block('evidence', 2000),
    'ER or doctor' => choice('medical', MEDICAL),
    'Blood work done' => choice('blood_test', BLOOD),
    'Blood reading (COHb)' => line('blood_reading', 60),
    'Blood work taken after' => choice('blood_delay', DELAY),
];

foreach (['Name', 'Street address', 'City', 'Telephone', 'Believed cause'] as $k) {
    if ($f[$k] === '') {
        back('missing');
    }
}
if ($f['State'] === null || $f['ER or doctor'] === null || $f['Blood work done'] === null
    || $f['Reading taken by'] === null || $f['Blood work taken after'] === null) {
    back('missing');
}
if (line('consent', 5) !== 'yes') {
    back('consent');
}
if (!preg_match('/^[0-9 +().-]{7,30}$/', $f['Telephone'])) {
    back('phone');
}
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $f['Date of incident']);
if (!$date || $date->format('Y-m-d') !== $f['Date of incident'] || $date > new DateTimeImmutable('tomorrow')) {
    back('date');
}
if ($f['CO reading at scene (ppm)'] !== '' && !ctype_digit($f['CO reading at scene (ppm)'])) {
    back('ppm');
}
if ($f['Reading taken by'] !== 'Other') {
    $f['Reading taken by (other)'] = '';
}
if ($f['Blood work done'] !== 'Yes') {
    $f['Blood reading (COHb)'] = '';
    $f['Blood work taken after'] = '';
}

$received = gmdate('Y-m-d H:i:s') . ' UTC';
$id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));

// Keep a copy first, so the enquiry survives a mail failure.
$record = ['id' => $id, 'received' => $received, 'ip' => $ip] + $f;
$stored = @file_put_contents(STORE_DIR . '/' . gmdate('Y-m') . '.jsonl',
    json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX) !== false;
@file_put_contents($rateFile, implode("\n", [...$recent, time()]) . "\n", LOCK_EX);

$body = "New case review from carbonmonoxidekills.com\nReference: $id\nReceived: $received\n\n";
foreach ($f as $label => $value) {
    if ($value !== '' && $value !== null) {
        $body .= str_pad($label . ':', 28) . str_replace("\n", "\n" . str_repeat(' ', 28), (string) $value) . "\n";
    }
}

$sent = false;
if (RECIPIENT !== '') {
    $subject = '=?UTF-8?B?' . base64_encode("Case review: {$f['Name']}, {$f['State']}") . '?=';
    $headers = implode("\r\n", [
        'From: Carbon Monoxide Kills <' . SENDER . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    $sent = mail(RECIPIENT, $subject, $body, $headers, '-f' . SENDER);
}
if (!$sent) {
    error_log("case-review: email not sent for $id (stored: " . ($stored ? 'yes' : 'no') . ')');
}
if (!$sent && !$stored) {
    back('server');
}
done();
