<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_connect.php';

try {
    require_once __DIR__ . '/session_config.php';
    require_once __DIR__ . '/auth_check.php';
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        throw new Exception('Unauthorized access. Admin role required.');
    }

    $branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;
    $mode = $_GET['mode'] ?? 'day';
    $mode = strtolower($mode);
    $statusClause = "(s.status IS NULL OR LOWER(s.status) IN ('completed','delivered','paid')) AND LOWER(s.status) != 'cancelled'";
    $branchClause = ($branchId !== null) ? " AND s.branch_id = ?" : "";

    if ($mode === 'day') {
        $date = $_GET['date'] ?? date('Y-m-d');
        $start = $date . ' 00:00:00';
        $end = $date . ' 23:59:59';

        $productNameMap = [];
        $nameSql = "SELECT productID, productName FROM products";
        if ($branchId !== null) {
            $nameSql .= " WHERE (branch_id IS NULL OR branch_id = " . (int)$branchId . ")";
        }
        if ($nameResult = $conn->query($nameSql)) {
            while ($nameRow = $nameResult->fetch_assoc()) {
                $productNameMap[(int)$nameRow['productID']] = $nameRow['productName'];
            }
            $nameResult->close();
        }

        $sql = "SELECT s.sale_id FROM sales s WHERE s.sale_datetime BETWEEN ? AND ? AND {$statusClause}{$branchClause} ORDER BY s.sale_datetime ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
        if ($branchId !== null) {
            $stmt->bind_param('ssi', $start, $end, $branchId);
        } else {
            $stmt->bind_param('ss', $start, $end);
        }
        $stmt->execute();
        $res = $stmt->get_result();

        $map = [];
        while ($row = $res->fetch_assoc()) {
            $saleId = (int)$row['sale_id'];
            $itemStmt = $conn->prepare("SELECT product_id, quantity, price FROM sale_items WHERE sale_id = ?" . ($branchId !== null ? " AND branch_id = ?" : ""));
            if ($branchId !== null) {
                $itemStmt->bind_param('ii', $saleId, $branchId);
            } else {
                $itemStmt->bind_param('i', $saleId);
            }
            $itemStmt->execute();
            $itemRes = $itemStmt->get_result();
            while ($it = $itemRes->fetch_assoc()) {
                $productId = (int)($it['product_id'] ?? 0);
                $name = $productId > 0 && isset($productNameMap[$productId]) ? $productNameMap[$productId] : 'Unknown Product';
                $qty = (int)($it['quantity'] ?? 1);
                $price = floatval($it['price'] ?? 0);
                $revenue = $price * $qty;
                $key = $productId > 0 ? 'id_'.$productId : 'name_'.strtolower($name);
                if (!isset($map[$key])) {
                    $map[$key] = ['name' => $name, 'revenue' => 0.0, 'quantity' => 0, 'product_id' => $productId];
                }
                $map[$key]['revenue'] += $revenue;
                $map[$key]['quantity'] += $qty;
            }
            $itemStmt->close();
        }
        $stmt->close();

        $rows = array_values($map);
        usort($rows, function($a,$b){ return ($b['revenue'] <=> $a['revenue']); });

        $labels = array_map(function($r){ return $r['name']; }, $rows);
        $values = array_map(function($r){ return round($r['revenue'],2); }, $rows);

        echo json_encode(['labels' => $labels, 'values' => $values, 'rows' => $rows, 'mode' => 'day', 'date' => $date]);
        exit;
    }

    if ($mode === 'week') {
        $today = new DateTimeImmutable('today 00:00:00');
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $today->modify("-{$i} days");
            $days[$d->format('Y-m-d')] = ['label' => $d->format('D M d'), 'revenue' => 0.0];
        }

        $start = array_key_first($days) . ' 00:00:00';
        $end = $today->format('Y-m-d 23:59:59');

        $sql = "SELECT DATE(s.sale_datetime) as d, COALESCE(SUM(s.total_amount),0) as revenue FROM sales s WHERE s.sale_datetime BETWEEN ? AND ? AND {$statusClause}{$branchClause} GROUP BY d ORDER BY d ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
        if ($branchId !== null) {
            $stmt->bind_param('ssi', $start, $end, $branchId);
        } else {
            $stmt->bind_param('ss', $start, $end);
        }
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $d = $row['d'];
            if (isset($days[$d])) $days[$d]['revenue'] = round(floatval($row['revenue']),2);
        }
        $stmt->close();

        $labels = array_map(function($k,$v){ return $v['label']; }, array_keys($days), $days);
        $values = array_map(function($k,$v){ return $v['revenue']; }, array_keys($days), $days);

        echo json_encode(['labels' => $labels, 'values' => $values, 'rows' => array_values($days), 'mode' => 'week']);
        exit;
    }

    if ($mode === 'month') {
        // current month grouped by weeks (Monday-Sunday)
        $monthStart = new DateTimeImmutable('first day of this month 00:00:00');
        $monthEnd = new DateTimeImmutable('last day of this month 23:59:59');
        
        // Find the first Monday of the month (or start of month if it's a Monday)
        $firstMonday = $monthStart;
        if ($firstMonday->format('N') != '1') {
            $firstMonday = $firstMonday->modify('monday this week');
            if ($firstMonday < $monthStart) {
                $firstMonday = $firstMonday->modify('+1 week');
            }
        }
        
        // Build week buckets for the month
        $weekBuckets = [];
        $currentWeekStart = $firstMonday < $monthStart ? $monthStart : $firstMonday;
        while ($currentWeekStart <= $monthEnd) {
            $weekEnd = $currentWeekStart->modify('+6 days');
            if ($weekEnd > $monthEnd) {
                $weekEnd = $monthEnd;
            }
            $key = $currentWeekStart->format('Y-m-d');
            $weekBuckets[$key] = [
                'start' => $currentWeekStart->format('Y-m-d 00:00:00'),
                'end' => $weekEnd->format('Y-m-d 23:59:59'),
                'label' => $currentWeekStart->format('M d') . ' - ' . $weekEnd->format('M d'),
                'revenue' => 0.0
            ];
            $currentWeekStart = $currentWeekStart->modify('+1 week');
        }
        
        $start = $monthStart->format('Y-m-d H:i:s');
        $end = $monthEnd->format('Y-m-d H:i:s');

        $sql = "SELECT DATE(s.sale_datetime) as order_date, COALESCE(SUM(s.total_amount),0) as revenue FROM sales s WHERE s.sale_datetime BETWEEN ? AND ? AND {$statusClause}{$branchClause} GROUP BY order_date ORDER BY order_date ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
        if ($branchId !== null) {
            $stmt->bind_param('ssi', $start, $end, $branchId);
        } else {
            $stmt->bind_param('ss', $start, $end);
        }
        $stmt->execute();
        $res = $stmt->get_result();

        // Aggregate daily sales into weeks
        while ($row = $res->fetch_assoc()) {
            $orderDate = $row['order_date'];
            $revenue = round(floatval($row['revenue']), 2);
            
            // Find which week this order belongs to
            foreach ($weekBuckets as &$week) {
                if ($orderDate >= $week['start'] && $orderDate <= $week['end']) {
                    $week['revenue'] += $revenue;
                    break;
                }
            }
            unset($week);
        }
        $stmt->close();

        // Convert to arrays and sort by start date
        $rows = array_values($weekBuckets);
        usort($rows, function($a, $b) {
            return strcmp($a['start'], $b['start']);
        });

        $labels = array_map(function($r){ return $r['label']; }, $rows);
        $values = array_map(function($r){ return round($r['revenue'], 2); }, $rows);

        echo json_encode(['labels' => $labels, 'values' => $values, 'rows' => $rows, 'mode' => 'month']);
        exit;
    }

    // default: year (monthly totals for selected year, Jan 1 - Dec 31)
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    // Validate year range
    if ($year < 2020 || $year > 2099) {
        $year = (int)date('Y');
    }
    
    // Create month buckets for the entire year (Jan 1 - Dec 31)
    $yearStart = new DateTimeImmutable("{$year}-01-01 00:00:00");
    $yearEnd = new DateTimeImmutable("{$year}-12-31 23:59:59");
    $monthBuckets = [];
    
    // Generate all 12 months for the selected year
    for ($month = 1; $month <= 12; $month++) {
        $m = new DateTimeImmutable("{$year}-{$month}-01");
        $key = $m->format('Y-m');
        $monthBuckets[$key] = ['label' => $m->format('M Y'), 'revenue' => 0.0];
    }

    $rangeStart = $yearStart->format('Y-m-d H:i:s');
    $rangeEnd = $yearEnd->format('Y-m-d H:i:s');

    $sql = "SELECT DATE_FORMAT(s.sale_datetime, '%Y-%m') as ym, COALESCE(SUM(s.total_amount),0) as revenue FROM sales s WHERE s.sale_datetime BETWEEN ? AND ? AND {$statusClause}{$branchClause} GROUP BY ym ORDER BY ym ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    if ($branchId !== null) {
        $stmt->bind_param('ssi', $rangeStart, $rangeEnd, $branchId);
    } else {
        $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $k = $row['ym'];
        if (isset($monthBuckets[$k])) {
            $monthBuckets[$k]['revenue'] = round(floatval($row['revenue']),2);
        }
    }
    $stmt->close();

    $labels = array_map(function($k,$v){ return $v['label']; }, array_keys($monthBuckets), $monthBuckets);
    $values = array_map(function($k,$v){ return $v['revenue']; }, array_keys($monthBuckets), $monthBuckets);

    echo json_encode(['labels' => $labels, 'values' => $values, 'rows' => array_values($monthBuckets), 'mode' => 'year']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
