<?php
// معالجة المتغيرات لتفادي التحذيرات
if (!isset($stats) || !is_array($stats)) {
    $stats = [
        'total_pilgrims' => 0,
        'pending_requests' => 0,
        'approved_requests' => 0,
        'nationalities' => 0
    ];
}
if (!isset($search)) $search = '';
if (!isset($nationalities) || !is_array($nationalities)) $nationalities = [];
if (!isset($nationality_filter)) $nationality_filter = '';
if (!isset($pilgrims) || !is_array($pilgrims)) $pilgrims = [];
if (!isset($page)) $page = 1;
if (!isset($total_pages)) $total_pages = 1;

// إعداد الاتصال بقاعدة البيانات
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

    // فلترة المعتمرين: استخدم بيانات الفلترة من $_GET أو من جافاسكريبت
    $where = [];
    $params = [];
    // استخدم isset بدلاً من !empty حتى لا يتم تجاهل القيم الفارغة (مثلاً "0")
    if (isset($_GET['search']) && trim($_GET['search']) !== '') {
        $search = trim($_GET['search']);
        $where[] = "(d.nom ILIKE :search OR d.telephone ILIKE :search OR CAST(d.offre_id AS TEXT) ILIKE :search)";
        $params[':search'] = "%$search%";
    }
    if (isset($_GET['wilaya']) && $_GET['wilaya'] !== '') {
        $where[] = "d.wilaya = :wilaya";
        $params[':wilaya'] = $_GET['wilaya'];
    }
    if (isset($_GET['statut']) && $_GET['statut'] !== '') {
        $where[] = "d.statut = :statut";
        $params[':statut'] = $_GET['statut'];
    }

    $sql = "SELECT d.*, o.titre AS offre_titre, a.nom_agence AS agence_nom
            FROM demande_umrah d
            LEFT JOIN offres o ON d.offre_id = o.id
            LEFT JOIN agences a ON o.agence_id = a.id";
    if ($where) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " ORDER BY d.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pilgrims = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // احصائيات مباشرة من قاعدة البيانات
    $stats['total_pilgrims'] = (int)$pdo->query("SELECT COUNT(*) FROM demande_umrah")->fetchColumn();
    $stats['pending_requests'] = (int)$pdo->query("SELECT COUNT(*) FROM demande_umrah WHERE statut = 'en_attente'")->fetchColumn();
    $stats['approved_requests'] = (int)$pdo->query("SELECT COUNT(*) FROM demande_umrah WHERE statut = 'accepte'")->fetchColumn();
    $stats['nationalities'] = 0; // حذف احصائيات الجنسيات

} catch (Exception $e) {
    $pilgrims = [];
    // ...existing error handling...
}

// دالة لحساب الوقت المنقضي بشكل نصي
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'سنة',
        'm' => 'شهر',
        'w' => 'أسبوع',
        'd' => 'يوم',
        'h' => 'ساعة',
        'i' => 'دقيقة',
        's' => 'ثانية',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v;
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? 'منذ ' . implode(', ', $string) : 'الآن';
}

// قائمة ولايات الجزائر
$algerian_wilayas = [
    "أدرار","الشلف","الأغواط","أم البواقي","باتنة","بجاية","بسكرة","بشار","البليدة","البويرة",
    "تمنراست","تبسة","تلمسان","تيارت","تيزي وزو","الجزائر","الجلفة","جيجل","سطيف","سعيدة",
    "سكيكدة","سيدي بلعباس","عنابة","قالمة","قسنطينة","المدية","مستغانم","المسيلة","معسكر","ورقلة",
    "وهران","البيض","إليزي","برج بوعريريج","بومرداس","الطارف","تندوف","تيسمسيلت","الوادي","خنشلة",
    "سوق أهراس","تيبازة","ميلة","عين الدفلى","النعامة","عين تموشنت","غرداية","غليزان"
];

// جلب العروض من قاعدة البيانات
$offres = [];
try {
    $stmtOffres = $pdo->query("SELECT id, titre FROM offres ORDER BY id DESC");
    $offres = $stmtOffres->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $offres = [];
}

// تعديل بيانات معتمر (باستخدام POST من نافذة التعديل)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_pilgrim']) && isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $nom = trim($_POST['nom']);
    $telephone = trim($_POST['telephone']);
    $wilaya = trim($_POST['wilaya']);
    $offre_id = intval($_POST['offre_id']);
    $statut = in_array($_POST['statut'], ['en_attente','accepte','refuse']) ? $_POST['statut'] : 'en_attente';
    $passport_image = null;

    // تحقق من أن رقم الهاتف أرقام فقط
    if (!preg_match('/^[0-9]+$/', $telephone)) {
        echo '<div id="errorToast" style="position:fixed;top:30px;right:30px;z-index:9999;background:linear-gradient(135deg,#fa709a 0%,#fee140 100%);color:#fff;padding:16px 32px;border-radius:8px;box-shadow:0 4px 15px rgba(0,0,0,0.1);font-size:1.1rem;font-family:Cairo,sans-serif;">يرجى إدخال رقم هاتف صحيح (أرقام فقط)</div>';
        echo '<script>
            setTimeout(function(){
                var toast=document.getElementById("errorToast");
                if(toast){toast.style.transition="opacity 0.5s";toast.style.opacity=0;}
            },1800);
        </script>';
    } else {
        // إذا تم رفع صورة جواز جديدة قم بتحديثها
        if (isset($_FILES['passport_image']) && $_FILES['passport_image']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['passport_image']['name'], PATHINFO_EXTENSION);
            $filename = uniqid('passport_') . '.' . $ext;
            $upload_dir = __DIR__ . '/uploads/passports/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $target = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['passport_image']['tmp_name'], $target)) {
                // حذف الصورة القديمة
                $stmt = $pdo->prepare("SELECT passport_image FROM demande_umrah WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['passport_image'])) {
                    $old_path = $upload_dir . $row['passport_image'];
                    if (file_exists($old_path)) @unlink($old_path);
                }
                $passport_image = $filename;
            }
        }

        if ($passport_image) {
            $stmt = $pdo->prepare("UPDATE demande_umrah SET nom=?, telephone=?, wilaya=?, offre_id=?, statut=?, passport_image=? WHERE id=?");
            $success = $stmt->execute([$nom, $telephone, $wilaya, $offre_id, $statut, $passport_image, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE demande_umrah SET nom=?, telephone=?, wilaya=?, offre_id=?, statut=? WHERE id=?");
            $success = $stmt->execute([$nom, $telephone, $wilaya, $offre_id, $statut, $id]);
        }

        if ($success) {
            echo '<div id="successToast" style="position:fixed;top:30px;right:30px;z-index:9999;background:linear-gradient(135deg,#4facfe 0%,#00f2fe 100%);color:#fff;padding:16px 32px;border-radius:8px;box-shadow:0 4px 15px rgba(0,0,0,0.1);font-size:1.1rem;font-family:Cairo,sans-serif;">تم تحديث بيانات المعتمر بنجاح</div>';
            echo '<script>
                setTimeout(function(){
                    var toast=document.getElementById("successToast");
                    if(toast){toast.style.transition="opacity 0.5s";toast.style.opacity=0;}
                },1800);
                setTimeout(function(){
                    window.location.replace(window.location.pathname);
                },2000);
            </script>';
            return;
        } else {
            echo "<script>
                setTimeout(function(){alert('حدث خطأ أثناء تحديث بيانات المعتمر');}, 100);
            </script>";
        }
    }
}

// معالجة إضافة معتمر جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_pilgrim'])) {
    $nom = trim($_POST['nom']);
    $telephone = trim($_POST['telephone']);
    $wilaya = trim($_POST['wilaya']);
    $offre_id = intval($_POST['offre_id']);
    $statut = in_array($_POST['statut'], ['en_attente','accepte','refuse']) ? $_POST['statut'] : 'en_attente';
    $passport_image = null;

    // تحقق من أن رقم الهاتف أرقام فقط
    if (!preg_match('/^[0-9]+$/', $telephone)) {
        echo '<div id="errorToast" style="position:fixed;top:30px;right:30px;z-index:9999;background:linear-gradient(135deg,#fa709a 0%,#fee140 100%);color:#fff;padding:16px 32px;border-radius:8px;box-shadow:0 4px 15px rgba(0,0,0,0.1);font-size:1.1rem;font-family:Cairo,sans-serif;">يرجى إدخال رقم هاتف صحيح (أرقام فقط)</div>';
        echo '<script>
            setTimeout(function(){
                var toast=document.getElementById("errorToast");
                if(toast){toast.style.transition="opacity 0.5s";toast.style.opacity=0;}
            },1800);
        </script>';
    } else {
        // رفع صورة الجواز إذا وجدت
        if (isset($_FILES['passport_image']) && $_FILES['passport_image']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['passport_image']['name'], PATHINFO_EXTENSION);
            $filename = uniqid('passport_') . '.' . $ext;
            $upload_dir = __DIR__ . '/uploads/passports/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $target = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['passport_image']['tmp_name'], $target)) {
                $passport_image = $filename;
            }
        }

        // إدراج في قاعدة البيانات
        $stmt = $pdo->prepare("INSERT INTO demande_umrah (nom, telephone, wilaya, offre_id, statut, passport_image) VALUES (?, ?, ?, ?, ?, ?)");
        $success = $stmt->execute([$nom, $telephone, $wilaya, $offre_id, $statut, $passport_image]);
        if ($success) {
            // عرض رسالة نجاح بدون alert/ok، استخدم رسالة عائمة (toast) وتحديث الصفحة تلقائياً
            echo '<div id="successToast" style="position:fixed;top:30px;right:30px;z-index:9999;background:linear-gradient(135deg,#4facfe 0%,#00f2fe 100%);color:#fff;padding:16px 32px;border-radius:8px;box-shadow:0 4px 15px rgba(0,0,0,0.1);font-size:1.1rem;font-family:Cairo,sans-serif;">تمت إضافة المعتمر بنجاح</div>';
            echo '<script>
                setTimeout(function(){
                    var toast=document.getElementById("successToast");
                    if(toast){toast.style.transition="opacity 0.5s";toast.style.opacity=0;}
                },1800);
                setTimeout(function(){
                    window.location.replace(window.location.pathname);
                },2000);
            </script>';
            return;
        } else {
            echo "<script>
                setTimeout(function(){alert('حدث خطأ أثناء إضافة المعتمر');}, 100);
            </script>";
        }
    }
}

// حذف معتمر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_pilgrim_id'])) {
    $delete_id = intval($_POST['delete_pilgrim_id']);
    // حذف صورة الجواز من السيرفر إذا وجدت
    $stmt = $pdo->prepare("SELECT passport_image FROM demande_umrah WHERE id = ?");
    $stmt->execute([$delete_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['passport_image'])) {
        $img_path = __DIR__ . '/uploads/passports/' . $row['passport_image'];
        if (file_exists($img_path)) @unlink($img_path);
    }
    // حذف السجل من قاعدة البيانات
    $stmt = $pdo->prepare("DELETE FROM demande_umrah WHERE id = ?");
    $stmt->execute([$delete_id]);
    // إعادة تحميل الصفحة بعد الحذف
    echo '<script>window.location.href=window.location.pathname;</script>';
}

// حذف معتمر عبر AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete_pilgrim_id'])) {
    $delete_id = intval($_POST['ajax_delete_pilgrim_id']);
    $stmt = $pdo->prepare("SELECT passport_image FROM demande_umrah WHERE id = ?");
    $stmt->execute([$delete_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['passport_image'])) {
        $img_path = __DIR__ . '/uploads/passports/' . $row['passport_image'];
        if (file_exists($img_path)) @unlink($img_path);
    }
    $stmt = $pdo->prepare("DELETE FROM demande_umrah WHERE id = ?");
    $stmt->execute([$delete_id]);
    echo 'OK';
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المعتمرين | Pilgrims Management</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --success: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --warning: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --danger: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --info: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
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

        /* Sidebar Styles - Same as dashboard */
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
            margin-right: 280px;
            padding: 2rem;
            transition: all 0.3s ease;
        }

        .main-content.expanded {
            margin-right: 80px;
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--primary);
        }

        .stat-card:nth-child(2)::before {
            background: var(--warning);
        }

        .stat-card:nth-child(3)::before {
            background: var(--success);
        }

        .stat-card:nth-child(4)::before {
            background: var(--info);
        }

        .stat-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-hover);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        .stat-card:nth-child(1) .stat-icon {
            background: var(--primary);
        }

        .stat-card:nth-child(2) .stat-icon {
            background: var(--warning);
        }

        .stat-card:nth-child(3) .stat-icon {
            background: var(--success);
        }

        .stat-card:nth-child(4) .stat-icon {
            background: var(--info);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .stat-change {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
            color: #28a745;
            font-weight: 600;
        }

        /* Content Card */
        .content-card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .content-header {
            padding: 2rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .content-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .content-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        /* Search and Filter */
        .search-filter {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
        }

        .search-input {
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: 2px solid transparent;
            border-radius: 25px;
            background: rgba(255,255,255,0.7);
            font-size: 1rem;
            width: 300px;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: var(--shadow);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }

        .filter-select {
            padding: 0.75rem 1rem;
            border: 2px solid transparent;
            border-radius: 25px;
            background: rgba(255,255,255,0.7);
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-select:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: var(--shadow);
        }

        /* Action Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
        }

        .pilgrims-table {
            width: 100%;
            border-collapse: collapse;
        }

        .pilgrims-table th,
        .pilgrims-table td {
            padding: 1.5rem 1rem;
            text-align: right;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }

        .pilgrims-table th {
            background: rgba(102, 126, 234, 0.1);
            font-weight: 600;
            color: var(--text-primary);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .pilgrims-table tr {
            transition: all 0.3s ease;
        }

        .pilgrims-table tr:hover {
            background: rgba(102, 126, 234, 0.05);
            transform: scale(1.01);
        }

        .pilgrim-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .pilgrim-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .pilgrim-details h4 {
            margin: 0 0 0.25rem 0;
            color: var(--text-primary);
            font-weight: 600;
        }

        .pilgrim-details p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-align: center;
        }

        .status-active {
            background: rgba(76, 175, 80, 0.1);
            color: #4caf50;
        }

        .status-pending {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
        }

        .action-btn {
            width: 35px;
            height: 35px;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            color: white;
        }

        .action-btn:hover {
            transform: scale(1.1);
            box-shadow: var(--shadow);
        }

        .btn-view { background: var(--info); }
        .btn-edit { background: var(--warning); }
        .btn-delete { background: var(--danger); }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 2rem;
        }

        .page-btn {
            padding: 0.75rem 1rem;
            border: none;
            border-radius: 10px;
            background: rgba(255,255,255,0.7);
            color: var(--text-primary);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
        }

        .page-btn:hover,
        .page-btn.active {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .page-info {
            margin: 0 1rem;
            color: var(--text-secondary);
            font-weight: 600;
        }

        /* Toggle Button */
        .sidebar-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
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
            z-index: 1001;
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
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: var(--card-bg);
            margin: 5% auto;
            padding: 2rem;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 600px;
            box-shadow: var(--shadow-hover);
            position: relative;
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .close-btn:hover {
            background: rgba(0,0,0,0.1);
            color: var(--text-primary);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid rgba(0,0,0,0.1);
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: var(--shadow);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-right: 0;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .content-header {
                flex-direction: column;
                align-items: stretch;
            }

            .content-actions {
                justify-content: center;
            }

            .search-filter {
                flex-direction: column;
                align-items: stretch;
            }

            .search-input {
                width: 100%;
            }

            .pilgrims-table {
                font-size: 0.9rem;
            }

            .pilgrims-table th,
            .pilgrims-table td {
                padding: 1rem 0.5rem;
            }

            .pilgrim-info {
                flex-direction: column;
                text-align: center;
            }

            .action-buttons {
                flex-direction: column;
            }
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .stat-card {
            animation: fadeInUp 0.6s ease forwards;
        }

        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        .stat-card:nth-child(4) { animation-delay: 0.4s; }

        .content-card {
            animation: fadeInUp 0.6s ease forwards;
            animation-delay: 0.5s;
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
                    <a href="manage_agencies.php">
                        <i class="fas fa-building"></i>
                        <span class="sidebar-text" data-ar="إدارة الوكالات" data-en="Manage Agencies" data-fr="Gérer les Agences">إدارة الوكالات</span>
                    </a>
                </li>
                <li>
                    <a href="demande_umrah.php" class="active">
                        <i class="fas fa-users"></i>
                        <span class="sidebar-text" data-ar="إدارة المعتمرين" data-en="Manage Pilgrims" data-fr="Gérer les Pèlerins">إدارة المعتمرين</span>
                    </a>
                </li>
                <li>
                    <a href="#">
                        <i class="fas fa-tags"></i>
                        <span class="sidebar-text" data-ar="إدارة العروض" data-en="Manage Offers" data-fr="Gérer les Offres">إدارة العروض</span>
                    </a>
                </li>
                <li>
                    <a href="#">
                        <i class="fas fa-clipboard-list"></i>
                        <span class="sidebar-text" data-ar="إدارة الطلبات" data-en="Manage Requests" data-fr="Gérer les Demandes">إدارة الطلبات</span>
                    </a>
                </li>
                <li>
                    <a href="#">
                        <i class="fas fa-user-shield"></i>
                        <span class="sidebar-text" data-ar="إدارة المدراء" data-en="Manage Admins" data-fr="Gérer les Admins">إدارة المدراء</span>
                    </a>
                </li>
                <li>
                    <a href="#">
                        <i class="fas fa-user-cog"></i>
                        <span class="sidebar-text" data-ar="إدارة المدراء الثانويين" data-en="Manage Secondary Admins" data-fr="Gérer les Admins Secondaires">إدارة المدراء الثانويين</span>
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
                    <h1 data-ar="إدارة المعتمرين" data-en="Pilgrims Management" data-fr="Gestion des pèlerins">إدارة المعتمرين</h1>
                    <p data-ar="إدارة وتتبع جميع المعتمرين المسجلين" data-en="Manage and track all registered pilgrims" data-fr="Gérer et suivre tous les pèlerins enregistrés">إدارة وتتبع جميع المعتمرين المسجلين</p>
                </div>
                <div class="header-right">
                    <div class="language-switcher">
                        <button class="lang-btn active" data-lang="ar">العربية</button>
                        <button class="lang-btn" data-lang="en">English</button>
                        <button class="lang-btn" data-lang="fr">Français</button>
                    </div>
                    <div class="user-info">
                        <div class="user-avatar">م</div>
                        <div>
                            <div class="user-name" data-ar="محمد المدير" data-en="Admin Mohamed" data-fr="Admin Mohamed">محمد المدير</div>
                            <div class="user-role" data-ar="مدير النظام" data-en="System Admin" data-fr="Administrateur">مدير النظام</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo number_format($stats['total_pilgrims']); ?></div>
                            <div class="stat-label" data-ar="إجمالي المعتمرين" data-en="Total Pilgrims" data-fr="Total pèlerins">إجمالي المعتمرين</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-change">
                        <i class="fas fa-arrow-up"></i>
                        <span data-ar="زيادة 12% عن الشهر الماضي" data-en="12% increase from last month" data-fr="12% d'augmentation par rapport au mois dernier">زيادة 12% عن الشهر الماضي</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo number_format($stats['pending_requests']); ?></div>
                            <div class="stat-label" data-ar="طلبات معلقة" data-en="Pending Requests" data-fr="Demandes en attente">طلبات معلقة</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-change text-warning">
                        <i class="fas fa-arrow-up"></i>
                        <span data-ar="زيادة 5% عن الأسبوع الماضي" data-en="5% increase from last week" data-fr="5% d'augmentation par rapport à la semaine dernière">زيادة 5% عن الأسبوع الماضي</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo number_format($stats['approved_requests']); ?></div>
                            <div class="stat-label" data-ar="طلبات معتمدة" data-en="Approved Requests" data-fr="Demandes approuvées">طلبات معتمدة</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-change text-success">
                        <i class="fas fa-arrow-up"></i>
                        <span data-ar="زيادة 8% عن الأسبوع الماضي" data-en="8% increase from last week" data-fr="8% d'augmentation par rapport à la semaine dernière">زيادة 8% عن الأسبوع الماضي</span>
                    </div>
                </div>
            </div>

            <!-- Pilgrims Table -->
            <div class="content-card">
                <div class="content-header">
                    <h2 class="content-title" data-ar="قائمة المعتمرين" data-en="Pilgrims List" data-fr="Liste des pèlerins">قائمة المعتمرين</h2>
                    <div class="content-actions">
                        <div class="search-filter">
                            <div class="search-box">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" class="search-input" placeholder="ابحث عن معتمر..." data-ar-placeholder="ابحث عن معتمر..." data-en-placeholder="Search pilgrim..." data-fr-placeholder="Rechercher pèlerin..." value="<?php echo htmlspecialchars(isset($_GET['search']) ? $_GET['search'] : $search); ?>">
                            </div>
                            <select class="filter-select" id="wilayaFilter" name="wilaya">
                                <option value="" data-ar="كل الولايات" data-en="All Wilayas" data-fr="Toutes les wilayas">كل الولايات</option>
                                <?php
                                // اجلب جميع الولايات من قاعدة البيانات أو من القائمة الثابتة وليس من $pilgrims
                                foreach ($algerian_wilayas as $wilaya): ?>
                                    <option value="<?php echo htmlspecialchars($wilaya); ?>" <?php echo (isset($_GET['wilaya']) && $_GET['wilaya'] === $wilaya) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($wilaya); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select class="filter-select" id="statutFilter" name="statut">
                                <option value="" data-ar="كل الحالات" data-en="All Statuses" data-fr="Tous les statuts">كل الحالات</option>
                                <option value="en_attente" data-ar="قيد الانتظار" data-en="Pending" data-fr="En attente" <?php echo (isset($_GET['statut']) && $_GET['statut'] === 'en_attente') ? 'selected' : ''; ?>>قيد الانتظار</option>
                                <option value="accepte" data-ar="مقبول" data-en="Accepted" data-fr="Accepté" <?php echo (isset($_GET['statut']) && $_GET['statut'] === 'accepte') ? 'selected' : ''; ?>>مقبول</option>
                                <option value="refuse" data-ar="مرفوض" data-en="Rejected" data-fr="Refusé" <?php echo (isset($_GET['statut']) && $_GET['statut'] === 'refuse') ? 'selected' : ''; ?>>مرفوض</option>
                            </select>
                        </div>
                        <a href="add_pilgrim.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i>
                            <span data-ar="إضافة معتمر" data-en="Add Pilgrim" data-fr="Ajouter pèlerin">إضافة معتمر</span>
                        </a>
                    </div>
                </div>
                <div class="table-container">
                    <table class="pilgrims-table">
                        <thead>
                            <tr>
                                <th data-ar="الاسم" data-en="Name" data-fr="Nom">الاسم</th>
                                <th data-ar="الهاتف" data-en="Phone" data-fr="Téléphone">الهاتف</th>
                                <th data-ar="الولاية" data-en="Wilaya" data-fr="Wilaya">الولاية</th>
                                <th data-ar="العرض" data-en="Offer" data-fr="Offre">العرض</th>
                                <th data-ar="الوكالة" data-en="Agency" data-fr="Agence">الوكالة</th>
                                <th data-ar="الحالة" data-en="Status" data-fr="Statut">الحالة</th>
                                <th data-ar="تاريخ الطلب" data-en="Request Date" data-fr="Date de demande">تاريخ الطلب</th>
                                <th data-ar="إجراءات" data-en="Actions" data-fr="Actions">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pilgrims)): ?>
                                <tr>
                                    <td colspan="8" style="text-align:center; color:#b00; font-weight:bold;">
                                        لا توجد بيانات مطابقة / Not Found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pilgrims as $pilgrim): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($pilgrim['nom']); ?></td>
                                        <td><?php echo htmlspecialchars($pilgrim['telephone']); ?></td>
                                        <td><?php echo htmlspecialchars($pilgrim['wilaya']); ?></td>
                                        <td>
                                            <?php
                                                if (!empty($pilgrim['offre_titre'])) {
                                                    echo htmlspecialchars($pilgrim['offre_titre']) . " (ID: " . htmlspecialchars($pilgrim['offre_id']) . ")";
                                                } else {
                                                    echo htmlspecialchars($pilgrim['offre_id']);
                                                }
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($pilgrim['agence_nom']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $pilgrim['statut']; ?>">
                                                <?php
                                                    if ($pilgrim['statut'] === 'en_attente') echo 'قيد الانتظار';
                                                    elseif ($pilgrim['statut'] === 'accepte') echo 'مقبول';
                                                    elseif ($pilgrim['statut'] === 'refuse') echo 'مرفوض';
                                                ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($pilgrim['date_demande']); ?></td>
                                        <td class="action-buttons">
                                            <button class="action-btn btn-view" onclick="viewPilgrim(<?php echo $pilgrim['id']; ?>)"><i class="fas fa-eye"></i></button>
                                            <button class="action-btn btn-edit" onclick="editPilgrim(<?php echo $pilgrim['id']; ?>)"><i class="fas fa-edit"></i></button>
                                            <button class="action-btn btn-delete" onclick="confirmDelete(<?php echo $pilgrim['id']; ?>)"><i class="fas fa-trash"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <button class="page-btn" onclick="changePage(<?php echo $page - 1; ?>)">
                            <i class="fas fa-chevron-right"></i>
                            <span data-ar="السابق" data-en="Previous" data-fr="Précédent">السابق</span>
                        </button>
                    <?php endif; ?>
                    
                    <span class="page-info">
                        <span data-ar="الصفحة" data-en="Page" data-fr="Page">الصفحة</span> 
                        <?php echo $page; ?> 
                        <span data-ar="من" data-en="of" data-fr="de">من</span> 
                        <?php echo $total_pages; ?>
                    </span>
                    
                    <?php if ($page < $total_pages): ?>
                        <button class="page-btn" onclick="changePage(<?php echo $page + 1; ?>)">
                            <span data-ar="التالي" data-en="Next" data-fr="Suivant">التالي</span>
                            <i class="fas fa-chevron-left"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- View Pilgrim Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content" style="max-width:900px; margin: 20px auto;">
            <div class="modal-header">
                <h3 class="modal-title" data-ar="تفاصيل المعتمر" data-en="Pilgrim Details" data-fr="Détails du pèlerin">تفاصيل المعتمر</h3>
                <button class="close-btn" onclick="closeModal('viewModal')">&times;</button>
            </div>
            <div id="pilgrimDetails">
                <!-- Details will be loaded here via AJAX -->
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" onclick="closeModal('viewModal')" data-ar="إغلاق" data-en="Close" data-fr="Fermer">إغلاق</button>
            </div>
        </div>
    </div>

    <!-- Edit Pilgrim Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content" style="max-width:900px; max-height:unset; padding:0; display:flex; align-items:flex-start; justify-content:center; margin:60px auto 20px auto;">
            <form id="editPilgrimForm" enctype="multipart/form-data" autocomplete="off" method="post"
                  style="background:#fff;border-radius:20px;padding:1.2rem;width:100%;box-sizing:border-box;">
                  <input type="hidden" name="edit_pilgrim" value="1">
                <div class="modal-header" style="margin-bottom:0.7rem;padding-bottom:0.5rem;">
                    <h3 class="modal-title"
                        data-ar="تعديل بيانات المعتمر"
                        data-en="Edit Pilgrim"
                        data-fr="Modifier pèlerin"
                        style="font-size:1.15rem;">تعديل بيانات المعتمر</h3>
                    <button class="close-btn" onclick="closeModal('editModal')" type="button">&times;</button>
                </div>
                <div id="editPilgrimFormContent" style="display:flex; flex-wrap:wrap; gap:0.7rem;">
                    <!-- سيتم تعبئة الحقول عبر جافاسكريبت -->
                </div>
                <div class="form-actions" style="margin-top:1rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')"
                        data-ar="إلغاء" data-en="Cancel" data-fr="Annuler"
                        style="padding:0.4rem 1.1rem;font-size:0.95rem;">إلغاء</button>
                    <button type="submit" class="btn btn-primary"
                        data-ar="حفظ التغييرات" data-en="Save Changes" data-fr="Enregistrer"
                        style="padding:0.4rem 1.1rem;font-size:0.95rem;">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <form id="deletePilgrimForm" method="post" onsubmit="return false;">
                <div class="modal-header">
                    <h3 class="modal-title" data-ar="تأكيد الحذف" data-en="Confirm Delete" data-fr="Confirmer suppression">تأكيد الحذف</h3>
                    <button class="close-btn" onclick="closeModal('deleteModal')" type="button">&times;</button>
                </div>
                <div class="form-group">
                    <p data-ar="هل أنت متأكد أنك تريد حذف هذا المعتمر؟ لا يمكن التراجع عن هذا الإجراء." data-en="Are you sure you want to delete this pilgrim? This action cannot be undone." data-fr="Êtes-vous sûr de vouloir supprimer ce pèlerin? Cette action est irréversible.">هل أنت متأكد أنك تريد حذف هذا المعتمر؟ لا يمكن التراجع عن هذا الإجراء.</p>
                </div>
                <input type="hidden" name="delete_pilgrim_id" id="delete_pilgrim_id" value="">
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')" data-ar="إلغاء" data-en="Cancel" data-fr="Annuler">إلغاء</button>
                    <button type="submit" class="btn btn-danger" id="confirmDeleteBtn" data-ar="حذف" data-en="Delete" data-fr="Supprimer">حذف</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Pilgrim Modal -->
    <div id="addPilgrimModal" class="modal">
        <div class="modal-content" style="max-width:900px; max-height:unset; padding:0; display:flex; align-items:flex-start; justify-content:center; margin:20px auto;">
            <form id="addPilgrimForm" enctype="multipart/form-data" autocomplete="off" method="post"
                  style="background:#fff;border-radius:20px;padding:1.2rem;width:100%;box-sizing:border-box;">
                <div class="modal-header" style="margin-bottom:0.7rem;padding-bottom:0.5rem;">
                    <h3 class="modal-title"
                        data-ar="إضافة معتمر جديد"
                        data-en="Add New Pilgrim"
                        data-fr="Ajouter un nouveau pèlerin"
                        style="font-size:1.15rem;">إضافة معتمر جديد</h3>
                    <button class="close-btn" onclick="closeModal('addPilgrimModal')" type="button">&times;</button>
                </div>
                <div style="display:flex; flex-wrap:wrap; gap:1.5rem;">
                    <div class="form-group" style="flex:1 1 45%; min-width:220px;">
                        <label class="form-label" for="add_nom"
                            data-ar="الاسم الكامل" data-en="Full Name" data-fr="Nom complet"
                            style="font-size:1.05rem;font-weight:bold;">الاسم الكامل</label>
                        <input type="text" class="form-input" id="add_nom" name="nom" required
                            placeholder="أدخل الاسم الكامل"
                            data-ar-placeholder="أدخل الاسم الكامل"
                            data-en-placeholder="Enter full name"
                            data-fr-placeholder="Entrer le nom complet"
                            style="padding:0.7rem 1rem;font-size:1.05rem;">
                    </div>
                    <div class="form-group" style="flex:1 1 45%; min-width:220px;">
                        <label class="form-label" for="add_telephone"
                            data-ar="رقم الهاتف" data-en="Phone Number" data-fr="Numéro de téléphone"
                            style="font-size:1.05rem;font-weight:bold;">رقم الهاتف</label>
                        <input type="text" class="form-input" id="add_telephone" name="telephone" required
                            placeholder="أدخل رقم الهاتف"
                            data-ar-placeholder="أدخل رقم الهاتف"
                            data-en-placeholder="Enter phone number"
                            data-fr-placeholder="Entrer le numéro de téléphone"
                            pattern="[0-9]+"
                            inputmode="numeric"
                            title="يرجى إدخال أرقام فقط"
                            style="padding:0.7rem 1rem;font-size:1.05rem;">
                    </div>
                    <div class="form-group" style="flex:1 1 45%; min-width:220px;">
                        <label class="form-label" for="add_wilaya"
                            data-ar="الولاية" data-en="Wilaya" data-fr="Wilaya"
                            style="font-size:1.05rem;font-weight:bold;">الولاية</label>
                        <select class="form-input" id="add_wilaya" name="wilaya" required
                            style="padding:0.7rem 1rem;font-size:1.05rem;">
                            <option value=""
                                data-ar="اختر الولاية"
                                data-en="Select Wilaya"
                                data-fr="Choisir la wilaya" disabled selected>اختر الولاية</option>
                            <?php foreach ($algerian_wilayas as $wilaya): ?>
                                <option value="<?php echo htmlspecialchars($wilaya); ?>"><?php echo htmlspecialchars($wilaya); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1 1 45%; min-width:220px;">
                        <label class="form-label" for="add_offre_id"
                            data-ar="العرض المختار" data-en="Selected Offer" data-fr="Offre choisie"
                            style="font-size:1.05rem;font-weight:bold;">العرض المختار</label>
                        <select class="form-input" id="add_offre_id" name="offre_id" required
                            style="padding:0.7rem 1rem;font-size:1.05rem;">
                            <option value=""
                                data-ar="اختر العرض"
                                data-en="Select Offer"
                                data-fr="Choisir l'offre" disabled selected>اختر العرض</option>
                            <?php foreach ($offres as $offre): ?>
                                <option value="<?php echo $offre['id']; ?>">
                                    <?php echo htmlspecialchars($offre['titre']); ?> (ID: <?php echo $offre['id']; ?>)
                            </option>
                        <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1 1 45%; min-width:220px;">
                        <label class="form-label" for="add_statut"
                            data-ar="حالة الطلب" data-en="Status" data-fr="Statut"
                            style="font-size:1.05rem;font-weight:bold;">حالة الطلب</label>
                        <select class="form-input" id="add_statut" name="statut" required
                            style="padding:0.7rem 1rem;font-size:1.05rem;">
                            <option value="en_attente"
                                data-ar="قيد الانتظار"
                                data-en="Pending"
                                data-fr="En attente">قيد الانتظار</option>
                            <option value="accepte"
                                data-ar="مقبول"
                                data-en="Accepted"
                                data-fr="Accepté">مقبول</option>
                            <option value="refuse"
                                data-ar="مرفوض"
                                data-en="Rejected"
                                data-fr="Refusé">مرفوض</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1 1 45%; min-width:220px;">
                        <label class="form-label" for="add_passport_image"
                            data-ar="صورة جواز السفر" data-en="Passport Image" data-fr="Image Passeport"
                            style="font-size:1.05rem;font-weight:bold;">صورة جواز السفر</label>
                        <input type="file" class="form-input" id="add_passport_image" name="passport_image" accept="image/*"
                            data-ar-placeholder="اختر صورة الجواز"
                            data-en-placeholder="Choose passport image"
                            data-fr-placeholder="Choisir l'image du passeport" required
                            style="padding:0.7rem 1rem;font-size:1.05rem;">
                    </div>
                </div>
                <div class="form-actions" style="margin-top:1rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addPilgrimModal')"
                        data-ar="إلغاء" data-en="Cancel" data-fr="Annuler"
                        style="padding:0.4rem 1.1rem;font-size:1rem;">إلغاء</button>
                    <button type="submit" class="btn btn-primary" name="add_pilgrim"
                        data-ar="إضافة" data-en="Add" data-fr="Ajouter"
                        style="padding:0.4rem 1.1rem;font-size:1rem;">إضافة</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toggle sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }

        // Change page
        function changePage(page) {
            // عند الفلترة عبر ajax، غير فقط الصفحة في نفس ajax
            searchPilgrimsAjax(page);
        }

        // Search and filter
        const searchInput = document.querySelector('.search-input');
        let searchTimeout;
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => searchPilgrimsAjax(1), 400); // Debounce: 400ms
            });
        }
        const statutFilter = document.getElementById('statutFilter');
        if (statutFilter) {
            statutFilter.addEventListener('change', function() { searchPilgrimsAjax(1); });
        }
        const wilayaFilter = document.getElementById('wilayaFilter');
        if (wilayaFilter) {
            wilayaFilter.addEventListener('change', function() { searchPilgrimsAjax(1); });
        }

        function searchPilgrimsAjax(page = 1) {
            const search = searchInput ? searchInput.value : '';
            const wilaya = wilayaFilter ? wilayaFilter.value : '';
            const statut = statutFilter ? statutFilter.value : '';
            const params = new URLSearchParams({search, wilaya, statut, page});
            fetch(window.location.pathname + '?ajax=1&' + params.toString())
                .then(res => res.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newTable = doc.querySelector('.table-container');
                    const newPagination = doc.querySelector('.pagination');
                    if (newTable && newPagination) {
                        document.querySelector('.table-container').innerHTML = newTable.innerHTML;
                        document.querySelector('.pagination').innerHTML = newPagination.innerHTML;
                    }
                    // تحديث بيانات جافاسكريبت للمعتمرين (للعرض/تعديل)
                    const pilgrimsDataScript = doc.getElementById('pilgrimsDataScript');
                    if (pilgrimsDataScript) {
                        window.pilgrimsData = JSON.parse(pilgrimsDataScript.textContent);
                    }
                    // إعادة تعيين قيمة الفلاتر بعد تحديث الجدول
                    if (wilayaFilter) wilayaFilter.value = wilaya;
                    if (statutFilter) statutFilter.value = statut;
                    if (searchInput) searchInput.value = search;
                });
        }

        // View pilgrim
        function viewPilgrim(id) {
            const pilgrim = window.pilgrimsData.find(p => p.id == id);
            if (!pilgrim) return;
            let passportImageHtml = '';
            if (pilgrim.passport_image) {
                passportImageHtml = `
                    <div style="flex:1 1 350px;display:flex;justify-content:center;align-items:flex-start;">
                        <img src="uploads/passports/${pilgrim.passport_image}" alt="صورة جواز السفر" style="max-width:350px;max-height:350px;border-radius:18px;border:2px solid #eee;box-shadow:0 2px 16px rgba(0,0,0,0.10);" />
                    </div>
                `;
            }
            const detailsHtml = `
                <div style="display:flex;flex-wrap:wrap;gap:2.5rem;align-items:flex-start;">
                    <div style="flex:1 1 320px;min-width:220px;">
                        <div style="display:flex;flex-wrap:wrap;gap:1.2rem;">
                            <div class="form-group" style="flex:1 1 45%;margin-bottom:1rem;">
                                <label class="form-label">الاسم</label>
                                <div class="form-static" style="font-weight:600">${pilgrim.nom}</div>
                            </div>
                            <div class="form-group" style="flex:1 1 45%;margin-bottom:1rem;">
                                <label class="form-label">الهاتف</label>
                                <div class="form-static">${pilgrim.telephone}</div>
                            </div>
                            <div class="form-group" style="flex:1 1 45%;margin-bottom:1rem;">
                                <label class="form-label">الولاية</label>
                                <div class="form-static">${pilgrim.wilaya}</div>
                            </div>
                            <div class="form-group" style="flex:1 1 45%;margin-bottom:1rem;">
                                <label class="form-label">العرض</label>
                                <div class="form-static">${pilgrim.offre_id}</div>
                            </div>
                            <div class="form-group" style="flex:1 1 45%;margin-bottom:1rem;">
                                <label class="form-label">الوكالة</label>
                                <div class="form-static">${pilgrim.agence_nom ? pilgrim.agence_nom : ''}</div>
                            </div>
                            <div class="form-group" style="flex:1 1 45%;margin-bottom:1rem;">
                                <label class="form-label">الحالة</label>
                                <div class="form-static">${pilgrim.statut === 'en_attente' ? 'قيد الانتظار' : (pilgrim.statut === 'accepte' ? 'مقبول' : 'مرفوض')}</div>
                            </div>
                            <div class="form-group" style="flex:1 1 45%;margin-bottom:1rem;">
                                <label class="form-label">تاريخ الطلب</label>
                                <div class="form-static">${pilgrim.date_demande}</div>
                            </div>
                        </div>
                    </div>
                    ${passportImageHtml}
                </div>
            `;
            document.getElementById('pilgrimDetails').innerHTML = detailsHtml;
            document.getElementById('viewModal').style.display = 'block';
        }

        // Edit pilgrim
        function editPilgrim(id) {
            const pilgrim = window.pilgrimsData.find(p => p.id == id);
            if (!pilgrim) return;
            // بناء خيارات الولايات
            let wilayaOptions = `<option value="" disabled>اختر الولاية</option>`;
            window.algerianWilayas.forEach(function(wilaya) {
                wilayaOptions += `<option value="${wilaya.replace(/"/g, '&quot;')}"${pilgrim.wilaya === wilaya ? ' selected' : ''}>${wilaya}</option>`;
            });
            // بناء خيارات العروض
            let offreOptions = `<option value="" disabled>اختر العرض</option>`;
            window.offresList.forEach(function(offre) {
                offreOptions += `<option value="${offre.id}"${pilgrim.offre_id == offre.id ? ' selected' : ''}>${offre.titre} (ID: ${offre.id})</option>`;
            });
            // بناء النموذج بدون عرض صورة الجواز الحالية
            const formHtml = `
                <input type="hidden" name="id" value="${pilgrim.id}">
                <div class="form-group" style="flex:1 1 48%; min-width:180px;">
                    <label class="form-label" for="edit_nom" style="font-size:0.95rem;">الاسم</label>
                    <input type="text" class="form-input" id="edit_nom" name="nom" value="${pilgrim.nom}" required style="padding:0.5rem 0.8rem;font-size:0.95rem;">
                </div>
                <div class="form-group" style="flex:1 1 48%; min-width:180px;">
                    <label class="form-label" for="edit_telephone" style="font-size:0.95rem;">الهاتف</label>
                    <input type="text" class="form-input" id="edit_telephone" name="telephone" value="${pilgrim.telephone}" required pattern="[0-9]+" inputmode="numeric" title="يرجى إدخال أرقام فقط" style="padding:0.5rem 0.8rem;font-size:0.95rem;">
                </div>
                <div class="form-group" style="flex:1 1 48%; min-width:180px;">
                    <label class="form-label" for="edit_wilaya" style="font-size:0.95rem;">الولاية</label>
                    <select class="form-input" id="edit_wilaya" name="wilaya" required style="padding:0.5rem 0.8rem;font-size:0.95rem;">
                        ${wilayaOptions}
                    </select>
                </div>
                <div class="form-group" style="flex:1 1 48%; min-width:180px;">
                    <label class="form-label" for="edit_offre_id" style="font-size:0.95rem;">العرض</label>
                    <select class="form-input" id="edit_offre_id" name="offre_id" required style="padding:0.5rem 0.8rem;font-size:0.95rem;">
                        ${offreOptions}
                    </select>
                </div>
                <div class="form-group" style="flex:1 1 48%; min-width:180px;">
                    <label class="form-label" for="edit_statut" style="font-size:0.95rem;">الحالة</label>
                    <select class="form-input" id="edit_statut" name="statut" required style="padding:0.5rem 0.8rem;font-size:0.95rem;">
                        <option value="en_attente"${pilgrim.statut === 'en_attente' ? ' selected' : ''}>قيد الانتظار</option>
                        <option value="accepte"${pilgrim.statut === 'accepte' ? ' selected' : ''}>مقبول</option>
                        <option value="refuse"${pilgrim.statut === 'refuse' ? ' selected' : ''}>مرفوض</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1 1 48%; min-width:180px;">
                    <label class="form-label" for="edit_passport_image" style="font-size:0.95rem;">صورة الجواز (اختياري)</label>
                    <input type="file" class="form-input" id="edit_passport_image" name="passport_image" accept="image/*" style="padding:0.5rem 0.8rem;font-size:0.95rem;">
                </div>
            `;
            document.getElementById('editPilgrimFormContent').innerHTML = formHtml;
            document.getElementById('editModal').style.display = 'block';
        }

        document.getElementById('editPilgrimForm').addEventListener('submit', function(e) {
            // لا تمنع الإرسال الافتراضي هنا
            // e.preventDefault();
            // لا تغلق المودال ولا تعرض alert هنا، لأن الصفحة ستتحدث بعد الحفظ
        });

        // Delete pilgrim
        let pilgrimToDelete = null;

        function confirmDelete(id) {
            pilgrimToDelete = id;
            document.getElementById('delete_pilgrim_id').value = id;
            document.getElementById('deleteModal').style.display = 'block';
        }

        // حذف عبر AJAX بدون إعادة تحميل الصفحة
        document.getElementById('deletePilgrimForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var id = document.getElementById('delete_pilgrim_id').value;
            if (!id) return;
            var btn = document.getElementById('confirmDeleteBtn');
            btn.disabled = true;
            btn.textContent = '...';
            fetch(window.location.pathname, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'ajax_delete_pilgrim_id=' + encodeURIComponent(id)
            })
            .then(res => res.text())
            .then(resp => {
                btn.disabled = false;
                btn.textContent = 'حذف';
                if (resp.trim() === 'OK') {
                    // حذف الصف من الجدول مباشرة
                    var row = document.querySelector('.action-btn.btn-delete[onclick="confirmDelete(' + id + ')"]');
                    if (row) {
                        var tr = row.closest('tr');
                        if (tr) tr.remove();
                    }
                    closeModal('deleteModal');
                } else {
                    alert('حدث خطأ أثناء الحذف');
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.textContent = 'حذف';
                alert('حدث خطأ أثناء الحذف');
            });
        });

        // فتح نافذة إضافة معتمر
        document.addEventListener('DOMContentLoaded', function() {
            var addPilgrimBtn = document.querySelector('a.btn.btn-primary');
            if (addPilgrimBtn) {
                addPilgrimBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    document.getElementById('addPilgrimModal').style.display = 'block';
                });
            }
            // يمكنك نقل أي أكواد event listeners أخرى هنا إذا كانت تعتمد على عناصر تم تحميلها بعد DOM
        });

        // Close modal
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        });

        // Language switcher
        const langBtns = document.querySelectorAll('.lang-btn');
        if (langBtns.length) {
            langBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const lang = this.getAttribute('data-lang');
                    switchLanguage(lang);
                    // Update active button
                    langBtns.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                });
            });
        }
        function switchLanguage(lang) {
            document.querySelectorAll('[data-ar], [data-en], [data-fr]').forEach(el => {
                if (el.hasAttribute(`data-${lang}`)) {
                    if (el.tagName === 'INPUT' && el.hasAttribute(`data-${lang}-placeholder`)) {
                        el.placeholder = el.getAttribute(`data-${lang}-placeholder`);
                    } else if (el.tagName === 'OPTION') {
                        el.textContent = el.getAttribute(`data-${lang}`);
                    } else {
                        el.textContent = el.getAttribute(`data-${lang}`);
                    }
                }
            });
            // Change direction for RTL/LTR
            const isRTL = lang === 'ar';
           
            document.documentElement.setAttribute('dir', isRTL ? 'rtl' : 'ltr');
            document.body.style.direction = isRTL ? 'rtl' : 'ltr';
            // Sidebar and main content
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            if (sidebar) sidebar.style.direction = isRTL ? 'rtl' : 'ltr';
            if (mainContent) mainContent.style.direction = isRTL ? 'rtl' : 'ltr';
            // Optional: flip sidebar position if needed
            if (sidebar) sidebar.style.float = isRTL ? 'right' : 'left';
            if (mainContent) mainContent.style.marginRight = isRTL ? '280px' : '0';

            if (mainContent) mainContent.style.marginLeft = isRTL ? '0' : '280px';
        }

        // إعداد بيانات المعتمرين لجافاسكريبت
        window.pilgrimsData = <?php echo json_encode($pilgrims, JSON_UNESCAPED_UNICODE); ?>;
        window.algerianWilayas = <?php echo json_encode($algerian_wilayas, JSON_UNESCAPED_UNICODE); ?>;
        window.offresList = <?php echo json_encode($offres, JSON_UNESCAPED_UNICODE); ?>;
    </script>
</body>
</html>