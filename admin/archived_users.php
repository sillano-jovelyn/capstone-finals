<?php
// archived_users.php (in admin folder)
session_start();

// Include db.php from the root directory
include __DIR__ . '/../db.php';

// Check if user is admin and logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Check if database connection is established
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Handle Restore User (DELETE from archive after restore)
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['restore_user'])){
    $archive_id = intval($_POST['archive_id']);
    $admin_id = $_SESSION['user_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get the archived user data
        $archived_data = $conn->query("SELECT * FROM archived_users_history WHERE id = $archive_id AND status = 'archived'");
        
        if($archived_data && $archived_data->num_rows > 0){
            $archive = $archived_data->fetch_assoc();
            
            // Decode JSON data
            $user_data = json_decode($archive['user_data'], true);
            $trainee_data = $archive['trainee_data'] ? json_decode($archive['trainee_data'], true) : null;
            $history_ids = $archive['archived_history_ids'] ? json_decode($archive['archived_history_ids'], true) : [];
            $history_data = $archive['archived_history_data'] ? json_decode($archive['archived_history_data'], true) : [];
            
            // Check if user ID already exists in users table
            $check_user = $conn->query("SELECT id FROM users WHERE id = {$user_data['id']}");
            if($check_user && $check_user->num_rows > 0){
                // User ID exists, generate new ID by unsetting it
                unset($user_data['id']);
            }
            
            // Build the INSERT query for users table dynamically
            $user_columns = implode(", ", array_keys($user_data));
            $user_placeholders = implode(", ", array_fill(0, count($user_data), "?"));
            $user_types = str_repeat("s", count($user_data));
            $user_values = array_values($user_data);
            
            $restore_user = $conn->prepare("INSERT INTO users ($user_columns) VALUES ($user_placeholders)");
            $restore_user->bind_param($user_types, ...$user_values);
            
            if(!$restore_user->execute()){
                throw new Exception("Error restoring user: " . $conn->error);
            }
            $new_user_id = $restore_user->insert_id;
            $restore_user->close();
            
            // Restore trainee data if exists
            if($trainee_data && is_array($trainee_data)){
                // Update trainee data with new user_id
                $trainee_data['user_id'] = $new_user_id;
                if(isset($trainee_data['id'])) {
                    unset($trainee_data['id']); // Remove old ID to auto-increment
                }
                
                $trainee_columns = implode(", ", array_keys($trainee_data));
                $trainee_placeholders = implode(", ", array_fill(0, count($trainee_data), "?"));
                $trainee_types = str_repeat("s", count($trainee_data));
                $trainee_values = array_values($trainee_data);
                
                $restore_trainee = $conn->prepare("INSERT INTO trainees ($trainee_columns) VALUES ($trainee_placeholders)");
                $restore_trainee->bind_param($trainee_types, ...$trainee_values);
                
                if(!$restore_trainee->execute()){
                    throw new Exception("Error restoring trainee data: " . $conn->error);
                }
                $restore_trainee->close();
            }
            
            // Restore archived_history records with new user_id
            $restored_count = 0;
            if(!empty($history_data)){
                foreach($history_data as $history_record){
                    // Remove the old ID to let auto_increment create new one
                    if(isset($history_record['id'])) {
                        unset($history_record['id']);
                    }
                    
                    // Set the new user_id
                    $history_record['user_id'] = $new_user_id;
                    
                    // Build INSERT query for archived_history
                    $history_columns = implode(", ", array_keys($history_record));
                    $history_placeholders = implode(", ", array_fill(0, count($history_record), "?"));
                    $history_types = str_repeat("s", count($history_record));
                    $history_values = array_values($history_record);
                    
                    $restore_history = $conn->prepare("INSERT INTO archived_history ($history_columns) VALUES ($history_placeholders)");
                    $restore_history->bind_param($history_types, ...$history_values);
                    
                    if(!$restore_history->execute()){
                        throw new Exception("Error restoring archived history record");
                    }
                    $restore_history->close();
                    $restored_count++;
                }
            }
            
            // DELETE the record from archived_users_history after successful restore
            $delete_record = $conn->prepare("DELETE FROM archived_users_history WHERE id = ?");
            $delete_record->bind_param("i", $archive_id);
            
            if(!$delete_record->execute()){
                throw new Exception("Error deleting archive record: " . $conn->error);
            }
            $delete_record->close();
            
            // Commit transaction
            $conn->commit();
            
            $_SESSION['flash'] = 'User restored successfully from archive!';
            
        } else {
            throw new Exception("Archived user not found or already restored!");
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash'] = 'Error restoring user: ' . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Handle Permanently Delete User (DELETE from archive)
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_user'])){
    $archive_id = intval($_POST['archive_id']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get the archived user data first (for logging purposes)
        $archived_data = $conn->query("SELECT * FROM archived_users_history WHERE id = $archive_id");
        
        if($archived_data && $archived_data->num_rows > 0){
            $archive = $archived_data->fetch_assoc();
            
            // Optional: Log the permanent deletion to a separate log table if needed
            // You could create a deletion_log table to track permanently deleted records
            
            // Permanently delete the record from archived_users_history
            $stmt = $conn->prepare("DELETE FROM archived_users_history WHERE id = ?");
            $stmt->bind_param("i", $archive_id);
            
            if(!$stmt->execute()){
                throw new Exception("Error permanently deleting archive record: " . $conn->error);
            }
            $stmt->close();
            
            $conn->commit();
            $_SESSION['flash'] = 'User permanently deleted from archive! ';
            
        } else {
            throw new Exception("Archived user not found!");
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash'] = 'Error permanently deleting user: ' . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Handle AJAX request for viewing images
if(isset($_GET['ajax']) && $_GET['ajax'] == 'get_image' && isset($_GET['type']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    $image_id = intval($_GET['id']);
    $image_type = $_GET['type'];
    $response = ['success' => false, 'html' => ''];
    
    try {
        if($image_type == 'valid_id' || $image_type == 'voters_cert') {
            // Get from trainees table via archived_users_history
            $query = "
                SELECT u.trainee_data 
                FROM archived_users_history u 
                WHERE u.id = $image_id AND u.trainee_data IS NOT NULL
            ";
            
            $result = $conn->query($query);
            if($result && $result->num_rows > 0) {
                $data = $result->fetch_assoc();
                $trainee_data = json_decode($data['trainee_data'], true);
                
                if($trainee_data && isset($trainee_data[$image_type])) {
                    $images = $trainee_data[$image_type];
                    if(is_string($images)) {
                        $images = json_decode($images, true);
                    }
                    
                    if(is_array($images)) {
                        $response['success'] = true;
                        $response['html'] = '<div class="image-gallery">';
                        foreach($images as $index => $image) {
                            $img_url = '';
                            if(is_array($image) && isset($image['url'])) {
                                $img_url = $image['url'];
                            } elseif(is_string($image)) {
                                // Check if it's a base64 image
                                if(strpos($image, 'data:image') === 0) {
                                    $img_url = $image;
                                } else {
                                    // Assume it's a file path
                                    $img_url = '../uploads/' . basename($image);
                                }
                            }
                            
                            if($img_url) {
                                $response['html'] .= '<div class="image-item">';
                                $response['html'] .= '<img src="' . htmlspecialchars($img_url) . '" alt="' . ucfirst($image_type) . ' Image ' . ($index+1) . '" onclick="openFullImage(this.src)">';
                                $response['html'] .= '</div>';
                            }
                        }
                        $response['html'] .= '</div>';
                    }
                }
            }
        } elseif($image_type == 'program_image' && isset($_GET['program_id']) && isset($_GET['archive_user_id'])) {
            // Get program image from archived_history data stored in archived_users_history
            $archive_user_id = intval($_GET['archive_user_id']);
            $program_id = intval($_GET['program_id']);
            
            $query = "SELECT archived_history_data FROM archived_users_history WHERE id = $archive_user_id";
            $result = $conn->query($query);
            
            if($result && $result->num_rows > 0) {
                $data = $result->fetch_assoc();
                $history_data = json_decode($data['archived_history_data'], true);
                
                if(is_array($history_data)) {
                    foreach($history_data as $program) {
                        if($program['id'] == $program_id && isset($program['program_image'])) {
                            $response['success'] = true;
                            $response['html'] = '<div class="image-gallery">';
                            $response['html'] .= '<div class="image-item">';
                            $response['html'] .= '<img src="' . htmlspecialchars($program['program_image']) . '" alt="' . htmlspecialchars($program['program_name']) . '" onclick="openFullImage(this.src)">';
                            $response['html'] .= '</div>';
                            $response['html'] .= '</div>';
                            break;
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        $response['error'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

// Handle View User Details (now separate page)
if(isset($_GET['view']) && is_numeric($_GET['view'])){
    $view_id = intval($_GET['view']);
    $view_data = $conn->query("SELECT * FROM archived_users_history WHERE id = $view_id");
    
    if($view_data && $view_data->num_rows > 0){
        $user_details = $view_data->fetch_assoc();
        $user_data = json_decode($user_details['user_data'], true);
        $trainee_data = $user_details['trainee_data'] ? json_decode($user_details['trainee_data'], true) : null;
        $history_data = $user_details['archived_history_data'] ? json_decode($user_details['archived_history_data'], true) : [];
        
        // Get archived history records (completed programs) from the JSON data
        $history_records = is_array($history_data) ? $history_data : [];
        
        // Include header
        include '../components/header.php';
        ?>
        
        <style>
            .details-container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 20px;
            }
            
            .page-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding: 20px;
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .page-header h1 {
                margin: 0;
                color: #1f2937;
                font-size: 24px;
            }
            
            .back-btn {
                background: #6b7280;
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
                display: flex;
                align-items: center;
                gap: 8px;
                text-decoration: none;
            }
            
            .back-btn:hover {
                background: #4b5563;
            }
            
            .detail-card {
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                padding: 24px;
                margin-bottom: 20px;
            }
            
            .section-title {
                font-size: 18px;
                color: #374151;
                margin-bottom: 15px;
                padding-bottom: 5px;
                border-bottom: 2px solid #e9ecef;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .detail-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 15px;
            }
            
            .detail-item {
                padding: 12px;
                background: #f8f9fa;
                border-radius: 4px;
            }
            
            .detail-label {
                font-size: 12px;
                color: #6b7280;
                margin-bottom: 4px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .detail-value {
                font-size: 16px;
                color: #1f2937;
                font-weight: 500;
                word-break: break-word;
            }
            
            .badge {
                display: inline-block;
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
            
            .badge-archived {
                background: #f3f4f6;
                color: #6b7280;
            }
            
            .badge-restored {
                background: #d1fae5;
                color: #065f46;
            }
            
            .badge-completed {
                background: #d1fae5;
                color: #065f46;
            }
            
            .badge-pending {
                background: #fef3c7;
                color: #92400e;
            }
            
            .image-gallery {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 15px;
                margin-top: 10px;
            }
            
            .image-item {
                position: relative;
                border: 1px solid #e9ecef;
                border-radius: 4px;
                overflow: hidden;
                cursor: pointer;
                transition: transform 0.2s;
            }
            
            .image-item:hover {
                transform: scale(1.02);
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            }
            
            .image-item img {
                width: 100%;
                height: 150px;
                object-fit: cover;
                display: block;
            }
            
            .image-item .image-label {
                position: absolute;
                bottom: 0;
                left: 0;
                right: 0;
                background: rgba(0,0,0,0.7);
                color: white;
                padding: 4px 8px;
                font-size: 12px;
                text-align: center;
            }
            
            .table-responsive {
                overflow-x: auto;
            }
            
            .table {
                width: 100%;
                border-collapse: collapse;
            }
            
            .table th {
                background: #f8f9fa;
                padding: 12px;
                text-align: left;
                font-weight: 600;
                color: #374151;
                border-bottom: 2px solid #e9ecef;
            }
            
            .table td {
                padding: 12px;
                border-bottom: 1px solid #e9ecef;
            }
            
            .table tr:hover {
                background: #f8f9fa;
            }
            
            .btn-view {
                background: #3b82f6;
                color: white;
                border: none;
                padding: 4px 8px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 12px;
            }
            
            .btn-view:hover {
                background: #2563eb;
            }
            
            .program-image-thumb {
                width: 50px;
                height: 50px;
                object-fit: cover;
                border-radius: 4px;
                cursor: pointer;
            }
            
            .rating-stars {
                color: #f59e0b;
            }
            
            .json-view {
                background: #1e293b;
                color: #e2e8f0;
                padding: 15px;
                border-radius: 4px;
                font-family: monospace;
                font-size: 13px;
                overflow-x: auto;
                max-height: 400px;
                overflow-y: auto;
            }
            
            .info-tabs {
                display: flex;
                gap: 10px;
                margin-bottom: 20px;
                border-bottom: 2px solid #e9ecef;
                padding-bottom: 10px;
                flex-wrap: wrap;
            }
            
            .tab-btn {
                padding: 8px 16px;
                border: none;
                background: none;
                cursor: pointer;
                font-size: 14px;
                color: #6b7280;
                border-radius: 4px;
            }
            
            .tab-btn.active {
                background: #3b82f6;
                color: white;
            }
            
            .tab-content {
                display: none;
            }
            
            .tab-content.active {
                display: block;
            }
            
            .modal-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.9);
                z-index: 1000;
                justify-content: center;
                align-items: center;
            }
            
            .modal-overlay.active {
                display: flex;
            }
            
            .full-image {
                max-width: 90%;
                max-height: 90%;
            }
            
            .full-image img {
                width: 100%;
                height: auto;
                border-radius: 4px;
            }
            
            .close-modal {
                position: absolute;
                top: 20px;
                right: 20px;
                color: white;
                font-size: 30px;
                cursor: pointer;
                background: none;
                border: none;
            }
            
            .close-modal:hover {
                color: #f3f4f6;
            }
            
            @media (max-width: 768px) {
                .detail-grid {
                    grid-template-columns: 1fr;
                }
                
                .image-gallery {
                    grid-template-columns: 1fr;
                }
                
                .page-header {
                    flex-direction: column;
                    gap: 10px;
                    text-align: center;
                }
            }
        </style>
        
        <div class="details-container">
            <div class="page-header">
                <h1><i class="fas fa-user-archive"></i> Archived User Details</h1>
                <a href="archived_users.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Archived Users</a>
            </div>
            
            <div class="info-tabs">
                <button class="tab-btn active" onclick="showTab('basic')">Basic Info</button>
                <?php if($trainee_data): ?>
                <button class="tab-btn" onclick="showTab('trainee')">Trainee Info</button>
                <?php endif; ?>
                <?php if(!empty($history_records)): ?>
                <button class="tab-btn" onclick="showTab('programs')">Completed Programs (<?= count($history_records) ?>)</button>
                <?php endif; ?>
            </div>
            
            <div id="tab-basic" class="tab-content active">
                <div class="detail-card">
                    <h3 class="section-title"><i class="fas fa-user"></i> Basic Information</h3>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Full Name</div>
                            <div class="detail-value"><?= htmlspecialchars($user_details['fullname'] ?? 'N/A') ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Email</div>
                            <div class="detail-value"><?= htmlspecialchars($user_details['email'] ?? 'N/A') ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Role</div>
                            <div class="detail-value">
                                <span class="badge <?= $user_details['role'] === 'trainer' ? 'badge-trainer' : 'badge-trainee' ?>">
                                    <?= htmlspecialchars(ucfirst($user_details['role'])) ?>
                                </span>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Original User ID</div>
                            <div class="detail-value"><?= htmlspecialchars($user_details['original_user_id']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Status</div>
                            <div class="detail-value">
                                <span class="badge <?= $user_details['status'] === 'archived' ? 'badge-archived' : 'badge-restored' ?>">
                                    <?= htmlspecialchars(ucfirst($user_details['status'])) ?>
                                </span>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Archived At</div>
                            <div class="detail-value"><?= date('F j, Y g:i A', strtotime($user_details['archived_at'])) ?></div>
                        </div>
                        <?php if($user_details['restored_at']): ?>
                        <div class="detail-item">
                            <div class="detail-label">Restored At</div>
                            <div class="detail-value"><?= date('F j, Y g:i A', strtotime($user_details['restored_at'])) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if($trainee_data): ?>
            <div id="tab-trainee" class="tab-content">
                <div class="detail-card">
                    <h3 class="section-title"><i class="fas fa-graduation-cap"></i> Trainee Information</h3>
                    
                    <div class="detail-grid">
                        <?php 
                        $excluded_fields = ['id', 'password', 'user_id', 'valid_id', 'voters_certificate', 'valid_id_files', 'voters_cert_files', 'valid_id_links', 'voters_cert_links', 'detected_id_types', 'special_categories'];
                        foreach($trainee_data as $key => $value): 
                            if(!in_array($key, $excluded_fields) && !is_null($value) && $value !== ''):
                        ?>
                            <div class="detail-item">
                                <div class="detail-label"><?= htmlspecialchars(str_replace('_', ' ', ucwords($key))) ?></div>
                                <div class="detail-value">
                                    <?php 
                                    if(is_array($value)){
                                        echo '<pre class="json-view" style="max-height:200px;">' . htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT)) . '</pre>';
                                    } elseif(strlen($value) > 100){
                                        echo '<textarea class="json-view" readonly rows="2" style="width:100%;">' . htmlspecialchars($value) . '</textarea>';
                                    } else {
                                        echo htmlspecialchars($value);
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if(!empty($history_records)): ?>
            <div id="tab-programs" class="tab-content">
                <div class="detail-card">
                    <h3 class="section-title"><i class="fas fa-history"></i> Completed Programs (Archived History)</h3>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Program Name</th>
                                    <th>Duration</th>
                                    <th>Schedule</th>
                                    <th>Trainer</th>
                                    <th>Status</th>
                                    <th>Attendance</th>
                                    <th>Ratings</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($history_records as $record): ?>
                                <tr>
                                    <td>#<?= $record['id'] ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($record['program_name']) ?></strong>
                                        <?php if(isset($record['program_image'])): ?>
                                            <br>
                                            <img src="<?= htmlspecialchars($record['program_image']) ?>" alt="Program" style="width:40px;height:40px;object-fit:cover;border-radius:4px;margin-top:5px;cursor:pointer" onclick="viewProgramImage(<?= $record['id'] ?>, <?= $view_id ?>)">
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $record['program_duration'] ?> <?= $record['program_duration_unit'] ?></td>
                                    <td>
                                        <?= isset($record['program_schedule_start']) ? date('M d, Y', strtotime($record['program_schedule_start'])) : 'N/A' ?><br>
                                        <small>to <?= isset($record['program_schedule_end']) ? date('M d, Y', strtotime($record['program_schedule_end'])) : 'N/A' ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($record['program_trainer_name'] ?? 'N/A') ?></td>
                                    <td>
                                        <span class="badge <?= isset($record['enrollment_status']) && $record['enrollment_status'] === 'completed' ? 'badge-completed' : 'badge-archived' ?>">
                                            <?= htmlspecialchars($record['enrollment_status'] ?? 'N/A') ?>
                                        </span>
                                    </td>
                                    <td><?= $record['enrollment_attendance'] ?? 0 ?>%</td>
                                    <td>
                                        <?php if(isset($record['trainer_expertise_rating']) && $record['trainer_expertise_rating']): ?>
                                            <div class="rating-stars">
                                                <?php
                                                $ratings = [
                                                    $record['trainer_expertise_rating'] ?? 0,
                                                    $record['trainer_communication_rating'] ?? 0,
                                                    $record['trainer_methods_rating'] ?? 0,
                                                    $record['program_knowledge_rating'] ?? 0
                                                ];
                                                $valid_ratings = array_filter($ratings);
                                                if(!empty($valid_ratings)) {
                                                    $avg = array_sum($valid_ratings) / count($valid_ratings);
                                                    echo number_format($avg, 1) . ' ★';
                                                } else {
                                                    echo 'No ratings';
                                                }
                                                ?>
                                            </div>
                                        <?php else: ?>
                                            No ratings
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn-view" onclick='viewProgramDetails(<?= json_encode($record) ?>)'>
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Full Image Modal -->
            <div class="modal-overlay" id="imageModal" onclick="closeImageModal()">
                <span class="close-modal" onclick="closeImageModal()">&times;</span>
                <div class="full-image" id="fullImageContainer"></div>
            </div>
            
            <!-- Program Details Modal -->
            <div class="modal-overlay" id="programModal" onclick="if(event.target === this) closeProgramModal()">
                <div style="background: white; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; border-radius: 8px; padding: 20px; position: relative;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="margin:0;">Program Details</h3>
                        <span style="cursor: pointer; font-size: 24px;" onclick="closeProgramModal()">&times;</span>
                    </div>
                    <div id="programDetailsContent"></div>
                </div>
            </div>
        </div>
        
        <script>
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById('tab-' + tabName).classList.add('active');
            
            // Add active class to clicked button
            event.target.classList.add('active');
        }
        
        function openFullImage(src) {
            document.getElementById('fullImageContainer').innerHTML = '<img src="' + src + '" style="max-width:100%; max-height:90vh;">';
            document.getElementById('imageModal').classList.add('active');
        }
        
        function closeImageModal() {
            document.getElementById('imageModal').classList.remove('active');
        }
        
        function viewProgramImage(programId, archiveUserId) {
            fetch('?ajax=get_image&type=program_image&id=' + programId + '&program_id=' + programId + '&archive_user_id=' + archiveUserId)
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        document.getElementById('fullImageContainer').innerHTML = data.html;
                        document.getElementById('imageModal').classList.add('active');
                    }
                });
        }
        
        function viewProgramDetails(program) {
            let html = '<div style="padding: 10px;">';
            
            // Program Info
            html += '<h4>Program Information</h4>';
            html += '<table style="width:100%; border-collapse:collapse;">';
            html += '<tr><td style="padding:8px; border-bottom:1px solid #eee;"><strong>Program Name:</strong></td><td>' + (program.program_name || 'N/A') + '</td></tr>';
            html += '<tr><td style="padding:8px; border-bottom:1px solid #eee;"><strong>Duration:</strong></td><td>' + (program.program_duration || 'N/A') + ' ' + (program.program_duration_unit || '') + '</td></tr>';
            html += '<tr><td style="padding:8px; border-bottom:1px solid #eee;"><strong>Schedule:</strong></td><td>' + (program.program_schedule_start || 'N/A') + ' to ' + (program.program_schedule_end || 'N/A') + '</td></tr>';
            html += '<tr><td style="padding:8px; border-bottom:1px solid #eee;"><strong>Trainer:</strong></td><td>' + (program.program_trainer_name || 'N/A') + '</td></tr>';
            html += '<tr><td style="padding:8px; border-bottom:1px solid #eee;"><strong>Status:</strong></td><td>' + (program.enrollment_status || 'N/A') + '</td></tr>';
            html += '<tr><td style="padding:8px; border-bottom:1px solid #eee;"><strong>Attendance:</strong></td><td>' + (program.enrollment_attendance || 0) + '%</td></tr>';
            html += '</table>';
            
            // Ratings
            if(program.trainer_expertise_rating) {
                html += '<h4 style="margin-top:20px;">Ratings</h4>';
                html += '<table style="width:100%; border-collapse:collapse;">';
                html += '<tr><td style="padding:8px; border-bottom:1px solid #eee;">Trainer Expertise:</td><td>' + (program.trainer_expertise_rating || 0) + ' ★</td></tr>';
                html += '<tr><td style="padding:8px; border-bottom:1px solid #eee;">Trainer Communication:</td><td>' + (program.trainer_communication_rating || 0) + ' ★</td></tr>';
                html += '<tr><td style="padding:8px; border-bottom:1px solid #eee;">Trainer Methods:</td><td>' + (program.trainer_methods_rating || 0) + ' ★</td></tr>';
                html += '<tr><td style="padding:8px; border-bottom:1px solid #eee;">Program Knowledge:</td><td>' + (program.program_knowledge_rating || 0) + ' ★</td></tr>';
                html += '</table>';
            }
            
            // Feedback
            if(program.feedback_comments) {
                html += '<h4 style="margin-top:20px;">Feedback Comments</h4>';
                html += '<p style="background:#f8f9fa; padding:10px; border-radius:4px;">' + program.feedback_comments + '</p>';
            }
            
            // Dates
            html += '<h4 style="margin-top:20px;">Important Dates</h4>';
            html += '<table style="width:100%; border-collapse:collapse;">';
            html += '<tr><td style="padding:8px; border-bottom:1px solid #eee;">Applied At:</td><td>' + (program.enrollment_applied_at || 'N/A') + '</td></tr>';
            html += '<tr><td style="padding:8px; border-bottom:1px solid #eee;">Completed At:</td><td>' + (program.enrollment_completed_at || 'N/A') + '</td></tr>';
            html += '<tr><td style="padding:8px; border-bottom:1px solid #eee;">Archived At:</td><td>' + (program.archived_at || 'N/A') + '</td></tr>';
            html += '<tr><td style="padding:8px; border-bottom:1px solid #eee;">Archive Trigger:</td><td>' + (program.archive_trigger || 'N/A') + '</td></tr>';
            html += '</table>';
            
            html += '</div>';
            
            document.getElementById('programDetailsContent').innerHTML = html;
            document.getElementById('programModal').classList.add('active');
        }
        
        function closeProgramModal() {
            document.getElementById('programModal').classList.remove('active');
        }
        
        // Load trainee images when tab is shown
        <?php if($trainee_data): ?>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if(isset($trainee_data['valid_id']) || isset($trainee_data['voters_certificate'])): ?>
            fetch('?ajax=get_image&type=valid_id&id=<?= $view_id ?>')
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        document.getElementById('trainee-images').innerHTML = data.html;
                    }
                });
            <?php endif; ?>
        });
        <?php endif; ?>
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if(e.key === 'Escape') {
                closeImageModal();
                closeProgramModal();
            }
        });
        </script>
        
        <?php
        // Include footer
        include '../components/footer.php';
        exit;
    }
}

// Process GET filters
$role = $_GET['role'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'archived';

// Initialize variables
$archived_users = [];
$total_count = 0;

try {
    // Query to get archived users from new table
    $sql = "SELECT id, original_user_id, fullname, email, role, archived_at, status, restored_at FROM archived_users_history WHERE 1=1";
    $count_sql = "SELECT COUNT(*) as total FROM archived_users_history WHERE 1=1";
    
    $params = [];
    $types = '';

    // Apply status filter - Note: With DELETE approach, 'restored' and 'permanently_deleted' records no longer exist
    // So we need to modify the filter options accordingly
    if (in_array($status, ['archived'])) {
        $sql .= " AND status = ?";
        $count_sql .= " AND status = ?";
        $params[] = $status;
        $types .= 's';
    } else {
        $sql .= " AND status = 'archived'"; // Default to archived only
        $count_sql .= " AND status = 'archived'";
    }

    // Apply role filter
    if ($role !== 'all' && in_array($role, ['trainer', 'trainee'])) {
        $sql .= " AND role = ?";
        $count_sql .= " AND role = ?";
        $params[] = $role;
        $types .= 's';
    }

    // Apply search filter
    if (!empty($search)) {
        $search_term = "%$search%";
        $sql .= " AND (fullname LIKE ? OR email LIKE ?)";
        $count_sql .= " AND (fullname LIKE ? OR email LIKE ?)";
        $params = array_merge($params, [$search_term, $search_term]);
        $types .= 'ss';
    }

    // Add ordering by archive date (newest first)
    $sql .= " ORDER BY archived_at DESC";

    // Prepare and execute main query
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $archived_users = $result->fetch_all(MYSQLI_ASSOC);
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
    error_log("Archived users query error: " . $e->getMessage());
    $flash = "An error occurred while loading archived users. Please try again.";
}

// Flash messages
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Include header
include '../components/header.php';
?>

<style>
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
  .role-select, .status-select {
      width: 160px;
      padding: 8px 12px;
      border: 1px solid #ddd;
      border-radius: 4px;
      background: white;
  }
  .search-input {
      flex: 1;
      min-width: 250px;
      max-width: 400px;
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
      background: #f3f4f6;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      color: #6b7280;
      font-size: 14px;
      flex-shrink: 0;
  }
  .user-info {
      display: flex;
      flex-direction: column;
      min-width: 0;
  }
  .user-name {
      font-weight: 600;
      color: #333;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
  }
  .user-program {
      font-size: 12px;
      color: #6b7280;
      margin-top: 2px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
  }
  .date-cell {
      font-size: 13px;
      color: #6b7280;
      white-space: nowrap;
  }
  .email-cell {
      font-size: 13px;
      color: #374151;
      word-break: break-word;
  }
  .inline-form {
      display: inline;
  }
  .badge {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 500;
      white-space: nowrap;
  }
  .badge-trainer {
      background: #dbeafe;
      color: #1e40af;
  }
  .badge-trainee {
      background: #dcfce7;
      color: #166534;
  }
  .badge-archived {
      background: #f3f4f6;
      color: #6b7280;
  }
  .badge-restored {
      background: #d1fae5;
      color: #065f46;
  }
  .badge-deleted {
      background: #fee2e2;
      color: #991b1b;
  }
  .status-archived {
      color: #6b7280;
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
  .btn-green {
      background: #10b981;
      color: white;
  }
  .btn-green:hover {
      background: #059669;
  }
  .btn-red {
      background: #ef4444;
      color: white;
  }
  .btn-red:hover {
      background: #dc2626;
  }
  .btn-yellow {
      background: #f59e0b;
      color: white;
  }
  .btn-yellow:hover {
      background: #d97706;
  }
  .btn-info {
      background: #6b7280;
      color: white;
  }
  .btn-info:hover {
      background: #4b5563;
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
      white-space: nowrap;
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
      gap: 16px;
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
  .warning-banner {
      background: #fef3c7;
      border: 1px solid #f59e0b;
      border-radius: 8px;
      padding: 16px;
      margin-bottom: 20px;
      color: #92400e;
  }
  .archived-date {
      font-size: 12px;
      color: #9ca3af;
  }
  .card {
      background: white;
      border-radius: 8px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      padding: 20px;
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
      .role-select, .status-select {
          width: 100%;
      }
  }
  
  /* Modal styles */
  .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.9);
  }
  
  .modal-content {
      margin: auto;
      display: block;
      max-width: 90%;
      max-height: 90%;
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
  }
  
  .modal-content img {
      width: 100%;
      height: auto;
  }
  
  .close {
      position: absolute;
      top: 15px;
      right: 35px;
      color: #f1f1f1;
      font-size: 40px;
      font-weight: bold;
      cursor: pointer;
  }
  
  .close:hover {
      color: #bbb;
  }
</style>

<div class="page-header">
  <h1>Archived Users History</h1>
  <div style="display: flex; gap: 12px; flex-wrap: wrap;">
    <a class="btn btn-blue" href="user-management.php">← Back to User Management</a>
  </div>
</div>

<?php if($flash): ?>
  <div class="notice <?= strpos($flash, 'Error') !== false || strpos($flash, 'error') !== false ? 'error' : '' ?>">
    <?= htmlspecialchars($flash) ?>
  </div>
<?php endif; ?>

<div class="warning-banner">
  <p>These users have been archived and are no longer active in the system. You can view details, restore them, or permanently delete them.</p>
</div>

<div class="card">

    <select name="role" class="role-select">
      <option value="all" <?= $role === 'all' ? 'selected' : '' ?>>All Roles</option>
      <option value="trainer" <?= $role === 'trainer' ? 'selected' : '' ?>>Trainers</option>
      <option value="trainee" <?= $role === 'trainee' ? 'selected' : '' ?>>Trainees</option>
    </select>

    <input class="search-input" type="text" name="search" placeholder="Search by name or email..." value="<?= htmlspecialchars($search) ?>">
    
    <button class="btn btn-blue" type="submit">Apply Filters</button>
    <a class="btn btn-ghost" href="archived_users.php">Clear Filters</a>
    
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
        $title = 'Archived Users';
        
        if ($role !== 'all') {
            $title = ucfirst($role) . ' ' . $title;
        }
        if (!empty($search)) {
            $title .= " matching '" . htmlspecialchars($search) . "'";
        }
        echo htmlspecialchars($title);
      ?> 
      <small class="count-small">(<?= count($archived_users) ?> records)</small>
    </h3>

    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>User</th>
            <th>Date Archived</th>
            <th>Email</th>
            <th>Role</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(count($archived_users) === 0): ?>
            <tr>
              <td colspan="5" class="empty">
                <?php if(!empty($search) || $role !== 'all'): ?>
                  No archived users found matching your current filters. 
                  <a href="archived_users.php" style="color: #3b82f6;">Clear filters</a> to see all archived users.
                <?php else: ?>
                  No archived users found.
                <?php endif; ?>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach($archived_users as $u): ?>
              <tr>
                <td>
                  <div class="user-avatar">
                    <div class="avatar">
                      <?= strtoupper(substr(htmlspecialchars($u['fullname'] ?? 'U'), 0, 1)) ?>
                    </div>
                    <div class="user-info">
                      <div class="user-name"><?= htmlspecialchars($u['fullname'] ?: 'No Name') ?></div>
                      <div class="user-program">Original ID: <?= (int)$u['original_user_id'] ?></div>
                    </div>
                  </div>
                </td>
                <td class="date-cell">
                  <?= date('M j, Y g:i A', strtotime($u['archived_at'])) ?>
                </td>
                <td class="email-cell"><?= htmlspecialchars($u['email']) ?></td>
                <td>
                  <span class="badge <?= $u['role'] === 'trainer' ? 'badge-trainer' : 'badge-trainee' ?>">
                    <?= htmlspecialchars(ucfirst($u['role'])) ?>
                  </span>
                </td>
                <td class="actions">
                  <a href="?view=<?= (int)$u['id'] ?>" class="btn btn-info" target="_blank">
                    <i class="fas fa-eye"></i> View Details
                  </a>
                  
                  <form class="inline-form" method="POST" action="" onsubmit="return confirm('Are you sure you want to restore this user? They will be moved back to active users with all their completed programs. This record will be deleted from the archive.');">
                      <input type="hidden" name="archive_id" value="<?= (int)$u['id'] ?>">
                      <input type="hidden" name="restore_user" value="1">
                      <button class="btn btn-green" type="submit"><i class="fas fa-undo"></i> Restore</button>
                  </form>
                  
                  <form class="inline-form" method="POST" action="" onsubmit="return confirm('⚠️ WARNING: This will permanently delete this user and all their archived data. This action cannot be undone! This record will be permanently removed from the archive.');">
                      <input type="hidden" name="archive_id" value="<?= (int)$u['id'] ?>">
                      <input type="hidden" name="delete_user" value="1">
                      <button class="btn btn-red" type="submit"><i class="fas fa-trash"></i> Permanently Delete</button>
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

<!-- Image Modal -->
<div id="imageModal" class="modal">
  <span class="close" onclick="document.getElementById('imageModal').style.display='none'">&times;</span>
  <img class="modal-content" id="modalImage">
</div>

<script>
// Open image in modal
function openImageModal(src) {
    document.getElementById('modalImage').src = src;
    document.getElementById('imageModal').style.display = 'block';
}

// Close modal when clicking outside the image
window.onclick = function(event) {
    var modal = document.getElementById('imageModal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}
</script>

</body>
</html>