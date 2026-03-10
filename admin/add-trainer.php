<?php
// add-trainer.php
// Database connection
include __DIR__ . '/../db.php';

if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_trainer'])){
    // Fixed: Use consistent column name 'fullname' instead of 'full_name'
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $email = $conn->real_escape_string($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
    $role = 'trainer'; // Hardcode this for security
    $program = !empty($_POST['program']) ? intval($_POST['program']) : null;
    $allow_multiple_programs = isset($_POST['allow_multiple_programs']) ? 1 : 0;

    // Check duplicate
    $check = $conn->query("SELECT * FROM users WHERE email='$email'");
    if($check->num_rows > 0){
        $_SESSION['flash'] = 'Email already exists!';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }

    // Fixed: Use correct column name and prepare statement for all values
    $stmt = $conn->prepare("INSERT INTO users (fullname, email, password, role, program, other_programs) VALUES (?, ?, ?, ?, ?, ?)");
    $other_programs = $allow_multiple_programs ? '' : null;
    $stmt->bind_param("ssssss", $full_name, $email, $password, $role, $program, $other_programs);
    
    if($stmt->execute()){
        $trainer_id = $stmt->insert_id;
        
        if($program){
            // Fixed: Use prepared statement to prevent SQL injection
            $update_stmt = $conn->prepare("UPDATE programs SET trainer_id = ? WHERE id = ?");
            $update_stmt->bind_param("ii", $trainer_id, $program);
            $update_stmt->execute();
            $update_stmt->close();
        }
        
        $_SESSION['flash'] = 'Trainer added successfully!';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    } else {
        $_SESSION['flash'] = 'Error adding trainer: ' . $conn->error;
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    $stmt->close();
    exit;
}

// Fetch programs for the dropdown
$programs = [];
$res = $conn->query("SELECT id, name, trainer_id FROM programs ORDER BY name");
if($res) {
    $programs = $res->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Trainer Modal</title>
<style>
/* Modal backdrop */
.modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(5px);
    justify-content: center;
    align-items: center;
    z-index: 10000;
}

/* Modal content */
.modal-content {
    background: #fff;
    border-radius: 12px;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    padding: 20px;
    animation: modal-appear 0.3s ease-out;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
}

@keyframes modal-appear {
    from { opacity: 0; transform: scale(0.9) translateY(-10px);}
    to { opacity: 1; transform: scale(1) translateY(0);}
}

.form-group {
    margin-bottom: 15px;
}

label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}

input, select, button {
    width: 100%;
    padding: 10px;
    margin: 5px 0;
    border-radius: 6px;
    border: 1px solid #ccc;
    box-sizing: border-box;
}

button {
    background-color: #0d9488;
    color: white;
    cursor: pointer;
    font-weight: bold;
    border: none;
    transition: background-color 0.3s;
}

button:hover {
    background-color: #0f766e;
}

.checkbox-container {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 15px 0;
}

.checkbox-container input[type="checkbox"] {
    width: auto;
    margin: 0;
}

.close-btn {
    float: right;
    font-size: 24px;
    font-weight: bold;
    cursor: pointer;
    background: none;
    border: none;
    width: auto;
    color: #333;
    padding: 0;
    margin: 0;
}

.close-btn:hover {
    color: #000;
}

.password-container {
    display: flex;
    gap: 10px;
}

.password-container input {
    flex: 1;
}

.password-container button {
    width: auto;
    padding: 10px 15px;
    white-space: nowrap;
}
</style>
</head>
<body>

<!-- Modal Structure -->
<div class="modal-backdrop" id="trainerModalBackdrop">
    <div class="modal-content">
        <span class="close-btn" id="closeTrainerModal">&times;</span>
        <h2>Add Trainer</h2>
        <form id="trainerForm" method="POST" action="">
            <div class="form-group">
                <label for="full_name">Full Name *</label>
                <input type="text" id="full_name" name="full_name" required placeholder="Enter trainer's full name">
            </div>
            
            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" required placeholder="Enter trainer's email">
            </div>
            
            <div class="form-group">
                <label for="password">Password *</label>
                <div class="password-container">
                    <input type="text" name="password" id="password" readonly required>
                    <button type="button" onclick="generatePassword()">Regenerate</button>
                </div>
            </div>
            
            <div class="form-group">
                <label for="program">Program (Optional)</label>
                <select name="program" id="program">
                    <option value="">Select Program</option>
                    <?php foreach($programs as $prog): ?>
                        <?php 
                            $disabled = $prog['trainer_id'] ? 'disabled' : '';
                            $label = $prog['trainer_id'] ? '(Assigned)' : '(Available)';
                        ?>
                        <option value="<?= (int)$prog['id'] ?>" <?= $disabled ?>>
                            <?= htmlspecialchars($prog['name']) ?> <?= $label ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="checkbox-container">
                <input type="checkbox" name="allow_multiple_programs" value="1" checked id="allow_multiple">
                <label for="allow_multiple" style="margin: 0;">Allow multiple programs</label>
            </div>

            <input type="hidden" name="add_trainer" value="1">

            <button type="submit">Add Trainer</button>
        </form>
    </div>
</div>

<script>
// Global functions to control the modal
function openTrainerModal() {
    document.getElementById('trainerModalBackdrop').style.display = 'flex';
    generatePassword(); // Generate password when modal opens
}

function closeTrainerModal() {
    document.getElementById('trainerModalBackdrop').style.display = 'none';
    // Reset form
    document.getElementById('trainerForm').reset();
    generatePassword();
}

// Modal event listeners
document.getElementById('closeTrainerModal').onclick = closeTrainerModal;

// Close modal when clicking outside
document.getElementById('trainerModalBackdrop').onclick = function(e) {
    if (e.target === this) {
        closeTrainerModal();
    }
};

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeTrainerModal();
    }
});

// Password generator
function generatePassword(length=12){
    const upper = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    const lower = "abcdefghijklmnopqrstuvwxyz";
    const nums = "0123456789";
    const syms = "@#$!";
    let pw = upper[Math.floor(Math.random()*upper.length)]
           + lower[Math.floor(Math.random()*lower.length)]
           + nums[Math.floor(Math.random()*nums.length)]
           + syms[Math.floor(Math.random()*syms.length)];
    const all = upper+lower+nums+syms;
    for(let i=4;i<length;i++){
        pw += all[Math.floor(Math.random()*all.length)];
    }
    pw = pw.split('').sort(()=>Math.random()-0.5).join('');
    document.getElementById('password').value = pw;
}

// Generate initial password on load
if(document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        generatePassword();
    });
} else {
    generatePassword();
}

// Form validation
document.getElementById('trainerForm').addEventListener('submit', function(e) {
    const fullName = document.getElementById('full_name').value.trim();
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    
    if (!fullName) {
        alert('Please enter a full name');
        e.preventDefault();
        return;
    }
    
    if (!email) {
        alert('Please enter an email');
        e.preventDefault();
        return;
    }
    
    if (!password) {
        alert('Please generate a password');
        e.preventDefault();
        return;
    }
});

// Make functions globally available
window.openTrainerModal = openTrainerModal;
window.closeTrainerModal = closeTrainerModal;
window.generatePassword = generatePassword;
</script>

</body>
</html>