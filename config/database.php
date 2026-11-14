<?php
class Database {
    private string $host;
    private string $port;
    private string $dbname;
    private string $username;
    private string $password;

    public function __construct() {
        $env = $this->loadEnv(__DIR__ . '/../.env');
        $this->host     = $env['DB_HOST'] ?? '';
        $this->port     = $env['DB_PORT'] ?? '5432';
        $this->dbname   = $env['DB_NAME'] ?? 'postgres';
        $this->username = $env['DB_USER'] ?? '';
        $this->password = $env['DB_PASS'] ?? '';
    }

    private function loadEnv(string $path): array {
        $vars = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $vars[trim($key)] = trim($value);
            }
        }
        return $vars;
    }

    public function connect(): PDO {
        try {
            $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname};sslmode=require";
            $pdo = new PDO($dsn, $this->username, $this->password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            die("Unable to connect to the database.");
        }
    }
}