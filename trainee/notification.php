<?php
// notification.php - All-in-one notification system
session_start();
require_once '../db.php';

// ==========================================
// 1. NOTIFICATION FUNCTIONS
// ==========================================

function sendNotification($user_id, $title, $message) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $user_id, $title, $message);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function sendNotificationToAdmin($title, $message) {
    global $conn;
    $stmt = $conn->prepare("SELECT id FROM users WHERE role = 'admin'");
    $stmt->execute();
    $result = $stmt->get_result();
    $sent_count = 0;
    while ($row = $result->fetch_assoc()) {
        if (sendNotification($row['id'], $title, $message)) $sent_count++;
    }
    $stmt->close();
    return $sent_count;
}

function sendNotificationToTrainees($title, $message) {
    global $conn;
    $stmt = $conn->prepare("SELECT u.id FROM users u WHERE u.role = 'trainee'");
    $stmt->execute();
    $result = $stmt->get_result();
    $sent_count = 0;
    while ($row = $result->fetch_assoc()) {
        if (sendNotification($row['id'], $title, $message)) $sent_count++;
    }
    $stmt->close();
    return $sent_count;
}

function sendNotificationToSpecificTrainee($trainee_user_id, $title, $message) {
    return sendNotification($trainee_user_id, $title, $message);
}

function sendNotificationToAllUsers($title, $message) {
    global $conn;
    $stmt = $conn->prepare("SELECT id FROM users");
    $stmt->execute();
    $result = $stmt->get_result();
    $sent_count = 0;
    while ($row = $result->fetch_assoc()) {
        if (sendNotification($row['id'], $title, $message)) $sent_count++;
    }
    $stmt->close();
    return $sent_count;
}

function sendEnrollmentNotification($trainee_user_id, $trainee_name, $program_name, $status) {
    $title = "Enrollment Application $status";
    if ($status === 'submitted') {
        $message = "Your application for '$program_name' has been submitted successfully. Please wait for admin approval.";
    } elseif ($status === 'approved') {
        $message = "Congratulations! Your application for '$program_name' has been approved. Please check your dashboard for schedule details.";
    } elseif ($status === 'rejected') {
        $message = "Your application for '$program_name' has been reviewed. Unfortunately, it was not approved at this time.";
    } elseif ($status === 'pending') {
        $message = "Your application for '$program_name' is currently under review. We'll notify you once a decision is made.";
    }
    return sendNotification($trainee_user_id, $title, $message);
}

function sendAdminEnrollmentNotification($trainee_name, $program_name) {
    global $conn;
    $title = "New Enrollment Application";
    $message = "$trainee_name has submitted an application for '$program_name'. Please review in the admin panel.";
    $sent_count = 0;
    $stmt = $conn->prepare("SELECT id FROM users WHERE role = 'admin'");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (sendNotification($row['id'], $title, $message)) $sent_count++;
    }
    $stmt->close();
    return $sent_count;
}

function sendTrainingNotification($trainee_user_id, $program_name, $type, $details = '') {
    $titles = [
        'start'      => "Training Session Started",
        'reminder'   => "Training Session Reminder",
        'update'     => "Training Session Update",
        'completion' => "Training Session Completed"
    ];
    $messages = [
        'start'      => "Your training session for '$program_name' has started. Please attend regularly.",
        'reminder'   => "Reminder: You have a training session for '$program_name' tomorrow. $details",
        'update'     => "Update for '$program_name': $details",
        'completion' => "Congratulations! You have successfully completed '$program_name'. Please submit your feedback."
    ];
    $title   = $titles[$type]   ?? "Training Notification";
    $message = $messages[$type] ?? $details;
    return sendNotification($trainee_user_id, $title, $message);
}

function sendSystemNotification($user_id, $type, $details) {
    $titles = [
        'info'        => "System Information",
        'warning'     => "System Warning",
        'important'   => "Important Announcement",
        'maintenance' => "System Maintenance"
    ];
    $title   = $titles[$type] ?? "System Notification";
    $message = $details;
    return sendNotification($user_id, $title, $message);
}

// ==========================================
// 2. AJAX ENDPOINTS
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action   = $_POST['action'];
    $response = ['success' => false];

    switch ($action) {

        case 'load_notifications':
            if (isset($_SESSION['user_id'])) {
                $user_id = $_SESSION['user_id'];
                // CHANGED: fetch ALL notifications (read + unread), include is_read column
                $stmt = $conn->prepare("
                    SELECT id, title, message, is_read,
                           DATE_FORMAT(created_at, '%M %d, %Y at %h:%i %p') AS created_at
                    FROM notifications
                    WHERE user_id = ?
                    ORDER BY created_at DESC
                    LIMIT 50
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();

                $notifications = [];
                $unread_count  = 0;
                while ($row = $result->fetch_assoc()) {
                    $notifications[] = $row;
                    if (!$row['is_read']) $unread_count++;
                }

                $response = [
                    'success'       => true,
                    'notifications' => $notifications,
                    'count'         => $unread_count  // badge = unread only
                ];
                $stmt->close();
            } else {
                $response['error'] = 'Not authenticated';
            }
            break;

        case 'mark_read':
            if (isset($_SESSION['user_id']) && isset($_POST['notification_id'])) {
                $user_id         = $_SESSION['user_id'];
                $notification_id = intval($_POST['notification_id']);
                $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
                $stmt->bind_param("ii", $notification_id, $user_id);
                $response['success'] = $stmt->execute();
                $stmt->close();
            } else {
                $response['error'] = 'Missing parameters';
            }
            break;

        case 'mark_all_read':
            if (isset($_SESSION['user_id'])) {
                $user_id = $_SESSION['user_id'];
                $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);
                $response['success'] = $stmt->execute();
                $stmt->close();
            } else {
                $response['error'] = 'Not authenticated';
            }
            break;

        case 'send_notification':
            if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
                $recipient_type = $_POST['recipient_type'] ?? '';
                $title          = $_POST['title']          ?? '';
                $message        = $_POST['message']        ?? '';
                $specific_user  = $_POST['specific_user']  ?? null;
                $count = 0;

                switch ($recipient_type) {
                    case 'all_trainees':
                        $count = sendNotificationToTrainees($title, $message);
                        break;
                    case 'all_users':
                        $count = sendNotificationToAllUsers($title, $message);
                        break;
                    case 'specific_trainee':
                        if ($specific_user) {
                            $count = sendNotification($specific_user, $title, $message) ? 1 : 0;
                        }
                        break;
                    case 'all_admins':
                        $count = sendNotificationToAdmin($title, $message);
                        break;
                }

                $response = [
                    'success' => $count > 0,
                    'count'   => $count,
                    'message' => "Notification sent to $count recipient(s)"
                ];
            } else {
                $response['error'] = 'Unauthorized';
            }
            break;

        case 'send_enrollment_notification':
            if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
                $trainee_user_id = $_POST['trainee_user_id'] ?? 0;
                $trainee_name    = $_POST['trainee_name']    ?? '';
                $program_name    = $_POST['program_name']    ?? '';
                $status          = $_POST['status']          ?? 'approved';
                $result = sendEnrollmentNotification($trainee_user_id, $trainee_name, $program_name, $status);
                $response = ['success' => $result, 'message' => "Enrollment notification sent"];
            } else {
                $response['error'] = 'Unauthorized';
            }
            break;
    }

    echo json_encode($response);
    exit;
}

// GET endpoint — also returns read + unread
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['load']) && $_GET['load'] === 'notifications') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT id, title, message, is_read,
               DATE_FORMAT(created_at, '%M %d, %Y at %h:%i %p') AS created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $notifications = [];
    $unread_count  = 0;
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
        if (!$row['is_read']) $unread_count++;
    }

    echo json_encode([
        'success'       => true,
        'notifications' => $notifications,
        'count'         => $unread_count
    ]);
    $stmt->close();
    $conn->close();
    exit;
}

// ==========================================
// 3. ADMIN NOTIFICATION SENDING PAGE
// ==========================================

if (isset($_GET['page']) && $_GET['page'] === 'send') {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        header("Location: ../login.php");
        exit();
    }

    $trainees = [];
    $stmt = $conn->prepare("
        SELECT u.id, t.firstname, t.lastname
        FROM users u
        JOIN trainees t ON u.id = t.user_id
        WHERE u.role = 'trainee'
        ORDER BY t.firstname
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $trainees[] = $row;
    $stmt->close();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Send Notification - Admin Panel</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Poppins', sans-serif;
                background-color: #f3f4f6;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .container {
                background: white;
                border-radius: 15px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.1);
                width: 100%;
                max-width: 800px;
                overflow: hidden;
            }
            .header {
                background: linear-gradient(135deg, #1c2a3a, #2b3b4c);
                color: white;
                padding: 30px;
                text-align: center;
            }
            .header h1 {
                font-size: 2rem;
                margin-bottom: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 15px;
            }
            .header p { opacity: 0.8; font-size: 0.9rem; }
            .content { padding: 30px; }
            .alert {
                padding: 15px 20px;
                border-radius: 10px;
                margin-bottom: 25px;
                display: flex;
                align-items: center;
                gap: 10px;
                font-weight: 500;
            }
            .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
            .alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
            .form-group { margin-bottom: 25px; }
            label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #374151;
                font-size: 0.95rem;
            }
            select, input, textarea {
                width: 100%;
                padding: 12px 15px;
                border: 2px solid #e5e7eb;
                border-radius: 10px;
                font-size: 1rem;
                font-family: 'Poppins', sans-serif;
                transition: all 0.3s ease;
                background: white;
            }
            select:focus, input:focus, textarea:focus {
                outline: none;
                border-color: #3b82f6;
                box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
            }
            textarea { min-height: 150px; resize: vertical; line-height: 1.5; }
            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                padding: 14px 30px;
                font-size: 1rem;
                font-weight: 600;
                border: none;
                border-radius: 10px;
                cursor: pointer;
                transition: all 0.3s ease;
                text-decoration: none;
                font-family: 'Poppins', sans-serif;
            }
            .btn-primary { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; }
            .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }
            .btn-secondary { background: #6b7280; color: white; margin-left: 15px; }
            .btn-secondary:hover { background: #4b5563; }
            .quick-templates { margin-top: 30px; padding-top: 25px; border-top: 1px solid #e5e7eb; }
            .quick-templates h3 { margin-bottom: 15px; color: #374151; font-size: 1.1rem; }
            .template-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
            .template-btn {
                background: #f3f4f6;
                border: 1px solid #e5e7eb;
                color: #374151;
                padding: 8px 15px;
                border-radius: 8px;
                cursor: pointer;
                transition: all 0.3s ease;
                font-size: 0.9rem;
            }
            .template-btn:hover { background: #e5e7eb; transform: translateY(-1px); }
            .notification-preview {
                background: #f8fafc;
                border: 2px dashed #d1d5db;
                border-radius: 10px;
                padding: 20px;
                margin-top: 15px;
                display: none;
            }
            .notification-preview.show { display: block; animation: fadeIn 0.3s ease; }
            .preview-title   { font-weight: 600; color: #1f2937; margin-bottom: 10px; font-size: 1.1rem; }
            .preview-message { color: #6b7280; line-height: 1.5; }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(-10px); }
                to   { opacity: 1; transform: translateY(0); }
            }
            @media (max-width: 768px) {
                .header { padding: 20px; }
                .header h1 { font-size: 1.5rem; }
                .content { padding: 20px; }
                .btn { width: 100%; margin-bottom: 10px; }
                .btn-secondary { margin-left: 0; }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1><i class="fas fa-bell"></i> Send Notification</h1>
                <p>Send notifications to users in the system</p>
            </div>
            <div class="content">
                <?php if (isset($_SESSION['notification_sent'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $_SESSION['notification_sent']; unset($_SESSION['notification_sent']); ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['notification_error'])): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $_SESSION['notification_error']; unset($_SESSION['notification_error']); ?>
                    </div>
                <?php endif; ?>

                <form id="notificationForm" method="POST" action="notification.php">
                    <input type="hidden" name="action" value="send_notification">

                    <div class="form-group">
                        <label for="recipient_type"><i class="fas fa-users"></i> Recipient Type</label>
                        <select id="recipient_type" name="recipient_type" required onchange="toggleSpecificUser()">
                            <option value="">Select recipient type</option>
                            <option value="all_trainees">All Trainees</option>
                            <option value="all_admins">All Admins</option>
                            <option value="all_users">All Users</option>
                            <option value="specific_trainee">Specific Trainee</option>
                        </select>
                    </div>

                    <div class="form-group" id="specificUserGroup" style="display:none;">
                        <label for="specific_user"><i class="fas fa-user"></i> Select Trainee</label>
                        <select id="specific_user" name="specific_user">
                            <option value="">Select a trainee</option>
                            <?php foreach ($trainees as $trainee): ?>
                                <option value="<?php echo $trainee['id']; ?>">
                                    <?php echo htmlspecialchars($trainee['firstname'] . ' ' . $trainee['lastname']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="title"><i class="fas fa-heading"></i> Notification Title</label>
                        <input type="text" id="title" name="title" required
                               placeholder="Enter notification title" oninput="updatePreview()">
                    </div>

                    <div class="form-group">
                        <label for="message"><i class="fas fa-comment-alt"></i> Notification Message</label>
                        <textarea id="message" name="message" required
                                  placeholder="Enter notification message here..." oninput="updatePreview()"></textarea>
                    </div>

                    <div id="notificationPreview" class="notification-preview">
                        <div class="preview-title"   id="previewTitle">Title will appear here</div>
                        <div class="preview-message" id="previewMessage">Message will appear here</div>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Send Notification
                        </button>
                        <a href="dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </form>

                <div class="quick-templates">
                    <h3><i class="fas fa-bolt"></i> Quick Templates</h3>
                    <div class="template-buttons">
                        <button type="button" class="template-btn" onclick="useTemplate('welcome')">Welcome Message</button>
                        <button type="button" class="template-btn" onclick="useTemplate('training_reminder')">Training Reminder</button>
                        <button type="button" class="template-btn" onclick="useTemplate('system_update')">System Update</button>
                        <button type="button" class="template-btn" onclick="useTemplate('important_announcement')">Important Announcement</button>
                        <button type="button" class="template-btn" onclick="useTemplate('feedback_request')">Feedback Request</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            function toggleSpecificUser() {
                const recipientType     = document.getElementById('recipient_type').value;
                const specificUserGroup = document.getElementById('specificUserGroup');
                const specificUser      = document.getElementById('specific_user');
                if (recipientType === 'specific_trainee') {
                    specificUserGroup.style.display = 'block';
                    specificUser.required = true;
                } else {
                    specificUserGroup.style.display = 'none';
                    specificUser.required = false;
                }
                updatePreview();
            }

            function updatePreview() {
                const title   = document.getElementById('title').value   || 'Title will appear here';
                const message = document.getElementById('message').value || 'Message will appear here';
                const preview = document.getElementById('notificationPreview');
                document.getElementById('previewTitle').textContent   = title;
                document.getElementById('previewMessage').textContent = message;
                preview.classList.toggle('show', !!(title || message));
            }

            function useTemplate(type) {
                const templates = {
                    welcome:                { title: 'Welcome to the Training Program!',  message: 'We are excited to have you join our training program. Please check your dashboard for your schedule and training materials.' },
                    training_reminder:      { title: 'Training Session Reminder',          message: 'This is a reminder for your training session tomorrow. Please arrive 15 minutes early and bring your training materials.' },
                    system_update:          { title: 'System Maintenance Notice',          message: 'The system will undergo maintenance this Sunday from 2 AM to 6 AM. Please save your work before this time.' },
                    important_announcement: { title: 'Important Announcement',             message: 'All trainees are required to attend the orientation session this Friday at 9 AM in the main hall.' },
                    feedback_request:       { title: 'Feedback Request',                   message: 'Please take a moment to provide feedback on your training experience. Your input helps us improve our programs.' }
                };
                if (templates[type]) {
                    document.getElementById('title').value   = templates[type].title;
                    document.getElementById('message').value = templates[type].message;
                    updatePreview();
                }
            }

            document.addEventListener('DOMContentLoaded', function () {
                toggleSpecificUser();
                updatePreview();
                document.getElementById('notificationForm').addEventListener('submit', function (e) {
                    e.preventDefault();
                    fetch('notification.php', { method: 'POST', body: new FormData(this) })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) { alert('✅ ' + data.message); this.reset(); updatePreview(); }
                            else              { alert('❌ Failed to send notification'); }
                        })
                        .catch(() => alert('❌ An error occurred'));
                });
            });
        </script>
    </body>
    </html>
    <?php
    $conn->close();
    exit;
}

// ==========================================
// 4. DEFAULT — return ALL notifications
//    (read + unread) for the current user
// ==========================================

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("
    SELECT id, title, message, is_read,
           DATE_FORMAT(created_at, '%M %d, %Y at %h:%i %p') AS created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 50
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
$unread_count  = 0;
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
    if (!$row['is_read']) $unread_count++;
}

header('Content-Type: application/json');
echo json_encode([
    'success'       => true,
    'notifications' => $notifications,
    'count'         => $unread_count
]);

$stmt->close();
$conn->close();
?>