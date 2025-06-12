<?php
// تأكد من عدم وجود مسافات أو أسطر فارغة قبل هذه العلامة
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_functions.php';

// بدء الجلسة إذا لم تكن بدأت
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$output = null;

try {
    // التحقق من تسجيل الدخول
    if (!isset($_SESSION['admin_id'])) {
        throw new Exception('غير مصرح لك بالوصول');
    }

    // التحقق من صلاحيات المدير العام
    $adminSql = "SELECT est_super_admin FROM admins WHERE id = ?";
    $adminStmt = executeQuery($adminSql, [$_SESSION['admin_id']]);
    $admin = $adminStmt ? $adminStmt->fetch() : false;

    if (!$admin || !$admin['est_super_admin']) {
        throw new Exception('ليس لديك صلاحية تصدير البيانات');
    }

    // تنظيف المخزن المؤقت تماماً
    while (ob_get_level()) {
        ob_end_clean();
    }

    // تعيين العنوان وإضافة UTF-8 BOM
    // تعيين رأس الملف ليدعم اللغة العربية
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=admins_' . date('Y-m-d_H-i-s') . '.csv');
    header('Pragma: no-cache');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');

    // إنشاء ملف CSV مؤقت والكتابة فيه
    $output = fopen('php://temp', 'w+');
    // إضافة BOM لدعم Unicode
    fwrite($output, "\xEF\xBB\xBF");

    // عناوين الأعمدة
    $headers = [
        'المعرف',
        'الاسم الكامل',
        'البريد الإلكتروني',
        'نوع الحساب',
        'الصلاحيات',
        'تاريخ الإنشاء',
        'آخر نشاط',
        'الحالة'
    ];

    // كتابة العناوين
    fputcsv($output, $headers);

    // المدراء الرئيسيين
    $mainAdmins = executeQuery("
        SELECT id, nom as full_name, email, created_at, last_activity 
        FROM admins 
        WHERE est_super_admin = true 
        ORDER BY id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($mainAdmins as $admin) {
        $row = [
            $admin['id'],
            $admin['full_name'],
            $admin['email'],
            'مدير عام',
            'جميع الصلاحيات',
            formatDate($admin['created_at']),
            formatDate($admin['last_activity']),
            isActive($admin['last_activity']) ? 'نشط' : 'غير نشط'
        ];
        fputcsv($output, $row);
    }

    // المدراء الفرعيين
    $subAdmins = executeQuery("
        SELECT sa.*, 
               (SELECT json_agg(json_build_object(
                   'key', permission_key,
                   'view', allow_view,
                   'add', allow_add,
                   'edit', allow_edit,
                   'delete', allow_delete
               ))
               FROM sub_admin_permissions
               WHERE sub_admin_id = sa.id) as permissions
        FROM sub_admins sa
        ORDER BY sa.id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($subAdmins as $admin) {
        $permissions = json_decode($admin['permissions'], true) ?: [];
        $permissionTexts = [];
        
        foreach ($permissions as $perm) {
            $actions = [];
            if ($perm['view']) $actions[] = 'عرض';
            if ($perm['add']) $actions[] = 'إضافة';
            if ($perm['edit']) $actions[] = 'تعديل';
            if ($perm['delete']) $actions[] = 'حذف';
            
            if (!empty($actions)) {
                $permissionTexts[] = translatePermissionKey($perm['key']) . 
                                   ' (' . implode(', ', $actions) . ')';
            }
        }

        $row = [
            $admin['id'],
            $admin['nom'],
            $admin['email'],
            'مدير فرعي',
            implode(' | ', $permissionTexts),
            formatDate($admin['created_at']),
            formatDate($admin['last_activity']),
            isActive($admin['last_activity']) ? 'نشط' : 'غير نشط'
        ];
        fputcsv($output, $row);
    }

    // إعادة مؤشر الملف إلى البداية وطباعته للمستخدم
    rewind($output);
    fpassthru($output);

    // لا مزيد من الإخراج بعد هذا
    exit;

} catch (Exception $e) {
    // تسجيل الخطأ
    error_log('Export Error: ' . $e->getMessage());
    
    // إرجاع استجابة JSON في حالة الخطأ
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'details' => 'حدث خطأ أثناء محاولة تصدير البيانات'
    ]);
} finally {
    if (isset($output) && is_resource($output)) {
        fclose($output);
    }
}

// دوال مساعدة
function formatDate($date) {
    return $date ? date('Y-m-d H:i', strtotime($date)) : 'لم يسجل';
}

function isActive($lastActivity) {
    return $lastActivity && strtotime($lastActivity) > strtotime('-5 minutes');
}

function translatePermissionKey($key) {
    $translations = [
        'agencies' => 'الوكالات',
        'pilgrims' => 'المعتمرين',
        'offers' => 'العروض',
        'chat' => 'المحادثات',
        'reports' => 'التقارير'
    ];
    return $translations[$key] ?? $key;
}
