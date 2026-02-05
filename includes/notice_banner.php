<?php
// includes/notice_banner.php - Include this file wherever you want the notice to appear

if (!isset($conn) || !($conn instanceof mysqli)) {
    return; // Silently fail if no DB connection
}

// Get active notice
$noticeQuery = $conn->query("SELECT * FROM site_notice WHERE id = 1 AND is_active = 1");
if (!$noticeQuery || $noticeQuery->num_rows === 0) {
    return; // No active notice
}

$notice = $noticeQuery->fetch_assoc();
$noticeId = $notice['notice_id'];
$cookieName = 'ledger_notice_' . $noticeId;

// Check if user has dismissed this notice
if ($notice['is_dismissible'] && isset($_COOKIE[$cookieName])) {
    return; // User dismissed this notice
}

// Handle AJAX dismiss request
if (isset($_POST['dismiss_notice']) && $_POST['dismiss_notice'] === $noticeId) {
    setcookie($cookieName, '1', time() + (365 * 24 * 60 * 60), '/'); // 1 year
    exit('dismissed');
}

$colorScheme = $notice['color_scheme'] ?? 'info';
$message = $notice['message'] ?? '';
$isDismissible = (bool)$notice['is_dismissible'];
?>

<style>
.ledger-notice-banner {
    position: relative;
    animation: slideDown 0.3s ease-out;
    margin-bottom: 1rem;
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

.ledger-notice-dismiss {
    cursor: pointer;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.ledger-notice-dismiss:hover {
    opacity: 1;
}
</style>

<div class="ledger-notice-banner alert alert-<?= htmlspecialchars($colorScheme) ?> <?= $isDismissible ? 'alert-dismissible' : '' ?> fade show mb-3" role="alert">
    <div class="container">
        <?= $message ?>
        <?php if ($isDismissible): ?>
            <button type="button" class="btn-close ledger-notice-dismiss" onclick="dismissNotice('<?= htmlspecialchars($noticeId) ?>')"></button>
        <?php endif; ?>
    </div>
</div>

<?php if ($isDismissible): ?>
<script>
function dismissNotice(noticeId) {
    // Set cookie
    document.cookie = 'ledger_notice_' + noticeId + '=1; path=/; max-age=' + (365 * 24 * 60 * 60);
    
    // Hide notice with fade out
    const notice = document.querySelector('.ledger-notice-banner');
    if (notice) {
        notice.style.transition = 'opacity 0.3s ease-out';
        notice.style.opacity = '0';
        setTimeout(() => notice.remove(), 300);
    }
}
</script>
<?php endif; ?>
