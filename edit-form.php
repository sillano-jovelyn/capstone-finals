<?php
// edit-form.php - Public edit form for trainees
session_start();
require_once 'db.php'; // Your database connection

// Check if token is provided
if (!isset($_GET['token']) || empty($_GET['token'])) {
    die("Invalid access. No token provided.");
}

$token = $_GET['token'];

// Validate token
function validateToken($token) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT rr.*, e.*, p.name as program_name, 
               t.*, t.user_id as trainee_user_id,
               (rr.token_expiry > NOW()) as token_valid
        FROM revision_requests rr
        JOIN enrollments e ON rr.enrollment_id = e.id
        JOIN programs p ON e.program_id = p.id
        JOIN trainees t ON e.user_id = t.user_id
        WHERE rr.edit_token = ? 
        AND rr.status = 'pending'
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $data = $result->fetch_assoc();
        
        if ($data['token_valid'] == 0) {
            return ['valid' => false, 'message' => 'This edit link has expired. Please contact the administrator.'];
        }
        
        return ['valid' => true, 'data' => $data];
    }
    
    return ['valid' => false, 'message' => 'Invalid edit link.'];
}

$validation = validateToken($token);

if (!$validation['valid']) {
    $error_message = $validation['message'];
} else {
    $data = $validation['data'];
    $trainee = $data;
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $conn->begin_transaction();
        
        try {
            // Handle document deletion if requested
            if (isset($_POST['delete_docs'])) {
                foreach ($_POST['delete_docs'] as $docType => $filesToDelete) {
                    if (is_array($filesToDelete)) {
                        $existingDocs = json_decode($trainee[$docType], true) ?? [];
                        $updatedDocs = array_diff($existingDocs, $filesToDelete);
                        
                        // Delete files from server
                        foreach ($filesToDelete as $fileToDelete) {
                            $filePath = "imageFile/" . $fileToDelete;
                            if (file_exists($filePath)) {
                                unlink($filePath);
                            }
                        }
                        
                        // Update database
                        $stmt = $conn->prepare("UPDATE trainees SET $docType = ? WHERE user_id = ?");
                        $updatedDocsJson = !empty($updatedDocs) ? json_encode(array_values($updatedDocs)) : NULL;
                        $stmt->bind_param("ss", $updatedDocsJson, $trainee['trainee_user_id']);
                        $stmt->execute();
                        
                        // Update local trainee data
                        $trainee[$docType] = $updatedDocsJson;
                    }
                }
            }
            
            // Update trainee information
            $update_fields = [];
            $params = [];
            $types = "";
            
            // Personal Information
            $fields_to_update = [
                'fullname' => $_POST['fullname'],
                'firstname' => $_POST['firstname'],
                'lastname' => $_POST['lastname'],
                'contact_number' => $_POST['contact_number'],
                'barangay' => $_POST['barangay'],
                'municipality' => $_POST['municipality'],
                'city' => $_POST['city'],
                'address' => $_POST['address'],
                'gender' => $_POST['gender'],
                'age' => $_POST['age'],
                'education' => $_POST['education']
            ];
            
            foreach ($fields_to_update as $field => $value) {
                if (isset($value)) {
                    $update_fields[] = "$field = ?";
                    $params[] = $value;
                    $types .= "s";
                }
            }
            
            if (!empty($update_fields)) {
                $query = "UPDATE trainees SET " . implode(", ", $update_fields) . " WHERE user_id = ?";
                $params[] = $trainee['trainee_user_id'];
                $types .= "s";
                
                $stmt = $conn->prepare($query);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
            }
            
            // Handle file uploads
            $documents = [];
            
            // Handle Valid ID upload
            if (!empty($_FILES['valid_id']['name'][0])) {
                $valid_id_files = json_decode($trainee['valid_id'] ?? '[]', true) ?: [];
                for ($i = 0; $i < count($_FILES['valid_id']['name']); $i++) {
                    if ($_FILES['valid_id']['error'][$i] === UPLOAD_ERR_OK) {
                        $file_name = uniqid() . '_' . basename($_FILES['valid_id']['name'][$i]);
                        $target_file = "imageFile/" . $file_name;
                        
                        if (move_uploaded_file($_FILES['valid_id']['tmp_name'][$i], $target_file)) {
                            $valid_id_files[] = $file_name;
                        }
                    }
                }
                if (!empty($valid_id_files)) {
                    $documents['valid_id'] = json_encode($valid_id_files);
                }
            }
            
            // Handle Voter's Certificate upload
            if (!empty($_FILES['voters_certificate']['name'][0])) {
                $voter_cert_files = json_decode($trainee['voters_certificate'] ?? '[]', true) ?: [];
                for ($i = 0; $i < count($_FILES['voters_certificate']['name']); $i++) {
                    if ($_FILES['voters_certificate']['error'][$i] === UPLOAD_ERR_OK) {
                        $file_name = uniqid() . '_' . basename($_FILES['voters_certificate']['name'][$i]);
                        $target_file = "imageFile/" . $file_name;
                        
                        if (move_uploaded_file($_FILES['voters_certificate']['tmp_name'][$i], $target_file)) {
                            $voter_cert_files[] = $file_name;
                        }
                    }
                }
                if (!empty($voter_cert_files)) {
                    $documents['voters_certificate'] = json_encode($voter_cert_files);
                }
            }
            
            // Update documents in database
            if (!empty($documents)) {
                $update_docs = [];
                $doc_params = [];
                $doc_types = "";
                
                foreach ($documents as $field => $value) {
                    $update_docs[] = "$field = ?";
                    $doc_params[] = $value;
                    $doc_types .= "s";
                }
                
                $doc_query = "UPDATE trainees SET " . implode(", ", $update_docs) . " WHERE user_id = ?";
                $doc_params[] = $trainee['trainee_user_id'];
                $doc_types .= "s";
                
                $stmt = $conn->prepare($doc_query);
                $stmt->bind_param($doc_types, ...$doc_params);
                $stmt->execute();
            }
            
            // Mark revision as completed
            $stmt = $conn->prepare("
                UPDATE revision_requests 
                SET status = 'completed', 
                    completed_at = NOW()
                WHERE edit_token = ?
            ");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            
            // FIXED: Removed 'updated_at' column reference
            $stmt = $conn->prepare("
                UPDATE enrollments 
                SET enrollment_status = 'pending',
                    revision_requests_id = NULL
                WHERE id = ?
            ");
            $stmt->bind_param("i", $data['enrollment_id']);
            $stmt->execute();
            
            $conn->commit();
            
            $success_message = "Your information has been updated successfully! Your application is now back in pending status for admin approval.";
            $submitted = true;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error updating your information: " . $e->getMessage();
        }
    }
}

// Parse existing documents
$existing_docs = [];
if (isset($trainee['valid_id']) && !empty($trainee['valid_id'])) {
    $existing_docs['valid_id'] = json_decode($trainee['valid_id'], true);
}
if (isset($trainee['voters_certificate']) && !empty($trainee['voters_certificate'])) {
    $existing_docs['voters_certificate'] = json_decode($trainee['voters_certificate'], true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Your Application - Livelihood Program</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
   <style>

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Poppins', sans-serif;
        min-height: 100vh;
        background-image: url('css/SMBHALL.png');
        background-size: cover;
        background-position: center center;
        background-attachment: fixed;
        background-repeat: no-repeat;
        color: #333;
        padding: 10px; /* Reduced from 20px */
        background-color: #1c2a3a;
    }
    
    /* FULL BLUE BACKGROUND OVERLAY */
    body::before {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(28, 42, 58, 0.85);
        z-index: -1;
    }
    
    .container {
        max-width: 750px; 
        margin: 0 auto;
        padding: 0 15px; 
    }
    
    .edit-form-wrapper {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }
    

    .header-card {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 8px; 
        padding: 15px 20px; 
        margin-bottom: 10px; 
        box-shadow: 0 5px 15px rgba(0,0,0,0.3); 
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        border-left: 3px solid #20c997; 
        max-width: 100%;
        margin-left: auto;
        margin-right: auto;
    }
    
    .header-card h1 {
        font-size: 1.5rem; 
        margin-bottom: 0.3rem;
        color: black;
    }
    
    .header-card .lead {
        font-size: 0.9rem; 
        margin-bottom: 0.5rem;
    }
    

 
    .form-card {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 8px; /* Reduced */
        padding: 20px; /* Reduced */
        box-shadow: 0 5px 15px rgba(0,0,0,0.3); /* Reduced */
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        margin-top: 10px; /* Reduced */
        margin-bottom: 20px; /* Reduced */
        max-width: 750px;
        margin-left: auto;
        margin-right: auto;
    }
    
    .form-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px; /* Reduced */
        background: linear-gradient(90deg, #20c997, #3b82f6);
    }
    
    /* Remove excessive spacing from form sections */
    .form-section {
        margin-bottom: 15px; /* Reduced */
        padding-bottom: 10px; /* Reduced */
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .form-section:last-child {
        margin-bottom: 0;
        padding-bottom: 0;
        border-bottom: none;
    }
    
    .form-section h4 {
        color: white;
        margin-bottom: 10px; /* Reduced */
        padding-bottom: 5px; /* Reduced */
        border-bottom: 2px solid #20c997;
        text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        font-size: 1rem; /* Reduced */
        font-weight: 600;
    }
    
    /* Reduce spacing in form rows */
    .row {
        margin-bottom: 8px; /* Reduced spacing between form rows */
    }
    
    .row:last-child {
        margin-bottom: 0;
    }
    
    .mb-3 {
        margin-bottom: 0.5rem !important; /* Reduced from default 1rem */
    }
    
    .mb-4 {
        margin-bottom: 1rem !important; /* Reduced */
    }
    
    .required:after {
        content: " *";
        color: #ff6b6b;
    }
    
    .file-upload-area {
        border: 2px dashed rgba(255, 255, 255, 0.3);
        border-radius: 6px; /* Reduced */
        padding: 15px; /* Reduced */
        text-align: center;
        margin-bottom: 10px; /* Reduced */
        transition: all 0.3s;
        cursor: pointer;
        background: rgba(0, 0, 0, 0.2);
        backdrop-filter: blur(5px);
        color: white;
        font-size: 0.85rem; /* Reduced */
    }
    
    .file-upload-area:hover {
        border-color: #20c997;
        background: rgba(32, 201, 151, 0.1);
    }
    
    .file-upload-area.dragover {
        border-color: #20c997;
        background: rgba(32, 201, 151, 0.2);
    }
    
    .uploaded-files {
        margin-top: 5px; /* Reduced */
    }
    
    .file-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px; /* Reduced */
        background: rgba(0, 0, 0, 0.2);
        border-radius: 5px; /* Reduced */
        margin-bottom: 5px; /* Reduced */
        backdrop-filter: blur(5px);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: white;
        font-size: 0.8rem; /* Reduced */
    }
    
    .file-info {
        display: flex;
        align-items: center;
        color: white;
    }
    
    .file-info i {
        margin-right: 6px; /* Reduced */
        color: #20c997;
        font-size: 0.8rem; /* Reduced */
    }
    
    .delete-btn {
        background: #dc3545;
        color: white;
        border: none;
        border-radius: 50%;
        width: 20px; /* Reduced */
        height: 20px; /* Reduced */
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 10px; /* Reduced */
        transition: all 0.3s;
        backdrop-filter: blur(5px);
    }
    
    .delete-btn:hover {
        background: #c82333;
        transform: scale(1.1);
    }
    
    .btn-custom {
        background: linear-gradient(135deg, #20c997, #17a589);
        color: white;
        padding: 8px 20px; /* Reduced */
        border-radius: 5px; /* Reduced */
        font-weight: bold;
        transition: all 0.3s;
        border: none;
        text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        box-shadow: 0 3px 10px rgba(32, 201, 151, 0.3);
        font-size: 0.85rem; /* Reduced */
    }
    
    .btn-custom:hover {
        background: linear-gradient(135deg, #17a589, #20c997);
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(32, 201, 151, 0.4);
        color: white;
    }
    
    .success-card,
    .expired-card {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 8px; /* Reduced */
        padding: 25px; /* Reduced */
        text-align: center;
        box-shadow: 0 5px 15px rgba(0,0,0,0.3); /* Reduced */
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: white;
        max-width: 750px;
        margin: 10px auto;
    }
    
    .success-icon,
    .expired-icon {
        font-size: 50px; /* Reduced */
        margin-bottom: 10px; /* Reduced */
        text-shadow: 0 4px 6px rgba(0,0,0,0.3);
    }
    
    /* ==========================================
       FORM CONTROLS STYLES - MORE COMPACT
    ========================================== */
    .form-control, .form-select {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2); /* Reduced from 2px */
        border-radius: 5px; /* Reduced */
        font-size: 13px; /* Reduced */
        transition: all 0.3s;
        color: white;
        padding: 8px 10px; /* Reduced */
        height: 38px; /* Set consistent height */
        min-height: 38px;
    }
    
    .form-control:focus, .form-select:focus {
        background: rgba(255, 255, 255, 0.15);
        border-color: #20c997;
        box-shadow: 0 0 0 2px rgba(32, 201, 151, 0.2); /* Reduced */
        color: white;
    }
    
    .form-control[readonly] {
        background: rgba(255, 255, 255, 0.05);
        color: rgba(255, 255, 255, 0.7);
    }
    
    .form-control::placeholder {
        color: rgba(255, 255, 255, 0.6);
        font-size: 0.85rem; /* Reduced */
    }
    
    select.form-control option {
        background: #1c2a3a;
        color: white;
        padding: 5px; /* Reduced padding for options */
    }
    
    label {
        color: white;
        font-weight: 600;
        margin-bottom: 4px; /* Reduced */
        text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        font-size: 0.85rem; /* Reduced */
        display: block;
    }
    
    /* Compact alerts */
    .alert {
        backdrop-filter: blur(5px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        padding: 10px 12px; /* Reduced */
        font-size: 0.85rem; /* Reduced */
        margin-bottom: 10px; /* Reduced */
        border-radius: 5px;
    }
    
    .alert-info {
        background: rgba(59, 130, 246, 0.2);
        color: rgba(255, 255, 255, 0.9);
        border-left: 3px solid #3b82f6; /* Reduced */
    }
    
    .alert-warning {
        background: rgba(245, 158, 11, 0.2);
        color: rgba(255, 255, 255, 0.9);
        border-left: 3px solid #f59e0b; /* Reduced */
    }
    
    .alert-danger {
        background: rgba(239, 68, 68, 0.2);
        color: rgba(255, 255, 255, 0.9);
        border-left: 3px solid #ef4444; /* Reduced */
    }
    
    .alert-success {
        background: rgba(34, 197, 94, 0.2);
        color: rgba(255, 255, 255, 0.9);
        border-left: 3px solid #22c55e; /* Reduced */
    }
    
    h2 {
        font-size: 1.3rem; /* Reduced */
        margin-bottom: 0.5rem;
        color:white;
    }
    
    h1, .lead, p {
        color: white !important;
        text-shadow: 0 1px 2px rgba(0,0,0,0.2);
    }
    
    .text-muted {
        color: rgba(255, 255, 255, 0.7) !important;
        font-size: 0.8rem; /* Reduced */
    }
    
    .text-success {
        color: #20c997 !important;
    }
    
    /* Make program info section more compact */
    .program-info {
        background: rgba(0, 0, 0, 0.15);
        padding: 8px 12px;
        border-radius: 5px;
        margin: 8px 0;
        font-size: 0.85rem;
    }
    
    /* ==========================================
       BUTTON STYLES - MORE COMPACT
    ========================================== */
    .btn-outline-secondary {
        background: rgba(108, 117, 125, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.3); /* Reduced */
        color: white;
        backdrop-filter: blur(5px);
        padding: 7px 12px; /* Reduced */
        font-size: 0.85rem; /* Reduced */
        height: 38px;
        border-radius: 5px;
    }
    
    .btn-outline-secondary:hover {
        background: rgba(108, 117, 125, 0.3);
        border-color: white;
        color: white;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #20c997, #17a589);
        border: none;
        color: white;
        text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        box-shadow: 0 3px 10px rgba(32, 201, 151, 0.2);
        padding: 8px 20px; /* Reduced */
        font-size: 0.85rem; /* Reduced */
        height: 38px;
        border-radius: 5px;
    }
    
    .btn-primary:hover {
        background: linear-gradient(135deg, #17a589, #20c997);
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(32, 201, 151, 0.3);
        color: white;
    }
    
    /* Submit button container */
    .submit-container {
        margin-top: 15px; /* Reduced */
        padding-top: 15px; /* Reduced */
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    /* ==========================================
       RESPONSIVE STYLES - MORE COMPACT
    ========================================== */
    @media (max-width: 768px) {
        body {
            padding: 5px; /* Further reduced */
        }
        
        .container {
            padding: 0 5px;
            max-width: 100%;
        }
        
        .header-card, .form-card {
            padding: 12px; /* Reduced */
            margin-bottom: 8px;
        }
        
        .header-card h1 {
            font-size: 1.3rem;
        }
        
        .header-card .lead {
            font-size: 0.8rem;
        }
        
        .form-section {
            margin-bottom: 12px;
            padding-bottom: 8px;
        }
        
        .form-section h4 {
            font-size: 0.95rem;
            margin-bottom: 8px;
        }
        
        .form-control, .form-select {
            font-size: 12px;
            padding: 6px 8px;
            height: 36px;
        }
        
        .btn-custom, .btn-primary, .btn-outline-secondary {
            padding: 6px 15px;
            font-size: 0.8rem;
            height: 36px;
            width: 100%; /* Full width on mobile */
            margin-top: 5px;
        }
        
        .row {
            margin-bottom: 6px;
        }
        
        .mb-3 {
            margin-bottom: 0.4rem !important;
        }
    }
    
    /* Extra small devices */
    @media (max-width: 576px) {
        .header-card, .form-card {
            padding: 10px;
        }
        
        .form-control, .form-select {
            font-size: 11px;
        }
        
        label {
            font-size: 0.8rem;
        }
    }
</style>
</head>
<body>
    <div class="edit-form-wrapper">
        <div class="container">
            <?php if (isset($error_message) && !isset($validation['valid'])): ?>
                <!-- Invalid/Expired Token View -->
                <div class="expired-card">
                    <div class="expired-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h2>Link Invalid or Expired</h2>
                    <p class="lead"><?php echo $error_message; ?></p>
                    <p>If you believe this is an error, please contact the program administrator.</p>
                    <a href="contact.php" class="btn btn-primary mt-3">
                        <i class="fas fa-envelope"></i> Contact Administrator
                    </a>
                </div>
                
            <?php elseif (isset($submitted) && $submitted): ?>
                <!-- Success View -->
                <div class="success-card">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h2>Update Successful!</h2>
                    <p class="lead"><?php echo $success_message; ?></p>
                    <p>Your application has been updated and is now pending admin approval.</p>
                    <p>You will be notified once your application has been reviewed.</p>
                    <div class="mt-4">
                        <a href="index.php" class="btn btn-primary">
                            <i class="fas fa-home"></i> Return to Home
                        </a>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- Edit Form -->
                <div class="header-card">
                    <div class="row align-items-center">
                        <div class="col-md-9">
                            <h2><i class="fas fa-edit"></i> Update Your Application</h2>
                            <p class="lead mb-0">Please review and update your information as requested by the administrator.</p>
                        </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-card">
                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Program:</strong> <?php echo htmlspecialchars($data['program_name']); ?> |
                        <strong>Application ID:</strong> #<?php echo $data['enrollment_id']; ?>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" id="editForm">
                        <!-- Hidden field to track deleted documents -->
                        <div id="deleteDocsContainer"></div>
                        
                        <!-- Personal Information Section -->
                        <div class="form-section">
                            <h4><i class="fas fa-user"></i> Personal Information</h4>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Full Name</label>
                                    <input type="text" class="form-control" name="fullname" 
                                           value="<?php echo htmlspecialchars($trainee['fullname'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">First Name</label>
                                    <input type="text" class="form-control" name="firstname" 
                                           value="<?php echo htmlspecialchars($trainee['firstname'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Last Name</label>
                                    <input type="text" class="form-control" name="lastname" 
                                           value="<?php echo htmlspecialchars($trainee['lastname'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Contact Number</label>
                                    <input type="tel" class="form-control" name="contact_number" 
                                           value="<?php echo htmlspecialchars($trainee['contact_number'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Email Address</label>
                                    <input type="email" class="form-control" value="<?php echo htmlspecialchars($trainee['email'] ?? ''); ?>" readonly>
                                    <small class="text-muted">Email cannot be changed</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Gender</label>
                                    <select class="form-control" name="gender" required>
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?php echo ($trainee['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($trainee['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo ($trainee['gender'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Age</label>
                                    <input type="number" class="form-control" name="age" 
                                           value="<?php echo htmlspecialchars($trainee['age'] ?? ''); ?>" min="16" max="100" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Education Level</label>
                                    <select class="form-control" name="education" required>
                                        <option value="">Select Education</option>
                                        <option value="Elementary" <?php echo ($trainee['education'] ?? '') === 'Elementary' ? 'selected' : ''; ?>>Elementary</option>
                                        <option value="High School" <?php echo ($trainee['education'] ?? '') === 'High School' ? 'selected' : ''; ?>>High School</option>
                                        <option value="College" <?php echo ($trainee['education'] ?? '') === 'College' ? 'selected' : ''; ?>>College</option>
                                        <option value="Vocational" <?php echo ($trainee['education'] ?? '') === 'Vocational' ? 'selected' : ''; ?>>Vocational</option>
                                        <option value="Post Graduate" <?php echo ($trainee['education'] ?? '') === 'Post Graduate' ? 'selected' : ''; ?>>Post Graduate</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Address Information Section -->
                        <div class="form-section">
                            <h4><i class="fas fa-map-marker-alt"></i> Address Information</h4>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Barangay</label>
                                    <input type="text" class="form-control" name="barangay" 
                                           value="<?php echo htmlspecialchars($trainee['barangay'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Municipality</label>
                                    <input type="text" class="form-control" name="municipality" 
                                           value="<?php echo htmlspecialchars($trainee['municipality'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">City</label>
                                    <input type="text" class="form-control" name="city" 
                                           value="<?php echo htmlspecialchars($trainee['city'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Complete Address</label>
                                    <input type="text" class="form-control" name="address" 
                                           value="<?php echo htmlspecialchars($trainee['address'] ?? ''); ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Documents Section -->
                        <div class="form-section">
                            <h4><i class="fas fa-file-alt"></i> Documents</h4>
                            
                            <!-- Valid ID -->
                            <div class="mb-4">
                                <label class="form-label">Valid ID (Government Issued)</label>
                                <p class="text-muted small">Upload scanned copy or photo of any valid government ID (Passport, Driver's License, etc.)</p>
                                
                                <!-- Existing Files with Delete Buttons -->
                                <?php if (!empty($existing_docs['valid_id']) && is_array($existing_docs['valid_id'])): ?>
                                    <div class="mb-3">
                                        <p class="text-success"><i class="fas fa-check-circle"></i> Currently uploaded:</p>
                                        <div class="uploaded-files" id="existingValidIdFiles">
                                            <?php foreach($existing_docs['valid_id'] as $file): ?>
                                                <div class="file-item" data-file="<?php echo htmlspecialchars($file); ?>" data-type="valid_id">
                                                    <div class="file-info">
                                                        <i class="fas fa-file-pdf"></i>
                                                        <span><?php echo htmlspecialchars(basename($file)); ?></span>
                                                    </div>
                                                    <button type="button" class="delete-btn" onclick="deleteExistingFile(this, 'valid_id')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="text-muted small mt-2">Upload new files to add to existing ones</p>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- File Upload Area -->
                                <div class="file-upload-area" onclick="document.getElementById('validIdFiles').click()" 
                                     ondragover="handleDragOver(event)" 
                                     ondragleave="handleDragLeave(event)" 
                                     ondrop="handleDrop(event, 'validIdFiles')">
                                    <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                    <h5>Drag & Drop or Click to Upload</h5>
                                    <p class="text-muted">PDF, JPG, PNG files (Max 5MB each)</p>
                                    <p class="text-muted small">You can upload multiple files</p>
                                </div>
                                <input type="file" id="validIdFiles" name="valid_id[]" multiple 
                                       accept=".pdf,.jpg,.jpeg,.png" style="display: none;" 
                                       onchange="displayFiles(this, 'validIdFilesList')">
                                <div id="validIdFilesList" class="uploaded-files"></div>
                            </div>
                            
                            <!-- Voter's Certificate/Residency -->
                            <div class="mb-4">
                                <label class="form-label">Voter's Certificate/Proof of Residency</label>
                                <p class="text-muted small">Upload proof of residency or voter's certificate</p>
                                
                                <!-- Existing Files with Delete Buttons -->
                                <?php if (!empty($existing_docs['voters_certificate']) && is_array($existing_docs['voters_certificate'])): ?>
                                    <div class="mb-3">
                                        <p class="text-success"><i class="fas fa-check-circle"></i> Currently uploaded:</p>
                                        <div class="uploaded-files" id="existingVoterCertFiles">
                                            <?php foreach($existing_docs['voters_certificate'] as $file): ?>
                                                <div class="file-item" data-file="<?php echo htmlspecialchars($file); ?>" data-type="voters_certificate">
                                                    <div class="file-info">
                                                        <i class="fas fa-file-image"></i>
                                                        <span><?php echo htmlspecialchars(basename($file)); ?></span>
                                                    </div>
                                                    <button type="button" class="delete-btn" onclick="deleteExistingFile(this, 'voters_certificate')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="text-muted small mt-2">Upload new files to add to existing ones</p>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- File Upload Area -->
                                <div class="file-upload-area" onclick="document.getElementById('voterCertFiles').click()" 
                                     ondragover="handleDragOver(event)" 
                                     ondragleave="handleDragLeave(event)" 
                                     ondrop="handleDrop(event, 'voterCertFiles')">
                                    <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                    <h5>Drag & Drop or Click to Upload</h5>
                                    <p class="text-muted">PDF, JPG, PNG files (Max 5MB each)</p>
                                    <p class="text-muted small">You can upload multiple files</p>
                                </div>
                                <input type="file" id="voterCertFiles" name="voters_certificate[]" multiple 
                                       accept=".pdf,.jpg,.jpeg,.png" style="display: none;" 
                                       onchange="displayFiles(this, 'voterCertFilesList')">
                                <div id="voterCertFilesList" class="uploaded-files"></div>
                            </div>
                        </div>
                        
                        <!-- Submission -->
                        <div class="mt-4">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Important:</strong> After submission, your application will return to pending status for admin approval. 
                                Please ensure all information is accurate before submitting.
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="button" class="btn btn-outline-secondary me-md-2" 
                                        onclick="if(confirm('Are you sure? Any changes will be lost.')) window.location.reload();">
                                    <i class="fas fa-undo"></i> Reset Form
                                </button>
                                <button type="submit" class="btn btn-custom">
                                    <i class="fas fa-paper-plane"></i> Submit Updated Application
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Track deleted files
        const deletedFiles = {
            valid_id: [],
            voters_certificate: []
        };
        
        // Delete existing file
        function deleteExistingFile(button, docType) {
            const fileItem = button.closest('.file-item');
            const fileName = fileItem.getAttribute('data-file');
            
            if (confirm('Are you sure you want to delete this file? This action cannot be undone.')) {
                // Add to deleted files list
                if (!deletedFiles[docType].includes(fileName)) {
                    deletedFiles[docType].push(fileName);
                    
                    // Create hidden input for deleted file
                    const container = document.getElementById('deleteDocsContainer');
                    const inputName = `delete_docs[${docType}][]`;
                    let existingInput = container.querySelector(`input[value="${fileName}"]`);
                    
                    if (!existingInput) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = inputName;
                        input.value = fileName;
                        container.appendChild(input);
                    }
                }
                
                // Remove from UI
                fileItem.remove();
                
                // Show message if no files left
                const containerId = docType === 'valid_id' ? 'existingValidIdFiles' : 'existingVoterCertFiles';
                const container = document.getElementById(containerId);
                if (container && container.children.length === 0) {
                    container.innerHTML = '<p class="text-muted">No files uploaded yet.</p>';
                }
            }
        }
        
        // File upload handling
        function handleDragOver(e) {
            e.preventDefault();
            e.stopPropagation();
            e.target.classList.add('dragover');
        }
        
        function handleDragLeave(e) {
            e.preventDefault();
            e.stopPropagation();
            e.target.classList.remove('dragover');
        }
        
        function handleDrop(e, inputId) {
            e.preventDefault();
            e.stopPropagation();
            e.target.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            const input = document.getElementById(inputId);
            
            // Create a new DataTransfer object
            const dataTransfer = new DataTransfer();
            
            // Add existing files
            for (let i = 0; i < input.files.length; i++) {
                dataTransfer.items.add(input.files[i]);
            }
            
            // Add new files
            for (let i = 0; i < files.length; i++) {
                dataTransfer.items.add(files[i]);
            }
            
            // Update input files
            input.files = dataTransfer.files;
            
            // Trigger change event
            const event = new Event('change', { bubbles: true });
            input.dispatchEvent(event);
        }
        
        function displayFiles(input, listId) {
            const fileList = document.getElementById(listId);
            fileList.innerHTML = '';
            
            for (let i = 0; i < input.files.length; i++) {
                const file = input.files[i];
                const fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                fileItem.innerHTML = `
                    <div class="file-info">
                        <i class="fas fa-file"></i>
                        <span>${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)</span>
                    </div>
                    <button type="button" class="delete-btn" onclick="removeNewFile(this, '${input.id}')">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                fileList.appendChild(fileItem);
            }
        }
        
        // Remove newly uploaded file (not yet saved)
        function removeNewFile(button, inputId) {
            const fileItem = button.closest('.file-item');
            const fileName = fileItem.querySelector('span').textContent.split(' (')[0];
            const input = document.getElementById(inputId);
            
            // Create new FileList without the removed file
            const dataTransfer = new DataTransfer();
            
            for (let i = 0; i < input.files.length; i++) {
                if (input.files[i].name !== fileName) {
                    dataTransfer.items.add(input.files[i]);
                }
            }
            
            input.files = dataTransfer.files;
            fileItem.remove();
            
            // Update display
            displayFiles(input, inputId === 'validIdFiles' ? 'validIdFilesList' : 'voterCertFilesList');
        }
        
        // Countdown timer for token expiry
        function updateTimer() {
            const expiryTime = new Date("<?php echo $data['token_expiry'] ?? ''; ?>").getTime();
            const now = new Date().getTime();
            const distance = expiryTime - now;
            
            if (distance < 0) {
                document.getElementById('timer').innerHTML = "Link has expired!";
                document.getElementById('timerAlert').className = 'alert alert-danger';
                return;
            }
            
            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);
            
            let timerText = "Link expires in: ";
            if (days > 0) timerText += `${days}d `;
            if (hours > 0) timerText += `${hours}h `;
            if (minutes > 0) timerText += `${minutes}m `;
            timerText += `${seconds}s`;
            
            document.getElementById('timer').innerHTML = timerText;
            
            // Change color when less than 1 hour remains
            if (distance < 3600000) { // 1 hour
                document.getElementById('timerAlert').className = 'alert alert-danger';
            } else if (distance < 86400000) { // 1 day
                document.getElementById('timerAlert').className = 'alert alert-warning';
            }
        }
        
        // Initialize timer if token is valid
        <?php if (isset($validation['valid']) && $validation['valid']): ?>
            updateTimer();
            setInterval(updateTimer, 1000);
        <?php endif; ?>
        
        // Form validation
        document.getElementById('editForm').addEventListener('submit', function(e) {
            let valid = true;
            const requiredFields = this.querySelectorAll('[required]');
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    valid = false;
                    field.classList.add('is-invalid');
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            if (!valid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            } else {
                // Show loading
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                submitBtn.disabled = true;
            }
        });
    </script>
</body>
</html>