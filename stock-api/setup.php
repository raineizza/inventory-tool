<?php
/**
 * Settings page for the stock-in API.
 *
 *   1. Sign in with the setup key from setup-key.php (not needed on localhost).
 *   2. Connect to the website's database. On a Laravel site this is read from its .env file.
 *   3. Pick the products table, and which columns hold the code (SKU), the stock and the name.
 *   4. Copy the API address and token into the scanner's settings.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

const KEY_FILE = __DIR__ . '/setup-key.php';
const TABLE_GUESSES = ['products', 'product', 'items', 'item', 'inventory', 'inventories', 'stocks', 'goods'];
const CODE_GUESSES = ['sku', 'barcode', 'model_number', 'product_code', 'item_code', 'part_number', 'model_no', 'model', 'upc', 'ean', 'gtin', 'code'];
const STOCK_GUESSES = ['stock', 'stock_quantity', 'stock_qty', 'quantity', 'qty', 'on_hand', 'inventory', 'available'];
const NAME_GUESSES = ['name', 'product_name', 'title', 'item_name', 'label', 'description'];
const UPDATED_GUESSES = ['updated_at', 'date_modified', 'modified_at', 'last_updated', 'updated', 'modified'];

ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

session_name('stockin_setup');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => folder_path() . '/',
    'secure' => is_https(),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$config = load_config();
$laravel = find_laravel_site();
$errors = [];
$notices = [];
$manualConfig = null;   // config.php text to create by hand when the folder isn't writable
$showBatchSql = false;

$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$action = $posted ? (string) ($_POST['action'] ?? '') : '';
if ($posted && !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
    $errors[] = 'This page had expired, so nothing was changed. Please try again.';
    $action = '';
}

// ------------------------------------------------------------------ signing in

if ($action === 'signout') {
    $_SESSION = [];
    session_destroy();
    header('Location: setup.php');
    exit;
}

$local = is_local_request();
$setupKey = $local ? null : setup_key();

if ($action === 'signin' && !$local) {
    if ($setupKey !== null && hash_equals($setupKey, trim((string) ($_POST['key'] ?? '')))) {
        session_regenerate_id(true);
        $_SESSION['authed'] = true;
    } else {
        sleep(1);
        $errors[] = 'That setup key is not right. Copy it again from setup-key.php.';
    }
}
if ($local) {
    $_SESSION['authed'] = true;
}
$authed = !empty($_SESSION['authed']);

// ------------------------------------------------------------------ database connection

if ($authed && $action === 'connect') {
    $db = db_from_form($_POST, $_SESSION['db'] ?? null);
    try {
        db_connect($db);
        $_SESSION['db'] = $db;
        $_SESSION['db_source'] = 'form';
        $notices[] = 'Connected to the database.';
    } catch (Throwable $e) {
        $errors[] = 'Could not connect: ' . $e->getMessage();
    }
}

if ($authed && empty($_SESSION['db'])) {
    if ($config !== null) {
        $_SESSION['db'] = $config['db'];
        $_SESSION['db_source'] = 'config';
    } elseif ($laravel !== null && $laravel['db'] !== null) {
        $_SESSION['db'] = $laravel['db'];
        $_SESSION['db_source'] = 'laravel';
    }
}

$pdo = null;
$tables = [];
if ($authed && !empty($_SESSION['db'])) {
    try {
        $pdo = db_connect($_SESSION['db']);
        $tables = table_names($pdo);
        if (!$tables) {
            $errors[] = 'Connected, but this database has no tables.';
        }
    } catch (Throwable $e) {
        $pdo = null;
        $errors[] = 'Could not connect to the database: ' . $e->getMessage();
    }
}

// ------------------------------------------------------------------ table and columns

$defaultTimezone = laravel_timezone($laravel) ?? date_default_timezone_get();
$choice = null;
$columns = [];
if ($pdo !== null && $tables) {
    $mappingPost = $posted && isset($_POST['table']);
    $savedMap = $config['map'] ?? null;
    $table = $mappingPost ? (string) $_POST['table'] : (string) ($savedMap['table'] ?? '');
    if (!in_array($table, $tables, true)) {
        $table = guess_name($tables, TABLE_GUESSES) ?: $tables[0];
    }
    $columns = table_columns($pdo, $table);

    if ($mappingPost && ($_POST['prev_table'] ?? '') === $table) {
        $source = 'post';
    } elseif (!$mappingPost && $savedMap !== null && $savedMap['table'] === $table) {
        $source = 'saved';
    } else {
        $source = 'guess';
    }
    $choice = column_choices($table, $columns, $source, $config, $_POST, $defaultTimezone);
}

if ($authed && $action === 'save' && $choice !== null) {
    $problems = [];
    if ($choice['code'] === '') {
        $problems[] = 'Choose the column that holds the code (SKU).';
    }
    if ($choice['stock'] === '') {
        $problems[] = 'Choose the stock column. It has to be a number column.';
    }
    if (!$problems) {
        try {
            ensure_batch_table($pdo);
        } catch (Throwable $e) {
            $problems[] = "Couldn't create the " . BATCH_TABLE . ' table (' . $e->getMessage() . '). Create it with the SQL below, then save again.';
            $showBatchSql = true;
        }
    }
    if ($problems) {
        $errors = array_merge($errors, $problems);
    } else {
        $newConfig = [
            'db' => $_SESSION['db'],
            'map' => map_from_choice($choice, $columns),
            'timezone' => $choice['timezone'],
            'token' => $config['token'] ?? new_token(),
            'saved_at' => date('c'),
        ];
        $manualConfig = save_config($newConfig);
        if ($manualConfig === null) {
            $config = $newConfig;
            $notices[] = 'Saved. Scanners can connect now.';
        } else {
            $errors[] = "Couldn't write config.php because the stock-api folder isn't writable. Create config.php in that folder with the text below (or make the folder writable and save again).";
        }
    }
}

if ($authed && $action === 'new_token' && $config !== null) {
    $newConfig = ['token' => new_token(), 'saved_at' => date('c')] + $config;
    $manualConfig = save_config($newConfig);
    if ($manualConfig === null) {
        $config = $newConfig;
        $notices[] = 'Made a new token. Put it into every scanner: the old one has stopped working.';
    } else {
        $errors[] = "Couldn't write config.php because the stock-api folder isn't writable.";
    }
}

// Preview of the choices, so it's easy to see they're right.
$preview = null;
if ($choice !== null && $choice['code'] !== '' && $choice['stock'] !== '') {
    $map = map_from_choice($choice, $columns);
    try {
        $preview = [
            'count' => (int) $pdo->query('SELECT COUNT(*) FROM ' . qi($pdo, $map['table']))->fetchColumn(),
            'rows' => $pdo->query(
                'SELECT ' . product_columns_sql($pdo, $map) . ' FROM ' . qi($pdo, $map['table'])
                . ' ORDER BY ' . qi($pdo, $map['id']) . ' LIMIT 5'
            )->fetchAll(),
            'duplicates' => duplicate_codes($pdo, $map['table'], $map['codes'][0]),
            'engine' => table_engine($pdo, $map['table']),
            'test' => null,
        ];
        if ($action === 'test') {
            $code = trim((string) ($_POST['test_code'] ?? ''));
            $id = $code === '' ? null : find_product_id($pdo, $map, $code);
            $preview['test'] = [
                'code' => $code,
                'id' => $id,
                'product' => $id !== null && $id !== AMBIGUOUS ? product_by_id($pdo, $map, $id) : null,
            ];
        }
    } catch (Throwable $e) {
        $errors[] = 'Could not read the table with these columns: ' . $e->getMessage();
    }
}

$warnings = [];
if (!is_https() && !$local) {
    $warnings[] = "This page isn't using HTTPS, so the token and database details travel unencrypted. Open it with https:// if the website supports it.";
}

// ------------------------------------------------------------------ helpers

function folder_path(): string
{
    return rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/** True only for a browser on the server itself, not for requests passed on by a proxy. */
function is_local_request(): bool
{
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
        return false;
    }
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_CLIENT_IP', 'HTTP_CF_CONNECTING_IP'] as $header) {
        if (!empty($_SERVER[$header])) {
            return false;
        }
    }
    return true;
}

function api_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return (is_https() ? 'https' : 'http') . '://' . $host . folder_path() . '/index.php';
}

/** The key in setup-key.php, made on first use. Only people who can open the server's files can read it. */
function setup_key(): ?string
{
    if (is_file(KEY_FILE)) {
        $key = include KEY_FILE;
        return is_string($key) && strlen(trim($key)) >= 12 ? trim($key) : null;
    }
    $key = bin2hex(random_bytes(12));
    $source = "<?php\n// Setup key for setup.php in this folder: copy the text between the quotes into the setup page.\n"
        . "// Delete this file to get a new key.\nreturn '{$key}';\n";
    return @file_put_contents(KEY_FILE, $source) === false ? null : $key;
}

function new_token(): string
{
    return bin2hex(random_bytes(24));
}

/** The Laravel app this folder sits in (public/stock-api or stock-api next to artisan), if any. */
function find_laravel_site(): ?array
{
    $dir = __DIR__;
    for ($level = 0; $level < 4; $level++) {
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
        if (@is_file($dir . '/artisan') && @is_file($dir . '/.env')) {
            $env = parse_env_file($dir . '/.env');
            return ['root' => $dir, 'env' => $env, 'db' => laravel_db($dir, $env)];
        }
    }
    return null;
}

function parse_env_file(string $file): array
{
    $vars = [];
    foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (!preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
            continue;
        }
        $value = $m[2];
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $value, $q)) {
            $value = str_replace(['\\"', '\\\\'], ['"', '\\'], $q[1]);
        } elseif (preg_match("/^'([^']*)'/", $value, $q)) {
            $value = $q[1];
        } else {
            $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
        }
        $vars[$m[1]] = $value;
    }
    return $vars;
}

function laravel_db(string $root, array $env): ?array
{
    $driver = strtolower($env['DB_CONNECTION'] ?? 'mysql');
    if ($driver === 'mysql' || $driver === 'mariadb') {
        return [
            'driver' => 'mysql',
            'host' => ($env['DB_HOST'] ?? '') ?: '127.0.0.1',
            'port' => (int) ($env['DB_PORT'] ?? 3306) ?: 3306,
            'database' => $env['DB_DATABASE'] ?? '',
            'username' => $env['DB_USERNAME'] ?? 'root',
            'password' => $env['DB_PASSWORD'] ?? '',
        ];
    }
    if ($driver === 'sqlite') {
        $path = $env['DB_DATABASE'] ?? '';
        if ($path === '') {
            $path = $root . '/database/database.sqlite';
        } elseif (!preg_match('~^([A-Za-z]:)?[\\\\/]~', $path)) {
            $path = $root . '/' . $path;
        }
        return ['driver' => 'sqlite', 'database' => $path];
    }
    return null;
}

function laravel_timezone(?array $site): ?string
{
    if ($site === null) {
        return null;
    }
    // config/app.php decides: some versions read APP_TIMEZONE from .env, others hard-code the zone.
    $env = (string) ($site['env']['APP_TIMEZONE'] ?? '');
    $source = (string) @file_get_contents($site['root'] . '/config/app.php');
    if (preg_match("/'timezone'\s*=>\s*env\(\s*'APP_TIMEZONE'\s*(?:,\s*'([^']*)')?\s*\)/", $source, $m)) {
        $zone = $env !== '' ? $env : ($m[1] ?? 'UTC');
    } elseif (preg_match("/'timezone'\s*=>\s*'([^']+)'/", $source, $m)) {
        $zone = $m[1];
    } else {
        $zone = $env;
    }
    return in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
}

function db_from_form(array $post, ?array $current): array
{
    if (($post['driver'] ?? '') === 'sqlite') {
        return ['driver' => 'sqlite', 'database' => trim((string) ($post['sqlite_path'] ?? ''))];
    }
    $db = [
        'driver' => 'mysql',
        'host' => trim((string) ($post['host'] ?? '')) ?: '127.0.0.1',
        'port' => (int) ($post['port'] ?? 3306) ?: 3306,
        'database' => trim((string) ($post['database'] ?? '')),
        'username' => trim((string) ($post['username'] ?? '')),
        'password' => (string) ($post['password'] ?? ''),
    ];
    // A blank password keeps the current one when it's the same server and user.
    if ($db['password'] === '' && ($current['driver'] ?? '') === 'mysql'
        && $current['host'] === $db['host'] && $current['username'] === $db['username']) {
        $db['password'] = (string) $current['password'];
    }
    return $db;
}

/** First name in $names that equals one of $wanted, else the first that contains one of them. */
function guess_name(array $names, array $wanted): string
{
    foreach ($wanted as $want) {
        foreach ($names as $name) {
            if (strcasecmp((string) $name, $want) === 0) {
                return (string) $name;
            }
        }
    }
    foreach ($wanted as $want) {
        foreach ($names as $name) {
            if (stripos((string) $name, $want) !== false) {
                return (string) $name;
            }
        }
    }
    return '';
}

/** The column choices to show: what was just picked, what's saved, or a best guess for a new table. */
function column_choices(string $table, array $columns, string $source, ?array $config, array $post, string $defaultTimezone): array
{
    $names = array_column($columns, 'name');
    $numeric = array_column(array_filter($columns, function (array $c) { return $c['numeric']; }), 'name');
    $primary = array_column(array_filter($columns, function (array $c) { return $c['primary']; }), 'name');

    $guess = [
        'id' => $primary[0] ?? (in_array('id', $names, true) ? 'id' : ($names[0] ?? '')),
        'stock' => guess_name($numeric, STOCK_GUESSES),
        'name' => guess_name($names, NAME_GUESSES),
        'updated' => guess_name($names, UPDATED_GUESSES),
        'timezone' => $defaultTimezone,
    ];
    $guess['code'] = guess_name($names, CODE_GUESSES) ?: $guess['id'];
    $guess['code2'] = guess_name(array_values(array_diff($names, [$guess['code']])), CODE_GUESSES);

    if ($source === 'saved') {
        $map = $config['map'];
        $values = [
            'id' => $map['id'],
            'code' => $map['codes'][0] ?? '',
            'code2' => $map['codes'][1] ?? '',
            'stock' => $map['stock'],
            'name' => $map['name'],
            'updated' => $map['updated'],
            'timezone' => (string) ($config['timezone'] ?? $defaultTimezone),
        ];
    } elseif ($source === 'post') {
        $values = [];
        foreach (['id', 'code', 'code2', 'stock', 'name', 'updated', 'timezone'] as $field) {
            $values[$field] = trim((string) ($post[$field] ?? ''));
        }
    } else {
        $values = $guess;
    }

    // Only real column names get through; anything else falls back to the guess (or blank when optional).
    foreach (['id' => $names, 'code' => $names, 'stock' => $numeric] as $field => $allowed) {
        if (!in_array($values[$field], $allowed, true)) {
            $values[$field] = $guess[$field];
        }
    }
    foreach (['code2', 'name', 'updated'] as $field) {
        if ($values[$field] !== '' && !in_array($values[$field], $names, true)) {
            $values[$field] = '';
        }
    }
    if ($values['code2'] === $values['code']) {
        $values['code2'] = '';
    }
    if (!in_array($values['timezone'], DateTimeZone::listIdentifiers(), true)) {
        $values['timezone'] = $defaultTimezone;
    }
    $values['table'] = $table;
    return $values;
}

function map_from_choice(array $choice, array $columns): array
{
    $integer = array_column(array_filter($columns, function (array $c) { return $c['integer']; }), 'name');
    $codes = array_values(array_filter([$choice['code'], $choice['code2']], function ($c) { return $c !== ''; }));
    return [
        'table' => $choice['table'],
        'id' => $choice['id'],
        'codes' => $codes,
        'numeric_codes' => array_values(array_intersect($codes, $integer)),
        'stock' => $choice['stock'],
        'name' => $choice['name'],
        'updated' => $choice['updated'],
    ];
}

function duplicate_codes(PDO $pdo, string $table, string $column): array
{
    $c = qi($pdo, $column);
    return $pdo->query(
        "SELECT {$c} FROM " . qi($pdo, $table) . " WHERE {$c} IS NOT NULL AND {$c} <> '' GROUP BY {$c} HAVING COUNT(*) > 1 LIMIT 6"
    )->fetchAll(PDO::FETCH_COLUMN);
}

function table_engine(PDO $pdo, string $table): string
{
    if (!is_mysql($pdo)) {
        return '';
    }
    $stmt = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (string) $stmt->fetchColumn();
}

function db_summary(PDO $pdo, array $db): string
{
    if (($db['driver'] ?? '') === 'sqlite') {
        return 'SQLite file ' . $db['database'];
    }
    return sprintf('Database %s on %s as %s (%s)', $db['database'], $db['host'], $db['username'], $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** <option>s for a column dropdown. */
function column_options(array $columns, string $selected, ?string $blank = null): string
{
    $html = $blank !== null ? '<option value="">' . h($blank) . '</option>' : '';
    foreach ($columns as $column) {
        $html .= sprintf(
            '<option value="%s"%s>%s  ·  %s%s</option>',
            h($column['name']),
            $column['name'] === $selected ? ' selected' : '',
            h($column['name']),
            h($column['type']),
            $column['primary'] ? '  ·  primary key' : ''
        );
    }
    return $html;
}

$csrf = h($_SESSION['csrf']);
$numericColumns = array_values(array_filter($columns, function (array $c) { return $c['numeric']; }));
$db = $_SESSION['db'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Stock-In API Setup</title>
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <script>
    tailwind.config = { theme: { extend: { colors: { brand: { 50: '#fff4f1', 100: '#ffe4dc', 500: '#ee4d2d', 600: '#d73a1b' } } } } };
  </script>
  <style type="text/tailwindcss">
    @layer components {
      .card      { @apply bg-white rounded-xl border border-slate-200 shadow-sm; }
      .card-head { @apply flex items-center gap-3 px-5 py-4 border-b border-slate-100; }
      .step      { @apply grid place-items-center w-7 h-7 shrink-0 rounded-full bg-brand-500 text-white text-sm font-bold; }
      .label     { @apply block text-sm font-medium text-slate-700 mb-1; }
      .hint      { @apply text-xs text-slate-500 mt-1; }
      .input     { @apply w-full h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none
                          focus:border-brand-500 focus:ring-2 focus:ring-brand-100; }
      .btn       { @apply inline-flex items-center justify-center gap-2 rounded-lg px-4 h-10 text-sm font-semibold transition; }
      .btn-primary   { @apply bg-brand-500 text-white hover:bg-brand-600; }
      .btn-secondary { @apply bg-white border border-slate-300 text-slate-700 hover:bg-slate-50; }
    }
  </style>
</head>
<body class="bg-slate-100 text-slate-800 antialiased min-h-screen">

<header class="bg-white border-b border-slate-200">
  <div class="max-w-3xl mx-auto px-4 h-16 flex items-center gap-3">
    <div class="w-10 h-10 shrink-0 rounded-lg bg-brand-500 text-white grid place-items-center">
      <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16.5 9.4 7.55 4.24"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12.01l8.73-5.05"/><path d="M12 22.08V12"/></svg>
    </div>
    <div class="min-w-0">
      <h1 class="text-lg font-semibold leading-tight">Stock-In API setup</h1>
      <p class="text-xs text-slate-500 truncate"><?= h($_SERVER['HTTP_HOST'] ?? '') ?> · connects the warehouse scanner to this website</p>
    </div>
    <?php if ($authed && !$local): ?>
      <form method="post" class="ml-auto">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <button name="action" value="signout" class="btn btn-secondary">Sign out</button>
      </form>
    <?php endif; ?>
  </div>
</header>

<main class="max-w-3xl mx-auto p-4 space-y-4">

  <?php foreach ($errors as $error): ?>
    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?= h($error) ?></div>
  <?php endforeach; ?>
  <?php foreach ($warnings as $warning): ?>
    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"><?= h($warning) ?></div>
  <?php endforeach; ?>
  <?php foreach ($notices as $notice): ?>
    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?= h($notice) ?></div>
  <?php endforeach; ?>

  <?php if (!$authed): ?>
    <!-- ======================= Sign in ======================= -->
    <section class="card">
      <div class="card-head"><h2 class="font-semibold">Sign in</h2></div>
      <form method="post" class="p-5 space-y-4">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <?php if ($setupKey === null): ?>
          <p class="text-sm text-slate-600">
            This page couldn't create its <b>setup-key.php</b> file, so nobody can sign in yet.
            In your hosting file manager, create <b>setup-key.php</b> in the stock-api folder containing:
          </p>
          <pre class="rounded-lg bg-slate-900 p-3 text-xs text-slate-100">&lt;?php return 'make-up-a-long-secret-here';</pre>
          <p class="text-sm text-slate-600">Then reload this page and enter that secret.</p>
        <?php else: ?>
          <p class="text-sm text-slate-600">
            To prove you manage this website, open <b>stock-api/setup-key.php</b> in your hosting file manager
            (for example cPanel → File Manager → Edit) and copy the key between the quotes.
          </p>
        <?php endif; ?>
        <div>
          <label class="label" for="key">Setup key</label>
          <input id="key" name="key" type="password" autocomplete="off" class="input font-mono" autofocus>
        </div>
        <button name="action" value="signin" class="btn btn-primary">Sign in</button>
      </form>
    </section>

  <?php else: ?>
    <!-- ======================= 1. Database ======================= -->
    <section class="card">
      <div class="card-head">
        <span class="step">1</span>
        <div class="min-w-0">
          <h2 class="font-semibold">Database</h2>
          <?php if ($pdo !== null): ?>
            <p class="text-xs text-slate-500 truncate">
              <?= h(db_summary($pdo, $db)) ?>
              <?php if (($_SESSION['db_source'] ?? '') === 'laravel'): ?> · read from this Laravel site's .env<?php endif; ?>
            </p>
          <?php endif; ?>
        </div>
        <?php if ($pdo !== null): ?>
          <span class="ml-auto shrink-0 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-700">Connected</span>
        <?php endif; ?>
      </div>
      <details class="group" <?= $pdo === null ? 'open' : '' ?>>
        <summary class="cursor-pointer select-none px-5 py-3 text-sm font-medium text-slate-600 hover:text-slate-800">
          <?= $pdo === null ? 'Enter the database details' : 'Use a different database' ?>
        </summary>
        <form method="post" class="px-5 pb-5 space-y-4">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <?php $isSqlite = ($db['driver'] ?? '') === 'sqlite'; ?>
          <div class="flex gap-4 text-sm">
            <label class="flex items-center gap-2"><input type="radio" name="driver" value="mysql" <?= $isSqlite ? '' : 'checked' ?> onchange="toggleDriver()"> MySQL / MariaDB</label>
            <label class="flex items-center gap-2"><input type="radio" name="driver" value="sqlite" <?= $isSqlite ? 'checked' : '' ?> onchange="toggleDriver()"> SQLite file</label>
          </div>
          <div id="mysqlFields" class="grid grid-cols-1 sm:grid-cols-2 gap-4 <?= $isSqlite ? 'hidden' : '' ?>">
            <div><label class="label">Host</label><input name="host" class="input" value="<?= h($isSqlite ? '127.0.0.1' : ($db['host'] ?? '127.0.0.1')) ?>"></div>
            <div><label class="label">Port</label><input name="port" class="input" inputmode="numeric" value="<?= h($isSqlite ? 3306 : ($db['port'] ?? 3306)) ?>"></div>
            <div><label class="label">Database name</label><input name="database" class="input" value="<?= h($isSqlite ? '' : ($db['database'] ?? '')) ?>"></div>
            <div><label class="label">Username</label><input name="username" class="input" autocomplete="off" value="<?= h($isSqlite ? '' : ($db['username'] ?? '')) ?>"></div>
            <div class="sm:col-span-2">
              <label class="label">Password</label>
              <input name="password" type="password" class="input" autocomplete="new-password" placeholder="<?= $db && !$isSqlite ? 'Leave blank to keep the current password' : '' ?>">
              <p class="hint">On cPanel these are in the website's settings file, or under MySQL Databases.</p>
            </div>
          </div>
          <div id="sqliteFields" class="<?= $isSqlite ? '' : 'hidden' ?>">
            <label class="label">Path to the .sqlite file on the server</label>
            <input name="sqlite_path" class="input font-mono" value="<?= h($isSqlite ? $db['database'] : '') ?>">
          </div>
          <button name="action" value="connect" class="btn btn-primary">Connect</button>
        </form>
      </details>
    </section>

    <?php if ($choice !== null): ?>
    <!-- ======================= 2. Products ======================= -->
    <section class="card">
      <div class="card-head">
        <span class="step">2</span>
        <div>
          <h2 class="font-semibold">Products</h2>
          <p class="text-xs text-slate-500">Pick where this website keeps its products. Every website names these differently.</p>
        </div>
      </div>
      <form method="post" class="p-5 space-y-5">
        <input type="hidden" name="action" value="preview">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="prev_table" value="<?= h($choice['table']) ?>">

        <div>
          <label class="label" for="table">Products table</label>
          <select id="table" name="table" class="input" onchange="this.form.submit()">
            <?php foreach ($tables as $t): ?>
              <option value="<?= h($t) ?>" <?= $t === $choice['table'] ? 'selected' : '' ?>><?= h($t) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($preview !== null): ?><p class="hint"><?= number_format($preview['count']) ?> rows</p><?php endif; ?>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="label" for="code">Code / SKU column <span class="text-brand-600">*</span></label>
            <select id="code" name="code" class="input" onchange="this.form.submit()"><?= column_options($columns, $choice['code']) ?></select>
            <p class="hint">What the QR code or barcode on the item contains, e.g. sku, model_number or barcode.</p>
          </div>
          <div>
            <label class="label" for="code2">Also match codes in</label>
            <select id="code2" name="code2" class="input" onchange="this.form.submit()"><?= column_options($columns, $choice['code2'], '— nothing else —') ?></select>
            <p class="hint">Optional second code, e.g. barcode, or the ID column if your QR labels hold the product page link.</p>
          </div>
          <div>
            <label class="label" for="stock">Stock column <span class="text-brand-600">*</span></label>
            <select id="stock" name="stock" class="input" onchange="this.form.submit()">
              <?php if (!$numericColumns): ?>
                <option value="">No number columns in this table</option>
              <?php else: ?>
                <?= column_options($numericColumns, $choice['stock'], $choice['stock'] === '' ? '— choose —' : null) ?>
              <?php endif; ?>
            </select>
            <p class="hint">The number that goes up when stock arrives.</p>
          </div>
          <div>
            <label class="label" for="name">Product name column</label>
            <select id="name" name="name" class="input" onchange="this.form.submit()"><?= column_options($columns, $choice['name'], '— none —') ?></select>
            <p class="hint">Shown on the scanner while counting.</p>
          </div>
          <div>
            <label class="label" for="id">Product ID column <span class="text-brand-600">*</span></label>
            <select id="id" name="id" class="input" onchange="this.form.submit()"><?= column_options($columns, $choice['id']) ?></select>
            <p class="hint">Usually the primary key, "id".</p>
          </div>
          <div>
            <label class="label" for="updated">"Last updated" column</label>
            <select id="updated" name="updated" class="input" onchange="this.form.submit()"><?= column_options($columns, $choice['updated'], '— don\'t change —') ?></select>
            <p class="hint">Set to the current time when stock is added.</p>
          </div>
          <div class="sm:col-span-2">
            <label class="label" for="timezone">Time zone for dates</label>
            <select id="timezone" name="timezone" class="input">
              <?php foreach (DateTimeZone::listIdentifiers() as $zone): ?>
                <option value="<?= h($zone) ?>" <?= $zone === $choice['timezone'] ? 'selected' : '' ?>><?= h($zone) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="hint">Use the same time zone as the website<?= $laravel ? " (this Laravel site uses " . h($defaultTimezone) . ")" : '' ?>.</p>
          </div>
        </div>

        <?php if ($preview !== null): ?>
          <div>
            <p class="label">Preview: first 5 products with these columns</p>
            <div class="overflow-x-auto rounded-lg border border-slate-200">
              <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th class="px-3 py-2 text-left font-semibold">ID</th>
                    <th class="px-3 py-2 text-left font-semibold">Code</th>
                    <?php if ($choice['code2'] !== ''): ?><th class="px-3 py-2 text-left font-semibold">Code 2</th><?php endif; ?>
                    <th class="px-3 py-2 text-left font-semibold">Name</th>
                    <th class="px-3 py-2 text-right font-semibold">Stock</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($preview['rows'] as $row): ?>
                    <tr class="border-t border-slate-100">
                      <td class="px-3 py-2 font-mono text-xs text-slate-500"><?= h($row['id']) ?></td>
                      <td class="px-3 py-2 font-mono text-xs"><?= h($row['code_0']) ?></td>
                      <?php if ($choice['code2'] !== ''): ?><td class="px-3 py-2 font-mono text-xs"><?= h($row['code_1']) ?></td><?php endif; ?>
                      <td class="px-3 py-2"><?= h($row['name']) ?></td>
                      <td class="px-3 py-2 text-right tabular-nums"><?= h($row['stock']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$preview['rows']): ?>
                    <tr><td colspan="5" class="px-3 py-4 text-center text-slate-400">This table is empty.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <?php if ($preview['duplicates']): ?>
            <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
              Some codes belong to more than one product, e.g. <b><?= h(implode(', ', array_slice($preview['duplicates'], 0, 5))) ?></b>.
              Scans of those codes will be refused because the scanner can't tell which product is meant.
            </p>
          <?php endif; ?>
          <?php if ($preview['engine'] !== '' && strcasecmp($preview['engine'], 'InnoDB') !== 0): ?>
            <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
              This table uses the <?= h($preview['engine']) ?> engine, which can't undo a half-finished update.
              Converting it to InnoDB is safer.
            </p>
          <?php endif; ?>

          <div class="rounded-lg border border-dashed border-slate-300 p-4">
            <label class="label" for="test_code">Test a code</label>
            <div class="flex gap-2">
              <input id="test_code" name="test_code" class="input font-mono" placeholder="Type or scan a code from a product label" value="<?= h($preview['test']['code'] ?? '') ?>">
              <button name="action" value="test" class="btn btn-secondary">Test</button>
            </div>
            <?php if ($preview['test'] !== null): $t = $preview['test']; ?>
              <?php if ($t['product'] !== null): ?>
                <p class="mt-2 text-sm text-emerald-700">
                  Matches product <b><?= h($t['product']['id']) ?></b>: <?= h($t['product']['name'] ?: $t['product']['code']) ?>
                  (stock now <?= h($t['product']['stock']) ?>).
                </p>
              <?php elseif ($t['id'] === AMBIGUOUS): ?>
                <p class="mt-2 text-sm text-amber-700">More than one product has this code, so a scan of it would be refused.</p>
              <?php elseif ($t['code'] !== ''): ?>
                <p class="mt-2 text-sm text-red-700">No product has this code in the column<?= $choice['code2'] !== '' ? 's' : '' ?> chosen above.</p>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="flex items-center gap-3 border-t border-slate-100 pt-4">
          <button name="action" value="save" class="btn btn-primary">Save settings</button>
          <?php if (!empty($config['saved_at'])): ?>
            <span class="text-xs text-slate-500">Last saved <?= h((new DateTimeImmutable($config['saved_at']))->setTimezone(new DateTimeZone($config['timezone']))->format('M j, Y g:i A')) ?></span>
          <?php endif; ?>
        </div>
      </form>
    </section>
    <?php endif; ?>

    <?php if ($showBatchSql && $pdo !== null): ?>
      <section class="card p-5">
        <p class="label">SQL for the <?= h(BATCH_TABLE) ?> table</p>
        <pre class="overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100"><?= h(batch_table_sql($pdo)) ?>;</pre>
      </section>
    <?php endif; ?>

    <?php if ($manualConfig !== null): ?>
      <section class="card p-5">
        <p class="label">Contents for stock-api/config.php</p>
        <textarea class="input h-64 py-2 font-mono text-xs" readonly><?= h($manualConfig) ?></textarea>
      </section>
    <?php endif; ?>

    <?php if ($config !== null): ?>
    <!-- ======================= 3. Scanner ======================= -->
    <section class="card">
      <div class="card-head">
        <span class="step">3</span>
        <div>
          <h2 class="font-semibold">Connect the scanner</h2>
          <p class="text-xs text-slate-500">In the scanner, open Settings, choose "Website", and paste these two.</p>
        </div>
      </div>
      <div class="p-5 space-y-4">
        <div>
          <label class="label" for="apiUrl">API address</label>
          <div class="flex gap-2">
            <input id="apiUrl" class="input font-mono" readonly value="<?= h(api_url()) ?>">
            <button type="button" class="btn btn-secondary" onclick="copyField('apiUrl', this)">Copy</button>
          </div>
        </div>
        <div>
          <label class="label" for="apiToken">API token</label>
          <div class="flex gap-2">
            <input id="apiToken" type="password" class="input font-mono" readonly value="<?= h($config['token']) ?>">
            <button type="button" class="btn btn-secondary" onclick="toggleToken(this)">Show</button>
            <button type="button" class="btn btn-secondary" onclick="copyField('apiToken', this)">Copy</button>
          </div>
          <p class="hint">Anyone with this token can add stock, so only put it into the warehouse scanners.</p>
        </div>
        <?php if (is_file(dirname(__DIR__) . '/index.html')): ?>
          <?php // The scanner sits next to this folder, so it can be opened already connected. The settings ride in the #fragment, which browsers never send to a server. ?>
          <a class="btn btn-primary" href="../index.html#connect=<?= h(rtrim(strtr(base64_encode((string) json_encode(['apiUrl' => api_url(), 'apiToken' => $config['token']])), '+/', '-_'), '=')) ?>">
            Open the scanner connected to this website
          </a>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Make a new token? Scanners using the current one will stop working until you give them the new one.')">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <button name="action" value="new_token" class="text-sm font-medium text-red-600 hover:text-red-700">Make a new token</button>
        </form>
      </div>
    </section>
    <?php endif; ?>
  <?php endif; ?>
</main>

<script>
  function toggleDriver() {
    const sqlite = document.querySelector('input[name="driver"][value="sqlite"]').checked;
    document.getElementById('mysqlFields').classList.toggle('hidden', sqlite);
    document.getElementById('sqliteFields').classList.toggle('hidden', !sqlite);
  }
  function toggleToken(button) {
    const field = document.getElementById('apiToken');
    field.type = field.type === 'password' ? 'text' : 'password';
    button.textContent = field.type === 'password' ? 'Show' : 'Hide';
  }
  async function copyField(id, button) {
    const field = document.getElementById(id);
    try {
      await navigator.clipboard.writeText(field.value);
    } catch {
      field.type = 'text';
      field.select();
      document.execCommand('copy');
    }
    const label = button.textContent;
    button.textContent = 'Copied';
    setTimeout(() => { button.textContent = label; }, 1500);
  }
</script>
</body>
</html>
