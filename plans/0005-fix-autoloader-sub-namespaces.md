# Plan 0005: Fix autoloader sub-namespaces path validation

> **Status**: ✅ DONE
> **Priority**: `CRITICAL`
> **Target Subsystems**: `includes/autoload.php`
> **Created**: 2026-10-05
> **Completed**: 2026-10-05
> **Verification**: 🟢 Verified — autoloader unit assertions passed cleanly via PHP CLI, directory traversal protections confirmed, and `vendor/bin/phpcs includes/autoload.php` clean.

---

## 1. Problem Statement & Context

When activating Speedy Sensor on WordPress, WordPress triggers the activation hook:
```php
register_activation_hook( __FILE__, array( 'Speedy_Sensor\\Install\\Activator', 'activate' ) );
```
This requires loading `Speedy_Sensor\Install\Activator`.

However, the standalone PSR-4 autoloader in `includes/autoload.php` attempts to prevent directory traversal by validating the relative class name `$relative`:
```php
// A PSR-4 name never contains a dot or a forward slash. Rejecting them
// here stops a malformed class name from traversing out of includes/.
if ( false !== strpos( $relative, '.' ) || false !== strpos( $relative, '/' ) || false !== strpos( $relative, '\\' ) ) {
    return;
}
```
Because of `false !== strpos( $relative, '\\' )`, **every class within a sub-namespace** (such as `Speedy_Sensor\Install\Activator`, `Speedy_Sensor\Admin\*`, `Speedy_Sensor\Db\*`, `Speedy_Sensor\Scanner\*`) was rejected and never required. Only classes directly in the top-level `Speedy_Sensor\` namespace (like `Speedy_Sensor\Plugin`) could load.

During plugin activation, `Speedy_Sensor\Install\Activator` could not be found, throwing a fatal error:
```text
Fatal error: Class "Speedy_Sensor\Install\Activator" not found
```

## 2. Scope

In:
- Modify `includes/autoload.php` to remove the rejection of `\\` while keeping checks for `.` and `/` directory traversal.
- Validate that classes across all sub-namespaces load correctly and directory traversal attempts are rejected.
- Verify coding standards with PHPCS.

Out:
- No changes to other plugin files or logic.

---

## 3. Architecture & Design

### Step 3.1: Modify `includes/autoload.php`
Remove `false !== strpos( $relative, '\\' )` from the validation check. Keep `.` and `/` checks to stop directory traversal out of `includes/`.

---

## 4. Files Touched

- `includes/autoload.php`
- `plans/0005-fix-autoloader-sub-namespaces.md`
- `plans/README.md`

---

## 5. Verification Plan

### 5.1 Automated Tests
1. Class resolution across sub-namespaces using PHP CLI script.
2. Directory traversal rejection test using PHP CLI script.
3. PHPCS lint check on `includes/autoload.php`.

### 5.2 Manual Verification
N/A

### 5.3 Verification Record

```bash
php -r '
define("ABSPATH", true);
define("SPEEDY_SENSOR_DIR", __DIR__ . "/");
require_once "includes/autoload.php";
$classes = [
    "Speedy_Sensor\\Install\\Activator",
    "Speedy_Sensor\\Install\\Uninstaller",
    "Speedy_Sensor\\Plugin",
    "Speedy_Sensor\\Admin\\Actions",
    "Speedy_Sensor\\Db\\Schema",
    "Speedy_Sensor\\Scanner\\ScanRunner"
];
foreach ($classes as $c) {
    assert(class_exists($c), "Failed to load $c");
}
assert(!class_exists("Speedy_Sensor\\..\\something"));
assert(!class_exists("Speedy_Sensor\\foo/bar"));
echo "All autoloader tests passed successfully!\n";
'
# Output:
# All autoloader tests passed successfully!

vendor/bin/phpcs includes/autoload.php
# Output:
# . 1 / 1 (100%)
# Time: 358ms; Memory: 14MB
```

---

## 6. Downstream Dependencies

None. Allows plugin activation and class resolution throughout the plugin.

---

## 7. Outcome & Deviations

### 7.1 What Shipped
- Modified `includes/autoload.php` so PSR-4 sub-namespaces can be loaded properly.
- Security checks against `.` and `/` directory traversal remain intact.

### 7.2 Deviations
None.
