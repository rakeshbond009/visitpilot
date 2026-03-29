<?php
// privacy.php - Privacy Policy Page
require_once 'includes/db.php';
// Use Global Company Settings
$company_logo = !empty($company_settings['logo']) ? $company_settings['logo'] : 'assets/img/codepilot_logo_small.png';
$company_name = $company_settings['name'] ?? 'CodePilotx VMS';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - <?php echo htmlspecialchars($company_name); ?></title>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* Reuse Landing Page Styles */
        :root {
            --primary-gradient: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
            --secondary-gradient: linear-gradient(135deg, #3b82f6 0%, #2dd4bf 100%);
            --text-main: #1e293b;
        }

        body {
            font-family: 'Outfit', sans-serif;
            color: var(--text-main);
            background-color: #f8fafc;
        }

        /* Navbar Matches Index */
        .navbar {
            padding: 1.5rem 0;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }

        .navbar-brand {
            font-weight: 800;
            font-size: 1.5rem;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
        }

        /* Reset text fill for logo container */
        .navbar-brand img {
            -webkit-text-fill-color: initial;
        }

        .btn-outline-primary {
            border-color: #6366f1;
            color: #6366f1;
        }

        .btn-outline-primary:hover {
            background-color: #6366f1;
            color: white;
        }

        .content-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 4rem;
            margin: 8rem auto 4rem;
            max-width: 900px;
        }

        h1 {
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 2rem;
        }

        h2 {
            font-weight: 600;
            font-size: 1.5rem;
            margin-top: 2rem;
            margin-bottom: 1rem;
            color: #334155;
        }

        p {
            line-height: 1.8;
            color: #64748b;
            margin-bottom: 1.5rem;
        }
    </style>
</head>

<body>
    <!-- Navbar (Consistent) -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img src="<?php echo htmlspecialchars($company_logo); ?>" alt="Logo" class="me-2 rounded-circle"
                    style="width: 40px; height: 40px; object-fit: contain;">
                <?php echo htmlspecialchars($company_name); ?>
            </a>
            <a href="index.php" class="btn btn-sm btn-outline-primary rounded-pill fw-bold px-3">Back to Home</a>
        </div>
    </nav>
    <div class="container">
        <div class="content-card">
            <h1>Privacy Policy</h1>
            <p>Last updated:
                <?php echo date('F d, Y'); ?>
            </p>

            <h2>1. Information Collection</h2>
            <p>We collect information necessary to facilitate visitor management, including visitor names, photos,
                contact details, and ID proof data. We also collect host information to enable meeting scheduling.</p>

            <h2>2. Use of Information</h2>
            <p>The collected data is used solely for:</p>
            <ul>
                <li>Verifying visitor identity and authorization.</li>
                <li>Generating secure digital and physical entry passes.</li>
                <li> maintaining security logs and audit trails.</li>
                <li>Notifying hosts of visitor arrivals.</li>
            </ul>

            <h2>3. Data Protection</h2>
            <p>We implement industry-standard security measures, including encryption and secure access controls (RBAC),
                to protect your personal data from unauthorized access, alteration, or disclosure.</p>

            <h2>4. Data Retention</h2>
            <p>Visitor data is retained in accordance with your organization's compliance requirements. You can request
                deletion of your personal data by contacting the system administrator.</p>

            <h2>5. Third-Party Sharing</h2>
            <p>We do not share your personal information with third parties except as necessary to provide the service
                (e.g., SMS/WhatsApp gateways for notifications) or as required by law.</p>

            <hr class="my-5">
            <p class="text-center text-muted text-sm">©
                <?php echo date('Y'); ?> <?php echo htmlspecialchars($company_name); ?>. All rights reserved.
            </p>
        </div>
    </div>
    </div>

    <!-- Simple Footer (Matching Index) -->
    <footer class="py-5 bg-white border-top align-self-end mt-auto">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6 text-center text-md-start mb-4 mb-md-0">
                    <span class="navbar-brand mb-0"><?php echo htmlspecialchars($company_name); ?></span>
                    <p class="text-muted small mt-2 mb-0">&copy; <?php echo date('Y'); ?> <a
                            href="https://codepilotx.com/" target="_blank"
                            class="text-decoration-none text-muted fw-bold">Codepilotx by Rakesh Verma</a>. All rights
                        reserved.</p>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <div class="d-flex justify-content-center justify-content-md-end gap-4">
                        <a href="terms.php" class="text-muted text-decoration-none small">Terms</a>
                        <a href="privacy.php" class="text-muted text-decoration-none small">Privacy</a>
                        <a href="https://www.codepilotx.com/pages/contact.html" target="_blank"
                            class="text-muted text-decoration-none small">Connect</a>
                        <a href="index.php"
                            onclick="setTimeout(()=>{var m=new bootstrap.Modal(document.getElementById('loginModal'));m.show()},500)"
                            class="text-muted text-decoration-none small">Login</a>
                    </div>
                </div>
            </div>
        </div>
    </footer>
    <?php include_once 'includes/chatbot.php'; ?>
</body>


</html>