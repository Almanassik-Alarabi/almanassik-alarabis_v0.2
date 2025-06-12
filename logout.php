<?php
// logout.php
session_start();
// حذف جميع متغيرات الجلسة
$_SESSION = array();
// إذا كان هناك ملف تعريف ارتباط للجلسة، قم بحذفه
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
// إنهاء الجلسة
session_destroy();
// إعادة التوجيه إلى صفحة تسجيل الدخول
header("Location: login_admin.php");
exit;
