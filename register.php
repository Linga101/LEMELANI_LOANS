<?php
require_once 'config/config.php';

// Redirect if already logged in
if (is_logged_in()) {
    redirect('/dashboard.php');
}

$errors = [];
$success = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    // Sanitize inputs
    $national_id = sanitize_input($_POST['national_id'] ?? '');
    $full_name = sanitize_input($_POST['full_name'] ?? '');
    $email = sanitize_input($_POST['email'] ?? '');
    $phone = sanitize_input($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Store form data for repopulation
    $form_data = compact('national_id', 'full_name', 'email', 'phone');
    
    // Validation stage 1
    if (empty($national_id)) {
        $errors[] = "National ID is required";
    }
    
    if (empty($full_name)) {
        $errors[] = "Full name is required";
    }
    
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    if (empty($phone)) {
        $errors[] = "Phone number is required";
    }

    // Validation stage 3 (passwords)
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }

    // Handle image data (camera or file)
    $selfie_path = null;
    $id_document_path = null;

    // helper for base64->file
    function save_base64_image($data, $dir, $prefix) {
        if (preg_match('/^data:image\/([^;]+);base64,(.+)$/', $data, $m)) {
            $ext = strtolower($m[1]);
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                return null;
            }
            $base64 = $m[2];
            $decoded = base64_decode($base64);
            if ($decoded === false) return null;
            $name = uniqid() . "_{$prefix}." . $ext;
            if (file_put_contents($dir . $name, $decoded) !== false) {
                return $name;
            }
        }
        return null;
    }

    // selfie
    if (!empty($_POST['selfie_data'])) {
        $saved = save_base64_image($_POST['selfie_data'], SELFIE_UPLOAD_DIR, 'selfie');
        if ($saved) {
            $selfie_path = 'selfies/' . $saved;
        } else {
            $errors[] = "Invalid selfie image data";
        }
    } elseif (isset($_FILES['selfie']) && $_FILES['selfie']['error'] === UPLOAD_ERR_OK) {
        // fallback to file upload
        $selfie_ext = pathinfo($_FILES['selfie']['name'], PATHINFO_EXTENSION);
        $allowed_ext = ['jpg', 'jpeg', 'png'];
        if (!in_array(strtolower($selfie_ext), $allowed_ext)) {
            $errors[] = "Selfie must be JPG or PNG";
        } elseif ($_FILES['selfie']['size'] > 5000000) {
            $errors[] = "Selfie file too large (max 5MB)";
        } else {
            $selfie_name = uniqid() . '_selfie.' . $selfie_ext;
            if (move_uploaded_file($_FILES['selfie']['tmp_name'], SELFIE_UPLOAD_DIR . $selfie_name)) {
                $selfie_path = 'selfies/' . $selfie_name;
            } else {
                $errors[] = "Failed to upload selfie";
            }
        }
    } else {
        $errors[] = "Selfie is required for verification";
    }

    // id document
    if (!empty($_POST['id_document_data'])) {
        $saved = save_base64_image($_POST['id_document_data'], ID_UPLOAD_DIR, 'id');
        if ($saved) {
            $id_document_path = 'ids/' . $saved;
        } else {
            $errors[] = "Invalid ID document image data";
        }
    } elseif (isset($_FILES['id_document']) && $_FILES['id_document']['error'] === UPLOAD_ERR_OK) {
        $id_ext = pathinfo($_FILES['id_document']['name'], PATHINFO_EXTENSION);
        $allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];
        if (!in_array(strtolower($id_ext), $allowed_ext)) {
            $errors[] = "ID document must be JPG, PNG or PDF";
        } elseif ($_FILES['id_document']['size'] > 5000000) {
            $errors[] = "ID document file too large (max 5MB)";
        } else {
            $id_name = uniqid() . '_id.' . $id_ext;
            if (move_uploaded_file($_FILES['id_document']['tmp_name'], ID_UPLOAD_DIR . $id_name)) {
                $id_document_path = 'ids/' . $id_name;
            } else {
                $errors[] = "Failed to upload ID document";
            }
        }
    } else {
        $errors[] = "National ID document is required for verification";
    }
    
    // Check if email or national ID already exists
    if (empty($errors)) {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            $check_query = "SELECT user_id FROM users WHERE email = :email OR national_id = :national_id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([
                ':email' => $email,
                ':national_id' => $national_id
            ]);
            
            if ($check_stmt->rowCount() > 0) {
                $errors[] = "Email or National ID already registered";
            }
        } catch(Exception $e) {
            error_log('Registration lookup error: ' . $e->getMessage());
            $errors[] = "Unable to process registration right now. Please try again.";
        }
    }
    
    // Insert user if no errors
    if (empty($errors)) {
        try {
            $password_hash = password_hash($password, HASH_ALGO);
            
            $insert_query = "INSERT INTO users (national_id, full_name, email, phone, password_hash, profile_photo, is_verified, verification_status) 
                           VALUES (:national_id, :full_name, :email, :phone, :password_hash, :profile_photo, 0, 'pending')";
            
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->execute([
                ':national_id' => $national_id,
                ':full_name' => $full_name,
                ':email' => $email,
                ':phone' => $phone,
                ':password_hash' => $password_hash,
                ':profile_photo' => $selfie_path
            ]);
            
            $user_id = $db->lastInsertId();

            if (!empty($id_document_path)) {
                $doc_query = "INSERT INTO user_documents (user_id, doc_type, file_path, is_verified) 
                              VALUES (:user_id, 'national_id', :file_path, 0)";
                $doc_stmt = $db->prepare($doc_query);
                $doc_stmt->execute([
                    ':user_id' => $user_id,
                    ':file_path' => $id_document_path
                ]);
            }
            
            // Log audit
            log_audit($user_id, 'USER_REGISTERED', 'users', $user_id);
            
            // Create notification
            $notif_query = "INSERT INTO notifications (user_id, notification_type, title, message) 
                          VALUES (:user_id, 'system', 'Welcome to Lemelani Loans', 'Your account has been created successfully. Please wait for verification.')";
            $notif_stmt = $db->prepare($notif_query);
            $notif_stmt->execute([':user_id' => $user_id]);
            
            $success = "Registration successful! Please login to continue. Your account will be verified shortly.";
            $form_data = []; // Clear form data
            
        } catch(Exception $e) {
            error_log('Registration error: ' . $e->getMessage());
            $errors[] = "Registration failed. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="assets/css/fontawesome-all.min.css" />
    <style>
        /* container/card same as before */
        .auth-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .auth-card {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2.5rem;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }

        .auth-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .auth-logo {
            height: 50px;
            margin-bottom: 1rem;
        }

        .auth-title {
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }

        /* multi‑step form styles */
        .progress-container {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        .progress-step {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background: var(--border-color);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .progress-step.active {
            background: var(--primary-green);
            color: #fff;
        }

        .form-step {
            display: none;
        }

        .form-step.active {
            display: block;
        }

        .form-navigation {
            display: flex;
            justify-content: space-between;
            margin-top: 1.5rem;
        }

        .camera-container {
            position: relative;
            margin-bottom: 1rem;
        }

        .camera-preview {
            width: 100%;
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }

        .capture-btn,
        .start-camera-btn,
        .retake-btn {
            margin-top: 0.5rem;
        }
        
        .hidden {
            display: none;
        }
    

        .auth-subtitle {
            color: var(--text-secondary);
        }

        .file-upload-wrapper {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .file-upload-input {
            display: none;
        }

        .file-upload-label {
            display: block;
            padding: 1rem;
            background: var(--dark-card);
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload-label:hover {
            border-color: var(--primary-green);
            background: var(--dark-card-hover);
        }

        .file-upload-label.has-file {
            border-color: var(--primary-green);
            border-style: solid;
        }

        .file-upload-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .file-name {
            color: var(--primary-green);
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }

        .auth-footer {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }

        .auth-footer a {
            color: var(--primary-green);
            text-decoration: none;
        }

        .auth-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="gradient-overlay"></div>

    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <img src="assets/images/logo.png" alt="<?php echo SITE_NAME; ?>" class="auth-logo" onerror="this.style.display='none'">
                <h1 class="auth-title">Create Account</h1>
                <p class="auth-subtitle">Join Lemelani Loans today</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Please fix the following errors:</strong>
                    <ul style="margin: 0.5rem 0 0 1.5rem;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                    <br><a href="<?php echo site_url('login.php'); ?>" style="color: inherit; text-decoration: underline;">Click here to login</a>
                </div>
            <?php endif; ?>

            <form id="registerForm" method="POST" enctype="multipart/form-data">
                <?php echo csrf_input(); ?>
                <!-- progress bubbles -->
                <div class="progress-container">
                    <div class="progress-step active" data-step="1">1</div>
                    <div class="progress-step" data-step="2">2</div>
                    <div class="progress-step" data-step="3">3</div>
                </div>

                <!-- step 1: personal details -->
                <div class="form-step active" data-step="1">
                    <div class="form-group">
                        <label for="national_id" class="form-label">National ID *</label>
                        <input type="text" id="national_id" name="national_id" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['national_id'] ?? ''); ?>" required>
                        <small class="form-text">Enter your Malawi National ID number</small>
                    </div>

                    <div class="form-group">
                        <label for="full_name" class="form-label">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="email" class="form-label">Email Address *</label>
                        <input type="email" id="email" name="email" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="phone" class="form-label">Phone Number *</label>
                        <input type="tel" id="phone" name="phone" class="form-control" 
                               placeholder="+265..." value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>" required>
                    </div>
                </div>

                <!-- step 2: photos via camera -->
                <div class="form-step" data-step="2">
                    <div class="form-group">
                        <label class="form-label">Capture National ID Document *</label>
                        <div class="camera-container">
                            <video id="idVideo" class="camera-preview hidden" autoplay playsinline></video>
                            <canvas id="idCanvas" class="hidden"></canvas>
                        </div>
                        <button type="button" class="btn start-camera-btn" id="startIdBtn">Start Camera</button>
                        <button type="button" class="btn capture-btn hidden" id="captureIdBtn">Capture ID</button>
                        <button type="button" class="btn retake-btn hidden" id="retakeIdBtn">Retake</button>
                        <input type="hidden" name="id_document_data" id="id_document_data">
                        <img id="idPreview" class="camera-preview hidden" alt="ID snapshot">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Capture Selfie *</label>
                        <div class="camera-container">
                            <video id="selfieVideo" class="camera-preview hidden" autoplay playsinline></video>
                            <canvas id="selfieCanvas" class="hidden"></canvas>
                        </div>
                        <button type="button" class="btn start-camera-btn" id="startSelfieBtn">Start Camera</button>
                        <button type="button" class="btn capture-btn hidden" id="captureSelfieBtn">Capture Selfie</button>
                        <button type="button" class="btn retake-btn hidden" id="retakeSelfieBtn">Retake</button>
                        <input type="hidden" name="selfie_data" id="selfie_data">
                        <img id="selfiePreview" class="camera-preview hidden" alt="Selfie snapshot">
                    </div>
                </div>

                <!-- step 3: password creation -->
                <div class="form-step" data-step="3">
                    <div class="form-group">
                        <label for="password" class="form-label">Password *</label>
                        <input type="password" id="password" name="password" class="form-control" 
                               minlength="6" required>
                        <small class="form-text">Minimum 6 characters</small>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                    </div>
                </div>

                <!-- navigation buttons -->
                <div class="form-navigation">
                    <button type="button" class="btn btn-secondary" id="prevBtn" disabled>Previous</button>
                    <button type="button" class="btn btn-primary" id="nextBtn">Next</button>
                    <button type="submit" class="btn btn-success hidden" id="submitBtn">Register</button>
                </div>
            </form>

            <div class="auth-footer">
                Already have an account? <a href="<?php echo site_url('login.php'); ?>">Login here</a>
            </div>
        </div>
    </div>

    <script>
        // multi-step navigation logic
        const steps = document.querySelectorAll('.form-step');
        const progressSteps = document.querySelectorAll('.progress-step');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');
        let currentStep = 1;

        function showStep(n) {
            steps.forEach(s => s.classList.remove('active'));
            progressSteps.forEach(p => p.classList.remove('active'));
            document.querySelector('.form-step[data-step="' + n + '"]').classList.add('active');
            document.querySelector('.progress-step[data-step="' + n + '"]').classList.add('active');
            prevBtn.disabled = (n === 1);
            nextBtn.style.display = (n === steps.length ? 'none' : 'inline-block');
            submitBtn.classList.toggle('hidden', n !== steps.length);
        }

        nextBtn.addEventListener('click', () => {
            // basic validation when moving forward
            if (currentStep === 1) {
                const inputs = steps[0].querySelectorAll('input');
                for (let inp of inputs) {
                    if (!inp.checkValidity()) {
                        inp.reportValidity();
                        return;
                    }
                }
            }
            if (currentStep === 2) {
                // require both selfie and ID to have been captured via camera
                const selfieData = document.getElementById('selfie_data').value;
                const idData = document.getElementById('id_document_data').value;
                if (!selfieData) {
                    alert('Please capture a selfie using the camera');
                    return;
                }
                if (!idData) {
                    alert('Please capture your national ID using the camera');
                    return;
                }
            }
            if (currentStep < steps.length) {
                currentStep++;
                showStep(currentStep);
            }
        });
        prevBtn.addEventListener('click', () => {
            if (currentStep > 1) {
                currentStep--;
                showStep(currentStep);
            }
        });

        // camera control helpers
        let streams = {
            id: null,
            selfie: null
        };

        async function startCamera(type) {
            const videoId = type + 'Video';
            const startBtn = document.getElementById('start' + capitalize(type) + 'Btn');
            const captureBtn = document.getElementById('capture' + capitalize(type) + 'Btn');
            const retakeBtn = document.getElementById('retake' + capitalize(type) + 'Btn');
            const videoElem = document.getElementById(videoId);

            try {
                const stream = await navigator.mediaDevices.getUserMedia({ video: true });
                streams[type] = stream;
                videoElem.srcObject = stream;
                videoElem.classList.remove('hidden');
                startBtn.classList.add('hidden');
                captureBtn.classList.remove('hidden');
                retakeBtn.classList.add('hidden');
            } catch (e) {
                console.warn('Camera not available', e);
            }
        }

        function capture(type) {
            const videoElem = document.getElementById(type + 'Video');
            const canvas = document.getElementById(type + 'Canvas');
            // special case for id document input name
            const hiddenId = (type === 'id') ? 'id_document_data' : (type + '_data');
            const hiddenInput = document.getElementById(hiddenId);
            const preview = document.getElementById(type + 'Preview');
            const captureBtn = document.getElementById('capture' + capitalize(type) + 'Btn');
            const retakeBtn = document.getElementById('retake' + capitalize(type) + 'Btn');

            canvas.width = videoElem.videoWidth;
            canvas.height = videoElem.videoHeight;
            canvas.getContext('2d').drawImage(videoElem, 0, 0);
            const dataUrl = canvas.toDataURL('image/png');
            hiddenInput.value = dataUrl;

            // show preview and update buttons
            preview.src = dataUrl;
            preview.classList.remove('hidden');
            videoElem.classList.add('hidden');
            captureBtn.classList.add('hidden');
            retakeBtn.classList.remove('hidden');

            // stop stream to release camera
            if (streams[type]) {
                streams[type].getTracks().forEach(t => t.stop());
                streams[type] = null;
            }
        }

        function retake(type) {
            const preview = document.getElementById(type + 'Preview');
            const hiddenId = (type === 'id') ? 'id_document_data' : (type + '_data');
            const hiddenInput = document.getElementById(hiddenId);
            const startBtn = document.getElementById('start' + capitalize(type) + 'Btn');
            const captureBtn = document.getElementById('capture' + capitalize(type) + 'Btn');
            const retakeBtn = document.getElementById('retake' + capitalize(type) + 'Btn');
            const videoElem = document.getElementById(type + 'Video');

            // clear previous data
            preview.src = '';
            preview.classList.add('hidden');
            hiddenInput.value = '';

            // reset UI to allow starting camera again
            startBtn.classList.remove('hidden');
            captureBtn.classList.add('hidden');
            retakeBtn.classList.add('hidden');
            videoElem.classList.add('hidden');
        }

        function capitalize(s) {
            return s.charAt(0).toUpperCase() + s.slice(1);
        }

        document.getElementById('startSelfieBtn').addEventListener('click', () => startCamera('selfie'));
        document.getElementById('startIdBtn').addEventListener('click', () => startCamera('id'));
        document.getElementById('captureSelfieBtn').addEventListener('click', () => capture('selfie'));
        document.getElementById('captureIdBtn').addEventListener('click', () => capture('id'));
        document.getElementById('retakeSelfieBtn').addEventListener('click', () => retake('selfie'));
        document.getElementById('retakeIdBtn').addEventListener('click', () => retake('id'));

        // password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirm = this.value;
            if (confirm && password !== confirm) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        showStep(currentStep);
        // camera will start only when user clicks the respective "Start Camera" buttons
        // initCamera();

    </script>
</body>
</html>
