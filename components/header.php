<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Handle logout
if (isset($_GET['logout'])) {
    // Unset all session variables
    $_SESSION = array();
    
    // Destroy the session cookie
    if (isset($_COOKIE[session_name()])) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), 
            '', 
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    
    // Destroy the session completely
    session_destroy();
    
    // Start a new clean session for the redirect
    session_start();
    
    // Optional: Set a logout message
    $_SESSION['logout_message'] = 'You have been successfully logged out.';
    
    // Redirect to login page
    header('Location: ../login.php');
    exit();
}

// Check if user is logged in (optional but recommended)
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php'); // Fixed path
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LEMS - Livelihood Enrollment & Monitoring System</title>
  <style>
    /* Reset and base styles */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    :root {
      --primary: #2c3e50;
      --secondary: #3498db;
      --accent: #e74c3c;
      --light: #ecf0f1;
      --dark: #2c3e50;
      --success: #2ecc71;
      --warning: #f39c12;
      --danger: #e74c3c;
      --gray: #95a5a6;
      --active: #0d9488;
      --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    body {
      background-color: #f5f7fa;
      min-height: 100vh;
    }

    /* Header Styles */
    .header {
      display: grid;
      grid-template-columns: 1fr 1fr;  /* Left | Right */
      align-items: center;
      background-color: var(--primary);
      color: white;
      padding: 0.75rem 1.5rem;
      box-shadow: var(--shadow);
      position: sticky;
      top: 0;
      z-index: 1000;
      height: 70px;
    }

    /* Left Section */
    .header-left {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    /* Right Section */
    .header-right {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 1.5rem;
    }
   
    .burger {
      background: none;
      border: none;
      color: white;
      font-size: 1.5rem;
      cursor: pointer;
      display: none;
      padding: 0.5rem;
      border-radius: 4px;
      transition: background-color 0.3s;
      width: 40px;
      height: 40px;
    }

    .burger:hover {
      background-color: rgba(255, 255, 255, 0.1);
    }

    .logo {
      height: 40px;
      width: auto;
      border-radius: 4px;
      object-fit: contain;
    }

    .title {
      font-size: 1.25rem;
      font-weight: 600;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      position: relative;
    }

    /* Hide the short title by default */
    .short-title {
      display: none;
    }

    /* Show full title by default */
    .full-title {
      display: inline;
    }

    .notification {
      background: none;
      border: none;
      color: white;
      font-size: 1.25rem;
      cursor: pointer;
      padding: 0.5rem;
      border-radius: 50%;
      transition: background-color 0.3s;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      width: 40px;
      height: 40px;
    }

    .notification:hover {
      background-color: rgba(255, 255, 255, 0.1);
    }

    .notification-badge {
      position: absolute;
      top: 2px;
      right: 2px;
      background-color: var(--danger);
      color: white;
      border-radius: 50%;
      width: 18px;
      height: 18px;
      font-size: 0.7rem;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: bold;
      border: 2px solid var(--primary);
    }

    #profileBtn {
      background: none;
      border: none;
      color: white;
      cursor: pointer;
      padding: 0.5rem 0.75rem;
      border-radius: 8px;
      transition: background-color 0.3s;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      font-size: 0.95rem;
      height: 50px;
    }

    #profileBtn:hover {
      background-color: rgba(255, 255, 255, 0.1);
    }

    .user-icon {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: white;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow: hidden;
      flex-shrink: 0;
    }

    .user-icon::before {
      content: '';
      position: absolute;
      width: 14px;
      height: 14px;
      background: var(--primary);
      border-radius: 50%;
      top: 6px;
    }

    .user-icon::after {
      content: '';
      position: absolute;
      width: 28px;
      height: 28px;
      background: var(--primary);
      border-radius: 50%;
      bottom: -10px;
    }

    .user-info {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      text-align: left;
    }

    .user-name {
      font-weight: 600;
      font-size: 0.9rem;
      white-space: nowrap;
    }

    .user-role {
      font-size: 0.75rem;
      opacity: 0.8;
      white-space: nowrap;
    }

    .profile-dropdown {
      position: relative;
    }

    .dropdown-content {
      display: none;
      position: absolute;
      right: 0;
      top: calc(100% + 5px);
      background-color: white;
      min-width: 200px;
      box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
      border-radius: 8px;
      overflow: hidden;
      z-index: 1001;
    }

    .dropdown-content.show {
      display: block;
    }

    .dropdown-content a {
      color: var(--dark);
      padding: 0.75rem 1rem;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      transition: background-color 0.2s;
      border-bottom: 1px solid #f0f0f0;
      font-size: 0.9rem;
    }

    .dropdown-content a:last-child {
      border-bottom: none;
    }

    .dropdown-content a:hover {
      background-color: var(--light);
    }

    .dropdown-content a.logout-btn {
      color: var(--danger);
      font-weight: 600;
    }

    .dropdown-content a.logout-btn:hover {
      background-color: #fee;
    }

    /* Sidebar Styles */
    .sidebar {
      position: fixed;
      left: 0;
      top: 70px;
      height: calc(100vh - 70px);
      width: 250px;
      background-color: var(--primary);
      box-shadow: var(--shadow);
      padding-top: 1rem;
      transform: translateX(0);
      transition: transform 0.3s ease;
      z-index: 999;
      overflow-y: auto;
    }

    .sidebar a {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 1rem 1.5rem;
      color: white;
      text-decoration: none;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      transition: all 0.2s;
      font-size: 0.95rem;
    }

    .sidebar a:hover {
      background-color: rgba(255, 255, 255, 0.1);
      padding-left: 2rem;
    }

    .sidebar a.active {
      background-color: var(--active);
      border-left: 4px solid var(--light);
    }

    .submenu {
      background-color: rgba(0, 0, 0, 0.1);
    }

    .submenu a {
      padding: 0.75rem 1.5rem 0.75rem 2.5rem;
      font-size: 0.9rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .submenu a:hover {
      background-color: rgba(255, 255, 255, 0.05);
      padding-left: 3rem;
    }

    .submenu a.active {
      background-color: rgba(52, 152, 219, 0.3);
    }

    /* Main Content */
    .main-content {
      margin-left: 250px;
      padding: 2rem;
      min-height: calc(100vh - 70px);
      transition: margin-left 0.3s ease;
    }

    .card {
      background: white;
      border-radius: 8px;
      box-shadow: var(--shadow);
      padding: 1.5rem;
      margin-bottom: 1.5rem;
    }

    .card h2 {
      color: var(--primary);
      margin-bottom: 1rem;
      padding-bottom: 0.5rem;
      border-bottom: 2px solid var(--secondary);
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .burger {
        display: flex;
        align-items: center;
        justify-content: center;
      }

      .sidebar {
        transform: translateX(-100%);
        top: 70px;
        height: calc(100vh - 70px);
      }

      .sidebar.active {
        transform: translateX(0);
      }

      .main-content {
        margin-left: 0;
        padding: 1rem;
      }

      .header {
        padding: 0.75rem 1rem;
      }

      .title {
        font-size: 1rem;
        max-width: none; /* Remove the max-width constraint */
      }

      /* Hide full title on mobile */
      .full-title {
        display: none;
      }

      /* Show short title on mobile */
      .short-title {
        display: inline;
        font-size: 1.2rem;
        font-weight: 600;
      }

      .user-info {
        display: none;
      }

      .header-right {
        gap: 0.75rem;
      }
    }

    @media (max-width: 480px) {
      .title {
        font-size: 0.9rem;
      }
      
      .short-title {
        font-size: 1rem;
      }

      .header {
        padding: 0.5rem;
      }

      .main-content {
        padding: 0.75rem;
      }

      .logo {
        height: 35px;
      }

      .header-right {
        gap: 0.5rem;
      }
    }

    /* Overlay for mobile */
    .overlay {
      position: fixed;
      top: 70px;
      left: 0;
      width: 100%;
      height: calc(100% - 70px);
      background-color: rgba(0, 0, 0, 0.5);
      z-index: 998;
      display: none;
    }

    .overlay.active {
      display: block;
    }

    /* Logout Confirmation Modal */
    .modal {
      display: none;
      position: fixed;
      z-index: 1002;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
      align-items: center;
      justify-content: center;
    }

    .modal.active {
      display: flex;
    }

    .modal-content {
      background-color: white;
      padding: 2rem;
      border-radius: 8px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
      max-width: 400px;
      width: 90%;
      text-align: center;
    }

    .modal-content h3 {
      color: var(--primary);
      margin-bottom: 1rem;
    }

    .modal-content p {
      margin-bottom: 1.5rem;
      color: #666;
      line-height: 1.5;
    }

    .modal-buttons {
      display: flex;
      gap: 1rem;
      justify-content: center;
    }

    .modal-btn {
      padding: 0.75rem 1.5rem;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 1rem;
      transition: all 0.3s;
      min-width: 100px;
      font-weight: 500;
    }

    .btn-cancel {
      background-color: var(--gray);
      color: white;
    }

    .btn-cancel:hover {
      background-color: #7f8c8d;
      transform: translateY(-1px);
    }

    .btn-confirm {
      background-color: var(--danger);
      color: white;
    }

    .btn-confirm:hover {
      background-color: #c0392b;
      transform: translateY(-1px);
    }
  </style>
</head>
<body>
  <header class="header">
    <div class="header-left">
      <!-- Burger Icon for Mobile -->
      <button class="burger" id="burger" aria-label="Toggle menu">&#9776;</button>

      <!-- Logo -->
      <img src="../css/logo2.jpg" alt="LEMS Logo" class="logo">

      <!-- Title - Full for desktop, LEMS for mobile -->
      <h1 class="title">
        <span class="full-title">Livelihood Enrollment & Monitoring System</span>
        <span class="short-title">LEMS</span>
      </h1>
    </div>

    <div class="header-right">
      
      <!-- Profile Dropdown -->
      <div class="profile-dropdown">
        <button id="profileBtn">
          <div class="user-icon"></div>
          <span>▼</span>
        </button>
        <div id="profileMenu" class="dropdown-content">
          <a href="profile.php">👤 View Profile</a>
          <a href="#" id="logoutLink" class="logout-btn">🚪 Logout</a>
        </div>
      </div>
    </div>
  </header>

  <!-- Logout Confirmation Modal -->
  <div id="logoutModal" class="modal">
    <div class="modal-content">
      <h3>Confirm Logout</h3>
      <p>Are you sure you want to logout from the system?</p>
      <div class="modal-buttons">
        <button class="modal-btn btn-cancel" id="cancelLogout">Cancel</button>
        <button class="modal-btn btn-confirm" id="confirmLogout">Logout</button>
      </div>
    </div>
  </div>

  <!-- Overlay for mobile menu -->
  <div class="overlay" id="overlay"></div>

  <!-- Sidebar Menu -->
  <nav id="sidebar" class="sidebar" aria-label="Main navigation">
    <?php
    // Get current page for active state
    $current_page = basename($_SERVER['PHP_SELF']);
    ?>
    <a href="dashboard.php" class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>">📊 Admin Dashboard</a>
    <a href="user-management.php" class="<?= $current_page === 'user-management.php' ? 'active' : '' ?>">👥 User Management</a>
    <a href="program-management.php" class="<?= $current_page === 'program-management.php' ? 'active' : '' ?>">📋 Program Management</a>
    <a href="enrollment-management.php" class="<?= $current_page === 'enrollment-management.php' ? 'active' : '' ?>">📝 Enrollment Management</a>
    <a href="reports_monitoring.php" class="<?= $current_page === 'reports_monitoring.php' ? 'active' : '' ?>">📈 Reports & Monitoring</a>
  </nav>

  <!-- Main Content STARTS HERE - Pages add their content after this -->
  <main class="main-content">

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const burger = document.getElementById('burger');
      const sidebar = document.getElementById('sidebar');
      const overlay = document.getElementById('overlay');
      const logoutLink = document.getElementById('logoutLink');
      const logoutModal = document.getElementById('logoutModal');
      const cancelLogout = document.getElementById('cancelLogout');
      const confirmLogout = document.getElementById('confirmLogout');
      const profileBtn = document.getElementById('profileBtn');
      const profileMenu = document.getElementById('profileMenu');
      
      // Toggle sidebar on burger click
      burger.addEventListener('click', function(e) {
        e.stopPropagation();
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
      });
      
      // Close sidebar when clicking on overlay
      overlay.addEventListener('click', function() {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
      });
      
      // Close sidebar when clicking on a link (mobile)
      const sidebarLinks = document.querySelectorAll('.sidebar a');
      sidebarLinks.forEach(link => {
        link.addEventListener('click', function() {
          if (window.innerWidth <= 768) {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
          }
        });
      });
      
      // Handle window resize
      window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
          sidebar.classList.remove('active');
          overlay.classList.remove('active');
          profileMenu.classList.remove('show');
        }
      });

      // Toggle profile dropdown
      profileBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        profileMenu.classList.toggle('show');
      });

      // Close dropdown when clicking outside
      document.addEventListener('click', function(e) {
        if (!profileBtn.contains(e.target) && !profileMenu.contains(e.target)) {
          profileMenu.classList.remove('show');
        }
      });

      // Logout confirmation modal
      logoutLink.addEventListener('click', function(e) {
        e.preventDefault();
        profileMenu.classList.remove('show');
        logoutModal.classList.add('active');
      });

      cancelLogout.addEventListener('click', function() {
        logoutModal.classList.remove('active');
      });

      confirmLogout.addEventListener('click', function() {
        window.location.href = '?logout=1';
      });

      // Close modal when clicking outside
      logoutModal.addEventListener('click', function(e) {
        if (e.target === logoutModal) {
          logoutModal.classList.remove('active');
        }
      });

      // Close modal with Escape key
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
          logoutModal.classList.remove('active');
        }
      });
    });
  </script>
  
  <!-- NOTE: Page content goes here, and pages must close with </main></body></html> -->