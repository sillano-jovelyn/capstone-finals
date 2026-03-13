<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent output before headers
ob_start();

// Include db.php from the root directory
include __DIR__ . '/../db.php';

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../vendor/autoload.php';

// Check if database connection exists
if (!isset($conn)) {
    die("Database connection not established. Check db.php file.");
}



function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    
    // Get the full script path
    $script_name = $_SERVER['SCRIPT_NAME'];
    
    // Remove 'admin/enrollment-management.php' from the path to get the root
    $base_path = preg_replace('/admin\/enrollment-management\.php$/', '', $script_name);
    
    return $protocol . $host . $base_path;
}



function createNotification($user_id, $type, $title, $message, $related_id = null, $related_type = null) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, related_id, related_type, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->bind_param("isssis", $user_id, $type, $title, $message, $related_id, $related_type);
        $result = $stmt->execute();
        
        if ($result) {
            return ['success' => true, 'notification_id' => $conn->insert_id];
        } else {
            return ['success' => false, 'error' => 'Failed to create notification: ' . $conn->error];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function getTraineeUserIdFromEnrollment($enrollment_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT user_id 
        FROM enrollments 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $enrollment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['user_id'];
    }
    
    return null;
}

function getRecentPrograms($limit = 3) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT p.*, 
               COUNT(e.id) as enrolled_count
        FROM programs p 
        LEFT JOIN enrollments e ON p.id = e.program_id
        WHERE p.archived = 0 AND p.status = 'active'
        GROUP BY p.id 
        ORDER BY p.created_at DESC 
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getLatestApprovedTrainees($limit = 10) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT e.*, p.name as program_name, t.fullname
        FROM enrollments e
        JOIN programs p ON e.program_id = p.id
        JOIN trainees t ON e.user_id = t.user_id
        WHERE e.enrollment_status = 'approved'
        ORDER BY e.applied_at DESC
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getPendingEnrollments() {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT e.*, p.name as program_name, t.fullname, t.contact_number, t.email
        FROM enrollments e
        JOIN programs p ON e.program_id = p.id
        JOIN trainees t ON e.user_id = t.user_id
        WHERE e.enrollment_status = 'pending'
        ORDER BY e.applied_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getTraineesByProgram($program_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT e.id as enrollment_id, e.applied_at, e.enrollment_status, e.attendance,
               t.toolkit_received,
               t.user_id, t.id as trainee_id, t.fullname, t.firstname, t.lastname, 
               t.contact_number, t.email, t.barangay, t.municipality, t.city, 
               t.gender, t.age, t.education, t.address,
               CONCAT(t.barangay, ', ', t.municipality, ', ', t.city) as full_address
        FROM enrollments e
        JOIN trainees t ON e.user_id = t.user_id
        WHERE e.program_id = ?
        ORDER BY e.applied_at DESC
    ");
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function updateEnrollmentStatus($enrollment_id, $status) {
    global $conn;
    
    $stmt = $conn->prepare("
        UPDATE enrollments 
        SET enrollment_status = ? 
        WHERE id = ?
    ");
    $stmt->bind_param("si", $status, $enrollment_id);
    return $stmt->execute();
}

function getAllPrograms() {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT * FROM programs 
        WHERE archived = 0 
        AND status = 'active'
        ORDER BY name
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getEnrollmentStats() {
    global $conn;
    
    $stats = array();
    
    // Total enrollments
    $result = $conn->query("SELECT COUNT(*) as total FROM enrollments");
    $stats['total_enrollments'] = $result->fetch_assoc()['total'];
    
    // Pending enrollments
    $result = $conn->query("SELECT COUNT(*) as pending FROM enrollments WHERE enrollment_status = 'pending'");
    $stats['pending_enrollments'] = $result->fetch_assoc()['pending'];
    
    // Approved enrollments
    $result = $conn->query("SELECT COUNT(*) as approved FROM enrollments WHERE enrollment_status = 'approved'");
    $stats['approved_enrollments'] = $result->fetch_assoc()['approved'];
    
    // Revision needed enrollments
    $result = $conn->query("SELECT COUNT(*) as revision_needed FROM enrollments WHERE enrollment_status = 'revision_needed'");
    $stats['revision_needed'] = $result->fetch_assoc()['revision_needed'];
    
    // Total programs
    $result = $conn->query("SELECT COUNT(*) as total FROM programs WHERE archived = 0 AND status = 'active'");
    $stats['total_programs'] = $result->fetch_assoc()['total'];
    
    return $stats;
}

function searchEverything($search_term) {
    global $conn;
    
    $search_term = "%$search_term%";
    
    $results = [
        'enrollments' => [],
        'programs' => [],
        'trainees' => []
    ];
    
    // Search enrollments
    $stmt = $conn->prepare("
        SELECT e.*, p.name as program_name, t.fullname, t.contact_number, t.email,
               'enrollment' as result_type
        FROM enrollments e
        JOIN programs p ON e.program_id = p.id
        JOIN trainees t ON e.user_id = t.user_id
        WHERE t.fullname LIKE ? OR p.name LIKE ? OR t.contact_number LIKE ? OR t.email LIKE ?
        ORDER BY e.applied_at DESC
        LIMIT 10
    ");
    $stmt->bind_param("ssss", $search_term, $search_term, $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();
    $results['enrollments'] = $result->fetch_all(MYSQLI_ASSOC);
    
    // Search programs
    $stmt = $conn->prepare("
        SELECT p.*, 
               COUNT(e.id) as enrolled_count,
               'program' as result_type
        FROM programs p 
        LEFT JOIN enrollments e ON p.id = e.program_id
        WHERE p.archived = 0 AND p.status = 'active'
        AND (p.name LIKE ? OR p.trainer LIKE ?)
        GROUP BY p.id 
        ORDER BY p.created_at DESC
        LIMIT 10
    ");
    $stmt->bind_param("ss", $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();
    $results['programs'] = $result->fetch_all(MYSQLI_ASSOC);
    
    // Search trainees
    $stmt = $conn->prepare("
        SELECT t.*, 
               COUNT(e.id) as enrollment_count,
               GROUP_CONCAT(p.name SEPARATOR ', ') as enrolled_programs,
               'trainee' as result_type
        FROM trainees t 
        LEFT JOIN enrollments e ON t.user_id = e.user_id
        LEFT JOIN programs p ON e.program_id = p.id
        WHERE t.fullname LIKE ? OR t.fullname LIKE ? OR t.lastname LIKE ? OR t.firstname LIKE ?
        GROUP BY t.user_id 
        ORDER BY t.fullname
        LIMIT 10
    ");
    $stmt->bind_param("ssss", $search_term, $search_term, $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();
    $results['trainees'] = $result->fetch_all(MYSQLI_ASSOC);
    
    return $results;
}

function getAllEnrollments() {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT e.id as enrollment_id, e.applied_at, e.enrollment_status,
               t.user_id, t.id as trainee_id, t.fullname, t.contact_number, t.email,
               p.name as program_name
        FROM enrollments e
        JOIN trainees t ON e.user_id = t.user_id
        JOIN programs p ON e.program_id = p.id
        ORDER BY e.applied_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getTraineeDetailsByUserId($user_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT t.*,
               (SELECT GROUP_CONCAT(p.name SEPARATOR ', ') 
                FROM enrollments e 
                JOIN programs p ON e.program_id = p.id 
                WHERE e.user_id = t.user_id) as enrolled_programs
        FROM trainees t 
        WHERE t.user_id = ?
    ");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getTraineeEnrollmentHistory($user_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT e.*, p.name as program_name, p.trainer
        FROM enrollments e
        JOIN programs p ON e.program_id = p.id
        WHERE e.user_id = ?
        ORDER BY e.applied_at DESC
    ");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getTraineeDocuments($user_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT valid_id, voters_certificate 
        FROM trainees 
        WHERE user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        $documents = [];
        
        // Parse valid_id JSON
        if (!empty($row['valid_id'])) {
            $valid_ids = json_decode($row['valid_id'], true);
            if (is_array($valid_ids)) {
                $documents['valid_id'] = $valid_ids;
            }
        }
        
        // Parse voters_certificate JSON
        if (!empty($row['voters_certificate'])) {
            $voter_certs = json_decode($row['voters_certificate'], true);
            if (is_array($voter_certs)) {
                $documents['voters_certificate'] = $voter_certs;
            }
        }
        
        return $documents;
    }
    
    return [];
}


function createRevisionRequest($enrollment_id, $admin_id, $reason) {
    global $conn;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Create revision request
        $stmt = $conn->prepare("
            INSERT INTO revision_requests (enrollment_id, admin_id, reason, status, created_at)
            VALUES (?, ?, ?, 'pending', NOW())
        ");
        $stmt->bind_param("iis", $enrollment_id, $admin_id, $reason);
        if (!$stmt->execute()) {
            throw new Exception("Failed to create revision request: " . $stmt->error);
        }
        
        $revision_id = $conn->insert_id;
        
        // Update enrollment status to 'revision_needed'
        $stmt = $conn->prepare("
            UPDATE enrollments 
            SET enrollment_status = 'revision_needed',
                revision_requests_id = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ii", $revision_id, $enrollment_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update enrollment: " . $stmt->error);
        }
        
        // Get enrollment details for email and notification
        $stmt = $conn->prepare("
            SELECT e.*, p.name as program_name, t.email, t.fullname, t.user_id
            FROM enrollments e
            JOIN programs p ON e.program_id = p.id
            JOIN trainees t ON e.user_id = t.user_id
            WHERE e.id = ?
        ");
        $stmt->bind_param("i", $enrollment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $enrollment = $result->fetch_assoc();
        
        // Create notification for the trainee
        if ($enrollment) {
            $notification_title = "Revision Required";
            $notification_message = "Your enrollment for '{$enrollment['program_name']}' requires revision. Please check your email and update your profile/documents.";
            
            $notify_stmt = $conn->prepare("
                INSERT INTO notifications (user_id, type, title, message, related_id, related_type, created_at)
                VALUES (?, 'warning', ?, ?, ?, 'enrollment', NOW())
            ");
            $notify_stmt->bind_param("issi", $enrollment['user_id'], $notification_title, $notification_message, $enrollment_id);
            $notify_stmt->execute();
            $notify_stmt->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        // Return data for email sending
        return [
            'success' => true,
            'revision_id' => $revision_id,
            'enrollment' => $enrollment
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function getRevisionRequests($status = null) {
    global $conn;
    
    $query = "
        SELECT rr.*, 
               e.id as enrollment_id, e.applied_at, e.enrollment_status,
               p.name as program_name,
               t.fullname as trainee_name, t.email as trainee_email,
               u.fullname as admin_name
        FROM revision_requests rr
        JOIN enrollments e ON rr.enrollment_id = e.id
        JOIN programs p ON e.program_id = p.id
        JOIN trainees t ON e.user_id = t.user_id
        LEFT JOIN users u ON rr.admin_id = u.id
    ";
    
    if ($status) {
        $query .= " WHERE rr.status = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $status);
    } else {
        $stmt = $conn->prepare($query);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}


function sendEnrollmentApprovalEmail($trainee_email, $trainee_name, $program_name, $program_details = []) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lems.superadmn@gmail.com';
        $mail->Password   = 'gubivcizhhkewkda';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Recipients
        $mail->setFrom('noreply@municipallivelihood.gov.ph', 'Municipal Livelihood Program');
        $mail->addAddress($trainee_email, $trainee_name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Enrollment Approved - ' . $program_name;
        
        $mail->Body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px; background-color: #f9f9f9; }
                .header { color: #27ae60; text-align: center; margin-bottom: 20px; }
                .content-box { background-color: #d4edda; border: 3px solid #27ae60; border-radius: 8px; padding: 25px; margin: 20px 0; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                .details-box { background-color: white; border: 2px solid #e9ecef; border-radius: 8px; padding: 20px; margin: 15px 0; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2 class='header'>🎉 Enrollment Approved!</h2>
                
                <div class='content-box'>
                    <h3 style='color: #27ae60; margin-top: 0;'>Congratulations, $trainee_name!</h3>
                    <p>Your enrollment application for <strong>$program_name</strong> has been <strong>APPROVED</strong>.</p>
                    
                    <div class='details-box'>
                        <h4 style='color: #2c5aa0; margin-top: 0;'>Program Details:</h4>
                        <p><strong>Program Name:</strong> $program_name</p>
                        " . (!empty($program_details['scheduleStart']) ? "<p><strong>Start Date:</strong> " . date('F j, Y', strtotime($program_details['scheduleStart'])) . "</p>" : "") . "
                        " . (!empty($program_details['scheduleEnd']) ? "<p><strong>End Date:</strong> " . date('F j, Y', strtotime($program_details['scheduleEnd'])) . "</p>" : "") . "
                        " . (!empty($program_details['trainer']) ? "<p><strong>Trainer:</strong> " . htmlspecialchars($program_details['trainer']) . "</p>" : "") . "
                        " . (!empty($program_details['duration']) ? "<p><strong>Duration:</strong> " . htmlspecialchars($program_details['duration']) . " days</p>" : "") . "
                        <p><strong>Status:</strong> <span style='color: #27ae60; font-weight: bold;'>APPROVED ✅</span></p>
                    </div>
                    
                    <h4 style='color: #2c5aa0; margin-top: 20px;'>Next Steps:</h4>
                    <ol style='margin: 10px 0 10px 20px;'>
                        <li>Login to your account to view program details</li>
                        <li>Check the program schedule and location</li>
                        <li>Prepare any required materials</li>
                        <li>Attend the orientation session (if scheduled)</li>
                    </ol>
                </div>
                
                <div class='details-box'>
                    <h4 style='color: #2c5aa0; margin-top: 0;'>Important Notes:</h4>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li>Keep your login credentials secure</li>
                        <li>Regularly check your email for updates</li>
                        <li>Contact the administrator if you have questions</li>
                        <li>Attend all scheduled sessions</li>
                    </ul>
                </div>
                
                <div class='footer'>
                    <p><strong>Need Help?</strong></p>
                    <p>If you have any questions or need assistance, please contact our support team.</p>
                    <p>This is an automated message. Please do not reply to this email.</p>
                </div>
                
                <br>
                <p>Best regards,<br>
                <strong>Municipal Livelihood Program Team</strong></p>
            </div>
        </body>
        </html>
        ";
        
        $mail->AltBody = "Congratulations $trainee_name!\n\nYour enrollment application for $program_name has been APPROVED.\n\nPlease login to your account for more details.\n\nBest regards,\nMunicipal Livelihood Program Team";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Mailer Error (Approval): " . $e->getMessage());
        return false;
    }
}

function sendEnrollmentRejectionEmail($trainee_email, $trainee_name, $program_name, $rejection_reason) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lems.superadmn@gmail.com';
        $mail->Password   = 'gubivcizhhkewkda';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Recipients
        $mail->setFrom('noreply@municipallivelihood.gov.ph', 'Municipal Livelihood Program');
        $mail->addAddress($trainee_email, $trainee_name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Enrollment Rejected - ' . $program_name;
        
        $mail->Body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px; background-color: #f9f9f9; }
                .header { color: #e74c3c; text-align: center; margin-bottom: 20px; }
                .content-box { background-color: #f8d7da; border: 3px solid #e74c3c; border-radius: 8px; padding: 25px; margin: 20px 0; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                .details-box { background-color: white; border: 2px solid #e9ecef; border-radius: 8px; padding: 20px; margin: 15px 0; }
                .reason-box { background-color: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; padding: 15px; margin: 15px 0; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2 class='header'>Enrollment Rejected</h2>
                
                <div class='content-box'>
                    <h3 style='color: #e74c3c; margin-top: 0;'>Dear $trainee_name,</h3>
                    <p>Your enrollment application for <strong>$program_name</strong> has been <strong style='color: #e74c3c;'>REJECTED</strong> and removed from our system.</p>
                    
                    <div class='reason-box'>
                        <h4 style='color: #856404; margin-top: 0;'>Reason for Rejection:</h4>
                        <p>" . nl2br(htmlspecialchars($rejection_reason)) . "</p>
                    </div>
                </div>
                
                <div class='details-box'>
                    <h4 style='color: #2c5aa0; margin-top: 0;'>What This Means:</h4>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li>Your enrollment has been permanently removed from the system</li>
                        <li>You can apply for other programs at any time</li>
                        <li>You may need to provide additional documentation for future applications</li>
                        <li>Check our program catalog for other opportunities</li>
                    </ul>
                </div>
                
                <div class='details-box'>
                    <h4 style='color: #2c5aa0; margin-top: 0;'>Important Information:</h4>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li>This decision is based on program requirements and available slots</li>
                        <li>You may reapply for future program offerings</li>
                        <li>Contact us for guidance on improving your application</li>
                    </ul>
                </div>
                
                <div class='footer'>
                    <p><strong>Need Assistance?</strong></p>
                    <p>If you have questions about this decision, please contact our program coordinator.</p>
                    <p>This is an automated message. Please do not reply to this email.</p>
                </div>
                
                <br>
                <p>Sincerely,<br>
                <strong>Municipal Livelihood Program Team</strong></p>
            </div>
        </body>
        </html>
        ";
        
        $mail->AltBody = "Enrollment Rejected\n\nDear $trainee_name,\n\nYour enrollment application for $program_name has been REJECTED and removed from our system.\n\nReason: $rejection_reason\n\nYou may apply for other programs or contact us for more information.\n\nSincerely,\nMunicipal Livelihood Program Team";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Mailer Error (Rejection): " . $e->getMessage());
        return false;
    }
}

function sendRevisionRequestEmail($trainee_email, $trainee_name, $program_name, $profile_link, $reason) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lems.superadmn@gmail.com';
        $mail->Password   = 'gubivcizhhkewkda';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Recipients
        $mail->setFrom('noreply@municipallivelihood.gov.ph', 'Municipal Livelihood Program');
        $mail->addAddress($trainee_email, $trainee_name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Revision Request - ' . $program_name;
        
        $mail->Body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px; background-color: #f9f9f9; }
                .header { color: #f39c12; text-align: center; margin-bottom: 20px; }
                .content-box { background-color: #fff3cd; border: 3px solid #f39c12; border-radius: 8px; padding: 25px; margin: 20px 0; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                .reason-box { background-color: white; border: 2px solid #e9ecef; border-radius: 8px; padding: 20px; margin: 15px 0; }
                .button { display: inline-block; padding: 12px 24px; background: #3498db; 
                         color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: bold; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2 class='header'>Application Revision Required</h2>
                
                <div class='content-box'>
                    <h3 style='color: #f39c12; margin-top: 0;'>Dear $trainee_name,</h3>
                    <p>Your enrollment application for <strong>$program_name</strong> requires some revisions before it can be processed.</p>
                    
                    <div class='reason-box'>
                        <h4 style='color: #2c5aa0; margin-top: 0;'>Reason for Revision:</h4>
                        <p>" . nl2br(htmlspecialchars($reason)) . "</p>
                    </div>
                    
                    <p style='text-align: center; margin: 25px 0;'>
                        <a href='$profile_link' class='button'>✏️ Update My Profile</a>
                    </p>
                    
                    <div style='background: #d1ecf1; padding: 15px; border-radius: 8px; border-left: 4px solid #0c5460; margin: 20px 0;'>
                        <h4 style='color: #0c5460; margin-top: 0;'>Important Instructions:</h4>
                        <ol style='margin: 10px 0 10px 20px;'>
                            <li>Click the button above to access your profile page</li>
                            <li>Login to your account (if not already logged in)</li>
                            <li>Review and update your information as requested</li>
                            <li>Upload or update required documents</li>
                            <li>Your application will automatically return to pending status after you update your profile</li>
                        </ol>
                    </div>
                </div>
                
                <div class='reason-box'>
                    <h4 style='color: #2c5aa0; margin-top: 0;'>Important Notes:</h4>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li>Make sure all information is accurate before saving</li>
                        <li>You can update both personal information and documents</li>
                        <li>Contact us if you need assistance with the revision</li>
                    </ul>
                </div>
                
                <div class='footer'>
                    <p><strong>Need Help?</strong></p>
                    <p>If you have questions about the required revisions, please contact our program coordinator.</p>
                    <p>This is an automated message. Please do not reply to this email.</p>
                </div>
                
                <br>
                <p>Sincerely,<br>
                <strong>Municipal Livelihood Program Team</strong></p>
            </div>
        </body>
        </html>
        ";
        
        $mail->AltBody = "Application Revision Required\n\nDear $trainee_name,\n\nYour enrollment application for $program_name requires revisions.\n\nReason: $reason\n\nPlease login to your account at $profile_link to update your profile and documents.\n\nSincerely,\nMunicipal Livelihood Program Team";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Mailer Error (Revision): " . $e->getMessage());
        return false;
    }
}


function getEnrollmentsForApproval($filter_status = 'pending', $filter_program = 'all', $filter_date = '') {
    global $conn;
    
    // Build query based on your actual database structure
    $query = "SELECT e.id, e.applied_at, e.enrollment_status, e.rejection_reason, e.admin_notes,
                     p.id as program_id, p.name as program_name, p.slotsAvailable,
                     t.user_id, t.fullname, t.email, t.contact_number, t.address,
                     NULL as approver_name
              FROM enrollments e 
              JOIN programs p ON e.program_id = p.id 
              JOIN trainees t ON e.user_id = t.user_id 
              WHERE p.status = 'active'";

    $params = [];
    $types = "";

    // Use enrollment_status for filtering
    if ($filter_status !== 'all') {
        $query .= " AND e.enrollment_status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }

    if ($filter_program !== 'all') {
        $query .= " AND e.program_id = ?";
        $params[] = $filter_program;
        $types .= "i";
    }

    if ($filter_date) {
        $query .= " AND DATE(e.applied_at) = ?";
        $params[] = $filter_date;
        $types .= "s";
    }

    $query .= " ORDER BY e.applied_at DESC";

    // Execute query
    if (!empty($params)) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $enrollments = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
    } else {
        $result = $conn->query($query);
        $enrollments = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
    
    return $enrollments;
}

function getApplicationDetails($id) {
    global $conn;
    
    $query = "SELECT e.id, e.applied_at, e.enrollment_status, e.attendance, e.rejection_reason, e.admin_notes,
                     p.name as program_name, p.duration, 
                     p.scheduleStart, p.scheduleEnd, p.trainer, p.total_slots, p.slotsAvailable,
                     t.user_id, t.fullname, t.email, t.contact_number, t.address, 
                     t.gender, t.age, t.education, t.barangay, t.municipality, t.city,
                     t.applicant_type, t.nc_holder  /* ADD THESE TWO FIELDS */
              FROM enrollments e 
              JOIN programs p ON e.program_id = p.id 
              JOIN trainees t ON e.user_id = t.user_id 
              WHERE e.id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $application = $result->fetch_assoc();
    $stmt->close();
    
    // Get documents if application exists
    if ($application) {
        $application['documents'] = getTraineeDocuments($application['user_id']);
    }
    
    return $application;
}



// Get initial data
$recent_programs = getRecentPrograms();
$latest_approved_trainees = getLatestApprovedTrainees();
$all_programs = getAllPrograms();
$stats = getEnrollmentStats();

// Initialize variables
$message = '';
$message_type = '';
$search_performed = false;
$search_results = [];

// Get filter parameters for approval section
$filter_status = $_GET['approval_status'] ?? 'pending';
$filter_program = $_GET['approval_program'] ?? 'all';
$filter_date = $_GET['approval_date'] ?? '';

// Get enrollments for approval
$approval_enrollments = getEnrollmentsForApproval($filter_status, $filter_program, $filter_date);

// Get programs for approval filter
$approval_programs = [];
$programs_result = $conn->query("SELECT id, name FROM programs WHERE archived = 0 AND status = 'active' ORDER BY name");
if ($programs_result) {
    $approval_programs = $programs_result->fetch_all(MYSQLI_ASSOC);
}

// Get revision requests
$revision_requests = getRevisionRequests('pending');


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   // In the POST request handling section for revision request
        if (isset($_POST['action']) && $_POST['action'] === 'request_revision' && isset($_POST['enrollment_id'])) {
            $enrollment_id = intval($_POST['enrollment_id']);
            $revision_reason = $_POST['revision_reason'] ?? '';
            $admin_notes = $_POST['admin_notes'] ?? '';
            $admin_id = $_SESSION['user_id'] ?? 1;
            
            error_log("========== REVISION REQUEST START ==========");
            error_log("Revision request initiated for enrollment ID: $enrollment_id");
            error_log("Admin ID: $admin_id");
            error_log("Revision Reason: $revision_reason");
            
            if (empty($revision_reason)) {
                $_SESSION['error'] = "Please provide a reason for revision.";
                ob_end_clean();
                header("Location: enrollment-management.php");
                exit;
            }
            
            // Step 1: Create revision request in database
            $result = createRevisionRequest($enrollment_id, $admin_id, $revision_reason);
            
            if ($result['success']) {
                error_log("✓ Revision request created successfully. Revision ID: " . $result['revision_id']);
                
                // Step 2: Get trainee user_id for profile link
                $trainee_user_id = null;
                if ($result['enrollment'] && isset($result['enrollment']['user_id'])) {
                    $trainee_user_id = $result['enrollment']['user_id'];
                    error_log("✓ Retrieved trainee user_id from enrollment: $trainee_user_id");
                } else {
                    $trainee_user_id = getTraineeUserIdFromEnrollment($enrollment_id);
                    error_log("✓ Retrieved trainee user_id via fallback: $trainee_user_id");
                }
                
                // Step 3: Create notification for trainee (already done in createRevisionRequest)
                $notification_status = " Notification recorded.";
                error_log("✓ Notification created for trainee (user_id: $trainee_user_id)");
                
                // Step 4: Send email with profile link - UPDATED VERSION
                if (!empty($result['enrollment'])) {
                    $enrollment = $result['enrollment'];
                    
                    // Get the base URL dynamically
                    $base_url = getBaseUrl();
                    error_log("Base URL: " . $base_url);
                    
                    // UPDATED: Construct profile link that goes through login first
                    $profile_link = $base_url . 'login.php?redirect=profile.php&user_id=' . $trainee_user_id . '&revision=1';
                    error_log("Profile link constructed: " . $profile_link);
                    
                    // Debug: Check if the file exists on the server
                    if (isset($_SERVER['DOCUMENT_ROOT'])) {
                        $file_path = $_SERVER['DOCUMENT_ROOT'] . '/trainee/profile.php';
                        error_log("Checking file existence: " . $file_path);
                        error_log("File exists? " . (file_exists($file_path) ? 'YES' : 'NO'));
                        
                        // Alternative path
                        $alt_file_path = $_SERVER['DOCUMENT_ROOT'] . '/livelihood/trainee/profile.php';
                        error_log("Alternative path: " . $alt_file_path);
                        error_log("Alternative file exists? " . (file_exists($alt_file_path) ? 'YES' : 'NO'));
                    }
                    
                    error_log("Attempting to send email to: " . $enrollment['email']);
                    error_log("Trainee name: " . $enrollment['fullname']);
                    error_log("Program name: " . $enrollment['program_name']);
                    
                    // Send email using PHPMailer
                    $email_sent = sendRevisionRequestEmail(
                        $enrollment['email'],
                        $enrollment['fullname'],
                        $enrollment['program_name'],
                        $profile_link,
                        $revision_reason
                    );
                    
                    $email_status = $email_sent ? 
                        " Email sent to trainee with profile link." : " Email could not be sent.";
                    
                    error_log("Email sent status: " . ($email_sent ? 'SUCCESS' : 'FAILED'));
                } else {
                    $email_status = " Could not retrieve enrollment details for email.";
                    error_log("✗ ERROR: Could not retrieve enrollment details");
                }
                
                $_SESSION['success'] = "Revision request sent successfully!" . $email_status . $notification_status;
                error_log("✓ Revision request process completed successfully");
                error_log("========== REVISION REQUEST END ==========");
                
            } else {
                $_SESSION['error'] = "Error creating revision request: " . $result['error'];
                error_log("✗ ERROR: Revision request failed: " . $result['error']);
                error_log("========== REVISION REQUEST FAILED ==========");
            }
            
            // Clear output buffer before redirect
            ob_end_clean();
            header("Location: enrollment-management.php");
            exit;
        }
    
    // Handle approval/rejection actions from approval section
    if (isset($_POST['enrollment_id']) && isset($_POST['action'])) {
        $enrollment_id = $_POST['enrollment_id'] ?? null;
        $action = $_POST['action'] ?? null;
        $rejection_reason = $_POST['rejection_reason'] ?? '';
        $admin_notes = $_POST['admin_notes'] ?? '';
        
        if ($enrollment_id && $action) {
            try {
                $conn->begin_transaction();
                
                // Get enrollment details
                $stmt = $conn->prepare("SELECT e.*, p.name as program_name, p.slotsAvailable, p.total_slots,
                                       p.scheduleStart, p.scheduleEnd, p.trainer, p.duration,
                                       t.email, t.fullname, t.user_id
                                       FROM enrollments e 
                                       JOIN programs p ON e.program_id = p.id 
                                       JOIN trainees t ON e.user_id = t.user_id 
                                       WHERE e.id = ?");
                $stmt->bind_param("i", $enrollment_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    throw new Exception("Enrollment not found.");
                }
                
                $enrollment = $result->fetch_assoc();
                $stmt->close();
                
                $email_sent = false;
                $updateStmt = null;
                
                if ($action === 'approve') {
                    // Check if slots are available
                    if ($enrollment['slotsAvailable'] <= 0) {
                        // Update enrollment status to approved (for waitlist)
                        $updateStmt = $conn->prepare("UPDATE enrollments SET 
                            enrollment_status = 'approved',
                            admin_notes = ?
                            WHERE id = ?");
                        $updateStmt->bind_param("si", $admin_notes, $enrollment_id);
                        
                        $message = "Enrollment approved for waiting list!";
                    } else {
                        // Update enrollment status
                        $updateStmt = $conn->prepare("UPDATE enrollments SET 
                            enrollment_status = 'approved',
                            admin_notes = ?
                            WHERE id = ?");
                        $updateStmt->bind_param("si", $admin_notes, $enrollment_id);
                        
                        // Update program slots if available
                        $slotStmt = $conn->prepare("UPDATE programs SET slotsAvailable = slotsAvailable - 1 WHERE id = ? AND slotsAvailable > 0");
                        $slotStmt->bind_param("i", $enrollment['program_id']);
                        $slotStmt->execute();
                        $slotStmt->close();
                        
                        $message = "Enrollment approved successfully!";
                    }
                    
                    // Create notification for trainee
                    $notificationResult = createNotification(
                        $enrollment['user_id'],
                        'success',
                        'Enrollment Approved',
                        "Your enrollment for '{$enrollment['program_name']}' has been approved.",
                        $enrollment_id,
                        'enrollment'
                    );
                    
                    // Send approval email
                    $program_details = [
                        'scheduleStart' => $enrollment['scheduleStart'],
                        'scheduleEnd' => $enrollment['scheduleEnd'],
                        'trainer' => $enrollment['trainer'],
                        'duration' => $enrollment['duration']
                    ];
                    
                    $email_sent = sendEnrollmentApprovalEmail(
                        $enrollment['email'],
                        $enrollment['fullname'],
                        $enrollment['program_name'],
                        $program_details
                    );
                    
                } elseif ($action === 'reject') {
                    // Send rejection email BEFORE deletion
                    $email_sent = sendEnrollmentRejectionEmail(
                        $enrollment['email'],
                        $enrollment['fullname'],
                        $enrollment['program_name'],
                        $rejection_reason
                    );
                    
                    // Create notification for trainee BEFORE deletion
                    $notificationResult = createNotification(
                        $enrollment['user_id'],
                        'danger',
                        'Enrollment Rejected',
                        "Your enrollment for '{$enrollment['program_name']}' has been rejected. Reason: {$rejection_reason}",
                        $enrollment_id,
                        'enrollment'
                    );
                    
                    // DELETE the enrollment from the database
                    $deleteStmt = $conn->prepare("DELETE FROM enrollments WHERE id = ?");
                    $deleteStmt->bind_param("i", $enrollment_id);
                    
                    if (!$deleteStmt->execute()) {
                        throw new Exception("Failed to delete enrollment.");
                    }
                    $deleteStmt->close();
                    
                    $message = "Enrollment rejected and deleted from system successfully!";
                }
                
                // Execute update if we have an update statement (for approve action)
                if ($updateStmt) {
                    if (!$updateStmt->execute()) {
                        throw new Exception("Database update failed.");
                    }
                    $updateStmt->close();
                }
                
                $conn->commit();
                
                // Add email status to message
                $email_status = $email_sent ? " Email notification sent to trainee." : " Email notification failed.";
                $notification_status = isset($notificationResult) && $notificationResult['success'] ? 
                    " Notification recorded." : " Notification failed.";
                $_SESSION['success'] = $message . $email_status . $notification_status;
                
                // Refresh the page to show updated data
                ob_end_clean();
                header("Location: enrollment-management.php");
                exit;
                
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error'] = "Error: " . $e->getMessage();
            }
        }
    }
    
    // Handle simple enrollment status updates (from search results)
    if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        $enrollment_id = $_POST['enrollment_id'] ?? null;
        $status = $_POST['status'] ?? null;
        
        if ($enrollment_id && $status) {
            try {
                $conn->begin_transaction();
                
                // Get enrollment details before any action
                $stmt = $conn->prepare("SELECT e.*, p.name as program_name, p.scheduleStart, p.scheduleEnd, p.trainer, p.duration,
                                       t.email, t.fullname, t.user_id
                                       FROM enrollments e 
                                       JOIN programs p ON e.program_id = p.id 
                                       JOIN trainees t ON e.user_id = t.user_id 
                                       WHERE e.id = ?");
                $stmt->bind_param("i", $enrollment_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $enrollment = $result->fetch_assoc();
                
                $email_sent = false;
                $notificationResult = null;
                
                if ($status === 'approved') {
                    // Update enrollment status
                    if (updateEnrollmentStatus($enrollment_id, $status)) {
                        // Create notification for trainee
                        $notificationResult = createNotification(
                            $enrollment['user_id'],
                            'success',
                            'Enrollment Approved',
                            "Your enrollment for '{$enrollment['program_name']}' has been approved.",
                            $enrollment_id,
                            'enrollment'
                        );
                        
                        $program_details = [
                            'scheduleStart' => $enrollment['scheduleStart'] ?? '',
                            'scheduleEnd' => $enrollment['scheduleEnd'] ?? '',
                            'trainer' => $enrollment['trainer'] ?? '',
                            'duration' => $enrollment['duration'] ?? ''
                        ];
                        
                        $email_sent = sendEnrollmentApprovalEmail(
                            $enrollment['email'],
                            $enrollment['fullname'],
                            $enrollment['program_name'],
                            $program_details
                        );
                        
                        $message = "Enrollment approved successfully!";
                    }
                    
                } elseif ($status === 'rejected') {
                    // Create notification for trainee
                    $notificationResult = createNotification(
                        $enrollment['user_id'],
                        'danger',
                        'Enrollment Rejected',
                        "Your enrollment for '{$enrollment['program_name']}' has been rejected.",
                        $enrollment_id,
                        'enrollment'
                    );
                    
                    // Send rejection email
                    $email_sent = sendEnrollmentRejectionEmail(
                        $enrollment['email'],
                        $enrollment['fullname'],
                        $enrollment['program_name'],
                        "Please contact the administrator for details."
                    );
                    
                    // DELETE the enrollment
                    $deleteStmt = $conn->prepare("DELETE FROM enrollments WHERE id = ?");
                    $deleteStmt->bind_param("i", $enrollment_id);
                    
                    if ($deleteStmt->execute()) {
                        $message = "Enrollment rejected and deleted from system!";
                    } else {
                        throw new Exception("Failed to delete enrollment.");
                    }
                    $deleteStmt->close();
                }
                
                $conn->commit();
                
                $email_status = $email_sent ? " Email notification sent." : "";
                $notification_status = $notificationResult && $notificationResult['success'] ? 
                    " Notification recorded." : "";
                $_SESSION['success'] = $message . $email_status . $notification_status;
                
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error'] = "Error: " . $e->getMessage();
            }
        }
        
        ob_end_clean();
        header("Location: enrollment-management.php");
        exit;
    }
    
    // Handle search
    if (isset($_POST['search']) && !empty($_POST['search_term'])) {
        $search_term = $_POST['search_term'] ?? '';
        $search_results = searchEverything($search_term);
        $search_performed = true;
    }
}

// Handle AJAX request for trainee details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_trainee_details' && isset($_GET['user_id'])) {
    $user_id = $_GET['user_id'];
    $trainee = getTraineeDetailsByUserId($user_id);
    $enrollments = getTraineeEnrollmentHistory($user_id);
    
    if ($trainee) {
        echo json_encode([
            'success' => true,
            'trainee' => $trainee,
            'enrollments' => $enrollments
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Trainee not found'
        ]);
    }
    exit;
}

// Handle AJAX request for application details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_application_details' && isset($_GET['id'])) {
    $id = $_GET['id'] ?? 0;
    $application = getApplicationDetails($id);
    
    if ($application) {
        ob_start();
        ?>
        <div class="row">
            <div class="col-md-6">
                <h6><i class="fas fa-user"></i> Applicant Information</h6>
                <table class="table table-sm">
                    <tr><th>Full Name:</th><td><?php echo htmlspecialchars($application['fullname']); ?></td></tr>
                    <tr><th>Email:</th><td><?php echo htmlspecialchars($application['email']); ?></td></tr>
                    <tr><th>Contact:</th><td><?php echo htmlspecialchars($application['contact_number'] ?? 'N/A'); ?></td></tr>
                    <tr><th>Address:</th><td><?php echo htmlspecialchars($application['address'] ?? 'N/A'); ?></td></tr>
                    <tr><th>Gender:</th><td><?php echo htmlspecialchars($application['gender'] ?? 'N/A'); ?></td></tr>
                    <tr><th>Age:</th><td><?php echo htmlspecialchars($application['age'] ?? 'N/A'); ?></td></tr>
                    <tr><th>Education:</th><td><?php echo htmlspecialchars($application['education'] ?? 'N/A'); ?></td></tr>
                    <tr><th>Barangay:</th><td><?php echo htmlspecialchars($application['barangay'] ?? 'N/A'); ?></td></tr>
                    <tr><th>Municipality:</th><td><?php echo htmlspecialchars($application['municipality'] ?? 'N/A'); ?></td></tr>
                    <tr><th>City:</th><td><?php echo htmlspecialchars($application['city'] ?? 'N/A'); ?></td></tr>
                    <!-- ADD THESE TWO NEW ROWS FOR APPLICANT TYPE AND NC HOLDER -->
                  <tr><th>Applicant Type:</th>
                        <td>
                            <?php 
                            $applicant_type = $application['applicant_type'] ?? '';
                            if (!empty($applicant_type)) {
                                $type_data = json_decode($applicant_type, true);
                                if (is_array($type_data)) {
                                    echo htmlspecialchars(implode(', ', $type_data));
                                } else {
                                    echo htmlspecialchars($applicant_type);
                                }
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </td>
                    </tr>

                    <tr>
                        <th>NC Holder:</th>
                        <td>
                            <?php 
                            $nc_holder = $application['nc_holder'] ?? '';
                            if (!empty($nc_holder)) {
                                $nc_data = json_decode($nc_holder, true);
                                if (is_array($nc_data)) {
                                    echo htmlspecialchars(implode(', ', $nc_data));
                                } else {
                                    echo htmlspecialchars($nc_holder);
                                }
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
                
                <!-- DOCUMENTS SECTION -->
                <h6><i class="fas fa-file-alt"></i> Submitted Documents</h6>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin-top: 10px; border-left: 4px solid #3498db;">
                    <?php if (!empty($application['documents'])): ?>
                        
                        <?php if (!empty($application['documents']['valid_id'])): ?>
                        <div style="margin-bottom: 15px;">
                            <strong>Valid ID:</strong>
                            <div style="margin-top: 5px;">
                                <?php foreach($application['documents']['valid_id'] as $doc): ?>
                                <div style="display: flex; align-items: center; padding: 8px; background: white; border-radius: 4px; border: 1px solid #ddd; margin-bottom: 5px;">
                                    <i class="fas fa-file-pdf" style="color: #e74c3c; margin-right: 10px;"></i>
                                    <span style="flex-grow: 1;">ID Document</span>
                                    <a href="../imageFile/<?php echo htmlspecialchars($doc); ?>" target="_blank" class="btn btn-sm btn-outline" style="margin-right: 5px;">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div style="color: #f39c12; margin-bottom: 10px;">
                            <i class="fas fa-exclamation-triangle"></i> No Valid ID submitted
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($application['documents']['voters_certificate'])): ?>
                        <div>
                            <strong>Voter's Certificate/Residency:</strong>
                            <div style="margin-top: 5px;">
                                <?php foreach($application['documents']['voters_certificate'] as $doc): ?>
                                <div style="display: flex; align-items: center; padding: 8px; background: white; border-radius: 4px; border: 1px solid #ddd; margin-bottom: 5px;">
                                    <i class="fas fa-file-image" style="color: #3498db; margin-right: 10px;"></i>
                                    <span style="flex-grow: 1;">Residency Document</span>
                                    <a href="../imageFile/<?php echo htmlspecialchars($doc); ?>" target="_blank" class="btn btn-sm btn-outline" style="margin-right: 5px;">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div style="color: #f39c12;">
                            <i class="fas fa-exclamation-triangle"></i> No Voter's Certificate/Residency submitted
                        </div>
                        <?php endif; ?>
                        
                    <?php else: ?>
                    <div style="color: #f39c12;">
                        <i class="fas fa-exclamation-triangle"></i> No documents submitted by this applicant.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-md-6">
                <h6><i class="fas fa-graduation-cap"></i> Program Information</h6>
                <table class="table table-sm">
                    <tr><th>Program:</th><td><?php echo htmlspecialchars($application['program_name']); ?></td></tr>
                    <tr><th>Duration:</th><td><?php echo htmlspecialchars($application['duration']); ?> days</td></tr>
                    <tr><th>Schedule:</th><td>
                        <?php echo date('M j, Y', strtotime($application['scheduleStart'])); ?> - 
                        <?php echo date('M j, Y', strtotime($application['scheduleEnd'])); ?>
                    </td></tr>
                    <tr><th>Trainer:</th><td><?php echo htmlspecialchars($application['trainer']); ?></td></tr>
                    <tr><th>Slots:</th><td><?php echo $application['slotsAvailable']; ?> available / <?php echo $application['total_slots']; ?> total</td></tr>
                </table>
                
                <h6><i class="fas fa-history"></i> Application Status</h6>
                <table class="table table-sm">
                    <tr><th>Applied:</th><td><?php echo date('M j, Y g:i A', strtotime($application['applied_at'])); ?></td></tr>
                    <?php if (!empty($application['rejection_reason'])): ?>
                        <tr><th>Rejection Reason:</th><td><?php echo nl2br(htmlspecialchars($application['rejection_reason'])); ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($application['admin_notes'])): ?>
                        <tr><th>Admin Notes:</th><td><?php echo nl2br(htmlspecialchars($application['admin_notes'])); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php
        echo ob_get_clean();
    } else {
        echo '<div class="alert alert-danger">Application not found</div>';
    }
    exit;
}

// Clear the output buffer before including header
ob_end_flush();

// Include header AFTER all PHP logic
include '../components/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrollment Management - Livelihood Program</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --accent: #e74c3c;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light: #ecf0f1;
            --dark: #2c3e50;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 15px;
        }
        
        .subtitle {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .admin-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 4px solid var(--secondary);
        }
        
        .stat-card:nth-child(1) { border-left-color: #f6ae07ff; }
        .stat-card:nth-child(2) { border-left-color: #9423d9ff; }
        .stat-card:nth-child(3) { border-left-color: #27ae60; }
        .stat-card:nth-child(4) { border-left-color: #f39c12; }
        
        .stat-card h3 {
            color: var(--primary);
            margin-bottom: 8px;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
        }
        
        .stat-card:nth-child(1) .stat-value { color: #f6ae07ff; }
        .stat-card:nth-child(2) .stat-value { color: #9423d9ff; }
        .stat-card:nth-child(3) .stat-value { color: #27ae60; }
        .stat-card:nth-child(4) .stat-value { color: #f39c12; }
        
        /* Alert Messages */
        .alert {
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 5px;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        /* Main Content Layout */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        @media (max-width: 1100px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Card Styles */
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 15px;
        }
        
        .card-header {
            padding: 12px 15px;
            background: var(--light);
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .card-header h2 {
            color: var(--primary);
            font-size: 1.1rem;
            margin: 0;
        }
        
        .card-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.3s;
            text-decoration: none;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        
        .btn-primary {
            background: var(--secondary);
            color: white;
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-warning {
            background: var(--warning);
            color: white;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid #ddd;
            color: #7f8c8d;
        }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        
        /* Table Styles */
        .table-container {
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }
        
        th, td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
            font-size: 0.9rem;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--primary);
            position: sticky;
            top: 0;
            white-space: nowrap;
        }
        
        tr:hover {
            background: #f5f7fa;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-approved {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-revision_needed {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .action-buttons {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 4px 6px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.75rem;
            white-space: nowrap;
        }
        
        /* Program Cards */
        .programs-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
            margin-top: 15px;
            padding: 0 15px 15px;
        }
        
        .program-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid var(--secondary);
            transition: transform 0.3s;
        }
        
        .program-card:hover {
            transform: translateY(-3px);
        }
        
        .program-card h3 {
            color: var(--primary);
            margin-bottom: 8px;
            font-size: 1.1rem;
        }
        
        .program-meta {
            display: flex;
            justify-content: space-between;
            margin-top: 12px;
            font-size: 0.85rem;
            color: #7f8c8d;
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .program-stats {
            display: flex;
            gap: 12px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        
        .stat {
            text-align: center;
            min-width: 60px;
        }
        
        .program-stat-value {
            font-size: 1.3rem;
            font-weight: bold;
            color: var(--secondary);
        }
        
        .stat-label {
            font-size: 0.75rem;
            color: #7f8c8d;
        }
        
        /* Search Form Styles */
        .search-box {
            width: 100%;
            margin-bottom: 25px;
        }

        .search-input-row {
            display: flex;
            gap: 12px;
            align-items: stretch;
            flex-wrap: wrap;
        }

        .search-input-row input[type="text"] {
            flex: 3;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            background: white;
            transition: border-color 0.3s ease;
            min-width: 200px;
        }

        .search-input-row input[type="text"]:focus {
            outline: none;
            border-color: var(--secondary);
        }

        .search-input-row .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 0.9rem;
            white-space: nowrap;
            min-width: fit-content;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid #ddd;
            margin-bottom: 15px;
            flex-wrap: wrap;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .tab {
            padding: 8px 15px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
            font-size: 0.9rem;
        }
        
        .tab.active {
            border-bottom: 3px solid var(--secondary);
            color: var(--secondary);
            font-weight: 600;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .tab-badge {
            background: var(--secondary);
            color: white;
            border-radius: 8px;
            padding: 2px 6px;
            font-size: 0.65rem;
            margin-left: 6px;
        }
        
        /* Enhanced Table Styles */
        .table-header-info {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            background: #f8f9fa;
        }

        .program-info h3 {
            color: var(--primary);
            margin-bottom: 6px;
            font-size: 1.1rem;
        }

        .program-details {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .program-details span {
            font-size: 0.85rem;
            color: #666;
        }

        .trainee-stats {
            display: flex;
            gap: 8px;
        }

        .stat-pill {
            background: white;
            padding: 8px 12px;
            border-radius: 15px;
            text-align: center;
            border: 1px solid #e0e0e0;
        }

        .stat-pill.approved {
            border-color: var(--success);
            background: #d4edda;
        }

        .stat-pill.pending {
            border-color: var(--warning);
            background: #fff3cd;
        }

        .stat-pill.revision_needed {
            border-color: #f39c12;
            background: #fff8e1;
        }

        .stat-number {
            display: block;
            font-size: 1.1rem;
            font-weight: bold;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.75rem;
            color: #666;
        }
        
        /* Approval Filter Styles */
        .approval-filter {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .approval-filter h5 {
            margin-bottom: 15px;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .approval-filter form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 500;
            color: #555;
        }

        .form-control {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary);
        }

        .btn-sm {
            padding: 8px 15px;
            font-size: 0.85rem;
        }

        /* Badge Styles */
        .badge {
            display: inline-block;
            padding: 4px 8px;
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 10px;
            color: white;
        }

        .badge-pending {
            background: var(--warning);
        }

        .badge-approved {
            background: var(--success);
        }

        .badge-rejected {
            background: var(--danger);
        }

        .badge-revision_needed {
            background: #ffc107;
            color: #212529;
        }

        .badge-waiting {
            background: #17a2b8;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-content {
            background: white;
            padding: 20px;
            border-radius: 8px;
            width: 100%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .modal-header h3 {
            color: var(--primary);
            font-size: 1.2rem;
            margin: 0;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #7f8c8d;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 30px 15px;
            color: #7f8c8d;
        }
        
        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 12px;
            color: #bdc3c7;
        }
        
        /* Enhanced Mobile Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header-content {
                flex-direction: column;
                text-align: center;
                gap: 12px;
                padding: 0 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .search-input-row {
                flex-direction: column;
            }
            
            .search-input-row input[type="text"] {
                width: 100%;
            }
            
            .approval-filter form {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                max-height: 350px;
            }
            
            th, td {
                padding: 8px 10px;
                font-size: 0.85rem;
            }
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 8px;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .card-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .btn {
                padding: 6px 10px;
                font-size: 0.8rem;
            }
        }
        
        /* Trainee row enhancements */
        .trainee-details {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 5px;
        }
        
        .detail-tag {
            background: #f0f0f0;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            color: #555;
        }
        
        .contact-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 5px;
        }
        
        .contact-item i {
            width: 16px;
            color: #666;
        }
        
        .enrollment-info div {
            margin-bottom: 5px;
            font-size: 0.85rem;
        }
        
        .no-print {
            display: none;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            body * {
                visibility: hidden;
            }
            
            .printable, .printable * {
                visibility: visible;
            }
            
            .printable {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    
    <header>
        <div class="container">
            <h1>Enrollment Management</h1>
        </div>
    </header>
    
    <div class="container">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Programs</h3>
                <div class="stat-value"><?php echo $stats['total_programs']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Enrollments</h3>
                <div class="stat-value"><?php echo $stats['total_enrollments']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Pending Approvals</h3>
                <div class="stat-value"><?php echo $stats['pending_enrollments']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Revision Needed</h3>
                <div class="stat-value"><?php echo $stats['revision_needed']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Approved Trainees</h3>
                <div class="stat-value"><?php echo $stats['approved_enrollments']; ?></div>
            </div>
        </div>

        <!-- Search Form -->
        <form method="POST" class="search-box">
            <input type="hidden" name="search" value="1">
            <div class="search-input-row">
                <input type="text" name="search_term" placeholder="Search across everything: trainees, programs, enrollments..." 
                       value="<?php echo isset($_POST['search_term']) ? htmlspecialchars($_POST['search_term']) : ''; ?>">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search Everything</button>
                <button type="button" class="btn btn-outline" onclick="window.location.href='enrollment-management.php'">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </form>

        <!-- Display Search Results -->
        <?php if ($search_performed): ?>
            <div class="search-results-container">
                <?php 
                $total_results = count($search_results['enrollments']) + count($search_results['programs']) + count($search_results['trainees']);
                ?>
                
                <?php if ($total_results > 0): ?>
                    <div class="search-results-header" style="margin-bottom: 20px; padding: 15px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h2 style="margin: 0 0 10px 0; color: var(--primary);">
                            <i class="fas fa-search"></i> Search Results
                        </h2>
                        <p style="margin: 0; color: #666;">
                            Found <strong><?php echo $total_results; ?></strong> results for "<strong><?php echo htmlspecialchars($_POST['search_term']); ?></strong>"
                        </p>
                    </div>

                    <!-- Enrollments Results -->
                    <?php if (count($search_results['enrollments']) > 0): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-file-signature"></i> Enrollments <span class="badge bg-primary"><?php echo count($search_results['enrollments']); ?></span></h3>
                            </div>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Program</th>
                                            <th>Contact</th>
                                            <th>Email</th>
                                            <th>Applied Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($search_results['enrollments'] as $enrollment): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($enrollment['fullname']); ?></td>
                                                <td><?php echo htmlspecialchars($enrollment['program_name']); ?></td>
                                                <td><?php echo htmlspecialchars($enrollment['contact_number'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($enrollment['email'] ?? 'N/A'); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($enrollment['applied_at'])); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $enrollment['enrollment_status']; ?>">
                                                        <?php echo ucfirst($enrollment['enrollment_status']); ?>
                                                    </span>
                                                </td>
                                                <td class="action-buttons">
                                                    <?php if ($enrollment['enrollment_status'] === 'pending'): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="enrollment_id" value="<?php echo $enrollment['id']; ?>">
                                                            <input type="hidden" name="status" value="approved">
                                                            <button type="submit" class="action-btn btn-success" title="Approve">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        </form>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="enrollment_id" value="<?php echo $enrollment['id']; ?>">
                                                            <input type="hidden" name="status" value="rejected">
                                                            <button type="submit" class="action-btn btn-danger" title="Reject">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Programs Results -->
                    <?php if (count($search_results['programs']) > 0): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-graduation-cap"></i> Programs <span class="badge bg-primary"><?php echo count($search_results['programs']); ?></span></h3>
                            </div>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Program Name</th>
                                            <th>Duration</th>
                                            <th>Trainer</th>
                                            <th>Slots Available</th>
                                            <th>Enrolled</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($search_results['programs'] as $program): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($program['name']); ?></td>
                                                <td><?php echo $program['duration'] . ' ' . ($program['durationUnit'] ?? 'Days'); ?></td>
                                                <td><?php echo htmlspecialchars($program['trainer'] ?? 'N/A'); ?></td>
                                                <td><?php echo $program['slotsAvailable'] ?? 0; ?></td>
                                                <td><?php echo $program['enrolled_count'] ?? 0; ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $program['status'] ?? 'active'; ?>">
                                                        <?php echo ucfirst($program['status'] ?? 'Active'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Trainees Results -->
                    <?php if (count($search_results['trainees']) > 0): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-users"></i> Trainees <span class="badge bg-primary"><?php echo count($search_results['trainees']); ?></span></h3>
                            </div>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Contact</th>
                                            <th>Email</th>
                                            <th>Enrollments</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($search_results['trainees'] as $trainee): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($trainee['fullname']); ?></td>
                                                <td><?php echo htmlspecialchars($trainee['contact_number'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($trainee['email'] ?? 'N/A'); ?></td>
                                                <td><?php echo $trainee['enrollment_count'] ?? 0; ?></td>
                                                <td class="action-buttons">
                                                    <button class="action-btn btn-primary" onclick="viewTraineeDetailsByUserId('<?php echo $trainee['user_id']; ?>')">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <h3>No Results Found</h3>
                        <p>No matches found for "<?php echo htmlspecialchars($_POST['search_term']); ?>". Try different keywords.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Enrollment Approvals Card -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-user-check"></i> Enrollment Approvals</h2>
                <div class="card-actions">
                    <span class="badge bg-primary"><?php echo count($approval_enrollments); ?> applications</span>
                </div>
            </div>
            
            <div class="approval-filter">
                <h5><i class="fas fa-filter"></i> Filters</h5>
                <form method="GET">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="approval_status" class="form-control">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="revision_needed" <?php echo $filter_status === 'revision_needed' ? 'selected' : ''; ?>>Revision Needed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Program</label>
                        <select name="approval_program" class="form-control">
                            <option value="all">All Programs</option>
                            <?php foreach ($approval_programs as $program): ?>
                                <option value="<?php echo $program['id']; ?>" 
                                    <?php echo $filter_program == $program['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($program['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="approval_date" class="form-control" value="<?php echo $filter_date; ?>">
                    </div>
                    <div class="form-group" style="display: flex; align-items: flex-end;">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="table-container">
                <?php if (empty($approval_enrollments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle fa-3x text-success mb-2"></i>
                        <h5>No enrollment applications found</h5>
                        <p class="text-muted">All applications have been processed or no applications match your filters.</p>
                    </div>
                <?php else: ?>
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Applicant</th>
                                <th>Program</th>
                                <th>Applied Date</th>
                                <th>Status</th>
                                <th>Slots</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approval_enrollments as $enrollment): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($enrollment['fullname']); ?></strong><br>
                                        <small class="text-muted">
                                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($enrollment['email']); ?><br>
                                            <i class="fas fa-phone"></i> <?php echo htmlspecialchars($enrollment['contact_number'] ?? 'N/A'); ?>
                                        </small>
                                    </td>
                                    <td><?php echo htmlspecialchars($enrollment['program_name']); ?></td>
                                    <td><?php echo date('M j, Y g:i A', strtotime($enrollment['applied_at'])); ?></td>
                                    <td>
                                        <?php
                                        $status_class = '';
                                        switch($enrollment['enrollment_status']) {
                                            case 'approved': $status_class = 'badge-approved'; break;
                                            case 'rejected': $status_class = 'badge-rejected'; break;
                                            case 'pending': $status_class = 'badge-pending'; break;
                                            case 'revision_needed': $status_class = 'badge-revision_needed'; break;
                                        }
                                        ?>
                                        <span class="badge <?php echo $status_class; ?>">
                                            <?php echo ucfirst($enrollment['enrollment_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $enrollment['slotsAvailable']; ?> available
                                    </td>
                                    <td class="action-buttons">
                                        <?php if ($enrollment['enrollment_status'] === 'pending'): ?>
                                            <button class="btn btn-success btn-sm" 
                                                    onclick="approveEnrollment(<?php echo $enrollment['id']; ?>)">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                            <button class="btn btn-danger btn-sm" 
                                                    onclick="rejectEnrollment(<?php echo $enrollment['id']; ?>)">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                            <button class="btn btn-warning btn-sm" 
                                                    onclick="requestRevision(<?php echo $enrollment['id']; ?>)">
                                                <i class="fas fa-edit"></i> Revision
                                            </button>
                                            <button class="btn btn-info btn-sm" 
                                                    onclick="viewApplicationDetails(<?php echo $enrollment['id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        <?php elseif ($enrollment['enrollment_status'] === 'revision_needed'): ?>
                                            <span class="text-muted">Waiting for trainee revision</span>
                                            <button class="btn btn-info btn-sm" 
                                                    onclick="viewApplicationDetails(<?php echo $enrollment['id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">Already <?php echo $enrollment['enrollment_status']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-grid">
            <!-- Recently Added Programs -->
            <div class="card">
                <div class="card-header">
                    <h2>Recently Added Programs</h2>
                    <div class="card-actions">
                        <a href="programs-management.php" class="btn btn-outline">View All</a>
                    </div>
                </div>
                <div class="programs-container">
                    <?php if (count($recent_programs) > 0): ?>
                        <?php foreach($recent_programs as $program): ?>
                            <div class="program-card">
                                <h3><?php echo htmlspecialchars($program['name']); ?></h3>
                                <div class="program-meta">
                                    <span><i class="fas fa-calendar"></i> 
                                        <?php echo isset($program['scheduleStart']) ? date('M j, Y', strtotime($program['scheduleStart'])) : 'Not set'; ?>
                                    </span>
                                    <span class="status-badge status-<?php echo $program['status'] ?? 'active'; ?>">
                                        <?php echo ucfirst($program['status'] ?? 'Active'); ?>
                                    </span>
                                </div>
                                <div class="program-stats">
                                    <div class="stat">
                                        <div class="program-stat-value"><?php echo $program['enrolled_count'] ?? 0; ?></div>
                                        <div class="stat-label">Enrolled</div>
                                    </div>
                                    <div class="stat">
                                        <div class="program-stat-value">
                                            <?php 
                                                $slots_available = $program['slotsAvailable'] ?? 0;
                                                echo $slots_available > 0 ? $slots_available : 'Unlimited';
                                            ?>
                                        </div>
                                        <div class="stat-label">Slots</div>
                                    </div>
                                    <div class="stat">
                                        <div class="program-stat-value">
                                            <?php 
                                                $duration = $program['duration'] ?? 'N/A';
                                                echo is_numeric($duration) ? $duration : $duration;
                                            ?>
                                        </div>
                                        <div class="stat-label">Days</div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <h3>No Programs Found</h3>
                            <p>There are no active programs in the system.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Newly Qualified Trainees -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-user-check"></i> Newly Qualified Trainees</h2>
                </div>
                <div class="table-container">
                    <?php if (count($latest_approved_trainees) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Program</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($latest_approved_trainees as $trainee): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($trainee['fullname']); ?></td>
                                        <td><?php echo htmlspecialchars($trainee['program_name']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <h3>No Newly Qualified Trainees</h3>
                            <p>There are no newly qualified trainees in the system yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Trainees by Program -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-users"></i> Trainees by Program</h2>
                <div class="card-actions">
                    <button class="btn btn-outline" onclick="printTable(event)"><i class="fas fa-print"></i> Print Report</button>
                </div>
            </div>
            
            <div class="tabs">
                <?php if (is_array($all_programs) && count($all_programs) > 0): ?>
                    <?php foreach($all_programs as $index => $program): ?>
                        <?php if ($program['status'] === 'active'): ?>
                            <div class="tab <?php echo $index === 0 ? 'active' : ''; ?>" 
                                 onclick="switchTab('program-<?php echo $program['id']; ?>')">
                                <?php echo htmlspecialchars($program['name']); ?>
                                <span class="tab-badge"><?php 
                                    $trainees_count = count(getTraineesByProgram($program['id']));
                                    echo $trainees_count > 0 ? $trainees_count : '0';
                                ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="tab active">No Active Programs Available</div>
                <?php endif; ?>
            </div>
            
            <?php if (is_array($all_programs) && count($all_programs) > 0): ?>
                <?php foreach($all_programs as $index => $program): ?>
                    <?php if ($program['status'] === 'active'): ?>
                        <div class="tab-content <?php echo $index === 0 ? 'active' : ''; ?>" id="program-<?php echo $program['id']; ?>">
                            <?php
                            $trainees = getTraineesByProgram($program['id']);
                            ?>
                            <div class="table-header-info">
                                <div class="program-info">
                                    <h3><?php echo htmlspecialchars($program['name']); ?></h3>
                                    <div class="program-details">
                                        <span><i class="fas fa-calendar"></i> 
                                            <?php echo isset($program['scheduleStart']) ? date('M j, Y', strtotime($program['scheduleStart'])) : 'Not set'; ?>
                                            <?php if (isset($program['scheduleEnd'])): ?>
                                                - <?php echo date('M j, Y', strtotime($program['scheduleEnd'])); ?>
                                            <?php endif; ?>
                                        </span>
                                        <span><i class="fas fa-chalkboard-teacher"></i> <?php echo htmlspecialchars($program['trainer'] ?? 'No trainer'); ?></span>
                                        <span><i class="fas fa-clock"></i> <?php echo $program['duration'] . ' ' . ($program['durationUnit'] ?? 'Days'); ?></span>
                                    </div>
                                </div>
                                <div class="trainee-stats">
                                    <div class="stat-pill">
                                        <span class="stat-number"><?php echo count($trainees); ?></span>
                                        <span class="stat-label">Total</span>
                                    </div>
                                  
                                </div>
                            </div>
                            
                            <div class="table-container">
                                <?php if (count($trainees) > 0): ?>
                                    <table class="printable enhanced-table">
                                        <thead>
                                            <tr>
                                                <th class="trainee-info">Trainee Information</th>
                                                <th class="contact-info">Contact Details</th>
                                                <th class="enrollment-info">Enrollment</th>
                                                <th class="status-col">Status</th>
                                                <th class="actions-col no-print">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($trainees as $trainee): ?>
                                                <tr class="trainee-row" 
                                                    data-trainee-id="<?php echo $trainee['trainee_id'] ?? ''; ?>"
                                                    data-education="<?php echo htmlspecialchars($trainee['education'] ?? 'N/A'); ?>"
                                                    data-barangay="<?php echo htmlspecialchars($trainee['barangay'] ?? 'N/A'); ?>"
                                                    data-municipality="<?php echo htmlspecialchars($trainee['municipality'] ?? 'N/A'); ?>"
                                                    data-city="<?php echo htmlspecialchars($trainee['city'] ?? 'N/A'); ?>"
                                                    data-address="<?php echo htmlspecialchars($trainee['full_address'] ?? ''); ?>"
                                                    data-training-attended="<?php echo isset($trainee['attendance']) && $trainee['attendance'] == 'completed' ? 'Yes' : 'No'; ?>"
                                                    data-toolkit-received="<?php echo isset($trainee['toolkit_received']) ? ($trainee['toolkit_received'] == 1 ? 'Yes' : 'No') : 'No'; ?>">
                                                    <td class="trainee-info">
                                                        <div class="trainee-main">
                                                            <div class="trainee-name"><?php echo htmlspecialchars($trainee['fullname']); ?></div>
                                                        </div>
                                                        <div class="trainee-details">
                                                            <?php if (isset($trainee['gender']) && $trainee['gender']): ?>
                                                                <span class="detail-tag"><i class="fas fa-venus-mars"></i> <?php echo htmlspecialchars($trainee['gender']); ?></span>
                                                            <?php endif; ?>
                                                            <?php if (isset($trainee['age']) && $trainee['age']): ?>
                                                                <span class="detail-tag"><i class="fas fa-birthday-cake"></i> <?php echo $trainee['age']; ?> yrs</span>
                                                            <?php endif; ?>
                                                            <?php if (isset($trainee['education']) && $trainee['education']): ?>
                                                                <span class="detail-tag"><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($trainee['education']); ?></span>
                                                            <?php endif; ?>
                                                            <?php if (isset($trainee['barangay']) && $trainee['barangay']): ?>
                                                                <span class="detail-tag"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($trainee['barangay']); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="contact-info">
                                                        <div class="contact-item">
                                                            <i class="fas fa-phone"></i>
                                                            <span><?php echo htmlspecialchars($trainee['contact_number'] ?? 'N/A'); ?></span>
                                                        </div>
                                                        <div class="contact-item">
                                                            <i class="fas fa-envelope"></i>
                                                            <span><?php echo htmlspecialchars($trainee['email'] ?? 'N/A'); ?></span>
                                                        </div>
                                                        <div class="contact-item">
                                                            <i class="fas fa-map-marker-alt"></i>
                                                            <span><?php echo htmlspecialchars(($trainee['municipality'] ?? 'N/A') . ', ' . ($trainee['city'] ?? 'N/A')); ?></span>
                                                        </div>
                                                    </td>
                                                    <td class="enrollment-info">
                                                        <div class="enrollment-date">
                                                            <strong>Applied:</strong>
                                                            <?php echo date('M j, Y', strtotime($trainee['applied_at'])); ?>
                                                        </div>
                                                        <div class="training-info">
                                                            <strong>Training:</strong>
                                                           <?php echo ucfirst($trainee['enrollment_status']); ?>
                                                        </div>
                                                        <div class="toolkit-info">
                                                            <strong>Toolkit:</strong>
                                                            <?php echo isset($trainee['toolkit_received']) && $trainee['toolkit_received'] == 1 ? 'Received' : 'Not Received'; ?>
                                                        </div>
                                                    </td>
                                                    <td class="status-col">
                                                        <span class="status-badge status-<?php echo $trainee['enrollment_status']; ?>">
                                                            <i class="fas fa-circle"></i>
                                                            <?php echo ucfirst($trainee['enrollment_status']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="actions-col no-print">
                                                        <div class="action-buttons">
                                                            <button class="action-btn btn-primary" onclick="viewTraineeDetailsByUserId('<?php echo $trainee['user_id']; ?>')" title="View Complete Details">
                                                                <i class="fas fa-eye"></i> View
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
                                        <h3>No Trainees Enrolled</h3>
                                        <p>There are no trainees enrolled in this program yet.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>No Active Programs Available</h3>
                    <p>There are no active programs in the system.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Approval System Modals -->
    <!-- View Details Modal -->
    <div class="modal" id="viewModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Application Details</h3>
                <button type="button" class="close-modal" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body" id="applicationDetails">
                Loading...
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div class="modal" id="rejectModal">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h3>Reject Application</h3>
                <button type="button" class="close-modal" onclick="closeRejectModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="enrollment_id" id="rejectEnrollmentId">
                    <input type="hidden" name="action" value="reject">
                    
                    <div class="form-group">
                        <label>Reason for Rejection <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" class="form-control" rows="3" required 
                                  placeholder="Please provide a reason for rejecting this application..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Admin Notes (Optional)</label>
                        <textarea name="admin_notes" class="form-control" rows="2" 
                                  placeholder="Additional notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Approve Modal -->
    <div class="modal" id="approveModal">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h3>Approve Application</h3>
                <button type="button" class="close-modal" onclick="closeApproveModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="enrollment_id" id="approveEnrollmentId">
                    <input type="hidden" name="action" value="approve">
                    
                    <div class="form-group">
                        <label>Admin Notes (Optional)</label>
                        <textarea name="admin_notes" class="form-control" rows="3" 
                                  placeholder="Add notes for the applicant..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> This action will approve the enrollment.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeApproveModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Approval</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Request Revision Modal -->
    <div class="modal" id="revisionModal">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h3><i class="fas fa-edit"></i> Request Revision</h3>
                <button type="button" class="close-modal" onclick="closeRevisionModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="enrollment_id" id="revisionEnrollmentId">
                    <input type="hidden" name="action" value="request_revision">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        This will move the enrollment to "Revision Needed" status and send an email to the trainee asking them to update their profile.
                    </div>
                    
                    <div class="form-group">
                        <label>Reason for Revision <span class="text-danger">*</span></label>
                        <textarea name="revision_reason" class="form-control" rows="4" required 
                                  placeholder="Please specify what needs to be revised (e.g., missing documents, incorrect information, additional details needed)..."></textarea>
                        <small class="text-muted">This reason will be included in the email sent to the trainee.</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Admin Notes (Optional)</label>
                        <textarea name="admin_notes" class="form-control" rows="2" 
                                  placeholder="Internal notes for administrators..."></textarea>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Note:</strong> The trainee will receive an email with a link to their profile page. 
                        After they update their profile, the enrollment will automatically return to pending status.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeRevisionModal()">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-paper-plane"></i> Send Revision Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Trainee Details Modal -->
    <div id="traineeDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user"></i> Trainee Complete Details</h3>
                <button type="button" class="close-modal" onclick="closeTraineeDetailsModal()">&times;</button>
            </div>
            <div id="traineeDetailsContent" style="padding: 20px;">
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>Loading trainee details...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tab switching functionality
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Activate selected tab
            event.currentTarget.classList.add('active');
        }
        
        // Approval System Functions
        function approveEnrollment(id) {
            document.getElementById('approveEnrollmentId').value = id;
            document.getElementById('approveModal').style.display = 'flex';
        }
        
        function rejectEnrollment(id) {
            document.getElementById('rejectEnrollmentId').value = id;
            document.getElementById('rejectModal').style.display = 'flex';
        }
        
        function requestRevision(id) {
            document.getElementById('revisionEnrollmentId').value = id;
            document.getElementById('revisionModal').style.display = 'flex';
        }
        
        function viewApplicationDetails(id) {
            fetch('enrollment-management.php?ajax=get_application_details&id=' + id)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('applicationDetails').innerHTML = data;
                    document.getElementById('viewModal').style.display = 'flex';
                });
        }
        
        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }
        
        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }
        
        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
        }
        
        function closeRevisionModal() {
            document.getElementById('revisionModal').style.display = 'none';
        }
        
        // View trainee details
        function viewTraineeDetailsByUserId(userId) {
            showTraineeDetailsModal();
            
            // Show loading state
            document.getElementById('traineeDetailsContent').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>Loading trainee details...</p>
                </div>
            `;
            
            // Make AJAX call to fetch trainee details
            fetch('enrollment-management.php?ajax=get_trainee_details&user_id=' + encodeURIComponent(userId))
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.trainee) {
                        const trainee = data.trainee;
                        let detailsHtml = `
                            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 15px; margin-bottom: 20px;">
                                <div style="font-weight: bold; color: #2c3e50;">Full Name:</div>
                                <div>${trainee.fullname || 'N/A'}</div>
                                
                                <div style="font-weight: bold; color: #2c3e50;">Email:</div>
                                <div>${trainee.email || 'N/A'}</div>
                                
                                <div style="font-weight: bold; color: #2c3e50;">Contact Number:</div>
                                <div>${trainee.contact_number || 'N/A'}</div>
                                
                                <div style="font-weight: bold; color: #2c3e50;">Gender:</div>
                                <div>${trainee.gender || 'N/A'}</div>
                                
                                <div style="font-weight: bold; color: #2c3e50;">Age:</div>
                                <div>${trainee.age || 'N/A'}</div>
                                
                                <div style="font-weight: bold; color: #2c3e50;">Education:</div>
                                <div>${trainee.education || 'N/A'}</div>
                                
                                <div style="font-weight: bold; color: #2c3e50;">Barangay:</div>
                                <div>${trainee.barangay || 'N/A'}</div>
                                
                                <div style="font-weight: bold; color: #2c3e50;">Municipality:</div>
                                <div>${trainee.municipality || 'N/A'}</div>
                                
                                <div style="font-weight: bold; color: #2c3e50;">City:</div>
                                <div>${trainee.city || 'N/A'}</div>
                            </div>
                        `;
                        
                        // Add enrollment history if available
                        if (data.enrollments && data.enrollments.length > 0) {
                            detailsHtml += `
                                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 20px;">
                                    <h4 style="margin-top: 0; color: #2c3e50;"><i class="fas fa-history"></i> Enrollment History</h4>
                                    <ul style="margin: 0; padding-left: 20px;">
                            `;
                            
                            data.enrollments.forEach(enrollment => {
                                const appliedDate = new Date(enrollment.applied_at).toLocaleDateString();
                                const statusClass = enrollment.enrollment_status ? `status-${enrollment.enrollment_status}` : '';
                                detailsHtml += `
                                    <li style="margin-bottom: 8px; padding: 8px; background: white; border-radius: 4px; border-left: 4px solid #3498db;">
                                        <strong>${enrollment.program_name || 'Unknown Program'}</strong><br>
                                        <small>Applied: ${appliedDate}</small><br>
                                        <span class="status-badge ${statusClass}" style="margin-top: 4px; display: inline-block;">
                                            ${enrollment.enrollment_status || 'Unknown'}
                                        </span>
                                    </li>
                                `;
                            });
                            
                            detailsHtml += `
                                    </ul>
                                </div>
                            `;
                        }
                        
                        document.getElementById('traineeDetailsContent').innerHTML = detailsHtml;
                    } else {
                        document.getElementById('traineeDetailsContent').innerHTML = `
                            <div style="text-align: center; padding: 40px; color: #e74c3c;">
                                <i class="fas fa-exclamation-circle fa-2x"></i>
                                <p>Error: ${data.message || 'Trainee not found'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error fetching trainee details:', error);
                    document.getElementById('traineeDetailsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #e74c3c;">
                            <i class="fas fa-exclamation-circle fa-2x"></i>
                            <p>Network error loading trainee details. Please try again.</p>
                        </div>
                    `;
                });
        }

        function showTraineeDetailsModal() {
            document.getElementById('traineeDetailsModal').style.display = 'flex';
        }

        function closeTraineeDetailsModal() {
            document.getElementById('traineeDetailsModal').style.display = 'none';
        }
        
        // Test Email Configuration
        function testEmailConfig() {
            if (confirm('Test email configuration? This will send a test email to the administrator.')) {
                fetch('test-email.php')
                    .then(response => response.json())
                    .then(data => {
                        alert(data.message);
                    })
                    .catch(error => {
                        alert('Error testing email configuration: ' + error);
                    });
            }
        }
        
        // Enhanced Print function
        function printTable(e) {
            // Prevent any default behavior
            if (e) e.preventDefault();
            
            console.log('Print function called'); // Debug log
            
            const activeTab = document.querySelector('.tab-content.active');
            if (!activeTab) {
                alert('No active tab found! Please select a program tab first.');
                return;
            }
            
            try {
                // Get all trainee rows from the active tab
                const traineeRows = activeTab.querySelectorAll('.trainee-row');
                console.log(`Found ${traineeRows.length} trainee rows`); // Debug log
                
                if (traineeRows.length === 0) {
                    alert('No trainee data to print! This program has no enrolled trainees.');
                    return;
                }
                
                let printRows = '';
                
                // Build a comprehensive table with all trainee details
                traineeRows.forEach((row, index) => {
                    // Extract details from the row
                    const traineeName = row.querySelector('.trainee-name')?.textContent || 'N/A';
                    
                    // Get email
                    let email = 'N/A';
                    const emailElement = row.querySelector('.contact-item:nth-child(2) span');
                    if (emailElement) {
                        email = emailElement.textContent;
                    }
                    
                    // Get contact number
                    let contact = 'N/A';
                    const contactElement = row.querySelector('.contact-item:nth-child(1) span');
                    if (contactElement) {
                        contact = contactElement.textContent;
                    }
                    
                    // Get complete address from data attribute or construct it
                    let address = row.dataset.address || 'N/A';
                    if (address === 'N/A') {
                        const municipality = row.dataset.municipality || '';
                        const city = row.dataset.city || '';
                        if (municipality || city) {
                            address = `${municipality}, ${city}`;
                        }
                    }
                    
                    // Get barangay
                    let barangay = row.dataset.barangay || 'N/A';
                    if (barangay === 'N/A') {
                        const barangayElement = row.querySelector('.trainee-details .detail-tag:nth-child(4)');
                        if (barangayElement) {
                            barangay = barangayElement.textContent.replace('📍', '').trim();
                        }
                    }
                    
                    // Get other details
                    const municipality = row.dataset.municipality || 'N/A';
                    const city = row.dataset.city || 'N/A';
                    const education = row.dataset.education || 'N/A';
                    const trainingAttended = row.dataset.trainingAttended || 'No';
                    const toolkitReceived = row.dataset.toolkitReceived || 'No';
                    
                    // Get applied date
                    let appliedDate = 'N/A';
                    const appliedDateElement = row.querySelector('.enrollment-date');
                    if (appliedDateElement) {
                        appliedDate = appliedDateElement.textContent.replace('Applied:', '').trim();
                    }
                    
                    // Get status
                    let status = 'N/A';
                    let statusClass = '';
                    const statusElement = row.querySelector('.status-badge');
                    if (statusElement) {
                        status = statusElement.textContent.trim();
                        statusClass = status.toLowerCase();
                    }
                    
                    // Clean up data
                    const cleanName = traineeName.trim();
                    const cleanEmail = email.toString().trim();
                    const cleanContact = contact.toString().trim();
                    const cleanAddress = address.toString().trim();
                    const cleanBarangay = barangay.toString().trim();
                    const cleanMunicipality = municipality.toString().trim();
                    const cleanCity = city.toString().trim();
                    const cleanEducation = education.toString().trim();
                    
                    printRows += `
                        <tr>
                            <td style="text-align: center;">${index + 1}</td>
                            <td><strong>${cleanName}</strong></td>
                            <td>${cleanEmail}</td>
                            <td>${cleanContact}</td>
                            <td>${cleanAddress}</td>
                            <td>${cleanBarangay}</td>
                            <td>${cleanMunicipality}</td>
                            <td>${cleanCity}</td>
                            <td>${cleanEducation}</td>
                            <td style="text-align: center;">${trainingAttended}</td>
                            <td style="text-align: center;">${toolkitReceived}</td>
                            <td>${appliedDate}</td>
                            <td><span class="status-${statusClass}">${status}</span></td>
                        </tr>
                    `;
                });
                
                // Get program info
                const programNameElement = activeTab.querySelector('.program-info h3');
                const programName = programNameElement ? programNameElement.textContent : 'Program';
                
                const trainerElement = activeTab.querySelector('.program-details span:nth-child(2)');
                let trainer = 'N/A';
                if (trainerElement) {
                    trainer = trainerElement.textContent.replace('👨‍🏫', '').replace('👩‍🏫', '').trim();
                }
                
                const scheduleElement = activeTab.querySelector('.program-details span:nth-child(1)');
                let schedule = 'N/A';
                if (scheduleElement) {
                    schedule = scheduleElement.textContent.replace('📅', '').trim();
                }
                
                // Create print window
                const printWindow = window.open('', '_blank', 'width=1200,height=800');
                if (!printWindow) {
                    alert('Please allow pop-ups for this site to use the print function.');
                    return;
                }
                
                const printDate = new Date();
                const formattedDate = printDate.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                const formattedTime = printDate.toLocaleTimeString('en-US', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                printWindow.document.write(`
<!DOCTYPE html>
<html>
<head>
    <title>Trainees Report - ${programName}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            margin: 0;
        }
        
        .header {
            display: flex;
            align-items: flex-start;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #2c3e50;
        }
        
        .logo {
            width: 80px;
            height: 80px;
            margin-right: 20px;
            border: 1px solid #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .logo img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        
        .header-info {
            flex: 1;
        }
        
        h1 {
            color: #2c3e50;
            margin: 0 0 10px 0;
            font-size: 24px;
        }
        
        .program-info {
            margin-bottom: 15px;
        }
        
        .program-info p {
            margin: 5px 0;
            font-size: 14px;
        }
        
        .report-date {
            text-align: right;
            font-size: 12px;
            color: #666;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 11px;
        }
        
        th {
            background-color: #2c3e50;
            color: white;
            padding: 8px 6px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #ddd;
        }
        
        td {
            padding: 6px 5px;
            border: 1px solid #ddd;
            vertical-align: top;
            word-wrap: break-word;
        }
        
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .status-approved {
            background: #d4edda;
            color: #155724;
            padding: 2px 6px;
            border-radius: 3px;
            display: inline-block;
            font-size: 10px;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
            padding: 2px 6px;
            border-radius: 3px;
            display: inline-block;
            font-size: 10px;
        }
        
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
            padding: 2px 6px;
            border-radius: 3px;
            display: inline-block;
            font-size: 10px;
        }
        
        .status-revision_needed {
            background: #fff3cd;
            color: #856404;
            padding: 2px 6px;
            border-radius: 3px;
            display: inline-block;
            font-size: 10px;
        }
        
        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
            font-size: 10px;
            color: #666;
            text-align: center;
        }
        
        @media print {
            body {
                padding: 10px;
                font-size: 10px;
            }
            
            .header {
                margin-bottom: 15px;
            }
            
            h1 {
                font-size: 20px;
            }
            
            table {
                font-size: 9px;
            }
            
            th, td {
                padding: 4px 3px;
            }
            
            .no-print {
                display: none !important;
            }
            
            @page {
                size: landscape;
                margin: 0.5cm;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">
            <img src="../css/logo.jpg" alt="Program Logo" onerror="this.style.display='none'; this.parentElement.style.display='none';">
        </div>
        <div class="header-info">
            <h1>TRAINEES REPORT</h1>
            <div class="program-info">
                <p><strong>Program:</strong> ${programName}</p>
                <p><strong>Trainer:</strong> ${trainer}</p>
                <p><strong>Schedule:</strong> ${schedule}</p>
                <p><strong>Total Trainees:</strong> ${traineeRows.length}</p>
            </div>
        </div>
    </div>
    
    <div class="report-date">
        <p><strong>Report Generated:</strong> ${formattedDate} at ${formattedTime}</p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Full Name</th>
                <th>Email Address</th>
                <th>Contact Number</th>
                <th>Complete Address</th>
                <th>Barangay</th>
                <th>Municipality</th>
                <th>City</th>
                <th>Education</th>
                <th>Training Attended</th>
                <th>Toolkit Received</th>
                <th>Applied Date</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            ${printRows}
        </tbody>
    </table>
    
    <div class="footer">
        <p>This report contains all trainee details for "${programName}" - Printed by: System Administrator</p>
        <p>Page 1 of 1</p>
    </div>
    
    <script>
        // Auto-print when the window loads
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    <\/script>
</body>
</html>
`);
                
                printWindow.document.close();
                
            } catch (error) {
                console.error('Print error:', error);
                alert('Error generating print report: ' + error.message);
            }
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>