<?php
// archive_user.php (in admin folder)
session_start();

// Include db.php from the root directory
include __DIR__ . '/../db.php';

// Check if user is admin and logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])){
    $user_id = intval($_POST['id']);
    
    // First, get the user data
    $user_data = $conn->query("SELECT * FROM users WHERE id = $user_id");
    
    if($user_data && $user_data->num_rows > 0){
        $user = $user_data->fetch_assoc();
        
        // Insert into archived_users table
        $archive_stmt = $conn->prepare("INSERT INTO archived_users (id, fullname, email, password, role, program, specialization, other_programs, date_created, status, reset_token, reset_expires, archived_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        
        $archive_stmt->bind_param("isssssssssss", 
            $user['id'],
            $user['fullname'],
            $user['email'],
            $user['password'],
            $user['role'],
            $user['program'],
            $user['specialization'],
            $user['other_programs'],
            $user['date_created'],
            $user['status'],
            $user['reset_token'],
            $user['reset_expires']
        );
        
        if($archive_stmt->execute()){
            // Then delete from users table
            $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $delete_stmt->bind_param("i", $user_id);
            
            if($delete_stmt->execute()){
                $_SESSION['flash'] = 'User archived successfully!';
            } else {
                $_SESSION['flash'] = 'Error removing user from active list: ' . $conn->error;
            }
            $delete_stmt->close();
        } else {
            $_SESSION['flash'] = 'Error archiving user: ' . $conn->error;
        }
        $archive_stmt->close();
    } else {
        $_SESSION['flash'] = 'User not found!';
    }
}

header("Location: " . $_SERVER['HTTP_REFERER']);
exit;
?>