<?php

class Database
{
    private string $host;
    private string $port;
    private string $dbname;
    private string $username;
    private string $password;
    private bool $isRender;

    public function __construct()
    {
        // Render provides a single DATABASE_URL variable
        $databaseUrl = getenv('DATABASE_URL');

        if ($databaseUrl) {
            $parsed = parse_url($databaseUrl);
            $this->host = $parsed['host'] ?? '';
            $this->port = $parsed['port'] ?? '5432';
            $this->dbname = ltrim($parsed['path'] ?? '', '/');
            $this->username = $parsed['user'] ?? '';
            $this->password = $parsed['pass'] ?? '';
            $this->isRender = true;
        } else {
            // Local development fallback (.env or environment variables)
            $env = $this->loadEnv(__DIR__ . '/../.env');

            $this->host = getenv('DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost');
            $this->port = getenv('DB_PORT') ?: ($env['DB_PORT'] ?? '5432');
            $this->dbname = getenv('DB_NAME') ?: ($env['DB_NAME'] ?? 'agriculture_db');
            $this->username = getenv('DB_USER') ?: ($env['DB_USER'] ?? 'postgres');
            $this->password = getenv('DB_PASS') ?: ($env['DB_PASS'] ?? '');
            $this->isRender = false;
        }
    }

    private function loadEnv(string $path): array
    {
        $vars = [];

        if (!is_file($path) || !is_readable($path)) {
            return $vars;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return $vars;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $vars[trim($key)] = trim($value);
            }
        }

        return $vars;
    }

    public function connect(): PDO
    {
        if ($this->host === '' || $this->username === '') {
            throw new PDOException('Database environment variables are missing.');
        }

        // Only use sslmode=require on Render; omit it for localhost
        $sslMode = $this->isRender ? ';sslmode=require' : '';
        $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname}{$sslMode}";

        $pdo = new PDO($dsn, $this->username, $this->password);

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }
}