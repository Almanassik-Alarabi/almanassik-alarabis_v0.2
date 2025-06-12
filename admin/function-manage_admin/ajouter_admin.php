<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-errors.log');

// Remove any previous output
ob_clean();

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Start session (if not already started)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once '../../includes/config.php';
require_once '../../includes/admin_functions.php';

// Verify AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    echo json_encode([
        'success' => false,
        'message' => 'يجب أن يتم الطلب عبر AJAX'
    ]);
    exit;
}

// Check if admin is logged in and has permission
if (!isset($_SESSION['admin_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'جلستك انتهت. يرجى تسجيل الدخول مرة أخرى'
    ]);
    exit;
}

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'add') {
    echo json_encode([
        'success' => false,
        'message' => 'طلب غير صالح'
    ]);
    exit;
}

// Validate inputs
$errors = array();

// Required fields validation with specific error messages
if (empty(trim($_POST['nom'] ?? ''))) {
    $errors['nom'] = "حقل الاسم الكامل مطلوب";
}

if (empty(trim($_POST['email'] ?? ''))) {
    $errors['email'] = "حقل البريد الإلكتروني مطلوب";
}

if (empty(trim($_POST['password'] ?? ''))) {
    $errors['password'] = "حقل كلمة المرور مطلوب";
}

if (empty(trim($_POST['confirm_password'] ?? ''))) {
    $errors['confirm_password'] = "حقل تأكيد كلمة المرور مطلوب";
}

// Password validation
if (!empty($_POST['password'])) {
    if (strlen($_POST['password']) < 8) {
        $errors['password'] = "كلمة المرور يجب أن تكون 8 أحرف على الأقل";
    } elseif ($_POST['password'] !== $_POST['confirm_password']) {
        $errors['confirm_password'] = "كلمات المرور غير متطابقة";
    }
}

// Validate email format and check if it exists
if (!empty($_POST['email'])) {
    if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "صيغة البريد الإلكتروني غير صحيحة";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = :email UNION SELECT id FROM sub_admins WHERE email = :email");
            $stmt->execute([':email' => $_POST['email']]);
            if ($stmt->fetch()) {
                $errors['email'] = "البريد الإلكتروني مسجل بالفعل";
            }
        } catch (PDOException $e) {
            error_log("Database error checking email: " . $e->getMessage());
            $errors['email'] = "حدث خطأ أثناء التحقق من البريد الإلكتروني";
        }
    }
}

// Check for at least one permission
if (!isset($_POST['permissions']) || !is_array($_POST['permissions']) || empty($_POST['permissions'])) {
    $errors['permissions'] = "يجب اختيار صلاحية واحدة على الأقل";
} else {
    $hasPermission = false;
    foreach ($_POST['permissions'] as $module => $actions) {
        foreach ($actions as $action => $value) {
            if ($value === '1') {
                $hasPermission = true;
                break 2;
            }
        }
    }
    if (!$hasPermission) {
        $errors['permissions'] = "يجب اختيار صلاحية واحدة على الأقل";
    }
}

// Return errors if any
if (!empty($errors)) {
    $errorMessages = [];
    foreach ($errors as $field => $message) {
        $errorMessages[$field] = $message;
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'يوجد أخطاء في البيانات المدخلة',
        'errors' => $errorMessages
    ]);
    exit;
}

// Process form data
try {
    $pdo->beginTransaction();

    // Debug log
    error_log("Starting admin creation process");
    error_log("POST data: " . print_r($_POST, true));

    // Hash password
    $hashedPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Insert sub-admin with better error checking
    $stmt = $pdo->prepare("
        INSERT INTO sub_admins (
            nom, 
            email, 
            mot_de_passe, 
            cree_par_admin_id, 
            created_at, 
            last_activity
        ) VALUES (
            :nom, 
            :email, 
            :mot_de_passe, 
            :cree_par_admin_id, 
            CURRENT_TIMESTAMP, 
            CURRENT_TIMESTAMP
        ) RETURNING id
    ");

    $params = [
        ':nom' => trim($_POST['nom']),
        ':email' => trim($_POST['email']),
        ':mot_de_passe' => $hashedPassword,
        ':cree_par_admin_id' => $_SESSION['admin_id']
    ];

    error_log("Executing insert with params: " . print_r($params, true));

    if (!$stmt->execute($params)) {
        throw new Exception("Database error: " . implode(", ", $stmt->errorInfo()));
    }
    
    $subAdminId = $stmt->fetchColumn();
    if (!$subAdminId) {
        throw new Exception("Failed to get new admin ID");
    }

    error_log("Successfully created admin with ID: " . $subAdminId);

    // Process permissions with better error checking
    foreach ($_POST['permissions'] as $module => $actions) {
        if (!preg_match('/^[a-z_]+$/', $module)) {
            error_log("Skipping invalid module name: " . $module);
            continue;
        }

        // تعديل: تحويل القيم إلى boolean بشكل صريح
        $permissionValues = [
            'allow_view' => false,
            'allow_add' => false,
            'allow_edit' => false,
            'allow_delete' => false
        ];

        foreach ($actions as $action => $value) {
            if ($value === '1') {
                switch ($action) {
                    case 'view': 
                        $permissionValues['allow_view'] = true; 
                        break;
                    case 'add': 
                    case 'send': 
                    case 'export': 
                        $permissionValues['allow_add'] = true; 
                        break;
                    case 'edit': 
                        $permissionValues['allow_edit'] = true; 
                        break;
                    case 'delete': 
                        $permissionValues['allow_delete'] = true; 
                        break;
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO sub_admin_permissions 
            (sub_admin_id, permission_key, allow_view, allow_add, allow_edit, allow_delete)
            VALUES 
            (:sub_admin_id, :permission_key, :allow_view, :allow_add, :allow_edit, :allow_delete)
        ");

        // تعديل: تحويل القيم إلى boolean قبل تمريرها للاستعلام
        $permParams = [
            ':sub_admin_id' => $subAdminId,
            ':permission_key' => $module,
            ':allow_view' => (bool)$permissionValues['allow_view'],
            ':allow_add' => (bool)$permissionValues['allow_add'],
            ':allow_edit' => (bool)$permissionValues['allow_edit'],
            ':allow_delete' => (bool)$permissionValues['allow_delete']
        ];

        error_log("Adding permissions for module {$module}: " . print_r($permParams, true));

        if (!$stmt->execute($permParams)) {
            throw new Exception("Error adding permissions for module {$module}: " . implode(", ", $stmt->errorInfo()));
        }
    }

    $pdo->commit();
    error_log("Successfully completed admin creation process");

    echo json_encode([
        'success' => true,
        'message' => 'تم إضافة المدير المساعد بنجاح',
        'sub_admin_id' => $subAdminId
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error in admin creation: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'message' => "حدث خطأ في إضافة المدير: " . $e->getMessage(),
        'debug_info' => [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}
