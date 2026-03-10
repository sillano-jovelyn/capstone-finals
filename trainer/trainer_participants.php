<?php
session_start();

// Set timezone to avoid date issues
date_default_timezone_set('Asia/Manila');

// Authentication check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: /login.php?redirectTo=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

if (strtolower($_SESSION['role']) !== 'trainer') {
    header('Location: /login.php');
    exit;
}

// User info
$user_id = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'] ?? 'Trainer';
$role = $_SESSION['role'];

// Database connection
require_once __DIR__ . '/../db.php';

// ============================================
// DATABASE FUNCTIONS - COMPLETELY FIXED
// ============================================

/**
 * Get trainer's assigned program - FIXED WITH CORRECT COUNTING
 */
function getTrainerProgram($conn, $trainer_name) {
    $stmt = $conn->prepare("
        SELECT p.*
        FROM programs p
        WHERE p.trainer = ? AND p.archived = 0
        ORDER BY p.created_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("s", $trainer_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $program = $result->fetch_assoc();
    
    if (!$program) {
        return null;
    }
    
    // Validate and format dates
    $program['scheduleStart'] = date('Y-m-d', strtotime($program['scheduleStart']));
    $program['scheduleEnd'] = date('Y-m-d', strtotime($program['scheduleEnd']));
    
    // FIXED: Count only ongoing trainees (not certified, failed, or dropout)
    // Also exclude trainees who are not officially enrolled (status != 'approved')
    $count_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT e.user_id) as total_trainees
        FROM enrollments e
        WHERE e.program_id = ? 
        AND e.enrollment_status = 'approved'  -- Only officially enrolled
        AND (e.assessment IS NULL 
             OR e.assessment = '' 
             OR e.assessment = 'Not yet graded' 
             OR e.assessment = 'Pending'
             OR e.assessment NOT IN ('Passed', 'Failed')) -- Not certified or failed
        AND e.enrollment_status != 'rejected' -- Not dropout
    ");
    $count_stmt->bind_param("i", $program['id']);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_data = $count_result->fetch_assoc();
    
    $program['total_trainees'] = $count_data['total_trainees'] ?? 0;
    
    return $program;
}

/**
 * Get trainee counts by status - NEW FUNCTION
 */
function getTraineeCountsByStatus($conn, $program_id) {
    $counts = [
        'total' => 0,
        'ongoing' => 0,
        'certified' => 0,
        'failed' => 0,
        'dropout' => 0
    ];
    
    // Total count (all enrollments except rejected)
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT e.user_id) as total
        FROM enrollments e
        WHERE e.program_id = ? 
        AND e.enrollment_status != 'rejected'
    ");
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $counts['total'] = $data['total'] ?? 0;
    
    // Ongoing count (approved, not certified/failed)
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT e.user_id) as ongoing
        FROM enrollments e
        WHERE e.program_id = ? 
        AND e.enrollment_status = 'approved'
        AND (e.assessment IS NULL 
             OR e.assessment = '' 
             OR e.assessment = 'Not yet graded' 
             OR e.assessment = 'Pending'
             OR e.assessment NOT IN ('Passed', 'Failed'))
    ");
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $counts['ongoing'] = $data['ongoing'] ?? 0;
    
    // Certified count
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT e.user_id) as certified
        FROM enrollments e
        WHERE e.program_id = ? 
        AND (e.assessment = 'Passed' OR e.enrollment_status = 'certified')
    ");
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $counts['certified'] = $data['certified'] ?? 0;
    
    // Failed count (failed but not dropout)
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT e.user_id) as failed
        FROM enrollments e
        WHERE e.program_id = ? 
        AND e.assessment = 'Failed'
        AND e.enrollment_status != 'rejected'
    ");
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $counts['failed'] = $data['failed'] ?? 0;
    
    // Dropout count
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT e.user_id) as dropout
        FROM enrollments e
        WHERE e.program_id = ? 
        AND e.enrollment_status = 'rejected'
    ");
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $counts['dropout'] = $data['dropout'] ?? 0;
    
    return $counts;
}

/**
 * Check if all ongoing trainees have marked attendance today
 */
function allOngoingTraineesMarkedAttendanceToday($conn, $program_id) {
    $today = date('Y-m-d');
    
    // Get count of ongoing trainees
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT e.user_id) as total_ongoing
        FROM enrollments e
        WHERE e.program_id = ? 
        AND e.enrollment_status = 'approved'
        AND (e.assessment IS NULL 
             OR e.assessment = '' 
             OR e.assessment = 'Not yet graded' 
             OR e.assessment = 'Pending'
             OR e.assessment NOT IN ('Passed', 'Failed'))
        AND e.enrollment_status != 'rejected'
    ");
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $total_ongoing = $data['total_ongoing'] ?? 0;
    
    if ($total_ongoing == 0) {
        return true; // No ongoing trainees, so technically all are "marked"
    }
    
    // Get count of ongoing trainees who have marked attendance today
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT e.user_id) as marked_ongoing
        FROM enrollments e
        JOIN attendance_records ar ON e.id = ar.enrollment_id
        WHERE e.program_id = ? 
        AND e.enrollment_status = 'approved'
        AND (e.assessment IS NULL 
             OR e.assessment = '' 
             OR e.assessment = 'Not yet graded' 
             OR e.assessment = 'Pending'
             OR e.assessment NOT IN ('Passed', 'Failed'))
        AND e.enrollment_status != 'rejected'
        AND ar.attendance_date = ?
        AND ar.status IN ('present', 'absent')
    ");
    $stmt->bind_param("is", $program_id, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $marked_ongoing = $data['marked_ongoing'] ?? 0;
    
    return $marked_ongoing >= $total_ongoing;
}

/**
 * Calculate program duration in days - FIXED VERSION
 */
function calculateProgramDuration($start_date, $end_date, $duration, $duration_unit) {
    if ($start_date && $end_date) {
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        
        // Add 1 to include both start and end dates
        $interval = $start->diff($end);
        return $interval->days + 1;
    } elseif ($duration && $duration_unit) {
        if ($duration_unit === 'days') return $duration;
        if ($duration_unit === 'weeks') return $duration * 7;
        if ($duration_unit === 'months') return $duration * 30;
        if ($duration_unit === 'years') return $duration * 365;
    }
    return 40;
}

/**
 * Check if today is within program schedule - FIXED VERSION
 */
function isTodayWithinProgramSchedule($scheduleStart, $scheduleEnd) {
    $today = new DateTime('today');
    $startDate = new DateTime($scheduleStart);
    $endDate = new DateTime($scheduleEnd);
    
    // Reset times to compare dates only
    $startDate->setTime(0, 0, 0);
    $endDate->setTime(23, 59, 59);
    
    return ($today >= $startDate && $today <= $endDate);
}

/**
 * Check if program has started - FIXED VERSION
 */
function hasProgramStarted($scheduleStart) {
    $today = new DateTime('today');
    $startDate = new DateTime($scheduleStart);
    $startDate->setTime(0, 0, 0);
    
    return ($today >= $startDate);
}

/**
 * Check if program has ended - FIXED VERSION
 */
function hasProgramEnded($scheduleEnd) {
    $today = new DateTime('today');
    $endDate = new DateTime($scheduleEnd);
    $endDate->setTime(23, 59, 59);
    
    return ($today > $endDate);
}

/**
 * Get current program day - NEW FUNCTION
 */
function getCurrentProgramDay($scheduleStart) {
    $today = new DateTime('today');
    $startDate = new DateTime($scheduleStart);
    $startDate->setTime(0, 0, 0);
    
    if ($today < $startDate) {
        return 0; // Program hasn't started
    }
    
    $interval = $startDate->diff($today);
    return $interval->days + 1; // Add 1 because day 1 is the start date
}

/**
 * Get trainees for trainer's program - UPDATED VERSION with proper counting
 */
function getTraineesByTrainerProgram($conn, $program_id, $status_filter = 'ongoing') {
    $base_query = "
        SELECT 
            e.id as enrollment_id,
            e.applied_at,
            e.enrollment_status,
            e.attendance,
            e.assessment,
            e.failure_notes,
            t.id,
            t.user_id,
            t.fullname,
            t.firstname,
            t.lastname,
            t.contact_number,
            t.email,
            t.barangay,
            t.municipality,
            t.city,
            t.gender,
            t.age,
            t.education,
            t.failure_notes_copy,
            p.name as program_name,
            p.scheduleStart,
            p.scheduleEnd,
            p.duration,
            p.durationUnit,
            (SELECT COUNT(*) FROM attendance_records ar 
             WHERE ar.enrollment_id = e.id AND ar.status = 'present') as days_attended,
            (SELECT status FROM attendance_records ar2 
             WHERE ar2.enrollment_id = e.id AND ar2.attendance_date = CURDATE() 
             LIMIT 1) as today_attendance_status
        FROM enrollments e
        JOIN trainees t ON e.user_id = t.user_id
        JOIN programs p ON e.program_id = p.id
        WHERE e.program_id = ? 
    ";
    
    // Status filter - UPDATED
    if ($status_filter === 'ongoing') {
        $base_query .= " AND e.enrollment_status = 'approved' AND (e.assessment IS NULL OR e.assessment = '' OR e.assessment = 'Not yet graded' OR e.assessment = 'Pending' OR e.assessment NOT IN ('Passed', 'Failed'))";
    } elseif ($status_filter === 'certified') {
        $base_query .= " AND (e.assessment = 'Passed' OR e.enrollment_status = 'certified')";
    } elseif ($status_filter === 'failed') {
        $base_query .= " AND e.assessment = 'Failed' AND e.enrollment_status != 'rejected'";
    } elseif ($status_filter === 'dropout') {
        $base_query .= " AND e.enrollment_status = 'rejected'";
    } else {
        // Default to 'ongoing' - REMOVED 'all' filter
        $base_query .= " AND e.enrollment_status = 'approved' AND (e.assessment IS NULL OR e.assessment = '' OR e.assessment = 'Not yet graded' OR e.assessment = 'Pending' OR e.assessment NOT IN ('Passed', 'Failed'))";
    }
    
    $base_query .= " ORDER BY t.fullname ASC";
    
    $stmt = $conn->prepare($base_query);
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $trainees = $result->fetch_all(MYSQLI_ASSOC);
    
    // Calculate total days for each trainee
    foreach ($trainees as &$trainee) {
        $trainee['total_days'] = calculateProgramDuration(
            $trainee['scheduleStart'], 
            $trainee['scheduleEnd'], 
            $trainee['duration'], 
            $trainee['durationUnit']
        );
    }
    
    return $trainees;
}

/**
 * Mark daily attendance
 */
function markDailyAttendance($conn, $enrollment_id, $date, $status, $marked_by) {
    // Check if trainee is already certified, failed, or dropout
    $check_stmt = $conn->prepare("
        SELECT e.assessment, e.enrollment_status 
        FROM enrollments e
        WHERE e.id = ?
    ");
    $check_stmt->bind_param("i", $enrollment_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $enrollment = $check_result->fetch_assoc();
    
    if ($enrollment && ($enrollment['enrollment_status'] === 'certified' || $enrollment['assessment'] === 'Passed' || $enrollment['assessment'] === 'Failed' || $enrollment['enrollment_status'] === 'rejected')) {
        return false; // Cannot mark attendance for certified, failed, or dropout trainees
    }
    
    // Check if exists
    $check_stmt = $conn->prepare("
        SELECT id FROM attendance_records 
        WHERE enrollment_id = ? AND attendance_date = ?
    ");
    $check_stmt->bind_param("is", $enrollment_id, $date);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Update
        $row = $check_result->fetch_assoc();
        $stmt = $conn->prepare("
            UPDATE attendance_records 
            SET status = ?, marked_by = ?, created_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("ssi", $status, $marked_by, $row['id']);
    } else {
        // Insert
        $stmt = $conn->prepare("
            INSERT INTO attendance_records 
            (enrollment_id, attendance_date, status, marked_by, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("isss", $enrollment_id, $date, $status, $marked_by);
    }
    
    $success = $stmt->execute();
    
    if ($success) {
        recalculateAttendancePercentage($conn, $enrollment_id);
    }
    
    return $success;
}

/**
 * Recalculate attendance percentage
 */
function recalculateAttendancePercentage($conn, $enrollment_id) {
    // Get enrollment details
    $stmt = $conn->prepare("
        SELECT e.*, p.scheduleStart, p.scheduleEnd, p.duration, p.durationUnit
        FROM enrollments e
        JOIN programs p ON e.program_id = p.id
        WHERE e.id = ?
    ");
    $stmt->bind_param("i", $enrollment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $enrollment = $result->fetch_assoc();
    
    if (!$enrollment) return false;
    
    // Calculate total days
    $total_days = calculateProgramDuration(
        $enrollment['scheduleStart'], 
        $enrollment['scheduleEnd'], 
        $enrollment['duration'], 
        $enrollment['durationUnit']
    );
    
    // Get attended days
    $attendance_stmt = $conn->prepare("
        SELECT COUNT(*) as attended_days 
        FROM attendance_records 
        WHERE enrollment_id = ? AND status = 'present'
    ");
    $attendance_stmt->bind_param("i", $enrollment_id);
    $attendance_stmt->execute();
    $attendance_data = $attendance_stmt->get_result()->fetch_assoc();
    $attended_days = $attendance_data['attended_days'] ?? 0;
    
    // Calculate percentage
    $percentage = $total_days > 0 ? ($attended_days / $total_days) * 100 : 0;
    $percentage = min(100, $percentage);
    
    // Update enrollment
    $update_stmt = $conn->prepare("UPDATE enrollments SET attendance = ? WHERE id = ?");
    $update_stmt->bind_param("di", $percentage, $enrollment_id);
    $update_stmt->execute();
    
    return $percentage;
}

/**
 * Get today's attendance for a trainee
 */
function getTodaysAttendance($conn, $enrollment_id) {
    $today = date('Y-m-d');
    $stmt = $conn->prepare("
        SELECT status, marked_by, created_at 
        FROM attendance_records 
        WHERE enrollment_id = ? AND attendance_date = ?
        LIMIT 1
    ");
    $stmt->bind_param("is", $enrollment_id, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Mark all trainees attendance for today
 */
function markAllTraineesAttendanceToday($conn, $program_id, $status, $marked_by, $scheduleStart, $scheduleEnd) {
    $today = date('Y-m-d');
    
    // Check if today is within program schedule
    if (!isTodayWithinProgramSchedule($scheduleStart, $scheduleEnd)) {
        return ['success_count' => 0, 'error_count' => 0, 'message' => 'Cannot mark attendance outside of program schedule'];
    }
    
    // Check if program has started
    if (!hasProgramStarted($scheduleStart)) {
        return ['success_count' => 0, 'error_count' => 0, 'message' => 'Program has not started yet'];
    }
    
    // Get all enrolled trainees (only ongoing, not certified, failed, or dropout)
    $stmt = $conn->prepare("
        SELECT e.id as enrollment_id 
        FROM enrollments e
        WHERE e.program_id = ? AND e.enrollment_status = 'approved'
        AND (e.assessment IS NULL OR e.assessment = '' OR e.assessment = 'Not yet graded' OR e.assessment = 'Pending' OR e.assessment NOT IN ('Passed', 'Failed'))
        AND e.enrollment_status != 'rejected'
    ");
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $enrollments = $result->fetch_all(MYSQLI_ASSOC);
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($enrollments as $enrollment) {
        if (markDailyAttendance($conn, $enrollment['enrollment_id'], $today, $status, $marked_by)) {
            $success_count++;
        } else {
            $error_count++;
        }
    }
    
    return ['success_count' => $success_count, 'error_count' => $error_count];
}

/**
 * Mark all ongoing trainees as Passed
 */
function markAllTraineesPassed($conn, $program_id, $marked_by) {
    // Get all ongoing trainees
    $stmt = $conn->prepare("
        SELECT e.id as enrollment_id, e.user_id
        FROM enrollments e
        WHERE e.program_id = ? AND e.enrollment_status = 'approved'
        AND (e.assessment IS NULL OR e.assessment = '' OR e.assessment = 'Not yet graded' OR e.assessment = 'Pending' OR e.assessment NOT IN ('Passed', 'Failed'))
        AND e.enrollment_status != 'rejected'
    ");
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $enrollments = $result->fetch_all(MYSQLI_ASSOC);
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($enrollments as $enrollment) {
        $success = updateTraineeAssessment($conn, $enrollment['enrollment_id'], 'Passed', null);
        if ($success) {
            $success_count++;
        } else {
            $error_count++;
        }
    }
    
    return ['success_count' => $success_count, 'error_count' => $error_count];
}

/**
 * Get trainee details
 */
function getTraineeDetails($conn, $trainee_user_id, $program_id) {
    $stmt = $conn->prepare("
        SELECT 
            e.id as enrollment_id,
            e.applied_at,
            e.enrollment_status,
            e.attendance,
            e.assessment,
            e.failure_notes,
            t.*,
            p.name as program_name,
            p.scheduleStart,
            p.scheduleEnd,
            p.duration,
            p.durationUnit
        FROM enrollments e
        JOIN trainees t ON e.user_id = t.user_id
        JOIN programs p ON e.program_id = p.id
        WHERE t.user_id = ? AND e.program_id = ?
    ");
    $stmt->bind_param("si", $trainee_user_id, $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// ============================================
// ASSESSMENT FUNCTIONS - WITH FAILURE NOTES COPYING
// ============================================

/**
 * Update trainee assessment - WITH FAILURE NOTES COPYING
 */
function updateTraineeAssessment($conn, $enrollment_id, $assessment, $failure_notes = null) {
    // Get trainee user_id first
    $stmt = $conn->prepare("SELECT user_id FROM enrollments WHERE id = ?");
    $stmt->bind_param("i", $enrollment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $enrollment = $result->fetch_assoc();
    
    if (!$enrollment) {
        return false;
    }
    
    $user_id = $enrollment['user_id'];
    
    // For Passed, set enrollment_status to 'certified'
    // For Failed, keep enrollment_status as 'approved' but mark assessment as 'Failed'
    if ($assessment === 'Passed') {
        $enrollment_status = 'certified';
    } else {
        $enrollment_status = 'approved';
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update enrollment
        if ($failure_notes) {
            $stmt = $conn->prepare("
                UPDATE enrollments 
                SET assessment = ?, enrollment_status = ?, failure_notes = ?
                WHERE id = ?
            ");
            $stmt->bind_param("sssi", $assessment, $enrollment_status, $failure_notes, $enrollment_id);
        } else {
            $stmt = $conn->prepare("
                UPDATE enrollments 
                SET assessment = ?, enrollment_status = ?, failure_notes = NULL
                WHERE id = ?
            ");
            $stmt->bind_param("ssi", $assessment, $enrollment_status, $enrollment_id);
        }
        
        $stmt->execute();
        
        // If assessment is Failed and there are notes, copy them to trainee
        if ($assessment === 'Failed' && $failure_notes) {
            // Get existing notes to append or create new
            $check_stmt = $conn->prepare("SELECT failure_notes_copy FROM trainees WHERE user_id = ?");
            $check_stmt->bind_param("s", $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $trainee = $check_result->fetch_assoc();
            
            $current_date = date('Y-m-d H:i:s');
            
            // Format the notes with date and separator
            $formatted_notes = "=== [" . date('F j, Y', strtotime($current_date)) . "] ===\n" . $failure_notes;
            
            if ($trainee && !empty($trainee['failure_notes_copy'])) {
                // Append to existing notes with separator
                $new_notes = $trainee['failure_notes_copy'] . "\n\n" . $formatted_notes;
            } else {
                // Create new notes
                $new_notes = $formatted_notes;
            }
            
            $update_stmt = $conn->prepare("UPDATE trainees SET failure_notes_copy = ? WHERE user_id = ?");
            $update_stmt->bind_param("ss", $new_notes, $user_id);
            $update_stmt->execute();
        }
        
        // Commit transaction
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        error_log("Error in updateTraineeAssessment: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark trainee as dropout - NEW FUNCTION
 */
function markTraineeAsDropout($conn, $enrollment_id, $dropout_reason, $marked_by) {
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update enrollment status to rejected (dropout)
        $stmt = $conn->prepare("
            UPDATE enrollments 
            SET enrollment_status = 'rejected', 
                failure_notes = ?,
                assessment = 'Failed'
            WHERE id = ?
        ");
        $stmt->bind_param("si", $dropout_reason, $enrollment_id);
        $stmt->execute();
        
        // Get user_id for updating trainee record
        $get_user_stmt = $conn->prepare("SELECT user_id FROM enrollments WHERE id = ?");
        $get_user_stmt->bind_param("i", $enrollment_id);
        $get_user_stmt->execute();
        $result = $get_user_stmt->get_result();
        $enrollment = $result->fetch_assoc();
        
        if ($enrollment) {
            $user_id = $enrollment['user_id'];
            $current_date = date('Y-m-d H:i:s');
            
            // Format the dropout notes with date
            $formatted_notes = "=== [DROPOUT - " . date('F j, Y', strtotime($current_date)) . "] ===\n" . 
                              "Reason: " . $dropout_reason . "\n" . 
                              "Marked by: " . $marked_by;
            
            // Get existing notes to append or create new
            $check_stmt = $conn->prepare("SELECT failure_notes_copy FROM trainees WHERE user_id = ?");
            $check_stmt->bind_param("s", $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $trainee = $check_result->fetch_assoc();
            
            if ($trainee && !empty($trainee['failure_notes_copy'])) {
                // Append to existing notes with separator
                $new_notes = $trainee['failure_notes_copy'] . "\n\n" . $formatted_notes;
            } else {
                // Create new notes
                $new_notes = $formatted_notes;
            }
            
            // Update trainee's failure notes copy
            $update_stmt = $conn->prepare("UPDATE trainees SET failure_notes_copy = ? WHERE user_id = ?");
            $update_stmt->bind_param("ss", $new_notes, $user_id);
            $update_stmt->execute();
        }
        
        // Commit transaction
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        error_log("Error in markTraineeAsDropout: " . $e->getMessage());
        return false;
    }
}

// ============================================
// AJAX HANDLING
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'mark_daily_attendance':
            $enrollment_id = $_POST['enrollment_id'];
            $status = $_POST['status'];
            $today = date('Y-m-d');
            
            // Check if trainee is already certified, failed, or dropout
            $check_stmt = $conn->prepare("
                SELECT e.attendance, e.assessment, e.enrollment_status, p.scheduleStart, p.scheduleEnd 
                FROM enrollments e
                JOIN programs p ON e.program_id = p.id
                WHERE e.id = ?
            ");
            $check_stmt->bind_param("i", $enrollment_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $enrollment = $check_result->fetch_assoc();
            
            if (!$enrollment) {
                echo json_encode(['success' => false, 'message' => 'Enrollment not found']);
                exit;
            }
            
            // Check if trainee is already certified
            if ($enrollment['enrollment_status'] === 'certified' || $enrollment['assessment'] === 'Passed') {
                echo json_encode(['success' => false, 'message' => 'Trainee is already certified. Cannot mark attendance.']);
                exit;
            }
            
            // Check if trainee has failed
            if ($enrollment['assessment'] === 'Failed') {
                echo json_encode(['success' => false, 'message' => 'Trainee has failed. Cannot mark attendance.']);
                exit;
            }
            
            // Check if trainee is dropout
            if ($enrollment['enrollment_status'] === 'rejected') {
                echo json_encode(['success' => false, 'message' => 'Trainee has dropped out. Cannot mark attendance.']);
                exit;
            }
            
            // Check if program has started
            $program_started_check = hasProgramStarted($enrollment['scheduleStart']);
            if (!$program_started_check) {
                echo json_encode(['success' => false, 'message' => 'Program has not started yet. Cannot mark attendance.']);
                exit;
            }
            
            // Check if today is within program schedule
            $within_schedule_check = isTodayWithinProgramSchedule($enrollment['scheduleStart'], $enrollment['scheduleEnd']);
            if (!$within_schedule_check) {
                echo json_encode(['success' => false, 'message' => 'Today is outside of program schedule. Cannot mark attendance.']);
                exit;
            }
            
            // Check if program has ended
            $program_ended_check = hasProgramEnded($enrollment['scheduleEnd']);
            if ($program_ended_check) {
                echo json_encode(['success' => false, 'message' => 'Program has already ended. Cannot mark attendance.']);
                exit;
            }
            
            $success = markDailyAttendance($conn, $enrollment_id, $today, $status, $fullname);
            echo json_encode(['success' => $success, 'message' => $success ? 'Attendance marked successfully' : 'Failed to mark attendance']);
            exit;
            
        case 'get_todays_attendance':
            $enrollment_id = $_POST['enrollment_id'];
            $attendance = getTodaysAttendance($conn, $enrollment_id);
            echo json_encode(['success' => true, 'attendance' => $attendance]);
            exit;
            
        case 'get_trainee_details':
            $trainee_user_id = $_POST['trainee_user_id'];
            $program_id = $_POST['program_id'];
            $trainee = getTraineeDetails($conn, $trainee_user_id, $program_id);
            
            if ($trainee) {
                // Get attendance stats
                $attendance_stmt = $conn->prepare("
                    SELECT 
                        COUNT(*) as total_days,
                        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days
                    FROM attendance_records 
                    WHERE enrollment_id = ?
                ");
                $attendance_stmt->bind_param("i", $trainee['enrollment_id']);
                $attendance_stmt->execute();
                $attendance_stats = $attendance_stmt->get_result()->fetch_assoc();
                $trainee['attendance_stats'] = $attendance_stats;
            }
            
            echo json_encode(['success' => true, 'trainee' => $trainee]);
            exit;
            
        case 'mark_all_present_today':
            $program_id = $_POST['program_id'];
            
            // Get program schedule
            $program_stmt = $conn->prepare("SELECT scheduleStart, scheduleEnd FROM programs WHERE id = ?");
            $program_stmt->bind_param("i", $program_id);
            $program_stmt->execute();
            $program_result = $program_stmt->get_result();
            $program = $program_result->fetch_assoc();
            
            if (!$program) {
                echo json_encode(['success' => false, 'message' => 'Program not found']);
                exit;
            }
            
            $result = markAllTraineesAttendanceToday($conn, $program_id, 'present', $fullname, $program['scheduleStart'], $program['scheduleEnd']);
            
            if (isset($result['message'])) {
                echo json_encode(['success' => false, 'message' => $result['message']]);
                exit;
            }
            
            echo json_encode([
                'success' => true, 
                'success_count' => $result['success_count'], 
                'error_count' => $result['error_count'],
                'message' => "Marked {$result['success_count']} trainees as present. Errors: {$result['error_count']}"
            ]);
            exit;
            
        case 'mark_all_absent_today':
            $program_id = $_POST['program_id'];
            
            // Get program schedule
            $program_stmt = $conn->prepare("SELECT scheduleStart, scheduleEnd FROM programs WHERE id = ?");
            $program_stmt->bind_param("i", $program_id);
            $program_stmt->execute();
            $program_result = $program_stmt->get_result();
            $program = $program_result->fetch_assoc();
            
            if (!$program) {
                echo json_encode(['success' => false, 'message' => 'Program not found']);
                exit;
            }
            
            $result = markAllTraineesAttendanceToday($conn, $program_id, 'absent', $fullname, $program['scheduleStart'], $program['scheduleEnd']);
            
            if (isset($result['message'])) {
                echo json_encode(['success' => false, 'message' => $result['message']]);
                exit;
            }
            
            echo json_encode([
                'success' => true, 
                'success_count' => $result['success_count'], 
                'error_count' => $result['error_count'],
                'message' => "Marked {$result['success_count']} trainees as absent. Errors: {$result['error_count']}"
            ]);
            exit;
            
        // ========== ASSESSMENT AJAX ==========
        case 'update_assessment':
            // Validate required parameters
            if (!isset($_POST['enrollment_id']) || !isset($_POST['assessment'])) {
                echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
                exit;
            }
            
            $enrollment_id = intval($_POST['enrollment_id']);
            $assessment = trim($_POST['assessment']);
            $failure_notes = isset($_POST['failure_notes']) ? trim($_POST['failure_notes']) : null;
            
            // Validate assessment value
            $valid_assessments = ['Passed', 'Failed']; // REMOVED: 'Pending', 'Not yet graded'
            if (!in_array($assessment, $valid_assessments)) {
                echo json_encode(['success' => false, 'message' => 'Invalid assessment value']);
                exit;
            }
            
            // Check if enrollment exists
            $check_stmt = $conn->prepare("SELECT id FROM enrollments WHERE id = ?");
            $check_stmt->bind_param("i", $enrollment_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Enrollment not found']);
                exit;
            }
            
            $success = updateTraineeAssessment($conn, $enrollment_id, $assessment, $failure_notes);
            
            if ($success) {
                // Get updated data
                $check_stmt = $conn->prepare("SELECT enrollment_status, assessment FROM enrollments WHERE id = ?");
                $check_stmt->bind_param("i", $enrollment_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $updated = $check_result->fetch_assoc();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Assessment updated successfully!',
                    'enrollment_status' => $updated['enrollment_status'],
                    'assessment' => $updated['assessment']
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update assessment. Please try again.']);
            }
            exit;
            
        // ========== MARK ALL PASSED AJAX ==========
        case 'mark_all_passed':
            if (!isset($_POST['program_id'])) {
                echo json_encode(['success' => false, 'message' => 'Missing program ID']);
                exit;
            }
            
            $program_id = intval($_POST['program_id']);
            
            // Check if program exists
            $check_stmt = $conn->prepare("SELECT id FROM programs WHERE id = ?");
            $check_stmt->bind_param("i", $program_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Program not found']);
                exit;
            }
            
            $result = markAllTraineesPassed($conn, $program_id, $fullname);
            
            echo json_encode([
                'success' => true, 
                'success_count' => $result['success_count'], 
                'error_count' => $result['error_count'],
                'message' => "Marked {$result['success_count']} trainees as Passed. Errors: {$result['error_count']}"
            ]);
            exit;
            
        // ========== DROPOUT AJAX ==========
        case 'mark_as_dropout':
            // Validate required parameters
            if (!isset($_POST['enrollment_id']) || !isset($_POST['dropout_reason'])) {
                echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
                exit;
            }
            
            $enrollment_id = intval($_POST['enrollment_id']);
            $dropout_reason = trim($_POST['dropout_reason']);
            
            // Check if enrollment exists
            $check_stmt = $conn->prepare("SELECT id FROM enrollments WHERE id = ?");
            $check_stmt->bind_param("i", $enrollment_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Enrollment not found']);
                exit;
            }
            
            // Validate reason length
            if (strlen($dropout_reason) < 10) {
                echo json_encode(['success' => false, 'message' => 'Please provide a detailed reason (at least 10 characters)']);
                exit;
            }
            
            $success = markTraineeAsDropout($conn, $enrollment_id, $dropout_reason, $fullname);
            
            if ($success) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Trainee marked as dropout successfully!',
                    'enrollment_status' => 'rejected',
                    'assessment' => 'Failed'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to mark as dropout. Please try again.']);
            }
            exit;
    }
}

// ============================================
// PAGE DATA
// ============================================

$trainer_program = getTrainerProgram($conn, $fullname);
$program_id = $trainer_program['id'] ?? 0;
$current_filter = isset($_GET['filter']) ? $_GET['filter'] : 'ongoing';
$trainees = $program_id > 0 ? getTraineesByTrainerProgram($conn, $program_id, $current_filter) : [];
$today = date('Y-m-d');

// Get trainee counts by status
$trainee_counts = $program_id > 0 ? getTraineeCountsByStatus($conn, $program_id) : [
    'total' => 0,
    'ongoing' => 0,
    'certified' => 0,
    'failed' => 0,
    'dropout' => 0
];

// Check if all ongoing trainees have marked attendance today
$all_ongoing_marked_attendance = $program_id > 0 ? allOngoingTraineesMarkedAttendanceToday($conn, $program_id) : true;

// Check program schedule status - FIXED
$program_started = false;
$program_ended = false;
$within_schedule = false;
$current_program_day = 0;
$total_program_days = 0;

if ($trainer_program) {
    $program_started = hasProgramStarted($trainer_program['scheduleStart']);
    $program_ended = hasProgramEnded($trainer_program['scheduleEnd']);
    $within_schedule = isTodayWithinProgramSchedule($trainer_program['scheduleStart'], $trainer_program['scheduleEnd']);
    $current_program_day = getCurrentProgramDay($trainer_program['scheduleStart']);
    $total_program_days = calculateProgramDuration(
        $trainer_program['scheduleStart'], 
        $trainer_program['scheduleEnd'], 
        $trainer_program['duration'], 
        $trainer_program['durationUnit']
    );
    
    // Ensure current day doesn't exceed total days
    $current_program_day = min($current_program_day, $total_program_days);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Participant Records - Trainer Dashboard</title>
    
    <!-- External Libraries -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://kit.fontawesome.com/a2d9b6f2c4.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        /* ========== BASE STYLES ========== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f9fafb; color: #333; line-height: 1.6; }
        
        /* ========== HEADER ========== */
        .header { background-color: #344152; color: white; display: flex; align-items: center; justify-content: space-between; padding: 0 24px; height: 60px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header-left { display: flex; align-items: center; gap: 12px; }
        .logo { width: 40px; height: 40px; }
        .system-name { font-weight: 600; font-size: 18px; }
        .header-right { display: flex; align-items: center; gap: 24px; }
        
        /* ========== PROFILE DROPDOWN ========== */
        .profile-container { position: relative; }
        .profile-btn { background: none; border: none; color: white; display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 8px 12px; border-radius: 6px; transition: background-color 0.2s; }
        .profile-btn:hover { background-color: rgba(255,255,255,0.1); }
        .profile-dropdown { display: none; position: absolute; right: 0; top: 100%; margin-top: 8px; width: 192px; background: white; color: black; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 1000; overflow: hidden; }
        .profile-dropdown.show { display: block; }
        .dropdown-item { display: block; padding: 12px 16px; text-decoration: none; color: #333; transition: background-color 0.2s; border: none; background: none; width: 100%; text-align: left; cursor: pointer; }
        .dropdown-item:hover { background-color: #f3f4f6; }
        .logout-btn { color: #dc2626; }
        .logout-btn:hover { background-color: #fee2e2; }
        
        /* ========== LAYOUT ========== */
        .main-container { display: flex; min-height: calc(100vh - 60px); }
        .sidebar { width: 256px; background-color: #344152; min-height: calc(100vh - 60px); padding: 16px; }
        .sidebar-btn { width: 100%; padding: 12px 16px; border-radius: 8px; margin-bottom: 8px; text-align: left; font-weight: 600; transition: all 0.2s; border: none; cursor: pointer; color: white; background-color: #344152; display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .sidebar-btn:hover { background-color: #3d4d62; }
        .sidebar-btn.active { background-color: #4a5568; color: white; }
        .main-content { flex: 1; padding: 32px; background-color: #f9fafb; }
        
        /* ========== CONTENT SECTIONS ========== */
        .page-header { background: white; padding: 20px 24px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 24px; }
        .page-title { font-size: 28px; font-weight: 700; color: #1a1a1a; margin: 0; }
        
        /* ========== PROGRAM STATUS BANNERS ========== */
        .status-banner { 
            padding: 15px 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            text-align: center;
            font-weight: 600;
            font-size: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .status-banner.not-started { 
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }
        .status-banner.in-progress { 
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        .status-banner.ended { 
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
        }
        .status-banner.outside-schedule { 
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        
        /* ========== TODAY'S DATE BANNER ========== */
        .today-banner { 
            background: linear-gradient(135deg, #4A90E2, #357ABD);
            color: white;
            padding: 15px 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            text-align: center;
            font-weight: 600;
            font-size: 18px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        /* ========== FILTERS ========== */
        .filter-container { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 16px 24px; margin-bottom: 24px; display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
        .filter-group { display: flex; gap: 8px; align-items: center; }
        .filter-label { font-weight: 600; color: #555; font-size: 14px; }
        .filter-btn { padding: 10px 20px; border: 2px solid #e0e0e0; background: white; border-radius: 8px; cursor: pointer; font-weight: 500; font-size: 14px; color: #666; transition: all 0.3s ease; }
        .filter-btn:hover { border-color: #4A90E2; color: #4A90E2; }
        .filter-btn.active { background: #4A90E2; color: white; border-color: #4A90E2; }
        
        /* ========== TABLE ========== */
        .table-card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; }
        .table-header { background: #4A90E2; color: white; padding: 16px 24px; font-size: 18px; font-weight: 600; }
        .table-container { overflow-x: auto; max-height: 600px; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f8f9fa; position: sticky; top: 0; z-index: 10; }
        th { padding: 16px; text-align: left; font-weight: 600; color: #333; font-size: 13px; text-transform: uppercase; border-bottom: 2px solid #e0e0e0; }
        td { padding: 16px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #555; }
        tbody tr { transition: background 0.2s; }
        tbody tr:hover { background: #f8f9fa; }
        
        /* ========== STATUS BADGES ========== */
        .status-badge { padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; display: inline-block; }
        .status-ongoing { background: #fff3cd; color: #856404; }
        .status-certified { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .status-dropout { background: #8b5d5d; color: #ffffff; }
        
        /* ========== ASSESSMENT BADGES ========== */
        .assessment-badge { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; }
        .assessment-not-graded { background: #e2e8f0; color: #4a5568; }
        .assessment-passed { background: #d4edda; color: #155724; }
        .assessment-failed { background: #f8d7da; color: #721c24; }
        .assessment-pending { background: #fff3cd; color: #856404; }
        
        /* ========== ATTENDANCE STATUS BADGES ========== */
        .attendance-status { padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; display: inline-block; }
        .attendance-present { background: #d4edda; color: #155724; }
        .attendance-absent { background: #f8d7da; color: #721c24; }
        .attendance-unmarked { background: #e2e8f0; color: #4a5568; }
        
        /* ========== PROGRESS BAR ========== */
        .progress-bar { width: 100%; height: 8px; background: #e0e0e0; border-radius: 10px; overflow: hidden; position: relative; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #4A90E2, #357ABD); border-radius: 10px; transition: width 0.3s; }
        .progress-text { font-size: 12px; color: #666; margin-top: 4px; font-weight: 500; }
        
        /* ========== BUTTONS ========== */
        .btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; transition: all 0.3s; text-decoration: none; font-size: 13px; }
        .btn-primary { background: #4A90E2; color: white; }
        .btn-primary:hover { background: #357ABD; transform: translateY(-2px); }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; transform: translateY(-2px); }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; transform: translateY(-2px); }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-warning:hover { background: #e0a800; transform: translateY(-2px); }
        .btn-outline { background: transparent; border: 1px solid #4A90E2; color: #4A90E2; }
        .btn-outline:hover { background: #4A90E2; color: white; }
        
        /* ========== ATTENDANCE ACTION BUTTONS ========== */
        .attendance-actions { display: flex; gap: 8px; }
        .attendance-btn { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 12px; transition: all 0.2s; }
        .attendance-btn.present { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .attendance-btn.present:hover { background: #c3e6cb; }
        .attendance-btn.present.active { background: #28a745; color: white; border-color: #28a745; }
        .attendance-btn.absent { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .attendance-btn.absent:hover { background: #f5c6cb; }
        .attendance-btn.absent.active { background: #dc3545; color: white; border-color: #dc3545; }
        
        /* ========== DISABLED BUTTONS ========== */
        .attendance-btn:disabled,
        .attendance-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed !important;
            pointer-events: none;
        }
        
        .btn:disabled,
        .btn.disabled {
            opacity: 0.5;
            cursor: not-allowed !important;
            pointer-events: none;
        }
        
        /* ========== MODALS ========== */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
        .modal-content { background: white; padding: 30px; border-radius: 12px; max-width: 600px; width: 100%; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e0e0e0; }
        .modal-header h3 { color: #1a1a1a; font-size: 20px; margin: 0; }
        .close-modal { background: none; border: none; font-size: 24px; cursor: pointer; color: #999; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; }
        
        /* ========== FORM STYLES ========== */
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; margin-bottom: 6px; font-weight: 600; color: #555; }
        .form-select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-family: inherit; font-size: 14px; }
        .form-select:focus { outline: none; border-color: #4A90E2; box-shadow: 0 0 0 2px rgba(74, 144, 226, 0.2); }
        
        /* ========== DETAIL ROW STYLES ========== */
        .detail-row { margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #f0f0f0; }
        .detail-row:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .detail-label { font-weight: 600; color: #555; margin-bottom: 5px; font-size: 14px; }
        .detail-value { color: #333; font-size: 15px; }
        
        /* ========== EMPTY STATE ========== */
        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state i { font-size: 48px; margin-bottom: 16px; color: #ddd; }
        
        /* ========== SUMMARY ========== */
        .attendance-summary { display: flex; gap: 15px; margin-top: 10px; flex-wrap: wrap; }
        .summary-item { padding: 10px 15px; border-radius: 8px; background-color: #f8f9fa; min-width: 120px; text-align: center; }
        .summary-label { font-size: 12px; color: #6b7280; margin-bottom: 5px; }
        .summary-value { font-size: 18px; font-weight: bold; color: #1f2937; }
        
        /* ========== RESPONSIVE ========== */
        @media (max-width: 768px) {
            .main-container { flex-direction: column; }
            .sidebar { width: 100%; min-height: auto; display: flex; overflow-x: auto; }
            .sidebar-btn { flex: 1; margin-bottom: 0; margin-right: 8px; }
            .main-content { padding: 16px; }
            .filter-container { flex-direction: column; align-items: stretch; }
            .filter-group { flex-direction: column; width: 100%; }
            .filter-btn, .dropdown-select { width: 100%; }
            .attendance-summary { flex-direction: column; }
            .summary-item { min-width: 100%; }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-left">
            <img src="/css/logo.png" alt="Logo" class="logo">
            <span class="system-name">Livelihood Enrollments & Monitoring System</span>
        </div>
        <div class="header-right">
            <div class="profile-container">
                <button class="profile-btn" id="profileBtn">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($fullname); ?></span>
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div class="profile-dropdown" id="profileDropdown">
                    <a href="trainer.php" class="dropdown-item">
                        <i class="fas fa-user"></i> View Profile
                    </a>
                    <button class="dropdown-item logout-btn" id="logoutBtn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <a href="/trainer/dashboard" class="sidebar-btn">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="/trainer/trainees" class="sidebar-btn active">
                <i class="fas fa-users"></i> Trainer Participants
            </a>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Program Status Banner -->
            <?php if ($trainer_program): ?>
                <?php if (!$program_started): ?>
                    <div class="status-banner not-started">
                        <i class="fas fa-clock"></i>
                        <span>Program has not started yet. Starts on: <?php 
                            $startDate = new DateTime($trainer_program['scheduleStart']);
                            echo $startDate->format('F j, Y'); 
                        ?></span>
                    </div>
                <?php elseif ($program_ended): ?>
                    <div class="status-banner ended">
                        <i class="fas fa-calendar-times"></i>
                        <span>Program ended on: <?php 
                            $endDate = new DateTime($trainer_program['scheduleEnd']);
                            echo $endDate->format('F j, Y'); 
                        ?></span>
                    </div>
                <?php elseif (!$within_schedule): ?>
                    <div class="status-banner outside-schedule">
                        <i class="fas fa-calendar-day"></i>
                        <span>Today is outside of program schedule (<?php 
                            $startDate = new DateTime($trainer_program['scheduleStart']);
                            $endDate = new DateTime($trainer_program['scheduleEnd']);
                            echo $startDate->format('M j, Y') . ' - ' . $endDate->format('M j, Y'); 
                        ?>)</span>
                    </div>
                <?php else: ?>
                    <div class="status-banner in-progress">
                        <i class="fas fa-calendar-check"></i>
                        <span>Program in progress: Day <?php echo $current_program_day; ?> of <?php echo $total_program_days; ?></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Today's Date Banner -->
            <div class="today-banner">
                <i class="fas fa-calendar-day"></i>
                <span>Today's Attendance: <?php echo date('l, F j, Y'); ?></span>
                <?php if ($program_started && $within_schedule && !$program_ended && $all_ongoing_marked_attendance): ?>
                    <span class="ml-4 px-3 py-1 bg-green-100 text-green-800 text-sm font-semibold rounded-full">
                        <i class="fas fa-check-circle mr-1"></i> All trainees marked for today
                    </span>
                <?php endif; ?>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-users"></i> Trainer Records</h1>
                <?php if ($trainer_program): ?>
                    <div class="attendance-summary">
                        <div class="summary-item">
                            <div class="summary-label">Program</div>
                            <div class="summary-value"><?php echo htmlspecialchars($trainer_program['name']); ?></div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Schedule</div>
                            <div class="summary-value">
                                <?php 
                                $startDate = new DateTime($trainer_program['scheduleStart']);
                                $endDate = new DateTime($trainer_program['scheduleEnd']);
                                echo $startDate->format('M d') . ' - ' . $endDate->format('M d, Y'); 
                                ?>
                            </div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Total Trainees</div>
                            <div class="summary-value" id="totalTraineesCount"><?php echo $trainer_program['total_trainees'] ?? 0; ?></div>
                            <div class="text-xs text-gray-500 mt-1">
                                <?php if ($program_id > 0): ?>
                                    Ongoing: <?php echo $trainee_counts['ongoing']; ?> | 
                                    Certified: <?php echo $trainee_counts['certified']; ?> | 
                                    Failed: <?php echo $trainee_counts['failed']; ?> | 
                                    Dropout: <?php echo $trainee_counts['dropout']; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <div class="filter-container">
                <div class="filter-group">
                    <span class="filter-label">Status:</span>
                    <button class="filter-btn <?php echo $current_filter === 'ongoing' ? 'active' : ''; ?>" onclick="changeFilter('ongoing')">
                        <i class="fas fa-circle"></i> Ongoing (<?php echo $trainee_counts['ongoing']; ?>)
                    </button>
                    <button class="filter-btn <?php echo $current_filter === 'certified' ? 'active' : ''; ?>" onclick="changeFilter('certified')">
                        <i class="fas fa-certificate"></i> Certified (<?php echo $trainee_counts['certified']; ?>)
                    </button>
                    <button class="filter-btn <?php echo $current_filter === 'failed' ? 'active' : ''; ?>" onclick="changeFilter('failed')">
                        <i class="fas fa-times-circle"></i> Failed (<?php echo $trainee_counts['failed']; ?>)
                    </button>
                    <button class="filter-btn <?php echo $current_filter === 'dropout' ? 'active' : ''; ?>" onclick="changeFilter('dropout')">
                        <i class="fas fa-user-times"></i> Dropout (<?php echo $trainee_counts['dropout']; ?>)
                    </button>
                </div>
                
                <div class="filter-group">
                    <span class="filter-label">Quick Actions:</span>
                    <button class="btn btn-success" onclick="markAllTrainees('present')" id="markAllPresentBtn" 
                            <?php echo (!$program_started || $program_ended || !$within_schedule || $all_ongoing_marked_attendance) ? 'disabled' : ''; ?>
                            title="<?php echo $all_ongoing_marked_attendance ? 'All ongoing trainees already marked attendance for today' : ''; ?>">
                        <i class="fas fa-check-circle"></i> Mark All Present
                    </button>
                    <button class="btn btn-danger" onclick="markAllTrainees('absent')" id="markAllAbsentBtn"
                            <?php echo (!$program_started || $program_ended || !$within_schedule || $all_ongoing_marked_attendance) ? 'disabled' : ''; ?>
                            title="<?php echo $all_ongoing_marked_attendance ? 'All ongoing trainees already marked attendance for today' : ''; ?>">
                        <i class="fas fa-times-circle"></i> Mark All Absent
                    </button>
                    <!-- NEW: Mark All Passed Button -->
                    <button class="btn btn-success" onclick="markAllTraineesPassed()" id="markAllPassedBtn"
                            title="Mark all ongoing trainees as Passed">
                        <i class="fas fa-certificate"></i> Mark All Passed
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="table-card">
                <div class="table-header">
                    <i class="fas fa-table"></i> Real-time Attendance - <?php echo date('M d, Y'); ?>
                    <?php if ($trainer_program): ?>
                        <span class="text-sm font-normal ml-2">
                            Program: <?php echo htmlspecialchars($trainer_program['name']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="table-container">
                    <?php if (count($trainees) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Program</th>
                                    <th>Attendance</th>
                                    <th>Days Attended</th>
                                    <th>Assessment</th>
                                    <th>Status</th>
                                    <th>Today's Status</th>
                                    <th>Mark Attendance</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($trainees as $trainee): ?>
                                    <?php
                                    $days_attended = $trainee['days_attended'] ?? 0;
                                    $total_days = $trainee['total_days'] ?? 1;
                                    $percentage = $trainee['attendance'] ?? 0;
                                    
                                    // Status determination - UPDATED
                                    $status = 'ongoing';
                                    $is_certified = false;
                                    $is_dropout = false;
                                    
                                    if ($trainee['enrollment_status'] === 'rejected') {
                                        $status = 'dropout';
                                        $is_dropout = true;
                                    } elseif ($trainee['assessment'] === 'Passed' || $trainee['enrollment_status'] === 'certified') {
                                        $status = 'certified';
                                        $is_certified = true;
                                    } elseif ($trainee['assessment'] === 'Failed') {
                                        $status = 'failed';
                                    }
                                    
                                    // Assessment status
                                    $assessment = $trainee['assessment'] ?? 'Not yet graded';
                                    $assessment_display = $assessment;
                                    $assessment_class = 'assessment-not-graded';
                                    
                                    if ($assessment === 'Passed' || $trainee['enrollment_status'] === 'certified') {
                                        $assessment_class = 'assessment-passed';
                                        $assessment_display = 'Passed';
                                    } elseif ($assessment === 'Failed') {
                                        $assessment_class = 'assessment-failed';
                                        $assessment_display = 'Failed';
                                    } elseif ($assessment === 'Pending') {
                                        $assessment_class = 'assessment-pending';
                                    }
                                    ?>
                                    <tr data-enrollment-id="<?php echo $trainee['enrollment_id']; ?>" id="trainee-row-<?php echo $trainee['enrollment_id']; ?>" 
                                        data-certified="<?php echo $is_certified ? 'true' : 'false'; ?>" 
                                        data-dropout="<?php echo $is_dropout ? 'true' : 'false'; ?>"
                                        data-assessment="<?php echo htmlspecialchars($assessment); ?>" 
                                        data-enrollment-status="<?php echo htmlspecialchars($trainee['enrollment_status']); ?>"
                                        data-attendance-marked="<?php echo ($trainee['today_attendance_status'] === 'present' || $trainee['today_attendance_status'] === 'absent') ? 'true' : 'false'; ?>">
                                        <td><strong><?php echo htmlspecialchars($trainee['fullname']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($trainee['program_name']); ?></td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                                            </div>
                                            <div class="progress-text"><?php echo round($percentage, 1); ?>%</div>
                                        </td>
                                        <td><?php echo $days_attended; ?> / <?php echo $total_days; ?> days</td>
                                        <td>
                                            <span class="assessment-badge <?php echo $assessment_class; ?>" id="assessment-<?php echo $trainee['enrollment_id']; ?>">
                                                <?php echo htmlspecialchars($assessment_display); ?>
                                            </span>
                                            <?php if ($trainee['failure_notes']): ?>
                                                <div class="text-xs text-gray-500 mt-1" style="cursor: pointer;" onclick="showFailureNotes('<?php echo htmlspecialchars(addslashes($trainee['failure_notes'])); ?>')">
                                                    <i class="fas fa-sticky-note"></i> Has notes
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $status; ?>" id="status-badge-<?php echo $trainee['enrollment_id']; ?>">
                                                <?php echo ucfirst($status); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php $today_attendance_status = $trainee['today_attendance_status'] ?? 'unmarked'; ?>
                                            <span class="attendance-status attendance-<?php echo $today_attendance_status; ?>" id="status-<?php echo $trainee['enrollment_id']; ?>">
                                                <?php 
                                                if ($today_attendance_status === 'present') echo '<i class="fas fa-check-circle mr-1"></i> Present';
                                                elseif ($today_attendance_status === 'absent') echo '<i class="fas fa-times-circle mr-1"></i> Absent';
                                                else echo '<i class="fas fa-clock mr-1"></i> Unmarked';
                                                ?>
                                            </span>
                                            <?php if ($today_attendance_status === 'present' || $today_attendance_status === 'absent'): ?>
                                                <div class="text-xs text-gray-500 mt-1">
                                                    <i class="fas fa-user-check"></i> Marked for today
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($program_started && $within_schedule && !$program_ended && !$is_certified && !$is_dropout && $status !== 'failed'): ?>
                                                <div class="attendance-actions">
                                                    <button class="attendance-btn present <?php echo $today_attendance_status === 'present' ? 'active' : ''; ?> <?php echo ($today_attendance_status === 'present' || $today_attendance_status === 'absent' || $is_certified || $is_dropout) ? 'disabled' : ''; ?>" 
                                                            onclick="markAttendance(<?php echo $trainee['enrollment_id']; ?>, 'present')"
                                                            <?php echo ($today_attendance_status === 'present' || $today_attendance_status === 'absent' || $is_certified || $is_dropout) ? 'disabled' : ''; ?>
                                                            title="<?php echo ($today_attendance_status === 'present' || $today_attendance_status === 'absent') ? 'Attendance already marked for today' : ''; ?>">
                                                        <i class="fas fa-check"></i> Present
                                                    </button>
                                                    <button class="attendance-btn absent <?php echo $today_attendance_status === 'absent' ? 'active' : ''; ?> <?php echo ($today_attendance_status === 'present' || $today_attendance_status === 'absent' || $is_certified || $is_dropout) ? 'disabled' : ''; ?>" 
                                                            onclick="markAttendance(<?php echo $trainee['enrollment_id']; ?>, 'absent')"
                                                            <?php echo ($today_attendance_status === 'present' || $today_attendance_status === 'absent' || $is_certified || $is_dropout) ? 'disabled' : ''; ?>
                                                            title="<?php echo ($today_attendance_status === 'present' || $today_attendance_status === 'absent') ? 'Attendance already marked for today' : ''; ?>">
                                                        <i class="fas fa-times"></i> Absent
                                                    </button>
                                                </div>
                                                <?php if ($is_certified): ?>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        <i class="fas fa-info-circle"></i> Certified - No attendance needed
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($is_dropout): ?>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        <i class="fas fa-user-times"></i> Dropout - No attendance needed
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($today_attendance_status === 'present' || $today_attendance_status === 'absent'): ?>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        <i class="fas fa-check-circle"></i> Attendance already marked for today
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($is_certified): ?>
                                                    <div class="text-xs text-gray-500">
                                                        <i class="fas fa-info-circle"></i> Certified - No attendance needed
                                                    </div>
                                                <?php elseif ($is_dropout): ?>
                                                    <div class="text-xs text-gray-500">
                                                        <i class="fas fa-user-times"></i> Dropout - No attendance needed
                                                    </div>
                                                <?php elseif ($status === 'failed'): ?>
                                                    <div class="text-xs text-gray-500">
                                                        <i class="fas fa-times-circle"></i> Failed - No attendance needed
                                                    </div>
                                                <?php elseif (!$program_started): ?>
                                                    <div class="text-xs text-gray-500">
                                                        <i class="fas fa-clock"></i> Program hasn't started
                                                    </div>
                                                <?php elseif ($program_ended): ?>
                                                    <div class="text-xs text-gray-500">
                                                        <i class="fas fa-calendar-times"></i> Program has ended
                                                    </div>
                                                <?php elseif (!$within_schedule): ?>
                                                    <div class="text-xs text-gray-500">
                                                        <i class="fas fa-calendar-day"></i> Outside program schedule
                                                    </div>
                                                <?php elseif ($today_attendance_status === 'present' || $today_attendance_status === 'absent'): ?>
                                                    <div class="text-xs text-gray-500">
                                                        <i class="fas fa-check-circle"></i> Attendance already marked for today
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                                <button class="btn btn-outline" onclick="viewTraineeDetails('<?php echo $trainee['user_id']; ?>', <?php echo $program_id; ?>)">
                                                    <i class="fas fa-info-circle"></i> Details
                                                </button>
                                                <button class="btn btn-warning" onclick="location.href='comprehensive_assessment.php?enrollment_id=<?php echo $trainee['enrollment_id']; ?>&program_id=<?php echo $program_id; ?>'">
    <i class="fas fa-clipboard-check"></i> Comprehensive Assess
</button>
                                              
                                                <!-- DROPOUT BUTTON -->
                                                <button class="btn btn-danger" onclick="markAsDropout(<?php echo $trainee['enrollment_id']; ?>)" 
                                                        <?php echo ($is_dropout || $is_certified || $status === 'failed') ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-user-times"></i> Dropout
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users-slash"></i>
                            <h3>No Participants Found</h3>
                            <p>
                                <?php if (!$trainer_program): ?>
                                    You are not assigned to any program.
                                <?php else: ?>
                                    There are no participants matching the selected filter.
                                    <?php if ($current_filter === 'certified'): ?>
                                        <br><small>No trainees have been certified yet. Mark trainees as "Passed" in the assessment to certify them.</small>
                                    <?php elseif ($current_filter === 'dropout'): ?>
                                        <br><small>No trainees have dropped out yet. Use the "Dropout" button to mark trainees as dropouts.</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- ========== MODALS ========== -->
    
    <!-- Trainee Details Modal -->
    <div id="traineeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user"></i> Trainee Details</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="trainee-details" id="traineeDetailsContent">
                <!-- Details loaded via JS -->
            </div>
        </div>
    </div>

    <script>
        // ========== INITIALIZATION ==========
        document.addEventListener('DOMContentLoaded', function() {
            // Profile dropdown
            const profileBtn = document.getElementById('profileBtn');
            const profileDropdown = document.getElementById('profileDropdown');
            
            profileBtn.addEventListener('click', () => {
                profileDropdown.classList.toggle('show');
            });
            
            // Close dropdown on outside click
            document.addEventListener('click', (e) => {
                if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                    profileDropdown.classList.remove('show');
                }
            });
            
            // Logout
            document.getElementById('logoutBtn').addEventListener('click', async () => {
                const result = await Swal.fire({
                    title: 'Logout',
                    text: 'Are you sure you want to logout?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, logout',
                    cancelButtonText: 'Cancel'
                });
                
                if (result.isConfirmed) {
                    try {
                        await fetch('/logout.php', { method: 'POST', credentials: 'same-origin' });
                        window.location.href = '/login.php';
                    } catch (error) {
                        console.error('Logout error:', error);
                        window.location.href = '/login.php';
                    }
                }
            });
            
            // Disable bulk buttons if program hasn't started, has ended, or all trainees marked attendance
            updateBulkButtons();
        });

        // ========== HELPER FUNCTIONS ==========
        
        function changeFilter(filter) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('filter', filter);
            window.location.href = '?' + urlParams.toString();
        }
        
        function updateBulkButtons() {
            // Check program status from PHP variables
            const programStarted = <?php echo $program_started ? 'true' : 'false'; ?>;
            const programEnded = <?php echo $program_ended ? 'true' : 'false'; ?>;
            const withinSchedule = <?php echo $within_schedule ? 'true' : 'false'; ?>;
            const allOngoingMarked = <?php echo $all_ongoing_marked_attendance ? 'true' : 'false'; ?>;
            
            const markAllPresentBtn = document.getElementById('markAllPresentBtn');
            const markAllAbsentBtn = document.getElementById('markAllAbsentBtn');
            
            if (!programStarted || programEnded || !withinSchedule || allOngoingMarked) {
                markAllPresentBtn.disabled = true;
                markAllPresentBtn.classList.add('disabled');
                markAllAbsentBtn.disabled = true;
                markAllAbsentBtn.classList.add('disabled');
                
                if (allOngoingMarked) {
                    markAllPresentBtn.title = 'All ongoing trainees already marked attendance for today';
                    markAllAbsentBtn.title = 'All ongoing trainees already marked attendance for today';
                }
            }
        }
        
        // ========== REAL-TIME ATTENDANCE FUNCTIONS ==========
        
        async function markAttendance(enrollmentId, status) {
            // Check program status from PHP variables
            const programStarted = <?php echo $program_started ? 'true' : 'false'; ?>;
            const programEnded = <?php echo $program_ended ? 'true' : 'false'; ?>;
            const withinSchedule = <?php echo $within_schedule ? 'true' : 'false'; ?>;
            
            if (!programStarted) {
                await Swal.fire({
                    icon: 'warning',
                    title: 'Program Not Started',
                    text: 'The training program has not started yet. Cannot mark attendance.',
                    timer: 3000,
                    showConfirmButton: false
                });
                return;
            }
            
            if (programEnded) {
                await Swal.fire({
                    icon: 'warning',
                    title: 'Program Ended',
                    text: 'The training program has already ended. Cannot mark attendance.',
                    timer: 3000,
                    showConfirmButton: false
                });
                return;
            }
            
            if (!withinSchedule) {
                await Swal.fire({
                    icon: 'warning',
                    title: 'Outside Schedule',
                    text: 'Today is outside of the program schedule. Cannot mark attendance.',
                    timer: 3000,
                    showConfirmButton: false
                });
                return;
            }
            
            // Check if trainee is already certified, failed, or dropout
            const row = document.getElementById(`trainee-row-${enrollmentId}`);
            const isCertified = row.getAttribute('data-certified') === 'true';
            const isDropout = row.getAttribute('data-dropout') === 'true';
            const assessment = row.getAttribute('data-assessment');
            const enrollmentStatus = row.getAttribute('data-enrollment-status');
            const attendanceMarked = row.getAttribute('data-attendance-marked') === 'true';
            
            if (isCertified || enrollmentStatus === 'certified' || assessment === 'Passed') {
                await Swal.fire({
                    icon: 'info',
                    title: 'Trainee Certified',
                    text: 'This trainee is already certified. No attendance marking needed.',
                    timer: 2000,
                    showConfirmButton: false
                });
                return;
            }
            
            if (assessment === 'Failed') {
                await Swal.fire({
                    icon: 'info',
                    title: 'Trainee Failed',
                    text: 'This trainee has failed. No attendance marking needed.',
                    timer: 2000,
                    showConfirmButton: false
                });
                return;
            }
            
            if (isDropout || enrollmentStatus === 'rejected') {
                await Swal.fire({
                    icon: 'info',
                    title: 'Trainee Dropped Out',
                    text: 'This trainee has dropped out. No attendance marking needed.',
                    timer: 2000,
                    showConfirmButton: false
                });
                return;
            }
            
            // Check if attendance is already marked for today
            if (attendanceMarked) {
                await Swal.fire({
                    icon: 'info',
                    title: 'Attendance Already Marked',
                    text: 'Attendance for today has already been marked for this trainee.',
                    timer: 2000,
                    showConfirmButton: false
                });
                return;
            }
            
            const result = await Swal.fire({
                title: 'Mark Attendance',
                html: `Mark as <strong>${status}</strong> for today?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: status === 'present' ? '#28a745' : '#dc3545',
                cancelButtonColor: '#6b7280',
                confirmButtonText: `Mark as ${status}`,
                cancelButtonText: 'Cancel'
            });
            
            if (result.isConfirmed) {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `ajax=1&action=mark_daily_attendance&enrollment_id=${enrollmentId}&status=${status}`
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update UI
                        const statusElement = document.getElementById(`status-${enrollmentId}`);
                        
                        // Update status badge
                        statusElement.className = `attendance-status attendance-${status}`;
                        statusElement.innerHTML = status === 'present' ? 
                            '<i class="fas fa-check-circle mr-1"></i> Present' : 
                            '<i class="fas fa-times-circle mr-1"></i> Absent';
                        
                        // Add marked by info
                        statusElement.innerHTML += `<div class="text-xs text-gray-500 mt-1">Marked by: <?php echo htmlspecialchars($fullname); ?></div>`;
                        
                        // Update button states
                        const presentBtn = row.querySelector('.attendance-btn.present');
                        const absentBtn = row.querySelector('.attendance-btn.absent');
                        
                        presentBtn.classList.toggle('active', status === 'present');
                        absentBtn.classList.toggle('active', status === 'absent');
                        
                        // Disable both buttons after marking
                        presentBtn.disabled = true;
                        presentBtn.classList.add('disabled');
                        presentBtn.title = 'Attendance already marked for today';
                        
                        absentBtn.disabled = true;
                        absentBtn.classList.add('disabled');
                        absentBtn.title = 'Attendance already marked for today';
                        
                        // Update row attribute
                        row.setAttribute('data-attendance-marked', 'true');
                        
                        // Check if we need to disable bulk buttons
                        checkAndDisableBulkButtons();
                        
                        // Show success message
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: `Marked as ${status}`,
                            timer: 1500,
                            showConfirmButton: false
                        });
                        
                        // Reload page after a short delay to update progress bars and status
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        // If error is because trainee is certified
                        if (data.message && data.message.includes('certified')) {
                            // Update row to show certified status
                            row.setAttribute('data-certified', 'true');
                            row.setAttribute('data-enrollment-status', 'certified');
                            const presentBtn = row.querySelector('.attendance-btn.present');
                            const absentBtn = row.querySelector('.attendance-btn.absent');
                            presentBtn.disabled = true;
                            presentBtn.classList.add('disabled');
                            presentBtn.title = 'Certified - No attendance needed';
                            absentBtn.disabled = true;
                            absentBtn.classList.add('disabled');
                            absentBtn.title = 'Certified - No attendance needed';
                            
                            Swal.fire({
                                icon: 'info',
                                title: 'Trainee Certified',
                                text: 'This trainee is now certified. No further attendance marking needed.',
                                timer: 2000,
                                showConfirmButton: false
                            });
                        } else if (data.message && data.message.includes('failed')) {
                            // Update row to show failed status
                            row.setAttribute('data-assessment', 'Failed');
                            const presentBtn = row.querySelector('.attendance-btn.present');
                            const absentBtn = row.querySelector('.attendance-btn.absent');
                            presentBtn.disabled = true;
                            presentBtn.classList.add('disabled');
                            presentBtn.title = 'Failed - No attendance needed';
                            absentBtn.disabled = true;
                            absentBtn.classList.add('disabled');
                            absentBtn.title = 'Failed - No attendance needed';
                            
                            Swal.fire({
                                icon: 'info',
                                title: 'Trainee Failed',
                                text: 'This trainee has failed. No further attendance marking needed.',
                                timer: 2000,
                                showConfirmButton: false
                            });
                        } else if (data.message && data.message.includes('dropped out')) {
                            // Update row to show dropout status
                            row.setAttribute('data-dropout', 'true');
                            row.setAttribute('data-enrollment-status', 'rejected');
                            const presentBtn = row.querySelector('.attendance-btn.present');
                            const absentBtn = row.querySelector('.attendance-btn.absent');
                            presentBtn.disabled = true;
                            presentBtn.classList.add('disabled');
                            presentBtn.title = 'Dropout - No attendance needed';
                            absentBtn.disabled = true;
                            absentBtn.classList.add('disabled');
                            absentBtn.title = 'Dropout - No attendance needed';
                            
                            Swal.fire({
                                icon: 'info',
                                title: 'Trainee Dropped Out',
                                text: 'This trainee has dropped out. No further attendance marking needed.',
                                timer: 2000,
                                showConfirmButton: false
                            });
                        } else if (data.message && (data.message.includes('not started') || data.message.includes('outside') || data.message.includes('ended'))) {
                            // Program schedule related errors
                            Swal.fire({
                                icon: 'warning',
                                title: 'Cannot Mark Attendance',
                                text: data.message,
                                timer: 3000,
                                showConfirmButton: false
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: data.message || 'Failed to mark attendance',
                                timer: 1500,
                                showConfirmButton: false
                            });
                        }
                    }
                } catch (error) {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Failed to mark attendance',
                        timer: 1500,
                        showConfirmButton: false
                    });
                }
            }
        }
        
        async function markAllTrainees(status) {
            // Check program status from PHP variables
            const programStarted = <?php echo $program_started ? 'true' : 'false'; ?>;
            const programEnded = <?php echo $program_ended ? 'true' : 'false'; ?>;
            const withinSchedule = <?php echo $within_schedule ? 'true' : 'false'; ?>;
            const allOngoingMarked = <?php echo $all_ongoing_marked_attendance ? 'true' : 'false'; ?>;
            
            if (!programStarted) {
                await Swal.fire({
                    icon: 'warning',
                    title: 'Program Not Started',
                    text: 'The training program has not started yet. Cannot mark attendance.',
                    timer: 3000,
                    showConfirmButton: false
                });
                return;
            }
            
            if (programEnded) {
                await Swal.fire({
                    icon: 'warning',
                    title: 'Program Ended',
                    text: 'The training program has already ended. Cannot mark attendance.',
                    timer: 3000,
                    showConfirmButton: false
                });
                return;
            }
            
            if (!withinSchedule) {
                await Swal.fire({
                    icon: 'warning',
                    title: 'Outside Schedule',
                    text: 'Today is outside of the program schedule. Cannot mark attendance.',
                    timer: 3000,
                    showConfirmButton: false
                });
                return;
            }
            
            if (allOngoingMarked) {
                await Swal.fire({
                    icon: 'info',
                    title: 'Already Marked',
                    text: 'All ongoing trainees have already marked attendance for today.',
                    timer: 2000,
                    showConfirmButton: false
                });
                return;
            }
            
            const result = await Swal.fire({
                title: 'Mark All Trainees',
                html: `Mark all <strong>ongoing</strong> trainees as <strong>${status}</strong> for today?<br><small>Certified, failed, and dropout trainees will be skipped.</small>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: status === 'present' ? '#28a745' : '#dc3545',
                cancelButtonColor: '#6b7280',
                confirmButtonText: `Mark all as ${status}`,
                cancelButtonText: 'Cancel'
            });
            
            if (result.isConfirmed) {
                try {
                    const action = status === 'present' ? 'mark_all_present_today' : 'mark_all_absent_today';
                    const response = await fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `ajax=1&action=${action}&program_id=<?php echo $program_id; ?>`
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            html: data.message + '<br><small>Only ongoing trainees were marked.</small>',
                            timer: 2000,
                            showConfirmButton: false
                        });
                        
                        // Disable bulk buttons
                        const markAllPresentBtn = document.getElementById('markAllPresentBtn');
                        const markAllAbsentBtn = document.getElementById('markAllAbsentBtn');
                        markAllPresentBtn.disabled = true;
                        markAllPresentBtn.classList.add('disabled');
                        markAllPresentBtn.title = 'All ongoing trainees already marked attendance for today';
                        markAllAbsentBtn.disabled = true;
                        markAllAbsentBtn.classList.add('disabled');
                        markAllAbsentBtn.title = 'All ongoing trainees already marked attendance for today';
                        
                        // Reload the page to update all statuses
                        setTimeout(() => {
                            location.reload();
                        }, 500);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Cannot Mark Attendance',
                            text: data.message || 'Failed to mark attendance for all trainees',
                            timer: 3000,
                            showConfirmButton: false
                        });
                    }
                } catch (error) {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Failed to mark attendance for all trainees',
                        timer: 1500,
                        showConfirmButton: false
                    });
                }
            }
        }
        
        // ========== MARK ALL TRAINEES PASSED FUNCTION ==========
        
        async function markAllTraineesPassed() {
            try {
                const result = await Swal.fire({
                    title: 'Mark All Trainees as Passed',
                    html: `Are you sure you want to mark <strong>all ongoing trainees</strong> as <strong>Passed</strong>?<br><br>
                           <strong>Note:</strong> This will:
                           <ul style="text-align: left; margin: 10px 0;">
                               <li>Certify all ongoing trainees</li>
                               <li>Move them to the Certified filter</li>
                               <li>Remove them from attendance tracking</li>
                               <li>Cannot be undone</li>
                           </ul>
                           <small>Certified, failed, and dropout trainees will be skipped.</small>`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#10b981',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, mark all as Passed',
                    cancelButtonText: 'Cancel'
                });
                
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Processing...',
                        text: 'Marking all trainees as Passed. Please wait...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    try {
                        const response = await fetch('', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `ajax=1&action=mark_all_passed&program_id=<?php echo $program_id; ?>`
                        });
                        
                        const data = await response.json();
                        
                        Swal.close();
                        
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success!',
                                html: data.message + '<br><small>All ongoing trainees have been marked as Passed and certified.</small>',
                                timer: 3000,
                                showConfirmButton: false
                            }).then(() => {
                                // Reload the page to update all statuses
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: data.message || 'Failed to mark all trainees as Passed',
                                timer: 3000,
                                showConfirmButton: false
                            });
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Network Error!',
                            text: 'Failed to connect to server. Please check your connection.',
                            timer: 3000,
                            showConfirmButton: false
                        });
                    }
                }
            } catch (error) {
                console.error('Error in mark all passed dialog:', error);
            }
        }
        
        // Check if all trainees have marked attendance and disable bulk buttons if needed
        function checkAndDisableBulkButtons() {
            // Get all ongoing trainee rows
            const rows = document.querySelectorAll('tr[data-enrollment-id]');
            let allMarked = true;
            
            rows.forEach(row => {
                const isCertified = row.getAttribute('data-certified') === 'true';
                const isDropout = row.getAttribute('data-dropout') === 'true';
                const assessment = row.getAttribute('data-assessment');
                const attendanceMarked = row.getAttribute('data-attendance-marked') === 'true';
                
                // Only check ongoing trainees (not certified, failed, or dropout)
                if (!isCertified && !isDropout && assessment !== 'Failed') {
                    if (!attendanceMarked) {
                        allMarked = false;
                    }
                }
            });
            
            if (allMarked) {
                const markAllPresentBtn = document.getElementById('markAllPresentBtn');
                const markAllAbsentBtn = document.getElementById('markAllAbsentBtn');
                
                markAllPresentBtn.disabled = true;
                markAllPresentBtn.classList.add('disabled');
                markAllPresentBtn.title = 'All ongoing trainees already marked attendance for today';
                
                markAllAbsentBtn.disabled = true;
                markAllAbsentBtn.classList.add('disabled');
                markAllAbsentBtn.title = 'All ongoing trainees already marked attendance for today';
            }
        }
        
        // ========== SHOW FAILURE NOTES ==========
        
        function showFailureNotes(notes) {
            Swal.fire({
                title: 'Failure Notes',
                html: `<div style="text-align: left; white-space: pre-line; padding: 10px; background: #f8f9fa; border-radius: 6px;">${notes}</div>`,
                showCloseButton: true,
                showConfirmButton: false,
                width: '600px'
            });
        }
        
        // ========== ASSESSMENT FUNCTION ==========
        
        async function updateAssessment(enrollmentId) {
            try {
                // First, check if this is for failure to ask for notes
                let failureNotes = null;
                
                const { value: assessment } = await Swal.fire({
                    title: 'Update Assessment',
                    html: 'Select assessment result:',
                    input: 'select',
                    inputOptions: {
                        'Passed': 'Passed',
                        'Failed': 'Failed'
                        // REMOVED: 'Pending', 'Not yet graded'
                    },
                    inputPlaceholder: 'Select assessment',
                    showCancelButton: true,
                    confirmButtonText: 'Next',
                    cancelButtonText: 'Cancel',
                    inputValidator: (value) => {
                        if (!value) {
                            return 'Please select an assessment result';
                        }
                    }
                });
                
                if (!assessment) return; // User cancelled
                
                if (assessment === 'Failed') {
                    // Ask for failure notes
                    const { value: notes } = await Swal.fire({
                        title: 'Failure Notes',
                        input: 'textarea',
                        inputLabel: 'Please provide reason(s) for failure:',
                        inputPlaceholder: 'Enter detailed notes about why the trainee failed...',
                        inputAttributes: {
                            'aria-label': 'Type your notes here'
                        },
                        showCancelButton: true,
                        confirmButtonText: 'Submit Assessment',
                        cancelButtonText: 'Cancel',
                        inputValidator: (value) => {
                            if (!value || value.trim().length < 10) {
                                return 'Please provide at least 10 characters for failure notes';
                            }
                        }
                    });
                    
                    if (notes === undefined) return; // User cancelled
                    failureNotes = notes || null;
                }
                
                if (assessment) {
                    const result = await Swal.fire({
                        title: 'Confirm Update',
                        html: `Update assessment to <strong>${assessment}</strong>${assessment === 'Failed' ? ' with notes' : ''}?`,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: assessment === 'Passed' ? '#10b981' : assessment === 'Failed' ? '#ef4444' : '#f59e0b',
                        cancelButtonColor: '#6b7280',
                        confirmButtonText: 'Yes, update',
                        cancelButtonText: 'Cancel'
                    });
                    
                    if (result.isConfirmed) {
                        // Show loading
                        Swal.fire({
                            title: 'Updating...',
                            text: 'Please wait',
                            allowOutsideClick: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        
                        try {
                            const formData = new URLSearchParams();
                            formData.append('ajax', '1');
                            formData.append('action', 'update_assessment');
                            formData.append('enrollment_id', enrollmentId);
                            formData.append('assessment', assessment);
                            if (failureNotes) {
                                formData.append('failure_notes', failureNotes);
                            }
                            
                            const response = await fetch('', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: formData.toString()
                            });
                            
                            const data = await response.json();
                            
                            Swal.close();
                            
                            if (data.success) {
                                // Update UI immediately
                                const assessmentBadge = document.getElementById(`assessment-${enrollmentId}`);
                                const row = document.getElementById(`trainee-row-${enrollmentId}`);
                                const statusBadge = document.getElementById(`status-badge-${enrollmentId}`);
                                
                                if (assessment === 'Passed') {
                                    // Update assessment badge
                                    assessmentBadge.className = 'assessment-badge assessment-passed';
                                    assessmentBadge.textContent = 'Passed';
                                    
                                    // Update row attributes
                                    row.setAttribute('data-certified', 'true');
                                    row.setAttribute('data-assessment', 'Passed');
                                    row.setAttribute('data-enrollment-status', 'certified');
                                    
                                    // Update status badge
                                    statusBadge.className = 'status-badge status-certified';
                                    statusBadge.textContent = 'Certified';
                                    
                                    // Disable attendance buttons
                                    const presentBtn = row.querySelector('.attendance-btn.present');
                                    const absentBtn = row.querySelector('.attendance-btn.absent');
                                    if (presentBtn) {
                                        presentBtn.disabled = true;
                                        presentBtn.classList.add('disabled');
                                        presentBtn.title = 'Certified - No attendance needed';
                                    }
                                    if (absentBtn) {
                                        absentBtn.disabled = true;
                                        absentBtn.classList.add('disabled');
                                        absentBtn.title = 'Certified - No attendance needed';
                                    }
                                    
                                    // Add certified message to attendance cell
                                    const attendanceCell = row.querySelector('td:nth-child(8)');
                                    if (attendanceCell) {
                                        const existingMsg = attendanceCell.querySelector('.text-xs.text-gray-500');
                                        if (!existingMsg) {
                                            const msgDiv = document.createElement('div');
                                            msgDiv.className = 'text-xs text-gray-500 mt-1';
                                            msgDiv.innerHTML = '<i class="fas fa-info-circle"></i> Certified - No attendance needed';
                                            attendanceCell.appendChild(msgDiv);
                                        }
                                    }
                                    
                                } else if (assessment === 'Failed') {
                                    // Update assessment badge
                                    assessmentBadge.className = 'assessment-badge assessment-failed';
                                    assessmentBadge.textContent = 'Failed';
                                    
                                    // Update row attributes
                                    row.setAttribute('data-assessment', 'Failed');
                                    
                                    // Update status badge
                                    statusBadge.className = 'status-badge status-failed';
                                    statusBadge.textContent = 'Failed';
                                    
                                    // Disable attendance buttons
                                    const presentBtn = row.querySelector('.attendance-btn.present');
                                    const absentBtn = row.querySelector('.attendance-btn.absent');
                                    if (presentBtn) {
                                        presentBtn.disabled = true;
                                        presentBtn.classList.add('disabled');
                                        presentBtn.title = 'Failed - No attendance needed';
                                    }
                                    if (absentBtn) {
                                        absentBtn.disabled = true;
                                        absentBtn.classList.add('disabled');
                                        absentBtn.title = 'Failed - No attendance needed';
                                    }
                                    
                                    // Add failed message
                                    const attendanceCell = row.querySelector('td:nth-child(8)');
                                    if (attendanceCell) {
                                        const existingMsg = attendanceCell.querySelector('.text-xs.text-gray-500');
                                        if (!existingMsg) {
                                            const msgDiv = document.createElement('div');
                                            msgDiv.className = 'text-xs text-gray-500 mt-1';
                                            msgDiv.innerHTML = '<i class="fas fa-times-circle"></i> Failed - No attendance needed';
                                            attendanceCell.appendChild(msgDiv);
                                        }
                                    }
                                    
                                    // Add failure notes indicator
                                    const assessmentCell = row.querySelector('td:nth-child(5)');
                                    if (assessmentCell && failureNotes) {
                                        const existingIndicator = assessmentCell.querySelector('.text-xs.text-gray-500');
                                        if (!existingIndicator) {
                                            const indicatorDiv = document.createElement('div');
                                            indicatorDiv.className = 'text-xs text-gray-500 mt-1';
                                            indicatorDiv.style.cursor = 'pointer';
                                            indicatorDiv.innerHTML = '<i class="fas fa-sticky-note"></i> Has notes';
                                            indicatorDiv.onclick = () => showFailureNotes(failureNotes);
                                            assessmentCell.appendChild(indicatorDiv);
                                        }
                                    }
                                }
                                
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Success!',
                                    text: 'Assessment updated successfully! The trainee will now appear in the appropriate filter.',
                                    timer: 3000,
                                    showConfirmButton: false
                                }).then(() => {
                                    // Always reload the page to update everything properly
                                    location.reload();
                                });
                                
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error!',
                                    text: data.message || 'Failed to update assessment. Please try again.',
                                    timer: 3000,
                                    showConfirmButton: false
                                });
                            }
                        } catch (error) {
                            console.error('Error:', error);
                            Swal.fire({
                                icon: 'error',
                                title: 'Network Error!',
                                text: 'Failed to connect to server. Please check your connection.',
                                timer: 3000,
                                showConfirmButton: false
                            });
                        }
                    }
                }
            } catch (error) {
                console.error('Error in assessment dialog:', error);
            }
        }
        
        // ========== DROPOUT FUNCTION ==========
        
        async function markAsDropout(enrollmentId) {
            try {
                // Ask for dropout reason
                const { value: dropoutReason } = await Swal.fire({
                    title: 'Mark as Dropout',
                    input: 'textarea',
                    inputLabel: 'Reason for dropout:',
                    inputPlaceholder: 'Please provide detailed reason why this trainee is dropping out...',
                    inputAttributes: {
                        'aria-label': 'Type dropout reason here'
                    },
                    showCancelButton: true,
                    confirmButtonText: 'Mark as Dropout',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#dc3545',
                    inputValidator: (value) => {
                        if (!value || value.trim().length < 10) {
                            return 'Please provide at least 10 characters for the dropout reason';
                        }
                    }
                });
                
                if (dropoutReason === undefined) return; // User cancelled
                
                // Confirm dropout
                const result = await Swal.fire({
                    title: 'Confirm Dropout',
                    html: `Are you sure you want to mark this trainee as a dropout?<br><br>
                           <strong>Note:</strong> This will:
                           <ul style="text-align: left; margin: 10px 0;">
                               <li>Move the trainee to the Dropout filter</li>
                               <li>Mark their assessment as Failed</li>
                               <li>Record the dropout reason in their permanent record</li>
                               <li>Remove them from attendance tracking</li>
                           </ul>`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, mark as dropout',
                    cancelButtonText: 'Cancel'
                });
                
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Processing...',
                        text: 'Please wait',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    try {
                        const formData = new URLSearchParams();
                        formData.append('ajax', '1');
                        formData.append('action', 'mark_as_dropout');
                        formData.append('enrollment_id', enrollmentId);
                        formData.append('dropout_reason', dropoutReason);
                        
                        const response = await fetch('', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: formData.toString()
                        });
                        
                        const data = await response.json();
                        
                        Swal.close();
                        
                        if (data.success) {
                            // Update UI immediately
                            const row = document.getElementById(`trainee-row-${enrollmentId}`);
                            const assessmentBadge = document.getElementById(`assessment-${enrollmentId}`);
                            const statusBadge = document.getElementById(`status-badge-${enrollmentId}`);
                            
                            // Update assessment badge
                            assessmentBadge.className = 'assessment-badge assessment-failed';
                            assessmentBadge.textContent = 'Failed';
                            
                            // Update row attributes
                            row.setAttribute('data-dropout', 'true');
                            row.setAttribute('data-enrollment-status', 'rejected');
                            row.setAttribute('data-assessment', 'Failed');
                            
                            // Update status badge
                            statusBadge.className = 'status-badge status-dropout';
                            statusBadge.textContent = 'Dropout';
                            
                            // Disable attendance buttons
                            const presentBtn = row.querySelector('.attendance-btn.present');
                            const absentBtn = row.querySelector('.attendance-btn.absent');
                            if (presentBtn) {
                                presentBtn.disabled = true;
                                presentBtn.classList.add('disabled');
                                presentBtn.title = 'Dropout - No attendance needed';
                            }
                            if (absentBtn) {
                                absentBtn.disabled = true;
                                absentBtn.classList.add('disabled');
                                absentBtn.title = 'Dropout - No attendance needed';
                            }
                            
                            // Disable assessment button
                            const assessBtn = row.querySelector('.btn-warning');
                            if (assessBtn) {
                                assessBtn.disabled = true;
                                assessBtn.classList.add('disabled');
                            }
                            
                            // Disable dropout button
                            const dropoutBtn = row.querySelector('.btn-danger');
                            if (dropoutBtn) {
                                dropoutBtn.disabled = true;
                                dropoutBtn.classList.add('disabled');
                            }
                            
                            // Add dropout message
                            const attendanceCell = row.querySelector('td:nth-child(8)');
                            if (attendanceCell) {
                                const existingMsg = attendanceCell.querySelector('.text-xs.text-gray-500');
                                if (!existingMsg) {
                                    const msgDiv = document.createElement('div');
                                    msgDiv.className = 'text-xs text-gray-500 mt-1';
                                    msgDiv.innerHTML = '<i class="fas fa-user-times"></i> Dropout - No attendance needed';
                                    attendanceCell.appendChild(msgDiv);
                                }
                            }
                            
                            // Update total trainees count
                            updateTotalTraineesCount();
                            
                            Swal.fire({
                                icon: 'success',
                                title: 'Success!',
                                text: 'Trainee marked as dropout. They will now appear in the Dropout filter.',
                                timer: 3000,
                                showConfirmButton: false
                            }).then(() => {
                                // Reload to update everything properly
                                location.reload();
                            });
                            
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: data.message || 'Failed to mark as dropout. Please try again.',
                                timer: 3000,
                                showConfirmButton: false
                            });
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Network Error!',
                            text: 'Failed to connect to server. Please check your connection.',
                            timer: 3000,
                            showConfirmButton: false
                        });
                    }
                }
            } catch (error) {
                console.error('Error in dropout dialog:', error);
            }
        }
        
        // Helper function to update total trainees count
        function updateTotalTraineesCount() {
            const totalTraineesElement = document.getElementById('totalTraineesCount');
            if (totalTraineesElement) {
                // You can implement AJAX to get updated count or just decrement
                const currentCount = parseInt(totalTraineesElement.textContent);
                if (!isNaN(currentCount) && currentCount > 0) {
                    totalTraineesElement.textContent = currentCount - 1;
                }
            }
        }
        
        // ========== VIEW TRAINEE DETAILS ==========
        
        function viewTraineeDetails(traineeUserId, programId) {
            const modal = document.getElementById('traineeModal');
            const content = document.getElementById('traineeDetailsContent');
            
            content.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
            modal.style.display = 'flex';
            
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `ajax=1&action=get_trainee_details&trainee_user_id=${traineeUserId}&program_id=${programId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const trainee = data.trainee;
                    const stats = trainee.attendance_stats || {};
                    
                    let detailsHTML = `
                        <div class="detail-row">
                            <div class="detail-label">Full Name:</div>
                            <div class="detail-value">${trainee.fullname}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Email:</div>
                            <div class="detail-value">${trainee.email || 'N/A'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Contact Number:</div>
                            <div class="detail-value">${trainee.contact_number || 'N/A'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Gender:</div>
                            <div class="detail-value">${trainee.gender || 'N/A'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Age:</div>
                            <div class="detail-value">${trainee.age || 'N/A'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Address:</div>
                            <div class="detail-value">${trainee.barangay || ''} ${trainee.municipality || ''} ${trainee.city || ''}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Education:</div>
                            <div class="detail-value">${trainee.education || 'N/A'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Program:</div>
                            <div class="detail-value">${trainee.program_name}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Applied Date:</div>
                            <div class="detail-value">${new Date(trainee.applied_at).toLocaleDateString()}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Attendance Stats:</div>
                            <div class="detail-value">
                                <div style="display: flex; gap: 15px; margin-top: 5px;">
                                    <div style="text-align: center;">
                                        <div style="font-size: 20px; font-weight: bold; color: #10b981;">${stats.present_days || 0}</div>
                                        <div style="font-size: 12px; color: #6b7280;">Present Days</div>
                                    </div>
                                    <div style="text-align: center;">
                                        <div style="font-size: 20px; font-weight: bold; color: #ef4444;">${stats.absent_days || 0}</div>
                                        <div style="font-size: 12px; color: #6b7280;">Absent Days</div>
                                    </div>
                                    <div style="text-align: center;">
                                        <div style="font-size: 20px; font-weight: bold; color: #3b82f6;">${stats.total_days || 0}</div>
                                        <div style="font-size: 12px; color: #6b7280;">Total Days</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Attendance:</div>
                            <div class="detail-value">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: ${trainee.attendance || 0}%"></div>
                                </div>
                                <div class="progress-text">${trainee.attendance || 0}%</div>
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Assessment:</div>
                            <div class="detail-value">
                                ${trainee.assessment || 'Not yet graded'}
                            </div>
                        </div>
                    `;
                    
                    // Add current failure/dropout notes if any
                    if (trainee.failure_notes) {
                        detailsHTML += `
                            <div class="detail-row">
                                <div class="detail-label">Current Failure/Dropout Notes:</div>
                                <div class="detail-value" style="background: #f8f9fa; padding: 10px; border-radius: 6px; border-left: 4px solid #ef4444;">
                                    <div style="white-space: pre-line; font-size: 14px;">${trainee.failure_notes}</div>
                                </div>
                            </div>
                        `;
                    }
                    
                    // Add failure notes archive if any
                    if (trainee.failure_notes_copy) {
                        detailsHTML += `
                            <div class="detail-row">
                                <div class="detail-label">All Failure/Dropout Notes Archive:</div>
                                <div class="detail-value" style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107;">
                                    <div style="white-space: pre-line; font-size: 13px; color: #856404; max-height: 200px; overflow-y: auto;">
                                        ${trainee.failure_notes_copy}
                                    </div>
                                    <div style="font-size: 11px; color: #999; margin-top: 5px;">
                                        <i class="fas fa-info-circle"></i> These notes are permanently stored with the trainee's record
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                    
                    detailsHTML += `
                        <div class="detail-row">
                            <div class="detail-label">Status:</div>
                            <div class="detail-value">
                                <span class="status-badge ${trainee.enrollment_status === 'rejected' ? 'status-dropout' : trainee.enrollment_status === 'certified' || trainee.assessment === 'Passed' ? 'status-certified' : trainee.assessment === 'Failed' ? 'status-failed' : 'status-ongoing'}">
                                    ${trainee.enrollment_status === 'rejected' ? 'Dropout' : trainee.enrollment_status === 'certified' || trainee.assessment === 'Passed' ? 'Certified' : trainee.assessment === 'Failed' ? 'Failed' : 'Ongoing'}
                                </span>
                            </div>
                        </div>
                    `;
                    
                    content.innerHTML = detailsHTML;
                } else {
                    content.innerHTML = '<div style="text-align: center; color: red;">Error loading details</div>';
                }
            })
            .catch(() => {
                content.innerHTML = '<div style="text-align: center; color: red;">Error loading details</div>';
            });
        }
        
        // ========== MODAL CONTROLS ==========
        function closeModal() { 
            document.getElementById('traineeModal').style.display = 'none'; 
        }
        
        // Close modals on outside click
        window.onclick = function(event) {
            if (event.target === document.getElementById('traineeModal')) {
                closeModal();
            }
        }
    </script>
</body>
</html>