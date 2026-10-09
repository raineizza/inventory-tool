<?php
/**
 * Shared code for the stock-in API (index.php) and its settings page (setup.php).
 *
 * Plain PHP 7.4+ and PDO, so the folder can sit next to any PHP website without touching
 * the website's own code. Works with MySQL / MariaDB and SQLite databases.
 */

declare(strict_types=1);

const CONFIG_FILE = __DIR__ . '/config.php';
const BATCH_TABLE = 'stock_in_batches';
const AMBIGUOUS = '*ambiguous*';
const NAME_PATTERN = '/^[A-Za-z0-9_$]{1,64}$/';

// ------------------------------------------------------------------ settings

function load_config(): ?array
{
    if (!is_file(CONFIG_FILE)) {
        return null;
    }
    $config = include CONFIG_FILE;
    return is_array($config) ? $config : null;
}

/**
 * Writes config.php. A .php file, so the web server runs it instead of showing the
 * password and token. Returns null on success, or the file's text when the folder isn't writable.
 */
function save_config(array $config): ?string
{
    $source = "<?php\n// Written by setup.php. Holds the database password and the API token: keep it private.\nreturn "
        . var_export($config, true) . ";\n";
    $temp = __DIR__ . '/config.' . bin2hex(random_bytes(4)) . '.tmp.php';
    if (@file_put_contents($temp, $source) === false || !@rename($temp, CONFIG_FILE)) {
        @unlink($temp);
        return $source;
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(CONFIG_FILE, true);
    }
    return null;
}

function now_string(array $config): string
{
    $zone = new DateTimeZone((string) ($config['timezone'] ?? date_default_timezone_get()));
    return (new DateTimeImmutable('now', $zone))->format('Y-m-d H:i:s');
}

// ------------------------------------------------------------------ database

function db_connect(array $db): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ];
    if (($db['driver'] ?? 'mysql') === 'sqlite') {
        // PDO would quietly create an empty database for a wrong path.
        if (!is_file((string) $db['database'])) {
            throw new RuntimeException('SQLite database file not found: ' . $db['database']);
        }
        return new PDO('sqlite:' . $db['database'], null, null, $options);
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int) $db['port'], $db['database']);
    return new PDO($dsn, (string) $db['username'], (string) $db['password'], $options);
}

function is_mysql(PDO $pdo): bool
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}

/** Quotes a table or column name for SQL. Names come from the database's own schema, and are checked anyway. */
function qi(PDO $pdo, string $name): string
{
    if (!preg_match(NAME_PATTERN, $name)) {
        throw new InvalidArgumentException("Unsupported table or column name: {$name}");
    }
    return is_mysql($pdo) ? "`{$name}`" : "\"{$name}\"";
}

/** @return string[] */
function table_names(PDO $pdo): array
{
    $sql = is_mysql($pdo)
        ? "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
        : "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
    $names = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter($names, function ($name) {
        return $name !== BATCH_TABLE && preg_match(NAME_PATTERN, (string) $name);
    }));
}

/** @return array<int, array{name: string, type: string, primary: bool, integer: bool, numeric: bool}> */
function table_columns(PDO $pdo, string $table): array
{
    $columns = [];
    if (is_mysql($pdo)) {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, COLUMN_KEY AS col_key FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$table]);
        foreach ($stmt->fetchAll() as $row) {
            $columns[] = column_info((string) $row['name'], (string) $row['type'], $row['col_key'] === 'PRI');
        }
    } else {
        foreach ($pdo->query('PRAGMA table_info(' . qi($pdo, $table) . ')')->fetchAll() as $row) {
            $columns[] = column_info((string) $row['name'], (string) $row['type'], (int) $row['pk'] > 0);
        }
    }
    return array_values(array_filter($columns, function (array $column) {
        return (bool) preg_match(NAME_PATTERN, $column['name']);
    }));
}

function column_info(string $name, string $type, bool $primary): array
{
    $integer = (bool) preg_match('/^(tiny|small|medium|big)?int(eger)?\b/i', $type);
    return [
        'name' => $name,
        'type' => strtolower($type),
        'primary' => $primary,
        'integer' => $integer,
        'numeric' => $integer || preg_match('/^(dec|decimal|numeric|float|double|real)\b/i', $type),
    ];
}

function ensure_batch_table(PDO $pdo): void
{
    $sql = is_mysql($pdo)
        ? 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        : "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([BATCH_TABLE]);
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec(batch_table_sql($pdo));
    }
}

/** One row per count received: an audit trail, and what stops a retried request from adding stock twice. */
function batch_table_sql(PDO $pdo): string
{
    $mysql = is_mysql($pdo);
    $id = $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    return 'CREATE TABLE IF NOT EXISTS ' . qi($pdo, BATCH_TABLE) . " (
    id {$id},
    batch_id VARCHAR(100) NOT NULL UNIQUE,
    station_id VARCHAR(100) NULL,
    total_quantity INTEGER NOT NULL,
    items_hash CHAR(64) NOT NULL,
    items TEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL
)" . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '');
}

// ------------------------------------------------------------------ products

// $map (from config.php) describes the website's products table:
//   table, id, codes (columns a scanned code is matched against, in priority order),
//   numeric_codes (those of them that only hold numbers), stock, name, updated

/** Codes match regardless of letter case and surrounding spaces. */
function normalize_code($code): string
{
    return strtoupper(trim((string) $code));
}

function product_columns_sql(PDO $pdo, array $map): string
{
    $columns = [qi($pdo, $map['id']) . ' AS id', qi($pdo, $map['stock']) . ' AS stock'];
    $columns[] = $map['name'] !== '' ? qi($pdo, $map['name']) . ' AS name' : "'' AS name";
    foreach ($map['codes'] as $i => $column) {
        $columns[] = qi($pdo, $column) . " AS code_{$i}";
    }
    return implode(', ', $columns);
}

/** @return array{id: string, code: string, name: string, stock: int|float|null} */
function shape_product(array $row, array $map): array
{
    $code = '';
    foreach (array_keys($map['codes']) as $i) {
        if (trim((string) ($row["code_{$i}"] ?? '')) !== '') {
            $code = trim((string) $row["code_{$i}"]);
            break;
        }
    }
    $stock = $row['stock'];
    return [
        'id' => (string) $row['id'],
        'code' => $code !== '' ? $code : (string) $row['id'],
        'name' => trim((string) ($row['name'] ?? '')),
        'stock' => is_numeric($stock) ? $stock + 0 : null,
    ];
}

function product_by_id(PDO $pdo, array $map, string $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT ' . product_columns_sql($pdo, $map) . ' FROM ' . qi($pdo, $map['table'])
        . ' WHERE ' . qi($pdo, $map['id']) . ' = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    // Exact match only: MySQL would also return product 12 for the id "12abc".
    return $row && (string) $row['id'] === $id ? shape_product($row, $map) : null;
}

/**
 * Product id for a scanned code: tries each code column in order, and the first column
 * with a match wins. Returns null when nothing matches, or AMBIGUOUS when two products
 * share the code in the same column.
 */
function find_product_id(PDO $pdo, array $map, string $code): ?string
{
    $wanted = normalize_code($code);
    if ($wanted === '') {
        return null;
    }
    foreach ($map['codes'] as $column) {
        // MySQL would compare "ABC" with a number column as 0 and match the wrong row.
        if (in_array($column, $map['numeric_codes'] ?? [], true) && !ctype_digit($wanted)) {
            continue;
        }
        $stmt = $pdo->prepare(
            'SELECT ' . qi($pdo, $map['id']) . ' AS id, ' . qi($pdo, $column) . ' AS code FROM ' . qi($pdo, $map['table'])
            . ' WHERE ' . qi($pdo, $column) . ' = ? LIMIT 5'
        );
        $stmt->execute([trim($code)]);
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            if (normalize_code($row['code']) === $wanted) {
                $ids[(string) $row['id']] = true;
            }
        }
        if (count($ids) > 1) {
            return AMBIGUOUS;
        }
        if (count($ids) === 1) {
            return (string) array_key_first($ids);
        }
    }
    return null;
}

/**
 * Everything the scanner needs to recognise codes while scanning:
 *   products: [{id, code, name}]
 *   codes:    {CODE: id}, or {CODE: null} when the code belongs to more than one product
 * Resolves codes exactly like find_product_id(), so the scanner and the API agree.
 */
function product_catalog(PDO $pdo, array $map, int $limit): array
{
    $rows = $pdo->query(
        'SELECT ' . product_columns_sql($pdo, $map) . ' FROM ' . qi($pdo, $map['table'])
        . ' ORDER BY ' . qi($pdo, $map['id']) . ' LIMIT ' . $limit
    )->fetchAll();

    $products = [];
    foreach ($rows as $row) {
        $product = shape_product($row, $map);
        unset($product['stock']);
        $products[] = $product;
    }

    $codes = [];
    $claimedBy = [];
    foreach (array_keys($map['codes']) as $i) {
        foreach ($rows as $row) {
            $code = normalize_code($row["code_{$i}"] ?? '');
            if ($code === '') {
                continue;
            }
            $id = (string) $row['id'];
            if (!array_key_exists($code, $codes)) {
                $codes[$code] = $id;
                $claimedBy[$code] = $i;
            } elseif ($claimedBy[$code] === $i && $codes[$code] !== $id) {
                $codes[$code] = null;
            }
        }
    }

    return [
        'count' => count($products),
        'truncated' => count($rows) === $limit,
        'products' => $products,
        'codes' => (object) $codes,
    ];
}
