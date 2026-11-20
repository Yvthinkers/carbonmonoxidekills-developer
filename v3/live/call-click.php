<?php
/*
 * Counts presses on the site's phone buttons (tel: links), sent by site.js with navigator.sendBeacon.
 * Stores only the time and the page path: no IP address, cookie or anything about the visitor.
 * One line per press in cmk-private/calls/<yyyy-mm>.jsonl (closed to the web); the blog editor
 * dashboard shows the totals.
 */
declare(strict_types=1);

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
$raw = (string) file_get_contents('php://input', false, null, 0, 400);
$page = trim($raw);
// Only a plain path from this site, e.g. /carbon-monoxide-lawyer-2/texas/
if (!preg_match('~^/[A-Za-z0-9/._-]{0,200}$~', $page)) {
    http_response_code(204);
    exit;
}
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|headless/i', $ua)) {
    http_response_code(204);
    exit;
}
$dir = __DIR__ . '/cmk-private/calls';
if (!is_dir($dir)) {
    @mkdir($dir, 0700, true);
}
@file_put_contents($dir . '/' . gmdate('Y-m') . '.jsonl',
    json_encode(['t' => gmdate('c'), 'page' => $page], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
http_response_code(204);
