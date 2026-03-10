<?php
session_start();
include __DIR__ . '/db.php';

// Get parameters
$program_id = $_GET['program_id'] ?? 0;
$email = $_GET['email'] ?? '';
$token = $_GET['token'] ?? '';

// Simple token validation (you can make this more secure)
$expected_token = md5($email . 'SECRET_KEY' . floor(time() / 60)); // Token valid for 1 minute
if ($token !== $expected_token && !isset($_SESSION['pending_enrollment_after_registration'])) {
    // Token invalid or expired, redirect to login
    header("Location: login.php?message=" . urlencode("Registration successful! Please login to complete enrollment.") . 
           "&suggested_email=" . urlencode($email));
    exit();
}

// Check if we have pending enrollment data in session
$pending_data = $_SESSION['pending_enrollment_after_registration'] ?? null;

// Try to auto-login
$query = $conn->prepare("SELECT id, email, password, role, status FROM users WHERE email = ?");
$query->bind_param("s", $email);
$query->execute();
$result = $query->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    
    // Check if user is active
    if ($user['status'] === 'active') {
        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['loggedin'] = true;
        
        // Clear pending enrollment data
        unset($_SESSION['pending_enrollment_after_registration']);
        unset($_SESSION['pending_enrollment']);
        
        // Redirect to enrollment page
        header("Location: enroll_program.php?program_id=" . $program_id . "&auto_enroll=1");
        exit();
    }
}

// If auto-login fails, redirect to login
unset($_SESSION['pending_enrollment_after_registration']);
header("Location: login.php?message=" . urlencode("Registration successful! Please login to complete enrollment in your selected program.") . 
       "&suggested_email=" . urlencode($email));
exit();
?>