<?php
// system-management.php
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is admin and logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection
include __DIR__ . '/../db.php';

// Check if database connection exists
if (!isset($conn)) {
    die("Database connection not established. Check your db.php file.");
}

// Variables
$success = '';
$error = '';

// Tab management
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'roles';

// Check and create necessary tables
checkAndCreateTables();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle different actions based on which button was pressed
    if (isset($_POST['migrate_roles'])) {
        $result = migrateUserRoles();
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    } elseif (isset($_POST['fix_roles'])) {
        $result = fixUserRoles();
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    } elseif (isset($_POST['update_role'])) {
        $userId = $_POST['user_id'];
        $newRole = $_POST['new_role'];
        $result = updateUserRole($userId, $newRole);
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    } elseif (isset($_POST['sync_roles'])) {
        $result = syncAllUserRoles();
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    } elseif (isset($_POST['sync_roles_to_users'])) {
        $result = syncRolesToUsersTable();
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    } elseif (isset($_POST['create_backup'])) {
        $result = createDatabaseBackup();
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    } elseif (isset($_POST['clear_logs'])) {
        $result = clearSystemLogs();
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    } elseif (isset($_POST['optimize_tables'])) {
        $result = optimizeDatabaseTables();
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    } elseif (isset($_POST['run_maintenance'])) {
        $result = runSystemMaintenance();
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    } elseif (isset($_POST['repair_tables'])) {
        $result = repairDatabaseTables();
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}

// Function to check and create necessary tables
function checkAndCreateTables() {
    global $conn;
    
    // Check if users table has the role column
    $result = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
    if ($result->num_rows == 0) {
        // Add role column to users table if it doesn't exist
        $conn->query("ALTER TABLE users ADD COLUMN role VARCHAR(50) DEFAULT 'trainee'");
    }
    
    // Check for existing roles in users table
    $result = $conn->query("SELECT DISTINCT role FROM users WHERE role IS NOT NULL AND role != ''");
    $existingRoles = [];
    while ($row = $result->fetch_assoc()) {
        $existingRoles[] = strtolower($row['role']);
    }
    
    // Ensure we have the three main roles
    $mainRoles = ['admin', 'trainer', 'trainee'];
    foreach ($mainRoles as $role) {
        if (!in_array($role, $existingRoles)) {
            // Set at least one user as admin if no admin exists
            if ($role == 'admin') {
                $conn->query("UPDATE users SET role = 'admin' WHERE id = 2 LIMIT 1");
            }
        }
    }
}

// NEW FUNCTION: Sync roles from user_roles table to users table
function syncRolesToUsersTable() {
    global $conn;
    
    try {
        $conn->begin_transaction();
        $updatedCount = 0;
        
        // First, get all user_role mappings
        $result = $conn->query("
            SELECT ur.user_id, r.role_name 
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
        ");
        
        while ($row = $result->fetch_assoc()) {
            $userId = $row['user_id'];
            $roleName = $row['role_name'];
            
            // Update users table with the role
            $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->bind_param("si", $roleName, $userId);
            if ($stmt->execute()) {
                $updatedCount++;
            }
            $stmt->close();
        }
        
        // For users without entries in user_roles, set as trainee
        $result = $conn->query("
            UPDATE users u
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            SET u.role = 'trainee'
            WHERE ur.user_id IS NULL AND (u.role IS NULL OR u.role NOT IN ('admin', 'trainer', 'trainee'))
        ");
        
        $updatedCount += $conn->affected_rows;
        
        $conn->commit();
        
        return [
            'success' => true,
            'message' => "Successfully synchronized $updatedCount user roles from user_roles table."
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        return [
            'success' => false,
            'message' => "Sync failed: " . $e->getMessage()
        ];
    }
}

// Function to migrate users to role-based system
function migrateUserRoles() {
    global $conn;
    
    try {
        $conn->begin_transaction();
        
        // Get all users without a role or with invalid role
        $result = $conn->query("
            SELECT u.id, u.email, u.fullname, tr.role as trainee_role
            FROM users u
            LEFT JOIN trainees tr ON u.id = tr.user_id
            WHERE u.role IS NULL OR u.role = '' OR u.role NOT IN ('admin', 'trainer', 'trainee')
        ");
        
        $migratedCount = 0;
        
        while ($user = $result->fetch_assoc()) {
            $userId = $user['id'];
            $email = strtolower($user['email']);
            $name = $user['fullname'];
            $traineeRole = $user['trainee_role'];
            
            // Determine role based on various factors
            $assignedRole = 'trainee'; // Default role
            
            // Check if user exists in trainees table
            if ($traineeRole && $traineeRole !== 'admin' && $traineeRole !== 'trainer') {
                $assignedRole = 'trainee';
            }
            // Check for admin patterns
            elseif (strpos($email, 'admin') !== false || 
                   stripos($name, 'admin') !== false ||
                   $userId == 2) { // Your existing admin
                $assignedRole = 'admin';
            }
            // Check for trainer patterns
            elseif (strpos($email, 'trainer') !== false || 
                   strpos($email, 'instructor') !== false ||
                   strpos($email, 'teacher') !== false ||
                   stripos($name, 'trainer') !== false ||
                   stripos($name, 'instructor') !== false) {
                $assignedRole = 'trainer';
            }
            
            // Update user role
            $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->bind_param("si", $assignedRole, $userId);
            if ($stmt->execute()) {
                $migratedCount++;
            }
            $stmt->close();
        }
        
        $conn->commit();
        
        return [
            'success' => true,
            'message' => "Successfully migrated $migratedCount users to role-based system."
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        return [
            'success' => false,
            'message' => "Migration failed: " . $e->getMessage()
        ];
    }
}

// Function to fix inconsistent roles
function fixUserRoles() {
    global $conn;
    
    try {
        // Fix users with invalid roles
        $result = $conn->query("
            UPDATE users 
            SET role = 'trainee' 
            WHERE role NOT IN ('admin', 'trainer', 'trainee') 
            OR role IS NULL 
            OR role = ''
        ");
        
        $fixedCount = $conn->affected_rows;
        
        // Ensure at least one admin exists (your admin is id=2)
        $adminCheck = $conn->query("SELECT COUNT(*) as admin_count FROM users WHERE role = 'admin'");
        $adminRow = $adminCheck->fetch_assoc();
        
        if ($adminRow['admin_count'] == 0) {
            // Make user id=2 an admin (based on your data)
            $conn->query("UPDATE users SET role = 'admin' WHERE id = 2 LIMIT 1");
            $fixedCount++;
        }
        
        return [
            'success' => true,
            'message' => "Fixed roles for $fixedCount users. Ensured at least one admin exists."
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "Role fix failed: " . $e->getMessage()
        ];
    }
}

// Function to update a specific user's role
function updateUserRole($userId, $newRole) {
    global $conn;
    
    // Validate role
    $validRoles = ['admin', 'trainer', 'trainee'];
    if (!in_array($newRole, $validRoles)) {
        return [
            'success' => false,
            'message' => "Invalid role specified. Must be: admin, trainer, or trainee."
        ];
    }
    
    try {
        // Get user info for logging
        $stmt = $conn->prepare("SELECT fullname, email, role as old_role FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if (!$user) {
            return [
                'success' => false,
                'message' => "User not found."
            ];
        }
        
        // Prevent removing the last admin
        if ($user['old_role'] == 'admin' && $newRole != 'admin') {
            $adminCount = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'")->fetch_assoc()['count'];
            if ($adminCount <= 1) {
                return [
                    'success' => false,
                    'message' => "Cannot remove the last admin user. Please assign another user as admin first."
                ];
            }
        }
        
        // Update the role in users table
        $updateStmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
        $updateStmt->bind_param("si", $newRole, $userId);
        
        if ($updateStmt->execute()) {
            // Also update user_roles table
            $roleId = 0;
            switch ($newRole) {
                case 'admin': $roleId = 1; break;
                case 'trainer': $roleId = 2; break;
                case 'trainee': $roleId = 3; break;
            }
            
            if ($roleId > 0) {
                // Delete existing role assignment
                $conn->query("DELETE FROM user_roles WHERE user_id = $userId");
                // Insert new role assignment
                $conn->query("INSERT INTO user_roles (user_id, role_id) VALUES ($userId, $roleId)");
            }
            
            // Update session if current user is updating themselves
            if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $userId) {
                $_SESSION['role'] = $newRole;
            }
            
            // Log the change
            logActivity('assign_user_role', 
                "Assigned user '{$user['fullname']}' to role '$newRole'"
            );
            
            return [
                'success' => true,
                'message' => "Successfully updated {$user['fullname']}'s role to " . ucfirst($newRole)
            ];
        } else {
            return [
                'success' => false,
                'message' => "Failed to update user role."
            ];
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "Update failed: " . $e->getMessage()
        ];
    }
}

// Function to sync all user roles with session data
function syncAllUserRoles() {
    global $conn;
    
    try {
        // Get all users with their current roles
        $result = $conn->query("SELECT id, role FROM users");
        $syncedCount = 0;
        
        while ($user = $result->fetch_assoc()) {
            // Validate and correct roles if needed
            $validRoles = ['admin', 'trainer', 'trainee'];
            if (!in_array($user['role'], $validRoles)) {
                $stmt = $conn->prepare("UPDATE users SET role = 'trainee' WHERE id = ?");
                $stmt->bind_param("i", $user['id']);
                $stmt->execute();
                $syncedCount++;
            }
        }
        
        return [
            'success' => true,
            'message' => "Synchronized roles for $syncedCount users."
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "Sync failed: " . $e->getMessage()
        ];
    }
}

// DATABASE MAINTENANCE FUNCTIONS

function createDatabaseBackup() {
    global $conn;
    
    try {
        // Get database name from connection
        $database = 'dbs14985503'; // From your SQL dump
        
        // Create backup directory if it doesn't exist
        $backupDir = __DIR__ . '/../backups/';
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0777, true);
        }
        
        // Generate backup filename
        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = $backupDir . "backup_{$timestamp}.sql";
        
        // Get all tables
        $tables = [];
        $result = $conn->query('SHOW TABLES');
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }
        
        $backupContent = "";
        
        // Add header
        $backupContent .= "-- Database Backup\n";
        $backupContent .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $backupContent .= "-- Database: $database\n\n";
        
        foreach ($tables as $table) {
            // Drop table if exists
            $backupContent .= "DROP TABLE IF EXISTS `$table`;\n\n";
            
            // Get create table statement
            $createResult = $conn->query("SHOW CREATE TABLE `$table`");
            $createRow = $createResult->fetch_row();
            $backupContent .= $createRow[1] . ";\n\n";
            
            // Get table data
            $dataResult = $conn->query("SELECT * FROM `$table`");
            if ($dataResult->num_rows > 0) {
                $backupContent .= "--\n-- Dumping data for table `$table`\n--\n\n";
                
                while ($row = $dataResult->fetch_assoc()) {
                    $columns = implode('`, `', array_keys($row));
                    $values = array_map(function($value) use ($conn) {
                        if (is_null($value)) {
                            return 'NULL';
                        }
                        return "'" . $conn->real_escape_string($value) . "'";
                    }, array_values($row));
                    $valuesStr = implode(', ', $values);
                    
                    $backupContent .= "INSERT INTO `$table` (`$columns`) VALUES ($valuesStr);\n";
                }
                $backupContent .= "\n";
            }
        }
        
        // Write backup file
        if (file_put_contents($backupFile, $backupContent)) {
            // Log the backup
            logActivity('backup', "Database backup created: " . basename($backupFile));
            
            return [
                'success' => true,
                'message' => "Database backup created successfully: " . basename($backupFile)
            ];
        } else {
            return [
                'success' => false,
                'message' => "Failed to create backup file."
            ];
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "Backup failed: " . $e->getMessage()
        ];
    }
}

function clearSystemLogs() {
    global $conn;
    
    try {
        // Clear activity logs older than 30 days
        $result = $conn->query("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $deletedRows = $conn->affected_rows;
        
        // Clear old sessions
        $conn->query("DELETE FROM sessions WHERE login_time < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $deletedSessions = $conn->affected_rows;
        
        logActivity('system_maintenance', "Cleared $deletedRows old activity logs and $deletedSessions old sessions");
        
        return [
            'success' => true,
            'message' => "Cleared $deletedRows old activity logs and $deletedSessions old sessions."
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "Failed to clear logs: " . $e->getMessage()
        ];
    }
}

function optimizeDatabaseTables() {
    global $conn;
    
    try {
        $tables = [];
        $result = $conn->query('SHOW TABLES');
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }
        
        $optimizedCount = 0;
        foreach ($tables as $table) {
            $conn->query("OPTIMIZE TABLE `$table`");
            $optimizedCount++;
        }
        
        logActivity('system_maintenance', "Optimized $optimizedCount database tables");
        
        return [
            'success' => true,
            'message' => "Optimized $optimizedCount database tables."
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "Optimization failed: " . $e->getMessage()
        ];
    }
}

function runSystemMaintenance() {
    global $conn;
    
    try {
        $actions = [];
        
        // 1. Update user status for inactive users
        $result = $conn->query("
            UPDATE users 
            SET status = 'Inactive' 
            WHERE date_created < DATE_SUB(NOW(), INTERVAL 90 DAY) 
            AND (last_login IS NULL OR last_login < DATE_SUB(NOW(), INTERVAL 30 DAY))
        ");
        $actions[] = "Updated " . $conn->affected_rows . " inactive users";
        
        // 2. Archive completed enrollments older than 30 days
        $result = $conn->query("
            UPDATE enrollments 
            SET enrollment_status = 'completed' 
            WHERE status = 'Completed' 
            AND completed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND enrollment_status != 'completed'
        ");
        $actions[] = "Archived " . $conn->affected_rows . " old enrollments";
        
        // 3. Clean up expired reset tokens
        $conn->query("DELETE FROM users WHERE reset_expires < NOW() AND reset_token IS NOT NULL");
        $actions[] = "Cleaned up expired reset tokens";
        
        logActivity('system_maintenance', "Ran system maintenance: " . implode(', ', $actions));
        
        return [
            'success' => true,
            'message' => "System maintenance completed: " . implode('; ', $actions)
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "Maintenance failed: " . $e->getMessage()
        ];
    }
}

function repairDatabaseTables() {
    global $conn;
    
    try {
        $tables = [];
        $result = $conn->query('SHOW TABLES');
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }
        
        $repairedCount = 0;
        $repairLog = [];
        
        foreach ($tables as $table) {
            $checkResult = $conn->query("CHECK TABLE `$table`");
            $checkRow = $checkResult->fetch_assoc();
            
            if (strtoupper($checkRow['Msg_type']) == 'ERROR' || 
                strtoupper($checkRow['Msg_text']) == 'TABLE NEEDS REPAIR') {
                $conn->query("REPAIR TABLE `$table`");
                $repairLog[] = $table;
                $repairedCount++;
            }
        }
        
        if ($repairedCount > 0) {
            logActivity('system_maintenance', "Repaired $repairedCount tables: " . implode(', ', $repairLog));
            return [
                'success' => true,
                'message' => "Repaired $repairedCount tables: " . implode(', ', $repairLog)
            ];
        } else {
            return [
                'success' => true,
                'message' => "No tables needed repair. All tables are in good condition."
            ];
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "Repair failed: " . $e->getMessage()
        ];
    }
}

// Function to log activity
function logActivity($type, $description) {
    global $conn;
    
    $userId = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    try {
        // Check if activity_logs table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'activity_logs'");
        if ($tableCheck->num_rows > 0) {
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (user_id, activity_type, description, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issss", $userId, $type, $description, $ip, $userAgent);
            $stmt->execute();
        }
    } catch (Exception $e) {
        // Silently fail logging if table doesn't exist
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

// Get user statistics
function getUserStatistics() {
    global $conn;
    
    $stats = [
        'total_users' => 0,
        'role_counts' => [],
        'role_percentages' => []
    ];
    
    // Get total users
    $result = $conn->query("SELECT COUNT(*) as total FROM users");
    $row = $result->fetch_assoc();
    $stats['total_users'] = $row['total'];
    
    // Get counts by role
    $result = $conn->query("
        SELECT 
            COALESCE(role, 'unassigned') as role,
            COUNT(*) as count
        FROM users 
        GROUP BY COALESCE(role, 'unassigned')
        ORDER BY 
            CASE COALESCE(role, 'unassigned')
                WHEN 'admin' THEN 1
                WHEN 'trainer' THEN 2
                WHEN 'trainee' THEN 3
                ELSE 4
            END
    ");
    
    while ($row = $result->fetch_assoc()) {
        $stats['role_counts'][$row['role']] = $row['count'];
        $stats['role_percentages'][$row['role']] = $stats['total_users'] > 0 
            ? round(($row['count'] / $stats['total_users']) * 100, 1) 
            : 0;
    }
    
    return $stats;
}

// Get all users with their roles
function getAllUsersWithRoles() {
    global $conn;
    
    $users = [];
    $result = $conn->query("
        SELECT u.id, u.fullname, u.email, u.role, 
               DATE_FORMAT(u.date_created, '%Y-%m-%d %H:%i') as created_date,
               ur.role_id, r.role_name as system_role
        FROM users u
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        ORDER BY 
            CASE u.role 
                WHEN 'admin' THEN 1
                WHEN 'trainer' THEN 2
                WHEN 'trainee' THEN 3
                ELSE 4
            END,
            u.fullname
    ");
    
    while ($row = $result->fetch_assoc()) {
        // Use system_role from user_roles if available, otherwise use users.role
        $row['effective_role'] = $row['system_role'] ?: $row['role'];
        $users[] = $row;
    }
    
    return $users;
}

// Get recent role changes
function getRecentRoleChanges() {
    global $conn;
    
    $changes = [];
    
    // Check if activity_logs table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'activity_logs'");
    if ($tableCheck->num_rows > 0) {
        $result = $conn->query("
            SELECT description, created_at
            FROM activity_logs 
            WHERE activity_type = 'assign_user_role'
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        
        while ($row = $result->fetch_assoc()) {
            $changes[] = $row;
        }
    }
    
    return $changes;
}

// Get system statistics for maintenance tab
function getSystemStatistics() {
    global $conn;
    
    $stats = [];
    
    // Get table sizes
    $result = $conn->query("
        SELECT 
            TABLE_NAME,
            ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb,
            TABLE_ROWS as row_count
        FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY size_mb DESC
    ");
    
    $stats['tables'] = [];
    $totalSize = 0;
    while ($row = $result->fetch_assoc()) {
        $stats['tables'][] = $row;
        $totalSize += $row['size_mb'];
    }
    $stats['total_size_mb'] = round($totalSize, 2);
    
    // Get log counts
    $result = $conn->query("SELECT COUNT(*) as count FROM activity_logs");
    $stats['activity_logs'] = $result->fetch_assoc()['count'];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM sessions");
    $stats['sessions'] = $result->fetch_assoc()['count'];
    
    // Get backup files
    $backupDir = __DIR__ . '/../backups/';
    $stats['backup_files'] = [];
    $stats['backup_count'] = 0;
    $stats['backup_total_size'] = 0;
    
    if (file_exists($backupDir)) {
        $files = glob($backupDir . '*.sql');
        $stats['backup_count'] = count($files);
        
        foreach ($files as $file) {
            $size = filesize($file);
            $stats['backup_total_size'] += $size;
            $stats['backup_files'][] = [
                'name' => basename($file),
                'size' => round($size / 1024 / 1024, 2), // MB
                'modified' => date('Y-m-d H:i', filemtime($file))
            ];
        }
        $stats['backup_total_size'] = round($stats['backup_total_size'] / 1024 / 1024, 2); // MB
    }
    
    return $stats;
}

// Get data for display
$userStats = getUserStatistics();
$allUsers = getAllUsersWithRoles();
$recentChanges = getRecentRoleChanges();
$systemStats = getSystemStatistics();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Management Panel</title>
    <style>
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1f2937;
            --light: #f9fafb;
            --admin-color: #8b5cf6;
            --trainer-color: #3b82f6;
            --trainee-color: #10b981;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 20px;
            color: var(--dark);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 30px 40px;
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: rgba(255,255,255,0.1);
            transform: rotate(30deg);
        }
        
        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
            padding: 0 30px;
        }
        
        .tab {
            padding: 15px 25px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            font-size: 15px;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .tab:hover {
            color: var(--primary);
            background: rgba(102, 126, 234, 0.05);
        }
        
        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: rgba(102, 126, 234, 0.1);
        }
        
        /* Main Content */
        .main-content {
            padding: 30px;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        /* Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border: 1px solid #e5e7eb;
            text-align: center;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.admin {
            border-top: 4px solid var(--admin-color);
        }
        
        .stat-card.trainer {
            border-top: 4px solid var(--trainer-color);
        }
        
        .stat-card.trainee {
            border-top: 4px solid var(--trainee-color);
        }
        
        .stat-card.total {
            border-top: 4px solid var(--primary);
        }
        
        .stat-card.system {
            border-top: 4px solid var(--warning);
        }
        
        .stat-card.database {
            border-top: 4px solid var(--success);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 10px 0;
        }
        
        .stat-card.admin .stat-number {
            color: var(--admin-color);
        }
        
        .stat-card.trainer .stat-number {
            color: var(--trainer-color);
        }
        
        .stat-card.trainee .stat-number {
            color: var(--trainee-color);
        }
        
        .stat-card.total .stat-number {
            color: var(--primary);
        }
        
        .stat-card.system .stat-number {
            color: var(--warning);
        }
        
        .stat-card.database .stat-number {
            color: var(--success);
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Action Cards */
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .action-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 25px;
            border: 1px solid #e5e7eb;
        }
        
        .action-card h3 {
            color: var(--dark);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .action-card p {
            color: #6b7280;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        
        /* Buttons */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background: #0da271;
        }
        
        .btn-warning {
            background: var(--warning);
            color: white;
        }
        
        .btn-warning:hover {
            background: #d97706;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background: #dc2626;
        }
        
        /* Forms */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 15px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        select.form-control {
            cursor: pointer;
        }
        
        /* Tables */
        .table-container {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            margin-top: 20px;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        .table th {
            background: #f9fafb;
            padding: 16px 20px;
            text-align: left;
            font-weight: 600;
            color: #4b5563;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .table td {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }
        
        .table tr:hover {
            background: #f9fafb;
        }
        
        /* Role Badges */
        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .role-badge.admin {
            background: rgba(139, 92, 246, 0.1);
            color: var(--admin-color);
            border: 1px solid rgba(139, 92, 246, 0.3);
        }
        
        .role-badge.trainer {
            background: rgba(59, 130, 246, 0.1);
            color: var(--trainer-color);
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .role-badge.trainee {
            background: rgba(16, 185, 129, 0.1);
            color: var(--trainee-color);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .role-badge.unassigned {
            background: rgba(107, 114, 128, 0.1);
            color: #6b7280;
            border: 1px solid rgba(107, 114, 128, 0.3);
        }
        
        /* Recent Activity */
        .activity-list {
            list-style: none;
            margin-top: 20px;
        }
        
        .activity-item {
            padding: 15px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-time {
            font-size: 12px;
            color: #9ca3af;
        }
        
        /* Role Update Form */
        .role-update-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .role-update-form select {
            flex: 1;
            max-width: 150px;
        }
        
        /* System Info */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e5e7eb;
        }
        
        .info-card h4 {
            color: var(--dark);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .info-list {
            list-style: none;
        }
        
        .info-list li {
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
        }
        
        .info-list li:last-child {
            border-bottom: none;
        }
        
        /* Danger Zone */
        .danger-zone {
            border: 2px solid var(--danger);
            border-radius: 12px;
            padding: 25px;
            margin-top: 30px;
            background: rgba(239, 68, 68, 0.05);
        }
        
        .danger-zone h3 {
            color: var(--danger);
            margin-bottom: 15px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .header {
                padding: 20px;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .tabs {
                padding: 0 15px;
                overflow-x: auto;
            }
            
            .tab {
                padding: 12px 15px;
                white-space: nowrap;
            }
            
            .main-content {
                padding: 20px;
            }
            
            .stats-grid,
            .action-grid,
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .table {
                font-size: 14px;
            }
            
            .role-update-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .role-update-form select {
                max-width: 100%;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-cogs"></i> System Management Panel</h1>
            <p>Administrative controls for role management, database maintenance, and system monitoring</p>
        </div>
        
        <!-- Tabs -->
        <div class="tabs">
            <button class="tab <?php echo $active_tab == 'roles' ? 'active' : ''; ?>" onclick="switchTab('roles')">
                <i class="fas fa-user-shield"></i> Role Management
            </button>
            <button class="tab <?php echo $active_tab == 'maintenance' ? 'active' : ''; ?>" onclick="switchTab('maintenance')">
                <i class="fas fa-tools"></i> System Maintenance
            </button>
            <button class="tab <?php echo $active_tab == 'database' ? 'active' : ''; ?>" onclick="switchTab('database')">
                <i class="fas fa-database"></i> Database Tools
            </button>
            <button class="tab <?php echo $active_tab == 'logs' ? 'active' : ''; ?>" onclick="switchTab('logs')">
                <i class="fas fa-history"></i> Activity Logs
            </button>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Alerts -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <!-- Tab 1: Role Management -->
            <div id="roles-tab" class="tab-content <?php echo $active_tab == 'roles' ? 'active' : ''; ?>">
                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card total">
                        <i class="fas fa-users fa-2x"></i>
                        <div class="stat-number"><?php echo $userStats['total_users']; ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                    
                    <div class="stat-card admin">
                        <i class="fas fa-crown fa-2x"></i>
                        <div class="stat-number"><?php echo $userStats['role_counts']['admin'] ?? 0; ?></div>
                        <div class="stat-label">Administrators</div>
                        <div class="stat-percentage"><?php echo $userStats['role_percentages']['admin'] ?? 0; ?>%</div>
                    </div>
                    
                    <div class="stat-card trainer">
                        <i class="fas fa-chalkboard-teacher fa-2x"></i>
                        <div class="stat-number"><?php echo $userStats['role_counts']['trainer'] ?? 0; ?></div>
                        <div class="stat-label">Trainers</div>
                        <div class="stat-percentage"><?php echo $userStats['role_percentages']['trainer'] ?? 0; ?>%</div>
                    </div>
                    
                    <div class="stat-card trainee">
                        <i class="fas fa-user-graduate fa-2x"></i>
                        <div class="stat-number"><?php echo $userStats['role_counts']['trainee'] ?? 0; ?></div>
                        <div class="stat-label">Trainees</div>
                        <div class="stat-percentage"><?php echo $userStats['role_percentages']['trainee'] ?? 0; ?>%</div>
                    </div>
                </div>
                
                <!-- Action Cards -->
                <div class="action-grid">
                    <div class="action-card">
                        <h3><i class="fas fa-database"></i> Sync with Role Tables</h3>
                        <p>Synchronize roles from user_roles table to users table (your new RBAC system).</p>
                        <form method="POST">
                            <button type="submit" name="sync_roles_to_users" class="btn btn-primary">
                                <i class="fas fa-exchange-alt"></i> Sync User Roles
                            </button>
                        </form>
                    </div>
                    
                    <div class="action-card">
                        <h3><i class="fas fa-sync-alt"></i> Migrate to Role System</h3>
                        <p>Automatically assign roles to users based on their email patterns and existing data.</p>
                        <form method="POST">
                            <button type="submit" name="migrate_roles" class="btn btn-primary">
                                <i class="fas fa-magic"></i> Start Migration
                            </button>
                        </form>
                    </div>
                    
                    <div class="action-card">
                        <h3><i class="fas fa-wrench"></i> Fix Role Issues</h3>
                        <p>Fix users with invalid or missing roles. Ensures at least one admin exists.</p>
                        <form method="POST">
                            <button type="submit" name="fix_roles" class="btn btn-warning">
                                <i class="fas fa-tools"></i> Fix Roles
                            </button>
                        </form>
                    </div>
                    
                    <div class="action-card">
                        <h3><i class="fas fa-sync"></i> Sync All Roles</h3>
                        <p>Synchronize all user roles with the system and validate role assignments.</p>
                        <form method="POST">
                            <button type="submit" name="sync_roles" class="btn btn-success">
                                <i class="fas fa-sync"></i> Sync Now
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- User Management Table -->
                <div class="action-card">
                    <h3><i class="fas fa-users-cog"></i> User Role Management</h3>
                    <p>View and manage roles for all users in the system.</p>
                    
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Current Role</th>
                                    <th>Join Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allUsers as $user): ?>
                                    <tr>
                                        <td><?php echo $user['id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($user['fullname']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td>
                                            <span class="role-badge <?php echo $user['effective_role'] ?: 'unassigned'; ?>">
                                                <?php echo ucfirst($user['effective_role'] ?: 'unassigned'); ?>
                                            </span>
                                            <?php if ($user['system_role'] && $user['system_role'] != $user['role']): ?>
                                                <br>
                                                <small style="color: #666; font-size: 11px;">
                                                    System: <?php echo $user['system_role']; ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $user['created_date']; ?></td>
                                        <td>
                                            <form method="POST" class="role-update-form" onsubmit="return confirm('Change role for <?php echo addslashes($user['fullname']); ?>?')">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <select name="new_role" class="form-control" required>
                                                    <option value="">Select Role</option>
                                                    <option value="admin" <?php echo $user['effective_role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                    <option value="trainer" <?php echo $user['effective_role'] == 'trainer' ? 'selected' : ''; ?>>Trainer</option>
                                                    <option value="trainee" <?php echo $user['effective_role'] == 'trainee' ? 'selected' : ''; ?>>Trainee</option>
                                                </select>
                                                <button type="submit" name="update_role" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-save"></i> Update
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Tab 2: System Maintenance -->
            <div id="maintenance-tab" class="tab-content <?php echo $active_tab == 'maintenance' ? 'active' : ''; ?>">
                <!-- System Statistics -->
                <div class="stats-grid">
                    <div class="stat-card system">
                        <i class="fas fa-database fa-2x"></i>
                        <div class="stat-number"><?php echo count($systemStats['tables']); ?></div>
                        <div class="stat-label">Database Tables</div>
                    </div>
                    
                    <div class="stat-card system">
                        <i class="fas fa-hdd fa-2x"></i>
                        <div class="stat-number"><?php echo $systemStats['total_size_mb']; ?> MB</div>
                        <div class="stat-label">Total Size</div>
                    </div>
                    
                    <div class="stat-card system">
                        <i class="fas fa-history fa-2x"></i>
                        <div class="stat-number"><?php echo $systemStats['activity_logs']; ?></div>
                        <div class="stat-label">Activity Logs</div>
                    </div>
                    
                    <div class="stat-card system">
                        <i class="fas fa-clock fa-2x"></i>
                        <div class="stat-number"><?php echo $systemStats['sessions']; ?></div>
                        <div class="stat-label">Active Sessions</div>
                    </div>
                </div>
                
                <!-- Maintenance Actions -->
                <div class="action-grid">
                    <div class="action-card">
                        <h3><i class="fas fa-broom"></i> Clear System Logs</h3>
                        <p>Remove old activity logs and sessions older than 30 days to free up space.</p>
                        <form method="POST">
                            <button type="submit" name="clear_logs" class="btn btn-warning">
                                <i class="fas fa-trash-alt"></i> Clear Old Logs
                            </button>
                        </form>
                    </div>
                    
                    <div class="action-card">
                        <h3><i class="fas fa-rocket"></i> Optimize Database</h3>
                        <p>Optimize all database tables to improve performance and reclaim unused space.</p>
                        <form method="POST">
                            <button type="submit" name="optimize_tables" class="btn btn-success">
                                <i class="fas fa-tachometer-alt"></i> Optimize Now
                            </button>
                        </form>
                    </div>
                    
                    <div class="action-card">
                        <h3><i class="fas fa-tasks"></i> Run Maintenance Tasks</h3>
                        <p>Execute routine maintenance tasks including status updates and cleanup.</p>
                        <form method="POST">
                            <button type="submit" name="run_maintenance" class="btn btn-primary">
                                <i class="fas fa-play-circle"></i> Run Maintenance
                            </button>
                        </form>
                    </div>
                    
                    <div class="action-card">
                        <h3><i class="fas fa-wrench"></i> Repair Database</h3>
                        <p>Check and repair any corrupted database tables to ensure data integrity.</p>
                        <form method="POST">
                            <button type="submit" name="repair_tables" class="btn btn-danger">
                                <i class="fas fa-medkit"></i> Check & Repair
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Database Table Sizes -->
                <div class="action-card">
                    <h3><i class="fas fa-table"></i> Database Table Sizes</h3>
                    <p>Overview of all database tables and their sizes.</p>
                    
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Table Name</th>
                                    <th>Rows</th>
                                    <th>Size (MB)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($systemStats['tables'] as $table): ?>
                                    <tr>
                                        <td><?php echo $table['TABLE_NAME']; ?></td>
                                        <td><?php echo number_format($table['row_count']); ?></td>
                                        <td><?php echo $table['size_mb']; ?> MB</td>
                                        <td>
                                            <span style="color: var(--success);">
                                                <i class="fas fa-check-circle"></i> Healthy
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Tab 3: Database Tools -->
            <div id="database-tab" class="tab-content <?php echo $active_tab == 'database' ? 'active' : ''; ?>">
                <!-- Database Statistics -->
                <div class="stats-grid">
                    <div class="stat-card database">
                        <i class="fas fa-save fa-2x"></i>
                        <div class="stat-number"><?php echo $systemStats['backup_count']; ?></div>
                        <div class="stat-label">Backup Files</div>
                    </div>
                    
                    <div class="stat-card database">
                        <i class="fas fa-hdd fa-2x"></i>
                        <div class="stat-number"><?php echo $systemStats['backup_total_size']; ?> MB</div>
                        <div class="stat-label">Backup Size</div>
                    </div>
                    
                    <div class="stat-card database">
                        <i class="fas fa-server fa-2x"></i>
                        <div class="stat-number"><?php echo count($systemStats['tables']); ?></div>
                        <div class="stat-label">Database Tables</div>
                    </div>
                    
                    <div class="stat-card database">
                        <i class="fas fa-chart-line fa-2x"></i>
                        <div class="stat-number"><?php echo $systemStats['total_size_mb']; ?> MB</div>
                        <div class="stat-label">Total DB Size</div>
                    </div>
                </div>
                
                <!-- Backup Actions -->
                <div class="action-grid">
                    <div class="action-card">
                        <h3><i class="fas fa-download"></i> Create Database Backup</h3>
                        <p>Create a complete backup of all database tables and data.</p>
                        <form method="POST">
                            <button type="submit" name="create_backup" class="btn btn-primary">
                                <i class="fas fa-save"></i> Create Backup
                            </button>
                        </form>
                    </div>
                    
                    <div class="action-card">
                        <h3><i class="fas fa-list"></i> View Backup Files</h3>
                        <p>View all available backup files and their details.</p>
                        <button class="btn btn-secondary" onclick="showBackupList()">
                            <i class="fas fa-eye"></i> View Backups
                        </button>
                    </div>
                    
                    <div class="action-card">
                        <h3><i class="fas fa-file-export"></i> Export Data</h3>
                        <p>Export specific tables or data in various formats (CSV, JSON, SQL).</p>
                        <button class="btn btn-secondary">
                            <i class="fas fa-file-export"></i> Export Options
                        </button>
                    </div>
                    
                    <div class="action-card">
                        <h3><i class="fas fa-search"></i> Database Diagnostics</h3>
                        <p>Run comprehensive diagnostics on the database for performance issues.</p>
                        <button class="btn btn-warning">
                            <i class="fas fa-stethoscope"></i> Run Diagnostics
                        </button>
                    </div>
                </div>
                
                <!-- Backup Files List -->
                <?php if (!empty($systemStats['backup_files'])): ?>
                <div class="action-card">
                    <h3><i class="fas fa-history"></i> Recent Backups</h3>
                    <p>List of recently created backup files.</p>
                    
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>File Name</th>
                                    <th>Size</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($systemStats['backup_files'] as $backup): ?>
                                    <tr>
                                        <td><?php echo $backup['name']; ?></td>
                                        <td><?php echo $backup['size']; ?> MB</td>
                                        <td><?php echo $backup['modified']; ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="downloadBackup('<?php echo $backup['name']; ?>')">
                                                <i class="fas fa-download"></i> Download
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="deleteBackup('<?php echo $backup['name']; ?>')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Danger Zone -->
                <div class="danger-zone">
                    <h3><i class="fas fa-exclamation-triangle"></i> Danger Zone</h3>
                    <p>These actions are irreversible. Use with extreme caution.</p>
                    
                    <div style="margin-top: 20px; display: flex; gap: 15px; flex-wrap: wrap;">
                        <button class="btn btn-danger" onclick="showResetConfirm()">
                            <i class="fas fa-redo"></i> Reset All User Roles
                        </button>
                        <button class="btn btn-danger" onclick="showTruncateConfirm()">
                            <i class="fas fa-trash"></i> Truncate All Tables
                        </button>
                        <button class="btn btn-danger" onclick="showDropConfirm()">
                            <i class="fas fa-skull-crossbones"></i> Drop Database
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Tab 4: Activity Logs -->
            <div id="logs-tab" class="tab-content <?php echo $active_tab == 'logs' ? 'active' : ''; ?>">
                <!-- Log Statistics -->
                <div class="stats-grid">
                    <div class="stat-card system">
                        <i class="fas fa-history fa-2x"></i>
                        <div class="stat-number"><?php echo $systemStats['activity_logs']; ?></div>
                        <div class="stat-label">Total Logs</div>
                    </div>
                    
                    <div class="stat-card system">
                        <i class="fas fa-user-shield fa-2x"></i>
                        <div class="stat-number"><?php echo count($recentChanges); ?></div>
                        <div class="stat-label">Role Changes</div>
                    </div>
                    
                    <div class="stat-card system">
                        <i class="fas fa-clock fa-2x"></i>
                        <div class="stat-number"><?php echo $systemStats['sessions']; ?></div>
                        <div class="stat-label">Session Records</div>
                    </div>
                    
                    <div class="stat-card system">
                        <i class="fas fa-database fa-2x"></i>
                        <div class="stat-number">30</div>
                        <div class="stat-label">Days Retention</div>
                    </div>
                </div>
                
                <!-- Recent Activity -->
                <?php if (!empty($recentChanges)): ?>
                    <div class="action-card">
                        <h3><i class="fas fa-history"></i> Recent Role Changes</h3>
                        <p>Track recent role updates and modifications.</p>
                        
                        <div class="activity-list">
                            <?php foreach ($recentChanges as $change): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="fas fa-user-edit"></i>
                                    </div>
                                    <div class="activity-content">
                                        <strong><?php echo htmlspecialchars($change['description']); ?></strong>
                                        <div class="activity-time">
                                            <?php echo date('M d, Y H:i', strtotime($change['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- View All Logs -->
                <div class="action-card">
                    <h3><i class="fas fa-search"></i> View Activity Logs</h3>
                    <p>Browse and search through all system activity logs.</p>
                    
                    <div style="margin-top: 20px;">
                        <form method="GET" style="display: flex; gap: 10px; margin-bottom: 20px;">
                            <input type="hidden" name="tab" value="logs">
                            <input type="text" name="search" placeholder="Search logs..." class="form-control" style="flex: 1;">
                            <select name="type" class="form-control" style="width: 200px;">
                                <option value="">All Types</option>
                                <option value="assign_user_role">Role Changes</option>
                                <option value="backup">Backups</option>
                                <option value="login">Logins</option>
                                <option value="system_maintenance">Maintenance</option>
                            </select>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </form>
                        
                        <button class="btn btn-warning" onclick="clearAllLogs()">
                            <i class="fas fa-trash"></i> Clear All Logs
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Tab switching
        function switchTab(tabName) {
            // Update URL without page reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
            
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Activate selected tab
            document.querySelectorAll('.tab').forEach(tab => {
                if (tab.textContent.includes(tabName.charAt(0).toUpperCase() + tabName.slice(1))) {
                    tab.classList.add('active');
                }
            });
        }
        
        // Set initial tab based on URL or default
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab') || 'roles';
            switchTab(tab);
        });
        
        // Prevent duplicate form submissions
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn && !submitBtn.disabled) {
                        submitBtn.disabled = true;
                        const originalHTML = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                        
                        setTimeout(() => {
                            if (submitBtn.disabled) {
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = originalHTML;
                            }
                        }, 10000);
                    }
                });
            });
            
            // Add confirmation for destructive actions
            document.querySelector('form button[name="clear_logs"]')?.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to clear old system logs?')) {
                    e.preventDefault();
                }
            });
            
            document.querySelector('form button[name="optimize_tables"]')?.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to optimize all database tables?')) {
                    e.preventDefault();
                }
            });
            
            document.querySelector('form button[name="repair_tables"]')?.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to check and repair database tables?')) {
                    e.preventDefault();
                }
            });
            
            document.querySelector('form button[name="create_backup"]')?.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to create a database backup?')) {
                    e.preventDefault();
                }
            });
        });
        
        // Backup functions
        function showBackupList() {
            alert('Backup list functionality would be implemented here.');
        }
        
        function downloadBackup(filename) {
            if (confirm('Download backup file: ' + filename + '?')) {
                window.location.href = 'download-backup.php?file=' + encodeURIComponent(filename);
            }
        }
        
        function deleteBackup(filename) {
            if (confirm('Are you sure you want to delete backup: ' + filename + '? This action cannot be undone.')) {
                window.location.href = 'delete-backup.php?file=' + encodeURIComponent(filename);
            }
        }
        
        // Danger zone functions
        function showResetConfirm() {
            if (confirm('WARNING: This will reset ALL user roles to default (trainee). Are you absolutely sure?')) {
                if (confirm('This action is irreversible. Type "RESET" to confirm:')) {
                    const input = prompt('Type RESET to confirm:');
                    if (input === 'RESET') {
                        alert('Reset functionality would be implemented here.');
                    }
                }
            }
        }
        
        function showTruncateConfirm() {
            if (confirm('WARNING: This will delete ALL data from ALL tables. Are you absolutely sure?')) {
                if (confirm('This will permanently delete all data. Type "DELETE ALL" to confirm:')) {
                    const input = prompt('Type DELETE ALL to confirm:');
                    if (input === 'DELETE ALL') {
                        alert('Truncate functionality would be implemented here.');
                    }
                }
            }
        }
        
        function showDropConfirm() {
            if (confirm('WARNING: This will DROP the entire database. Are you absolutely sure?')) {
                if (confirm('This will permanently delete the entire database. Type "DROP DATABASE" to confirm:')) {
                    const input = prompt('Type DROP DATABASE to confirm:');
                    if (input === 'DROP DATABASE') {
                        alert('Drop database functionality would be implemented here.');
                    }
                }
            }
        }
        
        function clearAllLogs() {
            if (confirm('Are you sure you want to clear ALL activity logs?')) {
                window.location.href = 'clear-logs.php?all=true';
            }
        }
        
        // Auto-refresh every 60 seconds
        setInterval(() => {
            location.reload();
        }, 60000);
        
        // Show loading spinner
        function showLoading() {
            document.body.innerHTML += `
                <div style="
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(255,255,255,0.8);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 9999;
                ">
                    <div style="text-align: center;">
                        <i class="fas fa-spinner fa-spin fa-3x" style="color: var(--primary);"></i>
                        <p style="margin-top: 20px; font-weight: 500;">Loading...</p>
                    </div>
                </div>
            `;
        }
    </script>
</body>
</html>