<?php
// إعدادات التصحيح
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/agency_errors.log');

// دالة تسجيل الأحداث المخصصة
function logEvent($type, $message, $data = null) {
    $logFile = __DIR__ . '/../logs/agency_errors.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$type}] {$message}";
    if ($data !== null) $logMessage .= "\nData: " . print_r($data, true);
    $logMessage .= "\n" . str_repeat('-', 80) . "\n";
    error_log($logMessage, 3, $logFile);
}

// بدء الجلسة
if (session_status() === PHP_SESSION_NONE) session_start();

// تحقق من تسجيل دخول المدير
if (!isset($_SESSION['admin_id'])) {
    header('Location: login_admin.php');
    exit();
}

// إعدادات اتصال Supabase (PostgreSQL)
$DB_CONFIG = [
    'host' => 'aws-0-eu-west-3.pooler.supabase.com',
    'port' => '6543',
    'dbname' => 'postgres',
    'user' => 'postgres.zrwtxvybdxphylsvjopi',
    'password' => 'Dj123456789.',
    'sslmode' => 'require',
    'schema' => 'public'
];

// Supabase Storage إعدادات
define('SUPABASE_URL', 'https://zrwtxvybdxphylsvjopi.supabase.co');
define('SUPABASE_BUCKET', 'agences/photos/');
define('SUPABASE_API_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Inpyd3R4dnliZHhwaHlsc3Zqb3BpIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NDkyMzM1NzIsImV4cCI6MjA2NDgwOTU3Mn0.QjdaZ5AjDJEgu7rNIY4gnSqzEww0VXJ4DeM3RrykI2s');

// إنشاء اتصال PDO
try {
    $dsn = sprintf(
        "pgsql:host=%s;port=%s;dbname=%s;sslmode=%s",
        $DB_CONFIG['host'],
        $DB_CONFIG['port'],
        $DB_CONFIG['dbname'],
        $DB_CONFIG['sslmode']
    );
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_PERSISTENT => false
    ];
    $pdo = new PDO($dsn, $DB_CONFIG['user'], $DB_CONFIG['password'], $options);
    if (isset($DB_CONFIG['schema'])) $pdo->exec("SET search_path TO " . $DB_CONFIG['schema']);
} catch (PDOException $e) {
    $error_message = 'فشل الاتصال بقاعدة البيانات: ' . $e->getMessage();
    error_log($error_message);
    die(json_encode(['success' => false, 'message' => $error_message]));
}

// دوال التحقق والمعالجة
function validateEmail($email) {
    $email = trim(strtolower($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if (!preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $email)) return false;
    return true;
}
function validatePhone($phone) {
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    $length = strlen($cleanPhone);
    return $length >= 8 && $length <= 20 && preg_match('/^[+]?[0-9\s-]{8,20}$/', $phone);
}

// رفع صورة إلى supabase storage وإرجاع URL العام
function supabaseUploadImage($file, $type) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxSize = 5 * 1024 * 1024;
    if (!in_array($file['type'], $allowedTypes)) throw new Exception('نوع الملف غير مدعوم');
    if ($file['size'] > $maxSize) throw new Exception('حجم الملف كبير جداً');

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = time() . '_' . uniqid() . '.' . $ext;
    $storagePath = SUPABASE_BUCKET . $fileName;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, SUPABASE_URL . "/storage/v1/object/" . $storagePath);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: " . SUPABASE_API_KEY,
        "Authorization: Bearer " . SUPABASE_API_KEY,
        "Content-Type: " . $file['type'],
        "x-upsert: true"
    ]);
    $fileContent = file_get_contents($file['tmp_name']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode >= 200 && $httpcode < 300) {
        $publicUrl = SUPABASE_URL . "/storage/v1/object/public/" . $storagePath;
        return $publicUrl;
    } else {
        throw new Exception('فشل رفع الصورة إلى التخزين Supabase');
    }
}

// إضافة وكالة جديدة
function addAgency($pdo, $data) {
    logEvent('INFO', 'بدء إضافة وكالة', $data);
    try {
        $required = ['name', 'email', 'phone', 'wilaya', 'password', 'commercial_license_number', 'latitude', 'longitude'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || trim($data[$field]) === '') throw new Exception("الحقل {$field} مطلوب");
        }
        $email = trim(strtolower($data['email']));
        if (!validateEmail($email)) throw new Exception('بريد إلكتروني غير صالح');
        if (!validatePhone($data['phone'])) throw new Exception('رقم الهاتف غير صالح');
        if (strlen($data['password']) < 6) throw new Exception('كلمة المرور قصيرة جداً');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM agences WHERE LOWER(email) = LOWER(:email)");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetchColumn() > 0) throw new Exception('البريد الإلكتروني مستخدم مسبقاً');
        
        // رفع الصور على supabase storage
        $photoProfil = isset($_FILES['photo_profil']) && $_FILES['photo_profil']['error'] === UPLOAD_ERR_OK
            ? supabaseUploadImage($_FILES['photo_profil'], 'profil') : null;
        $photoCouverture = isset($_FILES['photo_couverture']) && $_FILES['photo_couverture']['error'] === UPLOAD_ERR_OK
            ? supabaseUploadImage($_FILES['photo_couverture'], 'couverture') : null;

        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        $approuve = (isset($data['approuve']) && ($data['approuve'] === '1' || $data['approuve'] === true || $data['approuve'] === 'true')) ? 'true' : 'false';
        $latitude = floatval($data['latitude']);
        $longitude = floatval($data['longitude']);
        $stmt = $pdo->prepare("
            INSERT INTO agences (
                nom_agence, email, telephone, wilaya, mot_de_passe, approuve,
                photo_profil, photo_couverture, date_creation, date_modification,
                latitude, longitude, commercial_license_number
            ) VALUES (
                :name, :email, :phone, :wilaya, :password, :approuve,
                :photo_profil, :photo_couverture, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
                :latitude, :longitude, :commercial_license_number
            )
        ");
        $params = [
            'name' => trim($data['name']),
            'email' => $email,
            'phone' => trim($data['phone']),
            'wilaya' => trim($data['wilaya']),
            'password' => $hashedPassword,
            'approuve' => $approuve,
            'photo_profil' => $photoProfil,
            'photo_couverture' => $photoCouverture,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'commercial_license_number' => trim($data['commercial_license_number'])
        ];
        if ($stmt->execute($params)) {
            $newId = $pdo->lastInsertId();
            logEvent('SUCCESS', 'تمت إضافة وكالة بنجاح', ['agency_id' => $newId, 'agency_data' => $params]);
            return ['success' => true, 'message' => 'تمت إضافة الوكالة بنجاح', 'id' => $newId];
        } else {
            throw new Exception('فشل في إضافة الوكالة');
        }
    } catch (Exception $e) {
        logEvent('ERROR', $e->getMessage(), $data);
        throw $e;
    }
}

// تحديث وكالة
function updateAgency($pdo, $id, $data) {
    logEvent('INFO', 'بدء تحديث وكالة', ['agency_id' => $id, 'update_data' => $data]);
    try {
        $email = trim(strtolower($data['email']));
        $checkEmailStmt = $pdo->prepare("SELECT COUNT(*) FROM agences WHERE LOWER(email) = LOWER(:email) AND id != :id");
        $checkEmailStmt->execute(['email' => $email, 'id' => $id]);
        if ($checkEmailStmt->fetchColumn() > 0) throw new Exception('البريد الإلكتروني مستخدم مسبقاً');
        $params = [
            'id' => $id,
            'name' => $data['name'],
            'email' => $email,
            'phone' => $data['phone'],
            'wilaya' => $data['wilaya'],
            'approuve' => (isset($data['approuve']) && $data['approuve'] == '1') ? 1 : 0,
            'commercial_license_number' => $data['commercial_license_number'],
            'latitude' => floatval($data['latitude']),
            'longitude' => floatval($data['longitude'])
        ];
        $updateFields = [
            'nom_agence = :name',
            'email = :email',
            'telephone = :phone',
            'wilaya = :wilaya',
            'approuve = :approuve',
            'commercial_license_number = :commercial_license_number',
            'latitude = :latitude',
            'longitude = :longitude',
            'date_modification = CURRENT_TIMESTAMP'
        ];
        if (!empty($data['password'])) {
            $params['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            $updateFields[] = 'mot_de_passe = :password';
        }
        if (isset($_FILES['photo_profil']) && $_FILES['photo_profil']['error'] === UPLOAD_ERR_OK) {
            $photoProfil = supabaseUploadImage($_FILES['photo_profil'], 'profil');
            if ($photoProfil) {
                $params['photo_profil'] = $photoProfil;
                $updateFields[] = 'photo_profil = :photo_profil';
            }
        }
        if (isset($_FILES['photo_couverture']) && $_FILES['photo_couverture']['error'] === UPLOAD_ERR_OK) {
            $photoCouverture = supabaseUploadImage($_FILES['photo_couverture'], 'couverture');
            if ($photoCouverture) {
                $params['photo_couverture'] = $photoCouverture;
                $updateFields[] = 'photo_couverture = :photo_couverture';
            }
        }
        $sql = "UPDATE agences SET " . implode(', ', $updateFields) . " WHERE id = :id";
        $updateStmt = $pdo->prepare($sql);
        if ($updateStmt->execute($params)) {
            logEvent('SUCCESS', 'تم تحديث بيانات الوكالة بنجاح', ['agency_id' => $id, 'updated_data' => $params]);
            return ['success' => true, 'message' => 'تم تحديث بيانات الوكالة بنجاح'];
        } else {
            logEvent('ERROR', 'فشل في تحديث بيانات الوكالة', ['agency_id' => $id, 'params' => $params, 'sql_error' => $updateStmt->errorInfo()]);
            throw new Exception('فشل في تحديث بيانات الوكالة');
        }
    } catch (Exception $e) {
        logEvent('ERROR', $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// حذف وكالة
function deleteAgency($pdo, $id) {
    logEvent('INFO', 'بدء حذف وكالة', ['agency_id' => $id]);
    try {
        // جلب بيانات الوكالة قبل الحذف
        $getStmt = $pdo->prepare("SELECT * FROM agences WHERE id = :id");
        $getStmt->execute(['id' => $id]);
        $agencyData = $getStmt->fetch();

        // جلب جميع عروض الوكالة
        $offersStmt = $pdo->prepare("SELECT id FROM offres WHERE agence_id = :agence_id");
        $offersStmt->execute(['agence_id' => $id]);
        $offers = $offersStmt->fetchAll(PDO::FETCH_COLUMN);

        // حذف صور العروض المرتبطة
        if (!empty($offers)) {
            $inQuery = implode(',', array_fill(0, count($offers), '?'));
            $delImagesStmt = $pdo->prepare("DELETE FROM offer_images WHERE offer_id IN ($inQuery)");
            $delImagesStmt->execute($offers);

            // حذف العروض نفسها
            $delOffersStmt = $pdo->prepare("DELETE FROM offres WHERE id IN ($inQuery)");
            $delOffersStmt->execute($offers);
        }

        // حذف الوكالة
        $stmt = $pdo->prepare("DELETE FROM agences WHERE id = :id");
        $result = $stmt->execute(['id' => $id]);
        if ($result) {
            logEvent('SUCCESS', 'تم حذف الوكالة وجميع عروضها وصورها', ['agency_id' => $id, 'deleted_data' => $agencyData, 'deleted_offers' => $offers]);
        } else {
            logEvent('ERROR', 'فشل في الحذف', ['agency_id' => $id, 'sql_error' => $stmt->errorInfo()]);
        }
        return $result;
    } catch (PDOException $e) {
        logEvent('ERROR', $e->getMessage(), ['agency_id' => $id]);
        return false;
    }
}

// معالجة طلبات AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    logEvent('REQUEST', 'طلب AJAX جديد', [
        'action' => $_POST['action'],
        'post_data' => $_POST,
        'files' => isset($_FILES) ? $_FILES : null,
        'user_ip' => $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT']
    ]);
    try {
        switch ($_POST['action']) {
            case 'add':
                $data = [
                    'name' => trim($_POST['name']),
                    'email' => trim(strtolower($_POST['email'])),
                    'phone' => trim($_POST['phone']),
                    'wilaya' => trim($_POST['wilaya']),
                    'password' => $_POST['password'],
                    'commercial_license_number' => trim($_POST['commercial_license_number']),
                    'approuve' => (isset($_POST['approuve']) && ($_POST['approuve'] === '1' || $_POST['approuve'] === true)) ? 'true' : 'false',
                    'latitude' => floatval($_POST['latitude']),
                    'longitude' => floatval($_POST['longitude'])
                ];
                $result = addAgency($pdo, $data);
                $response = $result;
                break;
            case 'get_agency':
                if (!isset($_POST['id'])) throw new Exception('معرف الوكالة مطلوب');
                $stmt = $pdo->prepare("
                    SELECT id, nom_agence, email, telephone, wilaya, approuve, 
                           photo_profil, photo_couverture, date_creation, date_modification,
                           commercial_license_number, latitude, longitude
                    FROM agences 
                    WHERE id = :id
                ");
                $stmt->execute(['id' => $_POST['id']]);
                $agency = $stmt->fetch();
                if ($agency) {
                    $response = ['success' => true, 'agency' => $agency];
                } else {
                    throw new Exception('الوكالة غير موجودة');
                }
                break;
            case 'update':
                if (!isset($_POST['id'])) throw new Exception('معرف الوكالة مطلوب');
                $updateData = [
                    'name' => $_POST['name'],
                    'email' => $_POST['email'],
                    'phone' => $_POST['phone'],
                    'wilaya' => $_POST['wilaya'],
                    'approuve' => isset($_POST['approuve']) ? $_POST['approuve'] : '0',
                    'commercial_license_number' => $_POST['commercial_license_number'],
                    'latitude' => $_POST['latitude'],
                    'longitude' => $_POST['longitude']
                ];
                if (!empty($_POST['password'])) $updateData['password'] = $_POST['password'];
                $result = updateAgency($pdo, $_POST['id'], $updateData);
                $response = $result;
                break;
            case 'delete':
                if (isset($_POST['id']) && deleteAgency($pdo, $_POST['id'])) {
                    $response['success'] = true;
                    $response['message'] = 'تم حذف الوكالة بنجاح';
                }
                break;
        }
    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// جلب جميع الوكالات
try {
    $stmt = $pdo->query("
        SELECT id, nom_agence, wilaya, telephone, email, mot_de_passe, approuve, 
               photo_profil, photo_couverture, date_creation, date_modification,
               commercial_license_number, latitude, longitude
        FROM agences 
        ORDER BY id DESC
    ");
    $agencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('خطأ في جلب الوكالات: ' . $e->getMessage());
    $agencies = [];
}

// بقية كود HTML وJS يبقى كما هو
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الوكالات | Manage Agencies</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
     <style>
    :root {
        /* Islamic Theme Colors */
        --primary: linear-gradient(135deg, #006838 0%, #00401A 100%);
        --secondary: linear-gradient(135deg, #FAD02C 0%, #D4AF37 100%);
        --success: linear-gradient(135deg, #00965E 0%, #006E44 100%);
        --warning: linear-gradient(135deg, #F7C100 0%, #DBA901 100%);
        --danger: linear-gradient(135deg, #C41E3A 0%, #960018 100%);
        --dark: #1B4332;
        --light: #F8F9FA;
        --card-bg: rgba(255, 255, 255, 0.98);
        --text-primary: #1B4332;
        --text-secondary: #495057;
        --border-radius: 12px;
        --shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    /* Reset & Base Styles */
    *, *::before, *::after {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Cairo', 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        background: linear-gradient(135deg,rgb(4, 128, 70) 0%,rgb(180, 163, 5) 100%);
        min-height: 100vh;
        overflow-x: hidden;
        color: var(--text-primary);
        line-height: 1.6;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }

    /* Layout Container */
    .dashboard-container {
        display: flex;
        min-height: 100vh;
        position: relative;
        isolation: isolate;
    }

    /* Sidebar Styling */
    .sidebar {
        width: 280px;
        background: var(--card-bg);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        box-shadow: var(--shadow);
        padding: 2rem 0;
        position: fixed;
        height: 100vh;
        overflow-y: auto;
        transition: var(--transition);
        z-index: 1000;
        top: 0;
        
        /* Improved scrollbar styling */
        scrollbar-width: thin;
        scrollbar-color: rgba(0,0,0,0.2) transparent;
    }

    .sidebar::-webkit-scrollbar {
        width: 6px;
    }

    .sidebar::-webkit-scrollbar-track {
        background: transparent;
    }

    .sidebar::-webkit-scrollbar-thumb {
        background-color: rgba(0,0,0,0.2);
        border-radius: 3px;
    }

    [dir="rtl"] .sidebar {
        right: 0;
        border-left: 1px solid rgba(0,0,0,0.08);
    }

    [dir="ltr"] .sidebar {
        left: 0;
        border-right: 1px solid rgba(0,0,0,0.08);
    }

    .sidebar.collapsed {
        width: 80px;
        transition: var(--transition);
    }

    .sidebar-header {
        padding: 0 2rem 2rem;
        text-align: center;
        border-bottom: 1px solid rgba(0,0,0,0.08);
        margin-bottom: 2rem;
        position: relative;
    }

    /* Hover & Focus States */
    .sidebar a:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }

    /* Accessibility */
    @media (prefers-reduced-motion: reduce) {
        * {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.01ms !important;
            scroll-behavior: auto !important;
        }
    }

    /* Print Styles */
    @media print {
        .sidebar {
            display: none;
        }
    }

    .logo {
        background: var(--primary);
        background-clip: text;
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .sidebar-menu {
        list-style: none;
        padding: 0;
    }

    .sidebar-menu li {
        margin: 0.5rem 0;
    }

    .sidebar-menu a {
        display: flex;
        align-items: center;
        padding: 1rem 2rem;
        color: var(--text-primary);
        text-decoration: none;
        transition: all 0.3s ease;
        border-radius: 0 25px 25px 0;
        margin-right: 1rem;
        position: relative;
        overflow: hidden;
    }

    .sidebar-menu a::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 0;
        height: 100%;
        background: linear-gradient(135deg,rgb(180, 163, 5) 0%,rgb(4, 128, 70) 100%);
        transition: width 0.3s ease;
        z-index: -1;
    }

    .sidebar-menu a:hover::before,
    .sidebar-menu a.active::before {
        width: 100%;
    }

    .sidebar-menu a:hover,
    .sidebar-menu a.active {
        color: white;
        transform: translateX(-10px);
    }

    .sidebar-menu i {
        margin-left: 1rem;
        font-size: 1.2rem;
        width: 20px;
    }

    /* Main Content */
    .main-content {
        flex: 1;
        padding: 2rem;
        transition: all 0.3s ease;
        min-height: 100vh;
    }

    [dir="rtl"] .main-content {
        margin-right: 280px;
    }

    [dir="ltr"] .main-content {
        margin-left: 280px;
    }

    .main-content.expanded {
        margin-right: 80px;
        margin-left: 80px;
    }

    /* Header */
    .header {
        background: var(--card-bg);
        backdrop-filter: blur(20px);
        border-radius: var(--border-radius);
        padding: 1.5rem 2rem;
        margin-bottom: 2rem;
        box-shadow: var(--shadow);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .header-left h1 {
        color: var(--text-primary);
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .header-left p {
        color: var(--text-secondary);
    }

    .header-right {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .language-switcher {
        display: flex;
        gap: 0.5rem;
    }

    .lang-btn {
        padding: 0.5rem 1rem;
        border: none;
        border-radius: 25px;
        background: var(--primary);
        color: white;
        cursor: pointer;
        transition: all 0.3s ease;
        font-weight: 600;
    }

    .lang-btn:hover,
    .lang-btn.active {
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }

    .user-info {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 0.5rem 1rem;
        background: rgba(255,255,255,0.7);
        border-radius: 25px;
    }

    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
    }

    /* Action Buttons */
    .actions-section {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        background: var(--card-bg);
        backdrop-filter: blur(20px);
        border-radius: var(--border-radius);
        padding: 1.5rem;
        box-shadow: var(--shadow);
    }

    .search-box {
        display: flex;
        align-items: center;
        background: rgba(255,255,255,0.8);
        border-radius: 25px;
        padding: 0.5rem 1rem;
        width: 300px;
        border: 2px solid transparent;
        transition: all 0.3s ease;
    }

    .search-box:focus-within {
        border-color: #667eea;
        box-shadow: 0 0 20px rgba(102, 126, 234, 0.3);
    }

    .search-box input {
        border: none;
        outline: none;
        background: transparent;
        width: 100%;
        padding: 0.5rem;
        font-size: 1rem;
    }

    .search-box i {
        color: var(--text-secondary);
        margin-left: 0.5rem;
    }

    .action-buttons {
        display: flex;
        gap: 1rem;
    }

    .btn {
        padding: 0.75rem 1.5rem;
        border: none;
        border-radius: 25px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }

    .btn-success {
        background: var(--success);
        color: white;
    }

    .btn-success:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }

    .btn-warning {
        background: var(--warning);
        color: white;
    }

    .btn-warning:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }

    .btn-danger {
        background: var(--danger);
        color: white;
    }

    .btn-danger:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }

    /* Agencies Grid */
    .agencies-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1.2rem;
        margin-bottom: 2rem;
    }

    .agency-card {
        background: var(--card-bg);
        border-radius: var(--border-radius);
        box-shadow: var(--shadow);
        border: 1px solid rgba(0,0,0,0.08);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        overflow: hidden;
    }

    .agency-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    }

    .agency-header {
        height: 160px;
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        position: relative;
        overflow: visible;
    }

    .agency-logo {
        position: absolute;
        bottom: -25px;
        right: 15px;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        overflow: hidden;
        aspect-ratio: 1;
    }

    .agency-logo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
    }

    .agency-logo i {
        font-size: 2rem;
        color: #667eea;
    }

    .agency-body {
        padding: 1.2rem;
        padding-top: 2rem;
    }

    .agency-name {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .agency-email {
        color: var(--text-secondary);
        margin-bottom: 0.6rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.9rem;
    }

    .agency-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.8rem;
    }

    .agency-phone {
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    .agency-status {
        padding: 0.15rem 0.5rem;
        border-radius: 10px;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .status-active {
        background: rgba(40, 167, 69, 0.2);
        color: #28a745;
    }

    .status-pending {
        background: rgba(255, 193, 7, 0.2);
        color: #ffc107;
    }

    .status-inactive {
        background: rgba(220, 53, 69, 0.2);
        color: #dc3545;
    }

    .agency-actions {
        display: flex;
        gap: 0.3rem;
        justify-content: flex-end;
    }

    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
        border-radius: 12px;
        display: flex;
        align-items: center;
        gap: 0.3rem;
        min-width: 60px;
        justify-content: center;
    }

    .btn-sm i {
        font-size: 0.8rem;
    }

    .btn-edit {
        background: var(--primary);
        color: white;
    }

    .btn-delete {
        background: var(--danger);
        color: white;
    }

    .btn-details {
        background: var(--success);
        color: white;
    }

    .btn-sm:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }

    @media (max-width: 768px) {
        .btn-sm {
            padding: 0.2rem 0.4rem;
            font-size: 0.7rem;
            min-width: 50px;
        }
        
        .btn-sm i {
            font-size: 0.7rem;
        }
    }

    /* Toggle Button */
    .sidebar-toggle {
        position: fixed;
        top: 20px;
        z-index: 1001;
        background: var(--primary);
        border: none;
        border-radius: 50%;
        width: 50px;
        height: 50px;
        color: white;
        font-size: 1.2rem;
        cursor: pointer;
        box-shadow: var(--shadow);
        transition: all 0.3s ease;
    }

    [dir="rtl"] .sidebar-toggle {
        right: 20px;
    }

    [dir="ltr"] .sidebar-toggle {
        left: 20px;
    }

    .sidebar-toggle:hover {
        transform: scale(1.1);
    }

    /* Modal Styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 2000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
        backdrop-filter: blur(5px);
        overflow-y: auto;
    }

    .modal-content {
        background: white;
        margin: 2% auto;
        padding: 2rem;
        border-radius: 15px;
        width: 90%;
        max-width: 600px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        position: relative;
        max-height: 90vh;
        display: flex;
        flex-direction: column;
    }

    .modal-header {
        position: sticky;
        top: 0;
        background: white;
        z-index: 1;
        padding-bottom: 1rem;
        margin-bottom: 1rem;
        border-bottom: 1px solid #eee;
    }

    .form-scroll {
        overflow-y: auto;
        padding-right: 10px;
        margin-bottom: 1rem;
        max-height: calc(90vh - 150px);
        position: relative;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1rem;
        position: relative;
    }

    .form-group {
        margin-bottom: 1.5rem;
        position: relative;
    }

    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        color: #333;
    }

    .required {
        color: #dc3545;
        margin-right: 4px;
    }

    .form-control {
        width: 100%;
        padding: 0.75rem;
        border: 2px solid #ddd;
        border-radius: 8px;
        font-size: 1rem;
        transition: all 0.3s ease;
        background-color: white;
        position: relative;
        z-index: 1;
    }

    .form-control:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }

    .form-control.error {
        border-color: #dc3545;
    }

    .error-message {
        color: #dc3545;
        font-size: 0.875rem;
        margin-top: 0.25rem;
        display: none;
        position: relative;
        z-index: 2;
    }

    .form-actions {
        position: sticky;
        bottom: 0;
        background: white;
        padding-top: 1rem;
        border-top: 1px solid #eee;
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
        z-index: 10;
    }

    .btn {
        padding: 0.75rem 1.5rem;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn:disabled {
        opacity: 0.7;
        cursor: not-allowed;
    }

    .btn-primary {
        background: #667eea;
        color: white;
    }

    .btn-primary:hover:not(:disabled) {
        background: #5a67d8;
        transform: translateY(-1px);
    }

    .btn-secondary {
        background: #e2e8f0;
        color: #4a5568;
    }

    .btn-secondary:hover {
        background: #cbd5e0;
    }

    @media (max-width: 1200px) {
        .agencies-grid {
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }
    }

    @media (max-width: 992px) {
        .agencies-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .agencies-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Success Message */
    .success-message {
        position: fixed;
        top: 20px;
        right: 20px;
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        color: white;
        padding: 15px 25px;
        border-radius: 8px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        gap: 10px;
        z-index: 9999;
        animation: slideIn 0.5s ease-out;
        font-family: 'Cairo', sans-serif;
        font-weight: 500;
    }

    .success-message i {
        font-size: 1.2rem;
    }

    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }

    .success-message.hide {
        animation: slideOut 0.5s ease-out forwards;
    }

    .agency-details {
        padding: 0;
    }

    .agency-header {
        position: relative;
        margin-bottom: 30px;
    }

    .agency-cover {
        height: 300px;
        position: relative;
        overflow: hidden;
        border-radius: 15px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    }

    .agency-cover img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .agency-cover::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 50%;
        background: linear-gradient(to top, rgba(0,0,0,0.7), transparent);
    }

    .agency-profile {
        position: absolute;
        bottom: 2px;
        left: 30px;
        right: 30px;
        display: flex;
        align-items: flex-end;
        z-index: 2;
    }

    .profile-image {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        overflow: hidden;
        margin-right: 20px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        position: relative;
        aspect-ratio: 1;
        border: 4px solid white;
    }

    .profile-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
    }

    .profile-info {
        flex: 1;
        color: #fff;
        text-shadow: 0 2px 4px rgba(0,0,0,0.3);
    }

    .profile-info h3 {
        margin: 0 0 10px 0;
        font-size: 28px;
        font-weight: 600;
    }

    .profile-meta {
        display: flex;
        gap: 20px;
    }

    .profile-meta span {
        display: flex;
        align-items: center;
        font-size: 14px;
        opacity: 0.9;
    }

    .profile-meta span i {
        margin-right: 5px;
    }

    .agency-content {
        padding: 0 20px;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }

    .info-card {
        background: #fff;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        overflow: hidden;
    }

    .card-header {
        padding: 20px;
        background: #f8f9fa;
        border-bottom: 1px solid #eee;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-header i {
        font-size: 20px;
        color: #3498db;
    }

    .card-header h4 {
        margin: 0;
        color: #2c3e50;
        font-size: 18px;
    }

    .card-body {
        padding: 20px;
    }

    .info-item {
        display: flex;
        align-items: flex-start;
        gap: 15px;
        padding: 10px 0;
        border-bottom: 1px solid #f0f0f0;
    }

    .info-item:last-child {
        border-bottom: none;
    }

    .info-item i {
        font-size: 18px;
        color: #3498db;
    }

    .modal-content {
        max-width: 800px;
        width: 90%;
    }

    .modal-body {
        max-height: 80vh;
        overflow-y: auto;
    }

    @media (max-width: 768px) {
        .agency-profile {
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .profile-image {
            margin-right: 0;
            margin-bottom: 15px;
        }

        .info-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Loading Overlay */
    .loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.9);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        backdrop-filter: blur(5px);
    }

    .loading-spinner {
        width: 50px;
        height: 50px;
        border: 5px solid #f3f3f3;
        border-top: 5px solid #3498db;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .loading-text {
        margin-top: 15px;
        color: #333;
        font-size: 16px;
        font-weight: 500;
    }






 .modal-actions {
                                                background: white;
                                                padding: 20px;
                                                border-radius: 15px;
                                                box-shadow: 0 4px 15px rgba(0,0,0,0.05);
                                                display: flex;
                                                flex-direction: column;
                                                gap: 15px;
                                            }

                                            .modal-actions h3 {
                                                margin: 0;
                                                font-size: 24px;
                                                font-weight: 600;
                                                color: #2c3e50; 
                                            }

                                            .modal-actions .profile-meta {
                                                display: flex;
                                                gap: 20px;
                                                flex-wrap: wrap;
                                                margin-top: 10px;
                                            }

                                            .modal-actions .profile-meta span {
                                                display: flex;
                                                align-items: center;
                                                gap: 8px;
                                                font-size: 14px;
                                                color: #666;
                                            }

                                            .modal-actions .profile-meta i {
                                                color: #3498db;
                                                font-size: 16px;
                                            }
        
    
  
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(5px);
        animation: fadeIn 0.3s ease-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    .modal-content {
        background: #fff;
        margin: 2% auto;
        padding: 2rem;
        border-radius: 15px;
        width: 90%;
        max-width: 800px;
        position: relative;
        box-shadow: 0 15px 30px rgba(0,0,0,0.2);
        animation: slideIn 0.3s ease-out;
        max-height: 90vh;
        overflow-y: auto;
    }

    @keyframes slideIn {
        from {
            transform: translateY(-20px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid #f0f0f0;
    }

    .modal-title {
        font-size: 1.5rem;
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .modal-title i {
        color: #3498db;
    }

    .close {
        font-size: 2rem;
        color: #95a5a6;
        cursor: pointer;
        transition: color 0.3s ease;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
    }

    .close:hover {
        color: #e74c3c;
        background: #f8f9fa;
    }

    /* Form Styles */
    .form-row {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .form-group {
        position: relative;
    }

    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 500;
        color: #2c3e50;
        font-size: 0.95rem;
    }

    .form-control {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        font-size: 1rem;
        transition: all 0.3s ease;
        background: #f8fafc;
    }

    .form-control:focus {
        border-color: #3498db;
        box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        background: #fff;
    }

    .form-control::placeholder {
        color: #a0aec0;
    }

    /* File Input Styling */
    input[type="file"].form-control {
        padding: 0.5rem;
        background: #fff;
    }

    input[type="file"].form-control::-webkit-file-upload-button {
        padding: 0.5rem 1rem;
        background: #3498db;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        margin-right: 1rem;
        transition: background 0.3s ease;
    }

    input[type="file"].form-control::-webkit-file-upload-button:hover {
        background: #2980b9;
    }

    /* Select Styling */
    select.form-control {
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%232c3e50' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 1rem center;
        background-size: 1.2em;
        padding-right: 2.5rem;
    }

    /* Button Styles */
    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 2px solid #f0f0f0;
    }

    .btn {
        padding: 0.75rem 1.5rem;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        transition: all 0.3s ease;
    }

    .btn-primary {
        background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
        color: white;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
    }

    .btn-secondary {
        background: #e2e8f0;
        color: #4a5568;
    }

    .btn-secondary:hover {
        background: #cbd5e0;
    }

    .btn-success {
        background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
        color: white;
    }

    .btn-warning {
        background: linear-gradient(135deg, #f1c40f 0%, #f39c12 100%);
        color: white;
    }

    /* Map Modal Specific Styles */
    #map {
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        margin: 1rem 0;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }
        
        .modal-content {
            margin: 1rem;
            padding: 1.5rem;
        }
        
        .btn {
            padding: 0.6rem 1.2rem;
            font-size: 0.9rem;
        }
    }

    /* Loading States */
    .btn:disabled {
        opacity: 0.7;
        cursor: not-allowed;
    }

    .loading {
        position: relative;
        pointer-events: none;
    }

    .loading::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: inherit;
    }

    /* Error States */
    .form-control.error {
        border-color: #e74c3c;
    }

    .error-message {
        color: #e74c3c;
        font-size: 0.85rem;
        margin-top: 0.25rem;
    }

    /* Required Field Indicator */
    .required {
        color: #e74c3c;
        margin-left: 0.25rem;
    }

    /* Custom Scrollbar */
    .modal-content::-webkit-scrollbar {
        width: 8px;
    }

    .modal-content::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }

    .modal-content::-webkit-scrollbar-thumb {
        background: #cbd5e0;
        border-radius: 4px;
    }

    .modal-content::-webkit-scrollbar-thumb:hover {
        background: #a0aec0;
    }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <i class="fas fa-kaaba"></i>
                    <span class="sidebar-text">العمرة</span>
                </div>
                <p class="sidebar-text" data-ar="منصة إدارة العمرة" data-en="Umrah Management Platform" data-fr="Plateforme de Gestion Omra">منصة إدارة العمرة</p>
            </div>
            <ul class="sidebar-menu">
                <li>
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span class="sidebar-text" data-ar="لوحة التحكم" data-en="Dashboard" data-fr="Tableau de bord">لوحة التحكم</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="active">
                        <i class="fas fa-building"></i>
                        <span class="sidebar-text" data-ar="إدارة الوكالات" data-en="Manage Agencies" data-fr="Gérer les Agences">إدارة الوكالات</span>
                    </a>
                </li>
                <li>
                    <a href="demande_umrah.php">
                        <i class="fas fa-users"></i>
                        <span class="sidebar-text" data-ar="إدارة المعتمرين" data-en="Manage Pilgrims" data-fr="Gérer les Pèlerins">إدارة المعتمرين</span>
                    </a>
                </li>
                <li>
                    <a href="manage_offers.php">
                        <i class="fas fa-tags"></i>
                        <span class="sidebar-text" data-ar="إدارة العروض" data-en="Manage Offers" data-fr="Gérer les Offres">إدارة العروض</span>
                    </a>
                </li>
                <li>
                    <a href="manage_requests.php">
                        <i class="fas fa-clipboard-list"></i>
                        <span class="sidebar-text" data-ar="إدارة الطلبات" data-en="Manage Requests" data-fr="Gérer les Demandes">إدارة الطلبات</span>
                    </a>
                </li>
                <li>
                    <a href="manage_admins.php">
                        <i class="fas fa-user-shield"></i>
                        <span class="sidebar-text" data-ar="إدارة المدراء" data-en="Manage Admins" data-fr="Gérer les Admins">إدارة المدراء</span>
                    </a>
                </li>
                <li>
                    <a href="#">
                        <i class="fas fa-comments"></i>
                        <span class="sidebar-text" data-ar="الدردشة" data-en="Chat" data-fr="Chat">الدردشة</span>
                    </a>
                </li>
                <li>
                    <a href="#">
                        <i class="fas fa-chart-bar"></i>
                        <span class="sidebar-text" data-ar="التقارير" data-en="Reports" data-fr="Rapports">التقارير</span>
                    </a>
                </li>
                <li>
                    <a href="#">
                        <i class="fas fa-cogs"></i>
                        <span class="sidebar-text" data-ar="الإعدادات" data-en="Settings" data-fr="Paramètres">الإعدادات</span>
                    </a>
                </li>
            </ul>
        </div>
        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- Header -->
            <div class="header">
                <div class="header-left">
                    <h1 data-ar="إدارة الوكالات السياحية" data-en="Manage Travel Agencies" data-fr="Gérer les Agences de Voyage">إدارة الوكالات السياحية</h1>
                    <p data-ar="عرض وإدارة جميع الوكالات المسجلة في النظام" data-en="View and manage all registered agencies in the system" data-fr="Voir et gérer toutes les agences enregistrées dans le système">عرض وإدارة جميع الوكالات المسجلة في النظام</p>
                </div>
                <div class="header-right">
                    <div class="language-switcher">
                        <button class="lang-btn active" data-lang="ar">العربية</button>
                        <button class="lang-btn" data-lang="en">English</button>
                        <button class="lang-btn" data-lang="fr">Français</button>
                    </div>
                    <div class="user-info">
                        <div class="user-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <div style="font-weight: 600;"><?php echo isset($_SESSION['admin_name']) ? htmlspecialchars($_SESSION['admin_name']) : 'المدير العام'; ?></div>
                            <div style="font-size: 0.9rem; color: var(--text-secondary);">
                                <?php echo isset($_SESSION['admin_email']) ? htmlspecialchars($_SESSION['admin_email']) : 'admin@umrah.com'; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions Section -->
            <div class="actions-section">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchAgencies" placeholder="البحث عن الوكالات..." 
                           data-ar-placeholder="البحث عن الوكالات..." 
                           data-en-placeholder="Search agencies..." 
                           data-fr-placeholder="Rechercher des agences..."
                           oninput="searchAgencies(this.value)">
                </div>

                <div class="action-buttons">
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i>
                        <span data-ar="إضافة وكالة" data-en="Add Agency" data-fr="Ajouter Agence">إضافة وكالة</span>
                    </button>
                    <button class="btn btn-success" onclick="exportData()">
                        <i class="fas fa-download"></i>
                        <span data-ar="تصدير البيانات" data-en="Export Data" data-fr="Exporter les Données">تصدير البيانات</span>
                    </button>
                </div>
            </div>

            <!-- Agencies Grid -->
            <div class="agencies-grid" id="agenciesGrid">
                <?php foreach ($agencies as $agency): ?>
                <div class="agency-card" data-id="<?php echo htmlspecialchars($agency['id']); ?>">
                    <div class="agency-header" style="background-image: url('<?php 
                        echo !empty($agency['photo_couverture']) ? htmlspecialchars($agency['photo_couverture']) : '../assets/images/default-cover.jpg';
                    ?>')">
                        <div class="agency-logo">
                            <?php if (!empty($agency['photo_profil'])): ?>
                                <img src="<?php echo htmlspecialchars($agency['photo_profil']); ?>" 
                                     alt="Profile Picture" 
                                     onerror="this.src='../assets/images/default-profile.jpg'">
                            <?php else: ?>
                                <i class="fas fa-building"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="agency-body">
                        <h3 class="agency-name"><?php echo htmlspecialchars($agency['nom_agence']); ?></h3>
                        <div class="agency-email">
                            <i class="fas fa-envelope"></i>
                            <?php echo htmlspecialchars($agency['email']); ?>
                        </div>
                        <div class="agency-info">
                            <div class="agency-phone">
                                <i class="fas fa-phone"></i>
                                <?php echo htmlspecialchars($agency['telephone']); ?>
                            </div>
                            <span class="agency-status status-<?php echo htmlspecialchars($agency['approuve'] ? 'active' : 'pending'); ?>">
                                <?php if ($agency['approuve']): ?>
                                    <span data-ar="نشط" data-en="Active" data-fr="Actif">نشط</span>
                                <?php else: ?>
                                    <span data-ar="معلق" data-en="Pending" data-fr="En attente">معلق</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="agency-extra">
                            <?php if (!empty($agency['commercial_license_number'])): ?>
                                <div><i class="fas fa-id-card"></i> رخصة تجارية: <?php echo htmlspecialchars($agency['commercial_license_number']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($agency['latitude']) && !empty($agency['longitude'])): ?>
                                <div><i class="fas fa-map-marker-alt"></i> الموقع: <?php echo htmlspecialchars($agency['latitude']) . ', ' . htmlspecialchars($agency['longitude']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="agency-actions">
                            <button type="button" class="btn btn-success btn-sm" onclick="showAgencyDetails(<?php echo htmlspecialchars($agency['id']); ?>)">
                                <i class="fas fa-eye"></i>
                                <span data-ar="عرض" data-en="View" data-fr="Voir">عرض</span>
                            </button>
                            <button type="button" class="btn btn-warning btn-sm" onclick="editAgency(<?php echo htmlspecialchars($agency['id']); ?>)">
                                <i class="fas fa-edit"></i>
                                <span data-ar="تعديل" data-en="Edit" data-fr="Modifier">تعديل</span>
                            </button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="deleteAgency(<?php echo htmlspecialchars($agency['id']); ?>)">
                                <i class="fas fa-trash"></i>
                                <span data-ar="حذف" data-en="Delete" data-fr="Supprimer">حذف</span>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

        <!-- Modal إضافة وكالة جديدة / تعديل وكالة -->
        <div id="addAgencyModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title">
                        <i class="fas fa-building"></i>
                        <span data-ar="إضافة وكالة جديدة" data-en="Add New Agency" data-fr="Ajouter une Nouvelle Agence">إضافة وكالة جديدة</span>
                    </h2>
                    <span class="close" onclick="closeAddModal()">&times;</span>
                </div>
                <form method="post" action="" enctype="multipart/form-data" id="addAgencyForm" autocomplete="off">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="name">اسم الوكالة <span style="color:#e74c3c">*</span></label>
                            <input type="text" id="name" name="name" class="form-control" required placeholder="اسم الوكالة">
                        </div>
                        <div class="form-group" style="position:relative;">
                            <label class="form-label" for="wilaya">الولاية <span style="color:#e74c3c">*</span></label>
                            <input type="text" id="wilaya" name="wilaya" class="form-control" required placeholder="مثال: الجزائر العاصمة" list="wilaya-list-ar" autocomplete="off" oninput="switchWilayaList(this)">
                            <datalist id="wilaya-list-ar">
                                <option value="أدرار">
                                <option value="الشلف">
                                <option value="الأغواط">
                                <option value="أم البواقي">
                                <option value="باتنة">
                                <option value="بجاية">
                                <option value="بسكرة">
                                <option value="بشار">
                                <option value="البليدة">
                                <option value="البويرة">
                                <option value="تمنراست">
                                <option value="تبسة">
                                <option value="تلمسان">
                                <option value="تيارت">
                                <option value="تيزي وزو">
                                <option value="الجزائر العاصمة">
                                <option value="الجلفة">
                                <option value="جيجل">
                                <option value="سطيف">
                                <option value="سعيدة">
                                <option value="سكيكدة">
                                <option value="سيدي بلعباس">
                                <option value="عنابة">
                                <option value="قالمة">
                                <option value="قسنطينة">
                                <option value="المدية">
                                <option value="مستغانم">
                                <option value="المسيلة">
                                <option value="معسكر">
                                <option value="ورقلة">
                                <option value="وهران">
                                <option value="البيض">
                                <option value="إليزي">
                                <option value="برج بوعريريج">
                                <option value="بومرداس">
                                <option value="الطارف">
                                <option value="تندوف">
                                <option value="تيسمسيلت">
                                <option value="الوادي">
                                <option value="خنشلة">
                                <option value="سوق أهراس">
                                <option value="تيبازة">
                                <option value="ميلة">
                                <option value="عين الدفلى">
                                <option value="النعامة">
                                <option value="عين تموشنت">
                                <option value="غرداية">
                                <option value="غليزان">
                                <option value="تميمون">
                                <option value="برج باجي مختار">
                                <option value="أولاد جلال">
                                <option value="بني عباس">
                                <option value="عين صالح">
                                <option value="عين قزام">
                                <option value="تقرت">
                                <option value="جانت">
                                <option value="المغير">
                                <option value="المنيعة">
                            </datalist>
                            <datalist id="wilaya-list-fr" style="display:none;">
                                <option value="Adrar">
                                <option value="Chlef">
                                <option value="Laghouat">
                                <option value="Oum El Bouaghi">
                                <option value="Batna">
                                <option value="Béjaïa">
                                <option value="Biskra">
                                <option value="Béchar">
                                <option value="Blida">
                                <option value="Bouira">
                                <option value="Tamanrasset">
                                <option value="Tébessa">
                                <option value="Tlemcen">
                                <option value="Tiaret">
                                <option value="Tizi Ouzou">
                                <option value="Alger">
                                <option value="Djelfa">
                                <option value="Jijel">
                                <option value="Sétif">
                                <option value="Saïda">
                                <option value="Skikda">
                                <option value="Sidi Bel Abbès">
                                <option value="Annaba">
                                <option value="Guelma">
                                <option value="Constantine">
                                <option value="Médéa">
                                <option value="Mostaganem">
                                <option value="M'sila">
                                <option value="Mascara">
                                <option value="Ouargla">
                                <option value="Oran">
                                <option value="El Bayadh">
                                <option value="Illizi">
                                <option value="Bordj Bou Arreridj">
                                <option value="Boumerdès">
                                <option value="El Tarf">
                                <option value="Tindouf">
                                <option value="Tissemsilt">
                                <option value="El Oued">
                                <option value="Khenchela">
                                <option value="Souk Ahras">
                                <option value="Tipaza">
                                <option value="Mila">
                                <option value="Aïn Defla">
                                <option value="Naâma">
                                <option value="Aïn Témouchent">
                                <option value="Ghardaïa">
                                <option value="Relizane">
                                <option value="Timimoun">
                                <option value="Bordj Badji Mokhtar">
                                <option value="Ouled Djellal">
                                <option value="Béni Abbès">
                                <option value="In Salah">
                                <option value="In Guezzam">
                                <option value="Touggourt">
                                <option value="Djanet">
                                <option value="El M'Ghair">
                                <option value="El Menia">
                            </datalist>
                            <small style="color:#888;">يمكنك اختيار ولاية من القائمة أو كتابة ولاية جديدة</small>
                        </div>
                        <script>
                        function switchWilayaList(input) {
                            // Detect if the input is Arabic or French (basic check)
                            const arabicPattern = /[\u0600-\u06FF]/;
                            const isArabic = arabicPattern.test(input.value);
                            // Set the datalist accordingly
                            if (isArabic) {
                                input.setAttribute('list', 'wilaya-list-ar');
                            } else if (input.value.length > 0) {
                                input.setAttribute('list', 'wilaya-list-fr');
                            } else {
                                input.setAttribute('list', 'wilaya-list-ar');
                            }
                        }
                        </script>
                        <div class="form-group">
                            <label class="form-label" for="email">البريد الإلكتروني <span style="color:#e74c3c">*</span></label>
                            <input type="email" id="email" name="email" class="form-control" required placeholder="example@email.com">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="phone">رقم الهاتف <span style="color:#e74c3c">*</span></label>
                            <input type="tel" id="phone" name="phone" class="form-control" required placeholder="مثال: +213XXXXXXXXX">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="position:relative;">
                            <label class="form-label" for="password">كلمة المرور <span id="passwordRequired" style="color:#e74c3c">*</span></label>
                            <input type="password" id="password" name="password" class="form-control" placeholder="كلمة المرور">
                            <span class="toggle-password" onclick="togglePassword('password', this)" style="position:absolute;top:38px;left:10px;cursor:pointer;">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                        <div class="form-group" style="position:relative;">
                            <label class="form-label" for="password_confirm">تأكيد كلمة المرور <span id="passwordConfirmRequired" style="color:#e74c3c">*</span></label>
                            <input type="password" id="password_confirm" name="password_confirm" class="form-control" placeholder="تأكيد كلمة المرور">
                            <span class="toggle-password" onclick="togglePassword('password_confirm', this)" style="position:absolute;top:38px;left:10px;cursor:pointer;">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                        <div class="form-group" style="grid-column:1/3;">
                            <span id="passwordError" class="error-message" style="display:none;"></span>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="commercial_license_number">رقم الرخصة التجارية <span style="color:#e74c3c">*</span></label>
                            <input type="text" id="commercial_license_number" name="commercial_license_number" class="form-control" required placeholder="رقم الرخصة التجارية">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="approuve">الموافقة</label>
                            <select id="approuve" name="approuve" class="form-control">
                                <option value="0">معلق</option>
                                <option value="1">موافق عليه</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="flex:1;">
                            <label class="form-label" for="latitude">خط العرض (Latitude) <span style="color:#e74c3c">*</span></label>
                            <input type="text" id="latitude" name="latitude" class="form-control" required placeholder="مثال: 36.752887" readonly>
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label class="form-label" for="longitude">خط الطول (Longitude) <span style="color:#e74c3c">*</span></label>
                            <input type="text" id="longitude" name="longitude" class="form-control" required placeholder="مثال: 3.042048" readonly>
                        </div>
                    </div>
                    <div class="form-row">
                        <div style="flex:1;display:flex;align-items:center;gap:10px;">
                            <button type="button" class="btn btn-success" id="autoLocateBtn" style="width: 200px; height: 60px;"><i class="fas fa-location-arrow"></i> تحديد تلقائي</button>
                            <button type="button" class="btn btn-warning" id="manualLocateBtn" style="width: 200px; height: 60px;"><i class="fas fa-map-marked-alt"></i>تحديد يدوي</button>
                            <span id="gpsStatus" style="font-size:0.9em;color:#888;"></span>
                        </div>
                    </div>
                    <div id="mapModal" class="modal" style="display:none;z-index:9999;">
                        <div class="modal-content" style="max-width:600px;width:95%;">
                            <div class="modal-header">
                                <h3>تحديد الموقع من الخريطة</h3>
                                <span class="close" onclick="closeMapModal()">&times;</span>
                            </div>
                            <div id="map" style="height:350px;width:100%;border-radius:10px;"></div>
                            <div style="text-align:left;margin-top:10px;">
                                <button type="button" class="btn btn-primary" onclick="confirmMapLocation()">تأكيد الموقع</button>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="photo_profil">صورة الملف الشخصي</label>
                            <input type="file" id="photo_profil" name="photo_profil" class="form-control" accept="image/*">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="photo_couverture">صورة الغلاف</label>
                            <input type="file" id="photo_couverture" name="photo_couverture" class="form-control" accept="image/*">
                        </div>
                    </div>
                    <div class="form-actions" style="display:flex;justify-content:flex-end;gap:1rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeAddModal()"><i class="fas fa-times"></i> إغلاق</button>
                        <button type="submit" class="btn btn-primary" id="submitBtn"><i class="fas fa-save"></i> إضافة الوكالة</button>
                    </div>
                </form>
            </div>
        </div>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <script>
        // إظهار/إخفاء كلمة المرور
        function togglePassword(fieldId, el) {
            const input = document.getElementById(fieldId);
            if (input.type === "password") {
                input.type = "text";
                el.querySelector('i').classList.remove('fa-eye');
                el.querySelector('i').classList.add('fa-eye-slash');
            } else {
                input.type = "password";
                el.querySelector('i').classList.remove('fa-eye-slash');
                el.querySelector('i').classList.add('fa-eye');
            }
        }

        // إعادة تعيين الحقول عند فتح النافذة
        function resetAddAgencyForm() {
            document.getElementById('addAgencyForm').reset();
            document.getElementById('latitude').value = '';
            document.getElementById('longitude').value = '';
            document.getElementById('gpsStatus').textContent = '';
            // إعادة تعيين حقل كلمة المرور ليكون مطلوب فقط في وضع الإضافة
            document.getElementById('password').required = true;
            document.getElementById('password_confirm').required = true;
            document.getElementById('passwordRequired').style.display = '';
            document.getElementById('passwordConfirmRequired').style.display = '';
            document.getElementById('passwordError').style.display = 'none';
            document.getElementById('passwordError').textContent = '';
        }
        function openAddModal() {
            resetAddAgencyForm();
            document.getElementById('addAgencyModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
            autoLocate();
            document.getElementById('autoLocateBtn').onclick = autoLocate;
            document.getElementById('manualLocateBtn').onclick = function() {
                document.getElementById('mapModal').style.display = 'block';
                setTimeout(initMap, 200);
            };
            // إعداد الوضع للإضافة
            const form = document.getElementById('addAgencyForm');
            form.dataset.mode = 'add';
            form.removeAttribute('data-edit-id');
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> إضافة الوكالة';
            // كلمة المرور مطلوبة في الإضافة
            document.getElementById('password').required = true;
            document.getElementById('password_confirm').required = true;
            document.getElementById('passwordRequired').style.display = '';
            document.getElementById('passwordConfirmRequired').style.display = '';
        }
        function closeAddModal() {
            document.getElementById('addAgencyModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        // الموقع التلقائي
        function autoLocate() {
            const gpsStatus = document.getElementById('gpsStatus');
            gpsStatus.textContent = 'جاري تحديد الموقع...';
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    document.getElementById('latitude').value = position.coords.latitude;
                    document.getElementById('longitude').value = position.coords.longitude;
                    gpsStatus.textContent = 'تم تحديد الموقع بنجاح';
                }, function(error) {
                    gpsStatus.textContent = 'تعذر تحديد الموقع تلقائياً';
                }, { enableHighAccuracy: true, timeout: 10000 });
            } else {
                gpsStatus.textContent = 'المتصفح لا يدعم تحديد الموقع';
            }
        }
        // الموقع اليدوي
        let map, marker;
        function initMap() {
            if (!map) {
                map = L.map('map').setView([36.752887, 3.042048], 6);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: 'OpenStreetMap'
                }).addTo(map);
                map.on('click', function(e) {
                    if (marker) map.removeLayer(marker);
                    marker = L.marker(e.latlng).addTo(map);
                });
            }
            map.invalidateSize();
        }
        function confirmMapLocation() {
            if (marker) {
                const latlng = marker.getLatLng();
                document.getElementById('latitude').value = latlng.lat;
                document.getElementById('longitude').value = latlng.lng;
                document.getElementById('gpsStatus').textContent = 'تم اختيار الموقع من الخريطة';
                closeMapModal();
            } else {
                alert('يرجى اختيار موقع من الخريطة أولاً');
            }
        }
        function closeMapModal() {
            document.getElementById('mapModal').style.display = 'none';
        }

        // دالة إرسال الاستمارة
        function submitAddAgency(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);

            // تحديد نوع العملية (إضافة أو تحديث)
            const isEdit = form.dataset.mode === 'edit';
            formData.append('action', isEdit ? 'update' : 'add');
            if (isEdit) {
                formData.append('id', form.dataset.editId);
            }

            // تأكد من إضافة جميع الحقول المطلوبة
            formData.set('name', document.getElementById('name').value);
            formData.set('wilaya', document.getElementById('wilaya').value);
            formData.set('email', document.getElementById('email').value);
            formData.set('phone', document.getElementById('phone').value);
            formData.set('commercial_license_number', document.getElementById('commercial_license_number') ? document.getElementById('commercial_license_number').value : '');
            formData.set('approuve', document.getElementById('approuve').value);
            formData.set('latitude', document.getElementById('latitude').value);
            formData.set('longitude', document.getElementById('longitude').value);

            // كلمة المرور مطلوبة فقط في الإضافة
            const password = document.getElementById('password').value;
            const passwordConfirm = document.getElementById('password_confirm').value;
            const passwordError = document.getElementById('passwordError');

            if (!isEdit || password.length > 0) {
                if (password !== passwordConfirm) {
                    passwordError.textContent = 'كلمتا المرور غير متطابقتين!';
                    passwordError.style.display = 'block';
                    return false;
                } else if (password.length < 6) {
                    passwordError.textContent = 'كلمة المرور يجب أن تكون 6 أحرف أو أكثر!';
                    passwordError.style.display = 'block';
                    return false;
                } else {
                    passwordError.style.display = 'none';
                }
            }

            if (!isEdit) {
                formData.set('password', password);
            } else {
                if (password) {
                    formData.set('password', password);
                } else {
                    formData.delete('password');
                }
            }

            const submitBtn = document.getElementById('submitBtn');
            const originalContent = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...';
            submitBtn.disabled = true;

            fetch('manage_agencies.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeAddModal();
                    showSuccessMessage(data.message || (isEdit ? 'تم تحديث الوكالة بنجاح' : 'تمت إضافة الوكالة بنجاح'));
                    setTimeout(() => { window.location.reload(); }, 1000);
                } else {
                    alert(data.message || 'حدث خطأ أثناء ' + (isEdit ? 'تحديث' : 'إضافة') + ' الوكالة');
                    submitBtn.innerHTML = originalContent;
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('حدث خطأ في الاتصال بالخادم');
                submitBtn.innerHTML = originalContent;
                submitBtn.disabled = false;
            });
        }
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('addAgencyForm');
            if (form) {
                form.addEventListener('submit', submitAddAgency);
            }
        });

        // زر التعديل
        function editAgency(id) {
            // جلب بيانات الوكالة عبر AJAX
            fetch('manage_agencies.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_agency&id=' + encodeURIComponent(id)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.agency) {
                    openAddModal();
                    const agency = data.agency;
                    const form = document.getElementById('addAgencyForm');
                    form.dataset.mode = 'edit';
                    form.dataset.editId = agency.id;
                    document.getElementById('name').value = agency.nom_agence || '';
                    document.getElementById('wilaya').value = agency.wilaya || '';
                    document.getElementById('email').value = agency.email || '';
                    document.getElementById('phone').value = agency.telephone || '';
                    document.getElementById('commercial_license_number').value = agency.commercial_license_number || '';
                    document.getElementById('approuve').value = agency.approuve ? '1' : '0';
                    document.getElementById('latitude').value = agency.latitude || '';
                    document.getElementById('longitude').value = agency.longitude || '';
                    // كلمة المرور تترك فارغة ولا تكون مطلوبة في التعديل
                    document.getElementById('password').value = '';
                    document.getElementById('password_confirm').value = '';
                    document.getElementById('password').required = false;
                    document.getElementById('password_confirm').required = false;
                    document.getElementById('passwordRequired').style.display = 'none';
                    document.getElementById('passwordConfirmRequired').style.display = 'none';
                    document.getElementById('passwordError').style.display = 'none';
                    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> تحديث الوكالة';
                } else {
                    showErrorMessage(data.message || 'تعذر جلب بيانات الوكالة');
                }
            })
            .catch(() => {
                showErrorMessage('تعذر الاتصال بالخادم');
            });
        }

        // زر الحذف
        function deleteAgency(id) {
            if (!confirm('هل أنت متأكد أنك تريد حذف هذه الوكالة؟')) return;
            showLoadingOverlay();
            fetch('manage_agencies.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete&id=' + encodeURIComponent(id)
            })
            .then(response => response.json())
            .then(data => {
                hideLoadingOverlay();
                if (data.success) {
                    showSuccessMessage(data.message || 'تم حذف الوكالة بنجاح');
                    setTimeout(() => { window.location.reload(); }, 1000);
                } else {
                    showErrorMessage(data.message || 'فشل حذف الوكالة');
                }
            })
            .catch(() => {
                hideLoadingOverlay();
                showErrorMessage('تعذر الاتصال بالخادم');
            });
        }

        // زر العرض
        function showAgencyDetails(id) {
            // جلب بيانات الوكالة عبر AJAX
            fetch('manage_agencies.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_agency&id=' + encodeURIComponent(id)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.agency) {
                    // عرض نافذة التفاصيل الكاملة مع الوكالات الفرعية
                    const agency = data.agency;

                    // جلب الوكالات الفرعية عبر AJAX متزامن (لأننا داخل then)
                    fetch('manage_agencies.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=get_sub_agencies&agence_id=' + encodeURIComponent(agency.id)
                    })
                    .then(response => response.json())
                    .then(subData => {
                        let subAgenciesHtml = '';
                        if (subData.success && Array.isArray(subData.sub_agencies) && subData.sub_agencies.length > 0) {
                            subAgenciesHtml = `
                                <div style="margin-top:25px;">
                                    <h4><i class="fas fa-sitemap"></i> الوكالات الفرعية التابعة لهذه الوكالة:</h4>
                                    <table style="width:100%;margin-top:10px;border-collapse:collapse;">
                                        <thead>
                                            <tr style="background:#f8f9fa;">
                                                <th style="padding:8px;border:1px solid #eee;">#</th>
                                                <th style="padding:8px;border:1px solid #eee;">رقم الهاتف</th>
                                                <th style="padding:8px;border:1px solid #eee;">الولاية</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${subData.sub_agencies.map((sub, idx) => `
                                                <tr>
                                                    <td style="padding:8px;border:1px solid #eee;">${idx + 1}</td>
                                                    <td style="padding:8px;border:1px solid #eee;">${sub.telephone || ''}</td>
                                                    <td style="padding:8px;border:1px solid #eee;">${sub.wilaya || ''}</td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                                </div>
                            `;
                        } else {
                            subAgenciesHtml = `
                                <div style="margin-top:25px;">
                                    <h4><i class="fas fa-sitemap"></i> لا توجد وكالات فرعية تابعة لهذه الوكالة.</h4>
                                </div>
                            `;
                        }

                        let detailsHtml = `
                            <div class="modal-actions">
                                <h3>${agency.nom_agence || ''}</h3>
                                <div class="profile-meta">
                                    <span><i class="fas fa-envelope"></i> ${agency.email || ''}</span>
                                    <span><i class="fas fa-phone"></i> ${agency.telephone || ''}</span>
                                    <span><i class="fas fa-map-marker-alt"></i> ${agency.latitude || ''}, ${agency.longitude || ''}</span>
                                    <span><i class="fas fa-id-card"></i> ${agency.commercial_license_number || ''}</span>
                                    <span><i class="fas fa-check"></i> ${agency.approuve ? 'نشط' : 'معلق'}</span>
                                    <span><i class="fas fa-city"></i> ${agency.wilaya || ''}</span>
                                    <span><i class="fas fa-calendar-plus"></i> تاريخ الإنشاء: ${agency.date_creation || ''}</span>
                                    <span><i class="fas fa-calendar-edit"></i> آخر تعديل: ${agency.date_modification || ''}</span>
                                    <span><i class="fas fa-language"></i> اللغة المفضلة: ${agency.langue_preferee || ''}</span>
                                </div>
                                <div style="margin-top:15px;">
                                    <img src="${agency.photo_profil ? agency.photo_profil : '../assets/images/default-profile.jpg'}" alt="صورة الملف" style="width:80px;height:80px;border-radius:50%;object-fit:cover;">
                                    <img src="${agency.photo_couverture ? agency.photo_couverture : '../assets/images/default-cover.jpg'}" alt="صورة الغلاف" style="width:120px;height:60px;border-radius:10px;object-fit:cover;margin-right:10px;">
                                </div>
                                ${subAgenciesHtml}
                                <div style="margin-top:20px;text-align:left;">
                                    <button type="button" class="btn btn-secondary" onclick="closeDetailsModal()">إغلاق</button>
                                </div>
                            </div>
                        `;
                        let modal = document.getElementById('detailsModal');
                        if (!modal) {
                            modal = document.createElement('div');
                            modal.id = 'detailsModal';
                            modal.className = 'modal';
                            modal.innerHTML = `<div class="modal-content">${detailsHtml}</div>`;
                            document.body.appendChild(modal);
                        } else {
                            modal.innerHTML = `<div class="modal-content">${detailsHtml}</div>`;
                        }
                        modal.style.display = 'block';
                        document.body.style.overflow = 'hidden';
                    })
                    .catch(() => {
                        showErrorMessage('تعذر جلب بيانات الوكالات الفرعية');
                    });
                } else {
                    showErrorMessage(data.message || 'تعذر جلب بيانات الوكالة');
                }
            })
            .catch(() => {
                showErrorMessage('تعذر الاتصال بالخادم');
            });
        }
        function closeDetailsModal() {
            let modal = document.getElementById('detailsModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        function exportData() {
            showLoadingOverlay();
            // Get all agencies from the grid
            const agencies = Array.from(document.querySelectorAll('.agency-card')).map(card => {
                return {
                    id: card.dataset.id,
                    name: card.querySelector('.agency-name').textContent,
                    email: card.querySelector('.agency-email').textContent.trim(),
                    phone: card.querySelector('.agency-phone').textContent.trim(),
                    status: card.querySelector('.agency-status').textContent.trim()
                };
            });
            // Create CSV content
            let csv = 'ID,اسم الوكالة,البريد الإلكتروني,رقم الهاتف,الحالة\n';
            agencies.forEach(agency => {
                csv += `${agency.id},"${agency.name}","${agency.email}","${agency.phone}","${agency.status}"\n`;
            });
            // Create download link
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
            link.href = URL.createObjectURL(blob);
            link.download = `agencies_export_${timestamp}.csv`;
            // Trigger download
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            hideLoadingOverlay();
            showSuccessMessage('تم تصدير البيانات بنجاح');
        }
        function searchAgencies(searchTerm) {
            searchTerm = searchTerm.toLowerCase();
            const agencies = document.querySelectorAll('.agency-card');
            agencies.forEach(agency => {
                const name = agency.querySelector('.agency-name').textContent.toLowerCase();
                const email = agency.querySelector('.agency-email').textContent.toLowerCase();
                const phone = agency.querySelector('.agency-phone').textContent.toLowerCase();
                const wilaya = agency.querySelector('.agency-extra') ?
                    agency.querySelector('.agency-extra').textContent.toLowerCase() : '';
                const matches = name.includes(searchTerm) ||
                    email.includes(searchTerm) ||
                    phone.includes(searchTerm) ||
                    wilaya.includes(searchTerm);
                agency.style.display = matches ? 'block' : 'none';
            });
            const visibleAgencies = document.querySelectorAll('.agency-card[style="display: block"]');
            const noResultsDiv = document.getElementById('noResults');
            if (visibleAgencies.length === 0 && searchTerm !== '') {
                if (!noResultsDiv) {
                    const div = document.createElement('div');
                    div.id = 'noResults';
                    div.style.textAlign = 'center';
                    div.style.padding = '20px';
                    div.style.color = '#fff';
                    div.innerHTML = '<i class="fas fa-search"></i> لا توجد نتائج للبحث';
                    document.querySelector('.agencies-grid').appendChild(div);
                }
            } else if (noResultsDiv) {
                noResultsDiv.remove();
            }
        }
        function showLoadingOverlay() {
            const overlay = document.createElement('div');
            overlay.className = 'loading-overlay';
            overlay.innerHTML = `
                <div class="loading-spinner"></div>
                <div class="loading-text">جاري التحميل...</div>
            `;
            document.body.appendChild(overlay);
        }
        function hideLoadingOverlay() {
            const overlay = document.querySelector('.loading-overlay');
            if (overlay) {
                overlay.remove();
            }
        }
        function showSuccessMessage(message) {
            const messageDiv = document.createElement('div');
            messageDiv.className = 'success-message';
            messageDiv.innerHTML = `
                <i class="fas fa-check-circle"></i>
                <span>${message}</span>
            `;
            document.body.appendChild(messageDiv);
            setTimeout(() => {
                messageDiv.classList.add('hide');
                setTimeout(() => messageDiv.remove(), 500);
            }, 3000);
        }
        function showErrorMessage(message) {
            alert(message);
        }
        </script>
</body>
</html>