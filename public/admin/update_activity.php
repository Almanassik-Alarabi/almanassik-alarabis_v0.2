<?php
require_once '../includes/config.php';

// التحقق من حالة الجلسة قبل بدئها
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// التحقق من تسجيل الدخول
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

// تحديث حالة نشاط المدير الحالي
updateUserActivity($_SESSION['admin_id']);

// الحصول على قائمة المدراء النشطين
$activeAdmins = getActiveUsers('admin');
$activeSubAdmins = getActiveUsers('sub_admin');

// دمج قوائم المدراء النشطين
$activeUserIds = array_merge(
    array_column($activeAdmins, 'id'),
    array_column($activeSubAdmins, 'id')
);

// إرجاع النتيجة
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'active_users' => $activeUserIds,
    'last_update' => date('Y-m-d H:i:s')
]); 