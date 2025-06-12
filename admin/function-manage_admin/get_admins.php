<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../../includes/config.php';
require_once '../../includes/admin_functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Verify session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['admin_id'])) {
        throw new Exception('جلستك انتهت. يرجى تسجيل الدخول مرة أخرى');
    }

    // Get admins list with permissions
    $admins = getAllAdmins();
    
    // Generate HTML for admin cards
    ob_start();
    foreach ($admins as $admin) {
        ?>
        <div class="admin-card" data-admin-id="<?php echo $admin['id']; ?>">
            <div class="admin-info">
                <h3><?php echo htmlspecialchars($admin['full_name']); ?></h3>
                <p><?php echo htmlspecialchars($admin['email']); ?></p>
                <div class="permissions-section">
                    <?php if ($admin['is_super_admin']): ?>
                        <span class="badge badge-primary">مدير عام</span>
                    <?php else: ?>
                        <?php foreach ($admin['permissions'] as $section => $perms): ?>
                            <?php if (array_filter($perms)): ?>
                                <span class="badge badge-info"><?php echo $section; ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!$admin['is_super_admin']): ?>
                <div class="admin-actions">
                    <button class="btn btn-warning" onclick="openEditAdminModal(<?php echo htmlspecialchars(json_encode($admin)); ?>)">
                        <i class="fas fa-edit"></i> تعديل
                    </button>
                    <button class="btn btn-danger" onclick="deleteAdmin(<?php echo $admin['id']; ?>)">
                        <i class="fas fa-trash"></i> حذف
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    $html = ob_get_clean();

    echo json_encode([
        'success' => true,
        'html' => $html
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}