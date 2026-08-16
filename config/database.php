<?php
/**
 * AgroBusiness Malawi — shared .env loading and MySQL connection.
 *
 * api.php, register.php and directory-api.php each used to carry their own copy
 * of this logic, which meant a fix to one left the others wrong. This is the one
 * copy. Credentials are read from .env (gitignored) and never hardcoded.
 *
 * On production the site connects over the cPanel socket via localhost; locally
 * it connects to the host named in .env over TCP. Both come from DB_HOST, so no
 * environment sniffing happens here.
 */

if (defined('AGRO_DB_LOADED')) return;
define('AGRO_DB_LOADED', true);

/**
 * Read .env into $_ENV once. Missing file is not an error — the caller decides
 * whether the resulting empty credentials are fatal.
 */
function agro_load_env(?string $path = null): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    $envFile = $path ?? dirname(__DIR__) . '/.env';
    if (!is_file($envFile) || !is_readable($envFile)) return;

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

/**
 * Open a configured mysqli connection.
 *
 * @throws RuntimeException when credentials are missing or the connection fails.
 *         The message is safe to show a user: it never contains the credentials.
 */
function agro_db_connect(): mysqli
{
    agro_load_env();

    $host     = $_ENV['DB_HOST'] ?? '';
    $user     = $_ENV['DB_USER'] ?? '';
    $password = $_ENV['DB_PASS'] ?? '';
    $database = $_ENV['DB_NAME'] ?? '';
    $port     = (int)($_ENV['DB_PORT'] ?? 3306);

    if ($host === '' || $user === '' || $database === '') {
        throw new RuntimeException('Database configuration is incomplete.');
    }
    if (!class_exists('mysqli')) {
        throw new RuntimeException('The MySQLi extension is not available on this server.');
    }

    $db = mysqli_init();
    if (!$db) {
        throw new RuntimeException('Could not initialise the database driver.');
    }
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);

    // Warnings are suppressed so a failed connection surfaces as our own message
    // rather than a PHP warning containing the host and username.
    if (!@$db->real_connect($host, $user, $password, $database, $port)) {
        throw new RuntimeException('Could not connect to the database.');
    }

    $db->set_charset('utf8mb4');
    return $db;
}

/**
 * Fetch every row of an executed statement without get_result().
 *
 * get_result() needs mysqlnd, which this shared host does not guarantee, so the
 * whole codebase binds and fetches instead. Do not "simplify" this away.
 */
function agro_stmt_all(mysqli_stmt $stmt): array
{
    $meta = $stmt->result_metadata();
    if (!$meta) return [];

    $fields = [];
    $row = [];
    while ($field = $meta->fetch_field()) {
        $row[$field->name] = null;
        $fields[] = &$row[$field->name];
    }
    call_user_func_array([$stmt, 'bind_result'], $fields);

    $rows = [];
    while ($stmt->fetch()) {
        $rows[] = array_map(static fn($v) => $v, $row);
    }
    $meta->free();
    $stmt->free_result();
    return $rows;
}

/** First row of an executed statement, or null. */
function agro_stmt_one(mysqli_stmt $stmt): ?array
{
    return agro_stmt_all($stmt)[0] ?? null;
}
