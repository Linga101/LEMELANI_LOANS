<?php
require_once __DIR__ . '/../config/config.php';

require_role(['admin']);

$userId = $_GET['user_id'] ?? null;

if (!$userId) {
    // simple form to trigger an assessment
    echo "<h2>Assess User Credit Score</h2>";
    echo "<form method=GET>");
    echo "User ID: <input type=text name=user_id /> ";
    echo "<button type=submit>Assess</button>";
    echo "</form>";
    echo "<p><a href=\"" . site_url('admin/dashboard.php') . "\">Back to dashboard</a></p>";
    exit;
}

try {
    $engine = get_scoring_engine();
    $result = $engine->assessUser((int)$userId);
} catch (Exception $e) {
    http_response_code(500);
    echo "<h3>Error</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a href=\"" . site_url('admin/dashboard.php') . "\">Back</a></p>";
    exit;
}

// Display results (simple view)
echo "<h2>Credit Assessment Result for user #" . htmlspecialchars($userId) . "</h2>";
echo "<p><strong>Score:</strong> " . htmlspecialchars($result['total_score']) . "</p>";
echo "<p><strong>Tier:</strong> " . htmlspecialchars($result['credit_tier']) . "</p>";
echo "<p><strong>Rate Adjustment:</strong> " . htmlspecialchars($result['rate_adjustment']) . "%</p>";
echo "<h3>Breakdown</h3>";
echo "<ul>";
foreach ($result['breakdown'] as $k => $v) {
    echo "<li>" . htmlspecialchars(ucwords(str_replace('_', ' ', $k))) . ": " . htmlspecialchars($v['score']) . " / " . htmlspecialchars($v['max']) . "</li>";
}
echo "</ul>";

echo "<h3>Suggested Tips</h3>";
if (method_exists($engine, 'generateTips')) {
    $tips = $engine->generateTips([], $result['breakdown']['payment_history']['score'], $result['breakdown']['credit_utilization']['score'], $result['breakdown']['income_stability']['score'], $result['breakdown']['alternative_data']['score']);
    if (!empty($tips)) {
        echo "<ul>";
        foreach ($tips as $t) echo "<li>" . htmlspecialchars($t) . "</li>";
        echo "</ul>";
    } else {
        echo "<p>No tips available.</p>";
    }
}

echo "<p><a href=\"" . site_url('admin/dashboard.php') . "\">Back to dashboard</a></p>";

?>
