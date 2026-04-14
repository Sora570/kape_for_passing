<?php
/**
 * Helper functions for production cost overrides (database-based)
 * Replaces JSON file-based storage
 */

/** Cached check: does production_cost_overrides have variant_id column? */
function _production_cost_has_variant_id(mysqli $conn): bool
{
    static $has = null;
    if ($has === null) {
        $r = @$conn->query("SHOW COLUMNS FROM production_cost_overrides LIKE 'variant_id'");
        $has = $r && $r->num_rows > 0;
    }
    return $has;
}

/**
 * Load production cost overrides from database
 * Returns array: [productID => [inventoryID => ['neededPerCup' => float, 'ingredientCost' => float, 'removed' => bool]]]
 */
function load_production_cost_overrides_from_db(mysqli $conn): array
{
    $overrides = [];
    
    if (_production_cost_has_variant_id($conn)) {
        $sql = "SELECT productID, inventoryID, needed_per_cup, ingredient_cost, is_removed
                FROM production_cost_overrides
                WHERE is_active = 1 AND variant_id IS NULL";
    } else {
        $sql = "SELECT productID, inventoryID, needed_per_cup, ingredient_cost, is_removed
                FROM production_cost_overrides
                WHERE is_active = 1";
    }
    
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $productID = (int)$row['productID'];
            $inventoryID = (int)$row['inventoryID'];
            
            if ($productID <= 0 || $inventoryID <= 0) {
                continue;
            }
            
            if (!isset($overrides[$productID])) {
                $overrides[$productID] = [];
            }
            
            $overrides[$productID][] = [
                'inventoryID' => $inventoryID,
                'neededPerCup' => $row['needed_per_cup'] !== null ? (float)$row['needed_per_cup'] : null,
                'ingredientCost' => $row['ingredient_cost'] !== null ? (float)$row['ingredient_cost'] : null,
                'removed' => (bool)$row['is_removed']
            ];
        }
        $result->free();
    }
    
    return $overrides;
}

/**
 * Get production cost overrides for a specific product
 * Returns array: [inventoryID => ['neededPerCup' => float, 'ingredientCost' => float, 'removed' => bool]]
 */
function get_production_cost_overrides_for_product(mysqli $conn, int $productID, ?int $variantID = null): array
{
    $overrides = [];
    $hasVariantId = _production_cost_has_variant_id($conn);
    
    if ($hasVariantId) {
        if ($variantID !== null) {
            $stmt = $conn->prepare("
                SELECT inventoryID, needed_per_cup, ingredient_cost, is_removed
                FROM production_cost_overrides
                WHERE productID = ? AND variant_id = ? AND is_active = 1
            ");
            $stmt->bind_param('ii', $productID, $variantID);
        } else {
            $stmt = $conn->prepare("
                SELECT inventoryID, needed_per_cup, ingredient_cost, is_removed
                FROM production_cost_overrides
                WHERE productID = ? AND variant_id IS NULL AND is_active = 1
            ");
            $stmt->bind_param('i', $productID);
        }
    } else {
        $stmt = $conn->prepare("
            SELECT inventoryID, needed_per_cup, ingredient_cost, is_removed
            FROM production_cost_overrides
            WHERE productID = ? AND is_active = 1
        ");
        $stmt->bind_param('i', $productID);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $inventoryID = (int)$row['inventoryID'];
        if ($inventoryID <= 0) {
            continue;
        }
        
        $overrides[] = [
            'inventoryID' => $inventoryID,
            'neededPerCup' => $row['needed_per_cup'] !== null ? (float)$row['needed_per_cup'] : null,
            'ingredientCost' => $row['ingredient_cost'] !== null ? (float)$row['ingredient_cost'] : null,
            'removed' => (bool)$row['is_removed']
        ];
    }
    
    $stmt->close();
    return $overrides;
}

/**
 * Save or update production cost override in database
 */
function persist_production_cost_override_to_db(
    mysqli $conn,
    int $productID,
    ?int $variantID,
    int $inventoryID,
    ?float $neededPerCup = null,
    ?float $ingredientCost = null,
    bool $isRemoved = false
): void {
    $hasVariantId = _production_cost_has_variant_id($conn);
    $isRemovedInt = $isRemoved ? 1 : 0;

    if ($hasVariantId) {
        $stmt = $conn->prepare("
            INSERT INTO production_cost_overrides (productID, variant_id, inventoryID, needed_per_cup, ingredient_cost, is_removed)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                needed_per_cup = VALUES(needed_per_cup),
                ingredient_cost = VALUES(ingredient_cost),
                is_removed = VALUES(is_removed),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->bind_param('iiiddi', $productID, $variantID, $inventoryID, $neededPerCup, $ingredientCost, $isRemovedInt);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO production_cost_overrides (productID, inventoryID, needed_per_cup, ingredient_cost, is_removed)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                needed_per_cup = VALUES(needed_per_cup),
                ingredient_cost = VALUES(ingredient_cost),
                is_removed = VALUES(is_removed),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->bind_param('iiddi', $productID, $inventoryID, $neededPerCup, $ingredientCost, $isRemovedInt);
    }
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to save production cost override: ' . $stmt->error);
    }
    
    $stmt->close();
}

/**
 * Remove production cost override (mark as inactive or delete)
 */
function remove_production_cost_override_from_db(mysqli $conn, int $productID, int $inventoryID, ?int $variantID = null): void
{
    $hasVariantId = _production_cost_has_variant_id($conn);
    if ($hasVariantId) {
        if ($variantID !== null) {
            $stmt = $conn->prepare("DELETE FROM production_cost_overrides WHERE productID = ? AND variant_id = ? AND inventoryID = ?");
            $stmt->bind_param('iii', $productID, $variantID, $inventoryID);
        } else {
            $stmt = $conn->prepare("DELETE FROM production_cost_overrides WHERE productID = ? AND variant_id IS NULL AND inventoryID = ?");
            $stmt->bind_param('ii', $productID, $inventoryID);
        }
    } else {
        $stmt = $conn->prepare("DELETE FROM production_cost_overrides WHERE productID = ? AND inventoryID = ?");
        $stmt->bind_param('ii', $productID, $inventoryID);
    }
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to remove production cost override: ' . $stmt->error);
    }
    
    $stmt->close();
}

