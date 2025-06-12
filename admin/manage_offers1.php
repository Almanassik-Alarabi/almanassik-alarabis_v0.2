<?php
// تمكين إعدادات التصحيح
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/agency_errors.log');

// بدء الجلسة
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// التحقق من تسجيل الدخول
if (!isset($_SESSION['admin_id'])) {
    header('Location: login_admin.php');
    exit();
}

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

} catch (PDOException $e) {
    // سجل الأخطاء في ملف logs/error.log
    $logFile = __DIR__ . '/../logs/error.log';
    // تحقق من وجود المجلد logs، إذا لم يكن موجوداً أنشئه
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    // إذا لم يكن الملف موجوداً أنشئه
    if (!file_exists($logFile)) {
        touch($logFile);
        chmod($logFile, 0666);
    }
    $errorMessage = date('Y-m-d H:i:s') . ' | DB ERROR: ' . $e->getMessage() . "\n";
    file_put_contents($logFile, $errorMessage, FILE_APPEND);
    $error = 'حدث خطأ في النظام. يرجى المحاولة لاحقاً.';
}

$admin_id = $_SESSION['admin_id'];

// استعلام لاسترجاع بيانات الأدمن واللغة المفضلة
$query = $pdo->prepare("SELECT id, nom, email, langue_preferee FROM admins WHERE id = :id");
$query->execute(['id' => $admin_id]);
$admin = $query->fetch(PDO::FETCH_ASSOC);

$langue_preferee = $admin ? $admin['langue_preferee'] : 'ar';

// معالجة طلبات POST (إضافة، تعديل، حذف)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add_offer') {
        try {
            // إضافة عرض جديد
            $title = trim($_POST['title']);
            $agency_id = (int)$_POST['agency_id'];
            $type = isset($_POST['type']) ? $_POST['type'] : 'Omra';
            $price = floatval($_POST['price']);
            $description = trim($_POST['description']);
            $date_depart = isset($_POST['date_depart']) ? $_POST['date_depart'] : null;
            $date_retour = isset($_POST['date_retour']) ? $_POST['date_retour'] : null;
            $aeroport_depart = isset($_POST['aeroport_depart']) ? trim($_POST['aeroport_depart']) : null;
            $est_doree = isset($_POST['est_doree']) && $_POST['est_doree'] == '1' ? true : false;

            $stmt = $pdo->prepare("INSERT INTO offres (agence_id, titre, description, prix_base, est_doree, type_voyage, date_depart, date_retour, aeroport_depart) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $agency_id,
                $title,
                $description,
                $price,
                $est_doree,
                $type,
                $date_depart,
                $date_retour,
                $aeroport_depart
            ]);
            header("Location: manage_offers.php?success=1");
            exit;
        } catch (PDOException $e) {
            $logFile = __DIR__ . '/../logs/error.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0777, true);
            }
            if (!file_exists($logFile)) {
                touch($logFile);
                chmod($logFile, 0666);
            }
            $errorMessage = date('Y-m-d H:i:s') . ' | ADD OFFER ERROR: ' . $e->getMessage() . "\n";
            file_put_contents($logFile, $errorMessage, FILE_APPEND);
            $error = 'حدث خطأ أثناء إضافة العرض. يرجى المحاولة لاحقاً.';
        }
    }

    if ($action === 'edit_offer') {
        try {
            // تعديل عرض
            $offer_id = (int)$_POST['offer_id'];
            $title = trim($_POST['title']);
            $agency_id = (int)$_POST['agency_id'];
            $type = $_POST['type'] === 'golden' ? 'gold' : 'standard';
            $price = floatval($_POST['price']);
            $description = trim($_POST['description']);

            $est_doree = $type === 'gold' ? 'true' : 'false';

            $stmt = $pdo->prepare("UPDATE offres SET agence_id = ?, titre = ?, description = ?, prix_base = ?, est_doree = ?, type_voyage = ? WHERE id = ?");
            $stmt->execute([
                $agency_id,
                $title,
                $description,
                $price,
                $est_doree,
                'Omra',
                $offer_id
            ]);
            header("Location: manage_offers.php?success=2");
            exit;
        } catch (PDOException $e) {
            $logFile = __DIR__ . '/../logs/error.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0777, true);
            }
            if (!file_exists($logFile)) {
                touch($logFile);
                chmod($logFile, 0666);
            }
            $errorMessage = date('Y-m-d H:i:s') . ' | EDIT OFFER ERROR: ' . $e->getMessage() . "\n";
            file_put_contents($logFile, $errorMessage, FILE_APPEND);
            $error = 'حدث خطأ أثناء تعديل العرض. يرجى المحاولة لاحقاً.';
        }
    }

    if ($action === 'delete_offer') {
        try {
            // حذف صور العرض أولاً
            $offer_id = (int)$_POST['offer_id'];
            $stmt = $pdo->prepare("DELETE FROM chomber_images WHERE offre_id = ?");
            $stmt->execute([$offer_id]);
            // حذف العرض
            $stmt = $pdo->prepare("DELETE FROM offres WHERE id = ?");
            $stmt->execute([$offer_id]);
            header("Location: manage_offers.php?success=3");
            exit;
        } catch (PDOException $e) {
            $logFile = __DIR__ . '/../logs/error.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0777, true);
            }
            if (!file_exists($logFile)) {
                touch($logFile);
                chmod($logFile, 0666);
            }
            $errorMessage = date('Y-m-d H:i:s') . ' | DELETE OFFER ERROR: ' . $e->getMessage() . "\n";
            file_put_contents($logFile, $errorMessage, FILE_APPEND);
            $error = 'حدث خطأ أثناء حذف العرض. يرجى المحاولة لاحقاً.';
        }
    }
}

// استرجاع العروض
$offers_stmt = $pdo->query("
    SELECT o.*, a.nom_agence AS agency_name
    FROM offres o
    LEFT JOIN agences a ON o.agence_id = a.id
    ORDER BY o.est_doree DESC, o.id DESC
");
$offers = $offers_stmt->fetchAll(PDO::FETCH_ASSOC);

// استرجاع قائمة الوكالات
$agencies_stmt = $pdo->query("SELECT id, nom_agence AS name FROM agences WHERE approuve = true ORDER BY nom_agence");
$agencies = $agencies_stmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات العروض
$stats = [
    'total_offers' => 0,
    'standard_offers' => 0,
    'golden_offers' => 0,
    'avg_price' => 0,
    'hajj_offers' => 0,
    'omra_offers' => 0
];

// إحصائيات إضافية: عدد عروض الحج والعمرة
$stat_stmt = $pdo->query("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN est_doree = true THEN 1 ELSE 0 END) AS golden,
        SUM(CASE WHEN est_doree = false THEN 1 ELSE 0 END) AS standard,
        SUM(CASE WHEN type_voyage = 'Hajj' THEN 1 ELSE 0 END) AS hajj_offers,
        SUM(CASE WHEN type_voyage = 'Omra' THEN 1 ELSE 0 END) AS omra_offers,
        AVG(prix_base) AS avg_price
    FROM offres
");
$row = $stat_stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    $stats['total_offers'] = (int)$row['total'];
    $stats['standard_offers'] = (int)$row['standard'];
    $stats['golden_offers'] = (int)$row['golden'];
    $stats['hajj_offers'] = (int)$row['hajj_offers'];
    $stats['omra_offers'] = (int)$row['omra_offers'];
    $stats['avg_price'] = (float)$row['avg_price'];
}

// إعدادات اللغة
$dir = $langue_preferee === 'ar' ? 'rtl' : 'ltr';
$translations = [
    'ar' => [
        'page_title' => 'إدارة العروض',
        'offers_management' => 'إدارة عروض العمرة والحج',
        'search_placeholder' => 'البحث في العروض...',
        'filter_by_agency' => 'تصفية حسب الوكالة',
        'all_agencies' => 'جميع الوكالات',
        'filter_by_type' => 'تصفية حسب النوع',
        'all_types' => 'جميع الأنواع',
        'umrah' => 'عمرة',
        'hajj' => 'حج',
        'golden_offers' => 'العروض الذهبية',
        'all_offers' => 'جميع العروض',
        'golden_only' => 'ذهبية فقط',
        'regular_only' => 'عادية فقط',
        'total_offers' => 'إجمالي العروض',
        'golden_offer' => 'عرض ذهبي',
        'view_details' => 'عرض التفاصيل',
        'edit' => 'تعديل',
        'delete' => 'حذف',
        'confirm_delete' => 'هل أنت متأكد من حذف هذا العرض؟',
        'no_offers_found' => 'لا توجد عروض',
        'days' => 'أيام',
        'from' => 'من',
        'to' => 'إلى',
        'requests_count' => 'عدد الطلبات'
    ],
    'en' => [
        'page_title' => 'Manage Offers',
        'offers_management' => 'Umrah & Hajj Offers Management',
        'search_placeholder' => 'Search in offers...',
        'filter_by_agency' => 'Filter by Agency',
        'all_agencies' => 'All Agencies',
        'filter_by_type' => 'Filter by Type',
        'all_types' => 'All Types',
        'umrah' => 'Umrah',
        'hajj' => 'Hajj',
        'golden_offers' => 'Golden Offers',
        'all_offers' => 'All Offers',
        'golden_only' => 'Golden Only',
        'regular_only' => 'Regular Only',
        'total_offers' => 'Total Offers',
        'golden_offer' => 'Golden Offer',
        'view_details' => 'View Details',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'confirm_delete' => 'Are you sure you want to delete this offer?',
        'no_offers_found' => 'No offers found',
        'days' => 'days',
        'from' => 'from',
        'to' => 'to',
        'requests_count' => 'Requests Count'
    ],
    'fr' => [
        'page_title' => 'Gérer les Offres',
        'offers_management' => 'Gestion des Offres Omra & Hajj',
        'search_placeholder' => 'Rechercher dans les offres...',
        'filter_by_agency' => 'Filtrer par Agence',
        'all_agencies' => 'Toutes les Agences',
        'filter_by_type' => 'Filtrer par Type',
        'all_types' => 'Tous les Types',
        'umrah' => 'Omra',
        'hajj' => 'Hajj',
        'golden_offers' => 'Offres Dorées',
        'all_offers' => 'Toutes les Offres',
        'golden_only' => 'Dorées Seulement',
        'regular_only' => 'Régulières Seulement',
        'total_offers' => 'Total des Offres',
        'golden_offer' => 'Offre Dorée',
        'view_details' => 'Voir Détails',
        'edit' => 'Modifier',
        'delete' => 'Supprimer',
        'confirm_delete' => 'Êtes-vous sûr de vouloir supprimer cette offre ?',
        'no_offers_found' => 'Aucune offre trouvée',
        'days' => 'jours',
        'from' => 'de',
        'to' => 'à',
        'requests_count' => 'Nombre de Demandes'
    ]
];

$t = $translations[$langue_preferee];
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة العروض | Umrah Platform</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* استبدل تعريف الألوان في :root بالألوان الإسلامية */
        :root {
            --primary: linear-gradient(135deg, #198754 0%, #14532d 100%); /* أخضر إسلامي */
            --secondary: linear-gradient(135deg, #ffe066 0%, #ffd700 100%); /* ذهبي */
            --success: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --warning: linear-gradient(135deg, #ffe066 0%, #ffd700 100%);
            --danger: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --dark: #14532d;
            --light: #f8f9fa;
            --card-bg: rgba(255, 255, 255, 0.97);
            --text-primary: #14532d;
            --text-secondary: #6c757d;
            --border-radius: 20px;
            --shadow: 0 10px 30px rgba(25, 135, 84, 0.08);
            --shadow-hover: 0 20px 60px rgba(25, 135, 84, 0.13);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Cairo', 'Inter', sans-serif;
            background: linear-gradient(135deg, rgb(4, 128, 70) 0%, rgb(180, 163, 5) 100%);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 250px;
            background: linear-gradient(135deg, #fffbe6 0%, #e8ffe8 100%);
            border-left: 4px solid #198754;
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
            width: 70px;
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
            background: linear-gradient(135deg, rgb(180, 163, 5) 0%, rgb(4, 128, 70) 100%);
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
            margin-right: 250px;
            padding: 2rem;
            transition: all 0.3s ease;
        }

        .main-content.expanded {
            margin-right: 70px;
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
            height: 125px;
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
            color: #fff;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            border: 1px solid #198754;
        }

        .lang-btn:hover,
        .lang-btn.active {
            background: var(--secondary);
            color: #14532d;
            border: 1px solid #ffd700;
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
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            padding: 1rem;
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
            background: var(--secondary);
        }

        .stat-card:nth-child(3)::before {
            background: var(--success);
        }

        .stat-card:nth-child(4)::before {
            background: var(--warning);
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
            background: var(--secondary);
        }

        .stat-card:nth-child(3) .stat-icon {
            background: var(--success);
        }

        .stat-card:nth-child(4) .stat-icon {
            background: var(--warning);
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

        /* Charts Section */
        .charts-section {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .chart-card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--shadow);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .chart-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .chart-placeholder {
            height: 300px;
            background: linear-gradient(45deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            font-size: 1.1rem;
        }

        /* Recent Activity */
        .recent-activity {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--shadow);
        }

        .activity-item {
            display: flex;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: 1rem;
            color: white;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .activity-time {
            color: var(--text-secondary);
            font-size: 0.9rem;
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

        /* تحسين تناسق الجدول */
        .offers-table {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-top: 2rem;
        }
        .offers-table .table-header {
            margin-bottom: 1rem;
        }
        .offers-table table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: transparent;
        }
        .offers-table th, .offers-table td {
            padding: 0.75rem 1rem;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
            font-size: 1rem;
        }
        .offers-table th {
            background: #f8f9fa;
            color: var(--text-primary);
            font-weight: 700;
        }
        .offers-table tr:last-child td {
            border-bottom: none;
        }
        .offers-table td {
            background: transparent;
        }
        .offers-table .btn {
            font-size: 0.95rem;
            padding: 0.3rem 0.7rem;
        }
        .offers-table .btn i {
            margin-left: 0.3rem;
            margin-right: 0.3rem;
        }
        .offers-table .price {
            color: #198754;
            font-weight: bold;
        }
        .offers-table .offer-type {
            border-radius: 12px;
            padding: 0.2rem 0.7rem;
            font-size: 0.95rem;
            color: #fff;
            background: #198754;
            display: inline-block;
        }
        .offers-table .offer-type.golden {
            background: #ffd700;
            color: #14532d;
        }
        /* تحسين شريط البحث والإجراءات */
        .search-bar-container {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 1rem 1.5rem;
        }
        .search-bar {
            flex: 1;
            display: flex;
            align-items: center;
            background: #fff;
            border-radius: 25px;
            box-shadow: 0 2px 8px rgba(25,135,84,0.07);
            padding: 0.3rem 1rem;
            border: 1.5px solid #e9ecef;
            transition: border 0.2s;
        }
        .search-bar:focus-within {
            border: 1.5px solid #198754;
        }
        .search-bar input[type="text"] {
            border: none;
            outline: none;
            background: transparent;
            font-size: 1rem;
            flex: 1;
            padding: 0.5rem 0.5rem 0.5rem 0;
        }
        .search-bar button {
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            margin-right: 0.5rem;
            cursor: pointer;
            transition: background 0.2s;
        }
        .search-bar button:hover {
            background: #14532d;
        }
        .action-buttons {
            display: flex;
            gap: 1rem;
        }
        /* Responsive تحسين */
        @media (max-width: 900px) {
            .sidebar {
                width: 70px;
                padding: 1rem 0;
            }
            .main-content {
                margin-right: 70px;
            }
            .sidebar-menu a span,
            .sidebar-header .logo,
            .sidebar-header p {
                display: none;
            }
        }
        @media (max-width: 768px) {
            .sidebar {
                position: static;
                width: 100%;
                height: auto;
                border-left: none;
                border-bottom: 4px solid var(--primary);
            }
            .main-content {
                margin-right: 0;
                padding: 1rem;
            }
            .offers-table,
            .stats-grid {
                padding: 1rem;
            }
            .offers-table table,
            .offers-table th,
            .offers-table td {
                font-size: 0.95rem;
            }
        }

    .row-inline {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: center;
}
.row-inline > * {
    flex: 1 1 auto;
    min-width: 0;
}
/* Add missing styles for offer images in table */
.offer-thumbnail {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #e9ecef;
    background: #f8f9fa;
    display: block;
    margin: 0 auto;
}
.no-image {
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8f9fa;
    color: #aaa;
    border-radius: 10px;
    border: 1px solid #e9ecef;
    font-size: 0.9rem;
    margin: 0 auto;
}
    </style>
</head>
<body dir="<?php echo $dir; ?>">
   
    <div class="dashboard-container">
        <!-- Sidebar (unchanged) -->
       
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
                    <a href="manage_agencies.php" >
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
                    <a href="manage_offers.php" class="active">
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
<div class="main-content" id="main-content">
    <div class="header">
        <div class="header-left">
            <h1><?php echo htmlspecialchars($t['offers_management']); ?></h1>
            <p><?php echo htmlspecialchars($t['page_title']); ?></p>
        </div>
        <div class="header-right">
            <div class="language-switcher">
                <form method="post" style="display:inline;">
                    <input type="hidden" name="set_lang" value="ar">
                    <button type="submit" class="lang-btn<?php if($langue_preferee=='ar') echo ' active'; ?>">AR</button>
                </form>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="set_lang" value="en">
                    <button type="submit" class="lang-btn<?php if($langue_preferee=='en') echo ' active'; ?>">EN</button>
                </form>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="set_lang" value="fr">
                    <button type="submit" class="lang-btn<?php if($langue_preferee=='fr') echo ' active'; ?>">FR</button>
                </form>
            </div>
            <div class="user-info">
                <div class="user-avatar"><i class="fas fa-user-shield"></i></div>
                <span><?php echo htmlspecialchars($admin['nom'] ?? ''); ?></span>
            </div>
        </div>
    </div>

    <!-- إحصائيات العروض -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label"><?php echo htmlspecialchars($t['total_offers']); ?></span>
                <span class="stat-icon"><i class="fas fa-tags"></i></span>
            </div>
            <div class="stat-number"><?php echo $stats['total_offers']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label"><?php echo htmlspecialchars($t['golden_offers']); ?></span>
                <span class="stat-icon"><i class="fas fa-crown"></i></span>
            </div>
            <div class="stat-number"><?php echo $stats['golden_offers']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label"><?php echo htmlspecialchars($t['regular_only']); ?></span>
                <span class="stat-icon"><i class="fas fa-star"></i></span>
            </div>
            <div class="stat-number"><?php echo $stats['standard_offers']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label"><?php echo htmlspecialchars($t['avg_price'] ?? ''); ?></span>
                <span class="stat-icon"><i class="fas fa-money-bill-wave"></i></span>
            </div>
            <div class="stat-number"><?php echo number_format($stats['avg_price'] ?? 0, 2); ?></div>
        </div>
    </div>

    <!-- Search and Action Bar -->
    <div class="search-bar-container">
        <form class="search-bar" onsubmit="event.preventDefault(); searchOffers(document.getElementById('searchOffers').value);">
            <input type="text" id="searchOffers" placeholder="ابحث عن عرض..." 
                data-ar-placeholder="ابحث عن عرض..." 
                data-en-placeholder="Search offer..." 
                data-fr-placeholder="Rechercher offre..."
                oninput="searchOffers(this.value)">
            <button type="submit" title="بحث"><i class="fas fa-search"></i></button>
        </form>
        <div class="action-buttons">
            <button class="btn btn-primary" onclick="showAddOfferModal()">
                <i class="fas fa-plus"></i>
                <span data-ar="إضافة عرض جديد" data-en="Add New Offer" data-fr="Ajouter une Offre">إضافة عرض جديد</span>
            </button>
            <button class="btn btn-success" onclick="exportOffers()">
                <i class="fas fa-download"></i>
                <span data-ar="تصدير البيانات" data-en="Export Data" data-fr="Exporter les Données">تصدير البيانات</span>
            </button>
        </div>
    </div>

    <!-- Offers Table -->
    <div class="offers-table">
        <div class="table-header">
            <h3 class="table-title" data-ar="قائمة العروض" data-en="Offers List" data-fr="Liste des Offres">قائمة العروض</h3>
        </div>
        <div class="table-wrapper" style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th data-ar="الصورة" data-en="Image" data-fr="Image">الصورة</th>
                        <th data-ar="العنوان" data-en="Title" data-fr="Titre">العنوان</th>
                        <th data-ar="الوكالة" data-en="Agency" data-fr="Agence">الوكالة</th>
                        <th data-ar="السعر" data-en="Price" data-fr="Prix">السعر</th>
                        <th data-ar="النوع" data-en="Type" data-fr="Type">النوع</th>
                        <th data-ar="ذهبي؟" data-en="Golden?" data-fr="Dorée?">ذهبي؟</th>
                        <th data-ar="تاريخ السفر" data-en="Departure" data-fr="Départ">تاريخ السفر</th>
                        <th data-ar="تاريخ العودة" data-en="Return" data-fr="Retour">تاريخ العودة</th>
                        <th data-ar="مطار المغادرة" data-en="Airport" data-fr="Aéroport">مطار المغادرة</th>
                        <th data-ar="شركة الطيران" data-en="Airline" data-fr="Compagnie">شركة الطيران</th>
                     
                        <th data-ar="إجراءات" data-en="Actions" data-fr="Actions">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($offers as $offer): ?>
                        <tr>
                            <td>
                                <?php if (!empty($offer['image_offre_principale'])): ?>
                                    <img src="<?php echo htmlspecialchars($offer['image_offre_principale']); ?>" class="offer-thumbnail" alt="Offer Image">
                                <?php else: ?>
                                    <div class="no-image" data-ar="لا صورة" data-en="No Image" data-fr="Pas d'image">لا صورة</div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($offer['titre'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($offer['agency_name'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="price"><?php echo number_format($offer['prix_base'], 2); ?></span>
                            </td>
                            <td>
                                <span class="offer-type <?php echo isset($offer['type_voyage']) ? strtolower($offer['type_voyage']) : ''; ?>">
                                    <?php echo htmlspecialchars($offer['type_voyage'] ?? ''); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($offer['est_doree'])): ?>
                                    <span class="golden-badge" style="color:#ffd700;font-weight:bold;" data-ar="ذهبي" data-en="Golden" data-fr="Dorée">ذهبي</span>
                                <?php else: ?>
                                    <span class="badge" style="color:#14532d;" data-ar="عادي" data-en="Regular" data-fr="Régulier">عادي</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($offer['date_depart'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($offer['date_retour'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($offer['aeroport_depart'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($offer['compagnie_aerienne'] ?? ''); ?></td>
                           
                           
                            <td>
                                <div class="action-buttons">
                                    <button class="btn btn-sm btn-info" onclick="viewRoomDetails(<?php echo $offer['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <a class="btn btn-sm btn-success" href="function_manage_offers/details.php?id=<?php echo $offer['id']; ?>">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <button class="btn btn-sm btn-secondary" onclick="manageRoomImages(<?php echo $offer['id']; ?>)">
                                        <i class="fas fa-images"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- Room Details Modal -->
<div class="modal" id="viewRoomModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 data-ar="تفاصيل الغرفة" data-en="Room Details" data-fr="Détails de la chambre">تفاصيل الغرفة</h3>
            <button class="close-btn" onclick="hideModal('viewRoomModal')">&times;</button>
        </div>
        <div class="modal-body" id="roomDetailsContent">
            <!-- Room details will be loaded here via JS -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="hideModal('viewRoomModal')" data-ar="إغلاق" data-en="Close" data-fr="Fermer">إغلاق</button>
        </div>
    </div>
</div>

<!-- Edit Room Modal -->
<div class="modal" id="editRoomModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 data-ar="تعديل الغرفة" data-en="Edit Room" data-fr="Modifier la chambre">تعديل الغرفة</h3>
            <button class="close-btn" onclick="hideModal('editRoomModal')">&times;</button>
        </div>
        <form method="post" action="manage_offers.php">
            <input type="hidden" name="action" value="edit_offer">
            <input type="hidden" name="offer_id" id="edit_room_id">
            <div class="modal-body">
                <div class="row-inline">
                    <label data-ar="العنوان" data-en="Title" data-fr="Titre">العنوان</label>
                    <input type="text" name="title" id="edit_room_title" required>
                </div>
                <div class="row-inline">
                    <label data-ar="الوكالة" data-en="Agency" data-fr="Agence">الوكالة</label>
                    <select name="agency_id" id="edit_room_agency_id" required>
                        <?php foreach ($agencies as $agency): ?>
                            <option value="<?php echo $agency['id']; ?>"><?php echo htmlspecialchars($agency['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row-inline">
                    <label data-ar="النوع" data-en="Type" data-fr="Type">النوع</label>
                    <select name="type" id="edit_room_type">
                        <option value="Omra"><?php echo htmlspecialchars($t['umrah']); ?></option>
                        <option value="Hajj"><?php echo htmlspecialchars($t['hajj']); ?></option>
                    </select>
                </div>
                <div class="row-inline">
                    <label data-ar="السعر" data-en="Price" data-fr="Prix">السعر</label>
                    <input type="number" name="price" id="edit_room_price" min="0" step="0.01" required>
                </div>
                <div class="row-inline">
                    <label data-ar="تاريخ السفر" data-en="Departure Date" data-fr="Date de départ">تاريخ المغادرة</label>
                    <input type="date" name="date_depart" id="edit_room_date_depart">
                </div>
                <div class="row-inline">
                    <label data-ar="تاريخ العودة" data-en="Return Date" data-fr="Date de retour">تاريخ العودة</label>
                    <input type="date" name="date_retour" id="edit_room_date_retour">
                </div>
                <div class="row-inline">
                    <label data-ar="مطار المغادرة" data-en="Departure Airport" data-fr="Aéroport de départ">مطار المغادرة</label>
                    <input type="text" name="aeroport_depart" id="edit_room_aeroport_depart">
                </div>
                <div class="row-inline">
                    <label>
                        <input type="checkbox" name="est_doree" id="edit_room_est_doree" value="1">
                        <span data-ar="عرض ذهبي" data-en="Golden Offer" data-fr="Offre Dorée">عرض ذهبي</span>
                    </label>
                </div>
                <div class="row-inline">
                    <label data-ar="الوصف" data-en="Description" data-fr="Description">الوصف</label>
                    <textarea name="description" id="edit_room_description" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-success" data-ar="حفظ" data-en="Save" data-fr="Enregistrer">حفظ</button>
                <button type="button" class="btn btn-secondary" onclick="hideModal('editRoomModal')" data-ar="إلغاء" data-en="Cancel" data-fr="Annuler">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Room Images Modal -->
<div class="modal" id="roomImagesModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 data-ar="صور الغرفة" data-en="Room Images" data-fr="Images de la chambre">صور الغرفة</h3>
            <button class="close-btn" onclick="hideModal('roomImagesModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="image-upload-container">
                <form id="uploadRoomImageForm" method="post" action="function_manage_offers/upload_offer_image.php" enctype="multipart/form-data">
                    <input type="hidden" name="offer_id" id="currentRoomId">
                    <input type="file" name="chomber_image[]" accept="image/*" multiple required>
                    <button type="submit" class="btn btn-primary" data-ar="رفع الصور" data-en="Upload Images" data-fr="Télécharger">رفع الصور</button>
                </form>
            </div>
            <div class="images-grid" id="roomImagesGrid" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:15px;">
                <!-- Images will be loaded here via AJAX -->
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="hideModal('roomImagesModal')" data-ar="إغلاق" data-en="Close" data-fr="Fermer">إغلاق</button>
        </div>
    </div>
</div>

<script>
/**
 * عرض تفاصيل العرض مع كل البيانات المرجعة من API رفع الصور
 */
function viewRoomDetails(offerId) {
    // جلب بيانات العرض
    fetch(`function_manage_offers/get_offer_details.php?id=${offerId}`)
        .then(response => response.json())
        .then(offer => {
            // جلب صور العرض
            fetch(`function_manage_offers/get_offer_images.php?offer_id=${offerId}`)
                .then(res => res.json())
                .then(images => {
                    const content = document.getElementById('offerDetailsContent');
                    content.innerHTML = `
                        <div>
                            <h4>${offer.titre || ''}</h4>
                            <p><strong>الوكالة:</strong> ${offer.agence_id || ''}</p>
                            <p><strong>النوع:</strong> ${offer.type_voyage || ''} ${offer.est_doree ? '(ذهبي)' : ''}</p>
                            <p><strong>السعر الأساسي:</strong> ${offer.prix_base ?? ''}</p>
                            <p><strong>تاريخ السفر:</strong> ${offer.date_depart || ''}</p>
                            <p><strong>تاريخ العودة:</strong> ${offer.date_retour || ''}</p>
                            <p><strong>مطار المغادرة:</strong> ${offer.aeroport_depart || ''}</p>
                            <p><strong>شركة الطيران:</strong> ${offer.compagnie_aerienne || ''}</p>
                            <p><strong>اسم الفندق:</strong> ${offer.nom_hotel || ''}</p>
                            <p><strong>رقم الغرفة:</strong> ${offer.numero_chambre || ''}</p>
                            <p><strong>المسافة عن الحرم:</strong> ${offer.distance_haram ?? ''}</p>
                            <p><strong>سعر 2 أشخاص:</strong> ${offer.prix_2 ?? ''}</p>
                            <p><strong>سعر 3 أشخاص:</strong> ${offer.prix_3 ?? ''}</p>
                            <p><strong>سعر 4 أشخاص:</strong> ${offer.prix_4 ?? ''}</p>
                            <p><strong>الوصف:</strong> ${offer.description || ''}</p>
                            <p><strong>خدمات:</strong>
                                <ul>
                                    <li>دليل: ${offer.service_guide ? 'نعم' : 'لا'}</li>
                                    <li>نقل: ${offer.service_transport ? 'نعم' : 'لا'}</li>
                                    <li>إطعام: ${offer.service_nourriture ? 'نعم' : 'لا'}</li>
                                    <li>تأمين: ${offer.service_assurance ? 'نعم' : 'لا'}</li>
                                </ul>
                            </p>
                            <p><strong>هدايا:</strong>
                                <ul>
                                    <li>حقيبة: ${offer.cadeau_bag ? 'نعم' : 'لا'}</li>
                                    <li>زمزم: ${offer.cadeau_zamzam ? 'نعم' : 'لا'}</li>
                                    <li>مظلة: ${offer.cadeau_parapluie ? 'نعم' : 'لا'}</li>
                                    <li>أخرى: ${offer.cadeau_autre ? 'نعم' : 'لا'}</li>
                                </ul>
                            </p>
                            <p><strong>الصورة الرئيسية:</strong><br>
                                ${offer.image_offre_principale ? `<img src="${offer.image_offre_principale}" style="max-width:120px;border-radius:8px;border:1px solid #e9ecef;">` : 'لا توجد صورة'}
                            </p>
                        </div>
                        <div>
                            <h5>صور العرض</h5>
                            <div id="offerDetailsImagesGrid" style="display:flex;flex-wrap:wrap;gap:10px;">
                                ${
                                    images.length
                                    ? images.map(img => `<img src="${img.image_url}" alt="Offer Image" style="width:100px;height:100px;object-fit:cover;border-radius:8px;border:1px solid #e9ecef;">`).join('')
                                    : '<p>لا توجد صور لهذا العرض</p>'
                                }
                            </div>
                        </div>
                    `;
                    document.getElementById('viewOfferModal').classList.add('show');
                });
        });
}

// تعديل بيانات العرض
function editRoom(offerId) {
    fetch(`function_manage_offers/get_offer_details.php?id=${offerId}`)
        .then(response => response.json())
        .then(offer => {
            document.getElementById('edit_offer_id').value = offer.id;
            document.getElementById('edit_title').value = offer.titre;
            document.getElementById('edit_agency_id').value = offer.agence_id;
            document.getElementById('edit_type').value = offer.type_voyage;
            document.getElementById('edit_price').value = offer.prix_base;
            document.getElementById('edit_description').value = offer.description;
            document.getElementById('edit_date_depart').value = offer.date_depart;
            document.getElementById('edit_date_retour').value = offer.date_retour;
            document.getElementById('edit_aeroport_depart').value = offer.aeroport_depart;
            document.getElementById('edit_est_doree').checked = offer.est_doree ? true : false;
            document.getElementById('editOfferModal').classList.add('show');
        });
}

/

// إغلاق المودال
function hideModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}
    </script>

<!-- Edit Offer Modal -->
<div class="modal" id="editOfferModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 data-ar="تعديل العرض" data-en="Edit Offer" data-fr="Modifier l'Offre">تعديل العرض</h3>
            <button class="close-btn" onclick="hideModal('editOfferModal')">&times;</button>
        </div>
        <form method="post" action="manage_offers.php">
            <input type="hidden" name="action" value="edit_offer">
            <input type="hidden" name="offer_id" id="edit_offer_id">
            <div class="modal-body">
                <div class="row-inline">
                    <label data-ar="العنوان" data-en="Title" data-fr="Titre">العنوان</label>
                    <input type="text" name="title" id="edit_title" required>
                </div>
                <div class="row-inline">
                    <label data-ar="الوكالة" data-en="Agency" data-fr="Agence">الوكالة</label>
                    <select name="agency_id" id="edit_agency_id" required>
                        <?php foreach ($agencies as $agency): ?>
                            <option value="<?php echo $agency['id']; ?>"><?php echo htmlspecialchars($agency['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row-inline">
                    <label data-ar="النوع" data-en="Type" data-fr="Type">النوع</label>
                    <select name="type" id="edit_type">
                        <option value="Omra"><?php echo htmlspecialchars($t['umrah']); ?></option>
                        <option value="Hajj"><?php echo htmlspecialchars($t['hajj']); ?></option>
                    </select>
                </div>
                <div class="row-inline">
                    <label data-ar="السعر" data-en="Price" data-fr="Prix">السعر</label>
                    <input type="number" name="price" id="edit_price" min="0" step="0.01" required>
                </div>
                <div class="row-inline">
                    <label data-ar="تاريخ السفر" data-en="Departure Date" data-fr="Date de départ">تاريخ السفر</label>
                    <input type="date" name="date_depart" id="edit_date_depart">
                </div>
                <div class="row-inline">
                    <label data-ar="تاريخ العودة" data-en="Return Date" data-fr="Date de retour">تاريخ العودة</label>
                    <input type="date" name="date_retour" id="edit_date_retour">
                </div>
                <div class="row-inline">
                    <label data-ar="مطار المغادرة" data-en="Departure Airport" data-fr="Aéroport de départ">مطار المغادرة</label>
                    <input type="text" name="aeroport_depart" id="edit_aeroport_depart">
                </div>
                <div class="row-inline">
                    <label>
                        <input type="checkbox" name="est_doree" id="edit_est_doree" value="1">
                        <span data-ar="عرض ذهبي" data-en="Golden Offer" data-fr="Offre Dorée">عرض ذهبي</span>
                    </label>
                </div>
                <div class="row-inline">
                    <label data-ar="الوصف" data-en="Description" data-fr="Description">الوصف</label>
                    <textarea name="description" id="edit_description" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-success" data-ar="حفظ" data-en="Save" data-fr="Enregistrer">حفظ</button>
                <button type="button" class="btn btn-secondary" onclick="hideModal('editOfferModal')" data-ar="إلغاء" data-en="Cancel" data-fr="Annuler">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- View Offer Modal -->
<div class="modal" id="viewOfferModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 data-ar="تفاصيل العرض" data-en="Offer Details" data-fr="Détails de l'Offre">تفاصيل العرض</h3>
            <button class="close-btn" onclick="hideModal('viewOfferModal')">&times;</button>
        </div>
        <div class="modal-body" id="offerDetailsContent">
            <!-- Offer details will be loaded here via JS -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="hideModal('viewOfferModal')" data-ar="إغلاق" data-en="Close" data-fr="Fermer">إغلاق</button>
        </div>
    </div>
</div>

<!-- Modal Styles & Scripts -->
<style>
.modal { display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:2000; align-items:center; justify-content:center; }
.modal.show { display:flex; }
.modal-content { background:#fff; border-radius:16px; max-width:600px; width:100%; box-shadow:0 8px 32px rgba(0,0,0,0.15); padding:0; overflow:hidden; }
.modal-header, .modal-footer { padding:1rem 2rem; background:#f8f9fa; }
.modal-header { display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; }
.modal-footer { border-top:1px solid #eee; text-align:left; }
.modal-body { padding:2rem; }
.close-btn { background:none; border:none; font-size:1.5rem; color:#888; cursor:pointer; }
</style>

<script>
document.querySelectorAll('.modal').forEach(function(modal){
    modal.addEventListener('click', function(e){
        if(e.target === modal) modal.classList.remove('show');
    });
});
</script>
  
</body>
</html>