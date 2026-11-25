<?php
/**
 * Test IDNUMBER Setup
 * 
 * Run this script to verify that the idnumber system is working correctly.
 * 
 * Usage: php DB/test_idnumber_setup.php
 */

require_once __DIR__ . '/conn.php';

echo "=== IDNUMBER Setup Test ===\n\n";

$all_passed = true;

// Test 1: Check if idnumber column exists
echo "Test 1: Checking if idnumber column exists in infos table...\n";
try {
    $result = $conn->query("SHOW COLUMNS FROM infos LIKE 'idnumber'");
    if ($result->num_rows > 0) {
        echo "  ✓ PASS: idnumber column exists\n";
    } else {
        echo "  ✗ FAIL: idnumber column not found\n";
        $all_passed = false;
    }
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n";
    $all_passed = false;
}

// Test 2: Check if idnumber has unique constraint
echo "\nTest 2: Checking if idnumber has UNIQUE constraint...\n";
try {
    $result = $conn->query("
        SELECT COUNT(*) as count 
        FROM information_schema.TABLE_CONSTRAINTS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'infos' 
        AND CONSTRAINT_TYPE = 'UNIQUE' 
        AND CONSTRAINT_NAME LIKE '%idnumber%'
    ");
    $row = $result->fetch_assoc();
    if ($row['count'] > 0) {
        echo "  ✓ PASS: UNIQUE constraint exists\n";
    } else {
        echo "  ⚠ WARNING: UNIQUE constraint not found (run migration script)\n";
    }
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n";
    $all_passed = false;
}

// Test 3: Check if idnumber is indexed
echo "\nTest 3: Checking if idnumber is indexed...\n";
try {
    $result = $conn->query("
        SELECT COUNT(*) as count 
        FROM information_schema.STATISTICS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'infos' 
        AND COLUMN_NAME = 'idnumber'
    ");
    $row = $result->fetch_assoc();
    if ($row['count'] > 0) {
        echo "  ✓ PASS: Index exists on idnumber\n";
    } else {
        echo "  ⚠ WARNING: Index not found (run migration script)\n";
    }
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n";
    $all_passed = false;
}

// Test 4: Check if all users have idnumber
echo "\nTest 4: Checking if all users have idnumber values...\n";
try {
    $result = $conn->query("SELECT COUNT(*) as count FROM infos WHERE idnumber IS NULL OR idnumber = ''");
    $row = $result->fetch_assoc();
    if ($row['count'] == 0) {
        echo "  ✓ PASS: All users have idnumber\n";
    } else {
        echo "  ✗ FAIL: " . $row['count'] . " users missing idnumber\n";
        $all_passed = false;
    }
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n";
    $all_passed = false;
}

// Test 5: Check if helper functions file exists
echo "\nTest 5: Checking if helper functions file exists...\n";
if (file_exists(__DIR__ . '/idnumber_helpers.php')) {
    echo "  ✓ PASS: Helper functions file exists\n";
    
    // Test 6: Check if helper functions work
    echo "\nTest 6: Testing helper functions...\n";
    require_once __DIR__ . '/idnumber_helpers.php';
    
    // Get a test user
    $test_user = $conn->query("SELECT id, idnumber FROM infos LIMIT 1")->fetch_assoc();
    
    if ($test_user) {
        // Test getUserByIdNumber
        $user = getUserByIdNumber($conn, $test_user['idnumber']);
        if ($user && $user['id'] == $test_user['id']) {
            echo "  ✓ PASS: getUserByIdNumber() works\n";
        } else {
            echo "  ✗ FAIL: getUserByIdNumber() not working\n";
            $all_passed = false;
        }
        
        // Test getIdFromIdNumber
        $id = getIdFromIdNumber($conn, $test_user['idnumber']);
        if ($id == $test_user['id']) {
            echo "  ✓ PASS: getIdFromIdNumber() works\n";
        } else {
            echo "  ✗ FAIL: getIdFromIdNumber() not working\n";
            $all_passed = false;
        }
        
        // Test getIdNumberFromId
        $idnumber = getIdNumberFromId($conn, $test_user['id']);
        if ($idnumber == $test_user['idnumber']) {
            echo "  ✓ PASS: getIdNumberFromId() works\n";
        } else {
            echo "  ✗ FAIL: getIdNumberFromId() not working\n";
            $all_passed = false;
        }
    } else {
        echo "  ⚠ WARNING: No users in database to test\n";
    }
} else {
    echo "  ⚠ WARNING: Helper functions file not found (run migration script)\n";
}

// Test 7: Check activity log tables for idnumber columns
echo "\nTest 7: Checking activity log tables for idnumber columns...\n";
$tables_to_check = [
    'admin_activity_log' => 'user_idnumber',
    'user_activity_logs' => 'user_idnumber',
    'customer_activity_log' => 'customer_idnumber',
    'user_session_activity' => 'user_idnumber'
];

foreach ($tables_to_check as $table => $column) {
    try {
        $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($result && $result->num_rows > 0) {
            echo "  ✓ PASS: $table has $column column\n";
        } else {
            echo "  ⚠ WARNING: $table missing $column column (run migration script)\n";
        }
    } catch (Exception $e) {
        echo "  ⚠ WARNING: Could not check $table (table may not exist)\n";
    }
}

// Test 8: Display sample users
echo "\nTest 8: Sample users with idnumber:\n";
try {
    $result = $conn->query("
        SELECT id, idnumber, username, role 
        FROM infos 
        ORDER BY role, id 
        LIMIT 5
    ");
    
    if ($result->num_rows > 0) {
        echo "  ID  | IDNUMBER        | USERNAME      | ROLE\n";
        echo "  ----|-----------------|---------------|-------------\n";
        while ($row = $result->fetch_assoc()) {
            printf("  %-3d | %-15s | %-13s | %s\n", 
                $row['id'], 
                $row['idnumber'], 
                $row['username'], 
                $row['role']
            );
        }
    } else {
        echo "  No users found in database\n";
    }
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n";
    $all_passed = false;
}

// Summary
echo "\n=== Test Summary ===\n";
if ($all_passed) {
    echo "✓ All critical tests passed!\n";
    echo "\nNext steps:\n";
    echo "1. Test login and check session variables\n";
    echo "2. Update your code to use idnumber\n";
    echo "3. Review QUICK_START_IDNUMBER.md for examples\n";
} else {
    echo "✗ Some tests failed!\n";
    echo "\nRecommended actions:\n";
    echo "1. Run: php DB/migrations/migrate_to_idnumber_identifier.php\n";
    echo "2. Fix any errors shown above\n";
    echo "3. Run this test script again\n";
}

echo "\n";
$conn->close();
