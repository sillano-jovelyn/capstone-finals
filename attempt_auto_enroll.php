<?php
session_start();

// Include database
include __DIR__ . '/db.php';

// Check if we have registration data
if (isset($_GET['email']) && isset($_GET['program_id'])) {
    $email = $_GET['email'];
    $program_id = $_GET['program_id'];
    
    // Try to auto-login the newly registered user
    $sql = "SELECT id, password, fullname, role FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $email;
        $_SESSION['user_fullname'] = $user['fullname'];
        $_SESSION['user_role'] = $user['role'];
        
        // Store program ID for enrollment
        $_SESSION['pending_enrollment'] = $program_id;
        
        // Clear any temp registration data
        unset($_SESSION['temp_registration']);
        
        // Redirect to enrollment script
        header("Location: enroll-after-login.php?program_id=" . $program_id);
        exit();
    } else {
        // User not found yet (database might need a moment), 
        // Store in session and redirect to login
        $_SESSION['pending_enrollment'] = $program_id;
        $_SESSION['temp_registration_email'] = $email;
        
        header("Location: login.php?program_id=" . $program_id . "&email=" . urlencode($email) . "&new_registration=true&message=Please login with your new credentials");
        exit();
    }
} else {
    // No parameters provided
    header("Location: login.php");
    exit();
}
?>