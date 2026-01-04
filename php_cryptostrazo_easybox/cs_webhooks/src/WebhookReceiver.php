<?php
declare(strict_types=1);

namespace CryptoStrazo\Client;

final class WebhookReceiver
{
    private const SOURCE_LABEL = 'https://cryptostrazo.com/';

    private array $cfg;
    private Db $db;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $this->db  = new Db($cfg);
    }

    public function handleIncoming(Http $req): void
    {
        if ($req->method !== 'POST') {
            Http::json(405, ['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }

        $deliveryHeader  = (string)($this->cfg['delivery_header'] ?? 'X-STRZ-Delivery-Id');
        $timestampHeader = (string)($this->cfg['timestamp_header'] ?? 'X-STRZ-Timestamp');
        $signatureHeader = (string)($this->cfg['signature_header'] ?? 'X-STRZ-Signature');
        $eventHeader     = (string)($this->cfg['event_header'] ?? 'X-STRZ-Event');

        $deliveryUid = $req->header($deliveryHeader);
        $tsStr       = $req->header($timestampHeader);
        $sig         = $req->header($signatureHeader);
        $event       = $req->header($eventHeader);

        if ($tsStr === '' || !ctype_digit($tsStr)) {
            Http::json(401, ['ok' => false, 'error' => 'invalid_timestamp']);
            return;
        }

        $ts = (int)$tsStr;
        $maxDrift = (int)($this->cfg['max_drift_seconds'] ?? 300);
        if ($maxDrift > 0) {
            $drift = abs(time() - $ts);
            if ($drift > $maxDrift) {
                Http::json(401, ['ok' => false, 'error' => 'timestamp_out_of_range']);
                return;
            }
        }

        if ($sig === '') {
            Http::json(401, ['ok' => false, 'error' => 'missing_signature']);
            return;
        }

        $secret  = (string)($this->cfg['secret'] ?? '');
        $baseFmt = (string)($this->cfg['signature_base_format'] ?? '{timestamp}.{body}');
        if (!Signature::verify($secret, $ts, $req->body, $sig, $baseFmt)) {
            Http::json(401, ['ok' => false, 'error' => 'bad_signature']);
            return;
        }

        $payload = json_decode($req->body, true);
        if (!is_array($payload)) {
            Http::json(400, ['ok' => false, 'error' => 'bad_json']);
            return;
        }

        $resolvedEvent = $event !== '' ? $event : (string)($payload['event'] ?? '');
        $resolvedEvent = $resolvedEvent !== '' ? $resolvedEvent : null;

        // Extract invoice fields for readable DB columns (optional, only if present)
        $extracted = $this->extractInvoiceFields($payload);

        // Store VERIFIED raw JSON + extracted columns
        $res = $this->db->storeInbox($deliveryUid, $resolvedEvent, $req->body, $extracted);
        if (!($res['ok'] ?? false)) {
            Http::json(500, ['ok' => false, 'error' => 'storage_failed']);
            return;
        }

        if (($res['duplicate'] ?? false) === true) {
            Http::json(200, ['ok' => true, 'duplicate' => true]);
            return;
        }

        Http::json(200, ['ok' => true]);
    }

    private function extractInvoiceFields(array $payload): array
    {
        $inv = $payload['invoice'] ?? null;
        if (!is_array($inv)) return [
            'created_at' => is_string($payload['created_at'] ?? null) ? (string)$payload['created_at'] : null,
        ];

        // Keep everything as strings (safe across DBs)
        return [
            'created_at'        => is_string($payload['created_at'] ?? null) ? (string)$payload['created_at'] : null,

            'invoice_id'        => isset($inv['id']) ? (string)$inv['id'] : null,
            'invoice_public_id' => isset($inv['public_id']) ? (string)$inv['public_id'] : null,
            'external_id'       => isset($inv['external_id']) ? (string)$inv['external_id'] : null,
            'kind'              => isset($inv['kind']) ? (string)$inv['kind'] : null,
            'status'            => isset($inv['status']) ? (string)$inv['status'] : null,
            'amount_base'       => isset($inv['amount_base']) ? (string)$inv['amount_base'] : null,
            'amount_total'      => isset($inv['amount_total']) ? (string)$inv['amount_total'] : null,
            'currency'          => isset($inv['currency']) ? (string)$inv['currency'] : null,
            'wallet_address'    => isset($inv['wallet_address']) ? (string)$inv['wallet_address'] : null,
            'network'           => isset($inv['network']) ? (string)$inv['network'] : null,
            'tx_id'             => isset($inv['tx_id']) ? (string)$inv['tx_id'] : null,
            'paid_at'           => isset($inv['paid_at']) ? (string)$inv['paid_at'] : null,
            'expires_at'        => isset($inv['expires_at']) ? (string)$inv['expires_at'] : null,
        ];
    }

    public function handleLastView(Http $req): void
    {
        if (!(bool)($this->cfg['debug_ui'] ?? false)) {
            Http::json(404, ['ok' => false, 'error' => 'not_found']);
            return;
        }

        $token = (string)($this->cfg['debug_token'] ?? '');
        if ($token !== '') {
            $qs = (string)($_GET['token'] ?? '');
            if (!hash_equals($token, $qs)) {
                Http::json(403, ['ok' => false, 'error' => 'forbidden']);
                return;
            }
        }

        $row = $this->db->fetchLastInbox();
        if ($row === null) {
            Http::json(200, ['ok' => true, 'data' => null]);
            return;
        }

        $payloadRaw = (string)($row['payload_json'] ?? '');
        $decoded = json_decode($payloadRaw, true);

        Http::json(200, [
            'ok' => true,
            'data' => [
                'id' => $row['id'] ?? null,
                'delivery_uid' => $row['delivery_uid'] ?? null,
                'event' => $row['event'] ?? null,
                'received_at' => $row['received_at'] ?? null,
                'client_ip' => self::SOURCE_LABEL,

                // readable columns
                'invoice_public_id' => $row['invoice_public_id'] ?? null,
                'external_id' => $row['external_id'] ?? null,
                'status' => $row['status'] ?? null,
                'amount_total' => $row['amount_total'] ?? null,
                'currency' => $row['currency'] ?? null,
                'tx_id' => $row['tx_id'] ?? null,
                'paid_at' => $row['paid_at'] ?? null,

                'payload' => is_array($decoded) ? $decoded : null,
                'payload_raw' => is_array($decoded) ? null : $payloadRaw,
            ],
        ]);
    }
}
