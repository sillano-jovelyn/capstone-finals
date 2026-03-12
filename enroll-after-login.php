<?php
session_start();

// Enable detailed error logging for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
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

$user_id = intval($_SESSION['user_id']);
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
    error_log("ERROR: Database file not found at: " . $db_file);
    header('Location: ' . $DASHBOARD_URL . '?error=system');
    exit();
}

// Include database connection
include $db_file;

// Check if connection was established
if (!isset($conn) || !$conn) {
    error_log("ERROR: Database connection variable not set");
    header('Location: ' . $DASHBOARD_URL . '?error=database');
    exit();
}

// Check connection
if ($conn->connect_error) {
    error_log("ERROR: Database connection failed: " . $conn->connect_error);
    header('Location: ' . $DASHBOARD_URL . '?error=database');
    exit();
}

error_log("Database connected successfully");

try {
    // Start transaction
    if (!$conn->begin_transaction()) {
        throw new Exception("Failed to start transaction: " . $conn->error);
    }
    error_log("Transaction started");
    
    // ================================================
    // CRITICAL VALIDATION: CHECK FOR ANY PENDING OR ACTIVE ENROLLMENTS
    // ================================================
    error_log("VALIDATION: Checking for existing pending or active enrollments...");
    
    // Check if user has ANY pending or active enrollment in ANY program
    $checkActiveEnrollments = $conn->prepare("
        SELECT 
            e.id,
            e.program_id,
            e.enrollment_status,
            e.approval_status,
            p.name as program_name
        FROM enrollments e
        JOIN programs p ON e.program_id = p.id
        WHERE e.user_id = ? 
        AND (
            -- PENDING ENROLLMENTS
            e.enrollment_status = 'pending' 
            OR e.approval_status = 'pending'
            
            -- ACTIVE/APPROVED ENROLLMENTS
            OR (e.enrollment_status = 'approved' AND e.approval_status = 'approved')
            OR e.enrollment_status = 'enrolled'
            OR e.enrollment_status = 'active'
            
            -- ONGOING ENROLLMENTS (not completed or cancelled)
            OR (e.enrollment_status NOT IN ('completed', 'cancelled', 'rejected', 'waiting'))
        )
        ORDER BY e.applied_at DESC
        LIMIT 1
    ");
    
    if (!$checkActiveEnrollments) {
        throw new Exception("Database error: " . $conn->error);
    }
    
    if (!$checkActiveEnrollments->bind_param("i", $user_id)) {
        throw new Exception("Failed to bind parameters: " . $checkActiveEnrollments->error);
    }
    
    if (!$checkActiveEnrollments->execute()) {
        throw new Exception("Failed to execute query: " . $checkActiveEnrollments->error);
    }
    
    $activeResult = $checkActiveEnrollments->get_result();
    
    if ($activeResult->num_rows > 0) {
        // User has an existing pending or active enrollment
        $activeEnrollment = $activeResult->fetch_assoc();
        $existing_program_id = $activeEnrollment['program_id'];
        $existing_program_name = $activeEnrollment['program_name'];
        $existing_status = $activeEnrollment['enrollment_status'];
        $existing_approval = $activeEnrollment['approval_status'];
        
        error_log("BLOCKED: User has existing enrollment - Program: " . $existing_program_name . " (ID: " . $existing_program_id . ")");
        error_log("BLOCKED: Enrollment Status: " . $existing_status . ", Approval: " . $existing_approval);
        
        // Determine the exact status for the error message
        if ($existing_status === 'pending' || $existing_approval === 'pending') {
            throw new Exception("You have a pending enrollment in '" . $existing_program_name . "'. Please wait for approval or complete that program before applying to a new one.");
        } elseif ($existing_status === 'approved' || $existing_status === 'enrolled' || $existing_status === 'active') {
            throw new Exception("You are currently enrolled in '" . $existing_program_name . "'. You must complete this program before applying to another one.");
        } else {
            throw new Exception("You have an ongoing enrollment in '" . $existing_program_name . "'. Please complete that program before applying to a new one.");
        }
    }
    
    error_log("VALIDATION PASSED: No existing pending or active enrollments found");
    
    // STEP 1: CHECK PROGRAM DETAILS
    error_log("Checking program availability...");
    $checkStmt = $conn->prepare("SELECT id, name, status, slotsAvailable FROM programs WHERE id = ?");
    
    if (!$checkStmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    
    if (!$checkStmt->bind_param("i", $program_id)) {
        throw new Exception("Failed to bind parameters: " . $checkStmt->error);
    }
    
    if (!$checkStmt->execute()) {
        throw new Exception("Failed to execute query: " . $checkStmt->error);
    }
    
    $result = $checkStmt->get_result();
    
    if (!$result) {
        throw new Exception("Failed to get result: " . $conn->error);
    }
    
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
    
    // STEP 2: CHECK EXISTING ENROLLMENT FOR THIS SPECIFIC PROGRAM
    // (Only relevant for re-applying to the same program after completion/rejection)
    error_log("Checking for existing enrollment for this specific program...");
    $enrollCheck = $conn->prepare("SELECT id, enrollment_status, approval_status FROM enrollments WHERE user_id = ? AND program_id = ?");
    
    if (!$enrollCheck) {
        throw new Exception("Database error: " . $conn->error);
    }
    
    if (!$enrollCheck->bind_param("ii", $user_id, $program_id)) {
        throw new Exception("Failed to bind parameters: " . $enrollCheck->error);
    }
    
    if (!$enrollCheck->execute()) {
        throw new Exception("Failed to execute query: " . $enrollCheck->error);
    }
    
    $enrollResult = $enrollCheck->get_result();
    
    $enrollment_id = null;
    $needs_new_enrollment = true;
    
    if ($enrollResult->num_rows > 0) {
        // User has a previous enrollment record for this specific program
        $existing = $enrollResult->fetch_assoc();
        $enrollment_id = $existing['id'];
        $enrollment_status = $existing['enrollment_status'] ?? null;
        $approval_status = $existing['approval_status'] ?? null;
        
        error_log("Previous enrollment found for this program - ID: " . $enrollment_id);
        error_log("Previous Status: " . $enrollment_status . ", Approval: " . $approval_status);
        
        // Since we already validated they have no active enrollments,
        // we only need to check if they can re-apply to this same program
        if (($enrollment_status === 'approved' && $approval_status === 'approved') || 
            $enrollment_status === 'enrolled' || $enrollment_status === 'active') {
            // This shouldn't happen due to previous validation, but just in case
            throw new Exception("You are already enrolled in this program");
        } elseif ($enrollment_status === 'pending' || $approval_status === 'pending') {
            // This shouldn't happen due to previous validation, but just in case
            throw new Exception("Your application for this program is already pending");
        } elseif ($enrollment_status === 'rejected' || $enrollment_status === 'waiting' || 
                  $enrollment_status === 'completed' || $enrollment_status === 'cancelled') {
            // Can re-apply to the SAME program if previously rejected, completed, or cancelled
            // Only allowed because they have NO other active enrollments
            error_log("Re-applying to same program (previously " . $enrollment_status . ")");
            
            // Get current date from PHP
            $current_date = date('Y-m-d H:i:s');
            
            // Update the existing enrollment record
            $updateStmt = $conn->prepare("UPDATE enrollments SET enrollment_status = 'pending', approval_status = 'pending', applied_at = ?, enrollment_date = ? WHERE id = ?");
            
            if (!$updateStmt) {
                throw new Exception("Database error: " . $conn->error);
            }
            
            if (!$updateStmt->bind_param("ssi", $current_date, $current_date, $enrollment_id)) {
                throw new Exception("Failed to bind parameters: " . $updateStmt->error);
            }
            
            if (!$updateStmt->execute()) {
                throw new Exception("Failed to update enrollment: " . $updateStmt->error);
            }
            
            $needs_new_enrollment = false;
            error_log("Updated existing enrollment to pending status");
        }
    }

    
    
    // STEP 3: CREATE NEW ENROLLMENT IF NEEDED
    if ($needs_new_enrollment) {
        error_log("Creating new enrollment record...");
        // Get current date from PHP
        $current_date = date('Y-m-d H:i:s');
        
        // Create new enrollment
        $enrollStmt = $conn->prepare("INSERT INTO enrollments (user_id, program_id, enrollment_status, approval_status, applied_at, enrollment_date) VALUES (?, ?, 'pending', 'pending', ?, ?)");
        


        
        if (!$enrollStmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        
        if (!$enrollStmt->bind_param("iiss", $user_id, $program_id, $current_date, $current_date)) {
            throw new Exception("Failed to bind parameters: " . $enrollStmt->error);
        }
        
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
        
        if (!$slotStmt->bind_param("i", $program_id)) {
            throw new Exception("Failed to bind parameters: " . $slotStmt->error);
        }
        
        if (!$slotStmt->execute()) {
            throw new Exception("Failed to update slots: " . $slotStmt->error);
        }
        
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

   // Insert notification for trainee
    $trainee_notification = "INSERT INTO notifications (user_id, type, title, message, related_id, related_type, created_at) 
                             VALUES (?, 'application', 'Application Submitted', ?, ?, 'enrollment', NOW())";
    $trainee_stmt = $conn->prepare($trainee_notification);
    if ($trainee_stmt) {
        $trainee_message = "Your application for program '" . $program_name . "' has been submitted successfully and is pending approval.";
        $trainee_stmt->bind_param("isi", $user_id, $trainee_message, $enrollment_id);
        $trainee_stmt->execute();
        $trainee_stmt->close();
        error_log("Trainee notification created");
    }
    
    
    // STEP 5: COMMIT TRANSACTION
    if (!$conn->commit()) {
        throw new Exception("Failed to commit transaction: " . $conn->error);
    }
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
    
    // For debugging, show error if debug mode is on
    if (isset($_GET['debug'])) {
        echo "<h1>Error Details</h1>";
        echo "<pre>" . htmlspecialchars($error_message) . "</pre>";
        echo "<p><a href='" . $DASHBOARD_URL . "'>Go to Dashboard</a></p>";
        exit();
    }
    
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