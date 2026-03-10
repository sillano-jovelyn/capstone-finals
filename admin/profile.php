<?php
session_start();

// Include db.php from the root directory
include __DIR__ . '/../db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Check if user is admin
$role_check_query = "SELECT role FROM users WHERE id = '$user_id'";
$role_result = $conn->query($role_check_query);
if ($role_result->num_rows > 0) {
    $user_role = $role_result->fetch_assoc()['role'];
    if ($user_role !== 'admin') {
        header("Location: unauthorized.php");
        exit;
    }
} else {
    header("Location: login.php");
    exit;
}

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_profile') {
        updateProfile($conn, $user_id);
    } elseif ($_POST['action'] === 'change_password') {
        changePassword($conn, $user_id);
    }
}

// Function to update admin profile
function updateProfile($conn, $user_id) {
    // Escape and get form data
    $fullname = $conn->real_escape_string($_POST['fullname'] ?? '');
    $email = $conn->real_escape_string($_POST['email'] ?? '');
    
    // FIXED: Removed extra comma before WHERE clause
    $query = "UPDATE users SET 
              fullname = '$fullname',
              email = '$email'
              WHERE id = '$user_id'";
    
    if ($conn->query($query)) {
        $_SESSION['message'] = "Profile updated successfully!";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['error'] = "Failed to update profile: " . $conn->error;
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
    $query = "SELECT password FROM users WHERE id = '$user_id'";
    $result = $conn->query($query);
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $current_hash = $row['password'];
        
        // Verify current password
        if (password_verify($current_password, $current_hash)) {
            // Hash new password
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password
            $update_query = "UPDATE users SET password = '$new_password_hash' WHERE id = '$user_id'";

            if ($conn->query($update_query)) {
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

// Fetch admin user data
$query = "SELECT * FROM users WHERE id = '$user_id'";
$result = $conn->query($query);
$user = $result->fetch_assoc();

if (!$user) {
    die("User not found");
}

// Get user role display name
$role_display = ucfirst($user['role']);

// Helper function to display data
function displayData($data) {
    return !empty($data) ? htmlspecialchars($data) : '<span class="empty">Not set</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile - Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #f8fafc;
            color: #334155;
            line-height: 1.6;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }

        .container {
            width: 100%;
            max-width: 1200px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .main-content {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .max-w-5xl {
            max-width: 64rem;
            width: 100%;
            margin: 0 auto;
        }

        /* Center all content */
        .center-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
        }

        /* Back Button */
        .back-btn {
            margin-bottom: 1.5rem;
            color: #0d9488;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.2s;
            padding: 0.75rem 1.25rem;
            border-radius: 10px;
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            align-self: flex-start;
        }

        .back-btn:hover {
            color: #0f766e;
            background: #f0f9ff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        /* Messages - Centered */
        .message-container {
            width: 100%;
            display: flex;
            justify-content: center;
            margin-bottom: 1.5rem;
        }

        .message, .error {
            width: 100%;
            max-width: 64rem;
            padding: 1rem 1.25rem;
            border-radius: 12px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeIn 0.3s ease;
        }

        .message {
            background: linear-gradient(135deg, #dcfce7, #bbf7d0);
            border: 1px solid #22c55e;
            color: #166534;
        }

        .error {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border: 1px solid #ef4444;
            color: #b91c1c;
        }

        /* Profile Header */
        .profile-header {
            background: white;
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            width: 100%;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #0d9488, #8b5cf6);
        }

        .profile-main {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
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
            text-align: center;
        }

        @media (min-width: 768px) {
            .profile-identity {
                text-align: left;
            }
        }

        .profile-identity h2 {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #0d9488, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .profile-fullname {
            color: #475569;
            font-weight: 600;
            font-size: 18px;
            margin-bottom: 1.5rem;
            padding: 0.5rem 0;
            border-bottom: 2px solid #f1f5f9;
        }

        .profile-badge-container {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 768px) {
            .profile-badge-container {
                justify-content: flex-start;
            }
        }

        .profile-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .profile-userid {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #3b82f6;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 500;
        }

        .profile-actions {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px solid rgba(13, 148, 136, 0.1);
        }

        @media (min-width: 768px) {
            .profile-actions {
                flex-direction: row;
                gap: 1rem;
                margin-top: 1rem;
                padding-top: 1rem;
            }
        }

        /* Buttons */
        .btn {
            padding: 0.875rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-edit {
            background: linear-gradient(135deg, #0d9488, #14b8a6);
            color: white;
        }

        .btn-edit:hover {
            background: linear-gradient(135deg, #0f766e, #0d9488);
        }

        .btn-change-password {
            background: linear-gradient(135deg, #8b5cf6, #a78bfa);
            color: white;
        }

        .btn-change-password:hover {
            background: linear-gradient(135deg, #7c3aed, #8b5cf6);
        }

        .btn-save {
            background: linear-gradient(135deg, #0d9488, #14b8a6);
            color: white;
        }

        .btn-save:hover:not(:disabled) {
            background: linear-gradient(135deg, #0f766e, #0d9488);
            transform: translateY(-2px);
        }

        .btn-save:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-cancel {
            background: linear-gradient(135deg, #64748b, #475569);
            color: white;
        }

        .btn-cancel:hover {
            background: linear-gradient(135deg, #475569, #334155);
            transform: translateY(-2px);
        }

        /* Personal Info Section */
        .personal-info {
            background: white;
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            padding: 2rem;
            border: 1px solid #e2e8f0;
            width: 100%;
        }

        .personal-info h3 {
            font-size: 22px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
            text-align: center;
        }

        /* Centered Account Details */
        .centered-info-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            max-width: 600px;
            margin: 0 auto;
            padding: 2rem;
            background: #f8fafc;
            border-radius: 16px;
            border-left: 4px solid #0d9488;
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
        }

        .centered-info-section:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
            border-color: #0d9488;
        }

        .centered-info-section h4 {
            color: #0d9488;
            margin-bottom: 1.5rem;
            font-size: 18px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid rgba(13, 148, 136, 0.2);
            text-align: center;
            width: 100%;
        }

        .centered-info-item {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            width: 100%;
            text-align: center;
        }

        .centered-info-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .centered-info-item b {
            color: #475569;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 0.75rem;
            font-weight: 600;
        }

        .centered-info-item b i {
            color: #0d9488;
            width: 20px;
            text-align: center;
        }

        .centered-info-item span {
            color: #64748b;
            font-size: 16px;
            line-height: 1.5;
            display: block;
            min-height: 24px;
        }

        .empty {
            color: #94a3b8 !important;
            font-style: italic;
        }

        /* Form Styles */
        .edit-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .form-section {
            padding: 1.5rem;
            background: #f8fafc;
            border-radius: 16px;
            border-left: 4px solid #8b5cf6;
            border: 1px solid #e2e8f0;
            max-width: 600px;
            margin: 0 auto;
        }

        .form-section h4 {
            color: #8b5cf6;
            margin-bottom: 1.25rem;
            font-size: 17px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-align: center;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.25rem;
        }

        @media (min-width: 640px) {
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 14px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group label i {
            color: #8b5cf6;
            width: 18px;
        }

        .input-field {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid #cbd5e1;
            border-radius: 12px;
            outline: none;
            transition: all 0.3s ease;
            font-size: 15px;
            background: white;
            color: #334155;
        }

        .input-field:focus {
            border-color: #8b5cf6;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.15);
        }

        .input-field.error {
            border-color: #ef4444;
            background-color: #fef2f2;
        }

        .form-actions {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid #e2e8f0;
        }

        @media (min-width: 640px) {
            .form-actions {
                flex-direction: row;
                justify-content: center;
            }
        }

        /* Role Display Field */
        .role-display {
            width: 100%;
            padding: 0.875rem 1rem;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            color: #64748b;
            font-size: 15px;
            font-family: inherit;
        }

        /* Modal */
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
            backdrop-filter: blur(8px);
            transition: opacity 0.3s ease;
        }

        .modal.active {
            display: flex;
            opacity: 1;
            animation: modalFadeIn 0.3s ease forwards;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
            padding: 2rem;
            width: 100%;
            max-width: 450px;
            transform: translateY(-30px) scale(0.95);
            transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .modal.active .modal-content {
            transform: translateY(0) scale(1);
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .modal-header h2 {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-btn {
            color: #64748b;
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
            border-radius: 10px;
        }

        .close-btn:hover {
            color: #475569;
            background-color: #f1f5f9;
            transform: rotate(90deg);
        }

        /* Password Input */
        .password-input {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #64748b;
            cursor: pointer;
            transition: all 0.3s ease;
            padding: 6px;
            border-radius: 8px;
        }

        .password-toggle:hover {
            color: #475569;
            background-color: #f1f5f9;
        }

        .password-hint {
            font-size: 13px;
            color: #64748b;
            margin-top: 0.5rem;
            padding-left: 4px;
        }

        .modal-actions {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            padding-top: 1.5rem;
            margin-top: 1.5rem;
            border-top: 2px solid #e2e8f0;
        }

        @media (min-width: 480px) {
            .modal-actions {
                flex-direction: row;
                justify-content: center;
            }
        }

        /* Password Strength */
        .password-strength {
            margin-top: 0.75rem;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: all 0.4s ease;
            border-radius: 4px;
        }

        /* Animations */
        @keyframes modalFadeIn {
            from {
                opacity: 0;
                backdrop-filter: blur(0px);
            }
            to {
                opacity: 1;
                backdrop-filter: blur(8px);
            }
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-30px) scale(0.95);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }

        /* Responsive */
        @media (max-width: 640px) {
            .container {
                padding: 1rem;
            }
            
            .profile-header,
            .personal-info {
                padding: 1.5rem;
            }
            
            .btn {
                width: 100%;
                padding: 1rem;
            }
            
            .profile-identity h2 {
                font-size: 24px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .form-actions .btn {
                width: 100%;
            }
            
            .centered-info-section {
                padding: 1.5rem;
            }
            
            .form-section {
                padding: 1.5rem;
            }
        }

        /* Readonly field styling */
        .input-field[readonly] {
            background-color: #f8fafc;
            border-color: #e2e8f0;
            color: #64748b;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="main-content">
            <div class="max-w-5xl">
                <!-- Back Button -->
                <button onclick="window.location.href='dashboard.php'" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </button>

                <!-- Messages -->
                <div class="message-container">
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
                </div>

                <!-- Profile Content -->
                <div id="profile-content" class="center-content">
                    <!-- Profile Header -->
                    <div class="profile-header">
                        <div class="profile-main">
                            <div class="profile-identity">
                                <h2><?php echo htmlspecialchars($user['email'] ?? 'Admin'); ?></h2>
                                <p class="profile-fullname"><?php echo displayData($user['fullname']); ?></p>
                                
                                <div class="profile-badge-container">
                                    <span class="profile-badge" style="background: linear-gradient(135deg, #8b5cf6, #a78bfa);">
                                        <i class="fas fa-user-shield"></i> <?php echo $role_display; ?>
                                    </span>
                                   
                                </div>
                                
                                <div class="profile-actions">
                                    <button onclick="toggleEdit()" class="btn btn-edit" id="edit-btn">
                                        <i class="fas fa-edit"></i> Edit Profile
                                    </button>
                                    <button onclick="openChangePassword()" class="btn btn-change-password">
                                        <i class="fas fa-key"></i> Change Password
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Personal Info -->
                    <div class="personal-info">
                        <h3><i class="fas fa-user-circle"></i> Admin Profile</h3>

                        <!-- View Mode -->
                        <div id="view-mode">
                            <!-- Centered Account Details Section -->
                            <div class="centered-info-section">
                                <h4>Account Details</h4>
                                <div class="centered-info-item">
                                    <b><i class="fas fa-envelope"></i> Email</b>
                                    <span><?php echo displayData($user['email']); ?></span>
                                </div>
                                <div class="centered-info-item">
                                    <b><i class="fas fa-id-badge"></i> Full Name</b>
                                    <span><?php echo displayData($user['fullname']); ?></span>
                                </div>
                                <div class="centered-info-item">
                                    <b><i class="fas fa-user-tag"></i> Role</b>
                                    <span><?php echo $role_display; ?></span>
                                </div>
                                <div class="centered-info-item">
                                    <b><i class="fas fa-calendar-alt"></i> Account Created</b>
                                    <span><?php echo !empty($user['date_created']) ? date('F j, Y', strtotime($user['date_created'])) : 'Not available'; ?></span>
                                </div>
                                
                            </div>
                        </div>

                        <!-- Edit Mode -->
                        <div id="edit-mode" class="edit-form" style="display: none;">
                            <form method="POST" onsubmit="return validateEditForm()">
                                <input type="hidden" name="action" value="update_profile">
                                
                                <!-- Account Information -->
                                <div class="form-section">
                                    <h4><i class="fas fa-user"></i> Account Details</h4>
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label><i class="fas fa-envelope"></i> Email</label>
                                            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" 
                                                   class="input-field" placeholder="Enter your email" required>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label><i class="fas fa-id-badge"></i> Full Name</label>
                                            <input type="text" name="fullname" value="<?php echo htmlspecialchars($user['fullname'] ?? ''); ?>" 
                                                   class="input-field" placeholder="Enter your full name">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label><i class="fas fa-user-tag"></i> Role</label>
                                            <input type="text" value="<?php echo $role_display; ?>" 
                                                   class="role-display" readonly>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label><i class="fas fa-calendar-alt"></i> Account Created</label>
                                            <input type="text" value="<?php echo !empty($user['date_created']) ? date('F j, Y', strtotime($user['date_created'])) : 'Not available'; ?>" 
                                                   class="role-display" readonly>
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
                        </div>
                    </div>
                </div>
            </div>
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
                    <p class="password-hint">Must be at least 6 characters</p>
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

    <script>
        // State management
        let isEditing = false;

        // DOM Elements
        const viewMode = document.getElementById('view-mode');
        const editMode = document.getElementById('edit-mode');
        const editBtn = document.getElementById('edit-btn');
        const passwordModal = document.getElementById('password-modal');
        const saveBtn = document.getElementById('save-btn');
        const changePasswordBtn = document.getElementById('change-password-btn');

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide messages after 5 seconds
            setTimeout(() => {
                const messages = document.querySelectorAll('.message, .error');
                messages.forEach(msg => {
                    msg.style.opacity = '0';
                    msg.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => msg.remove(), 500);
                });
            }, 5000);
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
                editMode.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                viewMode.style.display = 'block';
                editMode.style.display = 'none';
                editBtn.innerHTML = '<i class="fas fa-edit"></i> Edit Profile';
                editBtn.classList.remove('btn-cancel');
                editBtn.classList.add('btn-edit');
            }
        }

        // Form Validation
        function validateEditForm() {
            const form = document.querySelector('#edit-mode form');
            const inputs = form.querySelectorAll('input:not([readonly])');
            let isValid = true;
            let firstError = null;
            
            inputs.forEach(input => {
                input.classList.remove('error');
                if (input.type === 'email' && input.value && !validateEmail(input.value)) {
                    input.classList.add('error');
                    isValid = false;
                    if (!firstError) firstError = input;
                } 
            });
            
            if (!isValid && firstError) {
                firstError.focus();
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                alert('Please correct the highlighted fields.');
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

        // Utility Functions
        function validateEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }

        // Close modal when clicking outside
        passwordModal.addEventListener('click', function(event) {
            if (event.target === passwordModal) {
                closeChangePassword();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && passwordModal.classList.contains('active')) {
                closeChangePassword();
            }
            if (event.key === 'Escape' && isEditing) {
                toggleEdit();
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>