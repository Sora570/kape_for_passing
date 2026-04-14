<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/audit_log.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

$input = $_POST;
$saleId = isset($input['sale_id']) ? (int)$input['sale_id'] : (isset($input['orderID']) ? (int)$input['orderID'] : 0);
$voidReason = sanitize_text($input['void_reason'] ?? '', 500);

if ($saleId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing sale ID']);
    exit;
}

if ($voidReason === '') {
    echo json_encode(['status' => 'error', 'message' => 'Void reason is required']);
    exit;
}

function get_owner_emails(mysqli $conn): array {
    $stmt = $conn->prepare("SELECT value FROM system_settings WHERE setting_name = 'void_notification_emails' LIMIT 1");
    if (!$stmt) {
        return [];
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $value = trim((string)($row['value'] ?? ''));
    if ($value === '') {
        return [];
    }
    $parts = array_filter(array_map('trim', explode(',', $value)));
    $emails = [];
    foreach ($parts as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }
    return array_values(array_unique($emails));
}

function build_receipt_lines(mysqli $conn, int $saleId): array {
    $itemsSql = "
        SELECT 
            si.sale_item_id,
            si.quantity,
            p.productName,
            pv.variant_name,
            pv.size_label,
            f.flavor_name
        FROM sale_items si
        LEFT JOIN products p ON p.productID = si.product_id
        LEFT JOIN product_variants pv ON pv.variant_id = si.variant_id
        LEFT JOIN flavors f ON f.flavor_id = si.flavor_id
        WHERE si.sale_id = ?
    ";

    $itemsStmt = $conn->prepare($itemsSql);
    if (!$itemsStmt) {
        return ['No items'];
    }
    $itemsStmt->bind_param('i', $saleId);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();

    $itemLines = [];
    while ($itemRow = $itemsResult->fetch_assoc()) {
        $qty = (int)$itemRow['quantity'];
        $productName = $itemRow['productName'] ?? 'Unknown Product';
        $line = "{$qty}x {$productName}";

        if ($itemRow['variant_name'] || $itemRow['size_label']) {
            $sizeInfo = $itemRow['size_label'] ?? $itemRow['variant_name'];
            $line .= " ({$sizeInfo})";
        }

        if ($itemRow['flavor_name']) {
            $line .= " [{$itemRow['flavor_name']}]";
        }

        $addonSql = "
            SELECT a.addon_name
            FROM sale_addons sa
            LEFT JOIN addons a ON a.addon_id = sa.addon_id
            WHERE sa.sale_item_id = ?
        ";
        $addonStmt = $conn->prepare($addonSql);
        if ($addonStmt) {
            $addonStmt->bind_param('i', $itemRow['sale_item_id']);
            $addonStmt->execute();
            $addonResult = $addonStmt->get_result();

            $addonNames = [];
            while ($addonRow = $addonResult->fetch_assoc()) {
                if ($addonRow['addon_name']) {
                    $addonNames[] = $addonRow['addon_name'];
                }
            }
            $addonStmt->close();

            if (!empty($addonNames)) {
                $line .= ' [+ ' . implode(', ', $addonNames) . ']';
            }
        }

        $itemLines[] = $line;
    }
    $itemsStmt->close();

    if (empty($itemLines)) {
        $itemLines[] = 'No items';
    }

    return $itemLines;
}

function build_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $base = $scheme . '://' . $host . $dir;
    return rtrim($base, '/');
}

try {
    $branchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : null;
    $currentUserId = (int)$_SESSION['userID'];

    $conn->begin_transaction();

    $saleQuery = "SELECT s.sale_id, s.status, s.total_amount, s.reference_number, s.sale_datetime, s.payment_method, s.branch_id,
                        u.username AS cashier_name, u.employee_id AS cashier_id, b.branch_name
                 FROM sales s
                 LEFT JOIN users u ON u.userID = s.user_id
                 LEFT JOIN branches b ON b.branch_id = s.branch_id
                 WHERE s.sale_id = ?";
    $stmt = $conn->prepare($saleQuery);
    $stmt->bind_param('i', $saleId);
    $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sale) {
        throw new Exception('Sale not found');
    }

    if ($branchId !== null && (int)$sale['branch_id'] !== (int)$branchId) {
        throw new Exception('Unauthorized for this branch');
    }

    $currentStatus = strtolower((string)($sale['status'] ?? ''));
    if ($currentStatus === 'cancelled') {
        throw new Exception('Sale already cancelled');
    }

    if ($currentStatus === 'pending_void') {
        throw new Exception('Sale already pending void approval');
    }

    $emails = get_owner_emails($conn);
    if (empty($emails)) {
        throw new Exception('No owner emails configured');
    }

    $token = bin2hex(random_bytes(24));
    $tokenExpiresAt = (new DateTime('+2 hours'))->format('Y-m-d H:i:s');

    $insertStmt = $conn->prepare("INSERT INTO void_requests (sale_id, requested_by, reason, status, approval_token, token_expires_at)
                                  VALUES (?, ?, ?, 'pending', ?, ?)");
    $insertStmt->bind_param('iisss', $saleId, $currentUserId, $voidReason, $token, $tokenExpiresAt);
    $insertStmt->execute();
    $insertStmt->close();

    $updateStmt = $conn->prepare("UPDATE sales SET status = 'pending_void' WHERE sale_id = ?");
    $updateStmt->bind_param('i', $saleId);
    $updateStmt->execute();
    $updateStmt->close();

    logOrderActivity($conn, $currentUserId, 'void_request', "Sale ID: $saleId, Reason: $voidReason");

    $receiptLines = build_receipt_lines($conn, $saleId);
    $baseUrl = build_base_url();
    $approveLink = $baseUrl . '/orders_void_approve.php?token=' . urlencode($token);

    $subject = "Void approval requested for Order #{$saleId}";
    $body = "A void request has been submitted and requires approval.\n\n" .
            "Order: #{$saleId}\n" .
            "Reference: " . ($sale['reference_number'] ?? '-') . "\n" .
            "Total: ₱" . number_format((float)($sale['total_amount'] ?? 0), 2) . "\n" .
            "Payment Method: " . ($sale['payment_method'] ?? '-') . "\n" .
            "Cashier: " . ($sale['cashier_name'] ?? '-') . " (" . ($sale['cashier_id'] ?? '-') . ")\n" .
            "Branch: " . ($sale['branch_name'] ?? 'Main Branch') . "\n" .
            "Order Time: " . ($sale['sale_datetime'] ?? '-') . "\n" .
            "Reason: {$voidReason}\n\n" .
            "Receipt:\n" . implode("\n", $receiptLines) . "\n\n" .
            "Approve this request: {$approveLink}\n";

    foreach ($emails as $email) {
        send_reset_mail($email, $subject, $body, false);
    }

    $conn->commit();

    echo json_encode(['status' => 'success', 'message' => 'Void request sent for approval']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
