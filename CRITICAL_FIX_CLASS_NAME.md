# CRITICAL FIX: Class Name Must Match Module Name Exactly

**Date**: 2025-11-08
**Issue**: Module validation failure
**Root Cause**: Incorrect class name format

---

## The Problem

PrestaShop modules require the **class name to EXACTLY match the module name**, including:
- Lowercase letters
- Underscores (not CamelCase)

## What Was Wrong

```php
// ❌ WRONG (CamelCase)
class OdooSalesSync extends Module

// ✅ CORRECT (exact match with folder/file name)
class odoo_sales_sync extends Module
```

## PrestaShop Module Naming Rules

For a module named `odoo_sales_sync`:

| Component | Required Value | Our Value | Status |
|-----------|---------------|-----------|--------|
| Folder name | `odoo_sales_sync` | `odoo_sales_sync` | ✅ |
| Main PHP file | `odoo_sales_sync.php` | `odoo_sales_sync.php` | ✅ |
| Class name | `odoo_sales_sync` | ~~`OdooSalesSync`~~ → `odoo_sales_sync` | ✅ FIXED |
| $this->name | `'odoo_sales_sync'` | `'odoo_sales_sync'` | ✅ |

## Proof from Working Module

From `odoo_direct_stock_sync.php`:
```php
class odoo_direct_stock_sync extends Module
{
    public function __construct()
    {
        $this->name = 'odoo_direct_stock_sync';
        // ...
    }
}
```

Notice:
- Class name: `odoo_direct_stock_sync` (lowercase, underscores)
- Matches folder: `odoo_direct_stock_sync/`
- Matches file: `odoo_direct_stock_sync.php`
- Matches $this->name: `'odoo_direct_stock_sync'`

## Why This Matters

PrestaShop's module autoloader expects:
1. Folder name = module identifier
2. Main PHP file = `{module_identifier}.php`
3. Class name = `{module_identifier}` (EXACT match, not CamelCase)
4. $this->name = `'{module_identifier}'`

Any deviation causes: **"El módulo no es válido y no se puede cargar"**

## The Fix

Changed line 33 in `odoo_sales_sync.php`:

**Before**:
```php
class OdooSalesSync extends Module
```

**After**:
```php
class odoo_sales_sync extends Module
```

## Status

✅ **FIXED AND READY FOR INSTALLATION**

All naming now matches exactly as required by PrestaShop.

---

**This was the root cause of all installation failures!**
