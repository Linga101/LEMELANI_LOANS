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
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/style.css'); ?>">
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/fontawesome-all.min.css'); ?>" />
    <style>
        /* Landing page specific styles */
        .scroll-progress {
            position: fixed;
            top: 0;
            left: 0;
            height: 3px;
            width: 0%;
            background: linear-gradient(90deg, #d4ff00 0%, #10b981 100%);
            z-index: 2000;
            box-shadow: 0 0 12px rgba(16, 185, 129, 0.7);
        }

        .hero-section {
            min-height: 90vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
            isolation: isolate;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .hero-scene {
            position: absolute;
            inset: 0;
            z-index: 0;
            pointer-events: none;
        }

        .hero-canvas {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            opacity: 0.7;
        }

        .aurora-blob {
            position: absolute;
            filter: blur(28px);
            border-radius: 999px;
            opacity: 0.55;
            animation: drift 14s ease-in-out infinite alternate;
        }

        .aurora-blob.blob-one {
            width: 360px;
            height: 360px;
            left: -5%;
            top: 5%;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.45) 0%, rgba(16, 185, 129, 0.02) 70%);
        }

        .aurora-blob.blob-two {
            width: 420px;
            height: 420px;
            right: -8%;
            bottom: -10%;
            animation-duration: 20s;
            background: radial-gradient(circle, rgba(212, 255, 0, 0.3) 0%, rgba(212, 255, 0, 0.03) 70%);
        }

        .aurora-blob.blob-three {
            width: 300px;
            height: 300px;
            right: 20%;
            top: 18%;
            animation-duration: 18s;
            animation-delay: 1s;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.24) 0%, rgba(59, 130, 246, 0.02) 70%);
        }

        .hero-content {
            max-width: 600px;
            z-index: 2;
            position: relative;
            transform: translateY(20px);
            opacity: 0;
            animation: revealUp 0.8s ease forwards;
            animation-delay: 0.15s;
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

        .hero-visual {
            position: absolute;
            right: 3%;
            width: min(36vw, 460px);
            aspect-ratio: 1 / 1;
            border-radius: 50%;
            z-index: 1;
            border: 1px solid rgba(212, 255, 0, 0.25);
            background:
                radial-gradient(circle at center, rgba(16, 185, 129, 0.2) 0%, rgba(16, 185, 129, 0.04) 55%, rgba(16, 185, 129, 0.01) 75%),
                linear-gradient(140deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0));
            box-shadow: inset 0 0 80px rgba(16, 185, 129, 0.2), 0 0 80px rgba(16, 185, 129, 0.2);
            overflow: hidden;
        }

        .hero-visual::before,
        .hero-visual::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            inset: 12%;
            border: 1px dashed rgba(255, 255, 255, 0.24);
            animation: spin 24s linear infinite;
        }

        .hero-visual::after {
            inset: 26%;
            border-style: solid;
            border-color: rgba(212, 255, 0, 0.35);
            animation-duration: 16s;
            animation-direction: reverse;
        }

        .hero-visual-core {
            position: absolute;
            width: 26%;
            aspect-ratio: 1 / 1;
            border-radius: 50%;
            left: 37%;
            top: 37%;
            background: radial-gradient(circle, rgba(212, 255, 0, 0.8) 0%, rgba(16, 185, 129, 0.45) 60%, rgba(16, 185, 129, 0) 100%);
            box-shadow: 0 0 30px rgba(212, 255, 0, 0.55);
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            margin: 4rem 0;
        }

        .scene {
            position: relative;
            overflow: hidden;
            padding: 2rem 0;
        }

        .scene::before {
            content: "";
            position: absolute;
            inset: 10% -20%;
            background: radial-gradient(circle at 20% 40%, rgba(16, 185, 129, 0.1), transparent 50%),
                        radial-gradient(circle at 80% 70%, rgba(59, 130, 246, 0.08), transparent 45%);
            filter: blur(10px);
            opacity: 0.75;
            pointer-events: none;
            z-index: 0;
        }

        .scene > .container {
            position: relative;
            z-index: 1;
        }

        .feature-card {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            transition: transform 0.45s ease, box-shadow 0.45s ease, border-color 0.35s ease;
            transform-style: preserve-3d;
        }

        .feature-card:hover {
            border-color: var(--primary-green);
            transform: translateY(-8px) scale(1.01);
            box-shadow: 0 18px 45px rgba(16, 185, 129, 0.22);
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
            backdrop-filter: blur(8px);
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
            box-shadow: 0 22px 50px rgba(16, 185, 129, 0.16);
        }

        .reveal {
            opacity: 0;
            transform: translateY(36px);
            transition: opacity 0.7s ease, transform 0.7s ease;
            transition-delay: var(--d, 0ms);
        }

        .reveal.in-view {
            opacity: 1;
            transform: translateY(0);
        }

        @keyframes drift {
            from { transform: translate(0, 0) scale(1); }
            to { transform: translate(18px, -14px) scale(1.1); }
        }

        @keyframes revealUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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

            .hero-section {
                min-height: 80vh;
            }

            .hero-visual {
                display: none;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .reveal,
            .hero-content,
            .aurora-blob,
            .hero-visual::before,
            .hero-visual::after {
                animation: none !important;
                transition: none !important;
                transform: none !important;
                opacity: 1 !important;
            }
        }
    </style>
</head>
<body>
    <div id="scrollProgress" class="scroll-progress"></div>
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
                    <li><a href="index.php" class="active">Home</a></li>
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
        <div class="hero-scene" aria-hidden="true">
            <canvas id="heroCanvas" class="hero-canvas"></canvas>
            <div class="aurora-blob blob-one"></div>
            <div class="aurora-blob blob-two"></div>
            <div class="aurora-blob blob-three"></div>
        </div>
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
        <div class="hero-visual" aria-hidden="true">
            <div class="hero-visual-core"></div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="scene">
        <div class="container reveal">
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
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="scene">
        <div class="container">
        <div class="text-center mb-4 reveal">
            <h2>Why Choose Lemelani Loans</h2>
            <p class="text-secondary">Simple, fast, and secure micro-lending for Malawians</p>
        </div>

        <div class="feature-grid">
            <div class="feature-card reveal" style="--d: 50ms;">
                <div class="feature-icon"><i class="fas fa-bolt"></i></div>
                <h3 class="feature-title">Instant Approval</h3>
                <p class="feature-description">
                    Our automated scoring algorithm provides instant loan decisions. No waiting, no hassle.
                </p>
            </div>

            <div class="feature-card reveal" style="--d: 120ms;">
                <div class="feature-icon"><i class="fas fa-lock"></i></div>
                <h3 class="feature-title">Secure & Verified</h3>
                <p class="feature-description">
                    National ID and selfie verification ensure security while protecting your identity.
                </p>
            </div>

            <div class="feature-card reveal" style="--d: 190ms;">
                <div class="feature-icon"><i class="fas fa-credit-card"></i></div>
                <h3 class="feature-title">Multiple Payment Options</h3>
                <p class="feature-description">
                    Repay via Airtel Money, TNM Mpamba, Sticpay, Mastercard, Visa, or Binance.
                </p>
            </div>

            <div class="feature-card reveal" style="--d: 260ms;">
                <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                <h3 class="feature-title">Build Credit History</h3>
                <p class="feature-description">
                    Every repayment builds your credit score, unlocking larger loans over time.
                </p>
            </div>

            <div class="feature-card reveal" style="--d: 320ms;">
                <div class="feature-icon"><i class="fas fa-bell"></i></div>
                <h3 class="feature-title">Smart Reminders</h3>
                <p class="feature-description">
                    Never miss a payment with automatic reminders via SMS, email, and in-app notifications.
                </p>
            </div>

            <div class="feature-card reveal" style="--d: 380ms;">
                <div class="feature-icon"><i class="fas fa-mobile-alt"></i></div>
                <h3 class="feature-title">Mobile-First Design</h3>
                <p class="feature-description">
                    Access your loans anytime, anywhere from your phone or computer.
                </p>
            </div>
        </div>
        </div>
    </section>

    <!-- How It Works -->
    <section id="how-it-works" class="scene">
        <div class="container">
        <div class="text-center mb-4 reveal">
            <h2>How It Works</h2>
            <p class="text-secondary">Get your loan in 3 simple steps</p>
        </div>

        <div class="feature-grid">
            <div class="feature-card reveal" style="--d: 60ms;">
                <div class="feature-icon step-icon">1</div>
                <h3 class="feature-title">Register & Verify</h3>
                <p class="feature-description">
                    Create your account with your National ID and take a quick selfie for verification.
                </p>
            </div>

            <div class="feature-card reveal" style="--d: 160ms;">
                <div class="feature-icon step-icon">2</div>
                <h3 class="feature-title">Apply for Loan</h3>
                <p class="feature-description">
                    Choose your loan amount (MK 10,000 - MK 300,000) and get instant approval.
                </p>
            </div>

            <div class="feature-card reveal" style="--d: 260ms;">
                <div class="feature-icon step-icon">3</div>
                <h3 class="feature-title">Receive & Repay</h3>
                <p class="feature-description">
                    Money is disbursed instantly. Repay flexibly using your preferred payment method.
                </p>
            </div>
        </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="scene">
        <div class="container reveal">
        <div class="cta-section">
            <h2>Ready to Get Started?</h2>
            <p class="text-secondary mb-3">Join thousands of Malawians who trust Lemelani Loans</p>
            <a href="register.php" class="btn btn-accent btn-lg">Apply Now</a>
        </div>
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

        // Scroll progress indicator
        const progressBar = document.getElementById('scrollProgress');
        const updateProgress = () => {
            const max = document.documentElement.scrollHeight - window.innerHeight;
            const progress = max > 0 ? (window.scrollY / max) * 100 : 0;
            progressBar.style.width = progress + '%';
        };
        window.addEventListener('scroll', updateProgress, { passive: true });
        updateProgress();

        // Section reveal animation
        const revealItems = document.querySelectorAll('.reveal');
        const io = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('in-view');
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.2, rootMargin: '0px 0px -40px 0px' });
        revealItems.forEach((item) => io.observe(item));

        // Lightweight particle network in hero
        (function initHeroCanvas() {
            const canvas = document.getElementById('heroCanvas');
            if (!canvas) return;
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (reducedMotion) return;

            const ctx = canvas.getContext('2d');
            let raf = null;
            let width = 0;
            let height = 0;
            const particleCount = window.innerWidth < 800 ? 32 : 56;
            const particles = Array.from({ length: particleCount }, () => ({
                x: Math.random(),
                y: Math.random(),
                vx: (Math.random() - 0.5) * 0.0015,
                vy: (Math.random() - 0.5) * 0.0015,
                r: 1 + Math.random() * 2.2
            }));

            const resize = () => {
                width = canvas.clientWidth;
                height = canvas.clientHeight;
                const dpr = Math.min(window.devicePixelRatio || 1, 2);
                canvas.width = Math.floor(width * dpr);
                canvas.height = Math.floor(height * dpr);
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            };

            const draw = () => {
                ctx.clearRect(0, 0, width, height);

                for (let i = 0; i < particles.length; i++) {
                    const p = particles[i];
                    p.x += p.vx;
                    p.y += p.vy;
                    if (p.x < 0 || p.x > 1) p.vx *= -1;
                    if (p.y < 0 || p.y > 1) p.vy *= -1;

                    const px = p.x * width;
                    const py = p.y * height;
                    ctx.beginPath();
                    ctx.fillStyle = 'rgba(180,255,219,0.8)';
                    ctx.arc(px, py, p.r, 0, Math.PI * 2);
                    ctx.fill();

                    for (let j = i + 1; j < particles.length; j++) {
                        const p2 = particles[j];
                        const dx = (p2.x - p.x) * width;
                        const dy = (p2.y - p.y) * height;
                        const dist = Math.hypot(dx, dy);
                        if (dist < 130) {
                            const alpha = 0.17 * (1 - dist / 130);
                            ctx.beginPath();
                            ctx.strokeStyle = `rgba(123, 255, 188, ${alpha})`;
                            ctx.lineWidth = 1;
                            ctx.moveTo(px, py);
                            ctx.lineTo(p2.x * width, p2.y * height);
                            ctx.stroke();
                        }
                    }
                }

                raf = requestAnimationFrame(draw);
            };

            resize();
            window.addEventListener('resize', resize);
            draw();

            document.addEventListener('visibilitychange', () => {
                if (document.hidden && raf) {
                    cancelAnimationFrame(raf);
                    raf = null;
                    return;
                }
                if (!document.hidden && !raf) {
                    draw();
                }
            });
        })();
    </script>
</body>
</html>
