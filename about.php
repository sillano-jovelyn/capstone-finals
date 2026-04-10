<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About - Livelihood Enrollment & Monitoring System (LEMS)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ==========================================
           MAIN BACKGROUND & LAYOUT - EXACTLY LIKE INDEX.PHP
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

        .about-page {
            min-height: 100vh;
            background: rgba(28, 42, 58, 0.85); /* Dark blue overlay with transparency */
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        /* ==========================================
           NAVIGATION STYLES FROM INDEX.PHP (IDENTICAL)
        ========================================== */
        .top-nav {
             display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 2rem;
            background: rgba(28, 42, 58, 0.85);
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

        .nav-link.active {
            background: rgba(255, 255, 255, 0.2);
            font-weight: 600;
        }

        /* ==========================================
           MOBILE MENU - IDENTICAL TO INDEX.PHP
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
            color: white;
            text-decoration: none;
            font-weight: 500;
        }

        .mobile-menu .nav-link:last-child {
            border-bottom: none;
        }

        .mobile-menu .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            padding-left: 2.5rem;
        }

        .mobile-menu .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 1.2rem;
        }

        /* ==========================================
           HERO SECTION - UPDATED (PEOPLE BACKGROUND REMOVED)
        ========================================== */
        .hero-section {
            background: linear-gradient(135deg, rgba(28, 42, 58, 0.95), rgba(43, 59, 76, 0.95));
            color: white;
            padding: 4rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
            min-height: 60vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            border-bottom: 5px solid #20c997;
        }

        .hero-content {
            max-width: 900px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }

        .hero-section h1 {
            font-size: 2.8rem;
            margin-bottom: 1.5rem;
            font-weight: 700;
            line-height: 1.3;
            text-shadow: 2px 2px 6px rgba(0, 0, 0, 0.5);
            color: #ffffff;
        }

        .hero-section .subtitle {
            font-size: 1.3rem;
            max-width: 700px;
            margin: 0 auto 2.5rem;
            opacity: 0.95;
            line-height: 1.6;
            color: #e6f2ff;
            font-weight: 400;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5);
        }

        .content-wrapper {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            flex: 1;
        }

        /* ==========================================
           IMPROVED TEXT READABILITY
        ========================================== */
        .hero-content h1,
        .hero-content .subtitle,
        .section-title,
        .section-subtitle,
        .intro-content p,
        .feature-block p,
        .benefit-card p,
        .user-card p,
        .step-content p,
        .tip-content p {
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5) !important;
        }

        /* Increase font weights for better contrast */
        .intro-content p,
        .feature-block p,
        .benefit-card p,
        .user-card p,
        .step-content p,
        .tip-content p {
            font-weight: 400 !important;
            line-height: 1.7 !important;
        }

        /* ==========================================
           SECTION STYLING - IMPROVED READABILITY
        ========================================== */
        .section {
            margin: 4rem 0;
            padding: 2.5rem 0;
            position: relative;
            background: rgba(255, 255, 255, 0.07);
            border-radius: 15px;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .section-header {
            text-align: center;
            margin-bottom: 3rem;
        }

        .section-title {
            font-size: 2.2rem;
            color: #ffffff;
            margin-bottom: 1rem;
            position: relative;
            display: inline-block;
            font-weight: 700;
        }

        .section-title:after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: #20c997;
            border-radius: 2px;
        }

        .section-subtitle {
            font-size: 1.2rem;
            color: #cccccc;
            max-width: 800px;
            margin: 0 auto;
            line-height: 1.6;
            font-weight: 300;
        }

        /* ==========================================
           INTRODUCTION SECTION - ENHANCED READABILITY
        ========================================== */
        .intro-content {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 15px;
            padding: 2.5rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            margin-bottom: 4rem;
            line-height: 1.8;
            font-size: 1.15rem;
            color: #e6e6e6;
            border-left: 5px solid #20c997;
        }

        .intro-content p {
            margin-bottom: 1.5rem;
            font-weight: 300;
        }

        .intro-highlight {
            background: linear-gradient(120deg, rgba(32, 201, 151, 0.15) 0%, rgba(32, 201, 151, 0.05) 100%);
            border-left: 5px solid #20c997;
            padding: 2rem;
            border-radius: 0 10px 10px 0;
            margin: 2rem 0;
            color: #ffffff;
        }

        .intro-highlight h3 {
            color: #20c997;
            font-size: 1.4rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        /* ==========================================
           IMPROVED EASY READ TIPS
        ========================================== */
        .easy-read-tips {
            background: rgba(255, 255, 255, 0.12);
            border-radius: 15px;
            padding: 2.5rem;
            margin: 3rem 0;
            border: 2px solid rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .easy-read-tips h3 {
            color: #20c997;
            font-size: 1.8rem;
            margin-bottom: 2rem;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.8rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .tips-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .tip-card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .tip-card:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
        }

        .tip-icon {
            width: 60px;
            height: 60px;
            background: rgba(32, 201, 151, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .tip-icon i {
            font-size: 1.5rem;
            color: #20c997;
        }

        .tip-content h4 {
            color: #ffffff;
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .tip-content p {
            color: #e6e6e6;
            font-size: 0.95rem;
            line-height: 1.5;
            margin: 0;
        }

        /* ==========================================
           SYSTEM ARCHITECTURE - FUN CARDS
        ========================================== */
        .architecture-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            margin-top: 3rem;
        }

        .arch-card {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border-top: 5px solid #20c997;
            position: relative;
            overflow: hidden;
            color: #ffffff;
        }

        .arch-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 15px 40px rgba(32, 201, 151, 0.2);
            background: rgba(255, 255, 255, 0.15);
        }

        .arch-card h3 {
            color: #20c997;
            font-size: 1.3rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-weight: 600;
        }

        .arch-card p {
            font-size: 1rem;
            line-height: 1.6;
            color: #e6e6e6;
        }

        .arch-card .number {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 2.5rem;
            font-weight: 800;
            color: rgba(32, 201, 151, 0.15);
        }

        /* ==========================================
           FEATURES DETAIL - EASY TO READ
        ========================================== */
        .features-detail {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 2rem;
            margin-top: 3rem;
        }

        .feature-block {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            color: #ffffff;
            transition: all 0.3s ease;
        }

        .feature-block:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.15);
        }

        .feature-block h3 {
            color: #20c997;
            font-size: 1.25rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-weight: 600;
        }

        .feature-block ul {
            list-style: none;
            padding: 0;
            margin: 1.5rem 0;
        }

        .feature-block ul li {
            padding: 0.8rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            font-size: 1rem;
            color: #e6e6e6;
        }

        .feature-block ul li:before {
            content: "✓";
            color: #20c997;
            font-weight: bold;
            margin-top: 0.2rem;
            font-size: 1.1rem;
        }

        /* ==========================================
           BENEFITS SECTION - FUN & ENGAGING
        ========================================== */
        .benefits-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            margin-top: 3rem;
        }

        .benefit-card {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            color: #ffffff;
            border: 2px solid transparent;
        }

        .benefit-card:hover {
            transform: translateY(-8px) rotate(1deg);
            box-shadow: 0 15px 40px rgba(32, 201, 151, 0.25);
            border-color: #20c997;
            background: rgba(255, 255, 255, 0.15);
        }

        .benefit-icon {
            font-size: 3rem;
            color: #20c997;
            margin-bottom: 1.5rem;
            display: inline-block;
            transition: transform 0.3s ease;
        }

        .benefit-card:hover .benefit-icon {
            transform: scale(1.2);
        }

        .benefit-card h3 {
            font-size: 1.3rem;
            margin-bottom: 1rem;
            color: #ffffff;
            font-weight: 600;
        }

        .benefit-card p {
            font-size: 1rem;
            line-height: 1.6;
            color: #e6e6e6;
        }

        /* ==========================================
           PROCESS FLOW - VISUAL & CLEAR
        ========================================== */
        .process-flow {
            position: relative;
            padding: 2rem 0;
            margin-top: 3rem;
        }

        .process-step {
            display: flex;
            align-items: center;
            margin-bottom: 2.5rem;
            position: relative;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .process-step:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateX(10px);
        }

        .step-number {
            width: 50px;
            height: 50px;
            background: #20c997;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            font-weight: bold;
            margin-right: 1.5rem;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(32, 201, 151, 0.3);
        }

        .step-content {
            flex: 1;
        }

        .step-content h4 {
            color: #20c997;
            margin-bottom: 0.5rem;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .step-content p {
            color: #e6e6e6;
            line-height: 1.6;
            font-size: 1rem;
        }

        /* ==========================================
           TARGET USERS - FRIENDLY CARDS
        ========================================== */
        .user-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-top: 3rem;
        }

        .user-card {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            color: #ffffff;
        }

        .user-card:hover {
            transform: translateY(-8px) scale(1.03);
            box-shadow: 0 15px 40px rgba(32, 201, 151, 0.2);
            background: rgba(255, 255, 255, 0.15);
        }

        .user-avatar {
            width: 80px;
            height: 80px;
            background: rgba(32, 201, 151, 0.2);
            border-radius: 50%;
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #20c997;
            border: 3px solid rgba(32, 201, 151, 0.3);
            transition: all 0.3s ease;
        }

        .user-card:hover .user-avatar {
            background: rgba(32, 201, 151, 0.3);
            border-color: #20c997;
            transform: scale(1.1);
        }

        .user-card h3 {
            font-size: 1.3rem;
            margin-bottom: 1rem;
            color: #ffffff;
            font-weight: 600;
        }

        .user-card p {
            font-size: 1rem;
            line-height: 1.6;
            color: #e6e6e6;
        }

        /* ==========================================
           FUN FACTS SECTION
        ========================================== */
        .fun-facts {
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 2rem;
            margin: 4rem 0;
            padding: 3rem;
            background: linear-gradient(135deg, rgba(32, 201, 151, 0.2), rgba(59, 130, 246, 0.2));
            border-radius: 15px;
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }

        .fact-item {
            text-align: center;
            padding: 1rem;
            flex: 1;
            min-width: 200px;
        }

        .fact-number {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            color: #20c997;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .fact-label {
            font-size: 1.1rem;
            opacity: 0.95;
            font-weight: 500;
        }

        /* ==========================================
           CTA SECTION
        ========================================== */
        .cta-section {
            text-align: center;
            padding: 4rem 2rem;
            background: linear-gradient(135deg, rgba(32, 201, 151, 0.9), rgba(59, 130, 246, 0.9));
            color: white;
            border-radius: 15px;
            margin: 4rem 0;
            box-shadow: 0 10px 40px rgba(32, 201, 151, 0.3);
        }

        .cta-section h2 {
            font-size: 2.2rem;
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .cta-section p {
            font-size: 1.2rem;
            max-width: 700px;
            margin: 0 auto 2rem;
            opacity: 0.95;
            line-height: 1.6;
        }

        .cta-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 1rem 2.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-block;
            font-size: 1.1rem;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: #20c997;
            color: white;
            box-shadow: 0 4px 15px rgba(32, 201, 151, 0.3);
        }

        .btn-primary:hover {
            background: #1daa7c;
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 8px 25px rgba(32, 201, 151, 0.4);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid white;
            color: white;
        }

        .btn-secondary:hover {
            background: white;
            color: #20c997;
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(255, 255, 255, 0.3);
        }

        /* ==========================================
           FOOTER
        ========================================== */
        .footer {
            text-align: center;
            padding: 2rem;
            background: rgba(0, 0, 0, 0.5);
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(5px);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: 4rem;
        }

        /* ==========================================
           RESPONSIVE DESIGN (IDENTICAL TO INDEX.PHP)
        ========================================== */
        @media (max-width: 768px) {
            /* Navigation responsive */
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
            
            .mobile-menu {
                padding-top: 80px;
                height: calc(100vh - 80px);
            }

            /* Content responsive */
            .hero-section {
                padding: 3rem 1rem;
                min-height: 50vh;
            }
            
            .hero-section h1 {
                font-size: 2rem;
            }
            
            .hero-section .subtitle {
                font-size: 1.1rem;
            }
            
            .content-wrapper {
                padding: 0 1rem;
            }
            
            .section-title {
                font-size: 1.8rem;
            }
            
            .section-subtitle {
                font-size: 1rem;
            }
            
            .intro-content {
                padding: 1.5rem;
                font-size: 1.05rem;
            }
            
            .architecture-grid,
            .features-detail,
            .benefits-container,
            .user-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
            
            .process-step {
                flex-direction: column;
                text-align: center;
                padding: 1.5rem;
            }
            
            .step-number {
                margin-right: 0;
                margin-bottom: 1rem;
            }
            
            .fun-facts {
                flex-direction: column;
                align-items: center;
                text-align: center;
                padding: 2rem 1rem;
            }
            
            .fact-item {
                min-width: 100%;
            }
            
            .cta-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                max-width: 300px;
                text-align: center;
            }
            
            .easy-read-tips {
                padding: 1.5rem;
            }
            
            .tips-container {
                grid-template-columns: 1fr;
            }
            
            .tip-card {
                flex-direction: column;
                text-align: center;
                padding: 1.2rem;
            }

            /* Ensure background still looks good on mobile */
            body {
                background-attachment: scroll;
            }
        }

        @media (max-width: 480px) {
            .hero-section h1 {
                font-size: 1.8rem;
            }
            
            .hero-section .subtitle {
                font-size: 1rem;
            }
            
            .section-title {
                font-size: 1.6rem;
            }
            
            .section {
                margin: 2.5rem 0;
                padding: 1.5rem 0;
            }
            
            .mobile-menu .nav-link {
                padding: 1.2rem 1.5rem;
                font-size: 1rem;
                color: white;
            }
            
            .mobile-menu .nav-link:hover {
                padding-left: 2rem;
            }
            
            .mobile-menu .nav-link i {
                color: white;
            }
            
            .btn {
                padding: 0.9rem 1.5rem;
                font-size: 1rem;
            }
            
            .cta-section {
                padding: 2.5rem 1rem;
            }
            
            .cta-section h2 {
                font-size: 1.8rem;
            }
            
            .cta-section p {
                font-size: 1rem;
            }
        }

        /* ==========================================
           ANIMATIONS
        ========================================== */
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

        .section,
        .arch-card,
        .feature-block,
        .benefit-card,
        .user-card,
        .process-step {
            animation: fadeInUp 0.6s ease forwards;
            opacity: 0;
        }

        .section:nth-child(1) { animation-delay: 0.1s; }
        .section:nth-child(2) { animation-delay: 0.2s; }
        .section:nth-child(3) { animation-delay: 0.3s; }
        .section:nth-child(4) { animation-delay: 0.4s; }
        .section:nth-child(5) { animation-delay: 0.5s; }
        .section:nth-child(6) { animation-delay: 0.6s; }
        .section:nth-child(7) { animation-delay: 0.7s; }
    </style>
</head>
<body>

<div class="about-page">

    <!-- TOP NAVBAR  -->
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
            <a href="about.php" class="nav-link active">About</a>
            <a href="faqs.php" class="nav-link">FAQs</a>
            <a href="login.php" class="nav-link">Login</a>
        </nav>
    </div>

    <!-- MOBILE MENU DROPDOWN -->
    <div class="mobile-menu" id="mobileMenu">
        <a href="index.php" class="nav-link">
            <i class="fas fa-home"></i> Home
        </a>
        <a href="about.php" class="nav-link active">
            <i class="fas fa-info-circle"></i> About
        </a>
        <a href="faqs.php" class="nav-link">
            <i class="fas fa-question-circle"></i> FAQs
        </a>
        <a href="login.php" class="nav-link">
            <i class="fas fa-sign-in-alt"></i> Login
        </a>
    </div>

    <!-- HERO SECTION -->
    <section class="hero-section">
        <div class="hero-content">
            <h1>Welcome to LEMS! Your Digital Path to Skills & Opportunities</h1>
            <p class="subtitle">A friendly, easy-to-use system that helps our community learn new skills, find opportunities, and grow together. Perfect for everyone - young, old, and everyone in between!</p>
        </div>
    </section>

    <div class="content-wrapper">
        
        
      
        <!-- INTRODUCTION SECTION -->
        <section id="introduction" class="section">
            <div class="section-header">
                <h2 class="section-title">What is LEMS?</h2>
                <p class="section-subtitle">Think of LEMS as your friendly digital helper for learning new skills and finding opportunities!</p>
            </div>
            
            <div class="intro-content">
                <p><strong>Hello there!</strong> We're so glad you're here! LEMS stands for <strong>Livelihood Enrollment and Monitoring System</strong>. But don't let the big name fool you - it's actually very simple and easy to use!</p>
                
                <p>Imagine having a helpful friend who keeps track of all the training programs in our town. A friend who reminds you about classes, helps you sign up easily, and keeps all your certificates safe. That's what LEMS does! </p>
                
                <div class="intro-highlight">
                    <h3><i class="fas fa-heart"></i> Why We Created LEMS</h3>
                    <p>We noticed that finding and joining training programs could be confusing. Long forms, unclear schedules, and lost certificates were common problems. So we thought: "Let's make this easier for everyone!" And LEMS was born! </p>
                </div>
                
                <p>Whether you want to learn cooking, sewing, computer skills, or gardening, LEMS helps you find the right program, sign up easily, and track your progress. It's like having a personal assistant for your learning journey!</p>
                
                <p><strong>Best part?</strong> It works on computers, tablets, and phones! So you can check your schedule from anywhere, anytime. </p>
            </div>
        </section>


       
        <!-- HOW IT WORKS - SIMPLE STEPS -->
        <section class="section">
            <div class="section-header">
                <h2 class="section-title">How Does It Work?</h2>
                <p class="section-subtitle">Just 4 simple steps to start your learning journey!</p>
            </div>
            
            <div class="process-flow">
                <div class="process-step">
                    <div class="step-number">1</div>
                    <div class="step-content">
                        <h4>Find a Program You Love </h4>
                        <p>Browse through fun programs like cooking, gardening, or computer classes. Pick what interests you!</p>
                    </div>
                </div>
                
                <div class="process-step">
                    <div class="step-number">2</div>
                    <div class="step-content">
                        <h4>Easy Sign-Up</h4>
                        <p>Click the "Apply" button, no complicated forms! We'll guide you every step of the way.</p>
                    </div>
                </div>
                
                <div class="process-step">
                    <div class="step-number">3</div>
                    <div class="step-content">
                        <h4>Learn & Have Fun</h4>
                        <p>Attend classes, learn new skills, and make friends! Get reminders so you never miss a class. Track your progress as you go!</p>
                    </div>
                </div>
                
                <div class="process-step">
                    <div class="step-number">4</div>
                    <div class="step-content">
                        <h4>Get Your Certificate</h4>
                        <p>After completing your program, receive a digital certificate! Show it to friends, family, or potential employers. Your achievement, celebrated!</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- FEATURES - SIMPLE LANGUAGE -->
        <section class="section">
            <div class="section-header">
                <h2 class="section-title">Why You'll Love LEMS</h2>
                <p class="section-subtitle">Features designed with you in mind!</p>
            </div>
            
            <div class="benefits-container">
                <div class="benefit-card">
                    <div class="benefit-icon">
                        <i class="fas fa-search"></i>
                    </div>
                    <h3>Easy to Find Programs</h3>
                    <p>See all available programs in one place, with clear pictures and simple descriptions. Filter by what interests you most!</p>
                </div>
                
                <div class="benefit-card">
                    <div class="benefit-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <h3>Helpful Reminders</h3>
                    <p>Get friendly reminders about your classes, deadlines, and important dates. Never miss a class again!</p>
                </div>
                
                <div class="benefit-card">
                    <div class="benefit-icon">
                        <i class="fas fa-certificate"></i>
                    </div>
                    <h3>Digital Certificates</h3>
                    <p>Receive and store your certificates safely online. Access them anytime, from any device. No more lost papers!</p>
                </div>
                
                <div class="benefit-card">
                    <div class="benefit-icon">
                        <i class="fas fa-hands-helping"></i>
                    </div>
                    <h3>Community Support</h3>
                    <p>Connect with other learners, share experiences, and learn together. We're all in this learning journey as a community!</p>
                </div>
            </div>
        </section>

        <!-- WHO CAN USE IT -->
        <section class="section">
            <div class="section-header">
                <h2 class="section-title">Who Can Use LEMS?</h2>
                <p class="section-subtitle">Everyone is welcome! Here's how different people use our system:</p>
            </div>
            
            <div class="user-grid">
                <div class="user-card">
                    <div class="user-avatar">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <h3>New Learners</h3>
                    <p>Just starting out? Perfect! Find beginner-friendly programs and learn at your own pace.</p>
                </div>
                
                <div class="user-card">
                    <div class="user-avatar">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <h3>Skill Seekers</h3>
                    <p>Looking to learn specific skills? Find specialized programs to boost your abilities.</p>
                </div>
                
                <div class="user-card">
                    <div class="user-avatar">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <h3>Teachers & Trainers</h3>
                    <p>Share your knowledge! Manage your classes and connect with enthusiastic learners.</p>
                </div>
                
                <div class="user-card">
                    <div class="user-avatar">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3>Community Groups</h3>
                    <p>Organizations can track their members' progress and organize group learning.</p>
                </div>
            </div>
        </section>

        <!-- TECHNOLOGY MADE SIMPLE -->
        <section class="section">
            <div class="section-header">
                <h2 class="section-title">Technology Made Simple</h2>
                <p class="section-subtitle">No tech skills needed! Our system is designed to be easy for everyone.</p>
            </div>
            
            <div class="architecture-grid">
                <div class="arch-card">
                    <div class="number">01</div>
                    <h3><i class="fas fa-mobile-alt"></i> Works Everywhere</h3>
                    <p>Use it on your computer, tablet, or smartphone. The system adjusts to your device automatically!</p>
                </div>
                
                <div class="arch-card">
                    <div class="number">02</div>
                    <h3><i class="fas fa-shield-alt"></i> Safe & Secure</h3>
                    <p>Your information is protected with modern security. Feel safe while learning and exploring.</p>
                </div>
                
                <div class="arch-card">
                    <div class="number">03</div>
                    <h3><i class="fas fa-sync-alt"></i> Always Updated</h3>
                    <p>See real-time information about class schedules, availability, and your progress.</p>
                </div>
                
                <div class="arch-card">
                    <div class="number">04</div>
                    <h3><i class="fas fa-headset"></i> Help Available</h3>
                    <p>Stuck? Need help? Our support team is just a click away, ready to assist you.</p>
                </div>
            </div>
        </section>

    </div>

    <!-- FOOTER -->
    <footer class="footer">
        <p>© 2025 Livelihood Enrollment and Monitoring System. All Rights Reserved.</p>
        <p style="margin-top: 0.5rem; font-size: 0.8rem;">
            <i class="fas fa-heart" style="color: #ff6b6b;"></i> Made with care for our community
        </p>
    </footer>

</div>

<script>
    // MOBILE MENU FUNCTIONALITY - IDENTICAL TO INDEX.PHP
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

    // Smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const targetId = this.getAttribute('href');
            if(targetId === '#') return;
            
            const targetElement = document.querySelector(targetId);
            if(targetElement) {
                window.scrollTo({
                    top: targetElement.offsetTop - 80,
                    behavior: 'smooth'
                });
            }
        });
    });

    // Add hover effects to cards
    document.querySelectorAll('.benefit-card, .user-card, .arch-card, .tip-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });

    // Add animation to progress steps
    const steps = document.querySelectorAll('.process-step');
    steps.forEach((step, index) => {
        step.style.animationDelay = `${index * 0.1 + 0.3}s`;
        step.style.opacity = '0';
        step.style.transform = 'translateX(-20px)';
        step.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
    });

    // Animate steps when they come into view
    const stepObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if(entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateX(0)';
            }
        });
    }, { threshold: 0.1 });

    steps.forEach(step => stepObserver.observe(step));

    // Add fun hover effect to icons
    document.querySelectorAll('.benefit-icon, .user-avatar, .tip-icon').forEach(icon => {
        icon.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.1) rotate(5deg)';
        });
        
        icon.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1) rotate(0)';
        });
    });

    // Add loading animation
    document.addEventListener('DOMContentLoaded', function() {
        console.log(' Welcome to LEMS About page! ');
    });
</script>

</body>
</html>