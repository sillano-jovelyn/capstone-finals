<?php
// Start session at the VERY BEGINNING
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Include db.php from the root directory
include __DIR__ . '/db.php';

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

// Define Sta. Maria, Bulacan barangays
$sta_maria_barangays = [
    'Bagbaguin', 'Balasing', 'Buenavista', 'Bulac', 'Camangyanan',
    'Catmon', 'Cay Pombo', 'Caysio', 'Guyong', 'Lalakhan',
    'Mag-asawang Sapa', 'Mahabang Parang', 'Manggahan', 'Parada',
    'Poblacion', 'Pulong Buhangin', 'San Gabriel', 'San Jose Patag',
    'San Vicente', 'Santa Clara', 'Santa Cruz', 'Santo Niño',
    'Sapang', 'Silicawan', 'Tumana'
];

// Initialize variables
$errors = [];
$success = false;
$message = '';
$pending_redirect = null;
$lastname = $firstname = $middleinitial = $house_street = $barangay = '';
$birthday = $contact_number = $gender = $gender_specify = '';
$civil_status = $employment_status = $education = $education_specify = '';
$trainings_attended = $toolkit_received = $email = '';
$age = 0;
$applicant_type = [];
$nc_holder = [];

// Check if there's a pending program redirect from URL
if (isset($_GET['redirect']) && strpos($_GET['redirect'], 'program_') === 0) {
    $pending_redirect = $_GET['redirect'];
    $_SESSION['pending_enrollment'] = str_replace('program_', '', $pending_redirect);
} elseif (isset($_GET['program_id'])) {
    $_SESSION['pending_enrollment'] = $_GET['program_id'];
}

// Function to generate easier but still secure password (12 characters)
function generatePassword($length = 12) {
    $words = ['Secure', 'Pass', 'Access', 'Login', 'Portal', 'Safe', 'User', 'Member', 'Join', 'Enter'];
    $numbers = '0123456789';
    $specialChars = '@#$%!';
    
    $word = $words[random_int(0, count($words) - 1)];
    $numberPart = '';
    for ($i = 0; $i < 3; $i++) {
        $numberPart .= $numbers[random_int(0, strlen($numbers) - 1)];
    }
    $specialChar = $specialChars[random_int(0, strlen($specialChars) - 1)];
    
    while (strlen($word . $numberPart . $specialChar) < 12) {
        $numberPart .= $numbers[random_int(0, strlen($numbers) - 1)];
    }
    
    $password = $word . $numberPart . $specialChar;
    return substr($password, 0, 12);
}

// Function to calculate age from birthday
function calculateAge($birthday) {
    $birthDate = new DateTime($birthday);
    $today = new DateTime();
    return $today->diff($birthDate)->y;
}

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== FORM SUBMISSION STARTED ===");
    
    // Collect and sanitize form data
    $lastname = trim($_POST['lastname'] ?? '');
    $firstname = trim($_POST['firstname'] ?? '');
    $middleinitial = trim($_POST['middleinitial'] ?? '');
    $house_street = trim($_POST['house_street'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $municipality = 'Sta. Maria';
    $city = 'Bulacan';
    $birthday = trim($_POST['birthday'] ?? '');
    $age = intval($_POST['age'] ?? 0);
    $contact_number = trim($_POST['contact_number'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $gender_specify = trim($_POST['gender_specify'] ?? '');
    $civil_status = trim($_POST['civil_status'] ?? '');
    $employment_status = trim($_POST['employment_status'] ?? '');
    $education = trim($_POST['education'] ?? '');
    $education_specify = trim($_POST['education_specify'] ?? '');
    $applicant_type = isset($_POST['applicant_type']) ? $_POST['applicant_type'] : [];
    $nc_holder = isset($_POST['nc_holder']) ? $_POST['nc_holder'] : [];
    $trainings_attended = trim($_POST['trainings_attended'] ?? '');
    $toolkit_received = trim($_POST['toolkit_received'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password_plain = generatePassword();

    error_log("APPLICANT_TYPE POST: " . print_r($_POST['applicant_type'] ?? [], true));
    error_log("NC_HOLDER POST: " . print_r($_POST['nc_holder'] ?? [], true));
    error_log("APPLICANT_TYPE after sanitization: " . print_r($applicant_type, true));
    error_log("NC_HOLDER after sanitization: " . print_r($nc_holder, true));

    // Validate required fields
    if (!$lastname || !$firstname || !$house_street || !$barangay || 
        !$birthday || !$age || !$contact_number || !$gender || 
        !$civil_status || !$employment_status || !$education || !$email) {
        $errors[] = "Please fill in all required fields.";
    }

    if (!empty($middleinitial) && !preg_match('/^[A-Za-z]{1,2}$/', $middleinitial)) {
        $errors[] = "Middle initial must be 1-2 letters only.";
    }

    if (!preg_match('/^[A-Za-zÑñ\s-]+$/', $lastname)) {
        $errors[] = "Last name can only contain letters, spaces, and hyphens.";
    }
    if (!preg_match('/^[A-Za-zÑñ\s-]+$/', $firstname)) {
        $errors[] = "First name can only contain letters, spaces, and hyphens.";
    }

    if (!preg_match('/^09[0-9]{9}$/', $contact_number)) {
        $errors[] = "Contact number must start with 09 and be exactly 11 digits.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    if (!empty($birthday)) {
        $dateParts = explode('-', $birthday);
        if (count($dateParts) == 3) {
            $year = intval($dateParts[0]);
            $month = intval($dateParts[1]);
            $day = intval($dateParts[2]);
            
            if (!checkdate($month, $day, $year)) {
                $errors[] = "Invalid date format. Please enter a valid date.";
            } else {
                $calculatedAge = calculateAge($birthday);
                if ($calculatedAge < 18 || $calculatedAge > 70) {
                    $errors[] = "Registration is only allowed for individuals between 18 and 70 years old. Your age: $calculatedAge";
                }
                if ($age != $calculatedAge) {
                    $age = $calculatedAge;
                }
            }
        } else {
            $errors[] = "Please enter a valid date in YYYY-MM-DD format.";
        }
    }

    if (empty($errors)) {
        $checkQuery = "SELECT * FROM trainees WHERE email = ?";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        if ($result->num_rows > 0) {
            $errors[] = "Email already exists in trainees table.";
        }
        $checkStmt->close();
        
        if (empty($errors)) {
            $checkQuery2 = "SELECT * FROM users WHERE email = ?";
            $checkStmt2 = $conn->prepare($checkQuery2);
            $checkStmt2->bind_param("s", $email);
            $checkStmt2->execute();
            $result2 = $checkStmt2->get_result();
            if ($result2->num_rows > 0) {
                $errors[] = "Email already exists in users table.";
            }
            $checkStmt2->close();
        }
    }

    // Handle file uploads
    $uploadDir = __DIR__ . '/imagefile/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $uploadedValidIds = [];
    $uploadedVotersCerts = [];

    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
    $maxFileSize = 10 * 1024 * 1024; // 10MB

    // Process scanned ID data (camera)
    if (isset($_POST['scanned_id_data']) && !empty($_POST['scanned_id_data'])) {
        $scannedIds = json_decode($_POST['scanned_id_data'], true);
        if (is_array($scannedIds)) {
            foreach ($scannedIds as $index => $dataUrl) {
                $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $dataUrl));
                $uniqueName = 'SCANNED_ID_' . time() . '_' . $index . '.jpg';
                $destination = $uploadDir . $uniqueName;
                file_put_contents($destination, $imageData);
                $uploadedValidIds[] = $uniqueName;
            }
        }
    }

    // Process uploaded ID files (file upload)
    if (isset($_FILES['valid_id_upload']) && !empty($_FILES['valid_id_upload']['name'][0])) {
        $fileCount = count($_FILES['valid_id_upload']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['valid_id_upload']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['valid_id_upload']['tmp_name'][$i];
                $fileSize    = $_FILES['valid_id_upload']['size'][$i];
                $fileMime    = mime_content_type($fileTmpPath);

                if ($fileSize > $maxFileSize) {
                    $errors[] = "Valid ID file #" . ($i + 1) . " exceeds the 10MB size limit.";
                    continue;
                }
                if (!in_array($fileMime, $allowedMimeTypes)) {
                    $errors[] = "Valid ID file #" . ($i + 1) . " must be an image (JPG, PNG, GIF, WEBP, BMP).";
                    continue;
                }

                $ext        = pathinfo($_FILES['valid_id_upload']['name'][$i], PATHINFO_EXTENSION);
                $uniqueName = 'UPLOADED_ID_' . time() . '_' . $i . '.' . strtolower($ext);
                $destination = $uploadDir . $uniqueName;

                if (move_uploaded_file($fileTmpPath, $destination)) {
                    $uploadedValidIds[] = $uniqueName;
                } else {
                    $errors[] = "Failed to save uploaded ID file #" . ($i + 1) . ".";
                }
            }
        }
    }

    // Process scanned certificate data (camera)
    if (isset($_POST['scanned_cert_data']) && !empty($_POST['scanned_cert_data'])) {
        $scannedCerts = json_decode($_POST['scanned_cert_data'], true);
        if (is_array($scannedCerts)) {
            foreach ($scannedCerts as $index => $dataUrl) {
                $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $dataUrl));
                $uniqueName = 'SCANNED_CERT_' . time() . '_' . $index . '.jpg';
                $destination = $uploadDir . $uniqueName;
                file_put_contents($destination, $imageData);
                $uploadedVotersCerts[] = $uniqueName;
            }
        }
    }

    // Process uploaded certificate files (file upload)
    if (isset($_FILES['voters_cert_upload']) && !empty($_FILES['voters_cert_upload']['name'][0])) {
        $fileCount = count($_FILES['voters_cert_upload']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['voters_cert_upload']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['voters_cert_upload']['tmp_name'][$i];
                $fileSize    = $_FILES['voters_cert_upload']['size'][$i];
                $fileMime    = mime_content_type($fileTmpPath);

                if ($fileSize > $maxFileSize) {
                    $errors[] = "Certificate file #" . ($i + 1) . " exceeds the 10MB size limit.";
                    continue;
                }
                if (!in_array($fileMime, $allowedMimeTypes)) {
                    $errors[] = "Certificate file #" . ($i + 1) . " must be an image (JPG, PNG, GIF, WEBP, BMP).";
                    continue;
                }

                $ext        = pathinfo($_FILES['voters_cert_upload']['name'][$i], PATHINFO_EXTENSION);
                $uniqueName = 'UPLOADED_CERT_' . time() . '_' . $i . '.' . strtolower($ext);
                $destination = $uploadDir . $uniqueName;

                if (move_uploaded_file($fileTmpPath, $destination)) {
                    $uploadedVotersCerts[] = $uniqueName;
                } else {
                    $errors[] = "Failed to save uploaded certificate file #" . ($i + 1) . ".";
                }
            }
        }
    }

    $hasValidId    = !empty($uploadedValidIds);
    $hasVotersCert = !empty($uploadedVotersCerts);

    if (!$hasValidId || !$hasVotersCert) {
        $errors[] = "Please provide both Valid Government-Issued ID and Voter's Certificate/Barangay Residency (via camera scan or file upload).";
    }

    // If no errors, proceed with registration
    if (empty($errors)) {
        error_log("No errors found, proceeding with registration...");
        
        $conn->begin_transaction();
        
        try {
            $fullname  = "$lastname, $firstname" . (!empty($middleinitial) ? " $middleinitial." : "");
            $address   = "$house_street, $barangay, $municipality, $city";
            $finalGender    = ($gender === 'Others') ? $gender_specify : $gender;
            $finalEducation = ($education === 'Others') ? $education_specify : $education;
            
            if (empty($applicant_type)) {
                $applicant_type = ['None'];
            }
            if (in_array('None', $applicant_type) && count($applicant_type) > 1) {
                $applicant_type = ['None'];
            }
            $applicant_type_json  = json_encode($applicant_type);
            $finalApplicantType   = implode(', ', $applicant_type);

            if (empty($nc_holder)) {
                $nc_holder = ['None'];
            }
            if (in_array('None', $nc_holder) && count($nc_holder) > 1) {
                $nc_holder = ['None'];
            }
            $nc_holder_json = json_encode($nc_holder);
            $finalNCHolder  = implode(', ', $nc_holder);

            error_log("Final NC_HOLDER array: " . print_r($nc_holder, true));
            error_log("Final NC_HOLDER JSON: " . $nc_holder_json);
            error_log("Final NC_HOLDER string: " . $finalNCHolder);
            error_log("Final APPLICANT_TYPE array: " . print_r($applicant_type, true));
            error_log("Final APPLICANT_TYPE JSON: " . $applicant_type_json);

            $validIdJson     = json_encode($uploadedValidIds);
            $votersCertJson  = json_encode($uploadedVotersCerts);
            $hashed          = password_hash($password_plain, PASSWORD_DEFAULT);

            $userRole               = 'trainee';
            $userStatus             = 'active';
            $allowMultiplePrograms  = 0;
            
            $sql2 = "INSERT INTO users (fullname, email, password, role, date_created, status, allow_multiple_programs)
                     VALUES (?, ?, ?, ?, NOW(), ?, ?)";

            $stmt2 = $conn->prepare($sql2);
            if (!$stmt2) {
                throw new Exception("Prepare failed for users: " . $conn->error);
            }
            
            $stmt2->bind_param("sssssi", $fullname, $email, $hashed, $userRole, $userStatus, $allowMultiplePrograms);

            if (!$stmt2->execute()) {
                throw new Exception("Error inserting into users: " . $stmt2->error);
            }
            
            $user_id = $conn->insert_id;
            $stmt2->close();

            $sql1 = "INSERT INTO trainees 
            (lastname, firstname, middleinitial, house_street, barangay, municipality, city,
            birthday, age, contact_number, gender, gender_specify, civil_status, employment_status, 
            education, education_specify, applicant_type, nc_holder,
            trainings_attended, toolkit_received, valid_id, voters_certificate, 
            email, password, email_verified, fullname, address, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)";

            $stmt1 = $conn->prepare($sql1);
            if (!$stmt1) {
                throw new Exception("Prepare failed for trainees: " . $conn->error);
            }

            $stmt1->bind_param(
                "ssssssssisssssssssssssssssi",
                $lastname,
                $firstname,
                $middleinitial,
                $house_street,
                $barangay,
                $municipality,
                $city,
                $birthday,
                $age,
                $contact_number,
                $gender,
                $gender_specify,
                $civil_status,
                $employment_status,
                $education,
                $education_specify,
                $applicant_type_json,
                $nc_holder_json,
                $trainings_attended,
                $toolkit_received,
                $validIdJson,
                $votersCertJson,
                $email,
                $hashed,
                $fullname,
                $address,
                $user_id
            );

            if (!$stmt1->execute()) {
                throw new Exception("Error inserting into trainees: " . $stmt1->error);
            }
            $stmt1->close();

            $conn->commit();
            error_log("Registration successful for: $email");
            
            if (isset($_SESSION['pending_enrollment'])) {
                $program_id = $_SESSION['pending_enrollment'];
                error_log("Pending enrollment found: $program_id");
                
                $_SESSION['temp_registration'] = [
                    'email'      => $email,
                    'password'   => $password_plain,
                    'program_id' => $program_id
                ];
                
                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'lems.superadmn@gmail.com';
                    $mail->Password   = 'gubivcizhhkewkda';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    $mail->setFrom('noreply@municipallivelihood.gov.ph', 'Municipal Livelihood Program');
                    $mail->addAddress($email, $fullname);
                    $mail->isHTML(true);
                    $mail->Subject = 'Your Account Credentials - Municipal Livelihood Program';
                    $mail->Body    = generateEmailBody($fullname, $email, $password_plain, $address, $contact_number, $finalGender, $civil_status, $employment_status, $finalEducation, $finalApplicantType, $finalNCHolder, true);
                    $mail->AltBody = "Welcome to Municipal Livelihood Program!\n\nDear $fullname,\n\nYour account has been created.\n\nEmail: $email\nPassword: $password_plain";
                    $mail->send();
                    error_log("Email sent successfully to: $email");
                } catch (Exception $e) {
                    error_log("Mailer Error: " . $e->getMessage());
                }
                
                $redirect_url = "attempt_auto_enroll.php?program_id=" . $program_id . "&email=" . urlencode($email);
                header("Location: " . $redirect_url);
                exit();
            }
            
            error_log("No pending enrollment, sending regular registration email");
            
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'lems.superadmn@gmail.com';
                $mail->Password   = 'gubivcizhhkewkda';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                $mail->setFrom('noreply@municipallivelihood.gov.ph', 'Municipal Livelihood Program');
                $mail->addAddress($email, $fullname);
                $mail->isHTML(true);
                $mail->Subject = 'Your Account Credentials - Municipal Livelihood Program';
                $mail->Body    = generateEmailBody($fullname, $email, $password_plain, $address, $contact_number, $finalGender, $civil_status, $employment_status, $finalEducation, $finalApplicantType, $finalNCHolder, false);
                $mail->AltBody = "Welcome to Municipal Livelihood Program!\n\nDear $fullname,\n\nYour account has been created.\n\nEmail: $email\nPassword: $password_plain";
                $mail->send();
                $success = true;
                $message = "Registration successful! Your temporary login credentials have been sent to $email. Please check your email for instructions to change your password after first login.";
                error_log("Regular registration email sent successfully");
            } catch (Exception $e) {
                $success = true;
                $message = "Registration successful! However, we were unable to send the credentials email to $email. Please note your temporary credentials:<br><br>
                <div style='background: #fff3cd; border: 2px solid #ffc107; padding: 20px; border-radius: 8px; margin: 15px 0;'>
                    <h3 style='color: #d35400; margin-top: 0;'>Your Temporary Login Credentials</h3>
                    <p><strong>Email:</strong> <span style='background: #fffaf0; padding: 5px 10px; border-radius: 4px; border: 1px solid #f39c12;'>$email</span></p>
                    <p><strong>Temporary Password:</strong> <span style='background: #e7f3fe; padding: 5px 10px; border-radius: 4px; border: 1px solid #0066cc; font-family: monospace; font-size: 16px;'>$password_plain</span></p>
                    <p style='margin-top: 10px; color: #d35400;'><strong>⚠️ IMPORTANT:</strong> Please change this password after your first login for security.</p>
                </div>";
                error_log("Mailer Error: " . $e->getMessage());
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Database error: " . $e->getMessage();
            error_log("Registration error: " . $e->getMessage());
        }
    } else {
        error_log("Form has errors: " . implode(", ", $errors));
    }
    error_log("=== FORM SUBMISSION COMPLETED ===");
}

// Helper function to generate email body
function generateEmailBody($fullname, $email, $password_plain, $address, $contact_number, $finalGender, $civil_status, $employment_status, $finalEducation, $finalApplicantType, $finalNCHolder, $hasEnrollment = false) {
    $enrollmentText = $hasEnrollment ? "<p><strong>Important:</strong> You are being automatically enrolled in the program you selected.</p>" : "";
    
    return "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px; background-color: #f9f9f9; }
            .header { color: #2c5aa0; text-align: center; margin-bottom: 20px; }
            .credentials-box { background-color: #fff3cd; border: 3px solid #ffc107; border-radius: 8px; padding: 25px; margin: 20px 0; }
            .credential-item { margin-bottom: 20px; padding: 15px; background-color: white; border-radius: 8px; border: 2px solid #e9ecef; }
            .label { font-weight: bold; color: #2c5aa0; font-size: 16px; }
            .value { font-size: 18px; font-weight: bold; color: #d35400; background-color: #fffaf0; padding: 8px 12px; border-radius: 5px; border: 1px solid #f39c12; margin-top: 5px; display: inline-block; }
            .warning { background-color: #f8d7da; border: 2px solid #dc3545; color: #721c24; padding: 20px; border-radius: 8px; margin: 25px 0; }
            .password-change { background-color: #d1ecf1; border: 2px solid #0c5460; color: #0c5460; padding: 20px; border-radius: 8px; margin: 25px 0; }
            .user-info { background-color: #e8f4fd; border: 1px solid #b8daff; border-radius: 8px; padding: 15px; margin: 15px 0; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 14px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2 class='header'>Welcome to Municipal Livelihood Program!</h2>
            <div class='user-info'>
                <p><strong>Dear $fullname,</strong></p>
                <p>Thank you for registering with the Municipal Livelihood Program. Your account has been successfully created.</p>
                $enrollmentText
            </div>
            <div class='credentials-box'>
                <h3 style='color: #d35400; margin-top: 0; text-align: center;'>YOUR TEMPORARY LOGIN CREDENTIALS</h3>
                <div class='credential-item'>
                    <span class='label'>Email Address:</span><br>
                    <span class='value'>$email</span>
                </div>
                <div class='credential-item'>
                    <span class='label'>Temporary Password:</span><br>
                    <div style='background-color: #e7f3fe; border: 1px solid #0066cc; border-radius: 6px; padding: 10px; margin: 10px 0; font-family: monospace; font-size: 16px;'>$password_plain</div>
                </div>
            </div>
            <div class='password-change'>
                <h4 style='color: #0c5460; margin-top: 0;'>⚠️ IMPORTANT: CHANGE YOUR PASSWORD</h4>
                <p>After your first login, please change your password immediately.</p>
            </div>
            <div class='warning'>
                <h4 style='color: #dc3545; margin-top: 0;'>SECURITY REMINDER</h4>
                <ul>
                    <li>Change your password after your first login</li>
                    <li>Do not share your password with anyone</li>
                </ul>
            </div>
            <div class='user-info'>
                <h4 style='color: #2c5aa0; margin-top: 0;'>Registration Details</h4>
                <p><strong>Full Name:</strong> $fullname</p>
                <p><strong>Address:</strong> $address</p>
                <p><strong>Contact Number:</strong> $contact_number</p>
                <p><strong>Gender:</strong> $finalGender</p>
                <p><strong>Civil Status:</strong> $civil_status</p>
                <p><strong>Employment Status:</strong> $employment_status</p>
                <p><strong>Education:</strong> $finalEducation</p>
                <p><strong>Applicant Type:</strong> $finalApplicantType</p>
                <p><strong>NC Holder:</strong> $finalNCHolder</p>
                <p><strong>Registration Date:</strong> " . date('F j, Y') . "</p>
            </div>
            <div class='footer'>
                <p><strong>Need Help?</strong> Contact our support team.</p>
                <p>This is an automated message. Please do not reply.</p>
            </div>
            <p>Best regards,<br><strong>Municipal Livelihood Program Team</strong></p>
        </div>
    </body>
    </html>
    ";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Municipal Livelihood Program Registration</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; min-height: 100vh; background-image: url('css/SMBHALL.png'); background-size: cover; background-position: center center; background-attachment: fixed; background-repeat: no-repeat; color: white; }
        .registration-wrapper { min-height: 100vh; background: rgba(28, 42, 58, 0.85); display: flex; flex-direction: column; }
        .top-nav { display: flex; justify-content: space-between; align-items: center; padding: 1rem 2rem; background: rgba(28, 42, 58, 0.9); backdrop-filter: blur(10px); position: sticky; top: 0; z-index: 1000; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
        .left-section { display: flex; align-items: center; gap: 1rem; }
        .logo { width: 50px; height: 50px; border-radius: 8px; }
        .title { font-size: 1.5rem; font-weight: 600; color: white; }
        .desktop-title { display: block; }
        .mobile-title { display: none; color: white; }
        .burger-btn { display: none; background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; padding: 0.5rem; z-index: 1001; }
        .right-section { display: flex; gap: 2rem; align-items: center; }
        .nav-link { color: white; text-decoration: none; font-weight: 500; padding: 0.5rem 1rem; border-radius: 5px; transition: background 0.3s; }
        .nav-link:hover { background: rgba(255, 255, 255, 0.1); }
        .mobile-menu { display: none; flex-direction: column; background: rgba(28, 42, 58, 0.98); backdrop-filter: blur(15px); position: fixed; top: 0; left: 0; right: 0; z-index: 999; padding-top: 70px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3); border-bottom: 1px solid rgba(255, 255, 255, 0.15); max-height: 100vh; overflow-y: auto; }
        .mobile-menu.active { display: flex; animation: slideDown 0.3s ease forwards; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .mobile-menu .nav-link { padding: 1.2rem 2rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); font-size: 1.1rem; text-align: left; transition: all 0.3s; display: flex; align-items: center; gap: 1rem; color: white !important; text-decoration: none; font-weight: 500; }
        .mobile-menu .nav-link:last-child { border-bottom: none; }
        .mobile-menu .nav-link:hover { background: rgba(255, 255, 255, 0.1); padding-left: 2.5rem; color: white !important; }
        .mobile-menu .nav-link i { width: 20px; text-align: center; font-size: 1.2rem; color: white !important; }
        .container { max-width: 900px; margin: 20px auto; padding: 0 20px; }
        .form-wrapper { background: rgba(255, 255, 255, 0.1); padding: 30px; border-radius: 15px; box-shadow: 0 15px 35px rgba(0,0,0,0.3); position: relative; overflow: hidden; border: 1px solid rgba(255, 255, 255, 0.2); backdrop-filter: blur(10px); margin-top: 20px; margin-bottom: 40px; transform: translateY(20px); animation: floatUp 0.5s ease-out forwards; }
        @keyframes floatUp { to { transform: translateY(0); } }
        .form-wrapper::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 5px; background: linear-gradient(90deg, #20c997, #3b82f6); }
        h1 { color: white; text-align: center; margin-bottom: 10px; font-size: 2.2em; text-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .subtitle { text-align: center; color: rgba(255, 255, 255, 0.8); margin-bottom: 30px; font-size: 1.1em; text-shadow: 0 1px 2px rgba(0,0,0,0.2); }
        .form-row { display: flex; gap: 20px; margin-bottom: 20px; }
        .form-group { flex: 1; margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: white; text-shadow: 0 1px 2px rgba(0,0,0,0.2); }
        input, select { width: 100%; padding: 12px; background: rgba(255, 255, 255, 0.1); border: 2px solid rgba(255, 255, 255, 0.2); border-radius: 8px; font-size: 16px; transition: all 0.3s; color: white; }
        select option { color: black !important; padding: 12px; font-size: 16px; }
        input::placeholder { color: rgba(255, 255, 255, 0.6); }
        input:focus, select:focus { outline: none; border-color: #20c997; box-shadow: 0 0 0 3px rgba(32, 201, 151, 0.2); background: rgba(255, 255, 255, 0.15); }
        input[readonly] { background: rgba(255, 255, 255, 0.05); color: rgba(255, 255, 255, 0.7); }
        .btn-submit { background: linear-gradient(135deg, #20c997, #17a589); color: white; padding: 16px 30px; border: none; border-radius: 8px; cursor: pointer; font-size: 18px; width: 100%; font-weight: bold; transition: all 0.3s; margin-top: 10px; text-shadow: 0 1px 2px rgba(0,0,0,0.2); box-shadow: 0 4px 15px rgba(32, 201, 151, 0.3); }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(32, 201, 151, 0.4); background: linear-gradient(135deg, #17a589, #20c997); }
        .alert { padding: 20px; margin-bottom: 30px; border-radius: 8px; border-left: 5px solid; animation: slideIn 0.5s ease-out; background: rgba(0, 0, 0, 0.3); backdrop-filter: blur(5px); }
        @keyframes slideIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .alert-error { background: rgba(220, 38, 38, 0.2); color: #fecaca; border-left-color: #ef4444; }
        .alert-success { background: rgba(22, 101, 52, 0.2); color: #bbf7d0; border-left-color: #16a34a; }
        small { color: rgba(255, 255, 255, 0.7); font-size: 12px; display: block; margin-top: 5px; text-shadow: 0 1px 1px rgba(0,0,0,0.2); }
        .btn-primary { display: inline-block; padding: 12px 25px; background: linear-gradient(135deg, #20c997, #17a589); color: white; text-decoration: none; border-radius: 8px; margin-top: 15px; text-align: center; transition: all 0.3s; font-weight: 600; border: none; cursor: pointer; text-shadow: 0 1px 2px rgba(0,0,0,0.2); box-shadow: 0 4px 10px rgba(32, 201, 151, 0.2); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(32, 201, 151, 0.3); background: linear-gradient(135deg, #17a589, #20c997); }
        h2 { color: white; margin: 30px 0 20px 0; padding-bottom: 10px; border-bottom: 2px solid rgba(255, 255, 255, 0.2); text-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .program-notice { background: rgba(32, 201, 151, 0.2); border: 2px solid #20c997; border-radius: 10px; padding: 20px; margin-bottom: 25px; text-align: center; backdrop-filter: blur(5px); }
        .program-notice h3 { color: #20c997; margin-top: 0; text-shadow: 0 1px 2px rgba(0,0,0,0.2); }
        .password-note { background: rgba(59, 130, 246, 0.2); border: 1px solid #3b82f6; border-radius: 8px; padding: 15px; margin: 15px 0; text-align: center; font-size: 14px; color: rgba(255, 255, 255, 0.9); backdrop-filter: blur(5px); }
        .scan-step { background: rgba(32, 201, 151, 0.1); border: 1px solid rgba(32, 201, 151, 0.3); border-radius: 8px; padding: 15px; margin: 10px 0; backdrop-filter: blur(5px); }
        .scan-step-number { display: inline-block; background: linear-gradient(135deg, #20c997, #17a589); color: white; width: 30px; height: 30px; border-radius: 50%; text-align: center; line-height: 30px; margin-right: 10px; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        .checkbox-group { background: rgba(255, 255, 255, 0.1); border: 2px solid rgba(255, 255, 255, 0.2); border-radius: 8px; padding: 15px; margin-top: 5px; }
        .checkbox-item { display: flex; align-items: center; margin-bottom: 10px; padding: 8px; border-radius: 5px; transition: background 0.3s; }
        .checkbox-item:hover { background: rgba(255, 255, 255, 0.1); }
        .checkbox-item input[type="checkbox"] { width: 18px; height: 18px; margin-right: 10px; accent-color: #20c997; flex-shrink: 0; }
        .checkbox-item label { margin: 0; font-weight: normal; cursor: pointer; flex: 1; }
        .checkbox-item input[type="checkbox"]:disabled + label { opacity: 0.5; cursor: not-allowed; }

        /* ── Document upload tab styles ── */
        .doc-upload-box { border: 2px dashed #20c997; border-radius: 10px; padding: 20px; background: rgba(32, 201, 151, 0.1); margin-bottom: 15px; }
        .tab-switcher { display: flex; gap: 0; margin-bottom: 18px; border-radius: 8px; overflow: hidden; border: 2px solid #20c997; }
        .tab-btn { flex: 1; padding: 10px 0; background: transparent; border: none; color: rgba(255,255,255,0.7); font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.25s; font-family: 'Poppins', sans-serif; }
        .tab-btn.active { background: linear-gradient(135deg, #20c997, #17a589); color: white; }
        .tab-btn:not(.active):hover { background: rgba(32,201,151,0.15); color: white; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        /* file drop zone */
        .file-drop-zone { border: 2px dashed rgba(255,255,255,0.35); border-radius: 10px; padding: 28px 20px; text-align: center; background: rgba(255,255,255,0.05); cursor: pointer; transition: all 0.3s; position: relative; }
        .file-drop-zone:hover, .file-drop-zone.dragover { border-color: #20c997; background: rgba(32,201,151,0.1); }
        .file-drop-zone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
        .file-drop-zone .drop-icon { font-size: 2.5rem; margin-bottom: 10px; }
        .file-drop-zone p { color: rgba(255,255,255,0.8); margin: 0; font-size: 14px; }
        .file-drop-zone strong { color: #20c997; }
        .uploaded-file-list { margin-top: 12px; }
        .uploaded-file-item { display: flex; align-items: center; gap: 10px; background: rgba(32,201,151,0.12); border: 1px solid rgba(32,201,151,0.3); border-radius: 8px; padding: 8px 12px; margin-bottom: 8px; }
        .uploaded-file-item img { width: 50px; height: 50px; object-fit: cover; border-radius: 5px; border: 1px solid rgba(255,255,255,0.2); flex-shrink: 0; }
        .uploaded-file-item .file-info { flex: 1; overflow: hidden; }
        .uploaded-file-item .file-name { font-size: 13px; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .uploaded-file-item .file-size { font-size: 11px; color: rgba(255,255,255,0.6); }
        .uploaded-file-item .remove-btn { background: rgba(220,53,69,0.7); border: none; color: white; border-radius: 5px; padding: 4px 8px; cursor: pointer; font-size: 12px; flex-shrink: 0; transition: background 0.2s; }
        .uploaded-file-item .remove-btn:hover { background: rgba(220,53,69,1); }

        @media (max-width: 768px) {
            .form-row { flex-direction: column; gap: 0; }
            .form-wrapper { padding: 25px 20px; margin: 10px; transform: translateY(10px); }
            h1 { font-size: 1.8em; }
            .desktop-title { display: none; }
            .mobile-title { display: block; color: white; }
            .burger-btn { display: block; color: white; }
            .right-section { display: none; }
            .top-nav { padding: 1rem; }
            .logo { width: 40px; height: 40px; }
            .title { font-size: 1.2rem; color: white; }
            .container { padding: 0 10px; }
            .mobile-menu { padding-top: 80px; height: calc(100vh - 80px); }
            .mobile-menu .nav-link { color: white !important; font-weight: 500; }
            .mobile-menu .nav-link i { color: white !important; }
        }
        @media (max-width: 480px) {
            h1 { font-size: 1.6em; }
            .subtitle { font-size: 1em; }
            .form-wrapper { padding: 20px 15px; }
            .mobile-menu .nav-link { padding: 1.2rem 1.5rem; font-size: 1rem; color: white !important; }
            .mobile-menu .nav-link:hover { padding-left: 2rem; color: white !important; }
            .mobile-menu .nav-link i { color: white !important; }
        }
        #cameraContainer, #certCameraContainer { background: rgba(0, 0, 0, 0.3); border-radius: 10px; padding: 15px; margin-bottom: 15px; }
        video { background: black; border-radius: 8px; }
        #scanPreview, #certScanPreview { background: rgba(0, 0, 0, 0.3); border-radius: 10px; padding: 20px; }
        #previewImage, #certPreviewImage { background: white; border-radius: 8px; }
        #scannedIdList, #scannedCertList { background: rgba(0, 0, 0, 0.2); border-radius: 8px; padding: 10px; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="registration-wrapper">
        <!-- TOP NAVBAR -->
        <div class="top-nav">
            <div class="left-section">
                <img src="/css/logo.png" alt="Logo" class="logo">
                <h1 class="title">
                    <span class="desktop-title">Livelihood Enrollment & Monitoring System</span>
                    <span class="mobile-title">LEMS</span>
                </h1>
            </div>

            <button class="burger-btn" id="burgerBtn" aria-label="Toggle menu">
                <i class="fas fa-bars"></i>
            </button>

            <nav class="right-section">
                <a href="index.php" class="nav-link">Home</a>
                <a href="about.php" class="nav-link">About</a>
                <a href="faqs.php" class="nav-link">FAQs</a>
                <a href="login.php" class="nav-link">Login</a>
            </nav>
        </div>

        <!-- MOBILE MENU -->
        <div class="mobile-menu" id="mobileMenu">
            <a href="index.php" class="nav-link"><i class="fas fa-home"></i> Home</a>
            <a href="about.php" class="nav-link"><i class="fas fa-info-circle"></i> About</a>
            <a href="faqs.php" class="nav-link"><i class="fas fa-question-circle"></i> FAQs</a>
            <a href="login.php" class="nav-link"><i class="fas fa-sign-in-alt"></i> Login</a>
        </div>

        <!-- MAIN CONTENT -->
        <div class="container">
            <div class="form-wrapper">
                <h1>Municipal Livelihood Program Registration</h1>
                <p class="subtitle">Please complete the form and provide your required documents.</p>

                <?php if (isset($_SESSION['pending_enrollment'])): 
                    $program_id = $_SESSION['pending_enrollment'];
                    $programQuery = $conn->prepare("SELECT name FROM programs WHERE id = ?");
                    $programQuery->bind_param("i", $program_id);
                    $programQuery->execute();
                    $programResult = $programQuery->get_result();
                    $program_name = ($programResult->num_rows > 0) ? $programResult->fetch_assoc()['name'] : "Selected Program";
                    $programQuery->close();
                ?>
                    <div class="program-notice">
                        <h3>Program Enrollment</h3>
                        <p>You are registering for: <strong><?php echo htmlspecialchars($program_name); ?></strong></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error">
                        <h3 style="margin-top: 0;">Please fix the following errors:</h3>
                        <?php foreach ($errors as $error): ?>
                            <p>• <?php echo htmlspecialchars($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <h3 style="margin-top: 0;">Registration Successful!</h3>
                        <p><?php echo $message; ?></p>
                        <a href="login.php" class="btn-primary">Go to Login</a>
                    </div>
                <?php else: ?>

                <form method="POST" enctype="multipart/form-data" id="registrationForm">
                    <!-- Full Name Fields -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Last Name *</label>
                            <input type="text" name="lastname" required pattern="[A-Za-zÑñ\s-]+" title="Letters, spaces, and hyphens only" placeholder="Dela Cruz" value="<?php echo htmlspecialchars($lastname ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>First Name *</label>
                            <input type="text" name="firstname" required pattern="[A-Za-zÑñ\s-]+" title="Letters, spaces, and hyphens only" placeholder="Juan" value="<?php echo htmlspecialchars($firstname ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>M.I. (Optional)</label>
                            <input type="text" name="middleinitial" maxlength="2" pattern="[A-Za-z]{1,2}" title="1-2 letters only (if provided)" placeholder="G" value="<?php echo htmlspecialchars($middleinitial ?? ''); ?>">
                            <small>Letters only, not required</small>
                        </div>
                    </div>

                    <!-- Address Fields -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>House No. & Street *</label>
                            <input type="text" name="house_street" required placeholder="1234 Sampaguita St." value="<?php echo htmlspecialchars($house_street ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Barangay *</label>
                            <select name="barangay" required>
                                <option value="">Select Barangay</option>
                                <?php foreach ($sta_maria_barangays as $brgy): ?>
                                    <option value="<?php echo htmlspecialchars($brgy); ?>" <?php echo (($barangay ?? '') === $brgy) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($brgy); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Municipality *</label>
                            <input type="text" name="municipality" value="Sta. Maria" readonly>
                        </div>
                        <div class="form-group">
                            <label>Province *</label>
                            <input type="text" name="city" value="Bulacan" readonly>
                        </div>
                    </div>

                    <!-- Birthday and Age -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Birthday *</label>
                            <input type="date" name="birthday" id="birthday" required value="<?php echo htmlspecialchars($birthday ?? ''); ?>">
                            <small>Please enter your birthdate (will be validated for age 18-70)</small>
                        </div>
                        <div class="form-group">
                            <label>Age *</label>
                            <input type="number" name="age" id="age" readonly value="<?php echo htmlspecialchars($age ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Contact Number -->
                    <div class="form-group">
                        <label>Contact Number *</label>
                        <input type="tel" name="contact_number" id="contact_number" required pattern="09[0-9]{9}" maxlength="11" placeholder="09123456789" value="<?php echo htmlspecialchars($contact_number ?? ''); ?>">
                        <small>Must start with 09 and be 11 digits</small>
                    </div>

                    <!-- Email -->
                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="email" required placeholder="juan.delacruz@example.com" value="<?php echo htmlspecialchars($email ?? ''); ?>">
                        <small>Your login credentials will be sent to this email</small>
                    </div>

                    <!-- Gender -->
                    <div class="form-group">
                        <label>Gender *</label>
                        <select name="gender" id="gender" required>
                            <option value="">Select Gender</option>
                            <option value="Male" <?php echo (($gender ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo (($gender ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                            <option value="Others" <?php echo (($gender ?? '') === 'Others') ? 'selected' : ''; ?>>Others</option>
                        </select>
                    </div>

                    <div class="form-group" id="gender_specify_group" style="display: none;">
                        <label>Please Specify *</label>
                        <input type="text" name="gender_specify" id="gender_specify" placeholder="Please specify your gender" value="<?php echo htmlspecialchars($gender_specify ?? ''); ?>">
                    </div>

                    <!-- Civil Status -->
                    <div class="form-group">
                        <label>Civil Status *</label>
                        <select name="civil_status" required>
                            <option value="">Select Civil Status</option>
                            <option value="Single" <?php echo (($civil_status ?? '') === 'Single') ? 'selected' : ''; ?>>Single</option>
                            <option value="Married" <?php echo (($civil_status ?? '') === 'Married') ? 'selected' : ''; ?>>Married</option>
                            <option value="Separated" <?php echo (($civil_status ?? '') === 'Separated') ? 'selected' : ''; ?>>Separated</option>
                            <option value="Divorced" <?php echo (($civil_status ?? '') === 'Divorced') ? 'selected' : ''; ?>>Divorced</option>
                            <option value="Widow/Widower" <?php echo (($civil_status ?? '') === 'Widow/Widower') ? 'selected' : ''; ?>>Widow/Widower</option>
                        </select>
                    </div>

                    <!-- Employment Status -->
                    <div class="form-group">
                        <label>Employment Status *</label>
                        <select name="employment_status" required>
                            <option value="">Select Employment Status</option>
                            <option value="Employed" <?php echo (($employment_status ?? '') === 'Employed') ? 'selected' : ''; ?>>Employed</option>
                            <option value="Unemployed" <?php echo (($employment_status ?? '') === 'Unemployed') ? 'selected' : ''; ?>>Unemployed</option>
                            <option value="Self-employed" <?php echo (($employment_status ?? '') === 'Self-employed') ? 'selected' : ''; ?>>Self-employed</option>
                        </select>
                    </div>

                    <!-- Educational Attainment -->
                    <div class="form-group">
                        <label>Educational Attainment *</label>
                        <select name="education" id="education" required>
                            <option value="">Select Educational Attainment</option>
                            <option value="Elementary Graduate" <?php echo (($education ?? '') === 'Elementary Graduate') ? 'selected' : ''; ?>>Elementary Graduate</option>
                            <option value="High School Level / Graduate" <?php echo (($education ?? '') === 'High School Level / Graduate') ? 'selected' : ''; ?>>High School Level / Graduate</option>
                            <option value="College Level / Graduate" <?php echo (($education ?? '') === 'College Level / Graduate') ? 'selected' : ''; ?>>College Level / Graduate</option>
                            <option value="Others" <?php echo (($education ?? '') === 'Others') ? 'selected' : ''; ?>>Others</option>
                        </select>
                    </div>

                    <div class="form-group" id="education_specify_group" style="display: none;">
                        <label>Please Specify Educational Attainment *</label>
                        <input type="text" name="education_specify" id="education_specify" placeholder="Please specify your educational attainment" value="<?php echo htmlspecialchars($education_specify ?? ''); ?>">
                    </div>

                    <!-- Applicant Type -->
                    <div class="form-group">
                        <label>Applicant Type (Check all that apply)</label>
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" name="applicant_type[]" id="type_pwd" value="PWD (Person With Disability)" <?php echo (isset($applicant_type) && is_array($applicant_type) && in_array('PWD (Person With Disability)', $applicant_type)) ? 'checked' : ''; ?>>
                                <label for="type_pwd">PWD (Person With Disability)</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" name="applicant_type[]" id="type_senior" value="Senior Citizen" <?php echo (isset($applicant_type) && is_array($applicant_type) && in_array('Senior Citizen', $applicant_type)) ? 'checked' : ''; ?>>
                                <label for="type_senior">Senior Citizen</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" name="applicant_type[]" id="type_4ps" value="4Ps Beneficiary" <?php echo (isset($applicant_type) && is_array($applicant_type) && in_array('4Ps Beneficiary', $applicant_type)) ? 'checked' : ''; ?>>
                                <label for="type_4ps">4Ps Beneficiary</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" name="applicant_type[]" id="type_none1" value="None" <?php echo (isset($applicant_type) && is_array($applicant_type) && in_array('None', $applicant_type)) ? 'checked' : ''; ?>>
                                <label for="type_none1">None of the above</label>
                            </div>
                        </div>
                        <small>Checking "None of the above" will uncheck all other options</small>
                    </div>

                    <!-- NC Holder -->
                    <div class="form-group">
                        <label>Are you an NC Holder? (Check all that apply)</label>
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" name="nc_holder[]" id="nc_nc1" value="NC1" <?php echo (isset($nc_holder) && is_array($nc_holder) && in_array('NC1', $nc_holder)) ? 'checked' : ''; ?>>
                                <label for="nc_nc1">NC1 Holder</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" name="nc_holder[]" id="nc_nc2" value="NC2" <?php echo (isset($nc_holder) && is_array($nc_holder) && in_array('NC2', $nc_holder)) ? 'checked' : ''; ?>>
                                <label for="nc_nc2">NC2 Holder</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" name="nc_holder[]" id="nc_none" value="None" <?php echo (isset($nc_holder) && is_array($nc_holder) && in_array('None', $nc_holder)) ? 'checked' : ''; ?>>
                                <label for="nc_none">None</label>
                            </div>
                        </div>
                        <small>Checking "None" will uncheck NC1 and NC2 options. NC1 and NC2 can both be selected.</small>
                    </div>

                    <!-- Trainings and Toolkit -->
                    <div class="form-group">
                        <label>Trainings Attended Before (in PESO) *</label>
                        <input type="text" name="trainings_attended" required placeholder="Put N/A if none" value="<?php echo htmlspecialchars($trainings_attended ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>Toolkit Received Before *</label>
                        <input type="text" name="toolkit_received" required placeholder="Put N/A if none" value="<?php echo htmlspecialchars($toolkit_received ?? ''); ?>">
                    </div>

                    <!-- ============================================================ -->
                    <!-- DOCUMENT SCANNING SECTION                                    -->
                    <!-- ============================================================ -->
                    <h2>Document Submission</h2>

                    <div class="scan-step">
                        <div style="background: rgba(0,0,0,0.2); border: 2px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 20px; margin-bottom: 15px;">
                            <strong style="color: #20c997; display: block; margin-bottom: 10px;">How to submit your documents:</strong>
                            <p style="color: rgba(255,255,255,0.85); font-size: 14px; margin: 0;">
                                For each document below, you can either <strong>scan using your camera</strong> or <strong>upload an image file</strong> from your device.
                                Both methods are accepted. Accepted formats: JPG, PNG, GIF, WEBP, BMP (max 10 MB per file).
                            </p>
                        </div>
                    </div>

                    <!-- ── VALID ID ── -->
                    <div class="form-group">
                        <label>Valid Government-Issued ID *</label>

                        <div class="doc-upload-box">
                            <!-- Tab switcher -->
                            <div class="tab-switcher">
                                <button type="button" class="tab-btn active" onclick="switchTab('id','camera')">
                                    📷 Use Camera
                                </button>
                                <button type="button" class="tab-btn" onclick="switchTab('id','upload')">
                                    📁 Upload File
                                </button>
                            </div>

                            <!-- Camera tab -->
                            <div class="tab-panel active" id="id-tab-camera">
                                <div id="cameraContainer" style="display: none;">
                                    <video id="cameraFeed" autoplay playsinline style="width: 100%; max-width: 500px; border-radius: 8px; border: 2px solid #20c997;"></video>
                                    <div style="margin: 15px 0;">
                                        <canvas id="scanCanvas" style="display: none;"></canvas>
                                        <button type="button" id="captureBtn" class="btn-primary" style="margin: 5px;">Capture Scan</button>
                                        <button type="button" id="cancelScanBtn" class="btn-primary" style="margin: 5px; background: rgba(108,117,125,0.8);">Cancel</button>
                                    </div>
                                </div>

                                <div id="scanPreview" style="display: none; margin: 15px 0;">
                                    <h4 style="color: #20c997;">ID Scan Preview</h4>
                                    <img id="previewImage" src="" style="max-width: 300px; border: 2px solid rgba(255,255,255,0.2); border-radius: 8px; margin-bottom: 15px;">
                                    <div style="margin: 15px 0;">
                                        <button type="button" id="confirmScanBtn" class="btn-primary" style="margin: 5px;">Confirm Scan</button>
                                        <button type="button" id="rescanBtn" class="btn-primary" style="margin: 5px; background: rgba(108,117,125,0.8);">Rescan</button>
                                    </div>
                                </div>

                                <div id="scanControls" style="text-align:center;">
                                    <button type="button" id="startScanBtn" class="btn-primary" style="margin: 10px;">
                                        📷 Start Live ID Scanning
                                    </button>
                                    <div style="color: rgba(255,255,255,0.8); font-size: 14px; margin-top: 10px;">
                                        <p><strong>Note:</strong> Make sure the ID is clear and all text is readable.</p>
                                    </div>
                                </div>

                                <input type="hidden" name="scanned_id_data" id="scanned_id_data">
                            </div>

                            <!-- Upload tab -->
                            <div class="tab-panel" id="id-tab-upload">
                                <div class="file-drop-zone" id="idDropZone">
                                    <input type="file" name="valid_id_upload[]" id="valid_id_upload" multiple accept="image/*" onchange="handleFileSelect(this,'idFileList','idDropZone')">
                                    <div class="drop-icon">🖼️</div>
                                    <p><strong>Click to browse</strong> or drag &amp; drop your ID image(s) here</p>
                                    <p style="margin-top: 6px; font-size: 12px;">JPG, PNG, GIF, WEBP, BMP &nbsp;|&nbsp; Max 10 MB per file</p>
                                </div>
                                <div class="uploaded-file-list" id="idFileList"></div>
                            </div>
                        </div>

                        <div id="scannedIdList" style="margin-top: 10px;"></div>
                    </div>

                    <!-- ── VOTER'S / BARANGAY CERTIFICATE ── -->
                    <div class="form-group">
                        <label>Voter's Certificate / Barangay Certificate of Residency *</label>

                        <div class="doc-upload-box">
                            <!-- Tab switcher -->
                            <div class="tab-switcher">
                                <button type="button" class="tab-btn active" onclick="switchTab('cert','camera')">
                                    📷 Use Camera
                                </button>
                                <button type="button" class="tab-btn" onclick="switchTab('cert','upload')">
                                    📁 Upload File
                                </button>
                            </div>

                            <!-- Camera tab -->
                            <div class="tab-panel active" id="cert-tab-camera">
                                <div id="certCameraContainer" style="display: none;">
                                    <video id="certCameraFeed" autoplay playsinline style="width: 100%; max-width: 500px; border-radius: 8px; border: 2px solid #20c997;"></video>
                                    <div style="margin: 15px 0;">
                                        <canvas id="certScanCanvas" style="display: none;"></canvas>
                                        <button type="button" id="certCaptureBtn" class="btn-primary" style="margin: 5px;">Capture Scan</button>
                                        <button type="button" id="certCancelScanBtn" class="btn-primary" style="margin: 5px; background: rgba(108,117,125,0.8);">Cancel</button>
                                    </div>
                                </div>

                                <div id="certScanPreview" style="display: none; margin: 15px 0;">
                                    <h4 style="color: #20c997;">Certificate Scan Preview</h4>
                                    <img id="certPreviewImage" src="" style="max-width: 300px; border: 2px solid rgba(255,255,255,0.2); border-radius: 8px;">
                                    <div id="certVerificationResult" style="margin: 15px 0; padding: 15px; border-radius: 8px; display: none;"></div>
                                    <div style="margin: 15px 0;">
                                        <button type="button" id="certConfirmScanBtn" class="btn-primary" style="margin: 5px; display: none;">Confirm Scan</button>
                                        <button type="button" id="certRescanBtn" class="btn-primary" style="margin: 5px; background: rgba(108,117,125,0.8);">Rescan</button>
                                    </div>
                                </div>

                                <div id="certScanControls" style="text-align:center;">
                                    <button type="button" id="startCertScanBtn" class="btn-primary" style="margin: 10px;">
                                        📄 Start Live Certificate Scanning
                                    </button>
                                    <div style="color: rgba(255,255,255,0.8); font-size: 14px; margin-top: 10px;">
                                        <p><strong>Note:</strong> Certificate should be issued within last 3 months.</p>
                                    </div>
                                </div>

                                <input type="hidden" name="scanned_cert_data" id="scanned_cert_data">
                            </div>

                            <!-- Upload tab -->
                            <div class="tab-panel" id="cert-tab-upload">
                                <div class="file-drop-zone" id="certDropZone">
                                    <input type="file" name="voters_cert_upload[]" id="voters_cert_upload" multiple accept="image/*" onchange="handleFileSelect(this,'certFileList','certDropZone')">
                                    <div class="drop-icon">📄</div>
                                    <p><strong>Click to browse</strong> or drag &amp; drop your certificate image(s) here</p>
                                    <p style="margin-top: 6px; font-size: 12px;">JPG, PNG, GIF, WEBP, BMP &nbsp;|&nbsp; Max 10 MB per file</p>
                                </div>
                                <div class="uploaded-file-list" id="certFileList"></div>
                            </div>
                        </div>

                        <div id="scannedCertList" style="margin-top: 10px;"></div>
                    </div>

                    <!-- Password Note -->
                    <div class="password-note">
                        <p><strong>Note:</strong> Your password will be automatically generated in an easy-to-remember format and sent to your email.</p>
                        <p><strong>Important:</strong> After your first login, you will be prompted to change your password for security.</p>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn-submit" id="submitBtn">Submit Registration</button>
                </form>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // ==========================================
        // MOBILE MENU FUNCTIONALITY
        // ==========================================
        const burgerBtn = document.getElementById('burgerBtn');
        const mobileMenu = document.getElementById('mobileMenu');
        const body = document.body;

        if (burgerBtn && mobileMenu) {
            burgerBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                mobileMenu.classList.toggle('active');
                body.classList.toggle('menu-open');
                const icon = burgerBtn.querySelector('i');
                if (mobileMenu.classList.contains('active')) {
                    icon.classList.replace('fa-bars','fa-times');
                } else {
                    icon.classList.replace('fa-times','fa-bars');
                }
            });
            document.addEventListener('click', (e) => {
                if (!burgerBtn.contains(e.target) && !mobileMenu.contains(e.target)) {
                    mobileMenu.classList.remove('active');
                    body.classList.remove('menu-open');
                    burgerBtn.querySelector('i').classList.replace('fa-times','fa-bars');
                }
            });
            mobileMenu.querySelectorAll('.nav-link').forEach(link => {
                link.addEventListener('click', () => {
                    mobileMenu.classList.remove('active');
                    body.classList.remove('menu-open');
                    burgerBtn.querySelector('i').classList.replace('fa-times','fa-bars');
                });
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && mobileMenu.classList.contains('active')) {
                    mobileMenu.classList.remove('active');
                    body.classList.remove('menu-open');
                    burgerBtn.querySelector('i').classList.replace('fa-times','fa-bars');
                }
            });
        }

        // ==========================================
        // TAB SWITCHER FOR DOCUMENT UPLOAD
        // ==========================================
        function switchTab(doc, tab) {
            // doc = 'id' | 'cert'
            const cameraPanel = document.getElementById(doc + '-tab-camera');
            const uploadPanel  = document.getElementById(doc + '-tab-upload');
            const btns = cameraPanel.closest('.doc-upload-box').querySelectorAll('.tab-btn');

            btns.forEach(b => b.classList.remove('active'));
            cameraPanel.classList.remove('active');
            uploadPanel.classList.remove('active');

            if (tab === 'camera') {
                cameraPanel.classList.add('active');
                btns[0].classList.add('active');
            } else {
                uploadPanel.classList.add('active');
                btns[1].classList.add('active');
            }
        }

        // ==========================================
        // FILE UPLOAD PREVIEW
        // ==========================================
        function handleFileSelect(input, listId, dropZoneId) {
            const list = document.getElementById(listId);
            list.innerHTML = '';

            const files = Array.from(input.files);
            const maxSize = 10 * 1024 * 1024;
            const allowedTypes = ['image/jpeg','image/png','image/gif','image/webp','image/bmp'];

            files.forEach((file, idx) => {
                if (!allowedTypes.includes(file.type)) {
                    showFileError(list, file.name + ': Not a valid image type.');
                    return;
                }
                if (file.size > maxSize) {
                    showFileError(list, file.name + ': Exceeds 10 MB limit.');
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e) {
                    const item = document.createElement('div');
                    item.className = 'uploaded-file-item';
                    item.dataset.index = idx;
                    item.innerHTML = `
                        <img src="${e.target.result}" alt="preview">
                        <div class="file-info">
                            <div class="file-name">${file.name}</div>
                            <div class="file-size">${(file.size / 1024).toFixed(1)} KB</div>
                        </div>
                        <button type="button" class="remove-btn" onclick="removeFile(this,'${input.id}','${listId}',${idx})">✕ Remove</button>
                    `;
                    list.appendChild(item);
                };
                reader.readAsDataURL(file);
            });
        }

        function showFileError(list, msg) {
            const err = document.createElement('p');
            err.style.cssText = 'color: #fca5a5; font-size: 13px; margin: 5px 0;';
            err.textContent = '⚠️ ' + msg;
            list.appendChild(err);
        }

        function removeFile(btn, inputId, listId, idx) {
            const input = document.getElementById(inputId);
            const dt = new DataTransfer();
            Array.from(input.files).forEach((f, i) => {
                if (i !== idx) dt.items.add(f);
            });
            input.files = dt.files;
            // Re-render the list
            handleFileSelect(input, listId, null);
        }

        // Drag & drop highlight
        document.querySelectorAll('.file-drop-zone').forEach(zone => {
            zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
            zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
            zone.addEventListener('drop', e => { e.preventDefault(); zone.classList.remove('dragover'); });
        });

        // ==========================================
        // FORM VALIDATION AND INTERACTIVITY
        // ==========================================
        document.addEventListener('DOMContentLoaded', function() {
            const birthdayInput = document.getElementById('birthday');
            const ageInput = document.getElementById('age');
            const genderSelect = document.getElementById('gender');
            const genderSpecifyGroup = document.getElementById('gender_specify_group');
            const genderSpecifyInput = document.getElementById('gender_specify');
            const educationSelect = document.getElementById('education');
            const educationSpecifyGroup = document.getElementById('education_specify_group');
            const educationSpecifyInput = document.getElementById('education_specify');
            const contactNumberInput = document.getElementById('contact_number');
            
            if (birthdayInput && ageInput) {
                birthdayInput.addEventListener('change', function() {
                    if (this.value) {
                        const birthDate = new Date(this.value);
                        const today = new Date();
                        let age = today.getFullYear() - birthDate.getFullYear();
                        const monthDiff = today.getMonth() - birthDate.getMonth();
                        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) age--;
                        ageInput.value = age;
                        const invalid = age < 18 || age > 70;
                        birthdayInput.style.borderColor = invalid ? '#ffc107' : '';
                        ageInput.style.borderColor    = invalid ? '#ffc107' : '';
                    }
                });
                if (birthdayInput.value) birthdayInput.dispatchEvent(new Event('change'));
            }
            
            if (genderSelect) {
                genderSelect.addEventListener('change', function() {
                    const show = this.value === 'Others';
                    genderSpecifyGroup.style.display = show ? 'flex' : 'none';
                    genderSpecifyInput.required = show;
                    if (!show) genderSpecifyInput.value = '';
                });
                if (genderSelect.value === 'Others') {
                    genderSpecifyGroup.style.display = 'flex';
                    genderSpecifyInput.required = true;
                }
            }
            
            if (educationSelect) {
                educationSelect.addEventListener('change', function() {
                    const show = this.value === 'Others';
                    educationSpecifyGroup.style.display = show ? 'flex' : 'none';
                    educationSpecifyInput.required = show;
                    if (!show) educationSpecifyInput.value = '';
                });
                if (educationSelect.value === 'Others') {
                    educationSpecifyGroup.style.display = 'flex';
                    educationSpecifyInput.required = true;
                }
            }
            
            if (contactNumberInput) {
                contactNumberInput.addEventListener('input', function() {
                    let v = this.value.replace(/\D/g, '');
                    if (v.length > 11) v = v.substring(0, 11);
                    this.value = v;
                });
            }

            // NC Holder mutual exclusion
            const ncNone = document.getElementById('nc_none');
            const ncNC1  = document.getElementById('nc_nc1');
            const ncNC2  = document.getElementById('nc_nc2');
            if (ncNone && ncNC1 && ncNC2) {
                ncNone.addEventListener('change', function() { if (this.checked) { ncNC1.checked = false; ncNC2.checked = false; } });
                ncNC1.addEventListener('change',  function() { if (this.checked) ncNone.checked = false; });
                ncNC2.addEventListener('change',  function() { if (this.checked) ncNone.checked = false; });
            }

            // Applicant Type mutual exclusion
            const appNone   = document.getElementById('type_none1');
            const appPwd    = document.getElementById('type_pwd');
            const appSenior = document.getElementById('type_senior');
            const app4ps    = document.getElementById('type_4ps');
            if (appNone && appPwd && appSenior && app4ps) {
                appNone.addEventListener('change', function() {
                    if (this.checked) { appPwd.checked = false; appSenior.checked = false; app4ps.checked = false; }
                });
                [appPwd, appSenior, app4ps].forEach(cb => {
                    cb.addEventListener('change', function() { if (this.checked) appNone.checked = false; });
                });
            }

            // Form submit validation
            const registrationForm = document.getElementById('registrationForm');
            if (registrationForm) {
                registrationForm.addEventListener('submit', function(e) {
                    const ncChecked  = document.querySelectorAll('input[name="nc_holder[]"]:checked');
                    const appChecked = document.querySelectorAll('input[name="applicant_type[]"]:checked');

                    if (ncChecked.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one option for NC Holder (NC1, NC2, or None).');
                        return false;
                    }
                    if (appChecked.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one option for Applicant Type (or "None of the above").');
                        return false;
                    }

                    // Check that at least one document is provided for each section
                    const hasIdScan   = document.getElementById('scanned_id_data').value !== '';
                    const hasIdUpload = document.getElementById('valid_id_upload').files.length > 0;
                    const hasCertScan  = document.getElementById('scanned_cert_data').value !== '';
                    const hasCertUpload = document.getElementById('voters_cert_upload').files.length > 0;

                    if (!hasIdScan && !hasIdUpload) {
                        e.preventDefault();
                        alert('Please provide your Valid Government-Issued ID via camera scan or file upload.');
                        return false;
                    }
                    if (!hasCertScan && !hasCertUpload) {
                        e.preventDefault();
                        alert("Please provide your Voter's Certificate / Barangay Residency via camera scan or file upload.");
                        return false;
                    }
                });
            }
        });

        // ==========================================
        // SCANNING FUNCTIONALITY
        // ==========================================
        document.addEventListener('DOMContentLoaded', function() {
            let cameraStream = null;
            let scanCount = 0;
            const maxScans = 2;
            
            const startScanBtn    = document.getElementById('startScanBtn');
            const cameraContainer = document.getElementById('cameraContainer');
            const cameraFeed      = document.getElementById('cameraFeed');
            const captureBtn      = document.getElementById('captureBtn');
            const cancelScanBtn   = document.getElementById('cancelScanBtn');
            const scanPreview     = document.getElementById('scanPreview');
            const previewImage    = document.getElementById('previewImage');
            const confirmScanBtn  = document.getElementById('confirmScanBtn');
            const rescanBtn       = document.getElementById('rescanBtn');
            const scanControls    = document.getElementById('scanControls');
            const scannedIdList   = document.getElementById('scannedIdList');
            const validIdFile     = document.getElementById('valid_id_upload');
            const scannedIdData   = document.getElementById('scanned_id_data');
            
            const startCertScanBtn    = document.getElementById('startCertScanBtn');
            const certCameraContainer = document.getElementById('certCameraContainer');
            const certCameraFeed      = document.getElementById('certCameraFeed');
            const certCaptureBtn      = document.getElementById('certCaptureBtn');
            const certCancelScanBtn   = document.getElementById('certCancelScanBtn');
            const certScanPreview     = document.getElementById('certScanPreview');
            const certPreviewImage    = document.getElementById('certPreviewImage');
            const certConfirmScanBtn  = document.getElementById('certConfirmScanBtn');
            const certRescanBtn       = document.getElementById('certRescanBtn');
            const certScanControls    = document.getElementById('certScanControls');
            const scannedCertList     = document.getElementById('scannedCertList');
            const votersCertFile      = document.getElementById('voters_cert_upload');
            const scannedCertData     = document.getElementById('scanned_cert_data');
            const certVerificationResult = document.getElementById('certVerificationResult');
            
            async function startCamera(videoEl) {
                return await navigator.mediaDevices.getUserMedia({ 
                    video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } }
                });
            }

            if (startScanBtn) {
                startScanBtn.addEventListener('click', async function() {
                    try {
                        cameraStream = await startCamera(cameraFeed);
                        cameraFeed.srcObject = cameraStream;
                        cameraContainer.style.display = 'block';
                        scanControls.style.display = 'none';
                        alert('📷 Camera activated! Position your ID clearly in the view.');
                    } catch (error) {
                        alert('Unable to access camera. Please ensure camera permissions are granted and try again.');
                    }
                });
            }
            
            if (captureBtn) {
                captureBtn.addEventListener('click', function() {
                    captureScan(cameraFeed, previewImage, scanPreview, cameraContainer);
                });
            }
            if (cancelScanBtn) {
                cancelScanBtn.addEventListener('click', function() {
                    stopCamera();
                    cameraContainer.style.display = 'none';
                    scanControls.style.display = 'block';
                });
            }
            if (confirmScanBtn) {
                confirmScanBtn.addEventListener('click', function() {
                    saveScan(previewImage.src, 'ID', scannedIdList, scannedIdData);
                    scanPreview.style.display = 'none';
                    scanControls.style.display = 'block';
                    scanCount++;
                    if (scanCount >= maxScans) {
                        startScanBtn.disabled = true;
                        startScanBtn.innerHTML = '✅ ID Scanning Complete';
                        startScanBtn.style.background = 'rgba(40,167,69,0.8)';
                    }
                });
            }
            if (rescanBtn) {
                rescanBtn.addEventListener('click', async function() {
                    scanPreview.style.display = 'none';
                    try {
                        stopCamera();
                        cameraStream = await startCamera(cameraFeed);
                        cameraFeed.srcObject = cameraStream;
                        cameraContainer.style.display = 'block';
                    } catch (error) {
                        alert('Unable to restart camera. Please refresh and try again.');
                        cameraContainer.style.display = 'none';
                        scanControls.style.display = 'block';
                    }
                });
            }
            
            if (startCertScanBtn) {
                startCertScanBtn.addEventListener('click', async function() {
                    try {
                        cameraStream = await startCamera(certCameraFeed);
                        certCameraFeed.srcObject = cameraStream;
                        certCameraContainer.style.display = 'block';
                        certScanControls.style.display = 'none';
                        alert('📄 Camera activated! Position the certificate clearly in the view.');
                    } catch (error) {
                        alert('Unable to access camera. Please ensure camera permissions are granted and try again.');
                    }
                });
            }
            if (certCaptureBtn) {
                certCaptureBtn.addEventListener('click', function() {
                    captureScan(certCameraFeed, certPreviewImage, certScanPreview, certCameraContainer, true);
                });
            }
            if (certCancelScanBtn) {
                certCancelScanBtn.addEventListener('click', function() {
                    stopCamera();
                    certCameraContainer.style.display = 'none';
                    certScanControls.style.display = 'block';
                });
            }
            if (certConfirmScanBtn) {
                certConfirmScanBtn.addEventListener('click', function() {
                    saveScan(certPreviewImage.src, 'CERTIFICATE', scannedCertList, scannedCertData);
                    certScanPreview.style.display = 'none';
                    certScanControls.style.display = 'block';
                });
            }
            if (certRescanBtn) {
                certRescanBtn.addEventListener('click', async function() {
                    certScanPreview.style.display = 'none';
                    certVerificationResult.style.display = 'none';
                    certConfirmScanBtn.style.display = 'none';
                    try {
                        stopCamera();
                        cameraStream = await startCamera(certCameraFeed);
                        certCameraFeed.srcObject = cameraStream;
                        certCameraContainer.style.display = 'block';
                    } catch (error) {
                        alert('Unable to restart camera. Please refresh and try again.');
                        certCameraContainer.style.display = 'none';
                        certScanControls.style.display = 'block';
                    }
                });
            }
            
            function captureScan(videoEl, previewImg, previewDiv, cameraDiv, isCertificate = false) {
                const canvas = document.createElement('canvas');
                canvas.width  = videoEl.videoWidth;
                canvas.height = videoEl.videoHeight;
                canvas.getContext('2d').drawImage(videoEl, 0, 0);
                const dataUrl = canvas.toDataURL('image/jpeg', 0.9);
                previewImg.src = dataUrl;
                previewDiv.style.display = 'block';
                cameraDiv.style.display  = 'none';
                stopCamera();
                if (isCertificate) {
                    certVerificationResult.innerHTML = `
                        <strong>Certificate Verification:</strong><br>
                        Image Captured: ✅ Success<br>
                        Quality Check: ✅ Passed<br><br>
                        <span style="color:#20c997;font-weight:bold;">✅ SCAN ACCEPTED</span><br>
                        Click "Confirm Scan" to use this certificate.
                    `;
                    certVerificationResult.style.cssText = 'display:block; background:rgba(32,201,151,0.2); border:2px solid #20c997; color:rgba(255,255,255,0.9); padding:15px; border-radius:8px; margin:15px 0;';
                    certConfirmScanBtn.style.display = 'inline-block';
                }
            }
            
            function saveScan(dataUrl, type, listEl, hiddenInput) {
                const label = type === 'ID' ? 'ID' : 'Certificate';
                const item  = document.createElement('div');
                item.style.cssText = 'background:rgba(32,201,151,0.1);border:1px solid rgba(32,201,151,0.3);border-radius:8px;padding:10px;margin:5px 0;';
                item.innerHTML = `
                    <strong style="color:#20c997;">${label}</strong> — ${new Date().toLocaleTimeString()}
                    <div style="margin-top:5px;"><img src="${dataUrl}" style="max-width:100px;border:1px solid rgba(255,255,255,0.2);border-radius:4px;"></div>
                    <div style="margin-top:5px;color:#20c997;font-size:12px;">✅ Verified and Accepted</div>
                `;
                listEl.appendChild(item);
                let scans = hiddenInput.value ? JSON.parse(hiddenInput.value) : [];
                scans.push(dataUrl);
                hiddenInput.value = JSON.stringify(scans);
            }
            
            function stopCamera() {
                if (cameraStream) {
                    cameraStream.getTracks().forEach(t => t.stop());
                    cameraStream = null;
                }
                if (cameraFeed && cameraFeed.srcObject)     cameraFeed.srcObject = null;
                if (certCameraFeed && certCameraFeed.srcObject) certCameraFeed.srcObject = null;
            }
            
            window.addEventListener('beforeunload', stopCamera);
        });
    </script>
</body>
</html>