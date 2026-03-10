<?php
// apply_program.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Database connection
$conn = null;
try {
    $db_file = __DIR__ . '/../db.php';
    if (!file_exists($db_file)) {
        throw new Exception("Database configuration file not found");
    }
    include $db_file;
    if (!$conn) throw new Exception("Database connection not established");
} catch (Exception $e) {
    $response = ['success' => false, 'message' => 'Database connection error'];
    if (isset($_POST['ajax']) && $_POST['ajax'] == 1) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    die("Database connection error: " . $e->getMessage());
}

// Check if user is logged in and is trainee
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'trainee') {
    $response = ['success' => false, 'message' => 'Please login to apply'];
    if (isset($_POST['ajax']) && $_POST['ajax'] == 1) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle AJAX request
if (isset($_POST['ajax']) && $_POST['ajax'] == 1) {
    header('Content-Type: application/json');
    
    if (!isset($_POST['program_id']) || empty($_POST['program_id'])) {
        echo json_encode(['success' => false, 'message' => 'Program ID is required']);
        exit();
    }
    
    $program_id = intval($_POST['program_id']);
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // FIRST, let's check what columns actually exist in your enrollments table
        $checkColumns = $conn->query("SHOW COLUMNS FROM enrollments");
        if (!$checkColumns) {
            throw new Exception("Could not read enrollments table structure. Please check if the 'enrollments' table exists.");
        }
        
        $columns = [];
        while ($col = $checkColumns->fetch_assoc()) {
            $columns[] = $col['Field'];
        }
        
        // Log the columns for debugging
        error_log("Enrollments table columns: " . implode(", ", $columns));
        
        // Determine the user ID column name
        $userColumn = null;
        if (in_array('user_id', $columns)) {
            $userColumn = 'user_id';
        } elseif (in_array('trainee_id', $columns)) {
            $userColumn = 'trainee_id';
        } else {
            throw new Exception("No user identifier column found in enrollments table. Expected 'user_id' or 'trainee_id'");
        }
        
        // Determine the status column name
        $statusColumn = null;
        if (in_array('enrollment_status', $columns)) {
            $statusColumn = 'enrollment_status';
        } elseif (in_array('status', $columns)) {
            $statusColumn = 'status';
        } else {
            throw new Exception("No status column found in enrollments table. Expected 'enrollment_status' or 'status'");
        }
        
        // Check if user already has an active program
        $activeCheck = $conn->prepare("SELECT COUNT(*) as count FROM enrollments WHERE $userColumn = ? AND $statusColumn = 'approved'");
        $activeCheck->bind_param("i", $user_id);
        $activeCheck->execute();
        $activeResult = $activeCheck->get_result()->fetch_assoc();
        $activeCheck->close();
        
        if ($activeResult['count'] > 0) {
            throw new Exception("You cannot apply while enrolled in an active program.");
        }
        
        // Check if already applied
        $existingCheck = $conn->prepare("SELECT $statusColumn FROM enrollments WHERE $userColumn = ? AND program_id = ?");
        $existingCheck->bind_param("ii", $user_id, $program_id);
        $existingCheck->execute();
        $existingResult = $existingCheck->get_result();
        
        if ($existingResult->num_rows > 0) {
            $row = $existingResult->fetch_assoc();
            $status = $row[$statusColumn];
            
            if ($status === 'pending') {
                throw new Exception("You already have a pending application for this program.");
            } elseif ($status === 'approved' || $status === 'completed') {
                throw new Exception("You are already enrolled in this program.");
            } elseif ($status === 'rejected') {
                // Allow re-application if rejected
                // You might want to delete the old rejection first
                $deleteStmt = $conn->prepare("DELETE FROM enrollments WHERE $userColumn = ? AND program_id = ? AND $statusColumn = 'rejected'");
                $deleteStmt->bind_param("ii", $user_id, $program_id);
                $deleteStmt->execute();
                $deleteStmt->close();
            }
        }
        $existingCheck->close();
        
        // Check program availability
        $programCheck = $conn->prepare("SELECT id, name, total_slots FROM programs WHERE id = ? AND status = 'active'");
        $programCheck->bind_param("i", $program_id);
        $programCheck->execute();
        $programResult = $programCheck->get_result();
        
        if ($programResult->num_rows === 0) {
            throw new Exception("Program not found or inactive.");
        }
        
        $program = $programResult->fetch_assoc();
        
        // Count enrolled students
        $countCheck = $conn->prepare("SELECT COUNT(*) as enrolled FROM enrollments WHERE program_id = ? AND $statusColumn IN ('approved', 'completed')");
        $countCheck->bind_param("i", $program_id);
        $countCheck->execute();
        $countResult = $countCheck->get_result()->fetch_assoc();
        $enrolled_count = $countResult['enrolled'];
        $countCheck->close();
        
        if ($program['total_slots'] > 0 && $enrolled_count >= $program['total_slots']) {
            throw new Exception("This program is already full.");
        }
        $programCheck->close();
        
        // SIMPLIFIED INSERT - Use a more direct approach
        // Find a date column that might exist
        $dateColumn = null;
        $possibleDateColumns = ['applied_at', 'created_at', 'application_date', 'enrollment_date', 'created'];
        foreach ($possibleDateColumns as $col) {
            if (in_array($col, $columns)) {
                $dateColumn = $col;
                break;
            }
        }
        
        // Build the INSERT query based on what columns we have
        $insertColumns = [];
        $insertPlaceholders = [];
        $params = [];
        $types = "";
        
        // Add program_id
        $insertColumns[] = 'program_id';
        $insertPlaceholders[] = '?';
        $types .= "i";
        $params[] = $program_id;
        
        // Add user column
        $insertColumns[] = $userColumn;
        $insertPlaceholders[] = '?';
        $types .= "i";
        $params[] = $user_id;
        
        // Add status column
        $insertColumns[] = $statusColumn;
        $insertPlaceholders[] = '?';
        $types .= "s";
        $params[] = 'pending';
        
        // Add date column if it exists
        if ($dateColumn) {
            $insertColumns[] = $dateColumn;
            $insertPlaceholders[] = 'NOW()'; // Not a parameter, so no binding needed
        }
        
        // Build and execute the query
        $sql = "INSERT INTO enrollments (" . implode(", ", $insertColumns) . ") VALUES (" . implode(", ", $insertPlaceholders) . ")";
        
        // If we have a date column with NOW(), we need to adjust the placeholders count
        if ($dateColumn) {
            // Remove the last placeholder count from types since NOW() doesn't need binding
            $stmt = $conn->prepare($sql);
            if (count($params) > 0) {
                $stmt->bind_param($types, ...$params);
            }
        } else {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to submit application: " . $stmt->error);
        }
        
        $enrollment_id = $conn->insert_id;
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Application submitted successfully!',
            'enrollment_id' => $enrollment_id
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    $conn->close();
    exit();
}

// If accessed directly without proper data
header("Location: dashboard.php");
exit();
?>