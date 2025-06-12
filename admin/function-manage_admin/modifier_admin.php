<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-errors.log');

require_once '../../includes/config.php';
require_once '../../includes/admin_functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Verify AJAX request
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        throw new Exception('يجب أن يتم الطلب عبر AJAX');
    }

    // Verify session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['admin_id'])) {
        throw new Exception('جلستك انتهت. يرجى تسجيل الدخول مرة أخرى');
    }

    // Get and validate input
    $adminId = $_POST['id'] ?? null;
    if (!$adminId) {
        throw new Exception('معرف المدير غير صالح');
    }

    $updateData = [
        'full_name' => $_POST['nom'] ?? '',
        'email' => $_POST['email'] ?? '',
        'permissions' => $_POST['permissions'] ?? []
    ];

    if (!empty($_POST['password'])) {
        $updateData['password'] = $_POST['password'];
    }

    // Update admin
    $result = updateAdmin($adminId, $updateData);

    echo json_encode([
        'success' => true,
        'message' => 'تم تحديث بيانات المدير بنجاح'
    ]);

} catch (Exception $e) {
    error_log('Error in modifier_admin.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}