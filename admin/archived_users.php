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

// Handle Restore User
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['restore_user'])){
    $user_id = intval($_POST['user_id']);
    
    // First, get the archived user data
    $archived_user = $conn->query("SELECT * FROM archived_users WHERE id = $user_id");
    
    if($archived_user && $archived_user->num_rows > 0){
        $user = $archived_user->fetch_assoc();
        
        // Insert back into users table
        $restore_stmt = $conn->prepare("INSERT INTO users (id, fullname, email, password, role, program, specialization, other_programs, date_created, status, reset_token, reset_expires) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $restore_stmt->bind_param("isssssssssss", 
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
        
        if($restore_stmt->execute()){
            // Delete from archived_users after successful restore
            $delete_stmt = $conn->prepare("DELETE FROM archived_users WHERE id = ?");
            $delete_stmt->bind_param("i", $user_id);
            $delete_stmt->execute();
            $delete_stmt->close();
            
            $_SESSION['flash'] = 'User restored successfully!';
        } else {
            $_SESSION['flash'] = 'Error restoring user: ' . $conn->error;
        }
        $restore_stmt->close();
    } else {
        $_SESSION['flash'] = 'Archived user not found!';
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Handle Permanently Delete User
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_user'])){
    $user_id = intval($_POST['user_id']);
    
    $stmt = $conn->prepare("DELETE FROM archived_users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    
    if($stmt->execute()){
        $_SESSION['flash'] = 'User permanently deleted!';
    } else {
        $_SESSION['flash'] = 'Error deleting user: ' . $conn->error;
    }
    $stmt->close();
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Process GET filters
$role = $_GET['role'] ?? 'all';
$search = trim($_GET['search'] ?? '');

// Initialize variables
$archived_users = [];
$total_count = 0;

try {
    // Query to get archived users
    $sql = "SELECT id, fullname, email, role, program, specialization, other_programs, date_created, status, archived_at FROM archived_users WHERE 1=1";
    $count_sql = "SELECT COUNT(*) as total FROM archived_users WHERE 1=1";
    
    $params = [];
    $types = '';

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
        $sql .= " AND (fullname LIKE ? OR email LIKE ? OR program LIKE ? OR other_programs LIKE ? OR specialization LIKE ?)";
        $count_sql .= " AND (fullname LIKE ? OR email LIKE ? OR program LIKE ? OR other_programs LIKE ? OR specialization LIKE ?)";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
        $types .= str_repeat('s', 5);
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
  .badge-archived {
      background: #f3f4f6;
      color: #6b7280;
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
          gap: 16px;
          align-items: flex-start;
      }
      .table-responsive {
          font-size: 14px;
      }
      .table th,
      .table td {
          padding: 8px 12px;
      }
  }
</style>

<div class="page-header">
  <h1>Archived Users</h1>
  <div style="display: flex; gap: 12px;">
    <a class="btn btn-blue" href="user-management.php">← Back to User Management</a>
  </div>
</div>

<?php if($flash): ?>
  <div class="notice <?= strpos($flash, 'Error') !== false ? 'error' : '' ?>">
    <?= htmlspecialchars($flash) ?>
  </div>
<?php endif; ?>

<div class="warning-banner">
  <strong>⚠️ Archived Users</strong>
  <p>These users have been archived and are no longer active in the system. You can restore them or permanently delete them.</p>
</div>

<div class="card">
  <form method="get" class="filter-form">
    <select name="role" class="role-select">
      <option value="all" <?= $role === 'all' ? 'selected' : '' ?>>All Roles</option>
      <option value="trainer" <?= $role === 'trainer' ? 'selected' : '' ?>>Trainers</option>
      <option value="trainee" <?= $role === 'trainee' ? 'selected' : '' ?>>Trainees</option>
    </select>

    <input class="search-input" type="text" name="search" placeholder="Search by name, email, program..." value="<?= htmlspecialchars($search) ?>">
    
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
            $title = 'Archived ' . ucfirst($role) . 's';
        }
        if (!empty($search)) {
            $title .= " matching '" . htmlspecialchars($search) . "'";
        }
        echo htmlspecialchars($title);
      ?> 
      <small class="count-small">(<?= count($archived_users) ?> out of <?= $total_count ?> total archived users)</small>
    </h3>

    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>User</th>
            <th>Date Archived</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(count($archived_users) === 0): ?>
            <tr>
              <td colspan="6" class="empty">
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
                      <div class="user-program">
                        <?= !empty($u['program']) ? htmlspecialchars($u['program']) : 'No program' ?>
                        <?= !empty($u['other_programs']) ? ' + ' . htmlspecialchars($u['other_programs']) : '' ?>
                        <?= !empty($u['specialization']) ? ' (' . htmlspecialchars($u['specialization']) . ')' : '' ?>
                      </div>
                    </div>
                  </div>
                </td>
                <td class="date-cell">
                  <?= date('M j, Y g:i A', strtotime($u['archived_at'])) ?>
                  <div class="archived-date">Archived</div>
                </td>
                <td class="email-cell"><?= htmlspecialchars($u['email']) ?></td>
                <td>
                  <span class="badge <?= $u['role'] === 'trainer' ? 'badge-trainer' : 'badge-trainee' ?>">
                    <?= htmlspecialchars(ucfirst($u['role'])) ?>
                  </span>
                </td>
                <td>
                  <span class="status-archived">
                    Archived
                  </span>
                </td>
                <td class="actions">
                  <form class="inline-form" method="POST" action="" onsubmit="return confirm('Are you sure you want to restore this user? They will be moved back to active users.');">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="restore_user" value="1">
                    <button class="btn btn-green" type="submit">Restore</button>
                  </form>
                  <form class="inline-form" method="POST" action="" onsubmit="return confirm('⚠️ WARNING: This will permanently delete this user and all their data. This action cannot be undone! Are you sure?');">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="delete_user" value="1">
                    <button class="btn btn-red" type="submit">Delete Permanently</button>
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

</body>
</html>