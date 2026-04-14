<?php
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/mail_helper.php';

header('Content-Type: application/json');

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
    $emails = get_owner_emails($conn);
    if (empty($emails)) {
        echo json_encode(['status' => 'error', 'message' => 'No owner emails configured']);
        exit;
    }

    $query = "
        SELECT vr.id, vr.sale_id, vr.reason, vr.requested_at, vr.approval_token,
               s.total_amount, s.reference_number, s.sale_datetime, s.payment_method,
               u.username AS cashier_name, u.employee_id AS cashier_id, b.branch_name
        FROM void_requests vr
        LEFT JOIN sales s ON s.sale_id = vr.sale_id
        LEFT JOIN users u ON u.userID = s.user_id
        LEFT JOIN branches b ON b.branch_id = s.branch_id
        WHERE vr.status = 'pending'
          AND vr.requested_at <= DATE_SUB(NOW(), INTERVAL 2 HOUR)
          AND (vr.last_escalation_sent_at IS NULL OR vr.last_escalation_sent_at <= DATE_SUB(NOW(), INTERVAL 2 HOUR))
    ";
    $result = $conn->query($query);

    $baseUrl = build_base_url();
    $count = 0;

    while ($row = $result->fetch_assoc()) {
        $saleId = (int)$row['sale_id'];
        $receiptLines = build_receipt_lines($conn, $saleId);
        $approveLink = $baseUrl . '/orders_void_approve.php?token=' . urlencode($row['approval_token']);

        $subject = "Escalation: Void approval pending for Order #{$saleId}";
        $body = "A void request is still pending approval.\n\n" .
            "Order: #{$saleId}\n" .
            "Reference: " . ($row['reference_number'] ?? '-') . "\n" .
            "Total: ₱" . number_format((float)($row['total_amount'] ?? 0), 2) . "\n" .
            "Payment Method: " . ($row['payment_method'] ?? '-') . "\n" .
            "Cashier: " . ($row['cashier_name'] ?? '-') . " (" . ($row['cashier_id'] ?? '-') . ")\n" .
            "Branch: " . ($row['branch_name'] ?? 'Main Branch') . "\n" .
            "Order Time: " . ($row['sale_datetime'] ?? '-') . "\n" .
            "Reason: " . ($row['reason'] ?? '-') . "\n\n" .
            "Receipt:\n" . implode("\n", $receiptLines) . "\n\n" .
            "Approve this request: {$approveLink}\n";

        foreach ($emails as $email) {
            send_reset_mail($email, $subject, $body, false);
        }

        $updateStmt = $conn->prepare("UPDATE void_requests SET last_escalation_sent_at = NOW() WHERE id = ?");
        $updateStmt->bind_param('i', $row['id']);
        $updateStmt->execute();
        $updateStmt->close();
        $count++;
    }

    echo json_encode(['status' => 'success', 'escalated' => $count]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
