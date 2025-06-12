<?php
// تمكين إعدادات التصحيح
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/agency_errors.log');

// إعدادات اتصال Supabase
$DB_CONFIG = [
    'host' => 'aws-0-eu-west-3.pooler.supabase.com',
    'port' => '6543',
    'dbname' => 'postgres',
    'user' => 'postgres.zrwtxvybdxphylsvjopi',
    'password' => 'Dj123456789.',
    'sslmode' => 'require',
    'schema' => 'public' // المخطط الافتراضي في Supabase
];

// إنشاء اتصال PDO مع Supabase PostgreSQL
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
        PDO::ATTR_PERSISTENT => false
    ];
    
    $GLOBALS['pdo'] = new PDO($dsn, $DB_CONFIG['user'], $DB_CONFIG['password'], $options);
    
    // تعيين المخطط إذا كان مختلفاً عن الافتراضي
    if (isset($DB_CONFIG['schema'])) {
        $GLOBALS['pdo']->exec("SET search_path TO " . $DB_CONFIG['schema']);
    }
    
} catch (\PDOException $e) {
    error_log('فشل الاتصال بقاعدة البيانات: ' . $e->getMessage());
    die('حدث خطأ في الاتصال بالنظام. يرجى المحاولة لاحقاً.');
}

// دالة مساعدة للاستعلامات الآمنة
function executeQuery($sql, $params = []) {
    try {
        $stmt = $GLOBALS['pdo']->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (\PDOException $e) {
        error_log('خطأ في تنفيذ الاستعلام: ' . $e->getMessage());
        return false;
    }
}

// بدء الجلسة إذا لم تكن بدأت
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// إعدادات قاعدة البيانات المحلية (إذا لزم الأمر)
$db_host = 'localhost';
$db_user = 'اسم_المستخدم_الحقيقي';
$db_pass = 'كلمة_المرور_الحقيقية';
$db_name = 'اسم_قاعدة_البيانات_الحقيقية';
?>