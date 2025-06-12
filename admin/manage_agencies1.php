<?php
// تمكين إعدادات التصحيح
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-errors.log');

// بدء الجلسة
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// التحقق من تسجيل دخول المدير
if (!isset($_SESSION['admin_id'])) {
    header('Location: login_admin.php');
    exit();
}

// إعدادات اتصال Supabase
$DB_CONFIG = array(
    'host' => 'aws-0-eu-west-3.pooler.supabase.com',
    'port' => '6543',
    'dbname' => 'postgres',
    'user' => 'postgres.zrwtxvybdxphylsvjopi',
    'password' => 'Dj123456789.',
    'sslmode' => 'require',
    'schema' => 'public'
);

// إنشاء اتصال PDO
try {
    $required_config = ['host', 'port', 'dbname', 'user', 'password'];
    foreach ($required_config as $config) {
        if (empty($DB_CONFIG[$config])) {
            throw new Exception("بيانات الاتصال غير مكتملة: {$config} مفقود");
        }
    }

    $dsn = sprintf(
        "pgsql:host=%s;port=%s;dbname=%s;sslmode=%s",
        $DB_CONFIG['host'],
        $DB_CONFIG['port'],
        $DB_CONFIG['dbname'],
        $DB_CONFIG['sslmode']
    );
    $options = array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_PERSISTENT => false
    );
    $pdo = new PDO($dsn, $DB_CONFIG['user'], $DB_CONFIG['password'], $options);

    if (isset($DB_CONFIG['schema'])) {
        $pdo->exec("SET search_path TO " . $DB_CONFIG['schema']);
    }

    // لا تنشئ الجدول تلقائياً إذا كان موجوداً في Supabase، فقط تحقق من الأعمدة
    $stmt = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_schema = 'public' AND table_name = 'agences'
    ");
    $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // تحقق من الأعمدة المطلوبة (حسب سكيمة Supabase)
    $requiredColumns = [
        'id', 'nom_agence', 'wilaya', 'telephone', 'email', 'mot_de_passe', 'approuve',
        'photo_profil', 'photo_couverture', 'date_creation', 'date_modification', 'last_activity',
        'nom', 'commercial_license_number', 'latitude', 'longitude'
    ];
    foreach ($requiredColumns as $col) {
        if (!in_array($col, $existingColumns)) {
            throw new Exception("عمود {$col} غير موجود في جدول agences. يرجى تحديث قاعدة البيانات.");
        }
    }
} catch (PDOException $e) {
    $error_message = 'خطأ في الاتصال بقاعدة البيانات: ';
    switch ($e->getCode()) {
        case 1045:
            $error_message .= 'بيانات الدخول غير صحيحة';
            break;
        case 2002:
            $error_message .= 'لا يمكن الاتصال بخادم قاعدة البيانات';
            break;
        case 1049:
            $error_message .= 'قاعدة البيانات غير موجودة';
            break;
        default:
            $error_message .= $e->getMessage();
    }
    error_log($error_message . ' - ' . $e->getMessage());
    die(json_encode([
        'success' => false,
        'message' => $error_message,
        'details' => 'يرجى التحقق من إعدادات الاتصال بقاعدة البيانات'
    ]));
} catch (Exception $e) {
    error_log('خطأ: ' . $e->getMessage());
    die(json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'details' => 'يرجى التواصل مع المسؤول'
    ]));
}

// دوال التحقق والمعالجة
function validateEmail($email) {
    $email = trim(strtolower($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if (!preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $email)) return false;
    return true;
}
function validatePhone($phone) {
    return preg_match('/^[+]?[0-9\s-]{8,20}$/', $phone);
}
function handleImageUpload($file, $type) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxSize = 5 * 1024 * 1024;
    if (!in_array($file['type'], $allowedTypes)) throw new Exception('نوع الملف غير مدعوم. يرجى رفع صورة بصيغة JPG أو PNG أو GIF');
    if ($file['size'] > $maxSize) throw new Exception('حجم الملف كبير جداً. الحد الأقصى هو 5 ميجابايت');
    $uploadDir = __DIR__ . '/uploads/agences/' . $type . '/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
    $fileName = uniqid() . '_' . basename($file['name']);
    $targetPath = $uploadDir . $fileName;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) throw new Exception('فشل في رفع الملف');
    return 'uploads/agences/' . $type . '/' . $fileName;
}

// إضافة وكالة جديدة
function addAgency($pdo, $data) {
    try {
        $requiredFields = ['nom_agence', 'email', 'telephone', 'wilaya', 'mot_de_passe'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) throw new Exception("الحقل {$field} مطلوب");
        }
        $email = trim(strtolower($data['email']));
        if (!validateEmail($email)) throw new Exception('البريد الإلكتروني غير صالح. يجب أن يكون بتنسيق صحيح مثل: example@gmail.com');
        if (!validatePhone($data['telephone'])) throw new Exception('رقم الهاتف غير صالح. يجب أن يحتوي على 8-20 رقم');
        if (strlen($data['mot_de_passe']) < 6) throw new Exception('كلمة المرور يجب أن تكون 6 أحرف على الأقل');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM agences WHERE LOWER(email) = LOWER(:email)");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetchColumn() > 0) throw new Exception('البريد الإلكتروني مستخدم مسبقاً');
        $photoProfil = isset($_FILES['photo_profil']) ? handleImageUpload($_FILES['photo_profil'], 'profil') : null;
        $photoCouverture = isset($_FILES['photo_couverture']) ? handleImageUpload($_FILES['photo_couverture'], 'couverture') : null;
        $hashedPassword = password_hash($data['mot_de_passe'], PASSWORD_DEFAULT);

        // معالجة الموقع الجغرافي
        $latitude = null;
        $longitude = null;
        if (!empty($data['wilaya']) && strpos($data['wilaya'], ',') !== false) {
            $parts = explode(',', $data['wilaya']);
            if (count($parts) === 2 && is_numeric(trim($parts[0])) && is_numeric(trim($parts[1]))) {
                $latitude = floatval(trim($parts[0]));
                $longitude = floatval(trim($parts[1]));
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO agences (
                nom_agence, email, telephone, wilaya, mot_de_passe, approuve,
                photo_profil, photo_couverture, date_creation, date_modification,
                latitude, longitude
            ) VALUES (
                :nom_agence, :email, :telephone, :wilaya, :mot_de_passe, FALSE,
                :photo_profil, :photo_couverture, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
                :latitude, :longitude
            )
        ");
        $params = [
            'nom_agence' => trim($data['nom_agence']),
            'email' => $email,
            'telephone' => trim($data['telephone']),
            'wilaya' => trim($data['wilaya']),
            'mot_de_passe' => $hashedPassword,
            'photo_profil' => $photoProfil,
            'photo_couverture' => $photoCouverture,
            'latitude' => $latitude,
            'longitude' => $longitude
        ];
        if ($stmt->execute($params)) {
            return [
                'success' => true,
                'message' => 'تمت إضافة الوكالة بنجاح',
                'id' => $pdo->lastInsertId()
            ];
        } else {
            throw new Exception('فشل في إضافة الوكالة');
        }
    } catch (PDOException $e) {
        error_log('خطأ في قاعدة البيانات: ' . $e->getMessage());
        throw new Exception('حدث خطأ في قاعدة البيانات');
    } catch (Exception $e) {
        error_log('خطأ في إضافة الوكالة: ' . $e->getMessage());
        throw $e;
    }
}

// تحديث بيانات الوكالة
function updateAgency($pdo, $id, $data) {
    try {
        $email = trim(strtolower($data['email']));
        $checkEmailStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM agences 
            WHERE LOWER(email) = LOWER(:email) 
            AND id != :id
        ");
        $checkEmailStmt->execute([
            'email' => $email,
            'id' => $id
        ]);
        if ($checkEmailStmt->fetchColumn() > 0) {
            throw new Exception('البريد الإلكتروني مستخدم مسبقاً');
        }
        $params = [
            'id' => $id,
            'name' => $data['name'],
            'email' => $email,
            'phone' => $data['phone'],
            'wilaya' => $data['wilaya'],
            'approved' => isset($data['approved']) ? true : false
        ];
        $updateFields = [
            'nom_agence = :name',
            'email = :email',
            'telephone = :phone',
            'wilaya = :wilaya',
            'approuve = :approved',
            'date_modification = CURRENT_TIMESTAMP'
        ];
        if (!empty($data['password'])) {
            $params['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            $updateFields[] = 'mot_de_passe = :password';
        }
        if (isset($data['photo_profil']) && $data['photo_profil'] !== '') {
            $params['photo_profil'] = $data['photo_profil'];
            $updateFields[] = 'photo_profil = :photo_profil';
        }
        if (isset($data['photo_couverture']) && $data['photo_couverture'] !== '') {
            $params['photo_couverture'] = $data['photo_couverture'];
            $updateFields[] = 'photo_couverture = :photo_couverture';
        }
        // تحديث الموقع الجغرافي إذا تغير العنوان
        if (!empty($data['wilaya']) && strpos($data['wilaya'], ',') !== false) {
            $parts = explode(',', $data['wilaya']);
            if (count($parts) === 2 && is_numeric(trim($parts[0])) && is_numeric(trim($parts[1]))) {
                $params['latitude'] = floatval(trim($parts[0]));
                $params['longitude'] = floatval(trim($parts[1]));
                $updateFields[] = 'latitude = :latitude';
                $updateFields[] = 'longitude = :longitude';
            }
        }
        $sql = "UPDATE agences SET " . implode(', ', $updateFields) . " WHERE id = :id";
        $updateStmt = $pdo->prepare($sql);
        if ($updateStmt->execute($params)) {
            return [
                'success' => true,
                'message' => 'تم تحديث بيانات الوكالة بنجاح'
            ];
        } else {
            throw new Exception('فشل في تحديث بيانات الوكالة');
        }
    } catch (Exception $e) {
        error_log('خطأ في تحديث الوكالة: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

// حذف وكالة
function deleteAgency($pdo, $id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM agences WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    } catch (PDOException $e) {
        error_log('خطأ في حذف الوكالة: ' . $e->getMessage());
        return false;
    }
}

// معالجة طلبات AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    try {
        switch ($_POST['action']) {
            case 'add':
                $email = trim(strtolower($_POST['email']));
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM agences WHERE LOWER(email) = LOWER(:email)");
                $stmt->execute(['email' => $email]);
                if ($stmt->fetchColumn() > 0) throw new Exception('البريد الإلكتروني مستخدم مسبقاً');
                $result = addAgency($pdo, $_POST);
                $response = $result;
                break;
            case 'get_agency':
                if (!isset($_POST['id'])) throw new Exception('معرف الوكالة مطلوب');
                $stmt = $pdo->prepare("
                    SELECT id, nom_agence, email, telephone, wilaya, approuve, 
                           photo_profil, photo_couverture, date_creation, date_modification,
                           nom, commercial_license_number, latitude, longitude
                    FROM agences 
                    WHERE id = :id
                ");
                $stmt->execute(['id' => $_POST['id']]);
                $agency = $stmt->fetch();
                if ($agency) {
                    $response = [
                        'success' => true,
                        'agency' => $agency
                    ];
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
                    'wilaya' => $_POST['address'],
                    'approved' => isset($_POST['approved']) ? true : false
                ];
                if (!empty($_POST['password'])) {
                    $updateData['password'] = $_POST['password'];
                }
                if (isset($_FILES['photo_profil']) && $_FILES['photo_profil']['error'] === UPLOAD_ERR_OK) {
                    $photoProfil = handleImageUpload($_FILES['photo_profil'], 'profil');
                    if ($photoProfil) $updateData['photo_profil'] = $photoProfil;
                }
                if (isset($_FILES['photo_couverture']) && $_FILES['photo_couverture']['error'] === UPLOAD_ERR_OK) {
                    $photoCouverture = handleImageUpload($_FILES['photo_couverture'], 'couverture');
                    if ($photoCouverture) $updateData['photo_couverture'] = $photoCouverture;
                }
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

// جلب جميع الوكالات من قاعدة البيانات حسب المخطط
try {
    $stmt = $pdo->query("
        SELECT id, nom_agence, wilaya, telephone, email, mot_de_passe, approuve, 
               photo_profil, photo_couverture, date_creation, date_modification, last_activity,
               nom, commercial_license_number, latitude, longitude
        FROM agences 
        ORDER BY id DESC
    ");
    $agencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('خطأ في جلب الوكالات: ' . $e->getMessage());
    $agencies = [];
}
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
            --primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --success: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --warning: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --danger: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --dark: #2c3e50;
            --light: #f8f9fa;
            --card-bg: rgba(255, 255, 255, 0.95);
            --text-primary: #2c3e50;
            --text-secondary: #6c757d;
            --border-radius: 20px;
            --shadow: 0 10px 30px rgba(0,0,0,0.1);
            --shadow-hover: 0 20px 60px rgba(0,0,0,0.15);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Cairo', 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            box-shadow: var(--shadow);
            padding: 2rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 1000;
            top: 0;
        }

        [dir="rtl"] .sidebar {
            right: 0;
        }

        [dir="ltr"] .sidebar {
            left: 0;
        }

        .sidebar.collapsed {
            width: 80px;
        }

        .sidebar-header {
            padding: 0 2rem 2rem;
            text-align: center;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            margin-bottom: 2rem;
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
            background: var(--primary);
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
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            position: relative;
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
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.3rem;
        }

        .agency-email {
            color: var(--text-secondary);
            margin-bottom: 0.6rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.8rem;
        }

        .agency-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.8rem;
        }

        .agency-phone {
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .agency-status {
            padding: 0.15rem 0.5rem;
            border-radius: 10px;
            font-size: 0.7rem;
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
            font-size: 0.7rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            min-width: 60px;
            justify-content: center;
        }

        .btn-sm i {
            font-size: 0.7rem;
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
                font-size: 0.65rem;
                min-width: 50px;
            }
            
            .btn-sm i {
                font-size: 0.65rem;
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
            bottom: -50px;
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
    </style>
</head>
<body>
    <!-- Sidebar Toggle -->
    <button class="sidebar-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

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
                    <a href="manage_agencies.php" class="active">
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
                    <a href="chat.php">
                        <i class="fas fa-comments"></i>
                        <span class="sidebar-text" data-ar="الدردشة" data-en="Chat" data-fr="Chat">الدردشة</span>
                    </a>
                </li>
                <li>
                    <a href="reports.php">
                        <i class="fas fa-chart-bar"></i>
                        <span class="sidebar-text" data-ar="التقارير" data-en="Reports" data-fr="Rapports">التقارير</span>
                    </a>
                </li>
                <li>
                    <a href="settings.php">
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
                            <div style="font-weight: 600;">المدير العام</div>
                            <div style="font-size: 0.9rem; color: var(--text-secondary);">admin@umrah.com</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions Section -->
            <div class="actions-section">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="البحث عن الوكالات..." data-ar-placeholder="البحث عن الوكالات..." data-en-placeholder="Search agencies..." data-fr-placeholder="Rechercher des agences...">
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
                        <!-- الحقول الجديدة -->
                        <div class="agency-extra">
                            <?php if (!empty($agency['commercial_license_number'])): ?>
                                <div><i class="fas fa-id-card"></i> رخصة تجارية: <?php echo htmlspecialchars($agency['commercial_license_number']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($agency['latitude']) && !empty($agency['longitude'])): ?>
                                <div><i class="fas fa-map-marker-alt"></i> الموقع: <?php echo htmlspecialchars($agency['latitude']) . ', ' . htmlspecialchars($agency['longitude']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="agency-actions">
                            <button class="btn btn-primary btn-sm" onclick="showAgencyDetails(<?php echo $agency['id']; ?>)">
                                <i class="fas fa-eye"></i>
                                <span data-ar="عرض" data-en="View" data-fr="Voir">عرض</span>
                            </button>
                            <button class="btn btn-warning btn-sm" onclick="editAgency(<?php echo $agency['id']; ?>)">
                                <i class="fas fa-edit"></i>
                                <span data-ar="تعديل" data-en="Edit" data-fr="Modifier">تعديل</span>
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="deleteAgency(<?php echo $agency['id']; ?>)">
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

    <!-- Modal إضافة وكالة جديدة -->
<div id="addAgencyModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <i class="fas fa-building"></i>
                <span data-ar="إضافة وكالة جديدة" data-en="Add New Agency" data-fr="Ajouter une Nouvelle Agence">إضافة وكالة جديدة</span>
            </h2>
            <span class="close" onclick="closeAddModal()">&times;</span>
        </div>
        <form id="addAgencyForm" onsubmit="submitAddAgency(event)" enctype="multipart/form-data">
            <div class="form-scroll">
                <div class="form-row">
                    <div class="form-group">
                        <label for="agencyName" class="form-label">
                            <i class="fas fa-building"></i>
                            <span data-ar="اسم الوكالة" data-en="Agency Name" data-fr="Nom de l'Agence">اسم الوكالة</span>
                            <span class="required">*</span>
                        </label>
                        <input type="text" id="agencyName" name="name" class="form-control" required
                               placeholder="أدخل اسم الوكالة" data-ar-placeholder="أدخل اسم الوكالة" 
                               data-en-placeholder="Enter agency name" data-fr-placeholder="Entrez le nom de l'agence"
                               oninput="validateField(this)">
                        <div class="error-message"></div>
                    </div>
                    <div class="form-group">
                        <label for="agencyEmail" class="form-label">
                            <i class="fas fa-envelope"></i>
                            <span data-ar="البريد الإلكتروني" data-en="Email" data-fr="Email">البريد الإلكتروني</span>
                            <span class="required">*</span>
                        </label>
                        <input type="email" id="agencyEmail" name="email" class="form-control" required 
                               placeholder="example@agency.com" data-ar-placeholder="example@agency.com"
                               oninput="validateField(this)">
                        <div class="error-message"></div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="agencyPhone" class="form-label">
                            <i class="fas fa-phone"></i>
                            <span data-ar="رقم الهاتف" data-en="Phone Number" data-fr="Numéro de Téléphone">رقم الهاتف</span>
                            <span class="required">*</span>
                        </label>
                        <input type="tel" id="agencyPhone" name="phone" class="form-control" required 
                               placeholder="+966 XX XXX XXXX" data-ar-placeholder="+966 XX XXX XXXX"
                               oninput="validateField(this)">
                        <div class="error-message"></div>
                    </div>
                    <div class="form-group">
                        <label for="agencyPassword" class="form-label">
                            <i class="fas fa-lock"></i>
                            <span data-ar="كلمة المرور" data-en="Password" data-fr="Mot de passe">كلمة المرور</span>
                            <span class="required">*</span>
                        </label>
                        <input type="password" id="agencyPassword" name="password" class="form-control" required 
                               placeholder="أدخل كلمة المرور" data-ar-placeholder="أدخل كلمة المرور"
                               oninput="validateField(this)">
                        <div class="error-message"></div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="agencyWilaya" class="form-label">
                            <i class="fas fa-map-marked-alt"></i>
                            <span data-ar="الولاية" data-en="State" data-fr="Wilaya">الولاية</span>
                            <span class="required">*</span>
                        </label>
                        <input type="text" id="agencyWilaya" name="wilaya" class="form-control" required 
                               placeholder="أدخل الولاية" data-ar-placeholder="أدخل الولاية"
                               oninput="validateField(this)">
                        <div class="error-message"></div>
                    </div>
                    <div class="form-group">
                        <label for="agencyPersonName" class="form-label">
                            <i class="fas fa-user"></i>
                            <span data-ar="اسم المسؤول" data-en="Responsible Name" data-fr="Nom du Responsable">اسم المسؤول</span>
                            <span class="required">*</span>
                        </label>
                        <input type="text" id="agencyPersonName" name="nom" class="form-control" required 
                               placeholder="أدخل اسم المسؤول" data-ar-placeholder="أدخل اسم المسؤول"
                               oninput="validateField(this)">
                        <div class="error-message"></div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="agencyLicense" class="form-label">
                            <i class="fas fa-id-card"></i>
                            <span data-ar="رقم الرخصة التجارية" data-en="Commercial License" data-fr="Licence Commerciale">رقم الرخصة التجارية</span>
                        </label>
                        <input type="text" id="agencyLicense" name="commercial_license_number" class="form-control"
                               placeholder="أدخل رقم الرخصة التجارية" data-ar-placeholder="أدخل رقم الرخصة التجارية"
                               oninput="validateField(this)">
                        <div class="error-message"></div>
                    </div>
                </div>

                <!-- حقل تحديد الموقع الجغرافي GPS -->
                <div class="form-group">
                    <label for="agencyLocation" class="form-label">
                        <i class="fas fa-map-marker-alt"></i>
                        <span data-ar="الموقع الجغرافي" data-en="Location (GPS)" data-fr="Emplacement (GPS)">الموقع الجغرافي</span>
                        <span class="required">*</span>
                    </label>
                    <div style="display: flex; gap: 8px;">
                        <input type="hidden" id="agencyLatitude" name="latitude">
                        <input type="hidden" id="agencyLongitude" name="longitude">
                        <input type="text" id="agencyLocation" name="address" class="form-control" required 
                            placeholder="اضغط لتحديد الموقع تلقائياً" data-ar-placeholder="اضغط لتحديد الموقع تلقائياً"
                            data-en-placeholder="Click to auto-detect location" data-fr-placeholder="Cliquez pour détecter l'emplacement"
                            readonly style="cursor:pointer;background:#f9f9f9;"
                            onclick="getLocationGPS(this)">
                        <button type="button" class="btn btn-success" style="min-width:120px;" onclick="getLocationGPS(document.getElementById('agencyLocation'))">
                            <i class="fas fa-location-arrow"></i>
                            <span data-ar="تحديد الموقع" data-en="Detect Location" data-fr="Détecter l'emplacement">تحديد الموقع</span>
                        </button>
                    </div>
                    <div class="error-message"></div>
                    <small class="form-text text-muted" id="gpsStatus"></small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="photoProfil" class="form-label">
                            <i class="fas fa-user-circle"></i>
                            <span data-ar="الصورة الشخصية" data-en="Profile Picture" data-fr="Photo de Profil">الصورة الشخصية</span>
                        </label>
                        <input type="file" id="photoProfil" name="photo_profil" class="form-control" accept="image/*"
                               onchange="validateImage(this)">
                        <small class="form-text text-muted">الحد الأقصى: 5 ميجابايت (JPG, PNG, GIF)</small>
                        <div class="error-message"></div>
                    </div>
                    <div class="form-group">
                        <label for="photoCouverture" class="form-label">
                            <i class="fas fa-image"></i>
                            <span data-ar="صورة الغلاف" data-en="Cover Picture" data-fr="Photo de Couverture">صورة الغلاف</span>
                        </label>
                        <input type="file" id="photoCouverture" name="photo_couverture" class="form-control" accept="image/*"
                               onchange="validateImage(this)">
                        <small class="form-text text-muted">الحد الأقصى: 5 ميجابايت (JPG, PNG, GIF)</small>
                        <div class="error-message"></div>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeAddModal()">
                    <i class="fas fa-times"></i>
                    <span data-ar="إلغاء" data-en="Cancel" data-fr="Annuler">إلغاء</span>
                </button>
                <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                    <i class="fas fa-save"></i>
                    <span data-ar="حفظ" data-en="Save" data-fr="Enregistrer">حفظ</span>
                </button>
            </div>
        </form>
    </div>
</div>
<script>
// دالة جلب الموقع الجغرافي GPS
function getLocationGPS(input) {
    const gpsStatus = document.getElementById('gpsStatus');
    gpsStatus.textContent = 'جاري تحديد الموقع...';
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            input.value = lat + ',' + lng;
            // تعبئة الحقول المخفية
            document.getElementById('agencyLatitude').value = lat;
            document.getElementById('agencyLongitude').value = lng;
            gpsStatus.textContent = 'تم تحديد الموقع بنجاح';
            input.classList.remove('error');
            input.nextElementSibling.style.display = 'none';
            updateSubmitButton();
        }, function(error) {
            let msg = 'تعذر تحديد الموقع';
            if (error.code === 1) msg = 'تم رفض إذن الموقع من المتصفح';
            else if (error.code === 2) msg = 'الموقع غير متوفر';
            else if (error.code === 3) msg = 'انتهت مهلة تحديد الموقع';
            gpsStatus.textContent = msg;
            input.value = '';
            document.getElementById('agencyLatitude').value = '';
            document.getElementById('agencyLongitude').value = '';
            input.classList.add('error');
            input.nextElementSibling.textContent = msg;
            input.nextElementSibling.style.display = 'block';
            updateSubmitButton();
        }, { enableHighAccuracy: true, timeout: 10000 });
    } else {
        gpsStatus.textContent = 'المتصفح لا يدعم تحديد الموقع';
        input.value = '';
        document.getElementById('agencyLatitude').value = '';
        document.getElementById('agencyLongitude').value = '';
        input.classList.add('error');
        input.nextElementSibling.textContent = 'المتصفح لا يدعم تحديد الموقع';
        input.nextElementSibling.style.display = 'block';
        updateSubmitButton();
    }
}
</script>

    <!-- Modal تعديل بيانات الوكالة -->
    <div id="editAgencyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-edit"></i>
                    <span data-ar="تعديل بيانات الوكالة" data-en="Edit Agency" data-fr="Modifier l'Agence">تعديل بيانات الوكالة</span>
                </h2>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <form id="editAgencyForm" onsubmit="submitEditAgency(event)" enctype="multipart/form-data">
                <input type="hidden" id="editAgencyId" name="id">
                <div class="form-scroll">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="editAgencyName" class="form-label">
                                <i class="fas fa-building"></i>
                                <span data-ar="اسم الوكالة" data-en="Agency Name" data-fr="Nom de l'Agence">اسم الوكالة</span>
                                <span class="required">*</span>
                            </label>
                            <input type="text" id="editAgencyName" name="name" class="form-control" required 
                                   placeholder="أدخل اسم الوكالة" data-ar-placeholder="أدخل اسم الوكالة" 
                                   data-en-placeholder="Enter agency name" data-fr-placeholder="Entrez le nom de l'agence"
                                   oninput="validateField(this)">
                            <div class="error-message"></div>
                        </div>
                        <div class="form-group">
                            <label for="editAgencyEmail" class="form-label">
                                <i class="fas fa-envelope"></i>
                                <span data-ar="البريد الإلكتروني" data-en="Email" data-fr="Email">البريد الإلكتروني</span>
                                <span class="required">*</span>
                            </label>
                            <input type="email" id="editAgencyEmail" name="email" class="form-control" required 
                                   placeholder="example@agency.com" data-ar-placeholder="example@agency.com"
                                   oninput="validateField(this)">
                            <div class="error-message"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="editAgencyPhone" class="form-label">
                                <i class="fas fa-phone"></i>
                                <span data-ar="رقم الهاتف" data-en="Phone Number" data-fr="Numéro de Téléphone">رقم الهاتف</span>
                                <span class="required">*</span>
                            </label>
                            <input type="tel" id="editAgencyPhone" name="phone" class="form-control" required 
                                   placeholder="+966 XX XXX XXXX" data-ar-placeholder="+966 XX XXX XXXX"
                                   oninput="validateField(this)">
                            <div class="error-message"></div>
                        </div>
                        <div class="form-group">
                            <label for="editAgencyPassword" class="form-label">
                                <i class="fas fa-lock"></i>
                                <span data-ar="كلمة المرور الجديدة" data-en="New Password" data-fr="Nouveau mot de passe">كلمة المرور الجديدة</span>
                            </label>
                            <input type="password" id="editAgencyPassword" name="password" class="form-control" 
                                   placeholder="اتركها فارغة إذا لم ترد تغييرها" data-ar-placeholder="اتركها فارغة إذا لم ترد تغييرها"
                                   oninput="validateField(this)">
                            <div class="error-message"></div>
                        </div>
                    </div>

                    <!-- حقل GPS بدلاً من العنوان -->
                    <div class="form-group">
                        <label for="editAgencyLocation" class="form-label">
                            <i class="fas fa-map-marker-alt"></i>
                            <span data-ar="الموقع الجغرافي (GPS)" data-en="Location (GPS)" data-fr="Emplacement (GPS)">الموقع الجغرافي (GPS)</span>
                            <span class="required">*</span>
                        </label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" id="editAgencyLocation" name="address" class="form-control" required 
                                placeholder="مثال: 24.7136,46.6753" data-ar-placeholder="مثال: 24.7136,46.6753"
                                data-en-placeholder="e.g. 24.7136,46.6753" data-fr-placeholder="ex: 24.7136,46.6753"
                                readonly style="cursor:pointer;background:#f9f9f9;"
                                onclick="confirmChangeGPS(this)">
                            <button type="button" class="btn btn-success" style="min-width:120px;" onclick="confirmChangeGPS(document.getElementById('editAgencyLocation'))">
                                <i class="fas fa-location-arrow"></i>
                                <span data-ar="تغيير الموقع" data-en="Change Location" data-fr="Changer l'emplacement">تغيير الموقع</span>
                            </button>
                        </div>
                        <div class="error-message"></div>
                        <small class="form-text text-muted" id="editGpsStatus"></small>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="editPhotoProfil" class="form-label">
                                <i class="fas fa-user-circle"></i>
                                <span data-ar="الصورة الشخصية" data-en="Profile Picture" data-fr="Photo de Profil">الصورة الشخصية</span>
                            </label>
                            <input type="file" id="editPhotoProfil" name="photo_profil" class="form-control" accept="image/*"
                                   onchange="validateImage(this)">
                            <small class="form-text text-muted">الحد الأقصى: 5 ميجابايت (JPG, PNG, GIF)</small>
                            <div class="error-message"></div>
                        </div>
                        <div class="form-group">
                            <label for="editPhotoCouverture" class="form-label">
                                <i class="fas fa-image"></i>
                                <span data-ar="صورة الغلاف" data-en="Cover Picture" data-fr="Photo de Couverture">صورة الغلاف</span>
                            </label>
                            <input type="file" id="editPhotoCouverture" name="photo_couverture" class="form-control" accept="image/*"
                                   onchange="validateImage(this)">
                            <small class="form-text text-muted">الحد الأقصى: 5 ميجابايت (JPG, PNG, GIF)</small>
                            <div class="error-message"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-check-circle"></i>
                            <span data-ar="حالة الوكالة" data-en="Agency Status" data-fr="Statut de l'Agence">حالة الوكالة</span>
                        </label>
                        <div class="form-check">
                            <input type="hidden" name="approved" value="0">
                            <input type="checkbox" id="editAgencyApproved" name="approved" class="form-check-input" value="1">
                            <label for="editAgencyApproved" class="form-check-label">
                                <span data-ar="موافقة على الوكالة" data-en="Approve Agency" data-fr="Approuver l'Agence">موافقة على الوكالة</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                        <i class="fas fa-times"></i>
                        <span data-ar="إلغاء" data-en="Cancel" data-fr="Annuler">إلغاء</span>
                    </button>
                    <button type="submit" class="btn btn-primary" id="editSubmitBtn">
                        <i class="fas fa-save"></i>
                        <span data-ar="حفظ التغييرات" data-en="Save Changes" data-fr="Enregistrer les modifications">حفظ التغييرات</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script>
    // تأكيد تغيير GPS ثم جلب الموقع
    function confirmChangeGPS(input) {
        if (confirm('هل أنت متأكد أنك تريد تغيير الموقع الجغرافي (GPS)؟')) {
            getEditLocationGPS(input);
        }
    }
    function getEditLocationGPS(input) {
        const gpsStatus = document.getElementById('editGpsStatus');
        gpsStatus.textContent = 'جاري تحديد الموقع...';
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                input.value = lat + ',' + lng;
                gpsStatus.textContent = 'تم تحديد الموقع بنجاح';
                input.classList.remove('error');
                input.nextElementSibling.style.display = 'none';
                updateSubmitButton();
            }, function(error) {
                let msg = 'تعذر تحديد الموقع';
                if (error.code === 1) msg = 'تم رفض إذن الموقع من المتصفح';
                else if (error.code === 2) msg = 'الموقع غير متوفر';
                else if (error.code === 3) msg = 'انتهت مهلة تحديد الموقع';
                gpsStatus.textContent = msg;
                input.value = '';
                input.classList.add('error');
                input.nextElementSibling.textContent = msg;
                input.nextElementSibling.style.display = 'block';
                updateSubmitButton();
            }, { enableHighAccuracy: true, timeout: 10000 });
        } else {
            gpsStatus.textContent = 'المتصفح لا يدعم تحديد الموقع';
            input.value = '';
            input.classList.add('error');
            input.nextElementSibling.textContent = 'المتصفح لا يدعم تحديد الموقع';
            input.nextElementSibling.style.display = 'block';
            updateSubmitButton();
        }
    }
    </script>

    <!-- عنصر لعرض رسائل النجاح -->
    <div class="success-message" id="successMessage" style="display: none;">
        <i class="fas fa-check-circle"></i>
        <span></span>
    </div>

    <!-- Modal تفاصيل الوكالة -->
    <div id="agencyDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>تفاصيل الوكالة</h2>
                <span class="close" onclick="closeDetailsModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="agency-details">
                    <div class="agency-header">
                        <div class="agency-cover">
                            <img id="detailsCoverPhoto" src="" alt="صورة الغلاف">
                        </div>
                        <div class="agency-profile">
                            <div class="profile-image">
                                <img id="detailsProfilePhoto" src="" alt="الصورة الشخصية">
                            </div>
                            <div class="profile-info">
                                <h3 id="detailsAgencyName"></h3>
                                <p id="detailsAgencyEmail"></p>
                                <p id="detailsAgencyPhone"></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="agency-info">
                        <div class="info-section">
                            <h4>معلومات الاتصال</h4>
                            <div class="info-grid">
                                <div class="info-item">
                                    <i class="fas fa-envelope"></i>
                                    <div>
                                        <label>البريد الإلكتروني</label>
                                        <p id="detailsEmail"></p>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-phone"></i>
                                    <div>
                                        <label>رقم الهاتف</label>
                                        <p id="detailsPhone"></p>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <div>
                                        <label>العنوان</label>
                                        <p id="detailsAddress"></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="info-section">
                            <h4>معلومات الحساب</h4>
                            <div class="info-grid">
                                <div class="info-item">
                                    <i class="fas fa-calendar-alt"></i>
                                    <div>
                                        <label>تاريخ التسجيل</label>
                                        <p id="detailsCreationDate"></p>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-clock"></i>
                                    <div>
                                        <label>آخر تحديث</label>
                                        <p id="detailsLastUpdate"></p>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-check-circle"></i>
                                    <div>
                                        <label>حالة الموافقة</label>
                                        <p id="detailsApprovalStatus"></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="info-section">
                            <h4>معلومات إضافية</h4>
                            <div class="info-grid">
                                <div class="info-item">
                                    <i class="fas fa-id-card"></i>
                                    <div>
                                        <label>رقم الرخصة التجارية</label>
                                        <p id="detailsLicense"></p>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <div>
                                        <label>الموقع الجغرافي</label>
                                        <p id="detailsLocation"></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeDetailsModal()">إغلاق</button>
            </div>
        </div>
    </div>

    <script>
        // دالة معاينة الشعار
        function previewLogo(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('logoPreview');
                    preview.innerHTML = `<img src="${e.target.result}" alt="Logo Preview">`;
                }
                reader.readAsDataURL(file);
            }
        }

        // دالة فتح نافذة إضافة وكالة جديدة
        function openAddModal() {
            document.getElementById('addAgencyModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        // دالة إغلاق نافذة إضافة وكالة جديدة
        function closeAddModal() {
            const modal = document.getElementById('addAgencyModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
            // إعادة تعيين النموذج
            document.getElementById('addAgencyForm').reset();
            // إعادة تعيين رسائل الخطأ
            document.querySelectorAll('.error-message').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.error').forEach(el => el.classList.remove('error'));
            // إعادة تعيين حالة زر الحفظ
            updateSubmitButton();
        }

        // دالة التحقق من الحقول
        function validateField(input) {
            const errorDiv = input.nextElementSibling;
            let isValid = true;
            let errorMessage = '';

            // إزالة حالة الخطأ السابقة
            input.classList.remove('error');
            errorDiv.style.display = 'none';

            // التحقق من الحقل الفارغ
            if (input.required && !input.value.trim()) {
                isValid = false;
                errorMesseag = 'هذا الحقل مطلوب';
            }

            // التحقق من البريد الإلكتروني
            if (input.type === 'email' && input.value) {
                const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
                if (!emailRegex.test(input.value)) {
                    isValid = false;
                    errorMessage = 'البريد الإلكتروني غير صالح';
                }
            }

            // التحقق من رقم الهاتف
            if (input.id === 'agencyPhone' && input.value) {
                const phoneRegex = /^[+]?[0-9\s-]{8,20}$/;
                if (!phoneRegex.test(input.value)) {
                    isValid = false;
                    errorMessage = 'رقم الهاتف غير صالح';
                }
            }

            // التحقق من كلمة المرور
            if (input.type === 'password' && input.value) {
                if (input.value.length < 6) {
                    isValid = false;
                    errorMessage = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل';
                }
            }

            // عرض رسالة الخطأ إذا كان هناك خطأ
            if (!isValid) {
                input.classList.add('error');
                errorDiv.textContent = errorMessage;
                errorDiv.style.display = 'block';
            }

            // تحديث حالة زر الحفظ
            updateSubmitButton();
        }

        // دالة التحقق من الصور
        function validateImage(input) {
            const errorDiv = input.nextElementSibling.nextElementSibling;
            let isValid = true;
            let errorMessage = '';

            // إزالة حالة الخطأ السابقة
            input.classList.remove('error');
            errorDiv.style.display = 'none';

            if (input.files.length > 0) {
                const file = input.files[0];
                const maxSize = 5 * 1024 * 1024; // 5MB
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];

                if (!allowedTypes.includes(file.type)) {
                    isValid = false;
                    errorMessage = 'نوع الملف غير مدعوم. يرجى رفع صورة بصيغة JPG أو PNG أو GIF';
                }

                if (file.size > maxSize) {
                    isValid = false;
                    errorMessage = 'حجم الملف كبير جداً. الحد الأقصى هو 5 ميجابايت';
                }
            }

            // عرض رسالة الخطأ إذا كان هناك خطأ
            if (!isValid) {
                input.classList.add('error');
                errorDiv.textContent = errorMessage;
                errorDiv.style.display = 'block';
            }

            // تحديث حالة زر الحفظ
            updateSubmitButton();
        }

        // دالة تحديث حالة زر الحفظ
        function updateSubmitButton() {
            const form = document.getElementById('addAgencyForm');
            const submitBtn = document.getElementById('submitBtn');
            const requiredFields = form.querySelectorAll('[required]');
            const errorFields = form.querySelectorAll('.error');
            
            // التحقق من جميع الحقول المطلوبة
            let isValid = true;
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                }
            });

            // التحقق من عدم وجود أخطاء
            if (errorFields.length > 0) {
                isValid = false;
            }

            // تحديث حالة الزر
            submitBtn.disabled = !isValid;
        }

        // دالة إرسال النموذج
        function submitAddAgency(event) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            formData.append('action', 'add');
            
            const submitBtn = form.querySelector('button[type="submit"]');
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
                    // إغلاق النافذة المنبثقة
                    closeAddModal();
                    // إظهار رسالة النجاح
                    showSuccessMessage(data.message);
                    // إعادة تحميل الصفحة بعد 1 ثانية
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    // إظهار رسالة الخطأ
                    alert(data.message);
                    // إعادة تفعيل زر الحفظ
                    submitBtn.innerHTML = originalContent;
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('خطأ:', error);
                alert('حدث خطأ أثناء إضافة الوكالة');
                // إعادة تفعيل زر الحفظ
                submitBtn.innerHTML = originalContent;
                submitBtn.disabled = false;
            });
        }

        // إضافة مستمع حدث للنموذج
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('addAgencyForm');
            if (form) {
                form.addEventListener('submit', submitAddAgency);
            }
        });

        // إغلاق النافذة عند النقر خارجها
        window.onclick = function(event) {
            const modal = document.getElementById('addAgencyModal');
            if (event.target == modal) {
                closeAddModal();
            }
        }

        // إغلاق النافذة عند الضغط على زر Escape
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAddModal();
            }
        });

        // دالة عرض تفاصيل الوكالة
        function viewAgency(id) {
            const agency = document.querySelector(`.agency-card[data-id="${id}"]`);
            const name = agency.querySelector('.agency-name').textContent;
            const email = agency.querySelector('.agency-email').textContent;
            const phone = agency.querySelector('.agency-phone').textContent;
            const status = agency.querySelector('.agency-status').textContent;
            const coverImage = agency.querySelector('.agency-header').style.backgroundImage;
            const profileImage = agency.querySelector('.agency-logo img')?.src || '../assets/images/default-profile.jpg';

            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">
                            <i class="fas fa-building"></i>
                            <span data-ar="تفاصيل الوكالة" data-en="Agency Details" data-fr="Détails de l'Agence">تفاصيل الوكالة</span>
                        </h2>
                        <span class="close" onclick="this.parentElement.parentElement.parentElement.remove()">&times;</span>
                    </div>
                    <div class="agency-details">
                        <div class="agency-cover" style="background-image: ${coverImage}">
                            <div class="agency-profile">
                                <img src="${profileImage}" 
                                     alt="Profile Picture" 
                                     onerror="this.src='../assets/images/default-profile.jpg'">
                            </div>
                        </div>
                        <div class="agency-info-details">
                            <h3>${name}</h3>
                            <p><i class="fas fa-envelope"></i> ${email}</p>
                            <p><i class="fas fa-phone"></i> ${phone}</p>
                            <p><i class="fas fa-check-circle"></i> <span data-ar="الحالة:" data-en="Status:" data-fr="Statut:">الحالة:</span> ${status}</p>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            modal.style.display = 'block';
        }

        // دالة فتح نافذة تعديل الوكالة
        function editAgency(id) {
            // جلب بيانات الوكالة من الخادم
            fetch('manage_agencies.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_agency&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // تعبئة النموذج ببيانات الوكالة
                    document.getElementById('editAgencyId').value = data.agency.id;
                    document.getElementById('editAgencyName').value = data.agency.nom_agence;
                    document.getElementById('editAgencyEmail').value = data.agency.email;
                    document.getElementById('editAgencyPhone').value = data.agency.telephone;
                    document.getElementById('editAgencyLocation').value = data.agency.wilaya;
                    document.getElementById('editAgencyApproved').checked = data.agency.approuve;
                    
                    // عرض النافذة المنبثقة
                    document.getElementById('editAgencyModal').style.display = 'block';
                    document.body.style.overflow = 'hidden';
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('حدث خطأ أثناء جلب بيانات الوكالة');
            });
        }

        // دالة إغلاق نافذة تعديل الوكالة
        function closeEditModal() {
            const modal = document.getElementById('editAgencyModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
            // إعادة تعيين النموذج
            document.getElementById('editAgencyForm').reset();
            // إعادة تعيين رسائل الخطأ
            document.querySelectorAll('.error-message').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.error').forEach(el => el.classList.remove('error'));
        }

        // دالة إرسال نموذج التعديل
        function submitEditAgency(event) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            // لا حاجة لإضافة approved=0 هنا لأن هناك input مخفي في النموذج
            formData.append('action', 'update');
            
            const submitBtn = form.querySelector('button[type="submit"]');
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
                    // إغلاق النافذة المنبثقة
                    closeEditModal();
                    // إظهار رسالة النجاح
                    showSuccessMessage(data.message);
                    // إعادة تحميل الصفحة بعد 1 ثانية
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    // إظهار رسالة الخطأ
                    alert(data.message);
                    // إعادة تفعيل زر الحفظ
                    submitBtn.innerHTML = originalContent;
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('حدث خطأ أثناء تحديث بيانات الوكالة');
                // إعادة تفعيل زر الحفظ
                submitBtn.innerHTML = originalContent;
                submitBtn.disabled = false;
            });
        }

        // إضافة مستمع حدث للنموذج
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('editAgencyForm');
            if (form) {
                form.addEventListener('submit', submitEditAgency);
            }
        });

        // إغلاق النافذة عند النقر خارجها
        window.onclick = function(event) {
            const modal = document.getElementById('editAgencyModal');
            if (event.target == modal) {
                closeEditModal();
            }
        }

        // إغلاق النافذة عند الضغط على زر Escape
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeEditModal();
            }
        });

        // دالة حذف الوكالة
        function deleteAgency(id) {
            const confirmMessage = {
                ar: 'هل أنت متأكد من حذف هذه الوكالة؟',
                en: 'Are you sure you want to delete this agency?',
                fr: 'Êtes-vous sûr de vouloir supprimer cette agence?'
            };
            
            const currentLang = document.documentElement.getAttribute('dir') === 'rtl' ? 'ar' : 
                              document.documentElement.getAttribute('dir') === 'ltr' ? 'en' : 'fr';
            
            if (confirm(confirmMessage[currentLang])) {
                fetch('manage_agencies.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=delete&id=${id}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const successMessage = {
                            ar: 'تم حذف الوكالة بنجاح',
                            en: 'Agency deleted successfully',
                            fr: 'Agence supprimée avec succès'
                        };
                        showSuccessMessage(successMessage[currentLang]);
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    const errorMessage = {
                        ar: 'حدث خطأ أثناء حذف الوكالة',
                        en: 'An error occurred while deleting the agency',
                        fr: 'Une erreur est survenue lors de la suppression de l\'agence'
                    };
                    alert(errorMessage[currentLang]);
                });
            }
        }

        // دالة تصدير البيانات
        function exportData() {
            // هنا يمكنك إضافة كود لتصدير البيانات
            alert('سيتم تصدير البيانات');
        }

        // دالة البحث عن الوكالات
        document.querySelector('.search-box input').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const agencyCards = document.querySelectorAll('.agency-card');

            agencyCards.forEach(card => {
                const name = card.querySelector('.agency-name').textContent.toLowerCase();
                const email = card.querySelector('.agency-email').textContent.toLowerCase();
                const phone = card.querySelector('.agency-phone').textContent.toLowerCase();

                if (name.includes(searchTerm) || email.includes(searchTerm) || phone.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // دالة تبديل اللغة
        document.querySelectorAll('.lang-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const lang = this.getAttribute('data-lang');
                document.querySelectorAll('.lang-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                // تحديث النصوص حسب اللغة المختارة
                document.querySelectorAll('[data-ar], [data-en], [data-fr]').forEach(element => {
                    const text = element.getAttribute(`data-${lang}`);
                    if (text) {
                        element.textContent = text;
                    }
                });

                // تحديث اتجاه الصفحة
                document.documentElement.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');
                
                // تحديث موضع الشريط الجانبي
                const sidebar = document.getElementById('sidebar');
                const mainContent = document.getElementById('mainContent');
                const sidebarToggle = document.querySelector('.sidebar-toggle');
                
                if (lang === 'ar') {
                    sidebar.style.right = '0';
                    sidebar.style.left = 'auto';
                    mainContent.style.marginRight = '280px';
                    mainContent.style.marginLeft = '0';
                    sidebarToggle.style.right = '20px';
                    sidebarToggle.style.left = 'auto';
                } else {
                    sidebar.style.left = '0';
                    sidebar.style.right = 'auto';
                    mainContent.style.marginLeft = '280px';
                    mainContent.style.marginRight = '0';
                    sidebarToggle.style.left = '20px';
                    sidebarToggle.style.right = 'auto';
                }

                // تحديث موضع الشريط الجانبي إذا كان مطوي
                if (sidebar.classList.contains('collapsed')) {
                    if (lang === 'ar') {
                        mainContent.style.marginRight = '80px';
                    } else {
                        mainContent.style.marginLeft = '80px';
                    }
                }
            });
        });

        // دالة تبديل القائمة الجانبية
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }

        // دالة إظهار رسالة النجاح
        function showSuccessMessage(message) {
            const successMessage = document.getElementById('successMessage');
            successMessage.querySelector('span').textContent = message;
            successMessage.style.display = 'flex';
            
            // إخفاء الرسالة بعد 3 ثواني
            setTimeout(() => {
                successMessage.style.display = 'none';
            }, 3000);
        }

        function showAgencyDetails(id) {
            fetch('manage_agencies.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_agency&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const agency = data.agency;
                    
                    // تحديث الصور مع معالجة الأخطاء
                    const profilePhoto = document.getElementById('detailsProfilePhoto');
                    const coverPhoto = document.getElementById('detailsCoverPhoto');
                    
                    // معالجة صورة الملف الشخصي
                    if (agency.photo_profil) {
                        profilePhoto.src = agency.photo_profil;
                        profilePhoto.onerror = function() {
                            this.src = 'assets/images/default-profile.png';
                        };
                    } else {
                        profilePhoto.src = 'assets/images/default-profile.png';
                    }
                    
                    // معالجة صورة الغلاف
                    if (agency.photo_couverture) {
                        coverPhoto.src = agency.photo_couverture;
                        coverPhoto.onerror = function() {
                            this.src = 'assets/images/default-cover.jpg';
                        };
                    } else {
                        coverPhoto.src = 'assets/images/default-cover.jpg';
                    }
                    
                    // تحديث المعلومات الأساسية
                    document.getElementById('detailsAgencyName').textContent = agency.nom_agence;
                    document.getElementById('detailsAgencyEmail').innerHTML = `<i class="fas fa-envelope"></i> ${agency.email}`;
                    document.getElementById('detailsAgencyPhone').innerHTML = `<i class="fas fa-phone"></i> ${agency.telephone}`;
                    
                    // تحديث معلومات الاتصال
                    document.getElementById('detailsEmail').textContent = agency.email;
                    document.getElementById('detailsPhone').textContent = agency.telephone;
                    document.getElementById('detailsAddress').textContent = agency.wilaya || 'غير محدد';
                    
                    // تحديث معلومات الحساب
                    document.getElementById('detailsCreationDate').textContent = new Date(agency.date_creation).toLocaleDateString('ar-SA');
                    document.getElementById('detailsLastUpdate').textContent = new Date(agency.date_modification).toLocaleDateString('ar-SA');
                    
                    const approvalStatus = document.getElementById('detailsApprovalStatus');
                    approvalStatus.textContent = agency.approuve ? 'موافق عليها' : 'في انتظار الموافقة';
                    approvalStatus.style.color = agency.approuve ? '#27ae60' : '#e74c3c';
                    
                    // إضافة الحقول الجديدة في نافذة التفاصيل
                    document.getElementById('detailsLicense').textContent = agency.commercial_license_number || 'غير متوفر';
                    document.getElementById('detailsLocation').textContent = (agency.latitude && agency.longitude) ? (agency.latitude + ', ' + agency.longitude) : 'غير محدد';
                    
                    // عرض النافذة
                    document.getElementById('agencyDetailsModal').style.display = 'block';
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('حدث خطأ أثناء جلب بيانات الوكالة');
            });
        }

        function closeDetailsModal() {
            document.getElementById('agencyDetailsModal').style.display = 'none';
        }

        // إغلاق النافذة عند النقر خارجها
        window.onclick = function(event) {
            const modal = document.getElementById('agencyDetailsModal');
            if (event.target == modal) {
                closeDetailsModal();
            }
        }
    </script>
</body>
</html>