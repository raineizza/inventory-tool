<?php
/**
 * Stock-in API for the warehouse scanner.
 *
 *   GET   the product list (id, code, name), so the scanner can recognise codes while scanning
 *   POST  add a confirmed count to stock. Every line is added or none is, and a batch that
 *         arrives twice (a retry after a dropped connection) is only added once.
 *
 * Both need the API token in an "Authorization: Bearer <token>" header.
 * The database and the product columns are set in setup.php, which writes config.php.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

const MAX_LINES = 500;
const MAX_QUANTITY = 100000;
const MAX_CATALOG = 50000;
const MAX_BODY_BYTES = 1048576;

ini_set('display_errors', '0');

final class ApiError extends RuntimeException
{
    /** @var int */
    public $status;
    /** @var string */
    public $error;
    /** @var array */
    public $extra;

    public function __construct(int $status, string $error, string $message, array $extra = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->error = $error;
        $this->extra = $extra;
    }
}

send_cors_headers();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $config = load_config();
    if ($config === null || strlen((string) ($config['token'] ?? '')) < 16) {
        throw new ApiError(503, 'not_configured', 'The stock-in API on this website is not set up yet. Open setup.php in the stock-api folder to set it up.');
    }
    if (!hash_equals((string) $config['token'], request_token())) {
        throw new ApiError(401, 'unauthorized', 'The API token is missing or wrong.');
    }
    try {
        $pdo = db_connect($config['db']);
    } catch (Throwable $e) {
        error_log('[stock-api] database connection failed: ' . $e->getMessage());
        throw new ApiError(503, 'database_unavailable', "The website's database can't be reached right now.");
    }

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            respond(200, ['success' => true] + product_catalog($pdo, $config['map'], MAX_CATALOG));
            break;
        case 'POST':
            respond(200, add_stock($pdo, $config, read_json_body()));
            break;
        default:
            header('Allow: GET, POST, OPTIONS');
            throw new ApiError(405, 'method_not_allowed', 'Use GET for the product list, or POST to add stock.');
    }
} catch (ApiError $e) {
    respond($e->status, ['success' => false, 'error' => $e->error, 'message' => $e->getMessage()] + $e->extra);
} catch (Throwable $e) {
    error_log('[stock-api] ' . $e);
    respond(500, [
        'success' => false,
        'error' => 'server_error',
        'message' => 'Something went wrong on the website, so nothing was added. The details are in its PHP error log.',
    ]);
}

// ------------------------------------------------------------------ adding stock

function add_stock(PDO $pdo, array $config, array $body): array
{
    $map = $config['map'];
    [$batchId, $stationId, $lines] = validate_payload($body);
    $hash = hash('sha256', (string) json_encode($lines));

    ensure_batch_table($pdo);
    $existing = find_batch($pdo, $batchId);
    if ($existing !== null) {
        return already_received($existing, $hash);
    }

    // Match every line to a product before changing anything.
    $targets = [];
    $notFound = [];
    $ambiguous = [];
    foreach ($lines as $line) {
        if ($line['productId'] !== null) {
            $id = product_by_id($pdo, $map, $line['productId']) ? $line['productId'] : null;
        } else {
            $id = find_product_id($pdo, $map, $line['code']);
        }
        $label = $line['code'] ?? $line['productId'];
        if ($id === AMBIGUOUS) {
            $ambiguous[] = $label;
        } elseif ($id === null) {
            $notFound[] = $label;
        } else {
            $targets[] = ['id' => $id, 'line' => $line];
        }
    }
    if ($notFound || $ambiguous) {
        throw new ApiError(422, 'unknown_products', unknown_message($notFound, $ambiguous), [
            'notFound' => $notFound,
            'ambiguous' => $ambiguous,
        ]);
    }

    $now = now_string($config);
    $stock = qi($pdo, $map['stock']);
    $set = "{$stock} = COALESCE({$stock}, 0) + ?";
    if ($map['updated'] !== '') {
        $set .= ', ' . qi($pdo, $map['updated']) . ' = ?';
    }
    $update = $pdo->prepare('UPDATE ' . qi($pdo, $map['table']) . " SET {$set} WHERE " . qi($pdo, $map['id']) . ' = ?');
    $total = array_sum(array_column($lines, 'quantity'));

    $pdo->beginTransaction();
    try {
        // Inserted first: a second copy of this batch arriving at the same moment waits here,
        // then fails on the unique batch_id instead of adding the stock again.
        $pdo->prepare(
            'INSERT INTO ' . qi($pdo, BATCH_TABLE)
            . ' (batch_id, station_id, total_quantity, items_hash, items, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $batchId,
            $stationId,
            $total,
            $hash,
            json_encode(array_map(function (array $t) {
                return ['productId' => $t['id'], 'code' => $t['line']['code'], 'quantity' => $t['line']['quantity']];
            }, $targets)),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $now,
        ]);

        $results = [];
        foreach ($targets as $target) {
            $params = [$target['line']['quantity']];
            if ($map['updated'] !== '') {
                $params[] = $now;
            }
            $params[] = $target['id'];
            $update->execute($params);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException("Product {$target['id']} was not updated.");
            }
            $product = product_by_id($pdo, $map, $target['id']);
            $results[] = [
                'productId' => $target['id'],
                'code' => $product['code'],
                'name' => $product['name'],
                'quantity' => $target['line']['quantity'],
                'newStock' => $product['stock'],
            ];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && strpos((string) $e->getCode(), '23') === 0) {
            $existing = find_batch($pdo, $batchId);
            if ($existing !== null) {
                return already_received($existing, $hash);
            }
        }
        throw $e;
    }

    return [
        'success' => true,
        'duplicate' => false,
        'batchId' => $batchId,
        'totalQuantity' => $total,
        'items' => $results,
        'message' => sprintf('Added %d unit%s across %d product%s.', $total, $total === 1 ? '' : 's', count($results), count($results) === 1 ? '' : 's'),
    ];
}

/** @return array{0: string, 1: ?string, 2: array} batch id, station id, lines sorted so the same count always hashes the same */
function validate_payload(array $body): array
{
    $batchId = $body['batchId'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null);
    if (!is_string($batchId) || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $batchId)) {
        throw new ApiError(422, 'invalid_request', 'batchId is missing or invalid: use up to 100 letters, numbers, ".", "_", ":" or "-".');
    }

    $stationId = $body['stationId'] ?? null;
    if ($stationId !== null && (!is_string($stationId) || strlen($stationId) > 100)) {
        throw new ApiError(422, 'invalid_request', 'stationId must be text of up to 100 characters.');
    }

    $items = $body['items'] ?? null;
    if (!is_array($items) || $items === [] || array_keys($items) !== range(0, count($items) - 1)) {
        throw new ApiError(422, 'invalid_request', '"items" must be a non-empty list.');
    }
    if (count($items) > MAX_LINES) {
        throw new ApiError(422, 'invalid_request', 'Too many lines in one count (the limit is ' . MAX_LINES . ').');
    }

    $lines = [];
    foreach ($items as $i => $item) {
        $n = $i + 1;
        if (!is_array($item)) {
            throw new ApiError(422, 'invalid_request', "Line {$n} must be an object.");
        }
        $productId = clean_identifier($item['productId'] ?? null);
        $code = clean_identifier($item['code'] ?? null);
        if ($productId === false || $code === false) {
            throw new ApiError(422, 'invalid_request', "Line {$n}: productId and code must be numbers or text of up to 191 characters.");
        }
        if ($productId === null && $code === null) {
            throw new ApiError(422, 'invalid_request', "Line {$n} needs a productId or a code.");
        }
        $quantity = $item['quantity'] ?? null;
        if (!is_int($quantity) || $quantity < 1 || $quantity > MAX_QUANTITY) {
            throw new ApiError(422, 'invalid_request', "Line {$n}: quantity must be a whole number from 1 to " . MAX_QUANTITY . '.');
        }
        $lines[] = ['productId' => $productId, 'code' => $code, 'quantity' => $quantity];
    }

    usort($lines, function (array $a, array $b) {
        return [(string) $a['productId'], (string) $a['code'], $a['quantity']] <=> [(string) $b['productId'], (string) $b['code'], $b['quantity']];
    });
    return [$batchId, $stationId, $lines];
}

/** @return string|null|false trimmed text, null when absent or blank, false when invalid */
function clean_identifier($value)
{
    if ($value === null) {
        return null;
    }
    if (is_int($value)) {
        $value = (string) $value;
    }
    if (!is_string($value) || strlen($value) > 191) {
        return false;
    }
    $value = trim($value);
    return $value === '' ? null : $value;
}

function find_batch(PDO $pdo, string $batchId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM ' . qi($pdo, BATCH_TABLE) . ' WHERE batch_id = ?');
    $stmt->execute([$batchId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function already_received(array $batch, string $hash): array
{
    if (!hash_equals((string) $batch['items_hash'], $hash)) {
        throw new ApiError(409, 'batch_conflict', 'This batch ID was already used for a different count, so nothing was added. Clear the count and scan it again.');
    }
    return [
        'success' => true,
        'duplicate' => true,
        'batchId' => $batch['batch_id'],
        'totalQuantity' => (int) $batch['total_quantity'],
        'items' => [],
        'message' => "This count was already received on {$batch['created_at']}, so the stock was not added again.",
    ];
}

function unknown_message(array $notFound, array $ambiguous): string
{
    $list = function (array $codes): string {
        $shown = array_slice($codes, 0, 10);
        $more = count($codes) - count($shown);
        return implode(', ', $shown) . ($more > 0 ? " and {$more} more" : '');
    };
    $parts = [];
    if ($notFound) {
        $parts[] = 'Not found on this website: ' . $list($notFound) . '.';
    }
    if ($ambiguous) {
        $parts[] = 'Matches more than one product: ' . $list($ambiguous) . '.';
    }
    $parts[] = 'Remove these from the count (or fix them on the website), then press OK again.';
    return implode(' ', $parts);
}

// ------------------------------------------------------------------ HTTP

function read_json_body(): array
{
    $raw = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
    if ($raw === false || trim($raw) === '') {
        throw new ApiError(400, 'invalid_request', 'The request body is empty.');
    }
    if (strlen($raw) > MAX_BODY_BYTES) {
        throw new ApiError(413, 'payload_too_large', 'The request is too large.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new ApiError(400, 'invalid_json', 'The request body must be a JSON object.');
    }
    return $data;
}

function request_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }
    if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
        return $m[1];
    }
    return trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
}

function send_cors_headers(): void
{
    // The token travels in a header, never in cookies, so any page may call this API:
    // without the token it gets nothing.
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Idempotency-Key, X-API-Key');
    header('Access-Control-Max-Age: 600');
    if (($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_PRIVATE_NETWORK'] ?? '') === 'true') {
        header('Access-Control-Allow-Private-Network: true');
    }
}

function respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
