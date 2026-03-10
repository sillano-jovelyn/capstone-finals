<?php
session_start();

// Include db.php from the root directory
include __DIR__ . '/../db.php';

// ============= ADDED: Check for auto-edit mode from revision =============
$auto_edit_mode = false;
if (isset($_SESSION['auto_edit_mode']) && $_SESSION['auto_edit_mode'] === true) {
    $auto_edit_mode = true;
    unset($_SESSION['auto_edit_mode']); // Clear it so it only happens once
}
// ============= END ADDED =============

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_profile') {
        updateProfile($conn, $user_id);
    } elseif ($_POST['action'] === 'change_password') {
        changePassword($conn, $user_id);
    } elseif ($_POST['action'] === 'upload_documents') {
        uploadDocuments($conn, $user_id);
    } elseif ($_POST['action'] === 'delete_document') {
        deleteDocument($conn, $user_id);
    }
}

// ============================================
// UPDATED: UPDATE PROFILE WITH AUTO-RESET REVISION STATUS
// ============================================
function updateProfile($conn, $user_id) {
    // Escape and get all form data
    $lastname = $conn->real_escape_string($_POST['lastname'] ?? '');
    $firstname = $conn->real_escape_string($_POST['firstname'] ?? '');
    $middleinitial = $conn->real_escape_string($_POST['middleinitial'] ?? '');
    $fullname = $conn->real_escape_string($firstname . ' ' . ($middleinitial ? $middleinitial . '. ' : '') . $lastname);
    $house_street = $conn->real_escape_string($_POST['house_street'] ?? '');
    $barangay = $conn->real_escape_string($_POST['barangay'] ?? '');
    $municipality = $conn->real_escape_string($_POST['municipality'] ?? '');
    $city = $conn->real_escape_string($_POST['city'] ?? '');
    $address = trim($house_street . ', ' . $barangay . ', ' . $municipality . ', ' . $city, ', ');
    
    $gender = $conn->real_escape_string($_POST['gender'] ?? '');
    $civil_status = $conn->real_escape_string($_POST['civil_status'] ?? '');
    $birthday = $conn->real_escape_string($_POST['birthday'] ?? '');
    
    // Calculate age from birthday
    $age = 0;
    if (!empty($birthday)) {
        $birthDate = new DateTime($birthday);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y;
    }
    
    $contact_number = $conn->real_escape_string($_POST['contact_number'] ?? '');
    $email = $conn->real_escape_string($_POST['email'] ?? '');
    $employment_status = $conn->real_escape_string($_POST['employment_status'] ?? '');
    $education = $conn->real_escape_string($_POST['education'] ?? '');
    $trainings_attended = $conn->real_escape_string($_POST['trainings_attended'] ?? '');
    $toolkit_received = $conn->real_escape_string($_POST['toolkit_received'] ?? '');
    
    $query = "UPDATE trainees SET 
              lastname = '$lastname',
              firstname = '$firstname',
              middleinitial = '$middleinitial',
              fullname = '$fullname',
              house_street = '$house_street',
              barangay = '$barangay',
              municipality = '$municipality',
              city = '$city',
              address = '$address',
              gender = '$gender',
              civil_status = '$civil_status',
              birthday = '$birthday',
              age = '$age',
              contact_number = '$contact_number',
              email = '$email',
              employment_status = '$employment_status',
              education = '$education',
              trainings_attended = '$trainings_attended',
              toolkit_received = '$toolkit_received'
              WHERE user_id = '$user_id'";
    
    if ($conn->query($query)) {
        // ============= AUTO-RESET REVISION REQUESTS =============
        // Check if this user has any pending revision requests
        $revision_reset_count = 0;
        
        $check_revision = $conn->prepare("
            SELECT e.id 
            FROM enrollments e
            JOIN revision_requests rr ON e.revision_requests_id = rr.id
            WHERE e.user_id = ? AND e.enrollment_status = 'revision_needed' AND rr.status = 'pending'
        ");
        $check_revision->bind_param("i", $user_id);
        $check_revision->execute();
        $revision_result = $check_revision->get_result();
        
        if ($revision_result->num_rows > 0) {
            // Get all enrollment IDs that need to be reset
            $enrollment_ids = [];
            while ($row = $revision_result->fetch_assoc()) {
                $enrollment_ids[] = $row['id'];
            }
            
            // Reset enrollment status to 'pending' for these enrollments
            if (!empty($enrollment_ids)) {
                $ids_string = implode(',', $enrollment_ids);
                $reset_query = "UPDATE enrollments SET enrollment_status = 'pending' WHERE id IN ($ids_string)";
                if ($conn->query($reset_query)) {
                    $revision_reset_count = count($enrollment_ids);
                    
                    // Update revision requests status to 'completed'
                    $update_revision = $conn->prepare("
                        UPDATE revision_requests rr
                        JOIN enrollments e ON rr.id = e.revision_requests_id
                        SET rr.status = 'completed'
                        WHERE e.user_id = ? AND e.id IN ($ids_string)
                    ");
                    $update_revision->bind_param("i", $user_id);
                    $update_revision->execute();
                    $update_revision->close();
                    
                    // Create notification for admin that revision is complete
                    $admin_notify = $conn->prepare("
                        INSERT INTO notifications (user_id, type, title, message, related_id, related_type, created_at)
                        SELECT u.id, 'info', 'Revision Completed', 
                               CONCAT(?, ' has updated their profile. Enrollment is now pending review.'),
                               e.id, 'enrollment', NOW()
                        FROM users u
                        CROSS JOIN enrollments e
                        WHERE u.role = 'admin' AND e.id IN ($ids_string)
                        LIMIT 1
                    ");
                    $admin_notify->bind_param("s", $fullname);
                    $admin_notify->execute();
                    $admin_notify->close();
                }
            }
        }
        $check_revision->close();
        
        // Set success message with revision reset info
        if ($revision_reset_count > 0) {
            $_SESSION['message'] = "Profile updated successfully! Your enrollment application has been reset to pending status and is now ready for re-review.";
        } else {
            $_SESSION['message'] = "Profile updated successfully!";
        }
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['error'] = "Failed to update profile: " . $conn->error;
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ============================================
// UPDATED: UPLOAD DOCUMENTS WITH AUTO-RESET REVISION STATUS
// ============================================
function uploadDocuments($conn, $user_id) {
    $upload_dir = __DIR__ . '/../imagefile/';
    
    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $errors = [];
    $success = false;
    
    // Handle single document upload from modal
    if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $document_type = $_POST['document_type_single'] ?? '';
        
        if ($document_type) {
            $file = $_FILES['document_file'];
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $original_name = $file['name'];
            $filename = $document_type . '_' . $user_id . '_' . time() . '.' . $extension;
            $filepath = $upload_dir . $filename;
            
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];
            
            $file_extension = strtolower($extension);
            
            if (in_array($file['type'], $allowed_types) && in_array($file_extension, $allowed_extensions)) {
                
                // Get current documents from database
                $query = "SELECT $document_type FROM trainees WHERE user_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                
                // Delete ALL existing files for this document type
                $deleted_count = 0;
                if ($row && !empty($row[$document_type])) {
                    $old_documents = json_decode($row[$document_type], true);
                    if (is_array($old_documents)) {
                        foreach ($old_documents as $old_filename) {
                            $old_filepath = $upload_dir . $old_filename;
                            if (file_exists($old_filepath)) {
                                if (unlink($old_filepath)) {
                                    $deleted_count++;
                                }
                            }
                        }
                    }
                }
                
                // Upload new document
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    // Store ONLY the new filename
                    $documents_json = json_encode([$filename]);
                    
                    // Update database
                    $update_query = "UPDATE trainees SET $document_type = ? WHERE user_id = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("si", $documents_json, $user_id);
                    
                    if ($update_stmt->execute()) {
                        $success = true;
                        
                        // ============= AUTO-RESET REVISION REQUESTS WHEN DOCUMENTS ARE UPLOADED =============
                        // Check if this user has any pending revision requests
                        $check_revision = $conn->prepare("
                            SELECT e.id 
                            FROM enrollments e
                            JOIN revision_requests rr ON e.revision_requests_id = rr.id
                            WHERE e.user_id = ? AND e.enrollment_status = 'revision_needed' AND rr.status = 'pending'
                        ");
                        $check_revision->bind_param("i", $user_id);
                        $check_revision->execute();
                        $revision_result = $check_revision->get_result();
                        
                        if ($revision_result->num_rows > 0) {
                            // Get all enrollment IDs that need to be reset
                            $enrollment_ids = [];
                            while ($row = $revision_result->fetch_assoc()) {
                                $enrollment_ids[] = $row['id'];
                            }
                            
                            // Reset enrollment status to 'pending' for these enrollments
                            if (!empty($enrollment_ids)) {
                                $ids_string = implode(',', $enrollment_ids);
                                $reset_query = "UPDATE enrollments SET enrollment_status = 'pending' WHERE id IN ($ids_string)";
                                $conn->query($reset_query);
                                
                                // Update revision requests status to 'completed'
                                $update_revision = $conn->prepare("
                                    UPDATE revision_requests rr
                                    JOIN enrollments e ON rr.id = e.revision_requests_id
                                    SET rr.status = 'completed'
                                    WHERE e.user_id = ? AND e.id IN ($ids_string)
                                ");
                                $update_revision->bind_param("i", $user_id);
                                $update_revision->execute();
                                $update_revision->close();
                            }
                        }
                        $check_revision->close();
                        
                        if ($deleted_count > 0) {
                            $_SESSION['message'] = ucfirst(str_replace('_', ' ', $document_type)) . " uploaded successfully! Previous " . 
                                                   ($deleted_count == 1 ? "document was" : $deleted_count . " documents were") . " replaced and deleted. Your enrollment has been reset to pending status for re-review.";
                        } else {
                            $_SESSION['message'] = ucfirst(str_replace('_', ' ', $document_type)) . " uploaded successfully! Your enrollment has been reset to pending status for re-review.";
                        }
                        $_SESSION['message_type'] = "success";
                    } else {
                        $errors[] = "Failed to save document information: " . $conn->error;
                        // Delete the uploaded file if database update fails
                        if (file_exists($filepath)) {
                            unlink($filepath);
                        }
                    }
                } else {
                    $errors[] = "Failed to upload file. Please check directory permissions.";
                }
            } else {
                $errors[] = "Invalid file type. Only JPG, PNG, and PDF files are allowed.";
            }
        } else {
            $errors[] = "Please select a document type.";
        }
    } else {
        $errors[] = "Please select a file to upload.";
    }
    
    if (!empty($errors)) {
        $_SESSION['error'] = implode("<br>", $errors);
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Function to change password
function changePassword($conn, $user_id) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate passwords
    if ($new_password !== $confirm_password) {
        $_SESSION['error'] = "New passwords do not match!";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    if (strlen($new_password) < 6) {
        $_SESSION['error'] = "Password must be at least 6 characters long!";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Get current password hash
    $query = "SELECT password FROM trainees WHERE user_id = '$user_id'";
    $result = $conn->query($query);
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $current_hash = $row['password'];
        
        // Verify current password (assuming passwords are hashed)
        if (password_verify($current_password, $current_hash)) {
            // Hash new password
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update both trainees and users tables if they're linked
            $update_trainees = "UPDATE trainees SET password = '$new_password_hash' WHERE user_id = '$user_id'";
            $update_users = "UPDATE users SET password = '$new_password_hash' WHERE id = '$user_id'";
            
            // Execute both queries
            if ($conn->query($update_trainees) && $conn->query($update_users)) {
                $_SESSION['message'] = "Password changed successfully!";
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['error'] = "Failed to change password: " . $conn->error;
            }
        } else {
            $_SESSION['error'] = "Current password is incorrect!";
        }
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Function to delete document
function deleteDocument($conn, $user_id) {
    $document_type = $_POST['document_type'] ?? '';
    $filename_to_delete = $_POST['filename'] ?? '';
    
    if (!$document_type || !$filename_to_delete) {
        $_SESSION['error'] = "No document specified.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $upload_dir = __DIR__ . '/../imagefile/';
    
    // Get current documents from database
    $query = "SELECT $document_type FROM trainees WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $documents = json_decode($row[$document_type], true);
        
        if (is_array($documents)) {
            // Remove the filename from array
            $key = array_search($filename_to_delete, $documents);
            if ($key !== false) {
                unset($documents[$key]);
                
                // Reindex array
                $documents = array_values($documents);
                
                // Encode back to JSON
                $documents_json = json_encode($documents);
                
                // Update database
                $update_query = "UPDATE trainees SET $document_type = ? WHERE user_id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("si", $documents_json, $user_id);
                
                if ($update_stmt->execute()) {
                    // Delete file from server
                    $filepath = $upload_dir . $filename_to_delete;
                    if (file_exists($filepath)) {
                        unlink($filepath);
                    }
                    
                    $_SESSION['message'] = ucfirst(str_replace('_', ' ', $document_type)) . " deleted successfully!";
                    $_SESSION['message_type'] = "success";
                } else {
                    $_SESSION['error'] = "Failed to delete document: " . $conn->error;
                }
            } else {
                $_SESSION['error'] = "Document not found in database.";
            }
        } else {
            $_SESSION['error'] = "No documents found.";
        }
    } else {
        $_SESSION['error'] = "User not found.";
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ============================================
// CHECK FOR PENDING REVISION REQUESTS
// ============================================
$has_revision_request = false;
$revision_programs = [];
$revision_reasons = [];

$revision_query = "
    SELECT rr.*, e.enrollment_status, p.name as program_name
    FROM revision_requests rr
    JOIN enrollments e ON rr.enrollment_id = e.id
    JOIN programs p ON e.program_id = p.id
    WHERE e.user_id = ? AND rr.status = 'pending' AND e.enrollment_status = 'revision_needed'
    ORDER BY rr.created_at DESC
";

$revision_stmt = $conn->prepare($revision_query);
$revision_stmt->bind_param("i", $user_id);
$revision_stmt->execute();
$revision_result = $revision_stmt->get_result();

if ($revision_result->num_rows > 0) {
    $has_revision_request = true;
    while ($row = $revision_result->fetch_assoc()) {
        $revision_programs[] = $row['program_name'];
        $revision_reasons[] = $row['reason'];
    }
}
$revision_stmt->close();

// Fetch trainee data with all fields
$query = "SELECT * FROM trainees WHERE user_id = '$user_id'";
$result = $conn->query($query);
$trainee = $result->fetch_assoc();

if (!$trainee) {
    die("Trainee not found");
}

// If fullname is empty but firstname/lastname exist, create fullname
if (empty($trainee['fullname']) && !empty($trainee['firstname']) && !empty($trainee['lastname'])) {
    $middle = !empty($trainee['middleinitial']) ? $trainee['middleinitial'] . '. ' : '';
    $trainee['fullname'] = $trainee['firstname'] . ' ' . $middle . $trainee['lastname'];
}

// Parse JSON documents from trainees table
function getDocumentsArray($json_data) {
    if (empty($json_data)) {
        return [];
    }
    $data = json_decode($json_data, true);
    return is_array($data) ? $data : [];
}

$valid_id_docs = getDocumentsArray($trainee['valid_id'] ?? '[]');
$voters_cert_docs = getDocumentsArray($trainee['voters_certificate'] ?? '[]');

// ============= DOCUMENT HELPER FUNCTIONS =============

// Get document URL function
function getDocumentUrl($filename) {
    if ($filename) {
        return '../imagefile/' . $filename;
    }
    return null;
}

// Check if file is image
function isImageFile($filename) {
    if (!$filename) return false;
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp']);
}

// Check if file is PDF
function isPdfFile($filename) {
    if (!$filename) return false;
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return $extension === 'pdf';
}

// Get file icon based on extension
function getFileIcon($filename) {
    if (!$filename) return 'fa-file';
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    switch($extension) {
        case 'pdf': return 'fa-file-pdf';
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
        case 'bmp':
        case 'webp': return 'fa-file-image';
        case 'doc':
        case 'docx': return 'fa-file-word';
        case 'xls':
        case 'xlsx': return 'fa-file-excel';
        default: return 'fa-file';
    }
}

// Get file color based on extension
function getFileColor($filename) {
    if (!$filename) return '#6b7280';
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    switch($extension) {
        case 'pdf': return '#ef4444';
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
        case 'bmp':
        case 'webp': return '#3b82f6';
        case 'doc':
        case 'docx': return '#2563eb';
        case 'xls':
        case 'xlsx': return '#10b981';
        default: return '#6b7280';
    }
}

$show_auto_delete_warning = true;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Trainee Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Add SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #f2f8ff;
            color: #333;
            line-height: 1.6;
        }

        .container {
            min-height: 100vh;
            padding: 20px;
        }

        .max-w-5xl {
            max-width: 80rem;
            margin: 0 auto;
        }

        /* Back Button */
        .back-btn {
            margin-bottom: 1.5rem;
            color: #0d9488;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            transition: color 0.2s;
            padding: 0.5rem 0;
        }

        .back-btn:hover {
            color: #0f766e;
        }

        /* Messages */
        .message, .error, .warning-message {
            margin-bottom: 1.5rem;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: fadeIn 0.3s ease;
        }

        .message {
            background: #dcfce7;
            border: 1px solid #22c55e;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            border: 1px solid #ef4444;
            color: #b91c1c;
        }

        .warning-message {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ============= REVISION NOTIFICATION STYLES ============= */
        .revision-notification {
            background: #fff3cd;
            border: 2px solid #f39c12;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: flex-start;
            gap: 15px;
            box-shadow: 0 4px 12px rgba(243, 156, 18, 0.15);
            position: relative;
            overflow: hidden;
        }

        .revision-notification::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #f39c12, #e67e22);
        }

        .revision-icon {
            background: #f39c12;
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .revision-content {
            flex: 1;
        }

        .revision-title {
            color: #856404;
            margin: 0 0 10px 0;
            font-size: 18px;
            font-weight: 700;
        }

        .revision-reason-box {
            background: white;
            border-left: 4px solid #f39c12;
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 10px;
        }

        .revision-instructions {
            background: #d1ecf1;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            border-left: 4px solid #0c5460;
        }

        .revision-instructions h4 {
            color: #0c5460;
            margin: 0 0 10px 0;
            font-size: 15px;
        }

        .revision-instructions ul {
            margin: 0;
            padding-left: 20px;
            color: #0c5460;
        }

        .revision-instructions li {
            margin-bottom: 8px;
        }

        .btn-revision-edit {
            background: #f39c12;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 15px;
        }

        .btn-revision-edit:hover {
            background: #e67e22;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(243, 156, 18, 0.3);
        }

        /* Profile Header */
        .profile-header {
            background: linear-gradient(135deg, #d8f3f0 0%, #e0f2fe 100%);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            padding: 24px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #0d9488, #3b82f6);
        }

        .profile-main {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        @media (min-width: 768px) {
            .profile-main {
                flex-direction: row;
                justify-content: space-between;
                align-items: flex-start;
            }
        }

        .profile-identity {
            flex: 1;
        }

        .profile-identity h2 {
            font-size: 24px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 16px;
        }

        .profile-badge-container {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 24px;
        }

        .profile-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #14b8a6;
            color: white;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .revision-badge {
            background: #f39c12;
            color: white;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.8; }
            100% { opacity: 1; }
        }

        .profile-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 2px solid rgba(13, 148, 136, 0.15);
        }

        @media (min-width: 768px) {
            .profile-actions {
                flex-direction: row;
                gap: 16px;
                margin-top: 20px;
                padding-top: 20px;
            }
        }

        /* Hide main upload button when in edit mode */
        body.editing-mode .btn-upload:not(.inline-upload) {
            display: none;
        }

        /* Enhanced Buttons */
        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 15px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            text-decoration: none;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 13px;
        }

        .btn-edit {
            background: linear-gradient(135deg, #14b8a6, #0d9488);
            color: white;
        }

        .btn-edit:hover {
            background: linear-gradient(135deg, #0d9488, #0f766e);
        }

        .btn-change-password {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }

        .btn-change-password:hover {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
        }

        .btn-save {
            background: linear-gradient(135deg, #0d9488, #0f766e);
            color: white;
        }

        .btn-save:hover:not(:disabled) {
            background: linear-gradient(135deg, #0f766e, #115e59);
        }

        .btn-save:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-cancel {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
        }

        .btn-cancel:hover {
            background: linear-gradient(135deg, #4b5563, #374151);
        }

        .btn-upload {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
        }

        .btn-upload:hover {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
        }

        .btn-upload-inline {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            padding: 10px 20px;
        }

        .btn-upload-inline:hover {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
        }

        .btn-view {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-view:hover {
            background: linear-gradient(135deg, #059669, #047857);
        }

        .btn-delete {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-delete:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
        }

        /* Personal Info Section */
        .personal-info {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            padding: 24px;
            margin-bottom: 24px;
        }

        .personal-info h3 {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Grid Layout for Info Sections */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }

        @media (min-width: 768px) {
            .info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .info-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .info-section {
            padding: 20px;
            background: #f9fafb;
            border-radius: 12px;
            border-left: 4px solid #0d9488;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .info-section:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
        }

        .info-section h4 {
            color: #1f2937;
            margin-bottom: 16px;
            font-size: 15px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding-bottom: 8px;
            border-bottom: 2px solid rgba(13, 148, 136, 0.2);
        }

        .info-item {
            margin-bottom: 16px;
        }

        .info-item:last-child {
            margin-bottom: 0;
        }

        .info-item b {
            color: #374151;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }

        .info-item b i {
            color: #0d9488;
            width: 18px;
        }

        .info-item span {
            color: #6b7280;
            font-size: 15px;
            padding-left: 28px;
            line-height: 1.5;
        }

        /* Documents Section */
        .documents-section {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            padding: 24px;
            margin-top: 24px;
        }

        .documents-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .documents-header h6 {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .documents-header h6 i {
            color: #3498db;
        }

        /* Form Styles */
        .form-section {
            padding: 24px;
            background: #f9fafb;
            border-radius: 12px;
            border-left: 4px solid #0d9488;
            margin-bottom: 20px;
        }

        .form-section h4 {
            color: #0d9488;
            margin-bottom: 20px;
            font-size: 17px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        @media (min-width: 640px) {
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .form-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group label i {
            color: #0d9488;
            width: 18px;
        }

        .input-field {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #d1d5db;
            border-radius: 8px;
            outline: none;
            transition: all 0.3s ease;
            font-size: 15px;
            background: white;
        }

        .input-field:focus {
            border-color: #14b8a6;
            box-shadow: 0 0 0 4px rgba(20, 184, 166, 0.15);
        }

        .input-field.error {
            border-color: #ef4444;
            background-color: #fef2f2;
        }

        textarea.input-field {
            resize: vertical;
            min-height: 100px;
        }

        .form-actions {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 2px solid #e5e7eb;
        }

        @media (min-width: 640px) {
            .form-actions {
                flex-direction: row;
                justify-content: flex-end;
            }
        }

        /* Auto-delete Warning Styles */
        .auto-delete-warning {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 16px 20px;
            background: #fff8e7;
            border-radius: 8px;
            color: #9c7c1c;
            border-left: 4px solid #f1c40f;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .auto-delete-warning i {
            font-size: 20px;
            color: #f39c12;
            margin-top: 2px;
        }

        .auto-delete-warning strong {
            color: #7a5c0c;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
            opacity: 0;
            backdrop-filter: blur(5px);
            transition: opacity 0.3s ease, backdrop-filter 0.3s ease;
        }

        .modal.active {
            display: flex;
            opacity: 1;
            animation: modalFadeIn 0.3s ease forwards;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
            padding: 28px;
            width: 100%;
            max-width: 500px;
            transform: translateY(-30px) scale(0.95);
            transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .modal.active .modal-content {
            transform: translateY(0) scale(1);
            animation: modalSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; backdrop-filter: blur(0px); }
            to { opacity: 1; backdrop-filter: blur(5px); }
        }

        @keyframes modalSlideIn {
            from { transform: translateY(-30px) scale(0.95); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .modal-header h2 {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-btn {
            color: #6b7280;
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            transition: all 0.3s ease;
            padding: 0;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }

        .close-btn:hover {
            color: #374151;
            background-color: #f3f4f6;
            transform: rotate(90deg);
        }

        /* File Input Styles */
        .file-input-wrapper {
            position: relative;
            margin-bottom: 16px;
        }

        .file-input-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px 20px;
            background: #f9fafb;
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-input-label:hover {
            border-color: #0d9488;
            background: #f0fdfa;
        }

        .file-input-label i {
            font-size: 32px;
            color: #0d9488;
            margin-bottom: 12px;
        }

        .file-input-label span {
            font-size: 14px;
            color: #374151;
            font-weight: 500;
        }

        .file-input-label small {
            font-size: 12px;
            color: #6b7280;
            margin-top: 8px;
        }

        input[type="file"] {
            display: none;
        }

        .selected-file {
            margin-top: 12px;
            padding: 12px;
            background: #e6f7f5;
            border-radius: 8px;
            font-size: 13px;
            color: #0d9488;
            display: flex;
            align-items: center;
            gap: 8px;
            word-break: break-word;
        }

        /* Document type selector */
        .document-type-selector {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .document-type-btn {
            flex: 1;
            padding: 16px 12px;
            border: 2px solid #e5e7eb;
            background: white;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            min-width: 120px;
        }

        .document-type-btn i {
            font-size: 28px;
            color: #6b7280;
        }

        .document-type-btn span {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
        }

        .document-type-btn.active {
            border-color: #0d9488;
            background: #f0fdfa;
            box-shadow: 0 4px 12px rgba(13, 148, 136, 0.15);
        }

        .document-type-btn.active i {
            color: #0d9488;
        }

        .document-type-btn:hover {
            border-color: #0d9488;
            transform: translateY(-2px);
        }

        /* Upload Modal Replacement Warning */
        .replacement-warning {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: #fff3cd;
            border-radius: 8px;
            color: #856404;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #ffeeba;
        }

        .replacement-warning i {
            font-size: 20px;
            color: #f39c12;
        }

        /* Password input with toggle */
        .password-input {
            position: relative;
            display: flex;
            align-items: center;
        }

        .password-input .input-field {
            padding-right: 45px;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .password-toggle:hover {
            color: #0d9488;
        }

        .password-strength {
            margin-top: 8px;
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        .password-hint {
            font-size: 11px;
            color: #6b7280;
            margin-top: 6px;
        }

        .modal-actions {
            display: flex;
            flex-direction: column;
            gap: 16px;
            padding-top: 24px;
            margin-top: 24px;
            border-top: 2px solid #e5e7eb;
        }

        @media (min-width: 480px) {
            .modal-actions {
                flex-direction: row;
            }
        }

        /* Lightbox Modal for Image Viewing */
        .lightbox-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.95);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .lightbox-content {
            position: relative;
            max-width: 90%;
            max-height: 90%;
        }

        .lightbox-image {
            max-width: 100%;
            max-height: 90vh;
            display: block;
            margin: 0 auto;
            border-radius: 8px;
            box-shadow: 0 0 30px rgba(0, 0, 0, 0.5);
        }

        .lightbox-close {
            position: absolute;
            top: -40px;
            right: 0;
            color: white;
            font-size: 35px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
        }

        .lightbox-close:hover {
            color: #ccc;
            transform: rotate(90deg);
        }

        .lightbox-caption {
            color: white;
            padding: 15px 0;
            text-align: center;
            font-size: 16px;
            font-weight: 500;
        }

        /* Inline Upload Styles */
        .inline-upload-section {
            background: white;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .inline-upload-section:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border-color: #8b5cf6;
        }

        .inline-upload-header {
            padding: 15px 20px;
            background: linear-gradient(to right, #f9fafb, #ffffff);
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .current-file-badge {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .file-input-inline {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .file-label-inline {
            flex: 1;
            padding: 10px 16px;
            background: #f3f4f6;
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s;
            min-width: 200px;
        }

        .file-label-inline:hover {
            border-color: #8b5cf6;
            background: #f5f3ff;
        }

        .file-label-inline i {
            color: #6b7280;
        }

        .file-label-inline:hover i {
            color: #8b5cf6;
        }

        .selected-file-inline {
            margin-top: 8px;
            padding: 8px 12px;
            background: #f0fdfa;
            border-radius: 6px;
            font-size: 12px;
            color: #0d9488;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Document Card Styles */
        .document-card-left {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #3498db;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .document-card-right {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #2ecc71;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .document-preview {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .document-icon-box {
            width: 60px;
            height: 60px;
            background: #f0f7ff;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .document-icon-box.right {
            background: #f0fff4;
        }

        .document-info {
            flex: 1;
        }

        .document-name {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 5px;
            word-break: break-word;
        }

        .document-meta {
            font-size: 12px;
            color: #64748b;
        }

        .document-meta i {
            margin-right: 4px;
        }

        .document-empty {
            background: white;
            border-radius: 10px;
            padding: 30px 20px;
            text-align: center;
            border: 2px dashed #cbd5e1;
        }

        .document-empty i {
            font-size: 40px;
            color: #94a3b8;
            margin-bottom: 10px;
        }

        .document-empty p {
            color: #64748b;
            margin-bottom: 15px;
        }

        .document-empty p.required {
            font-size: 13px;
            color: #f39c12;
        }

        .document-column {
            background: #f9fafc;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e0e7ff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .document-column.right {
            border: 1px solid #e0f2e9;
        }

        .column-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3498db;
        }

        .column-header.right {
            border-bottom: 2px solid #2ecc71;
        }

        .column-header i {
            font-size: 22px;
        }

        .column-header h4 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
        }

        .file-count {
            background: #e1f5fe;
            color: #0288d1;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: auto;
        }

        .file-count.right {
            background: #e8f5e9;
            color: #27ae60;
        }

        /* Responsive Design */
        @media (max-width: 640px) {
            .container {
                padding: 16px;
            }
            
            .profile-header,
            .personal-info,
            .documents-section {
                padding: 20px;
            }
            
            .personal-info h3,
            .documents-header h6 {
                font-size: 18px;
            }
            
            .btn {
                width: 100%;
                padding: 14px 20px;
            }
            
            .profile-badge {
                padding: 6px 14px;
                font-size: 12px;
            }
            
            .document-type-selector {
                flex-direction: column;
            }
            
            .document-type-btn {
                flex-direction: row;
                justify-content: center;
            }
            
            .inline-upload-section {
                grid-column: span 1 !important;
            }
            
            .file-input-inline {
                flex-direction: column;
                align-items: stretch;
            }
            
            .file-label-inline {
                width: 100%;
            }
            
            .revision-notification {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .document-preview {
                flex-direction: column;
                text-align: center;
            }
            
            .document-icon-box {
                margin: 0 auto;
            }
        }

        /* Loading State */
        .loading {
            opacity: 0.7;
            cursor: not-allowed;
            pointer-events: none;
        }

        .btn.loading {
            position: relative;
            padding-left: 45px;
        }

        .btn.loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Alert/Warning boxes */
        .alert-warning {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            background: #fff3e0;
            border-radius: 8px;
            color: #e67e22;
            border-left: 4px solid #e67e22;
            margin-bottom: 20px;
        }

        .alert-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            background: #e6f3ff;
            border-radius: 8px;
            color: #2980b9;
            border-left: 4px solid #3498db;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="max-w-5xl">
            <!-- Back Button -->
            <button onclick="window.location.href='dashboard.php'" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </button>

            <!-- Messages -->
            <?php if (isset($_SESSION['message'])): ?>
                <div class="message">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['message']; ?>
                    <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; ?>
                    <?php unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- ============= REVISION REQUEST NOTIFICATION ============= -->
            <?php if ($has_revision_request): ?>
                <div class="revision-notification">
                    <div class="revision-icon">
                        <i class="fas fa-exclamation-triangle" style="font-size: 24px;"></i>
                    </div>
                    <div class="revision-content">
                        <h3 class="revision-title">
                            <i class="fas fa-edit"></i> Application Revision Required
                        </h3>
                        <p style="margin: 0 0 15px 0; color: #856404; font-size: 15px;">
                            Your enrollment application for <strong><?php echo htmlspecialchars(implode(', ', $revision_programs)); ?></strong> requires revision.
                        </p>
                        
                        <?php foreach($revision_reasons as $index => $reason): ?>
                        <div class="revision-reason-box">
                            <strong style="color: #2c3e50; display: block; margin-bottom: 5px;">
                                <?php echo count($revision_reasons) > 1 ? 'Reason ' . ($index + 1) . ':' : 'Reason:'; ?>
                            </strong>
                            <p style="margin: 0; color: #555;"><?php echo nl2br(htmlspecialchars($reason)); ?></p>
                        </div>
                        <?php endforeach; ?>
                        
                        <div class="revision-instructions">
                            <h4><i class="fas fa-info-circle"></i> What you need to do:</h4>
                            <ul>
                                <li>Review the reason(s) above carefully</li>
                                <li>Click the "Update Profile" button below to update your information</li>
                                <li>Upload or replace documents if needed</li>
                                <li>Click "Save Changes" when done - your application will automatically return to pending status</li>
                            </ul>
                        </div>
                        
                        <button onclick="toggleEdit()" class="btn-revision-edit">
                            <i class="fas fa-edit"></i> Update Profile Now
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Profile Content -->
            <div id="profile-content">
                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="profile-main">
                        <div class="profile-identity">
                            <h2><?php echo htmlspecialchars($trainee['fullname'] ?? 'User'); ?></h2>
                            
                            <div class="profile-badge-container">
                                <span class="profile-badge">
                                    <i class="fas fa-user-graduate"></i> TRAINEE
                                </span>
                                <?php if ($has_revision_request): ?>
                                <span class="revision-badge">
                                    <i class="fas fa-exclamation-circle"></i> REVISION NEEDED
                                </span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Actions - Hide Edit Profile button when in revision mode -->
                            <div class="profile-actions">
                                <?php if (!$has_revision_request): ?>
                                <!-- Only show Edit Profile button when NOT in revision mode -->
                                <button onclick="toggleEdit()" class="btn btn-edit" id="edit-btn">
                                    <i class="fas fa-edit"></i> Edit Profile
                                </button>
                                <?php else: ?>
                                <!-- In revision mode, show a different button with orange styling -->
                                <button onclick="toggleEdit()" class="btn btn-edit" style="background: #f39c12;" id="edit-btn">
                                    <i class="fas fa-exclamation-triangle"></i> Update Profile (Revision Required)
                                </button>
                                <?php endif; ?>
                                
                                <button onclick="openChangePassword()" class="btn btn-change-password">
                                    <i class="fas fa-key"></i> Change Password
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Personal Info -->
                <div class="personal-info">
                    <h3><i class="fas fa-user-circle"></i> Personal Information</h3>

                    <!-- View Mode -->
                    <div id="view-mode">
                        <div class="info-grid">
                            <!-- Basic Information Section -->
                            <div class="info-section">
                                <h4>Basic Information</h4>
                                <div class="info-item">
                                    <b><i class="fas fa-user"></i> Full Name</b>
                                    <span><?php echo htmlspecialchars($trainee['fullname'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="info-item">
                                    <b><i class="fas fa-venus-mars"></i> Gender</b>
                                    <span><?php echo htmlspecialchars($trainee['gender'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="info-item">
                                    <b><i class="fas fa-heart"></i> Civil Status</b>
                                    <span><?php echo htmlspecialchars($trainee['civil_status'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="info-item">
                                    <b><i class="fas fa-birthday-cake"></i> Birthday</b>
                                    <span><?php echo !empty($trainee['birthday']) ? date('F j, Y', strtotime($trainee['birthday'])) : 'N/A'; ?></span>
                                </div>
                                <div class="info-item">
                                    <b><i class="fas fa-calendar-alt"></i> Age</b>
                                    <span><?php echo htmlspecialchars($trainee['age'] ?? 'N/A'); ?></span>
                                </div>
                            </div>

                            <!-- Contact Information Section -->
                            <div class="info-section">
                                <h4>Contact Information</h4>
                                <div class="info-item">
                                    <b><i class="fas fa-envelope"></i> Email</b>
                                    <span><?php echo htmlspecialchars($trainee['email'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="info-item">
                                    <b><i class="fas fa-phone"></i> Contact No</b>
                                    <span><?php echo htmlspecialchars($trainee['contact_number'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="info-item">
                                    <b><i class="fas fa-map-marker-alt"></i> Address</b>
                                    <span><?php echo htmlspecialchars($trainee['address'] ?? 'N/A'); ?></span>
                                </div>
                            </div>

                            <!-- Education & Employment Section -->
                            <div class="info-section">
                                <h4>Education & Employment</h4>
                                <div class="info-item">
                                    <b><i class="fas fa-graduation-cap"></i> Education</b>
                                    <span><?php echo htmlspecialchars($trainee['education'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="info-item">
                                    <b><i class="fas fa-briefcase"></i> Employment Status</b>
                                    <span><?php echo htmlspecialchars($trainee['employment_status'] ?? 'N/A'); ?></span>
                                </div>
                            </div>

                            <!-- Training Details Section -->
                            <div class="info-section">
                                <h4>Training Details</h4>
                                <div class="info-item">
                                    <b><i class="fas fa-certificate"></i> Trainings Attended</b>
                                    <span><?php echo !empty($trainee['trainings_attended']) ? nl2br(htmlspecialchars($trainee['trainings_attended'])) : 'None'; ?></span>
                                </div>
                                <div class="info-item">
                                    <b><i class="fas fa-tools"></i> Toolkit Received</b>
                                    <span><?php echo !empty($trainee['toolkit_received']) ? nl2br(htmlspecialchars($trainee['toolkit_received'])) : 'None'; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- EDIT MODE - PROFILE FORM -->
                    <div id="edit-mode" class="edit-form" style="display: none;">
                        <form method="POST" onsubmit="return validateEditForm()">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <!-- Basic Information Section -->
                            <div class="form-section">
                                <h4><i class="fas fa-user"></i> Basic Information</h4>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label><i class="fas fa-user"></i> Last Name *</label>
                                        <input type="text" name="lastname" value="<?php echo htmlspecialchars($trainee['lastname'] ?? ''); ?>" 
                                               class="input-field" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label><i class="fas fa-user"></i> First Name *</label>
                                        <input type="text" name="firstname" value="<?php echo htmlspecialchars($trainee['firstname'] ?? ''); ?>" 
                                               class="input-field" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label><i class="fas fa-user"></i> Middle Initial</label>
                                        <input type="text" name="middleinitial" value="<?php echo htmlspecialchars($trainee['middleinitial'] ?? ''); ?>" 
                                               class="input-field" maxlength="2" placeholder="e.g., D">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label><i class="fas fa-venus-mars"></i> Gender *</label>
                                        <select name="gender" class="input-field" required>
                                            <option value="">Select Gender</option>
                                            <option value="Male" <?php echo ($trainee['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                            <option value="Female" <?php echo ($trainee['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                            <option value="Other" <?php echo ($trainee['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label><i class="fas fa-heart"></i> Civil Status *</label>
                                        <select name="civil_status" class="input-field" required>
                                            <option value="">Select Status</option>
                                            <option value="Single" <?php echo ($trainee['civil_status'] ?? '') == 'Single' ? 'selected' : ''; ?>>Single</option>
                                            <option value="Married" <?php echo ($trainee['civil_status'] ?? '') == 'Married' ? 'selected' : ''; ?>>Married</option>
                                            <option value="Widowed" <?php echo ($trainee['civil_status'] ?? '') == 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                            <option value="Separated" <?php echo ($trainee['civil_status'] ?? '') == 'Separated' ? 'selected' : ''; ?>>Separated</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label><i class="fas fa-birthday-cake"></i> Birthday *</label>
                                        <input type="date" name="birthday" value="<?php echo htmlspecialchars($trainee['birthday'] ?? ''); ?>" 
                                               class="input-field" required max="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Contact Information Section -->
                            <div class="form-section">
                                <h4><i class="fas fa-address-card"></i> Contact Information</h4>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label><i class="fas fa-envelope"></i> Email *</label>
                                        <input type="email" name="email" value="<?php echo htmlspecialchars($trainee['email'] ?? ''); ?>" 
                                               class="input-field" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label><i class="fas fa-phone"></i> Contact Number *</label>
                                        <input type="tel" name="contact_number" value="<?php echo htmlspecialchars($trainee['contact_number'] ?? ''); ?>" 
                                               class="input-field" required pattern="[0-9+\-\s()]{7,15}" 
                                               title="Enter a valid phone number (7-15 digits)" placeholder="e.g., 09123456789">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label><i class="fas fa-home"></i> House No. & Street</label>
                                        <input type="text" name="house_street" value="<?php echo htmlspecialchars($trainee['house_street'] ?? ''); ?>" 
                                               class="input-field" placeholder="e.g., 123 Mabini St.">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label><i class="fas fa-map-marker-alt"></i> Barangay</label>
                                        <input type="text" name="barangay" value="<?php echo htmlspecialchars($trainee['barangay'] ?? ''); ?>" 
                                               class="input-field" placeholder="e.g., Barangay San Jose">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label><i class="fas fa-building"></i> Municipality</label>
                                        <input type="text" name="municipality" value="<?php echo htmlspecialchars($trainee['municipality'] ?? ''); ?>" 
                                               class="input-field" placeholder="e.g., Municipality Name">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label><i class="fas fa-city"></i> City</label>
                                        <input type="text" name="city" value="<?php echo htmlspecialchars($trainee['city'] ?? ''); ?>" 
                                               class="input-field" placeholder="e.g., City Name">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Education & Employment Section -->
                            <div class="form-section">
                                <h4><i class="fas fa-graduation-cap"></i> Education & Employment</h4>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label><i class="fas fa-graduation-cap"></i> Education</label>
                                        <select name="education" class="input-field">
                                            <option value="">Select Education</option>
                                            <option value="Elementary" <?php echo ($trainee['education'] ?? '') == 'Elementary' ? 'selected' : ''; ?>>Elementary</option>
                                            <option value="High School" <?php echo ($trainee['education'] ?? '') == 'High School' ? 'selected' : ''; ?>>High School</option>
                                            <option value="Senior High School" <?php echo ($trainee['education'] ?? '') == 'Senior High School' ? 'selected' : ''; ?>>Senior High School</option>
                                            <option value="College Level / Graduate" <?php echo ($trainee['education'] ?? '') == 'College Level / Graduate' ? 'selected' : ''; ?>>College Level / Graduate</option>
                                            <option value="Vocational" <?php echo ($trainee['education'] ?? '') == 'Vocational' ? 'selected' : ''; ?>>Vocational</option>
                                            <option value="Post Graduate" <?php echo ($trainee['education'] ?? '') == 'Post Graduate' ? 'selected' : ''; ?>>Post Graduate</option>
                                            <option value="Others" <?php echo ($trainee['education'] ?? '') == 'Others' ? 'selected' : ''; ?>>Others</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label><i class="fas fa-briefcase"></i> Employment Status</label>
                                        <select name="employment_status" class="input-field">
                                            <option value="">Select Status</option>
                                            <option value="Employed" <?php echo ($trainee['employment_status'] ?? '') == 'Employed' ? 'selected' : ''; ?>>Employed</option>
                                            <option value="Unemployed" <?php echo ($trainee['employment_status'] ?? '') == 'Unemployed' ? 'selected' : ''; ?>>Unemployed</option>
                                            <option value="Self-Employed" <?php echo ($trainee['employment_status'] ?? '') == 'Self-Employed' ? 'selected' : ''; ?>>Self-Employed</option>
                                            <option value="Student" <?php echo ($trainee['employment_status'] ?? '') == 'Student' ? 'selected' : ''; ?>>Student</option>
                                            <option value="Retired" <?php echo ($trainee['employment_status'] ?? '') == 'Retired' ? 'selected' : ''; ?>>Retired</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Training Details Section -->
                            <div class="form-section">
                                <h4><i class="fas fa-certificate"></i> Training Details</h4>
                                <div class="form-grid">
                                    <div class="form-group" style="grid-column: 1 / -1;">
                                        <label><i class="fas fa-certificate"></i> Trainings Attended</label>
                                        <textarea name="trainings_attended" class="input-field" rows="4"
                                                  placeholder="List trainings attended (separate with commas or new lines)"><?php echo htmlspecialchars($trainee['trainings_attended'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div class="form-group" style="grid-column: 1 / -1;">
                                        <label><i class="fas fa-tools"></i> Toolkit Received</label>
                                        <textarea name="toolkit_received" class="input-field" rows="4"
                                                  placeholder="List toolkits received (separate with commas or new lines)"><?php echo htmlspecialchars($trainee['toolkit_received'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="form-actions">
                                <button type="button" onclick="toggleEdit()" class="btn btn-cancel">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                                <button type="submit" class="btn btn-save" id="save-btn">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                            </div>
                        </form>
                        
                        <!-- DOCUMENT UPLOAD SECTION IN EDIT MODE -->
                        <div class="form-section" style="margin-top: 30px; border-left-color: #8b5cf6;">
                            <h4 style="display: flex; justify-content: space-between; align-items: center;">
                                <span><i class="fas fa-file-upload"></i> Upload Documents</span>
                                <span style="font-size: 13px; background: #fff3cd; padding: 5px 12px; border-radius: 20px; color: #856404;">
                                    <i class="fas fa-exclamation-triangle"></i> Replaces existing
                                </span>
                            </h4>
                            
                            <?php if ($has_revision_request): ?>
                            <div style="background: #d1ecf1; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #0c5460;">
                                <i class="fas fa-info-circle" style="color: #0c5460;"></i>
                                <strong style="color: #0c5460;">Note:</strong> Uploading a new document will automatically reset your enrollment to pending status for re-review.
                            </div>
                            <?php endif; ?>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                <!-- Valid ID Upload Form -->
                                <div class="inline-upload-section">
                                    <form method="POST" enctype="multipart/form-data" onsubmit="return validateInlineUpload('valid_id', event)">
                                        <input type="hidden" name="action" value="upload_documents">
                                        <input type="hidden" name="document_type_single" value="valid_id">
                                        
                                        <div class="inline-upload-header">
                                            <i class="fas fa-id-card" style="color: #3498db; font-size: 18px;"></i>
                                            <strong>Valid ID</strong>
                                            <?php if (!empty($valid_id_docs)): ?>
                                                <span class="current-file-badge">
                                                    <i class="fas fa-check-circle"></i> Current document
                                                </span>
                                            <?php else: ?>
                                                <span style="background: #ffebee; color: #c62828; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">
                                                    <i class="fas fa-exclamation-triangle"></i> No document
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div style="padding: 20px;">
                                            <div class="file-input-inline">
                                                <label for="valid_id_file_edit" class="file-label-inline">
                                                    <i class="fas fa-cloud-upload-alt"></i>
                                                    <span style="flex: 1;">Choose file...</span>
                                                </label>
                                                <input type="file" name="document_file" id="valid_id_file_edit" 
                                                       accept=".jpg,.jpeg,.png,.pdf" 
                                                       style="display: none;" 
                                                       onchange="showInlineFileName(this, 'valid_id_filename_edit')">
                                                <button type="submit" class="btn btn-upload-inline btn-sm">
                                                    <i class="fas fa-upload"></i> Upload
                                                </button>
                                            </div>
                                            
                                            <div id="valid_id_filename_edit" class="selected-file-inline" style="display: none;"></div>
                                        </div>
                                    </form>
                                    
                                    <?php if (!empty($valid_id_docs)): ?>
                                    <div style="padding: 0 20px 20px 20px;">
                                        <div style="padding: 12px; background: #f8fafc; border-radius: 8px; border-left: 3px solid #3498db;">
                                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                                <i class="fas fa-file" style="color: #64748b;"></i>
                                                <span style="font-size: 13px; color: #1e293b; font-weight: 500;">
                                                    Current file:
                                                </span>
                                            </div>
                                            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                                                <span style="font-size: 12px; color: #475569; word-break: break-all;">
                                                    <?php echo htmlspecialchars(basename($valid_id_docs[0])); ?>
                                                </span>
                                                <span style="font-size: 11px; color: #64748b;">
                                                    <i class="fas fa-calendar"></i> 
                                                    <?php 
                                                    $file_path = __DIR__ . '/../imagefile/' . $valid_id_docs[0];
                                                    echo file_exists($file_path) ? date('M d, Y', filemtime($file_path)) : '';
                                                    ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Voter's Certificate Upload Form -->
                                <div class="inline-upload-section">
                                    <form method="POST" enctype="multipart/form-data" onsubmit="return validateInlineUpload('voters_certificate', event)">
                                        <input type="hidden" name="action" value="upload_documents">
                                        <input type="hidden" name="document_type_single" value="voters_certificate">
                                        
                                        <div class="inline-upload-header">
                                            <i class="fas fa-vote-yea" style="color: #2ecc71; font-size: 18px;"></i>
                                            <strong>Voter's Certificate</strong>
                                            <?php if (!empty($voters_cert_docs)): ?>
                                                <span class="current-file-badge">
                                                    <i class="fas fa-check-circle"></i> Current document
                                                </span>
                                            <?php else: ?>
                                                <span style="background: #ffebee; color: #c62828; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">
                                                    <i class="fas fa-exclamation-triangle"></i> No document
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div style="padding: 20px;">
                                            <div class="file-input-inline">
                                                <label for="voters_file_edit" class="file-label-inline">
                                                    <i class="fas fa-cloud-upload-alt"></i>
                                                    <span style="flex: 1;">Choose file...</span>
                                                </label>
                                                <input type="file" name="document_file" id="voters_file_edit" 
                                                       accept=".jpg,.jpeg,.png,.pdf" 
                                                       style="display: none;" 
                                                       onchange="showInlineFileName(this, 'voters_filename_edit')">
                                                <button type="submit" class="btn btn-upload-inline btn-sm">
                                                    <i class="fas fa-upload"></i> Upload
                                                </button>
                                            </div>
                                            
                                            <div id="voters_filename_edit" class="selected-file-inline" style="display: none;"></div>
                                        </div>
                                    </form>
                                    
                                    <?php if (!empty($voters_cert_docs)): ?>
                                    <div style="padding: 0 20px 20px 20px;">
                                        <div style="padding: 12px; background: #f8fafc; border-radius: 8px; border-left: 3px solid #2ecc71;">
                                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                                <i class="fas fa-file" style="color: #64748b;"></i>
                                                <span style="font-size: 13px; color: #1e293b; font-weight: 500;">
                                                    Current file:
                                                </span>
                                            </div>
                                            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                                                <span style="font-size: 12px; color: #475569; word-break: break-all;">
                                                    <?php echo htmlspecialchars(basename($voters_cert_docs[0])); ?>
                                                </span>
                                                <span style="font-size: 11px; color: #64748b;">
                                                    <i class="fas fa-calendar"></i> 
                                                    <?php 
                                                    $file_path = __DIR__ . '/../imagefile/' . $voters_cert_docs[0];
                                                    echo file_exists($file_path) ? date('M d, Y', filemtime($file_path)) : '';
                                                    ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <p style="margin-top: 20px; font-size: 12px; color: #6b7280; display: flex; align-items: center; gap: 6px; padding: 12px; background: #f9fafb; border-radius: 8px;">
                                <i class="fas fa-info-circle" style="color: #8b5cf6;"></i> 
                                <span><strong>Note:</strong> Uploading a new document will automatically <strong>replace and permanently delete</strong> the existing document. This action cannot be undone.</span>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- DOCUMENTS SECTION - SIDE BY SIDE LAYOUT: Valid ID on LEFT, Voter's Certificate on RIGHT -->
                <div class="documents-section">
                    <div class="documents-header">
                        <h6>
                            <i class="fas fa-file-alt"></i> Submitted Documents
                        </h6>
                        <?php if ($has_revision_request): ?>
                        <span style="background: #f39c12; color: white; padding: 6px 15px; border-radius: 20px; font-size: 13px; font-weight: 600;">
                            <i class="fas fa-exclamation-triangle"></i> Update required
                        </span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- TWO-COLUMN DOCUMENTS LAYOUT - Valid ID on LEFT, Voter's Cert on RIGHT -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-top: 20px;">
                        
                        <!-- LEFT COLUMN - VALID ID -->
                        <div class="document-column">
                            <div class="column-header">
                                <i class="fas fa-id-card" style="color: #3498db;"></i>
                                <h4>Valid ID</h4>
                                <span class="file-count">
                                    <?php echo count($valid_id_docs); ?> file(s)
                                </span>
                            </div>
                            
                            <?php if (!empty($valid_id_docs)): ?>
                                <?php foreach($valid_id_docs as $index => $filename): ?>
                                    <?php if (!empty($filename)): ?>
                                    <div class="document-card-left">
                                        <!-- Document Preview -->
                                        <div class="document-preview">
                                            <!-- Icon/Preview -->
                                            <div class="document-icon-box">
                                                <?php if (isImageFile($filename)): ?>
                                                    <img src="<?php echo getDocumentUrl($filename); ?>" 
                                                         alt="Valid ID" 
                                                         style="max-width: 50px; max-height: 50px; border-radius: 4px; cursor: pointer;"
                                                         onclick="openLightbox('<?php echo getDocumentUrl($filename); ?>', 'Valid ID - <?php echo htmlspecialchars(basename($filename)); ?>')">
                                                <?php elseif (isPdfFile($filename)): ?>
                                                    <i class="fas fa-file-pdf" style="font-size: 30px; color: #ef4444;"></i>
                                                <?php else: ?>
                                                    <i class="fas <?php echo getFileIcon($filename); ?>" style="font-size: 30px; color: <?php echo getFileColor($filename); ?>;"></i>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <!-- File Info -->
                                            <div class="document-info">
                                                <div class="document-name">
                                                    <?php echo htmlspecialchars(basename($filename)); ?>
                                                </div>
                                                <?php 
                                                $file_path = __DIR__ . '/../imagefile/' . $filename;
                                                if (file_exists($file_path)):
                                                    $file_size = filesize($file_path) / 1024;
                                                ?>
                                                <div class="document-meta">
                                                    <i class="fas fa-calendar"></i> <?php echo date('M d, Y', filemtime($file_path)); ?> • 
                                                    <?php echo round($file_size, 1); ?> KB
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <!-- View Button -->
                                            <div>
                                                <?php if (isImageFile($filename)): ?>
                                                    <button onclick="openLightbox('<?php echo getDocumentUrl($filename); ?>', 'Valid ID - <?php echo htmlspecialchars(basename($filename)); ?>')" 
                                                            class="btn btn-view btn-sm" style="padding: 6px 12px;">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <a href="<?php echo getDocumentUrl($filename); ?>" target="_blank" 
                                                       class="btn btn-view btn-sm" style="padding: 6px 12px;">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <!-- Empty State for Valid ID -->
                                <div class="document-empty">
                                    <i class="fas fa-id-card"></i>
                                    <p>No Valid ID uploaded</p>
                                    <?php if ($has_revision_request): ?>
                                    <p class="required">This document is required</p>
                                    <?php endif; ?>
                                    <button onclick="toggleEdit(); setTimeout(function(){ document.getElementById('valid_id_file_edit').scrollIntoView({behavior: 'smooth', block: 'center'}); }, 300);" 
                                            class="btn btn-upload btn-sm">
                                        <i class="fas fa-upload"></i> Upload Now
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- RIGHT COLUMN - VOTER'S CERTIFICATE -->
                        <div class="document-column right">
                            <div class="column-header right">
                                <i class="fas fa-vote-yea" style="color: #2ecc71;"></i>
                                <h4>Voter's Certificate / Residency</h4>
                                <span class="file-count right">
                                    <?php echo count($voters_cert_docs); ?> file(s)
                                </span>
                            </div>
                            
                            <?php if (!empty($voters_cert_docs)): ?>
                                <?php foreach($voters_cert_docs as $index => $filename): ?>
                                    <?php if (!empty($filename)): ?>
                                    <div class="document-card-right">
                                        <!-- Document Preview -->
                                        <div class="document-preview">
                                            <!-- Icon/Preview -->
                                            <div class="document-icon-box right">
                                                <?php if (isImageFile($filename)): ?>
                                                    <img src="<?php echo getDocumentUrl($filename); ?>" 
                                                         alt="Voter's Certificate" 
                                                         style="max-width: 50px; max-height: 50px; border-radius: 4px; cursor: pointer;"
                                                         onclick="openLightbox('<?php echo getDocumentUrl($filename); ?>', 'Voter\'s Certificate - <?php echo htmlspecialchars(basename($filename)); ?>')">
                                                <?php elseif (isPdfFile($filename)): ?>
                                                    <i class="fas fa-file-pdf" style="font-size: 30px; color: #ef4444;"></i>
                                                <?php else: ?>
                                                    <i class="fas <?php echo getFileIcon($filename); ?>" style="font-size: 30px; color: <?php echo getFileColor($filename); ?>;"></i>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <!-- File Info -->
                                            <div class="document-info">
                                                <div class="document-name">
                                                    <?php echo htmlspecialchars(basename($filename)); ?>
                                                </div>
                                                <?php 
                                                $file_path = __DIR__ . '/../imagefile/' . $filename;
                                                if (file_exists($file_path)):
                                                    $file_size = filesize($file_path) / 1024;
                                                ?>
                                                <div class="document-meta">
                                                    <i class="fas fa-calendar"></i> <?php echo date('M d, Y', filemtime($file_path)); ?> • 
                                                    <?php echo round($file_size, 1); ?> KB
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <!-- View Button -->
                                            <div>
                                                <?php if (isImageFile($filename)): ?>
                                                    <button onclick="openLightbox('<?php echo getDocumentUrl($filename); ?>', 'Voter\'s Certificate - <?php echo htmlspecialchars(basename($filename)); ?>')" 
                                                            class="btn btn-view btn-sm" style="padding: 6px 12px;">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <a href="<?php echo getDocumentUrl($filename); ?>" target="_blank" 
                                                       class="btn btn-view btn-sm" style="padding: 6px 12px;">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <!-- Empty State for Voter's Certificate -->
                                <div class="document-empty">
                                    <i class="fas fa-vote-yea"></i>
                                    <p>No Voter's Certificate uploaded</p>
                                    <?php if ($has_revision_request): ?>
                                    <p class="required">This document is required</p>
                                    <?php endif; ?>
                                    <button onclick="toggleEdit(); setTimeout(function(){ document.getElementById('voters_file_edit').scrollIntoView({behavior: 'smooth', block: 'center'}); }, 300);" 
                                            class="btn btn-upload btn-sm">
                                        <i class="fas fa-upload"></i> Upload Now
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (empty($valid_id_docs) && empty($voters_cert_docs)): ?>
                    <!-- No documents at all message -->
                    <div style="margin-top: 20px;">
                        <div style="display: flex; align-items: center; gap: 15px; padding: 30px; background: <?php echo $has_revision_request ? '#fff3cd' : '#f8f9fa'; ?>; border-radius: 8px; color: <?php echo $has_revision_request ? '#856404' : '#7f8c8d'; ?>; text-align: center; justify-content: center; flex-direction: column; border: 2px dashed #cbd5e1;">
                            <i class="fas fa-file-upload" style="font-size: 48px; color: <?php echo $has_revision_request ? '#f39c12' : '#bdc3c7'; ?>;"></i>
                            <span style="font-size: 16px; font-weight: 500;">
                                <?php if ($has_revision_request): ?>
                                    Required documents are missing
                                <?php else: ?>
                                    No documents have been submitted
                                <?php endif; ?>
                            </span>
                            <span style="font-size: 14px; color: <?php echo $has_revision_request ? '#856404' : '#95a5a6'; ?>; margin-bottom: 10px;">
                                <?php if ($has_revision_request): ?>
                                    You need to upload both a Valid ID and Voter's Certificate to complete your application revision.
                                <?php else: ?>
                                    Upload your required documents to complete your application.
                                <?php endif; ?>
                            </span>
                            <button onclick="toggleEdit()" style="padding: 12px 24px; background: <?php echo $has_revision_request ? '#f39c12' : '#3498db'; ?>; color: white; border: none; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500;">
                                <i class="fas fa-upload"></i> 
                                <?php echo $has_revision_request ? 'Upload Required Documents' : 'Upload Your First Document'; ?>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ============= LIGHTBOX MODAL FOR IMAGE VIEWING ============= -->
    <div id="lightbox-modal" class="lightbox-modal" onclick="closeLightbox()">
        <div class="lightbox-content">
            <span class="lightbox-close">&times;</span>
            <img class="lightbox-image" id="lightbox-image" src="" alt="">
            <div class="lightbox-caption" id="lightbox-caption"></div>
        </div>
    </div>

    <!-- ============= UPLOAD DOCUMENTS MODAL ============= -->
    <div id="upload-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-upload"></i> Upload Document</h2>
                <button onclick="closeUploadModal()" class="close-btn">&times;</button>
            </div>

            <form method="POST" enctype="multipart/form-data" id="upload-form" onsubmit="return validateUploadForm()">
                <input type="hidden" name="action" value="upload_documents">
                
                <!-- Replacement Warning -->
                <div class="replacement-warning" id="replacement-warning" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Warning:</strong> Uploading a new document will <strong>automatically delete and replace</strong> any existing document of this type. This action cannot be undone.
                    </div>
                </div>
                
                <?php if ($has_revision_request): ?>
                <div style="background: #d1ecf1; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #0c5460;">
                    <i class="fas fa-info-circle" style="color: #0c5460;"></i>
                    <strong style="color: #0c5460;">Note:</strong> Uploading a document will automatically reset your enrollment to pending status for re-review.
                </div>
                <?php endif; ?>
                
                <!-- Document Type Selector -->
                <div class="document-type-selector">
                    <label class="document-type-btn" id="type-valid_id" onclick="selectDocumentType('valid_id')">
                        <i class="fas fa-id-card"></i>
                        <span>Valid ID</span>
                    </label>
                    <label class="document-type-btn" id="type-voters_certificate" onclick="selectDocumentType('voters_certificate')">
                        <i class="fas fa-vote-yea"></i>
                        <span>Voter's Certificate</span>
                    </label>
                </div>
                
                <input type="hidden" name="document_type_single" id="document_type_single" value="">

                <div id="upload-area" style="display: none;">
                    <div class="form-group">
                        <label><i class="fas fa-file-alt"></i> Select File</label>
                        <div class="file-input-wrapper">
                            <label for="document_file" class="file-input-label">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <span>Click to upload document</span>
                                <small>JPG, PNG, PDF (Max 5MB)</small>
                            </label>
                            <input type="file" name="document_file" id="document_file" accept=".jpg,.jpeg,.png,.pdf" onchange="showFileName(this, 'document_filename')">
                            <div id="document_filename" class="selected-file" style="display: none;"></div>
                        </div>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" onclick="closeUploadModal()" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-upload" id="upload-btn" disabled>
                        <i class="fas fa-upload"></i> Upload Document
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div id="password-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-key"></i> Change Password</h2>
                <button onclick="closeChangePassword()" class="close-btn">&times;</button>
            </div>

            <form method="POST" id="password-form" onsubmit="return validatePasswordForm()">
                <input type="hidden" name="action" value="change_password">
                
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Current Password</label>
                    <div class="password-input">
                        <input type="password" name="current_password" id="current-password" 
                               class="input-field" placeholder="Enter current password" required>
                        <button type="button" onclick="togglePassword('current-password', 'toggle-current')" 
                                class="password-toggle">
                            <i class="fas fa-eye" id="toggle-current"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-lock"></i> New Password</label>
                    <div class="password-input">
                        <input type="password" name="new_password" id="new-password" 
                               class="input-field" placeholder="Enter new password" required
                               onkeyup="checkPasswordStrength(this.value)">
                        <button type="button" onclick="togglePassword('new-password', 'toggle-new')" 
                                class="password-toggle">
                            <i class="fas fa-eye" id="toggle-new"></i>
                        </button>
                    </div>
                    <div class="password-strength">
                        <div class="password-strength-bar" id="password-strength-bar"></div>
                    </div>
                    <p class="password-hint">Must be at least 6 characters with letters and numbers</p>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Confirm New Password</label>
                    <div class="password-input">
                        <input type="password" name="confirm_password" id="confirm-password" 
                               class="input-field" placeholder="Confirm new password" required>
                        <button type="button" onclick="togglePassword('confirm-password', 'toggle-confirm')" 
                                class="password-toggle">
                            <i class="fas fa-eye" id="toggle-confirm"></i>
                        </button>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" onclick="closeChangePassword()" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-change-password" id="change-password-btn">
                        <i class="fas fa-sync-alt"></i> Change Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============= ADDED: Auto-edit mode for revision requests ============= -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($auto_edit_mode || $has_revision_request): ?>
            // Auto-open edit mode for revision requests after a short delay
            setTimeout(function() {
                // Check if toggleEdit function exists
                if (typeof toggleEdit === 'function') {
                    toggleEdit();
                    
                    // Scroll to revision notification or edit form
                    const revisionNotification = document.querySelector('.revision-notification');
                    if (revisionNotification) {
                        revisionNotification.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    } else {
                        // If no notification, scroll to edit form
                        const editMode = document.getElementById('edit-mode');
                        if (editMode) {
                            editMode.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }
                    
                    // Show a helpful message
                    Swal.fire({
                        icon: 'info',
                        title: '📝 Revision Required',
                        html: '<div style="text-align: left;">' +
                              '<p style="margin-bottom: 10px;">Please update your profile information as requested by the administrator.</p>' +
                              '<p style="color: #856404; background: #fff3cd; padding: 10px; border-radius: 5px; margin-top: 10px;">' +
                              '<i class="fas fa-exclamation-triangle"></i> ' +
                              'Make the necessary changes and click <strong>Save Changes</strong> when done. ' +
                              'Your enrollment will automatically return to pending status.</p>' +
                              '</div>',
                        confirmButtonText: 'Got it',
                        confirmButtonColor: '#f39c12',
                        background: '#fff8e7',
                        color: '#856404'
                    });
                } else {
                    console.log('toggleEdit function not found');
                }
            }, 800);
            <?php endif; ?>
        });
    </script>

    <script>
        // ============= LIGHTBOX FUNCTIONS =============
        // Open lightbox with image
        function openLightbox(imageSrc, caption) {
            const lightbox = document.getElementById('lightbox-modal');
            const lightboxImage = document.getElementById('lightbox-image');
            const lightboxCaption = document.getElementById('lightbox-caption');
            
            lightboxImage.src = imageSrc;
            lightboxCaption.textContent = caption;
            lightbox.style.display = 'flex';
            
            // Prevent body scrolling
            document.body.style.overflow = 'hidden';
        }

        // Close lightbox
        function closeLightbox() {
            const lightbox = document.getElementById('lightbox-modal');
            lightbox.style.display = 'none';
            
            // Restore body scrolling
            document.body.style.overflow = 'auto';
        }

        // ============= DOCUMENT UPLOAD FUNCTIONS =============
        // State management
        let isEditing = false;
        let selectedDocumentType = '';

        // DOM Elements
        const viewMode = document.getElementById('view-mode');
        const editMode = document.getElementById('edit-mode');
        const editBtn = document.getElementById('edit-btn');
        const passwordModal = document.getElementById('password-modal');
        const uploadModal = document.getElementById('upload-modal');
        const saveBtn = document.getElementById('save-btn');
        const changePasswordBtn = document.getElementById('change-password-btn');

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide messages after 5 seconds
            setTimeout(() => {
                const messages = document.querySelectorAll('.message, .error, .warning-message');
                messages.forEach(msg => {
                    msg.style.opacity = '0';
                    msg.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => msg.remove(), 500);
                });
            }, 5000);
            
            <?php if ($has_revision_request): ?>
            // Auto-show edit mode if there's a revision request
            setTimeout(function() {
                toggleEdit();
            }, 500);
            <?php endif; ?>
        });

        // Edit Mode Toggle
        function toggleEdit() {
            isEditing = !isEditing;
            
            if (isEditing) {
                viewMode.style.display = 'none';
                editMode.style.display = 'block';
                editBtn.innerHTML = '<i class="fas fa-times"></i> Cancel';
                editBtn.classList.remove('btn-edit');
                editBtn.classList.add('btn-cancel');
                document.body.classList.add('editing-mode');
                // Scroll to edit form
                editMode.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                viewMode.style.display = 'block';
                editMode.style.display = 'none';
                editBtn.innerHTML = '<i class="fas fa-edit"></i> Edit Profile';
                editBtn.classList.remove('btn-cancel');
                editBtn.classList.add('btn-edit');
                document.body.classList.remove('editing-mode');
            }
        }

        // Form Validation
        function validateEditForm() {
            const form = document.querySelector('#edit-mode form');
            const requiredInputs = form.querySelectorAll('input[required], select[required]');
            let isValid = true;
            let firstError = null;
            
            requiredInputs.forEach(input => {
                input.classList.remove('error');
                if (!input.value.trim()) {
                    input.classList.add('error');
                    isValid = false;
                    if (!firstError) firstError = input;
                } else if (input.type === 'email' && !validateEmail(input.value)) {
                    input.classList.add('error');
                    isValid = false;
                    if (!firstError) firstError = input;
                } else if (input.name === 'contact_number' && !validatePhone(input.value)) {
                    input.classList.add('error');
                    isValid = false;
                    if (!firstError) firstError = input;
                }
            });
            
            // Validate birthday
            const birthdayInput = form.querySelector('input[name="birthday"]');
            if (birthdayInput.value) {
                const birthday = new Date(birthdayInput.value);
                const today = new Date();
                if (birthday > today) {
                    birthdayInput.classList.add('error');
                    isValid = false;
                    if (!firstError) firstError = birthdayInput;
                }
            }
            
            if (!isValid && firstError) {
                firstError.focus();
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                alert('Please fill in all required fields correctly.');
                return false;
            }
            
            // Show loading state
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            saveBtn.disabled = true;
            saveBtn.classList.add('loading');
            
            return true;
        }

        // Password form validation
        function validatePasswordForm() {
            const currentPassword = document.getElementById('current-password').value;
            const newPassword = document.getElementById('new-password').value;
            const confirmPassword = document.getElementById('confirm-password').value;
            
            // Clear previous errors
            document.querySelectorAll('.input-field').forEach(input => input.classList.remove('error'));
            
            if (!currentPassword) {
                document.getElementById('current-password').classList.add('error');
                document.getElementById('current-password').focus();
                alert('Please enter your current password.');
                return false;
            }
            
            if (!newPassword) {
                document.getElementById('new-password').classList.add('error');
                document.getElementById('new-password').focus();
                alert('Please enter a new password.');
                return false;
            }
            
            if (newPassword.length < 6) {
                document.getElementById('new-password').classList.add('error');
                document.getElementById('new-password').focus();
                alert('New password must be at least 6 characters long.');
                return false;
            }
            
            if (newPassword === currentPassword) {
                document.getElementById('new-password').classList.add('error');
                document.getElementById('new-password').focus();
                alert('New password must be different from current password.');
                return false;
            }
            
            if (newPassword !== confirmPassword) {
                document.getElementById('confirm-password').classList.add('error');
                document.getElementById('confirm-password').focus();
                alert('New passwords do not match.');
                return false;
            }
            
            // Show loading state
            changePasswordBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Changing...';
            changePasswordBtn.disabled = true;
            changePasswordBtn.classList.add('loading');
            
            return true;
        }

        // Password Strength Checker
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('password-strength-bar');
            let strength = 0;
            
            if (password.length >= 6) strength += 25;
            if (/[a-z]/.test(password)) strength += 25;
            if (/[A-Z]/.test(password)) strength += 25;
            if (/[0-9]/.test(password)) strength += 25;
            
            strengthBar.style.width = strength + '%';
            
            if (strength < 50) {
                strengthBar.style.backgroundColor = '#ef4444';
            } else if (strength < 75) {
                strengthBar.style.backgroundColor = '#f59e0b';
            } else {
                strengthBar.style.backgroundColor = '#10b981';
            }
        }

        // Password Management
        function openChangePassword() {
            passwordModal.style.display = 'flex';
            setTimeout(() => {
                passwordModal.classList.add('active');
            }, 10);
            document.getElementById('password-form').reset();
            document.getElementById('password-strength-bar').style.width = '0%';
        }

        function closeChangePassword() {
            passwordModal.classList.remove('active');
            setTimeout(() => {
                passwordModal.style.display = 'none';
            }, 300);
            document.getElementById('password-form').reset();
            document.getElementById('password-strength-bar').style.width = '0%';
            
            // Reset button state
            changePasswordBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Change Password';
            changePasswordBtn.disabled = false;
            changePasswordBtn.classList.remove('loading');
        }

        function togglePassword(inputId, toggleId) {
            const input = document.getElementById(inputId);
            const toggle = document.getElementById(toggleId);
            
            if (input.type === 'password') {
                input.type = 'text';
                toggle.classList.remove('fa-eye');
                toggle.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                toggle.classList.remove('fa-eye-slash');
                toggle.classList.add('fa-eye');
            }
        }

        // ============= DOCUMENT UPLOAD FUNCTIONS =============
        // Select document type with replacement warning
        function selectDocumentType(type) {
            selectedDocumentType = type;
            
            // Update UI
            document.getElementById('document_type_single').value = type;
            
            // Remove active class from all buttons
            document.querySelectorAll('.document-type-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Add active class to selected button
            document.getElementById(`type-${type}`).classList.add('active');
            
            // Show upload area
            document.getElementById('upload-area').style.display = 'block';
            
            // Enable upload button
            document.getElementById('upload-btn').disabled = false;
            
            // Show replacement warning
            const warningEl = document.getElementById('replacement-warning');
            warningEl.style.display = 'flex';
        }

        // Open upload modal
        function openUploadModal(documentType = null) {
            uploadModal.style.display = 'flex';
            setTimeout(() => {
                uploadModal.classList.add('active');
            }, 10);
            
            // Reset form
            document.getElementById('upload-form').reset();
            document.getElementById('upload-area').style.display = 'none';
            document.getElementById('document_filename').style.display = 'none';
            document.getElementById('upload-btn').disabled = true;
            document.getElementById('replacement-warning').style.display = 'none';
            
            // Remove active class from all buttons
            document.querySelectorAll('.document-type-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Set document type if specified
            if (documentType) {
                selectDocumentType(documentType);
            }
        }

        function closeUploadModal() {
            uploadModal.classList.remove('active');
            setTimeout(() => {
                uploadModal.style.display = 'none';
            }, 300);
            
            // Reset form
            document.getElementById('upload-form').reset();
            document.getElementById('upload-area').style.display = 'none';
            document.getElementById('document_filename').style.display = 'none';
            document.getElementById('upload-btn').disabled = true;
            document.getElementById('replacement-warning').style.display = 'none';
            selectedDocumentType = '';
        }

        function showFileName(input, displayId) {
            const display = document.getElementById(displayId);
            if (input.files && input.files[0]) {
                display.style.display = 'flex';
                display.innerHTML = '<i class="fas fa-file"></i> ' + input.files[0].name;
            } else {
                display.style.display = 'none';
            }
        }

        function validateUploadForm() {
            if (!selectedDocumentType) {
                alert('Please select a document type.');
                return false;
            }
            
            const fileInput = document.getElementById('document_file');
            if (!fileInput.files || !fileInput.files[0]) {
                alert('Please select a file to upload.');
                return false;
            }
            
            // Validate file size (5MB max)
            const maxSize = 5 * 1024 * 1024; // 5MB
            if (fileInput.files[0].size > maxSize) {
                alert('File size must be less than 5MB.');
                return false;
            }
            
            // Validate file type
            const fileName = fileInput.files[0].name;
            const extension = fileName.split('.').pop().toLowerCase();
            const allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
            
            if (!allowedExtensions.includes(extension)) {
                alert('Only JPG, PNG, and PDF files are allowed.');
                return false;
            }
            
            // Show confirmation for replacement
            <?php if (!empty($valid_id_docs) || !empty($voters_cert_docs)): ?>
            let hasExistingDoc = false;
            if (selectedDocumentType === 'valid_id' && <?php echo !empty($valid_id_docs) ? 'true' : 'false'; ?>) {
                hasExistingDoc = true;
            } else if (selectedDocumentType === 'voters_certificate' && <?php echo !empty($voters_cert_docs) ? 'true' : 'false'; ?>) {
                hasExistingDoc = true;
            }
            
            if (hasExistingDoc) {
                if (!confirm('WARNING: Uploading a new document will permanently DELETE and REPLACE your existing document. This action cannot be undone. Are you sure you want to continue?')) {
                    return false;
                }
            }
            <?php endif; ?>
            
            // Show loading state
            const uploadBtn = document.getElementById('upload-btn');
            uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            uploadBtn.disabled = true;
            uploadBtn.classList.add('loading');
            
            return true;
        }

        // ============= INLINE DOCUMENT UPLOAD FUNCTIONS =============
        // Show selected filename for inline uploads
        function showInlineFileName(input, displayId) {
            const display = document.getElementById(displayId);
            if (input.files && input.files[0]) {
                display.style.display = 'flex';
                display.innerHTML = '<i class="fas fa-check-circle"></i> Selected: ' + input.files[0].name + 
                                   ' (' + (input.files[0].size / 1024).toFixed(1) + ' KB)';
            } else {
                display.style.display = 'none';
            }
        }

        // Validate inline upload form
        function validateInlineUpload(documentType, event) {
            const fileInput = documentType === 'valid_id' ? 
                document.getElementById('valid_id_file_edit') : 
                document.getElementById('voters_file_edit');
            
            if (!fileInput.files || !fileInput.files[0]) {
                alert('Please select a file to upload.');
                return false;
            }
            
            // Validate file size (5MB max)
            const maxSize = 5 * 1024 * 1024;
            if (fileInput.files[0].size > maxSize) {
                alert('File size must be less than 5MB.');
                return false;
            }
            
            // Validate file type
            const fileName = fileInput.files[0].name;
            const extension = fileName.split('.').pop().toLowerCase();
            const allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
            
            if (!allowedExtensions.includes(extension)) {
                alert('Only JPG, PNG, and PDF files are allowed.');
                return false;
            }
            
            // Show replacement confirmation
            <?php if (!empty($valid_id_docs) || !empty($voters_cert_docs)): ?>
            let hasExistingDoc = false;
            if (documentType === 'valid_id' && <?php echo !empty($valid_id_docs) ? 'true' : 'false'; ?>) {
                hasExistingDoc = true;
            } else if (documentType === 'voters_certificate' && <?php echo !empty($voters_cert_docs) ? 'true' : 'false'; ?>) {
                hasExistingDoc = true;
            }
            
            if (hasExistingDoc) {
                if (!confirm('WARNING: Uploading a new document will permanently DELETE and REPLACE your existing document. This action cannot be undone. Are you sure you want to continue?')) {
                    return false;
                }
            }
            <?php endif; ?>
            
            // Show loading state
            const submitBtn = event.submitter;
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            submitBtn.disabled = true;
            
            // Re-enable after 30 seconds (timeout protection)
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 30000);
            
            return true;
        }

        // Utility Functions
        function validateEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }

        function validatePhone(phone) {
            const phoneRegex = /^[0-9+\-\s()]{7,15}$/;
            return phoneRegex.test(phone.replace(/\s+/g, ''));
        }

        // Close modals when clicking outside
        if (passwordModal) {
            passwordModal.addEventListener('click', function(event) {
                if (event.target === passwordModal) {
                    closeChangePassword();
                }
            });
        }

        if (uploadModal) {
            uploadModal.addEventListener('click', function(event) {
                if (event.target === uploadModal) {
                    closeUploadModal();
                }
            });
        }

        // Close lightbox when clicking outside
        const lightboxModal = document.getElementById('lightbox-modal');
        if (lightboxModal) {
            lightboxModal.addEventListener('click', function(event) {
                if (event.target === this) {
                    closeLightbox();
                }
            });
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                if (passwordModal && passwordModal.classList.contains('active')) {
                    closeChangePassword();
                }
                if (uploadModal && uploadModal.classList.contains('active')) {
                    closeUploadModal();
                }
                if (lightboxModal && lightboxModal.style.display === 'flex') {
                    closeLightbox();
                }
                if (isEditing) {
                    toggleEdit();
                }
            }
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>