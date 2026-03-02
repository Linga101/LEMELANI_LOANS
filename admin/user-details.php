<?php
require_once '../config/config.php';
require_once '../classes/User.php';

require_role(['admin', 'manager']);

$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) {
    redirect('/admin/users.php');
}

$database = new Database();
$db = $database->getConnection();
$user = new User($db);
$userData = $user->getUserById($userId);

if (!$userData) {
    redirect('/admin/users.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Details - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="main-wrapper" style="margin-left:0;">
    <div class="main-content">
        <a href="<?php echo site_url('admin/users.php'); ?>" class="btn btn-secondary btn-sm">Back</a>
        <h1 style="margin-top:1rem;"><?php echo h($userData['full_name']); ?></h1>
        <div class="card mt-3" style="padding:1.5rem;">
            <p><strong>User ID:</strong> <?php echo (int)$userData['user_id']; ?></p>
            <p><strong>Email:</strong> <?php echo h($userData['email']); ?></p>
            <p><strong>Phone:</strong> <?php echo h($userData['phone']); ?></p>
            <p><strong>National ID:</strong> <?php echo h($userData['national_id']); ?></p>
            <p><strong>Credit Score:</strong> <?php echo (int)$userData['credit_score']; ?></p>
            <p><strong>Verification:</strong> <?php echo h(ucfirst($userData['verification_status'])); ?></p>
            <p><strong>Account Status:</strong> <?php echo h(ucfirst($userData['account_status'])); ?></p>
            <p><strong>Joined:</strong> <?php echo h(format_date($userData['created_at'])); ?></p>
        </div>

        <div class="card mt-3" style="padding:1.5rem;">
            <h3>Documents</h3>
            <p>
                <a class="btn btn-primary btn-sm" target="_blank"
                   href="<?php echo site_url('view-document.php?type=selfie&user_id=' . (int)$userData['user_id']); ?>">
                    View Selfie
                </a>
                <a class="btn btn-secondary btn-sm" target="_blank"
                   href="<?php echo site_url('view-document.php?type=national_id&user_id=' . (int)$userData['user_id']); ?>">
                    View National ID
                </a>
            </p>
        </div>
    </div>
</div>
</body>
</html>
