<?php
// user-management.php (in admin folder)
session_start();

// Include db.php from the root directory
include __DIR__ . '/../db.php';

// Include PHPMailer
require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check if user is admin and logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Check if database connection is established
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Handle PIN Location Settings Update (for trainers)
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_pin_settings'])) {
    $pin_latitude = floatval($_POST['pin_latitude'] ?? 0);
    $pin_longitude = floatval($_POST['pin_longitude'] ?? 0);
    $pin_radius = intval($_POST['pin_radius'] ?? 100);
    $pin_location_name = $conn->real_escape_string($_POST['pin_location_name'] ?? '');
    
    // Update all trainers with the new PIN location settings
    $stmt = $conn->prepare("UPDATE users SET pin_latitude = ?, pin_longitude = ?, pin_radius = ?, pin_location_name = ? WHERE role = 'trainer'");
    $stmt->bind_param("ddss", $pin_latitude, $pin_longitude, $pin_radius, $pin_location_name);
    
    if($stmt->execute()) {
        $_SESSION['flash'] = 'PIN location settings updated successfully for all trainers!';
    } else {
        $_SESSION['flash'] = 'Error updating PIN location settings: ' . $conn->error;
    }
    $stmt->close();
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=pin_location");
    exit();
}

// Handle Reset PIN Location for individual trainer
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_user_pin'])) {
    $user_id = intval($_POST['user_id']);
    
    $stmt = $conn->prepare("UPDATE users SET pin_latitude = NULL, pin_longitude = NULL, pin_radius = 100, pin_location_name = NULL WHERE id = ? AND role = 'trainer'");
    $stmt->bind_param("i", $user_id);
    
    if($stmt->execute()) {
        $_SESSION['flash'] = 'PIN location reset for trainer successfully!';
    } else {
        $_SESSION['flash'] = 'Error resetting PIN location: ' . $conn->error;
    }
    $stmt->close();
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=pin_location");
    exit();
}

// Handle Update Single Trainer PIN Location
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_user_pin'])) {
    $user_id = intval($_POST['user_id']);
    $pin_latitude = floatval($_POST['pin_latitude'] ?? 0);
    $pin_longitude = floatval($_POST['pin_longitude'] ?? 0);
    $pin_radius = intval($_POST['pin_radius'] ?? 100);
    $pin_location_name = $conn->real_escape_string($_POST['pin_location_name'] ?? '');
    
    $stmt = $conn->prepare("UPDATE users SET pin_latitude = ?, pin_longitude = ?, pin_radius = ?, pin_location_name = ? WHERE id = ? AND role = 'trainer'");
    $stmt->bind_param("ddssi", $pin_latitude, $pin_longitude, $pin_radius, $pin_location_name, $user_id);
    
    if($stmt->execute()) {
        $_SESSION['flash'] = 'Trainer PIN location updated successfully!';
    } else {
        $_SESSION['flash'] = 'Error updating trainer PIN location: ' . $conn->error;
    }
    $stmt->close();
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=pin_location");
    exit();
}

/**
 * Generate easy-to-remember 12-character password
 */
function generateEasyPasswordPHP($length = 12) {
    $words = ['Sun', 'Moon', 'Star', 'Tree', 'Book', 'Door', 'Bird', 'Fish', 'Cake', 'Ball',
              'Rain', 'Snow', 'Wind', 'Fire', 'Water', 'Apple', 'Peach', 'Lemon', 'Berry', 'Cloud'];
    
    $numberPatterns = ['123', '456', '789', '101', '202', '303', '777', '888', '999'];
    $specials = ['@', '#', '$', '!', '&', '*'];
    
    $word1 = $words[array_rand($words)];
    $word2 = $words[array_rand($words)];
    $numbers = $numberPatterns[array_rand($numberPatterns)];
    $special = $specials[array_rand($specials)];
    
    $password = $word1 . $numbers . $word2 . $special;
    
    // Ensure exactly 12 characters
    if (strlen($password) > 12) {
        $password = substr($password, 0, 12);
    } elseif (strlen($password) < 12) {
        $allChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789@#$!&*';
        while (strlen($password) < 12) {
            $password .= $allChars[rand(0, strlen($allChars) - 1)];
        }
    }
    
    return $password;
}

/**
 * Send trainer credentials with PHPMailer (SENDS ACTUAL PASSWORD)
 */
function sendTrainerCredentialsPHPMailer($email, $full_name, $password, $specialization = '') {
    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Invalid email address: $email");
    }
    
    try {
        // Create PHPMailer instance
        $mail = new PHPMailer(true);
        
        // Server settings - UPDATE THESE WITH YOUR CREDENTIALS
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lems.superadmn@gmail.com';
        $mail->Password   = 'gubivcizhhkewkda';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Recipients
        $mail->setFrom('lems.superadmn@gmail.com', 'Training Platform');
        $mail->addAddress($email, $full_name);
        $mail->addReplyTo('lems.superadmn@gmail.com', 'Support Team');
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your Trainer Account Credentials';
        $mail->Body = getEmailTemplate($full_name, $email, $password, $specialization);
        $mail->AltBody = getPlainTextTemplate($full_name, $email, $password, $specialization);
        
        // Send email
        $mail->send();
        
        error_log("Trainer credentials sent successfully to: $email");
        return true;
        
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        throw new Exception("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}

/**
 * HTML Email Template with actual password
 */
function getEmailTemplate($full_name, $email, $password, $specialization) {
    $specialization_html = $specialization ? 
        "<p><strong>Specialization:</strong> " . htmlspecialchars($specialization) . "</p>" : "";
    
    $login_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/login.php";
    
    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Trainer Account Credentials</title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                line-height: 1.6; 
                color: #333; 
                margin: 0; 
                padding: 0; 
                background-color: #f4f4f4;
            }
            .container { 
                max-width: 600px; 
                margin: 0 auto; 
                background: white; 
                border-radius: 8px; 
                overflow: hidden;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .header { 
                background: #0d9488; 
                color: white; 
                padding: 30px 20px; 
                text-align: center; 
            }
            .header h2 { 
                margin: 0; 
                font-size: 24px; 
            }
            .content { 
                padding: 30px; 
            }
            .credentials { 
                background: #f8f9fa; 
                padding: 20px; 
                border-left: 4px solid #0d9488; 
                margin: 20px 0; 
                border-radius: 4px;
            }
            .button { 
                background: #0d9488; 
                color: white; 
                padding: 12px 30px; 
                text-decoration: none; 
                border-radius: 5px; 
                display: inline-block; 
                margin: 15px 0; 
                font-weight: bold;
            }
            .footer { 
                text-align: center; 
                padding: 20px; 
                color: #666; 
                font-size: 14px;
                background: #f8f9fa;
                border-top: 1px solid #e9ecef;
            }
            .password-box {
                background: #e8f5e8;
                border: 2px solid #4caf50;
                padding: 15px;
                border-radius: 5px;
                font-size: 18px;
                font-weight: bold;
                text-align: center;
                margin: 15px 0;
                color: #2e7d32;
            }
            .security-note {
                background: #e3f2fd;
                border-left: 4px solid #2196f3;
                padding: 12px;
                margin: 15px 0;
                border-radius: 4px;
                font-size: 14px;
            }
            .specialization-badge {
                background: #0d9488;
                color: white;
                padding: 8px 16px;
                border-radius: 20px;
                display: inline-block;
                font-weight: bold;
                margin: 10px 0;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Welcome to Our Training Platform!</h2>
            </div>
            <div class='content'>
                <p>Hello <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
                <p>Your trainer account has been successfully created. Here are your login credentials:</p>
                
                <div class='credentials'>
                    <h3 style='margin-top: 0;'>Your Login Credentials:</h3>
                    <p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
                    $specialization_html
                    
                    <div class='password-box'>
                        Your Password: " . htmlspecialchars($password) . "
                    </div>
                </div>

                <div class='security-note'>
                    <strong>🔒 Keep your credentials secure:</strong> Do not share your password with anyone.
                </div>
                
                <p style='text-align: center;'>
                    <a href='$login_url' class='button'>Login to Your Account</a>
                </p>
            </div>
            <div class='footer'>
                <p>If you have any questions, please contact our support team.</p>
                <p>This is an automated message, please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Plain Text Template
 */
function getPlainTextTemplate($full_name, $email, $password, $specialization) {
    $specialization_text = $specialization ? "Specialization: $specialization" : "";
    $login_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/login.php";
    
    return "
Welcome to Our Training Platform!

Hello $full_name,

Your trainer account has been successfully created. Here are your login credentials:

YOUR LOGIN CREDENTIALS:
Email: $email
$specialization_text
Password: $password

Login URL: $login_url

🔒 Keep your credentials secure: Do not share your password with anyone.

If you have any questions, please contact our support team.

This is an automated message, please do not reply to this email.
    ";
}

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    if ($_GET['ajax'] == 'check_duplicate') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $fullname = $conn->real_escape_string($_POST['fullname'] ?? '');
        $email = $conn->real_escape_string($_POST['email'] ?? '');
        
        $response = ['hasDuplicate' => false, 'duplicateField' => '', 'message' => ''];
        
        // Check duplicate fullname (excluding current user)
        $fullnameCheck = $conn->query("
            SELECT id FROM users 
            WHERE fullname = '$fullname' 
            AND id != $user_id 
            AND role != 'admin'
        ");
        
        if ($fullnameCheck->num_rows > 0) {
            $response['hasDuplicate'] = true;
            $response['duplicateField'] = 'fullname';
            $response['message'] = 'Full name already exists for another user!';
        }
        
        // Check duplicate email (excluding current user)
        $emailCheck = $conn->query("
            SELECT id FROM users 
            WHERE email = '$email' 
            AND id != $user_id 
            AND role != 'admin'
        ");
        
        if ($emailCheck->num_rows > 0) {
            $response['hasDuplicate'] = true;
            $response['duplicateField'] = 'email';
            $response['message'] = 'Email already exists for another user!';
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    if ($_GET['ajax'] == 'fetch_trainer_programs') {
        $trainer_id = intval($_GET['trainer_id'] ?? 0);
        $specialization = $_GET['specialization'] ?? '';
        
        if ($trainer_id === 0) {
            echo json_encode(['programs' => [], 'assignedPrograms' => []]);
            exit;
        }
        
        // Get programs for this trainer's specialization
        $programs = [];
        $assignedPrograms = [];
        
        // If specialization is provided, filter by it
        if (!empty($specialization)) {
            $stmt = $conn->prepare("
                SELECT p.id, p.name, p.trainer_id, pc.name as category_name 
                FROM programs p 
                LEFT JOIN program_categories pc ON p.category_id = pc.id 
                WHERE pc.name = ? OR p.category_id IS NULL
                ORDER BY pc.name, p.name
            ");
            $stmt->bind_param("s", $specialization);
        } else {
            $stmt = $conn->prepare("
                SELECT p.id, p.name, p.trainer_id, pc.name as category_name 
                FROM programs p 
                LEFT JOIN program_categories pc ON p.category_id = pc.id 
                ORDER BY pc.name, p.name
            ");
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($prog = $result->fetch_assoc()) {
            $programs[] = $prog;
            
            // Check if this program is assigned to this trainer
            if ($prog['trainer_id'] == $trainer_id) {
                $assignedPrograms[] = $prog['id'];
            }
        }
        $stmt->close();
        
        // Also get programs already assigned to this trainer (in case they're not in the filtered list)
        if (!empty($assignedPrograms)) {
            $assignedResult = $conn->query("
                SELECT id FROM programs 
                WHERE trainer_id = $trainer_id
                AND id NOT IN (" . implode(',', $assignedPrograms) . ")
            ");
        } else {
            $assignedResult = $conn->query("
                SELECT id FROM programs 
                WHERE trainer_id = $trainer_id
            ");
        }
        
        while ($row = $assignedResult->fetch_assoc()) {
            $assignedPrograms[] = $row['id'];
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'programs' => $programs,
            'assignedPrograms' => $assignedPrograms
        ]);
        exit;
    }
    
    // AJAX endpoint to get user locations for map - SHOW TRAINERS ONLY
    if ($_GET['ajax'] == 'get_user_locations') {
        $users_result = $conn->query("
            SELECT 
                id, 
                fullname, 
                email, 
                role,
                specialization, 
                program,
                pin_latitude, 
                pin_longitude, 
                pin_radius, 
                pin_location_name
            FROM users 
            WHERE role != 'admin' 
            AND role = 'trainer'  -- Only show trainers on the map
            AND pin_latitude IS NOT NULL 
            AND pin_longitude IS NOT NULL
        ");
        
        $users = [];
        if ($users_result) {
            while ($user = $users_result->fetch_assoc()) {
                $users[] = [
                    'id' => $user['id'],
                    'name' => $user['fullname'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'specialization' => $user['specialization'],
                    'program' => $user['program'],
                    'lat' => floatval($user['pin_latitude']),
                    'lng' => floatval($user['pin_longitude']),
                    'radius' => intval($user['pin_radius']),
                    'location_name' => $user['pin_location_name']
                ];
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode($users);
        exit;
    }
}

// Handle Add Trainer
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_trainer'])){
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $email = $conn->real_escape_string($_POST['email']);
    
    // Generate easy-to-remember 12-character password if not provided
    if(empty($_POST['password'])) {
        $generated_password = generateEasyPasswordPHP();
    } else {
        $generated_password = $_POST['password'];
        // Ensure password is at least 12 characters for security
        if(strlen($generated_password) < 12) {
            $generated_password = generateEasyPasswordPHP();
        }
    }
    
    $password = password_hash($generated_password, PASSWORD_BCRYPT);
    $role = 'trainer';
    
    // Check duplicate before processing
    $duplicate_check = $conn->query("
        SELECT id FROM users 
        WHERE (email = '$email' OR fullname = '$full_name') 
        AND role != 'admin'
    ");
    
    if($duplicate_check->num_rows > 0) {
        $_SESSION['flash'] = 'Duplicate found! Full name or email already exists for another user.';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    // Handle specialization - either from select or custom input or new category
    if($_POST['specialization'] === 'custom_new_category' && !empty($_POST['new_category_name'])) {
        // Insert new category
        $new_category_name = $conn->real_escape_string($_POST['new_category_name']);
        $new_category_desc = $conn->real_escape_string($_POST['new_category_description'] ?? '');
        
        $category_stmt = $conn->prepare("INSERT INTO program_categories (name, description, status) VALUES (?, ?, 'active')");
        $category_stmt->bind_param("ss", $new_category_name, $new_category_desc);
        
        if($category_stmt->execute()) {
            $specialization = $new_category_name;
        } else {
            $_SESSION['flash'] = 'Error creating new category: ' . $conn->error;
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        $category_stmt->close();
    } elseif($_POST['specialization'] === 'custom' && !empty($_POST['custom_specialization'])) {
        $specialization = $conn->real_escape_string($_POST['custom_specialization']);
    } else {
        $specialization = $conn->real_escape_string($_POST['specialization']);
    }
    
    $program_ids = $_POST['programs'] ?? [];
    $allow_multiple_programs = isset($_POST['allow_multiple_programs']) ? 1 : 0;

    // Get program names for saving
    $program_names = [];
    if(!empty($program_ids)) {
        $program_ids_str = implode(',', array_map('intval', $program_ids));
        $program_result = $conn->query("SELECT name FROM programs WHERE id IN ($program_ids_str)");
        while($program = $program_result->fetch_assoc()) {
            $program_names[] = $program['name'];
        }
    }

    // Insert trainer with specialization
    $stmt = $conn->prepare("INSERT INTO users (fullname, email, password, role, program, specialization, other_programs, date_created, status) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'active')");
    $program_name = !empty($program_names) ? $program_names[0] : '';
    $other_programs = $allow_multiple_programs && count($program_names) > 1 ? implode(', ', array_slice($program_names, 1)) : null;
    $stmt->bind_param("sssssss", $full_name, $email, $password, $role, $program_name, $specialization, $other_programs);
    
    if($stmt->execute()){
        $trainer_id = $stmt->insert_id;
        
        // Assign programs to trainer
        if(!empty($program_ids)) {
            foreach($program_ids as $program_id) {
                $program_id = intval($program_id);
                $update_stmt = $conn->prepare("UPDATE programs SET trainer_id = ? WHERE id = ?");
                $update_stmt->bind_param("ii", $trainer_id, $program_id);
                $update_stmt->execute();
                $update_stmt->close();
            }
        }
        
        // Send email with specialization
        try {
            $email_sent = sendTrainerCredentialsPHPMailer($email, $full_name, $generated_password, $specialization);
            
            if($email_sent) {
                $_SESSION['flash'] = 'Trainer added successfully! Login credentials have been sent to their email.';
            } else {
                $_SESSION['flash'] = 'Trainer added successfully! But failed to send email credentials.';
            }
        } catch (Exception $e) {
            $_SESSION['flash'] = 'Trainer added successfully! But email sending failed: ' . $e->getMessage();
        }
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users");
        exit;
    } else {
        $_SESSION['flash'] = 'Error adding trainer: ' . $conn->error;
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users");
        exit;
    }
    $stmt->close();
}

// Handle Edit User Form Submission
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_user'])){
    $user_id = intval($_POST['user_id']);
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $email = $conn->real_escape_string($_POST['email']);
    
    // Check for duplicate fullname/email (excluding current user)
    $duplicate_check = $conn->query("
        SELECT id FROM users 
        WHERE (email = '$email' OR fullname = '$full_name') 
        AND id != $user_id 
        AND role != 'admin'
    ");
    
    if($duplicate_check->num_rows > 0) {
        $_SESSION['flash'] = 'Duplicate found! Full name or email already exists for another user.';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    // Handle specialization
    if($_POST['specialization'] === 'custom_new_category' && !empty($_POST['new_category_name'])) {
        // Insert new category
        $new_category_name = $conn->real_escape_string($_POST['new_category_name']);
        $new_category_desc = $conn->real_escape_string($_POST['new_category_description'] ?? '');
        
        $category_stmt = $conn->prepare("INSERT INTO program_categories (name, description, status) VALUES (?, ?, 'active')");
        $category_stmt->bind_param("ss", $new_category_name, $new_category_desc);
        
        if($category_stmt->execute()) {
            $specialization = $new_category_name;
        } else {
            $_SESSION['flash'] = 'Error creating new category: ' . $conn->error;
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        $category_stmt->close();
    } elseif($_POST['specialization'] === 'custom' && !empty($_POST['custom_specialization'])) {
        $specialization = $conn->real_escape_string($_POST['custom_specialization']);
    } else {
        $specialization = $conn->real_escape_string($_POST['specialization']);
    }
    
    $program_ids = $_POST['programs'] ?? [];
    $allow_multiple_programs = isset($_POST['allow_multiple_programs']) ? 1 : 0;

    // Get program names
    $program_names = [];
    if(!empty($program_ids)) {
        $program_ids_str = implode(',', array_map('intval', $program_ids));
        $program_result = $conn->query("SELECT name FROM programs WHERE id IN ($program_ids_str)");
        while($program = $program_result->fetch_assoc()) {
            $program_names[] = $program['name'];
        }
    }

    // Get current user data
    $current_user = $conn->query("SELECT program FROM users WHERE id = $user_id")->fetch_assoc();
    $old_program = $current_user['program'] ?? null;

    // Update user with specialization
    $stmt = $conn->prepare("UPDATE users SET fullname = ?, email = ?, program = ?, specialization = ?, other_programs = ? WHERE id = ?");
    $program_name = !empty($program_names) ? $program_names[0] : '';
    $other_programs = $allow_multiple_programs && count($program_names) > 1 ? implode(', ', array_slice($program_names, 1)) : null;
    $stmt->bind_param("sssssi", $full_name, $email, $program_name, $specialization, $other_programs, $user_id);
    
    if($stmt->execute()){
        // Handle program assignment changes
        if($old_program != $program_name) {
            // Remove trainer from old programs
            $conn->query("UPDATE programs SET trainer_id = NULL WHERE trainer_id = $user_id");
            
            // Assign trainer to new programs
            if(!empty($program_ids)) {
                foreach($program_ids as $program_id) {
                    $program_id = intval($program_id);
                    $update_stmt = $conn->prepare("UPDATE programs SET trainer_id = ? WHERE id = ?");
                    $update_stmt->bind_param("ii", $user_id, $program_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }
        }
        
        $_SESSION['flash'] = 'User updated successfully!';
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users");
        exit;
    } else {
        $_SESSION['flash'] = 'Error updating user: ' . $conn->error;
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=users");
        exit;
    }
    $stmt->close();
}

// Get current tab (only 2 tabs: users and pin_location)
$current_tab = $_GET['tab'] ?? 'users';

// Fetch program categories for specializations
$program_categories = [];
$categories_result = $conn->query("SELECT id, name, description FROM program_categories WHERE status = 'active' ORDER BY name");
if($categories_result) {
    $program_categories = $categories_result->fetch_all(MYSQLI_ASSOC);
}

// Fetch programs with categories
$programs = [];
$programs_result = $conn->query("
    SELECT p.id, p.name, p.trainer_id, pc.name as category_name, pc.id as category_id 
    FROM programs p 
    LEFT JOIN program_categories pc ON p.category_id = pc.id 
    ORDER BY pc.name, p.name
");
if($programs_result) {
    $programs = $programs_result->fetch_all(MYSQLI_ASSOC);
}

// Group programs by category
$programs_by_category = [];
$available_count = 0;
$assigned_count = 0;

foreach($programs as $prog) {
    $category_name = $prog['category_name'] ?: 'Uncategorized';
    if(!isset($programs_by_category[$category_name])) {
        $programs_by_category[$category_name] = [];
    }
    $programs_by_category[$category_name][] = $prog;
    
    if($prog['trainer_id']) {
        $assigned_count++;
    } else {
        $available_count++;
    }
}

// Process GET filters for users tab
$role = $_GET['role'] ?? 'all';
$search = trim($_GET['search'] ?? '');

// Initialize variables
$users = [];
$total_count = 0;

// Only fetch users if on users tab
if($current_tab === 'users') {
    try {
        // SIMPLE AND RELIABLE QUERY BUILDING - EXCLUDE ADMIN USERS
        $sql = "SELECT id, fullname, email, role, program, specialization, other_programs, date_created, status FROM users WHERE role != 'admin'";
        $count_sql = "SELECT COUNT(*) as total FROM users WHERE role != 'admin'";
        
        $params = [];
        $types = '';

        // Apply role filter (but don't allow showing admins)
        if ($role !== 'all' && in_array($role, ['trainer', 'trainee'])) {
            $sql .= " AND role = ?";
            $count_sql .= " AND role = ?";
            $params[] = $role;
            $types .= 's';
        }

        // Apply search filter
        if (!empty($search)) {
            $search_term = "%$search%";
            $sql .= " AND (fullname LIKE ? OR email LIKE ? OR program LIKE ? OR other_programs LIKE ? OR specialization LIKE ?)";
            $count_sql .= " AND (fullname LIKE ? OR email LIKE ? OR program LIKE ? OR other_programs LIKE ? OR specialization LIKE ?)";
            $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
            $types .= str_repeat('s', 5);
        }

        // Add ordering
        $sql .= " ORDER BY date_created DESC";

        // Prepare and execute main query
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $users = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        // Prepare and execute count query
        $count_stmt = $conn->prepare($count_sql);
        if ($count_stmt) {
            if (!empty($params)) {
                $count_stmt->bind_param($types, ...$params);
            }
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $total_row = $count_result->fetch_assoc();
            $total_count = $total_row['total'] ?? 0;
            $count_stmt->close();
        }

    } catch (Exception $e) {
        error_log("User management query error: " . $e->getMessage());
        $flash = "An error occurred while loading users. Please try again.";
    }
}

// Fetch PIN location statistics for pin location tab
$pin_stats = [];
$users_with_pins = [];
$users_without_pins = [];
$trainees_list = [];

if($current_tab === 'pin_location') {
    $stats_result = $conn->query("
        SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN role = 'trainer' AND pin_latitude IS NOT NULL AND pin_longitude IS NOT NULL THEN 1 ELSE 0 END) as trainers_with_pin,
            SUM(CASE WHEN role = 'trainer' AND (pin_latitude IS NULL OR pin_longitude IS NULL) THEN 1 ELSE 0 END) as trainers_without_pin,
            SUM(CASE WHEN role = 'trainee' THEN 1 ELSE 0 END) as total_trainees
        FROM users 
        WHERE role != 'admin'
    ");
    if($stats_result) {
        $pin_stats = $stats_result->fetch_assoc();
    }
    
    // Get all trainers for the PIN location tab (excluding trainees)
    $users_result = $conn->query("
        SELECT 
            id, 
            fullname, 
            email, 
            role,
            specialization, 
            program,
            pin_latitude, 
            pin_longitude, 
            pin_radius, 
            pin_location_name
        FROM users 
        WHERE role != 'admin' 
        AND role = 'trainer'  -- Only show trainers in PIN lists
        ORDER BY 
            CASE WHEN pin_latitude IS NOT NULL THEN 1 ELSE 2 END,
            fullname ASC
    ");
    
    if($users_result) {
        while($user = $users_result->fetch_assoc()) {
            if($user['pin_latitude'] && $user['pin_longitude']) {
                $users_with_pins[] = $user;
            } else {
                $users_without_pins[] = $user;
            }
        }
    }
    
    // Get trainees separately (for display without PIN info)
    $trainees_result = $conn->query("
        SELECT 
            id, 
            fullname, 
            email, 
            role,
            specialization, 
            program
        FROM users 
        WHERE role = 'trainee'
        ORDER BY fullname ASC
    ");
    
    if($trainees_result) {
        $trainees_list = $trainees_result->fetch_all(MYSQLI_ASSOC);
    }
}

// Flash messages
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Include header AFTER all PHP logic
include '../components/header.php';
?>

<style>
  /* Tab Navigation - Only 2 tabs */
  .tab-container {
      margin-bottom: 24px;
  }
  
  .tab-nav {
      display: flex;
      border-bottom: 2px solid #e5e7eb;
      gap: 8px;
      flex-wrap: wrap;
  }
  
  .tab-link {
      padding: 12px 24px;
      background: none;
      border: none;
      border-bottom: 2px solid transparent;
      cursor: pointer;
      font-size: 16px;
      font-weight: 500;
      color: #6b7280;
      transition: all 0.2s;
      margin-bottom: -2px;
  }
  
  .tab-link:hover {
      color: #0d9488;
  }
  
  .tab-link.active {
      color: #0d9488;
      border-bottom-color: #0d9488;
  }
  
  .tab-pane {
      display: none;
  }
  
  .tab-pane.active {
      display: block;
  }
  
  /* Map Styles */
  #googleMap {
      height: 500px;
      width: 100%;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      margin-bottom: 20px;
      z-index: 1;
  }
  
  .map-container {
      background: white;
      border-radius: 8px;
      padding: 20px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      margin-bottom: 20px;
  }
  
  .map-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      flex-wrap: wrap;
      gap: 15px;
  }
  
  .map-controls {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
  }
  
  .user-list-panel {
      background: white;
      border-radius: 8px;
      padding: 20px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      margin-top: 20px;
  }
  
  .user-card {
      border: 1px solid #e5e7eb;
      border-radius: 6px;
      padding: 15px;
      margin-bottom: 10px;
      background: #f8f9fa;
      transition: all 0.2s;
  }
  
  .user-card:hover {
      background: #f0f9ff;
      border-color: #0d9488;
      box-shadow: 0 2px 8px rgba(13, 148, 136, 0.1);
  }
  
  .user-card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 10px;
      flex-wrap: wrap;
      gap: 10px;
  }
  
  .user-name {
      font-weight: 600;
      color: #1f2937;
      font-size: 16px;
  }
  
  .role-badge {
      padding: 4px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 500;
  }
  
  .role-badge.trainer {
      background: #dbeafe;
      color: #1e40af;
  }
  
  .role-badge.trainee {
      background: #dcfce7;
      color: #166534;
  }
  
  .pin-badge {
      padding: 4px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 500;
  }
  
  .pin-badge.set {
      background: #d1fae5;
      color: #065f46;
  }
  
  .pin-badge.not-set {
      background: #fee2e2;
      color: #991b1b;
  }
  
  .pin-badge.hidden {
      background: #f3e8ff;
      color: #6b21a8;
  }
  
  .user-details {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 10px;
      margin: 10px 0;
      font-size: 13px;
  }
  
  .user-detail-item {
      color: #4b5563;
  }
  
  .user-detail-label {
      font-weight: 500;
      color: #6b7280;
      margin-right: 5px;
  }
  
  .pin-form-container {
      margin-top: 15px;
      padding: 15px;
      background: #f0f9ff;
      border-radius: 6px;
      border-left: 4px solid #0d9488;
      animation: slideDown 0.3s ease-out;
  }
  
  @keyframes slideDown {
      from {
          opacity: 0;
          transform: translateY(-10px);
      }
      to {
          opacity: 1;
          transform: translateY(0);
      }
  }
  
  .pin-coordinates {
      display: flex;
      gap: 10px;
      margin-bottom: 10px;
  }
  
  .pin-coordinate-input {
      flex: 1;
  }
  
  .pin-coordinate-input label {
      display: block;
      margin-bottom: 5px;
      font-size: 12px;
      color: #4b5563;
  }
  
  .pin-coordinate-input input {
      width: 100%;
      padding: 8px;
      border: 1px solid #d1d5db;
      border-radius: 4px;
      font-size: 13px;
  }
  
  .pin-coordinate-input input:focus {
      outline: none;
      border-color: #0d9488;
      box-shadow: 0 0 0 2px rgba(13, 148, 136, 0.1);
  }
  
  .radius-input {
      margin-bottom: 10px;
  }
  
  .radius-input label {
      display: block;
      margin-bottom: 5px;
      font-size: 12px;
      color: #4b5563;
  }
  
  .radius-input input[type="range"] {
      width: 100%;
  }
  
  .radius-value-display {
      font-weight: bold;
      color: #0d9488;
      margin-left: 10px;
  }
  
  .map-legend {
      background: white;
      padding: 10px 15px;
      border-radius: 4px;
      box-shadow: 0 1px 5px rgba(0,0,0,0.1);
      margin-bottom: 10px;
      font-size: 12px;
      display: flex;
      gap: 20px;
      flex-wrap: wrap;
  }
  
  .legend-item {
      display: flex;
      align-items: center;
      margin: 5px 0;
  }
  
  .legend-color {
      width: 20px;
      height: 20px;
      border-radius: 50%;
      margin-right: 8px;
  }
  
  .legend-color.trainer {
      background: #3b82f6; /* Blue for trainers */
  }
  
  .legend-color.radius {
      background: rgba(59, 130, 246, 0.2);
      border: 2px dashed #3b82f6;
      border-radius: 0;
      width: 30px;
      height: 2px;
  }
  
  .legend-color.selected {
      background: #34A853;
  }
  
  .legend-color.trainee-hidden {
      background: #10b981; /* Green for hidden trainees */
  }
  
  /* PIN Settings Styles */
  .pin-settings-container {
      background: white;
      border-radius: 8px;
      padding: 24px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
  }
  
  .pin-stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
  }
  
  .pin-stat-card {
      background: #f8f9fa;
      border: 1px solid #e9ecef;
      border-radius: 8px;
      padding: 20px;
      text-align: center;
  }
  
  .pin-stat-value {
      font-size: 32px;
      font-weight: bold;
      color: #0d9488;
      margin-bottom: 8px;
  }
  
  .pin-stat-label {
      color: #6b7280;
      font-size: 14px;
  }
  
  .coordinates-input-group {
      display: flex;
      gap: 16px;
      margin-bottom: 16px;
  }
  
  .coordinates-input {
      flex: 1;
  }
  
  .coordinates-input label {
      display: block;
      margin-bottom: 8px;
      font-weight: 500;
      color: #374151;
  }
  
  .coordinates-input input {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 14px;
  }
  
  .coordinates-input input:focus {
      outline: none;
      border-color: #0d9488;
      box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
  }
  
  .coordinates-input input[readonly] {
      background-color: #f3f4f6;
      cursor: pointer;
  }
  
  .radius-slider {
      width: 100%;
      margin: 10px 0;
  }
  
  .radius-value {
      font-weight: bold;
      color: #0d9488;
  }
  
  .pin-info-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
  }
  
  .pin-info-table th {
      background: #f8f9fa;
      padding: 12px;
      text-align: left;
      font-weight: 600;
      color: #374151;
      border-bottom: 2px solid #e5e7eb;
  }
  
  .pin-info-table td {
      padding: 12px;
      border-bottom: 1px solid #e5e7eb;
  }
  
  .pin-info-table tr:hover {
      background: #f8f9fa;
  }
  
  .pin-set-badge {
      background: #d1fae5;
      color: #065f46;
      padding: 4px 8px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 500;
  }
  
  .pin-not-set-badge {
      background: #fee2e2;
      color: #991b1b;
      padding: 4px 8px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 500;
  }
  
  .pin-bulk-actions {
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      border-radius: 8px;
      padding: 20px;
      margin: 20px 0;
  }
  
  .pin-bulk-actions h3 {
      margin-top: 0;
      color: #0369a1;
  }
  
  .pin-bulk-form {
      display: flex;
      flex-direction: column;
      gap: 16px;
  }
  
  /* Filter styles */
  .filter-form {
      display: flex;
      gap: 12px;
      align-items: center;
      margin-bottom: 20px;
      padding: 20px;
      background: #f8f9fa;
      border-radius: 8px;
      border: 1px solid #e9ecef;
      flex-wrap: wrap;
  }
  
  .role-select {
      width: 160px;
      padding: 8px 12px;
      border: 1px solid #ddd;
      border-radius: 4px;
      background: white;
  }
  
  .search-input {
      flex: 1;
      min-width: 250px;
      padding: 8px 12px;
      border: 1px solid #ddd;
      border-radius: 4px;
  }
  
  .user-count {
      margin-bottom: 16px;
      color: #333;
      font-size: 18px;
  }
  
  .count-small {
      color: #6b7280;
      font-size: 14px;
  }
  
  .table-responsive {
      overflow-x: auto;
      border: 1px solid #e9ecef;
      border-radius: 8px;
  }
  
  .user-avatar {
      display: flex;
      align-items: center;
      gap: 12px;
  }
  
  .avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: #eef2ff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      color: #3730a3;
      font-size: 14px;
  }
  
  .user-info {
      display: flex;
      flex-direction: column;
  }
  
  .user-name {
      font-weight: 600;
      color: #333;
  }
  
  .user-program {
      font-size: 12px;
      color: #6b7280;
      margin-top: 2px;
  }
  
  .date-cell {
      font-size: 13px;
      color: #6b7280;
  }
  
  .email-cell {
      font-size: 13px;
      color: #374151;
  }
  
  .inline-form {
      display: inline;
  }
  
  .badge {
      padding: 4px 8px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 500;
  }
  
  .badge-trainer {
      background: #dbeafe;
      color: #1e40af;
  }
  
  .badge-trainee {
      background: #dcfce7;
      color: #166534;
  }
  
  .badge-specialization {
      background: #f0f9ff;
      color: #0369a1;
      border: 1px solid #bae6fd;
      font-size: 11px;
      margin-top: 4px;
  }
  
  .status-active {
      color: #059669;
      font-weight: 500;
  }
  
  .status-inactive {
      color: #dc2626;
      font-weight: 500;
  }
  
  .status-pending {
      color: #d97706;
      font-weight: 500;
  }
  
  .actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
  }
  
  .btn {
      padding: 6px 12px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      text-decoration: none;
      font-size: 13px;
      display: inline-block;
      text-align: center;
      transition: all 0.2s;
  }
  
  .btn-blue {
      background: #3b82f6;
      color: white;
  }
  
  .btn-blue:hover {
      background: #2563eb;
  }
  
  .btn-ghost {
      background: #f8f9fa;
      color: #374151;
      border: 1px solid #d1d5db;
  }
  
  .btn-ghost:hover {
      background: #e5e7eb;
  }
  
  .btn-yellow {
      background: #f59e0b;
      color: white;
  }
  
  .btn-yellow:hover {
      background: #d97706;
  }
  
  .btn-red {
      background: #ef4444;
      color: white;
  }
  
  .btn-red:hover {
      background: #dc2626;
  }
  
  .btn-green {
      background: #0d9488;
      color: white;
  }
  
  .btn-green:hover {
      background: #0f766e;
  }
  
  .btn-teal {
      background: #14b8a6;
      color: white;
  }
  
  .btn-teal:hover {
      background: #0d9488;
  }
  
  .btn-purple {
      background: #8b5cf6;
      color: white;
  }
  
  .btn-purple:hover {
      background: #7c3aed;
  }
  
  .btn-sm {
      padding: 4px 8px;
      font-size: 12px;
  }
  
  .table {
      width: 100%;
      border-collapse: collapse;
      background: white;
  }
  
  .table th {
      background: #f8f9fa;
      padding: 12px 16px;
      text-align: left;
      font-weight: 600;
      color: #374151;
      border-bottom: 1px solid #e9ecef;
  }
  
  .table td {
      padding: 12px 16px;
      border-bottom: 1px solid #e9ecef;
  }
  
  .table tr:hover {
      background: #f8f9fa;
  }
  
  .empty {
      text-align: center;
      padding: 40px;
      color: #6b7280;
      font-style: italic;
  }
  
  .page-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 24px;
      padding-bottom: 16px;
      border-bottom: 1px solid #e9ecef;
      flex-wrap: wrap;
      gap: 15px;
  }
  
  .page-header h1 {
      margin: 0;
      color: #1f2937;
  }
  
  .notice {
      padding: 12px 16px;
      background: #d1fae5;
      color: #065f46;
      border-radius: 4px;
      margin-bottom: 20px;
      border: 1px solid #a7f3d0;
  }
  
  .notice.error {
      background: #fee2e2;
      color: #991b1b;
      border-color: #fecaca;
  }
  
  .filter-indicator {
      background: #e0f2fe;
      color: #0369a1;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 12px;
      margin-left: auto;
  }
  
  .search-location-btn {
      background: #0d9488;
      color: white;
      border: none;
      padding: 8px 12px;
      border-radius: 4px;
      cursor: pointer;
      font-size: 13px;
      display: inline-flex;
      align-items: center;
      gap: 5px;
  }
  
  .search-location-btn:hover {
      background: #0f766e;
  }
  
  .coordinates-preview {
      background: #f0f9ff;
      padding: 10px;
      border-radius: 4px;
      margin: 10px 0;
      font-size: 13px;
      border-left: 3px solid #0d9488;
  }
  
  /* Modal Styles */
  .modal-backdrop {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.5);
      display: none;
      justify-content: center;
      align-items: center;
      z-index: 1000;
  }
  
  .modal-content {
      background: white;
      border-radius: 8px;
      padding: 24px;
      max-width: 600px;
      width: 90%;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 4px 20px rgba(0,0,0,0.2);
  }
  
  .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      padding-bottom: 10px;
      border-bottom: 1px solid #e5e7eb;
  }
  
  .modal-header h2 {
      margin: 0;
      color: #1f2937;
  }
  
  .close-btn {
      font-size: 24px;
      cursor: pointer;
      color: #6b7280;
  }
  
  .close-btn:hover {
      color: #1f2937;
  }
  
  .form-group {
      margin-bottom: 20px;
  }
  
  .modal-label {
      display: block;
      margin-bottom: 5px;
      font-weight: 500;
      color: #374151;
  }
  
  .required-field::after {
      content: " *";
      color: #ef4444;
  }
  
  .modal-input, .modal-select {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #d1d5db;
      border-radius: 4px;
      font-size: 14px;
  }
  
  .modal-input:focus, .modal-select:focus {
      outline: none;
      border-color: #0d9488;
      box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
  }
  
  .error-message {
      color: #ef4444;
      font-size: 12px;
      margin-top: 5px;
      display: none;
  }
  
  .email-note {
      background: #f3f4f6;
      padding: 12px;
      border-radius: 4px;
      margin-bottom: 20px;
      font-size: 13px;
      color: #4b5563;
  }
  
  .form-actions {
      display: flex;
      gap: 12px;
      justify-content: flex-end;
      margin-top: 20px;
  }
  
  .modal-button {
      padding: 10px 20px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 14px;
      background: #0d9488;
      color: white;
  }
  
  .modal-button:hover {
      background: #0f766e;
  }
  
  .btn-cancel {
      background: #6b7280;
  }
  
  .btn-cancel:hover {
      background: #4b5563;
  }
  
  .user-role-badge {
      padding: 8px 12px;
      background: #f3f4f6;
      border-radius: 4px;
      font-size: 14px;
      color: #1f2937;
  }

  .program-category-section {
      margin-bottom: 20px;
      padding: 15px;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      background: #fafafa;
  }

  .program-category-title {
      font-weight: 600;
      color: #0d9488;
      margin-bottom: 10px;
      font-size: 16px;
  }

  .program-list {
      display: flex;
      flex-direction: column;
      gap: 8px;
  }

  .program-item {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px;
      border-radius: 4px;
      background: white;
      border: 1px solid #e5e7eb;
  }

  .program-item:hover {
      background: #f8f9fa;
  }

  .custom-specialization {
      margin-top: 10px;
      padding: 10px;
      background: #f0f9ff;
      border-radius: 6px;
      border-left: 4px solid #3b82f6;
  }
  
  @media (max-width: 768px) {
      .filter-form {
          flex-direction: column;
          align-items: stretch;
      }
      .search-input {
          max-width: none;
      }
      .actions {
          flex-direction: column;
      }
      .page-header {
          flex-direction: column;
          align-items: flex-start;
      }
      .table-responsive {
          font-size: 14px;
      }
      .table th,
      .table td {
          padding: 8px 12px;
      }
      .pin-coordinates {
          flex-direction: column;
      }
      .user-card-header {
          flex-direction: column;
          align-items: flex-start;
      }
      .coordinates-input-group {
          flex-direction: column;
      }
      .pin-stats-grid {
          grid-template-columns: 1fr;
      }
      #googleMap {
          height: 350px;
      }
  }
</style>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="page-header">
  <h1>User Management</h1>
  <?php if($current_tab === 'users'): ?>
  <div style="display: flex; gap: 12px;">
    <button class="btn btn-green" id="addTrainerBtn">+ Add Trainer</button>
    <a class="btn btn-yellow" href="archived_users.php">View Archive</a>
  </div>
  <?php endif; ?>
</div>

<?php if($flash): ?>
  <div class="notice <?= strpos($flash, 'Error') !== false || strpos($flash, 'already exists') !== false ? 'error' : '' ?>">
    <?= htmlspecialchars($flash) ?>
  </div>
<?php endif; ?>

<!-- Tab Navigation - Only 2 tabs -->
<div class="tab-container">
  <div class="tab-nav">
    <button class="tab-link <?= $current_tab === 'users' ? 'active' : '' ?>" onclick="switchTab('users')">
      <i class="fas fa-users"></i> Users List
    </button>
    <button class="tab-link <?= $current_tab === 'pin_location' ? 'active' : '' ?>" onclick="switchTab('pin_location')">
      <i class="fas fa-map-marker-alt"></i> Trainer PIN Location
    </button>
  </div>
</div>

<!-- Users List Tab -->
<div id="users-tab" class="tab-pane <?= $current_tab === 'users' ? 'active' : '' ?>">
  <div class="card">
    <form method="get" class="filter-form">
      <input type="hidden" name="tab" value="users">
      <select name="role" class="role-select">
        <option value="all" <?= $role === 'all' ? 'selected' : '' ?>>All Users</option>
        <option value="trainer" <?= $role === 'trainer' ? 'selected' : '' ?>>Trainers</option>
        <option value="trainee" <?= $role === 'trainee' ? 'selected' : '' ?>>Trainees</option>
      </select>

      <input class="search-input" type="text" name="search" placeholder="Search by name, email, program, specialization..." value="<?= htmlspecialchars($search) ?>">
      
      <button class="btn btn-blue" type="submit">Apply Filters</button>
      <a class="btn btn-ghost" href="user-management.php?tab=users">Clear Filters</a>
      
      <?php if(!empty($search) || $role !== 'all'): ?>
        <div class="filter-indicator">
          <?php
            $filter_text = [];
            if ($role !== 'all') {
                $filter_text[] = ucfirst($role) . 's';
            }
            if (!empty($search)) {
                $filter_text[] = "search: '" . htmlspecialchars($search) . "'";
            }
            echo htmlspecialchars(implode(' + ', $filter_text));
          ?>
        </div>
      <?php endif; ?>
    </form>

    <div style="padding: 0 20px 20px 20px;">
      <h3 class="user-count">
        <?php
          $title = 'All Users';
          if ($role !== 'all') {
              $title = ucfirst($role) . 's';
          }
          if (!empty($search)) {
              $title .= " matching '" . htmlspecialchars($search) . "'";
          }
          echo htmlspecialchars($title);
        ?> 
        <small class="count-small">(<?= count($users) ?> out of <?= $total_count ?> total users)</small>
      </h3>

      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>User</th>
              <th>Date Created</th>
              <th>Email</th>
              <th>Role</th>
              <th>Specialization</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if(count($users) === 0): ?>
              <tr>
                <td colspan="7" class="empty">
                  <?php if(!empty($search) || $role !== 'all'): ?>
                    No users found matching your current filters. 
                    <a href="user-management.php?tab=users" style="color: #3b82f6;">Clear filters</a> to see all users.
                  <?php else: ?>
                    No users found in the system.
                  <?php endif; ?>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach($users as $u): ?>
                <tr>
                  <td>
                    <div class="user-avatar">
                      <div class="avatar">
                        <?= strtoupper(substr(htmlspecialchars($u['fullname'] ?? 'U'), 0, 1)) ?>
                      </div>
                      <div class="user-info">
                        <div class="user-name"><?= htmlspecialchars($u['fullname'] ?: 'No Name') ?></div>
                        <div class="user-program">
                          <?= !empty($u['program']) ? htmlspecialchars($u['program']) : 'No program' ?>
                          <?= !empty($u['other_programs']) ? ' + ' . htmlspecialchars($u['other_programs']) : '' ?>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td class="date-cell">
                    <?= date('M j, Y', strtotime($u['date_created'])) ?>
                  </td>
                  <td class="email-cell"><?= htmlspecialchars($u['email']) ?></td>
                  <td>
                    <span class="badge <?= $u['role'] === 'trainer' ? 'badge-trainer' : 'badge-trainee' ?>">
                      <?= htmlspecialchars(ucfirst($u['role'])) ?>
                    </span>
                  </td>
                  <td>
                    <?php if(!empty($u['specialization'])): ?>
                      <span class="badge badge-specialization">
                        <?= htmlspecialchars($u['specialization']) ?>
                      </span>
                    <?php else: ?>
                      <span style="color: #6b7280; font-style: italic;">None</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php
                      $status = $u['status'] ?? 'active';
                      $status_class = 'status-' . $status;
                      $status_display = ucfirst($status);
                    ?>
                    <span class="<?= $status_class ?>">
                      <?= $status_display ?>
                    </span>
                  </td>
                  <td class="actions">
                    <button class="btn btn-ghost edit-user-btn" 
                            data-user-id="<?= (int)$u['id'] ?>" 
                            data-fullname="<?= htmlspecialchars($u['fullname']) ?>" 
                            data-email="<?= htmlspecialchars($u['email']) ?>" 
                            data-role="<?= htmlspecialchars($u['role']) ?>"
                            data-program="<?= htmlspecialchars($u['program']) ?>" 
                            data-specialization="<?= htmlspecialchars($u['specialization'] ?? '') ?>"
                            data-other-programs="<?= htmlspecialchars($u['other_programs'] ?? '') ?>"
                            data-allow-multiple="<?= !empty($u['other_programs']) ? 1 : 0 ?>">Edit</button>
                    
                    <!-- Archive Button -->
                    <form class="inline-form" method="post" action="archive_user.php" onsubmit="return confirm('Are you sure you want to archive this user?');">
                      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                      <button class="btn btn-yellow" type="submit">Archive</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- PIN Location Tab (Trainers on Map, Trainees Hidden) -->
<div id="pin-location-tab" class="tab-pane <?= $current_tab === 'pin_location' ? 'active' : '' ?>">
  
  <!-- Statistics Cards -->
  <div class="pin-stats-grid">
    <div class="pin-stat-card">
      <div class="pin-stat-value"><?= $pin_stats['total_users'] ?? 0 ?></div>
      <div class="pin-stat-label">Total Users (Non-Admin)</div>
    </div>
    <div class="pin-stat-card">
      <div class="pin-stat-value"><?= $pin_stats['trainers_with_pin'] ?? 0 ?></div>
      <div class="pin-stat-label">Trainers with PIN Set</div>
    </div>
    <div class="pin-stat-card">
      <div class="pin-stat-value"><?= $pin_stats['trainers_without_pin'] ?? 0 ?></div>
      <div class="pin-stat-label">Trainers without PIN</div>
    </div>
    <div class="pin-stat-card">
      <div class="pin-stat-value"><?= $pin_stats['total_trainees'] ?? 0 ?></div>
      <div class="pin-stat-label">Trainees (Hidden from Map)</div>
    </div>
  </div>

  <!-- Map Container with Leaflet -->
  <div class="map-container">
    <div class="map-header">
      <h2 style="margin: 0; color: #1f2937;">📍 Trainer PIN Locations on Map</h2>
      <div class="map-controls">
        <button class="btn btn-teal" onclick="centerMapOnAllUsers()">
          <i class="fas fa-location-arrow"></i> Show All Trainers
        </button>
        <button class="btn btn-blue" onclick="searchLocation()">
          <i class="fas fa-search"></i> Search Location
        </button>
        <button class="btn btn-purple" onclick="refreshUserLocations()">
          <i class="fas fa-sync-alt"></i> Refresh
        </button>
      </div>
    </div>
    
    <!-- Search Box -->
    <div style="margin-bottom: 15px; display: flex; gap: 10px;">
      <input type="text" id="locationSearch" class="search-input" placeholder="Search for a location..." style="flex: 1;">
      <button class="btn btn-blue" onclick="performSearch()">Search</button>
    </div>
    
    <!-- Leaflet Map Container -->
    <div id="googleMap"></div>
    
    <!-- Selected Coordinates Display -->
    <div class="coordinates-preview" id="selectedCoordinates" style="display: none;">
      <strong>Selected Location:</strong> 
      <span id="selectedLat"></span>, <span id="selectedLng"></span>
      <button class="btn btn-ghost btn-sm" onclick="clearSelectedLocation()" style="margin-left: 10px;">Clear</button>
    </div>
    
    <!-- Map Legend -->
    <div class="map-legend">
      <div class="legend-item">
        <div class="legend-color trainer"></div>
        <span>Trainer Location</span>
      </div>
      <div class="legend-item">
        <div class="legend-color radius"></div>
        <span>Geofence Radius</span>
      </div>
      <div class="legend-item">
        <div class="legend-color selected"></div>
        <span>Selected Location</span>
      </div>
      <div class="legend-item">
        <div class="legend-color trainee-hidden"></div>
        <span>Trainees (Hidden from Map)</span>
      </div>
    </div>
  </div>

  <!-- Quick Location Set for Selected Trainer -->
  <div class="pin-bulk-actions" id="quickLocationSet" style="display: none;">
    <h3>📍 Set Location for Trainer</h3>
    <p>Apply the selected map location to a trainer:</p>
    
    <select id="quickUserSelect" class="modal-select" style="width: 100%; margin-bottom: 15px;">
      <option value="">Select a trainer...</option>
      <?php 
      // Combine trainers with and without pins
      $all_trainers = array_merge($users_with_pins, $users_without_pins);
      foreach($all_trainers as $trainer): 
      ?>
        <option value="<?= $trainer['id'] ?>">
          <?= htmlspecialchars($trainer['fullname']) ?> - <?= $trainer['pin_latitude'] ? 'Has PIN' : 'No PIN' ?>
        </option>
      <?php endforeach; ?>
    </select>
    
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
      <input type="number" id="quickRadius" class="modal-input" placeholder="Radius (meters)" value="100" min="10" max="1000" style="width: 150px;">
      <input type="text" id="quickLocationName" class="modal-input" placeholder="Location name (optional)" style="flex: 1;">
      <button class="btn btn-teal" onclick="applyLocationToUser()">Apply to Trainer</button>
      <button class="btn btn-ghost" onclick="document.getElementById('quickLocationSet').style.display='none'">Cancel</button>
    </div>
  </div>

  <!-- Bulk Update Form (For Trainers Only) -->
  <div class="pin-bulk-actions">
    <h3>📍 Set Default PIN Location for All Trainers</h3>
    <p class="description" style="color: #6b7280; margin-bottom: 20px;">
      Configure the default PIN location settings that will be applied to all trainers. Click on the map to select coordinates.
      <br><strong>Note:</strong> Trainees are not included in bulk updates and are hidden from the map.
    </p>
    
    <form method="POST" action="" class="pin-bulk-form" onsubmit="return confirm('This will update PIN location for ALL trainers. Continue?');">
      <input type="hidden" name="update_pin_settings" value="1">
      
      <div class="coordinates-input-group">
        <div class="coordinates-input">
          <label for="pin_latitude">Latitude</label>
          <input type="number" id="pin_latitude" name="pin_latitude" step="any" 
                 value="<?= isset($users_with_pins[0]) ? $users_with_pins[0]['pin_latitude'] : '14.5995' ?>" 
                 placeholder="e.g., 14.5995" readonly required>
          <small style="color: #6b7280;">Click on map to set</small>
        </div>
        <div class="coordinates-input">
          <label for="pin_longitude">Longitude</label>
          <input type="number" id="pin_longitude" name="pin_longitude" step="any" 
                 value="<?= isset($users_with_pins[0]) ? $users_with_pins[0]['pin_longitude'] : '120.9842' ?>" 
                 placeholder="e.g., 120.9842" readonly required>
          <small style="color: #6b7280;">Click on map to set</small>
        </div>
      </div>
      
      <div class="coordinates-input-group">
        <div class="coordinates-input">
          <label for="pin_radius">PIN Radius (meters)</label>
          <input type="range" id="pin_radius_slider" class="radius-slider" min="10" max="1000" 
                 value="<?= isset($users_with_pins[0]) ? $users_with_pins[0]['pin_radius'] : 100 ?>" 
                 oninput="updateBulkRadiusValue(this.value)">
          <input type="number" id="pin_radius" name="pin_radius" min="10" max="1000" 
                 value="<?= isset($users_with_pins[0]) ? $users_with_pins[0]['pin_radius'] : 100 ?>" 
                 oninput="updateBulkRadiusSlider(this.value)">
          <span class="radius-value" id="bulk_radius_display">
            <?= isset($users_with_pins[0]) ? $users_with_pins[0]['pin_radius'] : 100 ?> meters
          </span>
        </div>
        <div class="coordinates-input">
          <label for="pin_location_name">Location Name (Optional)</label>
          <input type="text" id="pin_location_name" name="pin_location_name" 
                 value="<?= isset($users_with_pins[0]) ? htmlspecialchars($users_with_pins[0]['pin_location_name']) : 'Main Office' ?>" 
                 placeholder="e.g., Main Office, Training Center">
        </div>
      </div>
      
      <div style="display: flex; gap: 12px; justify-content: flex-end;">
        <button type="button" class="btn btn-blue" onclick="useSelectedLocation()">
          <i class="fas fa-map-marker-alt"></i> Use Selected Location
        </button>
        <button type="submit" class="btn btn-teal" style="padding: 10px 20px;">
          <i class="fas fa-save"></i> Update PIN Location for All Trainers
        </button>
      </div>
    </form>
  </div>
  
  <!-- Trainers with PIN List -->
  <div class="user-list-panel">
    <h3 style="margin-top: 0; color: #1f2937;">📍 Trainers with PIN Location Set (<?= count($users_with_pins) ?>)</h3>
    
    <?php if(count($users_with_pins) === 0): ?>
      <div class="empty">No trainers have set their PIN location yet.</div>
    <?php else: ?>
      <?php foreach($users_with_pins as $user): ?>
        <div class="user-card" id="user-card-<?= $user['id'] ?>">
          <div class="user-card-header">
            <div>
              <span class="user-name"><?= htmlspecialchars($user['fullname']) ?></span>
              <span class="role-badge trainer">Trainer</span>
            </div>
            <span class="pin-badge set">
              <i class="fas fa-check-circle"></i> PIN Set
            </span>
          </div>
          
          <div class="user-details">
            <div class="user-detail-item">
              <span class="user-detail-label">Email:</span> <?= htmlspecialchars($user['email']) ?>
            </div>
            <div class="user-detail-item">
              <span class="user-detail-label">Specialization:</span> <?= htmlspecialchars($user['specialization'] ?: 'None') ?>
            </div>
            <div class="user-detail-item">
              <span class="user-detail-label">Program:</span> <?= htmlspecialchars($user['program'] ?: 'None') ?>
            </div>
            <div class="user-detail-item">
              <span class="user-detail-label">Location:</span> <?= htmlspecialchars($user['pin_location_name'] ?: 'Not specified') ?>
            </div>
            <div class="user-detail-item">
              <span class="user-detail-label">Coordinates:</span> 
              <?= number_format($user['pin_latitude'], 6) ?>, <?= number_format($user['pin_longitude'], 6) ?>
            </div>
            <div class="user-detail-item">
              <span class="user-detail-label">Radius:</span> <?= $user['pin_radius'] ?> meters
            </div>
          </div>
          
          <div style="display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap;">
            <button class="btn btn-ghost" onclick="focusOnUser(<?= $user['id'] ?>, <?= $user['pin_latitude'] ?>, <?= $user['pin_longitude'] ?>)">
              <i class="fas fa-search-location"></i> Focus on Map
            </button>
            <button class="btn btn-ghost" onclick="showEditPinForm(<?= $user['id'] ?>)">
              <i class="fas fa-edit"></i> Edit PIN
            </button>
            <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Reset PIN location for this trainer?');">
              <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
              <input type="hidden" name="reset_user_pin" value="1">
              <button type="submit" class="btn btn-ghost">
                <i class="fas fa-undo"></i> Reset
              </button>
            </form>
          </div>
          
          <!-- Edit PIN Form (Hidden by default) -->
          <div id="edit-pin-form-<?= $user['id'] ?>" class="pin-form-container" style="display: none;">
            <form method="POST" action="" onsubmit="return validatePinForm(<?= $user['id'] ?>)">
              <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
              <input type="hidden" name="update_user_pin" value="1">
              
              <div class="pin-coordinates">
                <div class="pin-coordinate-input">
                  <label>Latitude</label>
                  <input type="number" id="lat_<?= $user['id'] ?>" name="pin_latitude" step="any" 
                         value="<?= $user['pin_latitude'] ?>" required>
                </div>
                <div class="pin-coordinate-input">
                  <label>Longitude</label>
                  <input type="number" id="lng_<?= $user['id'] ?>" name="pin_longitude" step="any" 
                         value="<?= $user['pin_longitude'] ?>" required>
                </div>
              </div>
              
              <div class="radius-input">
                <label>
                  Radius (meters): <span id="radius_display_<?= $user['id'] ?>" class="radius-value-display"><?= $user['pin_radius'] ?></span>
                </label>
                <input type="range" id="radius_slider_<?= $user['id'] ?>" name="pin_radius" 
                       min="10" max="1000" value="<?= $user['pin_radius'] ?>" 
                       oninput="updateRadiusDisplay(<?= $user['id'] ?>, this.value)">
              </div>
              
              <div class="pin-coordinate-input" style="margin-bottom: 10px;">
                <label>Location Name (Optional)</label>
                <input type="text" name="pin_location_name" value="<?= htmlspecialchars($user['pin_location_name'] ?: '') ?>" 
                       placeholder="e.g., Main Office, Training Center">
              </div>
              
              <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-blue" onclick="selectOnMap(<?= $user['id'] ?>)">
                  <i class="fas fa-map-marker-alt"></i> Select on Map
                </button>
                <button type="submit" class="btn btn-teal">Update PIN</button>
                <button type="button" class="btn btn-ghost" onclick="hideEditPinForm(<?= $user['id'] ?>)">Cancel</button>
              </div>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  
  <!-- Trainers without PIN List -->
  <div class="user-list-panel" style="margin-top: 20px;">
    <h3 style="margin-top: 0; color: #991b1b;">⚠️ Trainers without PIN Location (<?= count($users_without_pins) ?>)</h3>
    
    <?php if(count($users_without_pins) === 0): ?>
      <div class="empty">All trainers have set their PIN location. Great job!</div>
    <?php else: ?>
      <?php foreach($users_without_pins as $user): ?>
        <div class="user-card">
          <div class="user-card-header">
            <div>
              <span class="user-name"><?= htmlspecialchars($user['fullname']) ?></span>
              <span class="role-badge trainer">Trainer</span>
            </div>
            <span class="pin-badge not-set">
              <i class="fas fa-times-circle"></i> PIN Not Set
            </span>
          </div>
          
          <div class="user-details">
            <div class="user-detail-item">
              <span class="user-detail-label">Email:</span> <?= htmlspecialchars($user['email']) ?>
            </div>
            <div class="user-detail-item">
              <span class="user-detail-label">Specialization:</span> <?= htmlspecialchars($user['specialization'] ?: 'None') ?>
            </div>
            <div class="user-detail-item">
              <span class="user-detail-label">Program:</span> <?= htmlspecialchars($user['program'] ?: 'None') ?>
            </div>
          </div>
          
          <div style="margin-top: 10px;">
            <button class="btn btn-ghost" onclick="showEditPinForm(<?= $user['id'] ?>)">
              <i class="fas fa-plus-circle"></i> Set PIN Location
            </button>
          </div>
          
          <!-- Set PIN Form (Hidden by default) -->
          <div id="edit-pin-form-<?= $user['id'] ?>" class="pin-form-container" style="display: none;">
            <form method="POST" action="" onsubmit="return validatePinForm(<?= $user['id'] ?>)">
              <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
              <input type="hidden" name="update_user_pin" value="1">
              
              <div class="pin-coordinates">
                <div class="pin-coordinate-input">
                  <label>Latitude</label>
                  <input type="number" id="lat_<?= $user['id'] ?>" name="pin_latitude" step="any" 
                         placeholder="e.g., 14.5995" required>
                </div>
                <div class="pin-coordinate-input">
                  <label>Longitude</label>
                  <input type="number" id="lng_<?= $user['id'] ?>" name="pin_longitude" step="any" 
                         placeholder="e.g., 120.9842" required>
                </div>
              </div>
              
              <div class="radius-input">
                <label>
                  Radius (meters): <span id="radius_display_<?= $user['id'] ?>" class="radius-value-display">100</span>
                </label>
                <input type="range" id="radius_slider_<?= $user['id'] ?>" name="pin_radius" 
                       min="10" max="1000" value="100" 
                       oninput="updateRadiusDisplay(<?= $user['id'] ?>, this.value)">
              </div>
              
              <div class="pin-coordinate-input" style="margin-bottom: 10px;">
                <label>Location Name (Optional)</label>
                <input type="text" name="pin_location_name" placeholder="e.g., Main Office, Training Center">
              </div>
              
              <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-blue" onclick="selectOnMap(<?= $user['id'] ?>)">
                  <i class="fas fa-map-marker-alt"></i> Select on Map
                </button>
                <button type="submit" class="btn btn-teal">Set PIN</button>
                <button type="button" class="btn btn-ghost" onclick="hideEditPinForm(<?= $user['id'] ?>)">Cancel</button>
              </div>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div> <!-- Close pin-location-tab -->

<!-- Add Trainer Modal (Enhanced with Programs) -->
<div class="modal-backdrop" id="trainerModalBackdrop">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add Trainer</h2>
            <span class="close-btn" id="closeTrainerModal">&times;</span>
        </div>
        
        <form id="trainerForm" method="POST" action="">
            <!-- Full Name -->
            <div class="form-group">
                <label for="full_name" class="modal-label required-field">Full Name</label>
                <input type="text" id="full_name" name="full_name" class="modal-input" required placeholder="Enter trainer's full name">
                <div class="error-message" id="add_fullname_error"></div>
            </div>
            
            <!-- Email -->
            <div class="form-group">
                <label for="email" class="modal-label required-field">Email Address</label>
                <input type="email" id="email" name="email" class="modal-input" required placeholder="Enter trainer's email">
                <div class="error-message" id="add_email_error"></div>
            </div>
            
            <!-- Specialization (Connected to Program Categories) -->
            <div class="form-group">
                <label for="specialization" class="modal-label required-field">Specialization</label>
                <select name="specialization" id="specialization" class="modal-select" required onchange="toggleCustomSpecialization()">
                    <option value="">Select Specialization</option>
                    <?php foreach($program_categories as $category): ?>
                        <option value="<?= htmlspecialchars($category['name']) ?>">
                            <?= htmlspecialchars($category['name']) ?>
                            <?php if(!empty($category['description'])): ?>
                                - <?= htmlspecialchars($category['description']) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="custom_new_category">+ Add New Category</option>
                </select>
                
                <!-- Container for New Category -->
                <div id="newCategoryContainer" class="custom-specialization" style="display: none; margin-top: 10px;">
                    <label for="new_category" class="modal-label">New Category Name</label>
                    <input type="text" id="new_category" name="new_category_name" class="modal-input" placeholder="Enter new category name">
                    <label for="new_category_desc" class="modal-label" style="margin-top: 10px;">Description (Optional)</label>
                    <input type="text" id="new_category_desc" name="new_category_description" class="modal-input" placeholder="Enter category description">
                </div>
                
               
            </div>
            
         
            
            <!-- Email Note -->
            <div class="email-note">
                <strong>Note:</strong> A 12-character password will be automatically generated and emailed to the trainer.
                <br>The trainer's specialization is connected to the program categories.
            </div>

            <input type="hidden" name="add_trainer" value="1">

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="button" class="modal-button btn-cancel" onclick="closeTrainerModal()">Cancel</button>
                <button type="submit" class="modal-button" id="addSubmitBtn">Add Trainer</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal (Enhanced with Programs) -->
<div class="modal-backdrop" id="editUserModalBackdrop">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Edit User</h2>
            <span class="close-btn" id="closeEditUserModal">&times;</span>
        </div>
        
        <form id="editUserForm" method="POST" action="">
            <input type="hidden" name="user_id" id="edit_user_id">
            <input type="hidden" name="edit_user" value="1">
            
            <!-- Full Name -->
            <div class="form-group">
                <label for="edit_full_name" class="modal-label required-field">Full Name</label>
                <input type="text" id="edit_full_name" name="full_name" class="modal-input" required placeholder="Enter user's full name">
                <div class="error-message" id="edit_fullname_error"></div>
            </div>
            
            <!-- Email -->
            <div class="form-group">
                <label for="edit_email" class="modal-label required-field">Email Address</label>
                <input type="email" id="edit_email" name="email" class="modal-input" required placeholder="Enter user's email">
                <div class="error-message" id="edit_email_error"></div>
            </div>
            
            <!-- Role Display (Read-only) -->
            <div class="form-group">
                <label class="modal-label">Role</label>
                <div class="user-role-badge" id="edit_role_display">Trainer</div>
                <small style="color: #6b7280; font-size: 0.875rem;">User role cannot be changed</small>
            </div>
            
            <!-- Specialization - With New Category Option -->
            <div class="form-group">
                <label for="edit_specialization" class="modal-label required-field">Specialization</label>
                <select name="specialization" id="edit_specialization" class="modal-select" required onchange="toggleEditCustomSpecialization()">
                    <option value="">Select Specialization</option>
                    <?php foreach($program_categories as $category): ?>
                        <option value="<?= htmlspecialchars($category['name']) ?>">
                            <?= htmlspecialchars($category['name']) ?>
                            <?php if(!empty($category['description'])): ?>
                                - <?= htmlspecialchars($category['description']) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="custom_new_category">+ Add New Category</option>
                    <option value="custom">Other (Custom Specialization)</option>
                </select>
                
                <!-- Container for New Category -->
                <div id="editNewCategoryContainer" class="custom-specialization" style="display: none; margin-top: 10px;">
                    <label for="edit_new_category" class="modal-label">New Category Name</label>
                    <input type="text" id="edit_new_category" name="new_category_name" class="modal-input" placeholder="Enter new category name">
                    <label for="edit_new_category_desc" class="modal-label" style="margin-top: 10px;">Description (Optional)</label>
                    <input type="text" id="edit_new_category_desc" name="new_category_description" class="modal-input" placeholder="Enter category description">
                </div>
                
                <!-- Container for Custom Specialization -->
                <div id="editCustomSpecializationContainer" class="custom-specialization" style="display: none; margin-top: 10px;">
                    <label for="edit_custom_specialization" class="modal-label">Custom Specialization</label>
                    <input type="text" id="edit_custom_specialization" name="custom_specialization" class="modal-input" placeholder="Enter custom specialization">
                </div>
            </div>
         
            <!-- Form Actions -->
            <div class="form-actions">
                <button type="button" class="modal-button btn-cancel" onclick="closeEditUserModal()">Cancel</button>
                <button type="submit" class="modal-button" id="editSubmitBtn">Update User</button>
            </div>
        </form>
    </div>
</div>

<script>
// Program data from PHP
const programCategories = <?= json_encode($program_categories) ?>;
const programsByCategory = <?= json_encode($programs_by_category) ?>;

let leafletMap;
let markers = [];
let circles = [];
let selectedMarker = null;
let activeUserId = null;

// Initialize map when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM fully loaded');
    
    // Initialize Leaflet map if on pin location tab
    if (document.getElementById('googleMap') && 
        document.getElementById('pin-location-tab') &&
        document.getElementById('pin-location-tab').classList.contains('active')) {
        initGoogleMap();
    }
    
    // Initialize all event listeners
    initializeEventListeners();
});

// Separate function to initialize all event listeners
function initializeEventListeners() {
    console.log('Initializing event listeners');
    
    // Specialization toggle for add form
    const specializationSelect = document.getElementById('specialization');
    if (specializationSelect) {
        specializationSelect.addEventListener('change', toggleCustomSpecialization);
    }
    
    // Specialization toggle for edit form
    const editSpecializationSelect = document.getElementById('edit_specialization');
    if (editSpecializationSelect) {
        editSpecializationSelect.addEventListener('change', toggleEditCustomSpecialization);
    }
    
    // Add Trainer button
    const addTrainerBtn = document.getElementById('addTrainerBtn');
    if (addTrainerBtn) {
        console.log('Add Trainer button found');
        addTrainerBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('Add Trainer button clicked');
            openTrainerModal();
        });
    } else {
        console.log('Add Trainer button NOT found');
    }
    
    // Modal close buttons
    const closeTrainerBtn = document.getElementById('closeTrainerModal');
    if (closeTrainerBtn) {
        closeTrainerBtn.addEventListener('click', closeTrainerModal);
    }
    
    const closeEditUserBtn = document.getElementById('closeEditUserModal');
    if (closeEditUserBtn) {
        closeEditUserBtn.addEventListener('click', closeEditUserModal);
    }
    
    // Edit user buttons - FIXED VERSION
    attachEditButtonListeners();
    
    // Add trainer form submission
    const trainerForm = document.getElementById('trainerForm');
    if (trainerForm) {
        trainerForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            await handleAddTrainerSubmit(e);
        });
    }
    
    // Edit user form submission
    const editUserForm = document.getElementById('editUserForm');
    if (editUserForm) {
        editUserForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            await handleEditUserSubmit(e);
        });
    }
    
    // Close modals when clicking outside
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
        backdrop.addEventListener('click', function(e) {
            if (e.target === this) {
                if (this.id === 'trainerModalBackdrop') {
                    closeTrainerModal();
                } else if (this.id === 'editUserModalBackdrop') {
                    closeEditUserModal();
                }
            }
        });
    });
    
    // Close modals with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (document.getElementById('trainerModalBackdrop').style.display === 'flex') {
                closeTrainerModal();
            }
            if (document.getElementById('editUserModalBackdrop').style.display === 'flex') {
                closeEditUserModal();
            }
        }
    });
    
    // Clear search input when clear filters is clicked
    const clearLink = document.querySelector('a[href="user-management.php?tab=users"]');
    if (clearLink) {
        clearLink.addEventListener('click', function(e) {
            document.querySelector('input[name="search"]').value = '';
        });
    }
    
    // Add loading state to form submission
    const filterForm = document.querySelector('form[method="get"]');
    if (filterForm) {
        filterForm.addEventListener('submit', function() {
            const submitBtn = filterForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.textContent = 'Filtering...';
                submitBtn.disabled = true;
            }
        });
    }
}

// FIXED: Function to attach edit button listeners
function attachEditButtonListeners() {
    console.log('Attaching edit button listeners');
    
    // Use setTimeout to ensure DOM is fully loaded
    setTimeout(function() {
        const editButtons = document.querySelectorAll('.edit-user-btn');
        console.log('Found ' + editButtons.length + ' edit buttons');
        
        editButtons.forEach(btn => {
            // Remove any existing listeners by cloning and replacing
            const newBtn = btn.cloneNode(true);
            if (btn.parentNode) {
                btn.parentNode.replaceChild(newBtn, btn);
            }
            
            // Add new listener
            newBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Edit button clicked for user');
                
                // Get data attributes from the button
                const userId = this.getAttribute('data-user-id');
                const fullName = this.getAttribute('data-fullname');
                const email = this.getAttribute('data-email');
                const role = this.getAttribute('data-role');
                const program = this.getAttribute('data-program');
                const specialization = this.getAttribute('data-specialization');
                const otherPrograms = this.getAttribute('data-other-programs');
                const allowMultiple = this.getAttribute('data-allow-multiple') === '1';
                
                console.log('Edit button clicked with data:', {
                    userId, fullName, email, role, program, specialization, otherPrograms, allowMultiple
                });
                
                // Validate required data
                if (!userId || !fullName || !email) {
                    console.error('Missing required user data');
                    alert('Error: Missing user data. Please refresh the page and try again.');
                    return;
                }
                
                // Prepare user data object
                const userData = {
                    userId: userId,
                    fullName: fullName,
                    email: email,
                    role: role || 'trainer',
                    program: program || '',
                    specialization: specialization || '',
                    otherPrograms: otherPrograms || '',
                    allowMultiple: allowMultiple
                };
                
                // Open the edit modal with the user data
                openEditUserModal(userData);
            });
        });
    }, 100); // Small delay to ensure DOM is ready
}

// Handle add trainer form submission
async function handleAddTrainerSubmit(e) {
    const fullName = document.getElementById('full_name').value.trim();
    const email = document.getElementById('email').value.trim();
    const specialization = document.getElementById('specialization').value;
    const customSpecialization = document.getElementById('custom_specialization')?.value || '';
    const newCategory = document.getElementById('new_category')?.value || '';
    const submitBtn = document.getElementById('addSubmitBtn');
    
    // Clear previous errors
    document.getElementById('add_fullname_error').style.display = 'none';
    document.getElementById('add_email_error').style.display = 'none';
    
    // Validation
    if (!fullName) {
        document.getElementById('add_fullname_error').textContent = 'Please enter a full name';
        document.getElementById('add_fullname_error').style.display = 'block';
        return;
    }
    
    if (!email) {
        document.getElementById('add_email_error').textContent = 'Please enter an email';
        document.getElementById('add_email_error').style.display = 'block';
        return;
    }
    
    if (!specialization) {
        alert('Please select a specialization');
        return;
    }
    
    if (specialization === 'custom' && !customSpecialization) {
        alert('Please enter a custom specialization');
        return;
    }
    
    if (specialization === 'custom_new_category' && !newCategory) {
        alert('Please enter a new category name');
        return;
    }
    
    // Generate password
    const generatedPassword = generatePassword();
    
    // Add password to form
    const passwordInput = document.createElement('input');
    passwordInput.type = 'hidden';
    passwordInput.name = 'password';
    passwordInput.value = generatedPassword;
    e.target.appendChild(passwordInput);
    
    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.textContent = 'Checking...';
    
    // Check for duplicates
    const duplicateCheck = await checkDuplicateBeforeSubmit(0, fullName, email);
    
    if (duplicateCheck.hasDuplicate) {
        if (duplicateCheck.duplicateField === 'fullname') {
            document.getElementById('add_fullname_error').textContent = duplicateCheck.message;
            document.getElementById('add_fullname_error').style.display = 'block';
        } else if (duplicateCheck.duplicateField === 'email') {
            document.getElementById('add_email_error').textContent = duplicateCheck.message;
            document.getElementById('add_email_error').style.display = 'block';
        }
        
        // Re-enable submit button and remove password input
        submitBtn.disabled = false;
        submitBtn.textContent = 'Add Trainer';
        e.target.removeChild(passwordInput);
        return;
    }
    
    // Submit the form
    submitBtn.textContent = 'Adding Trainer...';
    e.target.submit();
}

// Handle edit user form submission
async function handleEditUserSubmit(e) {
    const userId = document.getElementById('edit_user_id').value;
    const fullname = document.getElementById('edit_full_name').value.trim();
    const email = document.getElementById('edit_email').value.trim();
    const submitBtn = document.getElementById('editSubmitBtn');
    
    // Clear previous errors
    document.getElementById('edit_fullname_error').style.display = 'none';
    document.getElementById('edit_email_error').style.display = 'none';
    
    // Validation
    if (!fullname || !email) {
        alert('Please fill in all required fields');
        return;
    }
    
    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.textContent = 'Checking...';
    
    // Check for duplicates
    const duplicateCheck = await checkDuplicateBeforeSubmit(userId, fullname, email);
    
    if (duplicateCheck.hasDuplicate) {
        if (duplicateCheck.duplicateField === 'fullname') {
            document.getElementById('edit_fullname_error').textContent = duplicateCheck.message;
            document.getElementById('edit_fullname_error').style.display = 'block';
        } else if (duplicateCheck.duplicateField === 'email') {
            document.getElementById('edit_email_error').textContent = duplicateCheck.message;
            document.getElementById('edit_email_error').style.display = 'block';
        }
        
        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.textContent = 'Update User';
        return;
    }
    
    // If no duplicates, submit the form
    submitBtn.textContent = 'Updating...';
    
    // Handle new category if selected
    const specializationSelect = document.getElementById('edit_specialization');
    if (specializationSelect && specializationSelect.value === 'custom_new_category') {
        const newCategory = document.getElementById('edit_new_category').value.trim();
        if (newCategory) {
            // Add hidden input for new category
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'new_category_name';
            hiddenInput.value = newCategory;
            e.target.appendChild(hiddenInput);
            
            const newCategoryDesc = document.getElementById('edit_new_category_desc').value.trim();
            if (newCategoryDesc) {
                const descInput = document.createElement('input');
                descInput.type = 'hidden';
                descInput.name = 'new_category_description';
                descInput.value = newCategoryDesc;
                e.target.appendChild(descInput);
            }
        }
    }
    
    // Submit the form
    e.target.submit();
}

// Toggle custom specialization input for add form
function toggleCustomSpecialization() {
    const specializationSelect = document.getElementById('specialization');
    const customContainer = document.getElementById('customSpecializationContainer');
    const newCategoryContainer = document.getElementById('newCategoryContainer');
    
    if (specializationSelect.value === 'custom') {
        customContainer.style.display = 'block';
        newCategoryContainer.style.display = 'none';
    } else if (specializationSelect.value === 'custom_new_category') {
        newCategoryContainer.style.display = 'block';
        customContainer.style.display = 'none';
    } else {
        customContainer.style.display = 'none';
        newCategoryContainer.style.display = 'none';
    }
}

// Toggle custom specialization input for edit form
function toggleEditCustomSpecialization() {
    const specializationSelect = document.getElementById('edit_specialization');
    const customContainer = document.getElementById('editCustomSpecializationContainer');
    const newCategoryContainer = document.getElementById('editNewCategoryContainer');
    
    if (specializationSelect.value === 'custom') {
        customContainer.style.display = 'block';
        newCategoryContainer.style.display = 'none';
    } else if (specializationSelect.value === 'custom_new_category') {
        newCategoryContainer.style.display = 'block';
        customContainer.style.display = 'none';
    } else {
        customContainer.style.display = 'none';
        newCategoryContainer.style.display = 'none';
    }
}

// Initialize Google Map (Leaflet)
function initGoogleMap() {
    const mapEl = document.getElementById('googleMap');
    if (!mapEl) return;

    leafletMap = L.map('googleMap').setView([14.5995, 120.9842], 11);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19
    }).addTo(leafletMap);

    // Click map to select location
    leafletMap.on('click', function(e) {
        selectLocation(e.latlng.lat, e.latlng.lng);
    });

    loadUserLocations();
}

function selectLocation(lat, lng, address = '') {
    document.getElementById('pin_latitude').value = parseFloat(lat).toFixed(6);
    document.getElementById('pin_longitude').value = parseFloat(lng).toFixed(6);
    document.getElementById('selectedCoordinates').style.display = 'block';
    document.getElementById('selectedLat').textContent = parseFloat(lat).toFixed(6);
    document.getElementById('selectedLng').textContent = parseFloat(lng).toFixed(6);

    // Remove previous selected marker
    if (selectedMarker) {
        leafletMap.removeLayer(selectedMarker);
        selectedMarker = null;
    }

    // Green marker for selected location
    const greenIcon = L.icon({
        iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-green.png',
        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
        iconSize: [25, 41],
        iconAnchor: [12, 41],
        popupAnchor: [1, -34]
    });

    selectedMarker = L.marker([lat, lng], { icon: greenIcon })
        .addTo(leafletMap)
        .bindPopup('📍 Selected Location')
        .openPopup();

    // Auto-fill location name using free Nominatim reverse geocoding
    if (!address) {
        fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`)
            .then(r => r.json())
            .then(data => {
                if (data.display_name) {
                    document.getElementById('pin_location_name').value = data.display_name;
                    const quickName = document.getElementById('quickLocationName');
                    if (quickName) quickName.value = data.display_name;
                }
            })
            .catch(() => {}); // silently fail if no internet
    } else {
        document.getElementById('pin_location_name').value = address;
    }

    if (activeUserId) {
        document.getElementById('quickLocationSet').style.display = 'block';
    }
}

function clearSelectedLocation() {
    document.getElementById('selectedCoordinates').style.display = 'none';
    document.getElementById('pin_latitude').value = '';
    document.getElementById('pin_longitude').value = '';

    if (selectedMarker) {
        leafletMap.removeLayer(selectedMarker);
        selectedMarker = null;
    }
}

function performSearch() {
    const query = document.getElementById('locationSearch').value.trim();
    if (!query) return;

    // Free geocoding via Nominatim (no API key needed)
    fetch(`https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(query)}&format=json&limit=1`)
        .then(r => r.json())
        .then(data => {
            if (data.length > 0) {
                const lat = parseFloat(data[0].lat);
                const lng = parseFloat(data[0].lon);
                leafletMap.setView([lat, lng], 15);
                selectLocation(lat, lng, data[0].display_name);
            } else {
                alert('Location not found. Try a different search term.');
            }
        })
        .catch(() => alert('Search failed. Check your internet connection.'));
}

function searchLocation() {
    performSearch();
}

function getCurrentLocation() {
    if (!navigator.geolocation) {
        alert('Geolocation is not supported by your browser.');
        return;
    }
    navigator.geolocation.getCurrentPosition(
        function(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            leafletMap.setView([lat, lng], 15);
            selectLocation(lat, lng);
        },
        function() {
            alert('Could not get your location. Please allow location access.');
        }
    );
}

function addUserToMap(user) {
    const blueIcon = L.icon({
        iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-blue.png',
        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
        iconSize: [25, 41],
        iconAnchor: [12, 41],
        popupAnchor: [1, -34]
    });

    const marker = L.marker([user.lat, user.lng], { icon: blueIcon })
        .addTo(leafletMap)
        .bindPopup(`
            <div style="min-width:180px; font-size:13px;">
                <div style="font-weight:bold; color:#3b82f6; margin-bottom:6px; padding-bottom:6px; border-bottom:1px solid #e5e7eb;">
                    ${user.name} (Trainer)
                </div>
                <p style="margin:4px 0;">📍 <strong>Location:</strong> ${user.location_name || 'Not specified'}</p>
                <p style="margin:4px 0;">📧 <strong>Email:</strong> ${user.email}</p>
                <p style="margin:4px 0;">🎯 <strong>Specialization:</strong> ${user.specialization || 'None'}</p>
                <p style="margin:4px 0;">📋 <strong>Program:</strong> ${user.program || 'None'}</p>
                <p style="margin:4px 0;">🗺️ <strong>Coordinates:</strong> ${user.lat.toFixed(6)}, ${user.lng.toFixed(6)}</p>
                <p style="margin:4px 0;">⚡ <strong>Radius:</strong> ${user.radius}m</p>
            </div>
        `);

    marker.on('click', function() {
        highlightUserCard(user.id);
    });

    // Geofence radius circle
    const circle = L.circle([user.lat, user.lng], {
        radius: user.radius,
        color: '#3b82f6',
        fillColor: '#3b82f6',
        fillOpacity: 0.1,
        weight: 2,
        dashArray: '5, 5'
    }).addTo(leafletMap);

    markers.push({ id: user.id, marker: marker, circle: circle });
    circles.push(circle);
}

function clearMapLayers() {
    markers.forEach(m => {
        leafletMap.removeLayer(m.marker);
        leafletMap.removeLayer(m.circle);
    });
    markers = [];
    circles.forEach(c => leafletMap.removeLayer(c));
    circles = [];
}

function loadUserLocations() {
    clearMapLayers();

    fetch('user-management.php?ajax=get_user_locations')
        .then(response => response.json())
        .then(users => {
            users.forEach(user => addUserToMap(user));

            // Fit map to show all markers
            if (markers.length > 0) {
                const group = L.featureGroup(markers.map(m => m.marker));
                leafletMap.fitBounds(group.getBounds().pad(0.2));
            }
        })
        .catch(error => console.error('Error loading user locations:', error));
}

function centerMapOnAllUsers() {
    if (markers.length === 0) {
        alert('No trainers with PIN locations to show.');
        return;
    }
    const group = L.featureGroup(markers.map(m => m.marker));
    leafletMap.fitBounds(group.getBounds().pad(0.2));
}

function focusOnUser(userId, lat, lng) {
    leafletMap.setView([parseFloat(lat), parseFloat(lng)], 16);

    const found = markers.find(m => m.id == userId);
    if (found) {
        found.marker.openPopup();
        // Bounce effect using CSS
        const el = found.marker.getElement();
        if (el) {
            el.style.transition = 'transform 0.3s';
            el.style.transform = 'scale(1.5)';
            setTimeout(() => el.style.transform = 'scale(1)', 600);
        }
    }

    document.getElementById('googleMap').scrollIntoView({ behavior: 'smooth', block: 'start' });
    highlightUserCard(userId);
}

function refreshUserLocations() {
    clearMapLayers();
    loadUserLocations();
}

function useSelectedLocation() {
    const lat = document.getElementById('pin_latitude').value;
    const lng = document.getElementById('pin_longitude').value;
    if (!lat || !lng) {
        alert('Please click on the map to select a location first.');
        return;
    }
    alert('Location ready! Click "Update PIN Location for All Trainers" to save.');
}

function selectOnMap(userId) {
    activeUserId = userId;
    document.getElementById('quickLocationSet').style.display = 'block';

    const select = document.getElementById('quickUserSelect');
    for (let i = 0; i < select.options.length; i++) {
        if (select.options[i].value == userId) {
            select.selectedIndex = i;
            break;
        }
    }

    const latEl = document.getElementById(`lat_${userId}`);
    const lngEl = document.getElementById(`lng_${userId}`);
    if (latEl && lngEl && latEl.value && lngEl.value) {
        leafletMap.setView([parseFloat(latEl.value), parseFloat(lngEl.value)], 15);
        const radiusEl = document.getElementById(`radius_slider_${userId}`);
        if (radiusEl) document.getElementById('quickRadius').value = radiusEl.value;
    }

    document.getElementById('googleMap').scrollIntoView({ behavior: 'smooth' });
}

function applyLocationToUser() {
    const userId = document.getElementById('quickUserSelect').value;
    const lat = document.getElementById('pin_latitude').value;
    const lng = document.getElementById('pin_longitude').value;
    const radius = document.getElementById('quickRadius').value;
    const locationName = document.getElementById('quickLocationName').value;

    if (!userId) { alert('Please select a trainer.'); return; }
    if (!lat || !lng) { alert('Please click on the map to select a location first.'); return; }

    const latField = document.getElementById(`lat_${userId}`);
    const lngField = document.getElementById(`lng_${userId}`);

    if (latField && lngField) {
        latField.value = lat;
        lngField.value = lng;

        const radiusField = document.getElementById(`radius_slider_${userId}`);
        const radiusDisplay = document.getElementById(`radius_display_${userId}`);
        if (radiusField) { radiusField.value = radius; }
        if (radiusDisplay) { radiusDisplay.textContent = radius + ' meters'; }

        const locationNameField = document.querySelector(`#edit-pin-form-${userId} input[name="pin_location_name"]`);
        if (locationNameField && locationName) locationNameField.value = locationName;

        alert('Location applied! Open the trainer\'s form and click Save.');
        document.getElementById('quickLocationSet').style.display = 'none';
        activeUserId = null;
    }
}

// Show edit PIN form (for trainers only)
function showEditPinForm(userId) {
    // Hide all other forms first
    document.querySelectorAll('[id^="edit-pin-form-"]').forEach(form => {
        form.style.display = 'none';
    });
    
    // Show the selected form
    const form = document.getElementById(`edit-pin-form-${userId}`);
    if (form) {
        form.style.display = 'block';
        form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

// Hide edit PIN form
function hideEditPinForm(userId) {
    const form = document.getElementById(`edit-pin-form-${userId}`);
    if (form) {
        form.style.display = 'none';
    }
}

// Update radius display
function updateRadiusDisplay(userId, value) {
    const display = document.getElementById(`radius_display_${userId}`);
    if (display) {
        display.textContent = value + ' meters';
    }
}

// Validate PIN form
function validatePinForm(userId) {
    const lat = document.getElementById(`lat_${userId}`).value;
    const lng = document.getElementById(`lng_${userId}`).value;
    
    if (!lat || !lng) {
        alert('Please enter both latitude and longitude.');
        return false;
    }
    
    if (isNaN(lat) || isNaN(lng)) {
        alert('Latitude and longitude must be valid numbers.');
        return false;
    }
    
    if (parseFloat(lat) < -90 || parseFloat(lat) > 90) {
        alert('Latitude must be between -90 and 90.');
        return false;
    }
    
    if (parseFloat(lng) < -180 || parseFloat(lng) > 180) {
        alert('Longitude must be between -180 and 180.');
        return false;
    }
    
    return true;
}

// Bulk update functions
function updateBulkRadiusValue(val) {
    document.getElementById('pin_radius').value = val;
    document.getElementById('bulk_radius_display').textContent = val + ' meters';
}

function updateBulkRadiusSlider(val) {
    document.getElementById('pin_radius_slider').value = val;
    document.getElementById('bulk_radius_display').textContent = val + ' meters';
}

// Tab switching function
function switchTab(tabName) {
    // Update URL without page reload
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tabName);
    window.location.href = url.toString();
}

// Highlight user card
function highlightUserCard(userId) {
    // Remove highlight from all cards
    document.querySelectorAll('.user-card').forEach(card => {
        card.style.backgroundColor = '';
        card.style.borderColor = '';
        card.style.boxShadow = '';
    });
    
    // Highlight the selected card
    const card = document.getElementById(`user-card-${userId}`);
    if (card) {
        card.style.backgroundColor = '#f0f9ff';
        card.style.borderColor = '#0d9488';
        card.style.borderWidth = '2px';
        card.style.boxShadow = '0 4px 12px rgba(13, 148, 136, 0.2)';
        
        // Scroll to card smoothly
        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

// Generate password
function generatePassword() {
    const easyWords = [
        'Sun', 'Moon', 'Star', 'Tree', 'Book', 'Door', 'Bird', 'Fish', 'Cake', 'Ball',
        'Rain', 'Snow', 'Wind', 'Fire', 'Water', 'Apple', 'Peach', 'Lemon', 'Berry', 'Cloud'
    ];
    
    const numberPatterns = ['123', '456', '789', '101', '202', '303', '777', '888', '999'];
    const specials = ['@', '#', '$', '!', '&', '*'];
    
    const word1 = easyWords[Math.floor(Math.random() * easyWords.length)];
    const word2 = easyWords[Math.floor(Math.random() * easyWords.length)];
    const numbers = numberPatterns[Math.floor(Math.random() * numberPatterns.length)];
    const special = specials[Math.floor(Math.random() * specials.length)];
    
    let password = word1 + numbers + word2 + special;
    
    if (password.length > 12) {
        password = password.substring(0, 12);
    } else if (password.length < 12) {
        const allChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789@#$!&*';
        while (password.length < 12) {
            password += allChars[Math.floor(Math.random() * allChars.length)];
        }
    }
    
    return password;
}

// Check for duplicates
async function checkDuplicateBeforeSubmit(userId, fullname, email) {
    try {
        const response = await fetch('user-management.php?ajax=check_duplicate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `user_id=${userId}&fullname=${encodeURIComponent(fullname)}&email=${encodeURIComponent(email)}`
        });
        
        const result = await response.json();
        return result;
    } catch (error) {
        console.error('Error checking duplicate:', error);
        return { hasDuplicate: false, message: '' };
    }
}

// Fetch programs for the selected trainer
async function fetchTrainerPrograms(trainerId, currentSpecialization = '') {
    try {
        const response = await fetch(`user-management.php?ajax=fetch_trainer_programs&trainer_id=${trainerId}&specialization=${encodeURIComponent(currentSpecialization)}`);
        const data = await response.json();
        return data;
    } catch (error) {
        console.error('Error fetching programs:', error);
        return { programs: [], assignedPrograms: [] };
    }
}

// FIXED: Modal functions
function openTrainerModal() {
    console.log('Opening trainer modal');
    const modal = document.getElementById('trainerModalBackdrop');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function closeTrainerModal() {
    document.getElementById('trainerModalBackdrop').style.display = 'none';
    document.getElementById('trainerForm').reset();
    document.getElementById('customSpecializationContainer').style.display = 'none';
    document.getElementById('newCategoryContainer').style.display = 'none';
    document.getElementById('add_fullname_error').style.display = 'none';
    document.getElementById('add_email_error').style.display = 'none';
}

// FIXED: Enhanced openEditUserModal function
function openEditUserModal(userData) {
    console.log('Opening edit modal for user:', userData);
    
    // Validate userData
    if (!userData || !userData.userId) {
        console.error('Invalid user data for edit modal');
        alert('Error: Invalid user data. Please try again.');
        return;
    }
    
    // Set form values
    document.getElementById('edit_user_id').value = userData.userId;
    document.getElementById('edit_full_name').value = userData.fullName || '';
    document.getElementById('edit_email').value = userData.email || '';
    document.getElementById('edit_role_display').textContent = userData.role ? userData.role.charAt(0).toUpperCase() + userData.role.slice(1) : 'User';
    
    // Handle specialization
    const specializationSelect = document.getElementById('edit_specialization');
    if (specializationSelect) {
        let foundInOptions = false;
        
        for (let i = 0; i < specializationSelect.options.length; i++) {
            if (specializationSelect.options[i].value === userData.specialization) {
                specializationSelect.selectedIndex = i;
                foundInOptions = true;
                break;
            }
        }
        
        if (!foundInOptions && userData.specialization) {
            specializationSelect.value = 'custom';
            const customInput = document.getElementById('edit_custom_specialization');
            if (customInput) {
                customInput.value = userData.specialization;
            }
            document.getElementById('editCustomSpecializationContainer').style.display = 'block';
        } else {
            document.getElementById('editCustomSpecializationContainer').style.display = 'none';
        }
        
        document.getElementById('editNewCategoryContainer').style.display = 'none';
    }
    
    // Display the modal
    const modal = document.getElementById('editUserModalBackdrop');
    if (modal) {
        modal.style.display = 'flex';
        console.log('Edit modal displayed');
    } else {
        console.error('Edit modal backdrop not found');
    }
}

function closeEditUserModal() {
    document.getElementById('editUserModalBackdrop').style.display = 'none';
    document.getElementById('editUserForm').reset();
    document.getElementById('editCustomSpecializationContainer').style.display = 'none';
    document.getElementById('editNewCategoryContainer').style.display = 'none';
    document.getElementById('edit_fullname_error').style.display = 'none';
    document.getElementById('edit_email_error').style.display = 'none';
}

// Make functions globally available
window.switchTab = switchTab;
window.centerMapOnAllUsers = centerMapOnAllUsers;
window.refreshUserLocations = refreshUserLocations;
window.focusOnUser = focusOnUser;
window.showEditPinForm = showEditPinForm;
window.hideEditPinForm = hideEditPinForm;
window.updateRadiusDisplay = updateRadiusDisplay;
window.validatePinForm = validatePinForm;
window.updateBulkRadiusValue = updateBulkRadiusValue;
window.updateBulkRadiusSlider = updateBulkRadiusSlider;
window.openTrainerModal = openTrainerModal;
window.closeTrainerModal = closeTrainerModal;
window.openEditUserModal = openEditUserModal;
window.closeEditUserModal = closeEditUserModal;
window.getCurrentLocation = getCurrentLocation;
window.searchLocation = searchLocation;
window.performSearch = performSearch;
window.selectOnMap = selectOnMap;
window.applyLocationToUser = applyLocationToUser;
window.useSelectedLocation = useSelectedLocation;
window.clearSelectedLocation = clearSelectedLocation;
window.toggleCustomSpecialization = toggleCustomSpecialization;
window.toggleEditCustomSpecialization = toggleEditCustomSpecialization;
</script>

</body>
</html>