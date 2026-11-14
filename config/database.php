
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
        // Load local .env only when it exists.
        $env = $this->loadEnv(__DIR__ . '/../.env');

        // Use Render environment variables first.
        $this->host = getenv('DB_HOST') ?: ($env['DB_HOST'] ?? '');
        $this->port = getenv('DB_PORT') ?: ($env['DB_PORT'] ?? '5432');
        $this->dbname = getenv('DB_NAME') ?: ($env['DB_NAME'] ?? 'postgres');
        $this->username = getenv('DB_USER') ?: ($env['DB_USER'] ?? '');
        $this->password = getenv('DB_PASS') ?: ($env['DB_PASS'] ?? '');

        if (
            $this->host === '' ||
            $this->username === '' ||
            $this->password === ''
        ) {
            throw new RuntimeException(
                'Database environment variables are missing.'
            );
        }
    }

    private function loadEnv(string $path): array
    {
        $vars = [];

        if (!is_file($path) || !is_readable($path)) {
            return $vars;
        }

        $lines = file(
            $path,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );

        if ($lines === false) {
            return $vars;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
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
        $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname};sslmode=require";

        $pdo = new PDO(
            $dsn,
            $this->username,
            $this->password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        return $pdo;
    }
}
