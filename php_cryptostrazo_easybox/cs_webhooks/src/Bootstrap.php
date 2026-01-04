<?php
declare(strict_types=1);

namespace CryptoStrazo\Client;

final class Bootstrap
{
    public static function loadConfig(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Config file not found.');
        }

        $cfg = require $path;
        if (!is_array($cfg)) {
            throw new \RuntimeException('Config must return array.');
        }

        $sk = (string)($cfg['secret'] ?? '');
        if ($sk === '' || str_contains($sk, 'PASTE_STRZ_SECRET_HERE')) {
            throw new \RuntimeException('Missing secret in config/cryptostrazo.php');
        }

        $db = (array)($cfg['db'] ?? []);
        $cfg['db'] = [
            'driver'      => (string)($db['driver'] ?? 'auto'),
            'host'        => (string)($db['host'] ?? ''),
            'name'        => (string)($db['name'] ?? ''),
            'user'        => (string)($db['user'] ?? ''),
            'pass'        => (string)($db['pass'] ?? ''),
            'charset'     => (string)($db['charset'] ?? 'utf8mb4'),
            'sqlite_path' => (string)($db['sqlite_path'] ?? (dirname($path) . '/../storage/strz.sqlite')),
        ];

        return $cfg;
    }
}
