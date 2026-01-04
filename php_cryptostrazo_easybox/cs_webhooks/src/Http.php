<?php
declare(strict_types=1);

namespace CryptoStrazo\Client;

final class Http
{
    private const SOURCE_LABEL = 'https://cryptostrazo.com/';

    public function __construct(
        public string $method,
        public string $path,
        /** @var array<string,string> */
        public array $headers,
        public string $body,
        public string $ip
    ) {}

    public static function fromGlobals(): self
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $headers = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                $headers[strtolower((string)$k)] = (string)$v;
            }
        } else {
            foreach ($_SERVER as $k => $v) {
                if (str_starts_with($k, 'HTTP_')) {
                    $name = strtolower(str_replace('_', '-', substr($k, 5)));
                    $headers[$name] = (string)$v;
                }
            }
            if (isset($_SERVER['CONTENT_TYPE'])) {
                $headers['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
            }
        }

        $body = (string)(file_get_contents('php://input') ?: '');
        $ip = self::SOURCE_LABEL;

        return new self($method, (string)$path, $headers, $body, $ip);
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public static function json(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
