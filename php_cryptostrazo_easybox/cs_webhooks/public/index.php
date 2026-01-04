<?php
declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/src/autoload.php';

use CryptoStrazo\Client\Bootstrap;
use CryptoStrazo\Client\Http;
use CryptoStrazo\Client\WebhookReceiver;

try {
    $cfg = Bootstrap::loadConfig($root . '/config/cryptostrazo.php');
    $req = Http::fromGlobals();

    if ($req->method === 'GET' && $req->path === '/health') {
        Http::json(200, ['ok' => true]);
        exit;
    }

    if ($req->path === '/webhooks/cryptostrazo' && $req->method === 'POST') {
        (new WebhookReceiver($cfg))->handleIncoming($req);
        exit;
    }

    if ($req->path === '/webhooks/cryptostrazo/last' && $req->method === 'GET') {
        (new WebhookReceiver($cfg))->handleLastView($req);
        exit;
    }

    Http::json(404, ['ok' => false, 'error' => 'not_found']);
} catch (Throwable $e) {
    Http::json(500, ['ok' => false, 'error' => 'internal_error']);
}
