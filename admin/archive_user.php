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
    $admin_id = $_SESSION['user_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First, get the user data from users table
        $user_data = $conn->query("SELECT * FROM users WHERE id = $user_id");
        
        if($user_data && $user_data->num_rows > 0){
            $user = $user_data->fetch_assoc();
            
            // Get trainee data if exists
            $trainee_data = null;
            $trainee_result = $conn->query("SELECT * FROM trainees WHERE user_id = $user_id");
            if($trainee_result && $trainee_result->num_rows > 0){
                $trainee = $trainee_result->fetch_assoc();
                $trainee_data = json_encode($trainee);
            }
            
            // Get archived history records related to this user
            $history_ids = [];
            $history_records = [];
            $history_result = $conn->query("SELECT * FROM archived_history WHERE user_id = $user_id");
            if($history_result && $history_result->num_rows > 0){
                while($row = $history_result->fetch_assoc()){
                    $history_ids[] = $row['id'];
                    $history_records[] = $row;
                }
            }
            
            // Encode the complete history records
            $history_data_json = json_encode($history_records);
            $history_ids_json = json_encode($history_ids);
            
            // Insert into archived_users_history table
            $archive_stmt = $conn->prepare("INSERT INTO archived_users_history 
                (original_user_id, user_data, trainee_data, archived_history_ids, archived_history_data, fullname, email, role, archived_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $user_data_json = json_encode($user);
            
            $archive_stmt->bind_param("isssssssi", 
                $user['id'],
                $user_data_json,
                $trainee_data,
                $history_ids_json,
                $history_data_json,
                $user['fullname'],
                $user['email'],
                $user['role'],
                $admin_id
            );
            
            if(!$archive_stmt->execute()){
                throw new Exception("Error archiving user: " . $conn->error);
            }
            $archive_stmt->close();
            
            // If user is a trainer, remove them from assigned programs
            if($user['role'] === 'trainer'){
                $update_programs = $conn->prepare("UPDATE programs SET trainer_id = NULL WHERE trainer_id = ?");
                $update_programs->bind_param("i", $user_id);
                if(!$update_programs->execute()){
                    throw new Exception("Error updating program assignments: " . $conn->error);
                }
                $update_programs->close();
            }
            
            // Delete from trainees table if they are a trainee
            $delete_trainee = $conn->prepare("DELETE FROM trainees WHERE user_id = ?");
            $delete_trainee->bind_param("i", $user_id);
            $delete_trainee->execute();
            $delete_trainee->close();
            
            // Delete from enrollments
            $delete_enrollments = $conn->prepare("DELETE FROM enrollments WHERE user_id = ?");
            $delete_enrollments->bind_param("i", $user_id);
            $delete_enrollments->execute();
            $delete_enrollments->close();
            
            // Delete from feedback
            $delete_feedback = $conn->prepare("DELETE FROM feedback WHERE user_id = ?");
            $delete_feedback->bind_param("i", $user_id);
            $delete_feedback->execute();
            $delete_feedback->close();
            
            // Delete from notifications
            $delete_notifications = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
            $delete_notifications->bind_param("i", $user_id);
            $delete_notifications->execute();
            $delete_notifications->close();
            
            // Delete from archived_history (we're moving it to archived_users_history)
            $delete_archived_history = $conn->prepare("DELETE FROM archived_history WHERE user_id = ?");
            $delete_archived_history->bind_param("i", $user_id);
            $delete_archived_history->execute();
            $delete_archived_history->close();
            
            // Finally delete from users table
            $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $delete_stmt->bind_param("i", $user_id);
            
            if(!$delete_stmt->execute()){
                throw new Exception("Error removing user from active list: " . $conn->error);
            }
            $delete_stmt->close();
            
            // Commit transaction
            $conn->commit();
            
            $_SESSION['flash'] = 'User archived successfully! ';
            
        } else {
            throw new Exception("User not found!");
        }
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
}

header("Location: " . $_SERVER['HTTP_REFERER']);
exit;
?>