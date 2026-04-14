<?php

class RecipeHelper
{
    private ?int $branchId = null;
    private array $recipes;
    private ?mysqli $conn;

    public function __construct(?mysqli $conn = null, ?int $branchId = null)
    {
        $this->conn = $conn;
        $this->branchId = $branchId;
        $this->loadFromDatabase();
    }

    private function loadFromDatabase(): void
    {
        // Initialize empty arrays
        $this->recipes = [];
        // If no connection provided, try to get one
        if ($this->conn === null) {
            $dbPath = __DIR__ . '/../db/db_connect.php';
            if (file_exists($dbPath)) {
                require_once $dbPath;
                if (isset($conn) && $conn instanceof mysqli) {
                    $this->conn = $conn;
                }
            }
        }

        if ($this->conn && !$this->conn->connect_error) {
            // Load recipes from database
            $recipeSql = "SELECT productID, inventoryID, amount, unit, display_order FROM recipes";
            if ($this->branchId !== null) {
                $recipeSql .= " WHERE productID IN (SELECT productID FROM products WHERE branch_id IS NULL OR branch_id = " . (int)$this->branchId . ")";
            }
            $recipeSql .= " ORDER BY productID, display_order";
            $recipeResult = $this->conn->query($recipeSql);
            if ($recipeResult) {
                while ($row = $recipeResult->fetch_assoc()) {
                    $productID = (int)$row['productID'];
                    if (!isset($this->recipes[$productID])) {
                        $this->recipes[$productID] = [];
                    }
                    $this->recipes[$productID][] = [
                        'inventoryID' => (int)$row['inventoryID'],
                        'amount' => (float)$row['amount'],
                        'unit' => $row['unit']
                    ];
                }
                $recipeResult->free();
            }
        }
    }

    /**
     * Aggregate ingredient usage for a collection of cart/order items.
     * Result format: [inventoryID => ['amount' => float, 'unit' => 'ml'|'g']]
     */
    public function aggregateUsage(array $items): array
    {
        $usage = [];

        foreach ($items as $item) {
            $productID = $this->extractProductId($item);
            if (!$productID || !isset($this->recipes[$productID])) {
                continue;
            }

            $quantity = $this->extractQuantity($item);
            if ($quantity <= 0) {
                $quantity = 1.0;
            }
            foreach ($this->recipes[$productID] as $ingredient) {
                $inventoryID = (int)($ingredient['inventoryID'] ?? 0);
                $amount = (float)($ingredient['amount'] ?? 0);
                $unit = $this->normalizeUnit($ingredient['unit'] ?? '');

                if ($inventoryID <= 0 || $amount <= 0 || !$unit) {
                    continue;
                }

                if (!isset($usage[$inventoryID])) {
                    $usage[$inventoryID] = ['amount' => 0.0, 'unit' => $unit];
                }

                $usage[$inventoryID]['amount'] += $amount * $quantity;
            }
        }

        return $usage;
    }

    public function mergeUsage(array &$base, array $addition): void
    {
        foreach ($addition as $inventoryID => $info) {
            if (!isset($info['amount']) || $info['amount'] <= 0) {
                continue;
            }

            if (!isset($base[$inventoryID])) {
                $base[$inventoryID] = [
                    'amount' => 0.0,
                    'unit' => $info['unit'] ?? 'ml'
                ];
            }

            $base[$inventoryID]['amount'] += $info['amount'];
        }
    }

    public function fetchInventoryRows(mysqli $conn, array $inventoryIDs, bool $forUpdate = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $inventoryIDs))));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT inventoryID, `Size`, `Unit`, `Current_Stock`, `Cost_Price`
                FROM inventory
                WHERE inventoryID IN ($placeholders)";
        if ($this->branchId !== null) {
            $sql .= " AND (branch_id IS NULL OR branch_id = " . (int)$this->branchId . ")";
        }
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare inventory lookup: " . $conn->error);
        }

        $types = str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[(int)$row['inventoryID']] = $row;
        }

        $stmt->close();
        return $rows;
    }

    public function mapUsageToInventory(array $usage, array $inventoryRows): array
    {
        $mapped = [];

        foreach ($usage as $inventoryID => $info) {
            if (!isset($inventoryRows[$inventoryID])) {
                continue;
            }

            $row = $inventoryRows[$inventoryID];
            $packCapacity = $this->getPackCapacity($row['Size'] ?? 0, $row['Unit'] ?? '');
            if ($packCapacity <= 0) {
                continue;
            }

            $requiredAmount = (float)$info['amount'];
            if ($requiredAmount <= 0) {
                continue;
            }

            $fraction = $requiredAmount / $packCapacity;
            $mapped[$inventoryID] = [
                'fraction' => $fraction,
                'required_amount' => $requiredAmount,
                'unit' => $info['unit'],
                'pack_capacity' => $packCapacity,
                'current_stock' => (float)$row['Current_Stock'],
                'cost_price' => (float)$row['Cost_Price']
            ];
        }

        return $mapped;
    }

    public function calculateCost(array $mappedUsage): float
    {
        $total = 0.0;
        foreach ($mappedUsage as $data) {
            $fraction = $data['fraction'] ?? 0;
            $costPrice = $data['cost_price'] ?? 0;
            if ($fraction > 0 && $costPrice >= 0) {
                $total += $fraction * $costPrice;
            }
        }
        return round($total, 2);
    }

    public function calculateCostPerOrderMap(array $inventoryRows): array
    {
        $usageMap = $this->getAverageUsageByIngredient();
        if (empty($usageMap)) {
            return [];
        }

        $costs = [];
        foreach ($inventoryRows as $row) {
            $inventoryID = (int)($row['inventoryID'] ?? 0);
            if (!$inventoryID || !isset($usageMap[$inventoryID])) {
                continue;
            }

            $packCapacity = $this->getPackCapacity($row['Size'] ?? $row['size'] ?? 0, $row['Unit'] ?? $row['unit'] ?? '');
            $packUnit = $this->normalizeUnit($row['Unit'] ?? $row['unit'] ?? '');
            if ($packCapacity <= 0 || !$packUnit) {
                continue;
            }

            $usage = $usageMap[$inventoryID];
            if ($usage['unit'] !== $packUnit) {
                continue;
            }

            $usageAmount = $usage['amount'];
            if ($usageAmount <= 0) {
                continue;
            }

            $fraction = $usageAmount / $packCapacity;
            $costPrice = (float)($row['Cost_Price'] ?? $row['Cost Price'] ?? 0);
            if ($costPrice <= 0 || $fraction <= 0) {
                continue;
            }

            $costs[$inventoryID] = max(0, $fraction * $costPrice);
        }

        return $costs;
    }

    private function getAverageUsageByIngredient(): array
    {
        $usage = [];

        foreach ($this->recipes as $recipe) {
            foreach ($recipe as $ingredient) {
                $inventoryID = (int)($ingredient['inventoryID'] ?? 0);
                $amount = (float)($ingredient['amount'] ?? 0);
                $originalUnit = $ingredient['unit'] ?? '';
                $unit = $this->normalizeUnit($originalUnit);

                if ($inventoryID <= 0 || $amount <= 0 || !$unit) {
                    continue;
                }

                $amountInBase = $this->convertAmountToBase($amount, $originalUnit);

                if (!isset($usage[$inventoryID])) {
                    $usage[$inventoryID] = [
                        'total' => 0.0,
                        'count' => 0,
                        'unit' => $unit
                    ];
                }

                $usage[$inventoryID]['total'] += $amountInBase;
                $usage[$inventoryID]['count'] += 1;
            }
        }

        $averages = [];
        foreach ($usage as $inventoryID => $data) {
            if ($data['count'] <= 0) {
                continue;
            }
            $averages[$inventoryID] = [
                'amount' => $data['total'] / $data['count'],
                'unit' => $data['unit']
            ];
        }

        return $averages;
    }

    public function getAverageUsageMap(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $averages = $this->getAverageUsageByIngredient();
        $formatted = [];
        foreach ($averages as $inventoryID => $data) {
            $amount = rtrim(rtrim(number_format($data['amount'], 2, '.', ''), '0'), '.');
            if ($amount === '') {
                $amount = '0';
            }
            $unitSuffix = $data['unit'] === 'ml' ? 'mL' : 'g';
            $formatted[$inventoryID] = $amount . $unitSuffix;
        }

        return $cache = $formatted;
    }

    public function getIngredientProductMap(mysqli $conn): array
    {
        $productNames = [];
        $branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;
        $sql = "SELECT p.productID, p.productName FROM products p";
        if ($branchId !== null) {
            $sql .= " WHERE (p.branch_id IS NULL OR p.branch_id = " . (int)$branchId . ")";
        }
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $label = trim($row['productName'] ?? '');
                if ($label === '') {
                    $label = 'Product #' . $row['productID'];
                }
                $productNames[(int)$row['productID']] = $label;
            }
            $result->free();
        }

        $usage = [];
        foreach ($this->recipes as $productID => $ingredients) {
            $label = $productNames[$productID] ?? ('Product #' . $productID);
            foreach ($ingredients as $ingredient) {
                $inventoryID = (int)($ingredient['inventoryID'] ?? 0);
                if ($inventoryID <= 0) {
                    continue;
                }
                if (!isset($usage[$inventoryID])) {
                    $usage[$inventoryID] = [];
                }
                $usage[$inventoryID][$label] = true;
            }
        }

        $summary = [];
        foreach ($usage as $inventoryID => $labels) {
            $list = array_keys($labels);
            $count = count($list);
            if ($count <= 3) {
                $summary[$inventoryID] = implode(', ', $list);
            } else {
                $summary[$inventoryID] = implode(', ', array_slice($list, 0, 3)) . ' +' . ($count - 3) . ' more';
            }
        }

        return $summary;
    }

    private function extractProductId($item): int
    {
        if (!is_array($item)) {
            return 0;
        }
        if (isset($item['productID'])) {
            return (int)$item['productID'];
        }
        if (isset($item['productId'])) {
            return (int)$item['productId'];
        }
        if (isset($item['id'])) {
            return (int)$item['id'];
        }
        return 0;
    }

    private function extractQuantity($item): float
    {
        if (!is_array($item)) {
            return 0.0;
        }
        if (isset($item['quantity'])) {
            return (float)$item['quantity'];
        }
        if (isset($item['qty'])) {
            return (float)$item['qty'];
        }
        return 1.0;
    }

    private function extractSizeId($item): int
    {
        if (!is_array($item)) {
            return 0;
        }
        if (isset($item['sizeID'])) {
            return (int)$item['sizeID'];
        }
        if (isset($item['sizeId'])) {
            return (int)$item['sizeId'];
        }
        return 0;
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = strtolower(trim($unit));
        return match ($unit) {
            'milliliter', 'millilitre', 'ml', 'l', 'liter', 'litre' => 'ml',
            'gram', 'grams', 'g', 'kg', 'kilogram' => 'g',
            'piece', 'pieces', 'pc', 'pcs', 'unit', 'units', 'ounce', 'ounces', 'oz' => 'pc',
            default => $unit ?: 'ml',
        };
    }

    private function convertAmountToBase(float $amount, string $unit): float
    {
        $unit = strtolower(trim($unit));
        return match ($unit) {
            'liter', 'litre', 'l' => $amount * 1000.0,
            'kilogram', 'kg' => $amount * 1000.0,
            default => $amount,
        };
    }

    private function getPackCapacity($sizeValue, string $unit): float
    {
        $size = (float)$sizeValue;
        if ($size <= 0) {
            return 0;
        }

        $unit = strtolower(trim($unit));
        return match ($unit) {
            'liter', 'litre', 'l' => $size * 1000.0,
            'milliliter', 'millilitre', 'ml' => $size,
            'kilogram', 'kg' => $size * 1000.0,
            'gram', 'g' => $size,
            'piece', 'pieces', 'pc', 'pcs', 'unit', 'units', 'ounce', 'ounces', 'oz' => $size,
            default => $size,
        };
    }

    public function calculateBaseProductCosts(array $inventoryRows): array
    {
        $baseCosts = [];

        foreach ($this->recipes as $productID => $ingredients) {
            $total = 0.0;

            foreach ($ingredients as $ingredient) {
                $inventoryID = (int)($ingredient['inventoryID'] ?? 0);
                if ($inventoryID <= 0 || !isset($inventoryRows[$inventoryID])) {
                    continue;
                }

                $row = $inventoryRows[$inventoryID];
                $packCapacity = $this->getPackCapacity($row['Size'] ?? $row['size'] ?? 0, $row['Unit'] ?? $row['unit'] ?? '');
                if ($packCapacity <= 0) {
                    continue;
                }

                $amountBase = $this->convertAmountToBase((float)($ingredient['amount'] ?? 0), $ingredient['unit'] ?? '');
                $usageUnit = $this->normalizeUnit($ingredient['unit'] ?? '');
                $packUnit = $this->normalizeUnit($row['Unit'] ?? $row['unit'] ?? '');

                if ($usageUnit !== $packUnit) {
                    continue;
                }

                $fraction = $amountBase / $packCapacity;
                $costPrice = (float)($row['Cost_Price'] ?? $row['Cost Price'] ?? 0);
                $total += $fraction * $costPrice;
            }

            $baseCosts[$productID] = max(0, $total);
        }

        return $baseCosts;
    }

}
