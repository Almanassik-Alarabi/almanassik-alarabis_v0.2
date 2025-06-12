<?php
// add_agency.php
header('Content-Type: application/json');
require_once '../../includes/config.php';

$response = ['success' => false, 'message' => 'حدث خطأ غير متوقع', 'debug_info' => ''];

error_log('Add Agency Request: ' . print_r($_POST, true));
error_log('Add Agency Files: ' . print_r($_FILES, true));

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('طريقة الطلب غير صحيحة');
    }

    // التحقق من الحقول المطلوبة
    $required = ['nom_agence', 'email', 'telephone', 'mot_de_passe', 'wilaya'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception('الحقل المطلوب غير موجود: ' . $field);
        }
    }

    $name = trim($_POST['nom_agence']);
    $email = trim(strtolower($_POST['email']));
    $phone = trim($_POST['telephone']);
    $password = password_hash($_POST['mot_de_passe'], PASSWORD_DEFAULT);
    $wilaya = trim($_POST['wilaya']);
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $commercial_license_number = isset($_POST['commercial_license_number']) ? trim($_POST['commercial_license_number']) : '';
    $nom = isset($_POST['nom']) ? trim($_POST['nom']) : '';

    // الموقع الجغرافي
    $latitude = null;
    $longitude = null;
    if (strpos($address, ',') !== false) {
        list($latitude, $longitude) = array_map('trim', explode(',', $address));
    }

    // رفع الصور
    $photo_profil = '';
    $photo_couverture = '';
    $uploadDir = '../uploads/agences/';
    if (!file_exists($uploadDir.'profil/')) mkdir($uploadDir.'profil/', 0777, true);
    if (!file_exists($uploadDir.'couverture/')) mkdir($uploadDir.'couverture/', 0777, true);

    if (!empty($_FILES['photo_profil']['name'])) {
        $ext = pathinfo($_FILES['photo_profil']['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . basename($_FILES['photo_profil']['name']);
        $target = $uploadDir.'profil/'.$filename;
        if (move_uploaded_file($_FILES['photo_profil']['tmp_name'], $target)) {
            $photo_profil = 'uploads/agences/profil/'.$filename;
        } else {
            throw new Exception('فشل رفع صورة الملف الشخصي');
        }
    }
    if (!empty($_FILES['photo_couverture']['name'])) {
        $ext = pathinfo($_FILES['photo_couverture']['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . basename($_FILES['photo_couverture']['name']);
        $target = $uploadDir.'couverture/'.$filename;
        if (move_uploaded_file($_FILES['photo_couverture']['tmp_name'], $target)) {
            $photo_couverture = 'uploads/agences/couverture/'.$filename;
        } else {
            throw new Exception('فشل رفع صورة الغلاف');
        }
    }

    // استخدم روابط الصور من الحقول المخفية إذا كانت موجودة
    if (isset($_POST['photo_profil_url']) && !empty($_POST['photo_profil_url'])) {
        $photo_profil = trim($_POST['photo_profil_url']);
    }
    if (isset($_POST['photo_couverture_url']) && !empty($_POST['photo_couverture_url'])) {
        $photo_couverture = trim($_POST['photo_couverture_url']);
    }

    // حالة الموافقة افتراضيًا: false (معلق)
    $approuve = 'false';

    // التحقق من اتصال قاعدة البيانات
    if (!isset($pdo)) {
        throw new Exception('فشل الاتصال بقاعدة البيانات');
    }

    // التحقق من صحة البريد الإلكتروني
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'البريد الإلكتروني غير صالح']);
        exit;
    }
    // التحقق من عدم تكرار البريد الإلكتروني
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM agencies WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'البريد الإلكتروني مستخدم بالفعل']);
        exit;
    }
    // التحقق من رقم الهاتف
    if (!preg_match('/^\d{8,15}$/', $phone)) {
        echo json_encode(['success' => false, 'message' => 'رقم الهاتف غير صالح']);
        exit;
    }
    // التحقق من قوة كلمة المرور (8 أحرف على الأقل، حرف كبير وصغير ورقم)
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password)) {
        echo json_encode(['success' => false, 'message' => 'كلمة المرور ضعيفة']);
        exit;
    }

    // إدخال البيانات في قاعدة البيانات
    $stmt = $pdo->prepare("
        INSERT INTO agences (
            nom_agence, email, telephone, wilaya, mot_de_passe, approuve,
            photo_profil, photo_couverture, nom, commercial_license_number,
            latitude, longitude, date_creation, date_modification
        ) VALUES (
            :nom_agence, :email, :telephone, :wilaya, :mot_de_passe, :approuve,
            :photo_profil, :photo_couverture, :nom, :commercial_license_number,
            :latitude, :longitude, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )
    ");

    $result = $stmt->execute([
        ':nom_agence' => $name,
        ':email' => $email,
        ':telephone' => $phone,
        ':wilaya' => $wilaya,
        ':mot_de_passe' => $password,
        ':approuve' => $approuve,
        ':photo_profil' => $photo_profil,
        ':photo_couverture' => $photo_couverture,
        ':nom' => $nom,
        ':commercial_license_number' => $commercial_license_number,
        ':latitude' => $latitude,
        ':longitude' => $longitude
    ]);

    if ($result) {
        $response = [
            'success' => true,
            'message' => 'تم إضافة الوكالة بنجاح'
        ];
    } else {
        throw new Exception('فشل في إضافة الوكالة: ' . implode(', ', $stmt->errorInfo()));
    }
} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage() . "\n" .
              'Stack Trace: ' . $e->getTraceAsString() . "\n" .
              'POST Data: ' . print_r($_POST, true) . "\n" .
              'Files: ' . print_r($_FILES, true));
    $response = [
        'success' => false,
        'message' => 'حدث خطأ في قاعدة البيانات',
        'debug_info' => $e->getMessage()
    ];
} catch (Exception $e) {
    error_log('General Error: ' . $e->getMessage() . "\n" .
              'Stack Trace: ' . $e->getTraceAsString() . "\n" .
              'POST Data: ' . print_r($_POST, true) . "\n" .
              'Files: ' . print_r($_FILES, true));
    $response = [
        'success' => false,
        'message' => $e->getMessage()
    ];
}

echo json_encode($response);
