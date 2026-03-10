<?php
// Start session for consistency
session_start();

// DATABASE CONNECTION
include 'db.php';

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require 'vendor/autoload.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_question'])) {
    $name = $_POST['name'] ?? 'Anonymous';
    $email = $_POST['email'] ?? '';
    $question = $_POST['question'] ?? '';
    $language = $_POST['language'] ?? 'en';
    
    if (!empty($question)) {
        // Sanitize inputs
        $name = htmlspecialchars(strip_tags($name));
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        $question = htmlspecialchars(strip_tags($question));
        
        try {
            // Send email using PHPMailer
            $mail = new PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'lems.superadmn@gmail.com';
            $mail->Password   = 'gubivcizhhkewkda';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            
            // Recipients
            $mail->setFrom('noreply@lems.com', 'LEMS Website');
            $mail->addAddress('lems.superadmn@gmail.com', 'LEMS Admin');
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = "New FAQ Question from LEMS Website";
            
            $mail->Body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px; }
                    .header { color: #2c3e50; text-align: center; margin-bottom: 20px; }
                    .info-box { background-color: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; padding: 15px; margin: 15px 0; }
                    .label { font-weight: bold; color: #2c3e50; }
                    .value { color: #555; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <h2 class='header'>New FAQ Question Submitted</h2>
                    
                    <div class='info-box'>
                        <p><span class='label'>Name:</span> <span class='value'>$name</span></p>
                        <p><span class='label'>Email:</span> <span class='value'>$email</span></p>
                        <p><span class='label'>Language:</span> <span class='value'>$language</span></p>
                        <p><span class='label'>Question:</span></p>
                        <div style='background: white; padding: 15px; border-radius: 5px; border: 1px solid #ddd; margin-top: 10px;'>
                            $question
                        </div>
                    </div>
                    
                    <p><span class='label'>Submitted on:</span> <span class='value'>" . date('F j, Y, g:i a') . "</span></p>
                    <p><span class='label'>IP Address:</span> <span class='value'>" . $_SERVER['REMOTE_ADDR'] . "</span></p>
                </div>
            </body>
            </html>
            ";
            
            $mail->AltBody = "New FAQ Question from LEMS Website\n\nName: $name\nEmail: $email\nLanguage: $language\nQuestion: $question\n\nSubmitted on: " . date('Y-m-d H:i:s');
            
            $mail->send();
            
            $success_message = ($language === 'fil') ? 
                "Salamat! Ang iyong tanong ay ipinadala sa admin." : 
                "Thank you! Your question has been sent to the admin.";
                
        } catch (Exception $e) {
            error_log("Mailer Error: " . $mail->ErrorInfo);
            $error_message = ($language === 'fil') ? 
                "May error sa pagpapadala. Pakisubukan muli." : 
                "Error sending question. Please try again.";
        }
    }
}

// Get language preference
$language = $_GET['lang'] ?? 'en';

// Comprehensive Filipino translations
$filipinoTranslations = [
    // FAQ questions
    "What is LEMS?" => "Ano ang LEMS?",
    "How do I register for a program?" => "Paano ako magrehistro para sa isang programa?",
    "Is there a registration fee?" => "May registration fee ba?",
    "What programs are available?" => "Anong mga programa ang available?",
    "How long are the training programs?" => "Gaano katagal ang mga training programs?",
    "Can I enroll in multiple programs?" => "Maaari ba akong mag-enroll sa maraming programa?",
    "How do I track my progress?" => "Paano ko masusubaybayan ang aking progreso?",
    "What if I miss a session?" => "Paano kung may hindi ako nati-nang session?",
    "Will I get a certificate?" => "Makakatanggap ba ako ng certificate?",
    "How do I contact support?" => "Paano ko makokontak ang support?",
    "Who can enroll in LEMS programs?" => "Sino ang pwedeng mag-enroll sa mga programa ng LEMS?",
    "Are the programs conducted online or face-to-face?" => "Ang mga programa ba ay online o face-to-face?",
    "What are the requirements for enrollment?" => "Ano ang mga requirements para sa enrollment?",
    "Can I cancel my enrollment?" => "Maaari ko bang kanselahin ang aking enrollment?",
    "How are trainers selected?" => "Paano napipili ang mga trainer?",
    
    // FAQ answers
    "LEMS stands for Livelihood Enrollment and Monitoring System. It is a platform designed to help individuals enroll in various livelihood programs and track their progress." => "Ang LEMS ay nangangahulugang Livelihood Enrollment and Monitoring System. Ito ay isang platform na idinisenyo upang tulungan ang mga indibidwal na mag-enroll sa iba't ibang livelihood programs at subaybayan ang kanilang progreso.",
    
    "To register, click on the 'Register' button on the homepage, fill in your personal details, select your preferred program, and submit the form." => "Para magrehistro, pindutin ang 'Register' button sa homepage, punan ang iyong personal na detalye, piliin ang iyong gustong programa, at isumite ang form.",
    
    "No, all livelihood programs offered through LEMS are completely free of charge." => "Hindi, lahat ng livelihood programs na inaalok sa pamamagitan ng LEMS ay ganap na libre.",
    
    "We offer various programs including cooking, baking, sewing, computer literacy, and agricultural training programs." => "Nag-aalok kami ng iba't ibang programa kabilang ang pagluluto, pagbe-bake, pananahi, computer literacy, at mga agricultural training programs.",
    
    "Program durations vary from 2 weeks to 3 months depending on the complexity of the program." => "Ang tagal ng mga programa ay nag-iiba mula 2 linggo hanggang 3 buwan depende sa kumplikado ng programa.",
    
    "Yes, you can enroll in multiple programs as long as their schedules don't conflict with each other." => "Oo, maaari kang mag-enroll sa maraming programa basta't hindi nagtutugma ang kanilang mga iskedyul.",
    
    "Once logged in, you can access your dashboard which shows your program progress, completed modules, and upcoming sessions." => "Kapag nakalog-in na, maaari mong ma-access ang iyong dashboard na nagpapakita ng iyong program progress, nakumpletong modules, at mga darating na session.",
    
    "If you miss a session, you can access the recorded materials through your dashboard or schedule a make-up session with your trainer." => "Kung may hindi kang nati-nang session, maaari mong ma-access ang mga recorded materials sa pamamagitan ng iyong dashboard o mag-iskedyul ng make-up session sa iyong trainer.",
    
    "Yes, upon successful completion of any program, you will receive a certificate of completion." => "Oo, sa matagumpay na pagkumpleto ng anumang programa, makakatanggap ka ng certificate of completion.",
    
    "You can email us at support@lems.gov.ph or call our hotline at (02) 1234-5678 during office hours." => "Maaari kang mag-email sa amin sa support@lems.gov.ph o tumawag sa aming hotline sa (02) 1234-5678 sa oras ng opisina.",
    
    "LEMS programs are open to all Filipino citizens aged 18 and above who are interested in gaining livelihood skills." => "Ang mga programa ng LEMS ay bukas sa lahat ng mamamayang Pilipino na edad 18 pataas na interesadong matuto ng livelihood skills.",
    
    "We offer both online and face-to-face programs depending on the course and your location." => "Nag-aalok kami ng parehong online at face-to-face na mga programa depende sa kursong at sa iyong lokasyon.",
    
    "Basic requirements include: Valid ID, proof of residence, and a completed application form." => "Ang mga pangunahing requirements ay: Valid ID, patunay ng tirahan, at nakumpletong application form.",
    
    "Yes, you can cancel your enrollment up to 3 days before the program starts without any penalty." => "Oo, maaari mong kanselahin ang iyong enrollment hanggang 3 araw bago magsimula ang programa nang walang anumang penalty.",
    
    "Our trainers are carefully selected based on their expertise, teaching experience, and certification in their respective fields." => "Ang aming mga trainer ay maingat na pinipili batay sa kanilang expertise, teaching experience, at certification sa kani-kanilang mga larangan.",
    
    // Page content
    "Frequently Asked Questions" => "Mga Madalas Itanong",
    "Search FAQs..." => "Maghanap ng FAQ...",
    "Showing %visible% of %total% FAQs" => "Ipinapakita ang %visible% ng %total% na FAQ",
    "No FAQs listed." => "Walang FAQ na nakalista.",
    
    // Form translations
    "Have Another Question?" => "May Tanong Ka Pa Ba?",
    "Name (Optional)" => "Pangalan (Opsyonal)",
    "Enter your name" => "Ipasok ang iyong pangalan",
    "Email (Optional)" => "Email (Opsyonal)",
    "If you provide email, we can update you when your question is answered." => "Kung magbibigay ka ng email, maaari naming i-update ka kapag nasagot na ang iyong tanong.",
    "Your Question *" => "Ang Iyong Tanong *",
    "What is your question? You can ask in English or Filipino" => "Ano ang iyong tanong? Maaari kang magtanong sa English o Filipino",
    "Submit Question" => "Ipadala ang Tanong",
    "Your question will be sent to our administrator and may be added to FAQs in the future." => "Ang iyong tanong ay ipapadala sa aming administrator at maaaring idagdag sa mga FAQ sa hinaharap.",
    
    // Language switcher
    "English" => "English",
    "Filipino" => "Filipino",
    
    // Footer
    "© 2025 Livelihood Enrollment and Monitoring System. All Rights Reserved." => "© 2025 Sistema sa Pag-enroll at Pagsubaybay sa Kabuhayan. Lahat ng Karapatan ay Nakalaan.",
    
    // Page title
    "FAQs - LEMS" => "Mga FAQ - LEMS",
    
    // Success/Error messages
    "Thank you! Your question has been sent to the admin." => "Salamat! Ang iyong tanong ay ipinadala sa admin.",
    "Error sending question. Please try again." => "May error sa pagpapadala. Pakisubukan muli.",
];

// Simple translation function
function translate($text, $language, $translations) {
    if ($language === 'fil' && isset($translations[$text])) {
        return $translations[$text];
    }
    return $text;
}

// Get FAQs from database
$faqs = $conn->query("SELECT * FROM faqs ORDER BY id ASC");
$translated_faqs = [];

while ($row = $faqs->fetch_assoc()) {
    $row['question'] = translate($row['question'], $language, $filipinoTranslations);
    $row['answer'] = translate($row['answer'], $language, $filipinoTranslations);
    $translated_faqs[] = $row;
}

// Translate page content
$pageTitle = ($language === 'fil') ? "Mga FAQ - LEMS" : "FAQs - LEMS";
$pageHeading = ($language === 'fil') ? "Mga Madalas Itanong" : "Frequently Asked Questions";
$searchPlaceholder = ($language === 'fil') ? "Maghanap ng FAQ..." : "Search FAQs...";
$formTitle = ($language === 'fil') ? "May Tanong Ka Pa Ba?" : "Have Another Question?";
$nameLabel = ($language === 'fil') ? "Pangalan (Opsyonal)" : "Name (Optional)";
$namePlaceholder = ($language === 'fil') ? "Ipasok ang iyong pangalan" : "Enter your name";
$emailLabel = ($language === 'fil') ? "Email (Opsyonal)" : "Email (Optional)";
$emailNote = ($language === 'fil') ? "Kung magbibigay ka ng email, maaari naming i-update ka kapag nasagot na ang iyong tanong." : "If you provide email, we can update you when your question is answered.";
$questionLabel = ($language === 'fil') ? "Ang Iyong Tanong *" : "Your Question *";
$questionPlaceholder = ($language === 'fil') ? "Ano ang iyong tanong? Maaari kang magtanong sa English o Filipino" : "What is your question? You can ask in English or Filipino";
$submitButton = ($language === 'fil') ? "Ipadala ang Tanong" : "Submit Question";
$formNote = ($language === 'fil') ? "Ang iyong tanong ay ipapadala sa aming administrator at maaaring idagdag sa mga FAQ sa hinaharap." : "Your question will be sent to our administrator and may be added to FAQs in the future.";
$footerText = ($language === 'fil') ? "© 2025 Sistema sa Pag-enroll at Pagsubaybay sa Kabuhayan. Lahat ng Karapatan ay Nakalaan." : "© 2025 Livelihood Enrollment and Monitoring System. All Rights Reserved.";
$noResultsText = ($language === 'fil') ? "Walang FAQ na nakalista." : "No FAQs listed.";
$countPattern = ($language === 'fil') ? "Ipinapakita ang %visible% ng %total% na FAQ" : "Showing %visible% of %total% FAQs";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
    /* ==========================================
       OVERALL BACKGROUND WITH SMBHALL.PNG (Same as index.php)
    ========================================== */
    body {
        margin: 0;
        padding: 0;
        font-family: 'Poppins', sans-serif;
        background-image: url('css/SMBHALL.png');
        background-size: cover;
        background-position: center center;
        background-attachment: fixed;
        background-repeat: no-repeat;
        min-height: 100vh;
        color: white;
    }

    /* Optional overlay to improve text readability */
    .faq-page {
        min-height: 100vh;
        background: rgba(28, 42, 58, 0.85);
    }

    /* ==========================================
       NAVBAR STYLES (Same as index.php)
    ========================================== */
    .top-nav {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 2rem;
        background: rgba(28, 42, 58, 0.9);
        backdrop-filter: blur(10px);
        position: sticky;
        top: 0;
        z-index: 1000;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .left-section {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .logo {
        width: 50px;
        height: 50px;
        border-radius: 8px;
    }

    .title {
        font-size: 1.5rem;
        font-weight: 600;
        color: white;
    }

    .desktop-title {
        display: block;
    }

    .mobile-title {
        display: none;
        color: white;
    }

    .burger-btn {
        display: none;
        background: none;
        border: none;
        color: white;
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0.5rem;
        z-index: 1001;
    }

    .right-section {
        display: flex;
        gap: 2rem;
        align-items: center;
    }

    .nav-link {
        color: white;
        text-decoration: none;
        font-weight: 500;
        padding: 0.5rem 1rem;
        border-radius: 5px;
        transition: background 0.3s;
    }

    .nav-link:hover {
        background: rgba(255, 255, 255, 0.1);
    }

    /* ==========================================
       MOBILE MENU (Same as index.php)
    ========================================== */
    .mobile-menu {
        display: none;
        flex-direction: column;
        background: rgba(28, 42, 58, 0.98);
        backdrop-filter: blur(15px);
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 999;
        padding-top: 70px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        max-height: 100vh;
        overflow-y: auto;
    }

    .mobile-menu.active {
        display: flex;
        animation: slideDown 0.3s ease forwards;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .mobile-menu .nav-link {
        padding: 1.2rem 2rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        font-size: 1.1rem;
        text-align: left;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        gap: 1rem;
        color: white !important;
        text-decoration: none;
        font-weight: 500;
    }

    .mobile-menu .nav-link:last-child {
        border-bottom: none;
    }

    .mobile-menu .nav-link:hover {
        background: rgba(255, 255, 255, 0.1);
        padding-left: 2.5rem;
        color: white !important;
    }

    .mobile-menu .nav-link i {
        width: 20px;
        text-align: center;
        font-size: 1.2rem;
        color: white !important;
    }

    /* ==========================================
       FAQ CONTENT STYLES
    ========================================== */
    .content {
        padding: 3rem 2rem;
        max-width: 1200px;
        margin: 0 auto;
    }

    .language-switcher {
        display: flex;
        gap: 10px;
        margin-bottom: 30px;
        justify-content: center;
    }
    
    .lang-btn {
        padding: 10px 25px;
        border: 2px solid white;
        background: rgba(255, 255, 255, 0.1);
        color: white;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s;
        font-weight: 600;
        backdrop-filter: blur(5px);
    }
    
    .lang-btn.active {
        background: white;
        color: #2c3e50;
    }
    
    .lang-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    }
    
    .heading {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 2rem;
        text-align: center;
        background: linear-gradient(90deg, #20c997, #3b82f6);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }
    
    .language-indicator {
        display: inline-block;
        padding: 3px 15px;
        background: <?php echo ($language === 'fil') ? '#e74c3c' : '#3498db'; ?>;
        color: white;
        border-radius: 20px;
        font-size: 14px;
        margin-left: 15px;
        vertical-align: middle;
        font-weight: normal;
    }

    .search-box {
        margin: 30px auto;
        max-width: 600px;
    }
    
    .search-input {
        width: 100%;
        padding: 15px 25px;
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-radius: 50px;
        font-size: 16px;
        transition: all 0.3s;
        background: rgba(255, 255, 255, 0.1);
        color: white;
        backdrop-filter: blur(5px);
    }
    
    .search-input:focus {
        outline: none;
        border-color: #20c997;
        box-shadow: 0 0 20px rgba(32, 201, 151, 0.3);
        background: rgba(255, 255, 255, 0.15);
    }

    .search-input::placeholder {
        color: rgba(255, 255, 255, 0.7);
    }
    
    .faq-count {
        text-align: center;
        color: rgba(255, 255, 255, 0.9);
        margin: 15px 0 40px;
        font-weight: bold;
        font-size: 1.1rem;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }
    
    .faq-list {
        max-width: 900px;
        margin: 0 auto 50px;
    }
    
    .faq-item {
        margin-bottom: 20px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 15px;
        overflow: hidden;
        transition: all 0.3s;
        background: rgba(255, 255, 255, 0.05);
        backdrop-filter: blur(10px);
    }
    
    .faq-item:hover {
        border-color: #20c997;
        box-shadow: 0 10px 25px rgba(32, 201, 151, 0.2);
        transform: translateY(-5px);
    }
    
    .faq-question {
        width: 100%;
        padding: 25px;
        text-align: left;
        background: rgba(255, 255, 255, 0.1);
        border: none;
        font-size: 18px;
        font-weight: bold;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: background 0.3s;
        color: white;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }
    
    .faq-question:hover {
        background: rgba(255, 255, 255, 0.15);
    }
    
    .faq-answer {
        padding: 0 25px;
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.5s ease-out, padding 0.3s;
        color: rgba(255, 255, 255, 0.9);
        line-height: 1.6;
    }
    
    .faq-answer.active {
        padding: 25px;
        max-height: 1000px;
    }
    
    .arrow {
        transition: transform 0.3s;
        font-size: 16px;
        color: #20c997;
    }
    
    .arrow.active {
        transform: rotate(180deg);
    }
    
    /* ==========================================
       QUESTION FORM STYLES
    ========================================== */
    .question-form {
        background: rgba(255, 255, 255, 0.1);
        padding: 40px;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        margin: 60px auto;
        max-width: 700px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
    }
    
    .form-title {
        color: white;
        margin-bottom: 30px;
        text-align: center;
        font-size: 1.8rem;
        font-weight: 600;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }
    
    .form-group {
        margin-bottom: 25px;
    }
    
    .form-label {
        display: block;
        margin-bottom: 10px;
        font-weight: bold;
        color: white;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }
    
    .form-input, .form-textarea {
        width: 100%;
        padding: 15px;
        border: 2px solid rgba(255, 255, 255, 0.2);
        border-radius: 10px;
        font-size: 16px;
        transition: all 0.3s;
        background: rgba(255, 255, 255, 0.1);
        color: white;
        backdrop-filter: blur(5px);
    }
    
    .form-input:focus, .form-textarea:focus {
        outline: none;
        border-color: #20c997;
        box-shadow: 0 0 15px rgba(32, 201, 151, 0.3);
        background: rgba(255, 255, 255, 0.15);
    }
    
    .form-textarea {
        min-height: 150px;
        resize: vertical;
    }
    
    .form-input::placeholder,
    .form-textarea::placeholder {
        color: rgba(255, 255, 255, 0.6);
    }
    
    .submit-btn {
        background: linear-gradient(90deg, #20c997, #17a589);
        color: white;
        padding: 16px 40px;
        border: none;
        border-radius: 10px;
        font-size: 18px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        display: block;
        margin: 30px auto 0;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        box-shadow: 0 5px 15px rgba(32, 201, 151, 0.3);
    }
    
    .submit-btn:hover {
        background: linear-gradient(90deg, #17a589, #20c997);
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(32, 201, 151, 0.4);
    }
    
    .optional-note {
        font-size: 14px;
        color: rgba(255, 255, 255, 0.7);
        font-style: italic;
        margin-top: 8px;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    }
    
    .message {
        padding: 20px;
        border-radius: 10px;
        margin: 30px 0;
        text-align: center;
        animation: fadeIn 0.5s;
        font-weight: 500;
    }
    
    .success {
        background: rgba(212, 237, 218, 0.2);
        color: #d4edda;
        border: 2px solid #20c997;
        backdrop-filter: blur(5px);
    }
    
    .error {
        background: rgba(248, 215, 218, 0.2);
        color: #f8d7da;
        border: 2px solid #e74c3c;
        backdrop-filter: blur(5px);
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .no-results {
        text-align: center;
        padding: 60px;
        color: rgba(255, 255, 255, 0.7);
        font-size: 18px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 15px;
        border: 2px dashed rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(5px);
    }

    /* ==========================================
       FOOTER (Same as index.php)
    ========================================== */
    .footer {
        text-align: center;
        padding: 2rem;
        background: rgba(0, 0, 0, 0.5);
        font-size: 0.9rem;
        color: rgba(255, 255, 255, 0.7);
        backdrop-filter: blur(5px);
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    /* ==========================================
       RESPONSIVE STYLES (Same as index.php)
    ========================================== */
    @media (max-width: 768px) {
        .desktop-title {
            display: none;
        }
        
        .mobile-title {
            display: block;
            color: white;
        }
        
        .burger-btn {
            display: block;
            color: white;
        }
        
        .right-section {
            display: none;
        }
        
        .heading {
            font-size: 2rem;
        }
        
        .content {
            padding: 2rem 1rem;
        }
        
        .question-form {
            padding: 25px;
            margin: 40px 15px;
        }
        
        .faq-question {
            padding: 20px;
            font-size: 16px;
        }
        
        .top-nav {
            padding: 1rem;
        }
        
        .logo {
            width: 40px;
            height: 40px;
        }
        
        .title {
            font-size: 1.2rem;
            color: white;
        }
        
        /* Mobile menu takes full height */
        .mobile-menu {
            padding-top: 80px;
            height: calc(100vh - 80px);
        }
        
        /* Ensure mobile menu text is white */
        .mobile-menu .nav-link {
            color: white !important;
            font-weight: 500;
        }
        
        .mobile-menu .nav-link i {
            color: white !important;
        }
        
        body {
            background-attachment: scroll;
        }
    }

    @media (max-width: 480px) {
        .heading {
            font-size: 1.6rem;
        }
        
        .language-switcher {
            flex-direction: column;
            align-items: center;
        }
        
        .lang-btn {
            width: 200px;
        }
        
        .question-form {
            padding: 20px;
        }
        
        .submit-btn {
            width: 100%;
            padding: 15px;
        }
        
        .mobile-menu .nav-link {
            padding: 1.2rem 1.5rem;
            font-size: 1rem;
            color: white !important;
        }
        
        .mobile-menu .nav-link:hover {
            padding-left: 2rem;
            color: white !important;
        }
        
        .mobile-menu .nav-link i {
            color: white !important;
        }
    }

    /* Animation for FAQ items when they appear */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .faq-item {
        animation: fadeInUp 0.6s ease forwards;
        opacity: 0;
    }

    .faq-item:nth-child(1) { animation-delay: 0.1s; }
    .faq-item:nth-child(2) { animation-delay: 0.2s; }
    .faq-item:nth-child(3) { animation-delay: 0.3s; }
    .faq-item:nth-child(4) { animation-delay: 0.4s; }
    .faq-item:nth-child(5) { animation-delay: 0.5s; }
    .faq-item:nth-child(6) { animation-delay: 0.6s; }
    .faq-item:nth-child(7) { animation-delay: 0.7s; }
    .faq-item:nth-child(8) { animation-delay: 0.8s; }
    .faq-item:nth-child(9) { animation-delay: 0.9s; }
    .faq-item:nth-child(10) { animation-delay: 1s; }
    
    /* ==========================================
       DEBUG STYLES (Optional)
    ========================================== */
    .debug-badge {
        position: fixed;
        top: 10px;
        right: 10px;
        background: rgba(0, 0, 0, 0.8);
        color: white;
        padding: 5px 10px;
        border-radius: 5px;
        font-size: 12px;
        z-index: 9999;
        display: none; /* Change to 'block' to see debug info */
        backdrop-filter: blur(5px);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }
    </style>
</head>
<body>
    <!-- DEBUG BADGE (Optional) -->
    <div class="debug-badge">
        Language: <?php echo strtoupper($language); ?> | FAQs: <?php echo count($translated_faqs); ?>
    </div>

    <div class="faq-page">

        <!-- TOP NAVBAR (Same as index.php) -->
        <div class="top-nav">
            <!-- LEFT SECTION -->
            <div class="left-section">
                <img src="/css/logo.png" alt="Logo" class="logo">
                <h1 class="title" title="Livelihood Enrollment & Monitoring System">
                    <span class="desktop-title">Livelihood Enrollment & Monitoring System</span>
                    <span class="mobile-title">LEMS</span>
                </h1>
            </div>

            <!-- BURGER BUTTON (mobile only) -->
            <button class="burger-btn" id="burgerBtn" aria-label="Toggle menu">
                <i class="fas fa-bars"></i>
            </button>

            <!-- DESKTOP NAV -->
            <nav class="right-section">
                <a href="index.php" class="nav-link">Home</a>
                <a href="about.php" class="nav-link">About</a>
                <a href="faqs.php" class="nav-link">FAQs</a>
                <a href="login.php" class="nav-link">Login</a>
            </nav>
        </div>

        <!-- MOBILE MENU DROPDOWN -->
        <div class="mobile-menu" id="mobileMenu">
            <a href="index.php" class="nav-link">
                <i class="fas fa-home"></i> Home
            </a>
            <a href="about.php" class="nav-link">
                <i class="fas fa-info-circle"></i> About
            </a>
            <a href="faqs.php" class="nav-link">
                <i class="fas fa-question-circle"></i> FAQs
            </a>
            <a href="login.php" class="nav-link">
                <i class="fas fa-sign-in-alt"></i> Login
            </a>
        </div>

        <!-- CONTENT -->
        <section class="content">
            <!-- Language Switcher -->
            <div class="language-switcher">
                <button class="lang-btn <?php echo ($language === 'en') ? 'active' : ''; ?>" onclick="switchLanguage('en')">
                    English
                </button>
                <button class="lang-btn <?php echo ($language === 'fil') ? 'active' : ''; ?>" onclick="switchLanguage('fil')">
                    Filipino
                </button>
            </div>
            
            <h2 class="heading">
                <?php echo $pageHeading; ?>
                <span class="language-indicator">
                    <?php echo ($language === 'fil') ? 'Filipino' : 'English'; ?>
                </span>
            </h2>
            
            <!-- Search Box -->
            <div class="search-box">
                <input type="text" 
                       class="search-input" 
                       id="searchInput" 
                       placeholder="<?php echo $searchPlaceholder; ?>"
                       onkeyup="searchFAQs()">
            </div>
            
            <!-- FAQ Count -->
            <div class="faq-count" id="faqCount"></div>
            
            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
                <div class="message success"><?php echo $success_message; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="message error"><?php echo $error_message; ?></div>
            <?php endif; ?>
            
            <!-- FAQ List -->
            <div class="faq-list" id="faqList">
                <?php if (count($translated_faqs) > 0): ?>
                    <?php foreach ($translated_faqs as $row): ?>
                    <div class="faq-item" data-question="<?php echo strtolower(htmlspecialchars($row['question'])); ?>">
                        <button class="faq-question" onclick="toggleFaq(this)">
                            <?php echo htmlspecialchars($row['question']); ?>
                            <span class="arrow">▼</span>
                        </button>
                        <div class="faq-answer">
                            <?php echo nl2br(htmlspecialchars($row['answer'])); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-results">
                        <?php echo $noResultsText; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Ask Question Form -->
            <div class="question-form">
                <h3 class="form-title">
                    <?php echo $formTitle; ?>
                </h3>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label">
                            <?php echo $nameLabel; ?>
                        </label>
                        <input type="text" 
                               name="name" 
                               class="form-input" 
                               placeholder="<?php echo $namePlaceholder; ?>"
                               maxlength="100">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <?php echo $emailLabel; ?>
                        </label>
                        <input type="email" 
                               name="email" 
                               class="form-input" 
                               placeholder="you@example.com"
                               maxlength="100">
                        <div class="optional-note">
                            <?php echo $emailNote; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <?php echo $questionLabel; ?>
                        </label>
                        <textarea name="question" 
                                  class="form-textarea" 
                                  placeholder="<?php echo $questionPlaceholder; ?>"
                                  required
                                  maxlength="1000"></textarea>
                    </div>
                    
                    <input type="hidden" name="language" value="<?php echo $language; ?>">
                    
                    <button type="submit" 
                            name="submit_question" 
                            class="submit-btn">
                        <?php echo $submitButton; ?>
                    </button>
                </form>
                
                <div class="optional-note" style="margin-top: 25px; text-align: center; font-size: 15px;">
                    <?php echo $formNote; ?>
                </div>
            </div>
        </section>

        <!-- FOOTER -->
        <footer class="footer">
            <?php echo $footerText; ?>
        </footer>
    </div>

    <script>
    // ==========================================
    // MOBILE MENU FUNCTIONALITY (Same as index.php)
    // ==========================================
    const burgerBtn = document.getElementById('burgerBtn');
    const mobileMenu = document.getElementById('mobileMenu');
    const body = document.body;

    if (burgerBtn && mobileMenu) {
        burgerBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            mobileMenu.classList.toggle('active');
            body.classList.toggle('menu-open');
            
            // Change burger icon to X when menu is open
            const icon = burgerBtn.querySelector('i');
            if (mobileMenu.classList.contains('active')) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-times');
            } else {
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        });

        // Close mobile menu when clicking outside
        document.addEventListener('click', (e) => {
            if (!burgerBtn.contains(e.target) && !mobileMenu.contains(e.target)) {
                mobileMenu.classList.remove('active');
                body.classList.remove('menu-open');
                
                // Reset burger icon
                const icon = burgerBtn.querySelector('i');
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        });

        // Close mobile menu when clicking a link
        const mobileLinks = mobileMenu.querySelectorAll('.nav-link');
        mobileLinks.forEach(link => {
            link.addEventListener('click', () => {
                mobileMenu.classList.remove('active');
                body.classList.remove('menu-open');
                
                // Reset burger icon
                const icon = burgerBtn.querySelector('i');
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            });
        });

        // Close menu with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && mobileMenu.classList.contains('active')) {
                mobileMenu.classList.remove('active');
                body.classList.remove('menu-open');
                
                // Reset burger icon
                const icon = burgerBtn.querySelector('i');
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        });
    }

    // Prevent body scroll when menu is open
    function toggleBodyScroll(disable) {
        if (disable) {
            body.style.overflow = 'hidden';
        } else {
            body.style.overflow = '';
        }
    }

    // Observe mobile menu for changes
    if (mobileMenu) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    toggleBodyScroll(mobileMenu.classList.contains('active'));
                }
            });
        });
        
        observer.observe(mobileMenu, { attributes: true });
    }

    // ==========================================
    // FAQ FUNCTIONALITY
    // ==========================================
    
    // Store translations for JavaScript
    const currentLanguage = '<?php echo $language; ?>';
    const countPattern = '<?php echo addslashes($countPattern); ?>';
    
    // Toggle FAQ answers with animation
    function toggleFaq(button) {
        const answer = button.nextElementSibling;
        const arrow = button.querySelector(".arrow");
        const isActive = answer.classList.contains("active");
        
        // Close all other FAQs
        document.querySelectorAll('.faq-answer.active').forEach(item => {
            if (item !== answer) {
                item.classList.remove('active');
                item.previousElementSibling.querySelector('.arrow').classList.remove('active');
            }
        });
        
        // Toggle current FAQ
        answer.classList.toggle('active');
        arrow.classList.toggle('active');
        
        // Scroll into view if opening
        if (!isActive) {
            setTimeout(() => {
                button.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 300);
        }
    }
    
    // Search FAQs
    function searchFAQs() {
        const input = document.getElementById('searchInput').value.toLowerCase();
        const faqItems = document.querySelectorAll('.faq-item');
        let visibleCount = 0;
        
        faqItems.forEach(item => {
            const question = item.getAttribute('data-question');
            const answer = item.querySelector('.faq-answer').textContent.toLowerCase();
            
            if (question.includes(input) || answer.includes(input)) {
                item.style.display = 'block';
                visibleCount++;
                
                // Highlight search terms
                if (input.length > 2) {
                    highlightText(item, input);
                }
            } else {
                item.style.display = 'none';
            }
        });
        
        // Update count with translated pattern
        const totalFaqs = faqItems.length;
        const countText = countPattern
            .replace('%visible%', visibleCount)
            .replace('%total%', totalFaqs);
        document.getElementById('faqCount').textContent = countText;
    }
    
    // Highlight search terms
    function highlightText(element, searchTerm) {
        const text = element.innerHTML;
        const regex = new RegExp(`(${searchTerm})`, 'gi');
        element.innerHTML = text.replace(regex, '<span style="background-color: rgba(255, 243, 205, 0.5); padding: 2px; border-radius: 3px;">$1</span>');
    }
    
    // Switch language
    function switchLanguage(lang) {
        window.location.href = `faqs.php?lang=${lang}`;
    }
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize FAQ count
        const faqItems = document.querySelectorAll('.faq-item');
        const totalFaqs = faqItems.length;
        const countText = countPattern
            .replace('%visible%', totalFaqs)
            .replace('%total%', totalFaqs);
        document.getElementById('faqCount').textContent = countText;
        
        // Check for URL hash and open corresponding FAQ
        if (window.location.hash) {
            const faqId = window.location.hash.substring(1);
            const targetFaq = document.querySelector(`[data-question*="${faqId.toLowerCase()}"]`);
            if (targetFaq) {
                const button = targetFaq.querySelector('.faq-question');
                toggleFaq(button);
                
                // Scroll to the FAQ
                setTimeout(() => {
                    targetFaq.scrollIntoView({ behavior: 'smooth' });
                }, 500);
            }
        }
        
        // Auto-hide success/error messages after 5 seconds
        const messages = document.querySelectorAll('.message');
        messages.forEach(message => {
            setTimeout(() => {
                message.style.opacity = '0';
                message.style.transform = 'translateY(-10px)';
                setTimeout(() => {
                    if (message.parentNode) {
                        message.parentNode.removeChild(message);
                    }
                }, 500);
            }, 5000);
        });
    });

    // ==========================================
    // DEBUG: Show debug info on Ctrl+D
    // ==========================================
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'd') {
            e.preventDefault();
            const debugInfo = document.querySelector('.debug-badge');
            debugInfo.style.display = debugInfo.style.display === 'block' ? 'none' : 'block';
        }
    });
    </script>
</body>
</html>