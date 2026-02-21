<?php
require_once 'config/config.php';

// Redirect if already logged in
if (is_logged_in()) {
    redirect('/dashboard.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo SITE_TAGLINE; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="assets/css/fontawesome-all.min.css" />
    <style>
        /* Landing page specific styles */
        .hero-section {
            min-height: 90vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
        }

        .hero-content {
            max-width: 600px;
            z-index: 2;
        }

        .hero-title {
            font-size: 3.5rem;
            line-height: 1.1;
            margin-bottom: 1.5rem;
            background: linear-gradient(135deg, #ffffff 0%, #10b981 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-subtitle {
            font-size: 1.25rem;
            color: var(--text-secondary);
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .hero-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            margin: 4rem 0;
        }

        .feature-card {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .feature-card:hover {
            border-color: var(--primary-green);
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(16, 185, 129, 0.2);
        }

        
        .feature-title {
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }

        .feature-description {
            color: var(--text-secondary);
            line-height: 1.6;
        }

        .stats-section {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 3rem;
            margin: 4rem 0;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
            text-align: center;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-green);
            margin-bottom: 0.5rem;
        }

        .stat-text {
            color: var(--text-secondary);
        }

        .cta-section {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2) 0%, rgba(16, 185, 129, 0.05) 100%);
            border: 1px solid var(--primary-green);
            border-radius: 12px;
            padding: 3rem;
            text-align: center;
            margin: 4rem 0;
        }

        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }

            .hero-buttons {
                flex-direction: column;
            }

            .hero-buttons .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="gradient-overlay"></div>

    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="navbar-container">
                <a href="index.php" class="navbar-brand">
                    <img src="assets/images/logo.png" alt="<?php echo SITE_NAME; ?>" onerror="this.style.display='none'">
                    <span><?php echo SITE_NAME; ?></span>
                </a>
                <ul class="navbar-menu">
                    <li><a href="#features">Features</a></li>
                    <li><a href="#how-it-works">How It Works</a></li>
                    <li><a href="<?php echo site_url('login.php'); ?>">Login</a></li>
                    <li><a href="register.php" class="btn btn-primary btn-sm">Get Started</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="hero-content">
                <h1 class="hero-title">The Modern Capital Platform</h1>
                <p class="hero-subtitle">
                    We're eliminating the friction and fuss of traditional financing, 
                    connecting Malawians to quick micro-loans from MK 10,000 to MK 300,000.
                </p>
                <div class="hero-buttons">
                    <a href="register.php" class="btn btn-accent btn-lg">Apply for a Loan</a>
                    <a href="<?php echo site_url('login.php'); ?>" class="btn btn-secondary btn-lg">Sign In</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="container">
        <div class="stats-section">
            <div class="stats-row">
                <div class="stat-item">
                    <div class="stat-number">MK 10K - 300K</div>
                    <div class="stat-text">Loan Range</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">Instant</div>
                    <div class="stat-text">Approval</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">6+</div>
                    <div class="stat-text">Payment Methods</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">24/7</div>
                    <div class="stat-text">Access</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="container">
        <div class="text-center mb-4">
            <h2>Why Choose Lemelani Loans</h2>
            <p class="text-secondary">Simple, fast, and secure micro-lending for Malawians</p>
        </div>

        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-bolt"></i></div>
                <h3 class="feature-title">Instant Approval</h3>
                <p class="feature-description">
                    Our automated scoring algorithm provides instant loan decisions. No waiting, no hassle.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-lock"></i></div>
                <h3 class="feature-title">Secure & Verified</h3>
                <p class="feature-description">
                    National ID and selfie verification ensure security while protecting your identity.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-credit-card"></i></div>
                <h3 class="feature-title">Multiple Payment Options</h3>
                <p class="feature-description">
                    Repay via Airtel Money, TNM Mpamba, Sticpay, Mastercard, Visa, or Binance.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                <h3 class="feature-title">Build Credit History</h3>
                <p class="feature-description">
                    Every repayment builds your credit score, unlocking larger loans over time.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-bell"></i></div>
                <h3 class="feature-title">Smart Reminders</h3>
                <p class="feature-description">
                    Never miss a payment with automatic reminders via SMS, email, and in-app notifications.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-mobile-alt"></i></div>
                <h3 class="feature-title">Mobile-First Design</h3>
                <p class="feature-description">
                    Access your loans anytime, anywhere from your phone or computer.
                </p>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section id="how-it-works" class="container">
        <div class="text-center mb-4">
            <h2>How It Works</h2>
            <p class="text-secondary">Get your loan in 3 simple steps</p>
        </div>

        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon step-icon">1</div>
                <h3 class="feature-title">Register & Verify</h3>
                <p class="feature-description">
                    Create your account with your National ID and take a quick selfie for verification.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon step-icon">2</div>
                <h3 class="feature-title">Apply for Loan</h3>
                <p class="feature-description">
                    Choose your loan amount (MK 10,000 - MK 300,000) and get instant approval.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon step-icon">3</div>
                <h3 class="feature-title">Receive & Repay</h3>
                <p class="feature-description">
                    Money is disbursed instantly. Repay flexibly using your preferred payment method.
                </p>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="container">
        <div class="cta-section">
            <h2>Ready to Get Started?</h2>
            <p class="text-secondary mb-3">Join thousands of Malawians who trust Lemelani Loans</p>
            <a href="register.php" class="btn btn-accent btn-lg">Apply Now</a>
        </div>
    </section>

    <!-- Footer -->
    <footer class="container" style="padding: 3rem 1.5rem; text-align: center; border-top: 1px solid var(--border-color); margin-top: 4rem;">
        <p class="text-muted">&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
        <p class="text-muted" style="margin-top: 0.5rem;"><?php echo SITE_TAGLINE; ?></p>
    </footer>

    <script>
        // Smooth scroll for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });
    </script>
</body>
</html>