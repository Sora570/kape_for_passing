<?php
/**
 * Migration Script: JSON Files to Database Tables
 * 
 * This script migrates data from JSON configuration files to database tables:
 * - inventory_manual_usage.json -> inventory table (qty_per_order column) (DEPRECATED - migration commented out)
 * - production_cost_overrides.json -> production_cost_overrides table
 * - product_manual_costs.json -> product_cost_overrides table (DEPRECATED - no longer used)
 * 
 * Usage: Run this script once to migrate existing data, then update code to use database
 */

require_once __DIR__ . '/db_connect.php';

// Start transaction
$conn->begin_transaction();

try {
    echo "Starting migration from JSON files to database tables...\n\n";
    
    // ============================================
    // STEP 1: Create database tables
    // ============================================
    echo "Step 1: Creating database tables...\n";
    
    // Table 1: inventory_usage_overrides (DEPRECATED - no longer used, kept for backward compatibility)
    // This table is no longer used. Data should be migrated directly to inventory table's qty_per_order column.
    // Commented out to prevent new installations from creating unused tables.
    /*
    $sql1 = "CREATE TABLE IF NOT EXISTS `inventory_usage_overrides` (
        `overrideID` int(11) NOT NULL AUTO_INCREMENT,
        `inventoryID` int(11) NOT NULL,
        `qty_per_order` varchar(50) DEFAULT NULL,
        `cost_per_order` decimal(10,4) DEFAULT NULL,
        `is_active` tinyint(1) DEFAULT 1,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`overrideID`),
        UNIQUE KEY `unique_inventory_override` (`inventoryID`),
        KEY `idx_inventory` (`inventoryID`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    if ($conn->query($sql1)) {
        echo "✓ Created table: inventory_usage_overrides\n";
    } else {
        throw new Exception("Failed to create inventory_usage_overrides table: " . $conn->error);
    }
    */
    
    // Table 2: production_cost_overrides
    $sql2 = "CREATE TABLE IF NOT EXISTS `production_cost_overrides` (
        `overrideID` int(11) NOT NULL AUTO_INCREMENT,
        `productID` int(11) NOT NULL,
        `inventoryID` int(11) NOT NULL,
        `needed_per_cup` decimal(10,4) DEFAULT NULL,
        `ingredient_cost` decimal(10,4) DEFAULT NULL,
        `is_removed` tinyint(1) DEFAULT 0,
        `is_active` tinyint(1) DEFAULT 1,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`overrideID`),
        UNIQUE KEY `unique_product_inventory_override` (`productID`, `inventoryID`),
        KEY `idx_product` (`productID`),
        KEY `idx_inventory` (`inventoryID`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    if ($conn->query($sql2)) {
        echo "✓ Created table: production_cost_overrides\n";
    } else {
        throw new Exception("Failed to create production_cost_overrides table: " . $conn->error);
    }
    
    // Table 3: product_cost_overrides (DEPRECATED - no longer used, kept for backward compatibility)
    // This table is no longer used by the application. Production costs are now calculated from recipes.
    // Commented out to prevent new installations from creating unused tables.
    /*
    $sql3 = "CREATE TABLE IF NOT EXISTS `product_cost_overrides` (
        `overrideID` int(11) NOT NULL AUTO_INCREMENT,
        `product_name` varchar(150) NOT NULL,
        `category_name` varchar(100) DEFAULT NULL,
        `size` varchar(50) DEFAULT NULL,
        `total_cost` decimal(10,2) DEFAULT NULL,
        `selling_price` decimal(10,2) DEFAULT NULL,
        `profit` decimal(10,2) DEFAULT NULL,
        `margin` decimal(5,2) DEFAULT NULL,
        `is_active` tinyint(1) DEFAULT 1,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`overrideID`),
        UNIQUE KEY `unique_product_size` (`product_name`, `size`),
        KEY `idx_product_name` (`product_name`),
        KEY `idx_category` (`category_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    if ($conn->query($sql3)) {
        echo "✓ Created table: product_cost_overrides\n";
    } else {
        throw new Exception("Failed to create product_cost_overrides table: " . $conn->error);
    }
    */
    
    echo "\n";
    
    // ============================================
    // STEP 2: Migrate inventory_manual_usage.json (DEPRECATED - no longer used)
    // ============================================
    // This migration step is no longer needed. Data should be migrated directly to inventory table.
    // Use migrate_inventory_columns.php if you need to migrate from inventory_usage_overrides table.
    // Commented out to prevent migration to deprecated table.
    /*
    echo "Step 2: Migrating inventory_manual_usage.json...\n";
    $inventoryUsageFile = __DIR__ . '/../config/inventory_manual_usage.json';
    $migratedCount = 0;
    
    if (file_exists($inventoryUsageFile)) {
        $data = json_decode(file_get_contents($inventoryUsageFile), true);
        
        if (is_array($data)) {
            // Handle both formats: {"items": [...]} or {inventoryID: {...}}
            if (isset($data['items']) && is_array($data['items'])) {
                $data = $data['items'];
            }
            
            $stmt = $conn->prepare("
                INSERT INTO inventory_usage_overrides (inventoryID, qty_per_order, cost_per_order)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    qty_per_order = VALUES(qty_per_order),
                    cost_per_order = VALUES(cost_per_order),
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            foreach ($data as $key => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                
                $inventoryID = isset($entry['inventoryID']) ? (int)$entry['inventoryID'] : (int)$key;
                if ($inventoryID <= 0) {
                    continue;
                }
                
                $qtyPerOrder = isset($entry['qty_per_order']) ? trim($entry['qty_per_order']) : null;
                $costPerOrder = isset($entry['cost_per_order']) && $entry['cost_per_order'] !== '' 
                    ? (float)$entry['cost_per_order'] 
                    : null;
                
                // Skip if both are empty
                if (empty($qtyPerOrder) && $costPerOrder === null) {
                    continue;
                }
                
                $stmt->bind_param('isd', $inventoryID, $qtyPerOrder, $costPerOrder);
                if ($stmt->execute()) {
                    $migratedCount++;
                }
            }
            
            $stmt->close();
            echo "✓ Migrated $migratedCount inventory usage overrides\n";
        } else {
            echo "⚠ inventory_manual_usage.json is not a valid array, skipping...\n";
        }
    } else {
        echo "⚠ inventory_manual_usage.json not found, skipping...\n";
    }
    */
    
    echo "\n";
    
    // ============================================
    // STEP 3: Migrate production_cost_overrides.json
    // ============================================
    echo "Step 3: Migrating production_cost_overrides.json...\n";
    $productionCostFile = __DIR__ . '/../config/production_cost_overrides.json';
    $migratedCount = 0;
    
    if (file_exists($productionCostFile)) {
        $data = json_decode(file_get_contents($productionCostFile), true);
        
        if (is_array($data)) {
            $stmt = $conn->prepare("
                INSERT INTO production_cost_overrides (productID, inventoryID, needed_per_cup, ingredient_cost, is_removed)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    needed_per_cup = VALUES(needed_per_cup),
                    ingredient_cost = VALUES(ingredient_cost),
                    is_removed = VALUES(is_removed),
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            foreach ($data as $productID => $overrides) {
                $productID = (int)$productID;
                if ($productID <= 0 || !is_array($overrides)) {
                    continue;
                }
                
                foreach ($overrides as $override) {
                    if (!is_array($override) || !isset($override['inventoryID'])) {
                        continue;
                    }
                    
                    $inventoryID = (int)$override['inventoryID'];
                    if ($inventoryID <= 0) {
                        continue;
                    }
                    
                    $neededPerCup = isset($override['neededPerCup']) ? (float)$override['neededPerCup'] : null;
                    $ingredientCost = isset($override['ingredientCost']) ? (float)$override['ingredientCost'] : null;
                    $isRemoved = isset($override['removed']) && $override['removed'] === true ? 1 : 0;
                    
                    $stmt->bind_param('iiddi', $productID, $inventoryID, $neededPerCup, $ingredientCost, $isRemoved);
                    if ($stmt->execute()) {
                        $migratedCount++;
                    }
                }
            }
            
            $stmt->close();
            echo "✓ Migrated $migratedCount production cost overrides\n";
        } else {
            echo "⚠ production_cost_overrides.json is not a valid array, skipping...\n";
        }
    } else {
        echo "⚠ production_cost_overrides.json not found, skipping...\n";
    }
    
    echo "\n";
    
    // ============================================
    // STEP 4: Migrate product_manual_costs.json (DEPRECATED - no longer used)
    // ============================================
    // This migration step is no longer needed. Production costs are now calculated from recipes.
    // Commented out to prevent migration of unused data.
    /*
    echo "Step 4: Migrating product_manual_costs.json...\n";
    $productCostFile = __DIR__ . '/../config/product_manual_costs.json';
    $migratedCount = 0;
    
    if (file_exists($productCostFile)) {
        $data = json_decode(file_get_contents($productCostFile), true);
        
        if (is_array($data)) {
            $stmt = $conn->prepare("
                INSERT INTO product_cost_overrides (product_name, category_name, size, total_cost, selling_price, profit, margin)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    category_name = VALUES(category_name),
                    total_cost = VALUES(total_cost),
                    selling_price = VALUES(selling_price),
                    profit = VALUES(profit),
                    margin = VALUES(margin),
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            foreach ($data as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                
                $productName = trim($entry['Product'] ?? '');
                if (empty($productName)) {
                    continue;
                }
                
                $categoryName = trim($entry['Category'] ?? '');
                $size = trim($entry['Size'] ?? '');
                $totalCost = isset($entry['TotalCost']) ? (float)$entry['TotalCost'] : null;
                $sellingPrice = isset($entry['SellingPrice']) ? (float)$entry['SellingPrice'] : null;
                $profit = isset($entry['Profit']) ? (float)$entry['Profit'] : null;
                $margin = isset($entry['Margin']) ? (float)$entry['Margin'] : null;
                
                $stmt->bind_param('sssdddd', $productName, $categoryName, $size, $totalCost, $sellingPrice, $profit, $margin);
                if ($stmt->execute()) {
                    $migratedCount++;
                }
            }
            
            $stmt->close();
            echo "✓ Migrated $migratedCount product cost overrides\n";
        } else {
            echo "⚠ product_manual_costs.json is not a valid array, skipping...\n";
        }
    } else {
        echo "⚠ product_manual_costs.json not found, skipping...\n";
    }
    */
    
    // Commit transaction
    $conn->commit();
    
    echo "\n";
    echo "========================================\n";
    echo "Migration completed successfully! ✓\n";
    echo "========================================\n";
    echo "\n";
    echo "Next steps:\n";
    echo "1. Update PHP files to use database tables instead of JSON files\n";
    echo "2. Test the application thoroughly\n";
    echo "3. Once verified, you can optionally backup and remove JSON files\n";
    echo "\n";
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    echo "\n";
    echo "========================================\n";
    echo "ERROR: Migration failed!\n";
    echo "========================================\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "\n";
    echo "All changes have been rolled back.\n";
    exit(1);
}

$conn->close();
?>

