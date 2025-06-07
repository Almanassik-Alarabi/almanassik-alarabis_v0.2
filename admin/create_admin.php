<?php
// إعدادات اتصال قاعدة البيانات
$DB_CONFIG = array(
    'host' => 'aws-0-eu-west-3.pooler.supabase.com',
    'port' => '6543',
    'dbname' => 'postgres',
    'user' => 'postgres.zrwtxvybdxphylsvjopi',
    'password' => 'Dj123456789.',
    'sslmode' => 'require',
    'schema' => 'public'
);

try {
    // إنشاء اتصال PDO
    $dsn = sprintf(
        "pgsql:host=%s;port=%s;dbname=%s;sslmode=%s",
        $DB_CONFIG['host'],
        $DB_CONFIG['port'],
        $DB_CONFIG['dbname'],
        $DB_CONFIG['sslmode']
    );
    
    $pdo = new PDO($dsn, $DB_CONFIG['user'], $DB_CONFIG['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    if (isset($DB_CONFIG['schema'])) {
        $pdo->exec("SET search_path TO " . $DB_CONFIG['schema']);
    }

    // كلمة المرور الأصلية
    $password = 'admin123';
    
    // تشفير كلمة المرور
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // التحقق من وجود المدير العام
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = :email");
    $stmt->execute(['email' => 'admin@umrah.com']);
    
    if ($stmt->rowCount() > 0) {
        // تحديث كلمة المرور إذا كان المدير موجوداً
        $stmt = $pdo->prepare("UPDATE admins SET mot_de_passe = :password WHERE email = :email");
        $stmt->execute([
            'password' => $hashed_password,
            'email' => 'admin@umrah.com'
        ]);
        echo "تم تحديث كلمة مرور المدير العام بنجاح\n";
    } else {
        // إضافة المدير العام إذا لم يكن موجوداً
        $stmt = $pdo->prepare("INSERT INTO admins (nom, email, mot_de_passe) VALUES (:nom, :email, :password)");
        $stmt->execute([
            'nom' => 'المدير العام',
            'email' => 'admin@umrah.com',
            'password' => $hashed_password
        ]);
        echo "تم إضافة المدير العام بنجاح\n";
    }
    
    echo "بيانات تسجيل الدخول:\n";
    echo "البريد الإلكتروني: admin@umrah.com\n";
    echo "كلمة المرور: admin123\n";
    
} catch (PDOException $e) {
    echo "خطأ: " . $e->getMessage() . "\n";
}
?> 