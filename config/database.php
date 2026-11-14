<?php

class Database
{
    private string $host;
    private string $port;
    private string $dbname;
    private string $username;
    private string $password;

    public function __construct()
    {
        // Render uses its Environment Variables.
        // The .env file is optional for local development.
        $env = $this->loadEnv(__DIR__ . '/../.env');

        $this->host = getenv('DB_HOST') ?: ($env['DB_HOST'] ?? '');
        $this->port = getenv('DB_PORT') ?: ($env['DB_PORT'] ?? '5432');
        $this->dbname = getenv('DB_NAME') ?: ($env['DB_NAME'] ?? 'postgres');
        $this->username = getenv('DB_USER') ?: ($env['DB_USER'] ?? '');
        $this->password = getenv('DB_PASS') ?: ($env['DB_PASS'] ?? '');
    }

    private function loadEnv(string $path): array
    {
        $vars = [];

        // If there is no .env file, continue without showing a warning.
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
        // Check that the required database settings are present.
        if ($this->host === '' || $this->username === '' || $this->password === '') {
            throw new PDOException('Database environment variables are missing.');
        }

        $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname};sslmode=require";

        $pdo = new PDO($dsn, $this->username, $this->password);

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }
}