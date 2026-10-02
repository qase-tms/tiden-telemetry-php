<?php

declare(strict_types=1);

/*
 * Router for `php -S` used by CurlTransportTest. Switches on the request path and
 * appends one JSON line per request to the file named by TIDEN_ROUTER_LOG.
 *
 *   /ok               200
 *   /fail             500
 *   /limited          429 with Retry-After: 1
 *   /limited-default  429 without Retry-After
 *   /slow             sleeps 1 s, then 200
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$body = (string) file_get_contents('php://input');

$log = getenv('TIDEN_ROUTER_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode([
        'path' => $path,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
        'body' => $body,
    ])."\n", FILE_APPEND | LOCK_EX);
}

switch ($path) {
    case '/ok':
        http_response_code(200);
        break;
    case '/fail':
        http_response_code(500);
        break;
    case '/limited':
        http_response_code(429);
        header('Retry-After: 1');
        break;
    case '/limited-default':
        http_response_code(429);
        break;
    case '/slow':
        sleep(1);
        http_response_code(200);
        break;
    default:
        http_response_code(404);
}
