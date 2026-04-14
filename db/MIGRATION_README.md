# JSON to Database Migration Guide

This migration moves data from JSON configuration files to database tables for better reliability and easier management.

## Overview

The migration script (`migrate_json_to_database.php`) will:

1. **Create 2 new database tables:**
   - `inventory_usage_overrides` - Stores manual inventory usage overrides (DEPRECATED - now in `inventory` table)
   - `production_cost_overrides` - Stores production cost overrides per product/ingredient
   - ~~`product_cost_overrides`~~ - **DEPRECATED** - No longer used (production costs calculated from recipes)

2. **Migrate existing data** from JSON files:
   - `config/inventory_manual_usage.json` → `inventory` table (`qty_per_order` column)
   - `config/production_cost_overrides.json` → `production_cost_overrides`
   - ~~`config/product_manual_costs.json`~~ - **DEPRECATED** - No longer migrated

## How to Run the Migration

### Option 1: Via Web Browser
1. Navigate to: `http://localhost/kapetimplados-main3/db/migrate_json_to_database.php`
2. Check the output for success/error messages

### Option 2: Via Command Line (Recommended)
```bash
cd C:\xampp\htdocs\kapetimplados-main3\db
php migrate_json_to_database.php
```

## What the Migration Does

### ~~Table: `inventory_usage_overrides`~~ (DEPRECATED - REMOVED)
- **Status:** This table has been removed. Data migrated to `inventory` table.
- **Replacement:** The `inventory` table now has a `qty_per_order` column
- **Note:** `cost_per_order` is now always calculated dynamically, not stored

### Table: `production_cost_overrides`
- **Purpose:** Stores production cost overrides for specific product/ingredient combinations
- **Fields:**
  - `productID` - Links to products table
  - `inventoryID` - Links to inventory table
  - `needed_per_cup` - Override for needed amount per cup
  - `ingredient_cost` - Override for ingredient cost
  - `is_removed` - Flag to mark ingredient as removed from recipe
  - `is_active` - Enable/disable override
  - `created_at`, `updated_at` - Timestamps

### ~~Table: `product_cost_overrides`~~ (DEPRECATED - NO LONGER USED)
- **Status:** This table is no longer used by the application
- **Reason:** Production costs are now calculated dynamically from recipes and `production_cost_overrides`
- **Action:** If this table exists in your database, it can be safely deleted
- **Note:** The migration script no longer creates or populates this table

## Safety Features

- **Transaction-based:** All changes are wrapped in a transaction
- **Rollback on error:** If anything fails, all changes are rolled back
- **Duplicate handling:** Uses `ON DUPLICATE KEY UPDATE` to handle existing data
- **Non-destructive:** Original JSON files are NOT deleted or modified

## After Migration

1. **Test the application** to ensure everything works correctly
2. **Update PHP code** to use database tables instead of JSON files (next step)
3. **Optional:** Backup and archive JSON files once verified

## Troubleshooting

### Error: "Table already exists"
- This is normal if you run the migration multiple times
- The script uses `CREATE TABLE IF NOT EXISTS`, so it's safe to re-run

### Error: "Foreign key constraint fails"
- Make sure inventory items and products exist in the database
- The migration will skip invalid references

### No data migrated
- Check that JSON files exist in `config/` directory
- Verify JSON files are valid (not corrupted)
- Check file permissions

## Next Steps

After successful migration, the following files have been updated to use database tables:

1. `db/inventory_get.php` - Uses `qty_per_order` column in `inventory` table
2. `db/inventory_update.php` - Saves to `qty_per_order` column in `inventory` table
3. `db/inventory_add.php` - Saves to `qty_per_order` column in `inventory` table
4. `db/inventory_export.php` - Uses `qty_per_order` column in `inventory` table

**Note:** The `inventory_usage_overrides` table has been removed. All data was migrated to the `inventory` table's `qty_per_order` column.
5. `db/production_cost_get.php` - Load from `production_cost_overrides`
6. `db/production_cost_save.php` - Save to `production_cost_overrides`
7. `db/inventory_costing.php` - Load from both tables
8. `db/production_cost_total.php` - Load from `production_cost_overrides`

## Benefits of Database Approach

✅ **No file permission issues** - Database handles concurrency  
✅ **Better for new installations** - Empty tables work out of the box  
✅ **Transactional updates** - All-or-nothing operations  
✅ **Easier querying** - SQL queries instead of file parsing  
✅ **Foreign key constraints** - Data integrity  
✅ **Audit trail** - Timestamps for tracking changes  
✅ **Scalability** - Better performance with large datasets

