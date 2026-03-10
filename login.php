<?php
// login.php
session_start();
header_remove("X-Powered-By");

// ============= ADDED: Handle revision redirects =============
if (isset($_GET['redirect']) && $_GET['redirect'] === 'profile.php' && isset($_GET['user_id'])) {
    $_SESSION['post_login_redirect'] = [
        'page' => 'profile.php',
        'user_id' => $_GET['user_id'],
        'revision' => isset($_GET['revision']) ? true : false
    ];
    
    // Store in session for after login
    $_SESSION['redirect_after_login'] = 'profile.php';
    $_SESSION['redirect_user_id'] = $_GET['user_id'];
    $_SESSION['revision_mode'] = isset($_GET['revision']) ? true : false;
}
// ============= END ADDED =============

// Store pending enrollment if coming from program enrollment
if (isset($_GET['program_id'])) {
    $_SESSION['pending_enrollment'] = [
        'program_id' => $_GET['program_id'],
        'program_name' => $_GET['program_name'] ?? '',
        'source' => 'login_page',
        'timestamp' => time()
    ];
    
    // Also store in legacy format for compatibility
    $_SESSION['pending_program_id'] = $_GET['program_id'];
    $_SESSION['pending_program_name'] = $_GET['program_name'] ?? '';
}

// Check for new registration parameters early
if (isset($_GET['new_registration']) && isset($_GET['program_id']) && isset($_GET['email'])) {
    $_SESSION['pending_enrollment'] = [
        'program_id' => $_GET['program_id'],
        'program_name' => $_GET['program_name'] ?? '',
        'source' => 'new_registration',
        'timestamp' => time()
    ];
    
    $_SESSION['temp_registration_email'] = $_GET['email'];
    
    // Also store in legacy format
    $_SESSION['pending_program_id'] = $_GET['program_id'];
    $_SESSION['pending_program_name'] = $_GET['program_name'] ?? '';
}

// If user is already logged in and has pending enrollment, redirect to enrollment
if (isset($_SESSION['user_id']) && isset($_SESSION['pending_enrollment'])) {
    header("Location: enroll-after-login.php");
    exit();
}

// If the request is an AJAX JSON POST, handle login logic and return JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    require_once __DIR__ . '/db.php';
    
    // Debug logging
    error_log("login.php AJAX handler started");
    error_log("GET parameters: " . print_r($_GET, true));
    error_log("POST JSON body: " . file_get_contents('php://input'));
    error_log("Session pending_enrollment: " . (isset($_SESSION['pending_enrollment']) ? print_r($_SESSION['pending_enrollment'], true) : 'not set'));
    
    // Read JSON body
    $body = json_decode(file_get_contents('php://input'), true);
    $email = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    $response = ['success' => false, 'message' => 'Invalid request'];

    if ($email === '' || $password === '') {
        $response['message'] = 'Email and password are required.';
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    // Check for pending enrollment from URL parameters or session
    $program_id = null;
    $program_name = '';
    $enrollment_data = null;

    // First check new session structure
    if (isset($_SESSION['pending_enrollment']) && is_array($_SESSION['pending_enrollment'])) {
        $enrollment_data = $_SESSION['pending_enrollment'];
        $program_id = $enrollment_data['program_id'] ?? null;
        $program_name = $enrollment_data['program_name'] ?? '';
    } 
    // Then check legacy session variables
    elseif (isset($_SESSION['pending_program_id'])) {
        $program_id = $_SESSION['pending_program_id'];
        $program_name = $_SESSION['pending_program_name'] ?? '';
        $enrollment_data = [
            'program_id' => $program_id,
            'program_name' => $program_name
        ];
    }
    // Then check URL parameters
    elseif (isset($_GET['program_id'])) {
        $program_id = $_GET['program_id'];
        $program_name = $_GET['program_name'] ?? '';
        $enrollment_data = [
            'program_id' => $program_id,
            'program_name' => $program_name
        ];
    }

    error_log("login.php - Found enrollment data: program_id=" . $program_id . ", program_name=" . $program_name);

    // Prepared statement to fetch the user
    $stmt = $conn->prepare("SELECT id, fullname, email, password, role, status FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $res->num_rows === 1) {
        $row = $res->fetch_assoc();

        // Check status
        if (isset($row['status']) && strtolower($row['status']) !== 'active') {
            $response['message'] = 'Account is not active. Contact administrator.';
            echo json_encode($response);
            exit;
        }

        // Verify hashed password
        if (password_verify($password, $row['password'])) {
            // Auth successful — set session
            session_regenerate_id(true);
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['fullname'] = $row['fullname'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['user_email'] = $row['email'];
            
            // ============= UPDATED: After successful login - Check for redirects =============
            $redirect_url = '';

            // PRIORITY 1: Check for revision redirect (from email link)
            if (isset($_SESSION['redirect_after_login']) && $_SESSION['redirect_after_login'] === 'profile.php') {
                $redirect_url = '/trainee/profile.php';
                if (isset($_SESSION['revision_mode']) && $_SESSION['revision_mode']) {
                    // Add flag to auto-show edit mode
                    $_SESSION['auto_edit_mode'] = true;
                }
                
                // Clear the redirect data
                unset($_SESSION['redirect_after_login']);
                unset($_SESSION['redirect_user_id']);
                unset($_SESSION['revision_mode']);
                unset($_SESSION['post_login_redirect']);
                
                $response = [
                    'success' => true,
                    'message' => 'Login successful. Redirecting to profile...',
                    'user' => [
                        'id' => $row['id'],
                        'fullname' => $row['fullname'],
                        'role' => $row['role']
                    ],
                    'redirect' => $redirect_url,
                    'revision_mode' => true
                ];
            }
            // PRIORITY 2: Check for program enrollment
            else if ($enrollment_data && $program_id) {
                // Store enrollment data in session (preserve all data)
                $_SESSION['pending_enrollment'] = $enrollment_data;
                
                // Also store in legacy format for compatibility
                $_SESSION['pending_program_id'] = $program_id;
                $_SESSION['pending_program_name'] = $program_name;
                
                // Clear temporary registration email if exists
                unset($_SESSION['temp_registration_email']);
                
                // Redirect to enroll-after-login.php
                $redirect_url = "enroll-after-login.php";
                
                $response = [
                    'success' => true,
                    'message' => 'Login successful. Redirecting to program enrollment...',
                    'user' => [
                        'id' => $row['id'],
                        'fullname' => $row['fullname'],
                        'role' => $row['role']
                    ],
                    'redirect' => $redirect_url,
                    'enrollment' => true,
                    'program_id' => $program_id,
                    'program_name' => $program_name
                ];
            } 
            // PRIORITY 3: Regular redirect based on role
            else {
                $role = strtolower($row['role'] ?? '');
                switch ($role) {
                    case 'admin':
                        $redirect_url = '/admin/dashboard';
                        break;
                    case 'trainer':
                        $redirect_url = '/trainer/dashboard';
                        break;
                    case 'trainee':
                        $redirect_url = '/trainee/dashboard';
                        break;
                    default:
                        $redirect_url = '/';
                }
                
                // Clear any old pending enrollment data
                unset($_SESSION['pending_enrollment']);
                unset($_SESSION['pending_program_id']);
                unset($_SESSION['pending_program_name']);
                unset($_SESSION['temp_registration_email']);
                
                $response = [
                    'success' => true,
                    'message' => 'Login successful',
                    'user' => [
                        'id' => $row['id'],
                        'fullname' => $row['fullname'],
                        'role' => $row['role']
                    ],
                    'redirect' => $redirect_url,
                    'enrollment' => false
                ];
            }
        } else {
            $response['message'] = 'Invalid password';
        }
    } else {
        $response['message'] = 'No account found with this email';
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// If not AJAX POST, render the HTML page (GET or normal POST fallback)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Municipal Livelihood Program</title>
    
    <!-- CSS & Fonts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
    /* ==========================================
       OVERALL BACKGROUND WITH SMBHALL.PNG (Same as index.php)
    ========================================== */
    body {
        margin: 0;
        padding: 0;
        font-family: 'Poppins', sans-serif;
        background-image: url('css/SMBHALL.png');
        background-size: cover;
        background-position: center center;
        background-attachment: fixed;
        background-repeat: no-repeat;
        min-height: 100vh;
        color: white;
    }

    /* Container with overlay */
    .login-page {
        min-height: 100vh;
        background: rgba(28, 42, 58, 0.85); /* Dark blue overlay with transparency */
    }

    /* ==========================================
       HEADER/NAV STYLES (Same as index.php)
    ========================================== */
    .top-nav {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 2rem;
        background: rgba(28, 42, 58, 0.9); /* Semi-transparent dark blue */
        backdrop-filter: blur(10px);
        position: sticky;
        top: 0;
        z-index: 1000;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .left-section {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .logo {
        width: 50px;
        height: 50px;
        border-radius: 8px;
    }

    .title {
        font-size: 1.5rem;
        font-weight: 600;
        color: white;
    }

    .desktop-title {
        display: block;
    }

    .mobile-title {
        display: none;
        color: white;
    }

    .burger-btn {
        display: none;
        background: none;
        border: none;
        color: white;
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0.5rem;
        z-index: 1001;
    }

    .right-section {
        display: flex;
        gap: 2rem;
        align-items: center;
    }

    .nav-link {
        color: white;
        text-decoration: none;
        font-weight: 500;
        padding: 0.5rem 1rem;
        border-radius: 5px;
        transition: background 0.3s;
    }

    .nav-link:hover {
        background: rgba(255, 255, 255, 0.1);
    }

    /* ==========================================
       MOBILE MENU STYLES (Same as index.php)
    ========================================== */
    .mobile-menu {
        display: none;
        flex-direction: column;
        background: rgba(28, 42, 58, 0.98);
        backdrop-filter: blur(15px);
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 999;
        padding-top: 70px; /* Space for the top bar */
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        max-height: 100vh;
        overflow-y: auto;
    }

    .mobile-menu.active {
        display: flex;
        animation: slideDown 0.3s ease forwards;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .mobile-menu .nav-link {
        padding: 1.2rem 2rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        font-size: 1.1rem;
        text-align: left;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        gap: 1rem;
        color: white !important; /* Force white text */
        text-decoration: none;
        font-weight: 500;
    }

    .mobile-menu .nav-link:last-child {
        border-bottom: none;
    }

    .mobile-menu .nav-link:hover {
        background: rgba(255, 255, 255, 0.1);
        padding-left: 2.5rem;
        color: white !important;
    }

    .mobile-menu .nav-link i {
        width: 20px;
        text-align: center;
        font-size: 1.2rem;
        color: white !important; /* White icons too */
    }

    /* ==========================================
       LOGIN FORM STYLES (Updated)
    ========================================== */
    .login-container {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: calc(100vh - 80px);
        padding: 2rem;
    }

    .login-card {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 20px;
        width: 100%;
        max-width: 1000px;
        overflow: hidden;
        backdrop-filter: blur(15px);
        border: 1px solid rgba(255, 255, 255, 0.15);
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
        display: flex;
        flex-direction: row;
    }

    @media (max-width: 768px) {
        .login-card {
            flex-direction: column;
            max-width: 450px;
        }
    }

    /* Left side - Branding */
    .login-branding {
        flex: 1;
        /* Removed: background gradient */
        background: transparent; /* Made transparent */
        padding: 3rem 2rem;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
        color: white;
        border-right: 1px solid rgba(255, 255, 255, 0.15); /* Added border */
    }

    .brand-logo {
        width: 180px; /* Increased from 120px */
        height: 180px; /* Increased from 120px */
        margin-bottom: 1.5rem;
        object-fit: contain;
        /* Removed: background, padding, box-shadow, border-radius */
    }

    .brand-title {
        font-size: 1.8rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        line-height: 1.3;
    }

    .brand-subtitle {
        font-size: 1rem;
        opacity: 0.95;
        margin-bottom: 2rem;
        max-width: 300px;
        line-height: 1.5;
    }

    /* Program info box */
    .program-info {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 15px;
        padding: 1.5rem;
        margin-top: 2rem;
        width: 100%;
        max-width: 350px;
        border: 1px solid rgba(255, 255, 255, 0.3);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }

    .program-badge {
        background: rgba(255, 255, 255, 0.3);
        color: white;
        padding: 8px 18px;
        border-radius: 25px;
        font-size: 0.9rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 1rem;
        backdrop-filter: blur(5px);
    }

    .program-name {
        font-size: 1.3rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        line-height: 1.4;
    }

    .program-description {
        font-size: 0.95rem;
        opacity: 0.95;
        line-height: 1.5;
    }

    /* Right side - Login Form */
    .login-form-section {
        flex: 1;
        background: rgba(28, 42, 58, 0.85);
        padding: 3rem 2.5rem;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .login-header {
        text-align: center;
        margin-bottom: 2.5rem;
    }

    .login-title {
        font-size: 2.2rem;
        font-weight: 700;
        margin-bottom: 0.75rem;
        color: white;
        line-height: 1.3;
    }

    .login-subtitle {
        font-size: 1.1rem;
        color: rgba(255, 255, 255, 0.85);
        line-height: 1.5;
    }

    /* Notices */
    .enrollment-notice, 
    .registration-success-notice,
    .redirect-notice {
        border-radius: 12px;
        padding: 1.2rem;
        margin-bottom: 1.5rem;
        display: none;
        animation: fadeIn 0.5s ease-out;
    }

    .enrollment-notice {
        background: rgba(59, 130, 246, 0.2);
        border-left: 4px solid #3b82f6;
    }

    .registration-success-notice {
        background: rgba(34, 197, 94, 0.2);
        border-left: 4px solid #22c55e;
    }

    .redirect-notice {
        background: rgba(245, 158, 11, 0.2);
        border-left: 4px solid #f59e0b;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .notice-content {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
    }

    .notice-icon {
        font-size: 1.3rem;
        margin-top: 0.2rem;
    }

    .notice-text h4 {
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 0.3rem;
        color: white;
    }

    .notice-text p {
        font-size: 0.9rem;
        color: rgba(255, 255, 255, 0.9);
        line-height: 1.4;
    }

    /* Error message */
    .error-message {
        color: #f87171;
        text-align: center;
        margin-bottom: 1.5rem;
        font-size: 1rem;
        padding: 1rem 1.2rem;
        background: rgba(239, 68, 68, 0.15);
        border-radius: 10px;
        border: 1px solid rgba(239, 68, 68, 0.3);
        display: none;
        line-height: 1.5;
    }

    /* Form inputs */
    .form-group {
        margin-bottom: 2rem;
    }

    .form-label {
        display: block;
        font-size: 1.05rem;
        font-weight: 600;
        margin-bottom: 0.8rem;
        color: white;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .form-label i {
        font-size: 1.1rem;
        color: #20c997;
    }

    .form-input {
        width: 30rem;
        padding: 1.1rem 1.5rem;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 12px;
        color: white;
        font-size: 1.05rem;
        transition: all 0.3s;
        font-family: 'Poppins', sans-serif;
    }

    .form-input:focus {
        outline: none;
        border-color: #20c997;
        background: rgba(255, 255, 255, 0.15);
        box-shadow: 0 0 0 3px rgba(32, 201, 151, 0.3);
        transform: translateY(-2px);
    }

    .form-input::placeholder {
        color: rgba(255, 255, 255, 0.4);
        font-size: 1rem;
    }

    /* Password toggle */
    .password-container {
        position: relative;
    }

    .password-toggle {
        position: absolute;
        right: 1.2rem;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: rgba(255, 255, 255, 0.5);
        cursor: pointer;
        font-size: 1.1rem;
        padding: 0.5rem;
        transition: color 0.3s;
    }

    .password-toggle:hover {
        color: #20c997;
    }

    /* Submit button */
    .submit-btn {
        width: 100%;
        padding: 1.3rem;
        background: linear-gradient(90deg, #20c997, #17a589);
        color: white;
        border: none;
        border-radius: 12px;
        font-size: 1.15rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        margin-top: 1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        font-family: 'Poppins', sans-serif;
        letter-spacing: 0.5px;
        box-shadow: 0 5px 15px rgba(32, 201, 151, 0.3);
    }

    .submit-btn:hover {
        background: linear-gradient(90deg, #17a589, #20c997);
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(32, 201, 151, 0.4);
    }

    .submit-btn:active {
        transform: translateY(-1px);
    }

    .submit-btn:disabled {
        opacity: 0.7;
        cursor: not-allowed;
        transform: none !important;
        box-shadow: none !important;
    }

    .spinner {
        width: 22px;
        height: 22px;
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-top: 2px solid white;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* Links */
    .login-links {
        text-align: center;
        margin-top: 2.5rem;
        font-size: 1rem;
        color: rgba(255, 255, 255, 0.8);
        line-height: 1.6;
    }

    .login-links a {
        color: #20c997;
        text-decoration: none;
        font-weight: 600;
        transition: color 0.3s;
    }

    .login-links a:hover {
        color: #17a589;
        text-decoration: underline;
    }

    .login-links p {
        margin-bottom: 0.8rem;
    }

    /* Additional form options */
    .form-options {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .remember-me {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.95rem;
        color: rgba(255, 255, 255, 0.8);
        cursor: pointer;
    }

    .remember-checkbox {
        width: 18px;
        height: 18px;
        border-radius: 4px;
        border: 2px solid rgba(255, 255, 255, 0.3);
        background: rgba(255, 255, 255, 0.1);
        cursor: pointer;
        transition: all 0.3s;
    }

    .remember-checkbox:checked {
        background: #20c997;
        border-color: #20c997;
    }

    /* Footer */
    .footer {
        text-align: center;
        padding: 1.5rem;
        background: rgba(0, 0, 0, 0.5);
        font-size: 0.95rem;
        color: rgba(255, 255, 255, 0.7);
        backdrop-filter: blur(5px);
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        line-height: 1.5;
    }

    /* ==========================================
       RESPONSIVE STYLES
    ========================================== */
    @media (max-width: 768px) {
        .desktop-title {
            display: none;
        }
        
        .mobile-title {
            display: block;
            color: white;
        }
        
        .burger-btn {
            display: block;
            color: white;
        }
        
        .right-section {
            display: none;
        }
        
        .login-container {
            padding: 1rem;
            min-height: calc(100vh - 70px);
        }
        
        .login-branding {
            padding: 2rem 1.5rem;
            border-right: none; /* Remove border on mobile */
            border-bottom: 1px solid rgba(255, 255, 255, 0.15); /* Add bottom border instead */
        }
        
        .login-form-section {
            padding: 2rem 1.5rem;
        }
        
        .brand-logo {
            width: 150px; /* Adjusted for mobile */
            height: 150px; /* Adjusted for mobile */
        }
        
        .brand-title {
            font-size: 1.6rem;
        }
        
        .brand-subtitle {
            font-size: 0.95rem;
        }
        
        .login-title {
            font-size: 1.8rem;
        }
        
        .login-subtitle {
            font-size: 1rem;
        }
        
        .form-label {
            font-size: 1rem;
        }
        
        .form-input {
            padding: 1rem 1.2rem;
            font-size: 1rem;
        }
        
        .submit-btn {
            padding: 1.1rem;
            font-size: 1.1rem;
        }
        
        .top-nav {
            padding: 1rem;
        }
        
        .logo {
            width: 40px;
            height: 40px;
        }
        
        .title {
            font-size: 1.2rem;
            color: white;
        }
        
        body {
            background-attachment: scroll;
        }
        
        .mobile-menu {
            padding-top: 80px;
            height: calc(100vh - 80px);
        }
        
        .mobile-menu .nav-link {
            color: white !important;
            font-weight: 500;
        }
        
        .mobile-menu .nav-link i {
            color: white !important;
        }
    }

    @media (max-width: 480px) {
        .login-branding,
        .login-form-section {
            padding: 1.8rem 1.2rem;
        }
        
        .brand-logo {
            width: 130px; /* Adjusted for small mobile */
            height: 130px; /* Adjusted for small mobile */
        }
        
        .brand-title {
            font-size: 1.5rem;
        }
        
        .brand-subtitle {
            font-size: 0.9rem;
        }
        
        .login-title {
            font-size: 1.6rem;
        }
        
        .login-subtitle {
            font-size: 0.95rem;
        }
        
        .form-input {
            padding: 0.9rem 1rem;
            font-size: 0.95rem;
        }
        
        .submit-btn {
            padding: 1rem;
            font-size: 1rem;
        }
        
        .login-links {
            font-size: 0.95rem;
        }
        
        .mobile-menu .nav-link {
            padding: 1.2rem 1.5rem;
            font-size: 1rem;
            color: white !important;
        }
        
        .mobile-menu .nav-link:hover {
            padding-left: 2rem;
            color: white !important;
        }
        
        .mobile-menu .nav-link i {
            color: white !important;
        }
    }

    /* Animation for form elements */
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .login-form-section > * {
        animation: slideIn 0.5s ease forwards;
        opacity: 0;
    }

    .login-form-section > *:nth-child(1) { animation-delay: 0.1s; }
    .login-form-section > *:nth-child(2) { animation-delay: 0.2s; }
    .login-form-section > *:nth-child(3) { animation-delay: 0.3s; }
    .login-form-section > *:nth-child(4) { animation-delay: 0.4s; }
    .login-form-section > *:nth-child(5) { animation-delay: 0.5s; }
    .login-form-section > *:nth-child(6) { animation-delay: 0.6s; }
    </style>
</head>
<body>
    <div class="login-page">

        <!-- TOP NAVBAR (Same as index.php) -->
        <div class="top-nav">
            <!-- LEFT SECTION -->
            <div class="left-section">
                <img src="/css/logo.png" alt="Logo" class="logo">
                <h1 class="title" title="Livelihood Enrollment & Monitoring System">
                    <span class="desktop-title">Livelihood Enrollment & Monitoring System</span>
                    <span class="mobile-title">LEMS</span>
                </h1>
            </div>

            <!-- BURGER BUTTON (mobile only) -->
            <button class="burger-btn" id="burgerBtn" aria-label="Toggle menu">
                <i class="fas fa-bars"></i>
            </button>

            <!-- DESKTOP NAV -->
            <nav class="right-section">
                <a href="index.php" class="nav-link">Home</a>
                <a href="about.php" class="nav-link">About</a>
                <a href="faqs.php" class="nav-link">FAQs</a>
                <a href="login.php" class="nav-link active">Login</a>
            </nav>
        </div>

        <!-- MOBILE MENU DROPDOWN -->
        <div class="mobile-menu" id="mobileMenu">
            <a href="index.php" class="nav-link">
                <i class="fas fa-home"></i> Home
            </a>
            <a href="about.php" class="nav-link">
                <i class="fas fa-info-circle"></i> About
            </a>
            <a href="faqs.php" class="nav-link">
                <i class="fas fa-question-circle"></i> FAQs
            </a>
            <a href="login.php" class="nav-link active">
                <i class="fas fa-sign-in-alt"></i> Login
            </a>
        </div>

        <!-- MAIN LOGIN CONTENT -->
        <div class="login-container">
            <div class="login-card">
                <!-- Left Side - Branding -->
                <div class="login-branding">
                    <img src="../css/logo.png" alt="Logo" class="brand-logo">
                    <h2 class="brand-title">Municipal Livelihood Program</h2>
                    <p class="brand-subtitle">Empowering communities through skills development</p>
                    
                    <!-- Show program info if enrolling -->
                    <?php
                    // Check for program enrollment from URL or session
                    $display_program_id = null;
                    $display_program_name = '';

                    if (isset($_GET['program_id']) && !isset($_GET['new_registration'])) {
                        $display_program_id = $_GET['program_id'];
                        $display_program_name = $_GET['program_name'] ?? '';
                    } elseif (isset($_SESSION['pending_enrollment']) && is_array($_SESSION['pending_enrollment'])) {
                        $display_program_id = $_SESSION['pending_enrollment']['program_id'];
                        $display_program_name = $_SESSION['pending_enrollment']['program_name'];
                    } elseif (isset($_SESSION['pending_program_id'])) {
                        $display_program_id = $_SESSION['pending_program_id'];
                        $display_program_name = $_SESSION['pending_program_name'] ?? '';
                    }

                    if ($display_program_id) {
                        // If we don't have program name, fetch it from database
                        if (empty($display_program_name)) {
                            require_once __DIR__ . '/db.php';
                            $stmt = $conn->prepare("SELECT name FROM programs WHERE id = ?");
                            $stmt->bind_param('i', $display_program_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($result->num_rows > 0) {
                                $program = $result->fetch_assoc();
                                $display_program_name = $program['name'];
                            }
                        }
                        
                        if ($display_program_name) {
                            echo '<div class="program-info">';
                            echo '<div class="program-badge">';
                            echo '<i class="fas fa-graduation-cap"></i>';
                            echo '<span>PROGRAM ENROLLMENT</span>';
                            echo '</div>';
                            echo '<h3 class="program-name">' . htmlspecialchars($display_program_name) . '</h3>';
                            echo '<p class="program-description">Please login to enroll in this program</p>';
                            echo '</div>';
                        }
                    }
                    ?>
                </div>

                <!-- Right Side - Login Form -->
                <div class="login-form-section">
                    <div class="login-header">
                        <h2 class="login-title">Welcome Back</h2>
                        <p class="login-subtitle">Enter your credentials to continue</p>
                    </div>

                    <!-- Notices -->
                    <div class="enrollment-notice" id="enrollmentNotice">
                        <div class="notice-content">
                            <div class="notice-icon">
                                <i class="fas fa-graduation-cap text-blue-300"></i>
                            </div>
                            <div class="notice-text">
                                <h4>Program Enrollment Required</h4>
                                <p>Please login to complete your enrollment in the selected program.</p>
                            </div>
                        </div>
                    </div>

                    <div class="registration-success-notice" id="registrationSuccessNotice">
                        <div class="notice-content">
                            <div class="notice-icon">
                                <i class="fas fa-check-circle text-green-300"></i>
                            </div>
                            <div class="notice-text">
                                <h4>Registration Successful! 🎉</h4>
                                <p>Your account has been created. Please login with your credentials to complete enrollment.</p>
                            </div>
                        </div>
                    </div>

                    <div class="redirect-notice" id="redirectNotice">
                        <div class="notice-content">
                            <div class="notice-icon">
                                <i class="fas fa-exclamation-triangle text-yellow-300"></i>
                            </div>
                            <div class="notice-text">
                                <h4>Login Required</h4>
                                <p>Please login to access the requested page</p>
                            </div>
                        </div>
                    </div>

                    <!-- Error Message -->
                    <div class="error-message" id="errorMessage"></div>

                    <!-- Login Form -->
                    <form id="loginForm" onsubmit="return false;">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-envelope"></i>
                                Email Address
                            </label>
                            <input type="email" id="email" class="form-input" placeholder="Enter your email" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-lock"></i>
                                Password
                            </label>
                            <div class="password-container">
                                <input type="password" id="password" class="form-input" placeholder="Enter your password" required>
                                <button type="button" class="password-toggle" id="passwordToggle" title="Show password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Hidden program_id field -->
                        <input type="hidden" id="programIdField" value="<?php echo isset($display_program_id) ? htmlspecialchars($display_program_id) : ''; ?>">

                        <button type="submit" id="submitBtn" class="submit-btn">
                            <span id="spinner" class="spinner" style="display: none;"></span>
                            <i id="loginIcon" class="fas fa-sign-in-alt"></i>
                            <span id="btnText">Login to Continue</span>
                        </button>
                    </form>

                </div>
            </div>
        </div>

        <!-- FOOTER -->
        <footer class="footer">
            © <?php echo date('Y'); ?> Livelihood Enrollment and Monitoring System. All Rights Reserved.
        </footer>
    </div>

<script>
// ==========================================
// MOBILE MENU FUNCTIONALITY (Same as index.php)
// ==========================================
const burgerBtn = document.getElementById('burgerBtn');
const mobileMenu = document.getElementById('mobileMenu');
const body = document.body;

if (burgerBtn && mobileMenu) {
    burgerBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        mobileMenu.classList.toggle('active');
        body.classList.toggle('menu-open');
        
        // Change burger icon to X when menu is open
        const icon = burgerBtn.querySelector('i');
        if (mobileMenu.classList.contains('active')) {
            icon.classList.remove('fa-bars');
            icon.classList.add('fa-times');
        } else {
            icon.classList.remove('fa-times');
            icon.classList.add('fa-bars');
        }
    });

    // Close mobile menu when clicking outside
    document.addEventListener('click', (e) => {
        if (!burgerBtn.contains(e.target) && !mobileMenu.contains(e.target)) {
            mobileMenu.classList.remove('active');
            body.classList.remove('menu-open');
            
            // Reset burger icon
            const icon = burgerBtn.querySelector('i');
            icon.classList.remove('fa-times');
            icon.classList.add('fa-bars');
        }
    });

    // Close mobile menu when clicking a link
    const mobileLinks = mobileMenu.querySelectorAll('.nav-link');
    mobileLinks.forEach(link => {
        link.addEventListener('click', () => {
            mobileMenu.classList.remove('active');
            body.classList.remove('menu-open');
            
            // Reset burger icon
            const icon = burgerBtn.querySelector('i');
            icon.classList.remove('fa-times');
            icon.classList.add('fa-bars');
        });
    });

    // Close menu with Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && mobileMenu.classList.contains('active')) {
            mobileMenu.classList.remove('active');
            body.classList.remove('menu-open');
            
            // Reset burger icon
            const icon = burgerBtn.querySelector('i');
            icon.classList.remove('fa-times');
            icon.classList.add('fa-bars');
        }
    });
}

// ==========================================
// PASSWORD TOGGLE FUNCTIONALITY
// ==========================================
const passwordToggle = document.getElementById('passwordToggle');
const passwordInput = document.getElementById('password');

if (passwordToggle && passwordInput) {
    passwordToggle.addEventListener('click', () => {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        
        const icon = passwordToggle.querySelector('i');
        if (type === 'text') {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
            passwordToggle.title = 'Hide password';
        } else {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
            passwordToggle.title = 'Show password';
        }
    });
}

// ==========================================
// LOGIN FUNCTIONALITY (Keep your existing logic)
// ==========================================
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('loginForm');
    const emailEl = document.getElementById('email');
    const passEl = document.getElementById('password');
    const submitBtn = document.getElementById('submitBtn');
    const spinner = document.getElementById('spinner');
    const btnText = document.getElementById('btnText');
    const errorMessage = document.getElementById('errorMessage');
    const redirectNotice = document.getElementById('redirectNotice');
    const enrollmentNotice = document.getElementById('enrollmentNotice');
    const registrationSuccessNotice = document.getElementById('registrationSuccessNotice');
    const programIdField = document.getElementById('programIdField');

    // Check URL parameters for enrollment scenario
    const params = new URLSearchParams(window.location.search);
    let programId = params.get('program_id');
    let programName = params.get('program_name');
    const newRegistration = params.get('new_registration');
    const registrationEmail = params.get('email');
    const redirectParam = params.get('redirect');

    // Check if we should show enrollment (from URL or from page content)
    const hasProgramInfo = document.querySelector('.program-info') !== null;
    
    // Show appropriate notices based on URL parameters or program badge
    if (programId || hasProgramInfo) {
        // This is for program enrollment (either from URL or session)
        enrollmentNotice.style.display = 'block';
        
        // Update button text for enrollment
        btnText.textContent = 'Login to Enroll';
        
        // Store program_id in hidden field if available
        if (programId) {
            programIdField.value = programId;
        } else if (hasProgramInfo) {
            // Try to get program ID from the hidden field set by PHP
            programId = programIdField.value;
        }
    }
    
    // Check for new registration (after user registers for a program)
    if (newRegistration && registrationEmail) {
        // Pre-fill email
        emailEl.value = decodeURIComponent(registrationEmail);
        
        // Show registration success notice
        registrationSuccessNotice.style.display = 'block';
        
        // Hide other notices
        enrollmentNotice.style.display = 'none';
        redirectNotice.style.display = 'none';
        
        // Focus on password field for convenience
        setTimeout(() => {
            passEl.focus();
            passEl.select();
        }, 300);
        
        // Update button text
        btnText.textContent = 'Complete Enrollment';
        
        // Store program_id if available
        if (programId) {
            programIdField.value = programId;
        }
    }
    
    // Show regular redirect notice if no program enrollment
    if (redirectParam && !programId && !newRegistration && !hasProgramInfo) {
        redirectNotice.style.display = 'block';
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        errorMessage.style.display = 'none';
        submitBtn.disabled = true;
        spinner.style.display = 'block';
        btnText.textContent = 'Authenticating...';

        // Get program ID from hidden field or URL
        const currentProgramId = programIdField.value || params.get('program_id') || '';
        
        const payload = {
            email: emailEl.value.trim(),
            password: passEl.value
        };

        try {
            // Build URL with parameters
            let url = window.location.href.split('?')[0];
            const urlParams = new URLSearchParams();
            
            if (currentProgramId) {
                urlParams.append('program_id', currentProgramId);
            }
            if (programName) {
                urlParams.append('program_name', programName);
            }
            if (redirectParam) {
                urlParams.append('redirect', redirectParam);
            }
            
            // Check if this is a new registration
            if (newRegistration) {
                urlParams.append('new_registration', newRegistration);
                if (registrationEmail) {
                    urlParams.append('email', registrationEmail);
                }
            }
            
            const queryString = urlParams.toString();
            if (queryString) {
                url += '?' + queryString;
            }
            
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });

            const data = await res.json();
            if (data.success) {
                // Replace history so back button doesn't go back to login
                const target = data.redirect || '/';
                history.replaceState(null, '', target);
                
                // Show success message
                let title = '✅ Login Successful';
                let html = `Welcome back, <strong>${data.user.fullname || 'User'}</strong>!`;
                
                if (data.revision_mode) {
                    html += `<br><div class="mt-2 p-2 bg-blue-50 rounded">Redirecting to your profile for revision...</div>`;
                    title = '📝 Revision Required';
                    
                    setTimeout(() => {
                        window.location.href = target;
                    }, 1500);
                }
                else if (data.enrollment) {
                    html += `<br><div class="mt-2 p-2 bg-blue-50 rounded">Redirecting to program enrollment...</div>`;
                    title = '🎓 Login Successful';
                    
                    // For enrollment, redirect immediately after a brief delay
                    setTimeout(() => {
                        window.location.href = target;
                    }, 1500);
                } else {
                    html += `<br>Redirecting to ${data.user.role} dashboard...`;
                    
                    Swal.fire({
                        icon: 'success',
                        title: title,
                        html: html,
                        timer: 1400,
                        showConfirmButton: false,
                        timerProgressBar: true,
                        background: '#f8fafc',
                        color: '#1e293b'
                    }).then(() => {
                        window.location.href = target;
                    });
                }
            } else {
                errorMessage.textContent = data.message || 'Login failed';
                errorMessage.style.display = 'block';
                
                // Special handling for enrollment failures
                if (currentProgramId && data.message.includes('not active')) {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Enrollment Failed', 
                        html: `<div class="text-left">
                            <p class="mb-2">Your account is not active yet.</p>
                            <p class="text-sm text-gray-600">Please contact the administrator to activate your account before enrolling in programs.</p>
                        </div>`,
                        confirmButtonText: 'OK',
                        background: '#f8fafc',
                        color: '#1e293b'
                    });
                } else {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Login Failed', 
                        text: data.message || 'Login failed',
                        timer: 3000,
                        showConfirmButton: false,
                        background: '#f8fafc',
                        color: '#1e293b'
                    });
                }
            }
        } catch (err) {
            console.error(err);
            Swal.fire({ 
                icon: 'error', 
                title: 'Connection Error', 
                text: 'An unexpected error occurred. Please check your connection and try again.',
                background: '#f8fafc',
                color: '#1e293b'
            });
        } finally {
            submitBtn.disabled = false;
            spinner.style.display = 'none';
            btnText.textContent = (programId || hasProgramInfo) ? 'Login to Enroll' : 'Login to Continue';
        }
    });

    // Forgot password handler
    document.getElementById('forgotBtn')?.addEventListener('click', async (e) => {
        e.preventDefault();
        const { value: email } = await Swal.fire({
            title: 'Reset Password',
            input: 'email',
            inputLabel: 'Enter your email address',
            inputPlaceholder: 'your@email.com',
            inputValue: emailEl.value || '',
            showCancelButton: true,
            confirmButtonText: 'Send Reset Link',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#1abc9c',
            cancelButtonColor: '#6c757d',
            background: '#f8fafc',
            color: '#1e293b',
            preConfirm: async (email) => {
                if (!email) {
                    Swal.showValidationMessage('Please enter your email address');
                    return false;
                }
                // call forgot_password.php
                try {
                    const resp = await fetch('/forgot_password.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ email })
                    });
                    const j = await resp.json();
                    if (!j.success) throw new Error(j.message || 'Failed to send');
                    return true;
                } catch (err) {
                    Swal.showValidationMessage(`Failed: ${err.message}`);
                    return false;
                }
            },
            allowOutsideClick: () => !Swal.isLoading()
        });

        if (email !== undefined) {
            Swal.fire({
                icon: 'success',
                title: 'Email Sent!',
                html: `<div class="text-left">
                    <p class="mb-2">If the email <strong>${email}</strong> is in our system, a password reset link has been sent.</p>
                    <p class="text-sm text-gray-600">Check your inbox (and spam folder) for the reset instructions.</p>
                </div>`,
                timer: 4000,
                showConfirmButton: false,
                background: '#f8fafc',
                color: '#1e293b'
            });
        }
    });

    // Remember me functionality
    const rememberMe = document.getElementById('rememberMe');
    const storedEmail = localStorage.getItem('rememberedEmail');
    if (storedEmail && !emailEl.value) {
        emailEl.value = storedEmail;
        rememberMe.checked = true;
    }

    rememberMe?.addEventListener('change', function() {
        if (this.checked && emailEl.value) {
            localStorage.setItem('rememberedEmail', emailEl.value);
        } else {
            localStorage.removeItem('rememberedEmail');
        }
    });

    // Auto-save email when typing (if remember me is checked)
    emailEl.addEventListener('input', function() {
        if (rememberMe && rememberMe.checked && this.value) {
            localStorage.setItem('rememberedEmail', this.value);
        }
    });

    // Enter key to submit
    passEl.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            form.dispatchEvent(new Event('submit'));
        }
    });

});
</script>

</body>
</html>