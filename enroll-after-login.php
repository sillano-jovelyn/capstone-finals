<?php
session_start();

// Enable detailed error logging for debugging
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Create log directory if it doesn't exist
$log_dir = __DIR__ . '/logs';
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// Set custom error log file
ini_set('error_log', $log_dir . '/enrollment_errors.log');

error_log("================================================");
error_log("[" . date('Y-m-d H:i:s') . "] TRAINEE ENROLLMENT STARTED");

// CONSTANTS - Always redirect to trainee dashboard
$DASHBOARD_URL = 'trainee/dashboard.php';

// 1. CHECK IF USER IS LOGGED IN AS TRAINEE
if (!isset($_SESSION['user_id'])) {
    error_log("ERROR: User not logged in");
    header('Location: login.php?redirect=enroll');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';

// ONLY ALLOW TRAINEES
if (strtolower($user_role) !== 'trainee') {
    error_log("ERROR: User is not a trainee. Role: " . $user_role);
    header('Location: ' . $DASHBOARD_URL . '?error=access_denied');
    exit();
}

error_log("Trainee ID: " . $user_id);

// 2. GET PROGRAM ID
$program_id = null;
$program_name = '';

// Priority: POST > Session > GET
if (isset($_POST['program_id'])) {
    $program_id = intval($_POST['program_id']);
    $program_name = $_POST['program_name'] ?? '';
    error_log("From POST: program_id=" . $program_id);
} elseif (isset($_SESSION['pending_enrollment'])) {
    if (is_array($_SESSION['pending_enrollment'])) {
        $program_id = intval($_SESSION['pending_enrollment']['program_id'] ?? 0);
        $program_name = $_SESSION['pending_enrollment']['program_name'] ?? '';
    } else {
        $program_id = intval($_SESSION['pending_enrollment']);
    }
    error_log("From session: program_id=" . $program_id);
} elseif (isset($_GET['program_id'])) {
    $program_id = intval($_GET['program_id']);
    $program_name = $_GET['program_name'] ?? '';
    error_log("From GET: program_id=" . $program_id);
}

// Validate program_id
if (!$program_id || $program_id <= 0) {
    error_log("ERROR: Invalid program_id");
    header('Location: ' . $DASHBOARD_URL . '?error=no_program');
    exit();
}

error_log("Processing enrollment for program_id: " . $program_id);

// 3. CONNECT TO DATABASE
$db_file = __DIR__ . '/db.php';
if (!file_exists($db_file)) {
    error_log("ERROR: Database file not found");
    header('Location: ' . $DASHBOARD_URL . '?error=system');
    exit();
}

include $db_file;

if (!isset($conn) || !$conn || $conn->connect_error) {
    error_log("ERROR: Database connection failed");
    header('Location: ' . $DASHBOARD_URL . '?error=database');
    exit();
}

error_log("Database connected successfully");

try {
    // Start transaction
    $conn->begin_transaction();
    error_log("Transaction started");
    
    // STEP 1: CHECK PROGRAM DETAILS
    error_log("Checking program availability...");
    $checkStmt = $conn->prepare("SELECT id, name, status, slotsAvailable FROM programs WHERE id = ?");
    
    if (!$checkStmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    
    $checkStmt->bind_param("i", $program_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Program not found");
    }
    
    $program = $result->fetch_assoc();
    $program_name = $program['name'];
    $program_status = $program['status'];
    $slotsAvailable = $program['slotsAvailable'];
    
    error_log("Program: " . $program_name);
    error_log("Status: " . $program_status);
    error_log("Available slots: " . $slotsAvailable);
    
    // Validate program
    if ($program_status !== 'active') {
        throw new Exception("This program is not currently available");
    }
    
    if ($slotsAvailable <= 0) {
        throw new Exception("This program is full. No slots available.");
    }
    
    // STEP 2: CHECK EXISTING ENROLLMENT
    error_log("Checking for existing enrollment...");
    $enrollCheck = $conn->prepare("SELECT id, enrollment_status, approval_status FROM enrollments WHERE user_id = ? AND program_id = ?");
    
    if (!$enrollCheck) {
        throw new Exception("Database error: " . $conn->error);
    }
    
    $enrollCheck->bind_param("ii", $user_id, $program_id);
    $enrollCheck->execute();
    $enrollResult = $enrollCheck->get_result();
    
    $enrollment_id = null;
    $needs_new_enrollment = true;
    
    if ($enrollResult->num_rows > 0) {
        // User already has an enrollment record
        $existing = $enrollResult->fetch_assoc();
        $enrollment_id = $existing['id'];
        $enrollment_status = $existing['enrollment_status'] ?? null;
        $approval_status = $existing['approval_status'] ?? null;
        
        error_log("Existing enrollment found - ID: " . $enrollment_id);
        error_log("Status: " . $enrollment_status . ", Approval: " . $approval_status);
        
        // Check current status
        if (($enrollment_status === 'approved' && $approval_status === 'approved') || 
            $enrollment_status === 'enrolled') {
            // Already enrolled
            throw new Exception("You are already enrolled in this program");
        } elseif ($enrollment_status === 'pending' || $approval_status === 'pending') {
            // Already pending
            throw new Exception("Your application is already pending approval");
        } elseif ($enrollment_status === 'rejected' || $enrollment_status === 'waiting') {
            // Can re-apply
            error_log("Re-applying for previously " . $enrollment_status . " enrollment");
            $updateStmt = $conn->prepare("UPDATE enrollments SET enrollment_status = 'pending', approval_status = 'pending', applied_at = NOW() WHERE id = ?");
            $updateStmt->bind_param("i", $enrollment_id);
            $updateStmt->execute();
            $needs_new_enrollment = false;
        }
    }
    
    // STEP 3: CREATE NEW ENROLLMENT IF NEEDED
    if ($needs_new_enrollment) {
        error_log("Creating new enrollment record...");
        $enrollStmt = $conn->prepare("INSERT INTO enrollments (user_id, program_id, enrollment_status, approval_status, applied_at) VALUES (?, ?, 'pending', 'pending', NOW())");
        
        if (!$enrollStmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        
        $enrollStmt->bind_param("ii", $user_id, $program_id);
        
        if (!$enrollStmt->execute()) {
            throw new Exception("Failed to create enrollment: " . $enrollStmt->error);
        }
        
        $enrollment_id = $enrollStmt->insert_id;
        error_log("New enrollment created with ID: " . $enrollment_id);
        
        // STEP 4: UPDATE AVAILABLE SLOTS
        error_log("Updating available slots...");
        $slotStmt = $conn->prepare("UPDATE programs SET slotsAvailable = slotsAvailable - 1 WHERE id = ? AND slotsAvailable > 0");
        
        if (!$slotStmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        
        $slotStmt->bind_param("i", $program_id);
        $slotStmt->execute();
        
        $affected_rows = $slotStmt->affected_rows;
        error_log("Slots updated - affected rows: " . $affected_rows);
        
        if ($affected_rows === 0) {
            // Double-check if slots are actually 0
            $checkSlots = $conn->prepare("SELECT slotsAvailable FROM programs WHERE id = ?");
            $checkSlots->bind_param("i", $program_id);
            $checkSlots->execute();
            $slotResult = $checkSlots->get_result();
            $slotData = $slotResult->fetch_assoc();
            
            if ($slotData['slotsAvailable'] <= 0) {
                throw new Exception("This program is now full. Please try another program.");
            }
        }
    }
    
    // STEP 5: COMMIT TRANSACTION
    $conn->commit();
    error_log("Transaction committed successfully");
    
    // Clear session data
    unset($_SESSION['pending_enrollment']);
    
    // STEP 6: SUCCESS - REDIRECT TO TRAINEE DASHBOARD
    $success_url = $DASHBOARD_URL . '?tab=enrollments&message=application_submitted&enrollment_id=' . $enrollment_id;
    error_log("SUCCESS! Enrollment ID: " . $enrollment_id);
    error_log("Redirecting to: " . $success_url);
    
    header('Location: ' . $success_url);
    exit();
    
} catch (Exception $e) {
    // ROLLBACK ON ERROR
    if (isset($conn) && $conn) {
        $conn->rollback();
        error_log("Transaction rolled back");
    }
    
    $error_message = $e->getMessage();
    error_log("ENROLLMENT FAILED: " . $error_message);
    
    // REDIRECT TO TRAINEE DASHBOARD WITH ERROR
    $error_url = $DASHBOARD_URL . '?error=enrollment_failed&message=' . urlencode($error_message) . '&program_id=' . $program_id;
    header('Location: ' . $error_url);
    exit();
    
} finally {
    // CLOSE DATABASE CONNECTION
    if (isset($conn) && $conn) {
        $conn->close();
        error_log("Database connection closed");
    }
    
    error_log("Script execution completed");
    error_log("================================================\n\n");
}

exit();
?>