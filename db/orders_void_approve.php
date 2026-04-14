<?php
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/audit_log.php';

header('Content-Type: text/html; charset=UTF-8');

$token = trim($_REQUEST['token'] ?? '');
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'approve' : 'review';

function render_page(string $title, string $bodyHtml, int $code = 200): void {
    http_response_code($code);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . '<style>@import url("https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&display=swap");body{font-family:"Fredoka", sans-serif;background:linear-gradient(180deg,#fff1e4,#f8e7d6);color:#4a2f1f;margin:0;padding:32px;} .card{max-width:720px;margin:0 auto;background:#fffdfb;border-radius:18px;box-shadow:0 12px 26px rgba(127,85,57,0.18);padding:28px 28px 24px;border:2px solid #ede0d4;} .brand{display:flex;align-items:center;gap:12px;margin-bottom:18px;} .brand img{width:52px;height:52px;border-radius:14px;object-fit:cover;box-shadow:0 4px 10px rgba(127,85,57,0.18);} .brand h1{margin:0;font-size:20px;color:#7f5539;font-weight:600;} .brand p{margin:0;color:#9c6644;font-size:12px;} .status{font-size:22px;font-weight:700;margin-bottom:10px;color:#7f5539;} .message{font-size:15px;line-height:1.6;color:#6b4b38;} .hint{margin-top:16px;font-size:13px;color:#9c6644;} .meta{margin:16px 0;padding:14px;background:#fff1e4;border-radius:12px;border:1px solid #ede0d4;} .meta div{margin:6px 0;font-size:14px;} .receipt{margin-top:14px;padding:14px;border:1px solid #e6ccb2;border-radius:12px;background:#f8e7d6;} .receipt ul{margin:8px 0 0 18px;padding:0;} .btn{display:inline-flex;align-items:center;justify-content:center;margin-top:12px;padding:10px 20px;background:#7f5539;color:#fff;border:none;border-radius:10px;font-weight:600;cursor:pointer;text-decoration:none;transition:all 0.2s ease;} .btn:hover{background:#6d4329;} .btn-secondary{background:#b08968;} .btn-secondary:hover{background:#9c6644;} .actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;} @media (max-width:600px){body{padding:20px;} .card{padding:22px;} .brand{flex-direction:column;align-items:flex-start;} .btn{width:100%;}}</style>'
        . '</head><body><div class="card"><div class="brand"><img src="../assest/image/logo.png" alt="Kape Timplado"><h1>Kape Timplado</h1></div>' . $bodyHtml . '</div></body></html>';
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

if ($token === '') {
    render_page('Void Request', '<div class="status">Missing approval token</div><div class="message">The approval link is invalid.</div>', 400);
    exit;
}

try {
    $requestStmt = $conn->prepare("SELECT vr.id, vr.sale_id, vr.requested_by, vr.reason, vr.status, vr.token_expires_at,
                                         s.status AS sale_status, s.total_amount, s.reference_number, s.sale_datetime,
                                         s.payment_method, s.branch_id, u.username AS cashier_name, u.employee_id AS cashier_id,
                                         b.branch_name
                                  FROM void_requests vr
                                  LEFT JOIN sales s ON s.sale_id = vr.sale_id
                                  LEFT JOIN users u ON u.userID = s.user_id
                                  LEFT JOIN branches b ON b.branch_id = s.branch_id
                                  WHERE vr.approval_token = ?");
    $requestStmt->bind_param('s', $token);
    $requestStmt->execute();
    $request = $requestStmt->get_result()->fetch_assoc();
    $requestStmt->close();

    if (!$request) {
        render_page('Void Request', '<div class="status">Invalid or expired token</div><div class="message">This approval link is no longer valid.</div>', 404);
        exit;
    }

    if ($request['token_expires_at'] && strtotime($request['token_expires_at']) < time()) {
        $updateStmt = $conn->prepare("UPDATE void_requests SET status = 'expired' WHERE id = ?");
        $updateStmt->bind_param('i', $request['id']);
        $updateStmt->execute();
        $updateStmt->close();
        render_page('Void Request', '<div class="status">Void request expired</div><div class="message">This request is already expired.</div>', 410);
        exit;
    }

    if ($request['status'] !== 'pending') {
        render_page('Void Request', '<div class="status">Void request already processed</div><div class="message">This request has already been handled.</div>', 400);
        exit;
    }

    $saleId = (int)$request['sale_id'];
    $receiptLines = build_receipt_lines($conn, $saleId);

    if ($action === 'approve') {
        $conn->begin_transaction();

        $updateRequest = $conn->prepare("UPDATE void_requests SET status = 'approved', approved_at = NOW(), approved_by = NULL WHERE id = ?");
        $updateRequest->bind_param('i', $request['id']);
        $updateRequest->execute();
        $updateRequest->close();

        $voidReason = $request['reason'] ?? '';
        $requestedBy = (int)$request['requested_by'];
        $updateSale = $conn->prepare("UPDATE sales SET status = 'cancelled', voided_at = NOW(), void_reason = ?, voided_by = ? WHERE sale_id = ?");
        $updateSale->bind_param('sii', $voidReason, $requestedBy, $saleId);
        $updateSale->execute();
        $updateSale->close();

        logOrderActivity($conn, $requestedBy, 'cancel', "Sale ID: $saleId, Reason: $voidReason");

        $conn->commit();

        $body = '<div class="status" style="color:#059669;">Void request approved</div>'
            . '<div class="message">The order has been voided successfully.</div>'
            . '<div class="hint">You can close this page after reviewing the status.</div>';
        render_page('Void Request Approved', $body, 200);
        exit;
    }

    $metaHtml = '<div class="status" style="color:#6d28d9;">Review void request</div>'
        . '<div class="message">Please review the order details below before approving.</div>'
        . '<div class="meta">'
        . '<div><strong>Order:</strong> #' . htmlspecialchars((string)$saleId) . '</div>'
        . '<div><strong>Reference:</strong> ' . htmlspecialchars((string)($request['reference_number'] ?? '-')) . '</div>'
        . '<div><strong>Total:</strong> ₱' . number_format((float)($request['total_amount'] ?? 0), 2) . '</div>'
        . '<div><strong>Payment Method:</strong> ' . htmlspecialchars((string)($request['payment_method'] ?? '-')) . '</div>'
        . '<div><strong>Cashier:</strong> ' . htmlspecialchars((string)($request['cashier_name'] ?? '-')) . ' (' . htmlspecialchars((string)($request['cashier_id'] ?? '-')) . ')</div>'
        . '<div><strong>Branch:</strong> ' . htmlspecialchars((string)($request['branch_name'] ?? 'Main Branch')) . '</div>'
        . '<div><strong>Order Time:</strong> ' . htmlspecialchars((string)($request['sale_datetime'] ?? '-')) . '</div>'
        . '<div><strong>Reason:</strong> ' . nl2br(htmlspecialchars((string)($request['reason'] ?? '-'))) . '</div>'
        . '</div>';

    $receiptHtml = '<div class="receipt"><strong>Receipt</strong><ul>';
    foreach ($receiptLines as $line) {
        $receiptHtml .= '<li>' . htmlspecialchars($line) . '</li>';
    }
    $receiptHtml .= '</ul></div>';

    $actionHtml = '<form method="POST">'
        . '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">' 
        . '<div class="actions">'
        . '<button type="submit" class="btn">Approve void request</button>'
        . '<a class="btn btn-secondary" href="#" onclick="window.close();return false;">Close</a>'
        . '</div></form>';

    render_page('Void Request Review', $metaHtml . $receiptHtml . $actionHtml, 200);
} catch (Exception $e) {
    if ($conn->errno) {
        $conn->rollback();
    }
    render_page('Void Request', '<div class="status">Server error</div><div class="message">' . htmlspecialchars($e->getMessage()) . '</div>', 500);
}

$conn->close();
?>
