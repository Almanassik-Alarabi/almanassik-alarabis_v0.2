<?php
require_once '../includes/config.php';

// معالجة طلب جلب تفاصيل الطلب عبر AJAX
if (isset($_GET['ajax_request_details']) && is_numeric($_GET['ajax_request_details'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_GET['ajax_request_details']);
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
        $stmt = $pdo->prepare("SELECT d.*, o.titre AS offre_titre, a.nom_agence AS agence_nom FROM demande_umrah d LEFT JOIN offres o ON d.offre_id = o.id LEFT JOIN agences a ON o.agence_id = a.id WHERE d.id = ?");
        $stmt->execute([$id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($request) {
            echo json_encode($request, JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['error' => 'not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'db error']);
    }
    exit;
}

// دالة تحويل حالة الطلب إلى شارة
function get_status_badge($statut) {
    switch ($statut) {
        case 'en_attente':
            return ['class' => 'status-pending', 'icon' => 'fa-clock', 'text' => 'في الانتظار'];
        case 'accepte':
            return ['class' => 'status-approved', 'icon' => 'fa-check', 'text' => 'مقبولة (مرسلة للوكالة)'];
        case 'refuse':
            return ['class' => 'status-rejected', 'icon' => 'fa-times', 'text' => 'مرفوضة'];
        default:
            return ['class' => 'status-pending', 'icon' => 'fa-clock', 'text' => 'في الانتظار'];
    }
}

// فلترة الطلبات: نجبر الفلتر ليكون فقط "pending"
$status_filter = 'pending';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// بناء شرط WHERE
$where = ["statut = 'en_attente'"];
$params = [];
if ($search !== '') {
    $where[] = "(nom ILIKE :search OR telephone ILIKE :search OR CAST(id AS TEXT) ILIKE :search)";
    $params[':search'] = "%$search%";
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

// إحصائيات
$stats = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0
];
$stat_query = executeQuery("SELECT statut, COUNT(*) as cnt FROM demande_umrah GROUP BY statut");
$stat_rows = is_object($stat_query) ? $stat_query->fetchAll() : [];
foreach ($stat_rows as $row) {
    $stats['total'] += $row['cnt'];
    if ($row['statut'] === 'en_attente') $stats['pending'] = $row['cnt'];
    if ($row['statut'] === 'accepte') {
        $stats['approved'] = $row['cnt'];
    }
    if ($row['statut'] === 'refuse') $stats['rejected'] = $row['cnt'];
}

// معالجة تحديث الحالة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['request_id'])) {
    $action = $_POST['action'];
    $request_id = intval($_POST['request_id']);
    $admin_note = isset($_POST['admin_note']) ? trim($_POST['admin_note']) : '';

    // تحويل الأكشن إلى حالة قاعدة البيانات
    if ($action === 'approved' || $action === 'sent_to_agency') $new_statut = 'accepte';
    elseif ($action === 'rejected') $new_statut = 'refuse';
    else $new_statut = 'en_attente';

    $sql = "UPDATE demande_umrah SET statut = :statut WHERE id = :id";
    $params_upd = [':statut' => $new_statut, ':id' => $request_id];
    $res = executeQuery($sql, $params_upd);

    if ($res) {
        $success_message = "تم تحديث حالة الطلب بنجاح.";
    } else {
        $error_message = "حدث خطأ أثناء تحديث حالة الطلب.";
    }
}

// جلب الطلبات مع الانضمام للعروض والوكالات
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$count_sql = "SELECT COUNT(*) FROM demande_umrah $where_sql";
$total_requests = executeQuery($count_sql, $params)->fetchColumn();
$total_pages = ceil($total_requests / $per_page);

$sql = "SELECT d.*, o.titre AS offer_title, o.prix_base AS price, o.est_doree AS offer_type, a.nom_agence AS agency_name
        FROM demande_umrah d
        LEFT JOIN offres o ON d.offre_id = o.id
        LEFT JOIN agences a ON o.agence_id = a.id
        $where_sql
        ORDER BY d.date_demande DESC
        LIMIT $per_page OFFSET $offset";
$requests = executeQuery($sql, $params)->fetchAll();

// دالة لحساب الوقت المنقضي
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $string = [
        'y' => 'سنة',
        'm' => 'شهر',
        'd' => 'يوم',
        'h' => 'ساعة',
        'i' => 'دقيقة',
        's' => 'ثانية',
    ];
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v;
        } else {
            unset($string[$k]);
        }
    }
    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' منذ' : 'الآن';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الطلبات | Manage Requests</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: linear-gradient(135deg, #198754 0%, #14532d 100%); /* أخضر إسلامي */
            --secondary: linear-gradient(135deg, #ffe066 0%, #ffd700 100%); /* ذهبي */
            --success: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --warning: linear-gradient(135deg, #ffe066 0%, #ffd700 100%);
            --danger: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --info: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
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

        /* Sidebar - Same as dashboard */
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            padding: 1.5rem;
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
            background: var(--danger);
        }

        .stat-card:nth-child(5)::before {
            background: var(--info);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
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
            background: var(--danger);
        }

        .stat-card:nth-child(5) .stat-icon {
            background: var(--info);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 0.9rem;
        }

        /* Requests Table */
        .requests-section {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .requests-table {
            width: 100%;
            border-collapse: collapse;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .requests-table th {
            background: linear-gradient(135deg, #198754 0%, #14532d 100%);
            color: white;
            padding: 1rem;
            text-align: right;
            font-weight: 600;
        }

        .requests-table td {
            padding: 1rem;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }

        .requests-table tr:hover {
            background-color: #f8f9fa;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.8rem;
            font-weight: 600;
            color: white;
        }

        .status-pending { background: linear-gradient(135deg, #ffc107 0%, #ff8c00 100%); }
        .status-approved { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); }
        .status-rejected { background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%); }
        .status-sent_to_agency { background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%); }

        .offer-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .offer-standard {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
        }

        .offer-golden {
            background: linear-gradient(135deg, #ffd700 0%, #ffb347 100%);
            color: #8b4513;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            color: white;
        }
        .btn-approve { background: linear-gradient(135deg, #198754 0%, #14532d 100%); }
        .btn-reject { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .btn-send { background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%); }
        .btn-view { background: linear-gradient(135deg, #2196f3 0%, #00bcd4 100%); }
        .btn-view:hover { background: linear-gradient(135deg, #1565c0 0%, #00acc1 100%); color: #fff; box-shadow: 0 4px 16px rgba(33,150,243,0.13); }
        .btn-approve:hover { filter: brightness(1.1); box-shadow: 0 4px 16px rgba(25,135,84,0.13); }
        .btn-reject:hover { filter: brightness(1.1); box-shadow: 0 4px 16px rgba(250,112,154,0.13); }
        .btn-send:hover { filter: brightness(1.1); box-shadow: 0 4px 16px rgba(23,162,184,0.13); }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .pagination a,
        .pagination span {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .pagination a {
            background: white;
            color: var(--text-primary);
            border: 2px solid #e9ecef;
        }

        .pagination a:hover {
            background: var(--primary);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            transform: translateY(-2px);
        }

        .pagination .current {
            background: var(--primary);
            color: white;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: var(--card-bg);
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
            border-bottom: 2px solid #e9ecef;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .close {
            background: none;
            border: none;
            font-size: 2rem;
            cursor: pointer;
            color: var(--text-secondary);
            transition: color 0.3s ease;
        }

        .close:hover {
            color: var(--danger);
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
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
        }

        .modal-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
        }

        .btn-submit {
            background: var(--primary);
            color: white;
        }

        .modal-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border: 1px solid #f5c6cb;
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

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .requests-table {
                font-size: 0.8rem;
            }

            .requests-table th,
            .requests-table td {
                padding: 0.5rem;
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
        .stat-card:nth-child(5) { animation-delay: 0.5s; }
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
                    <a href="manage_offers.php">
                        <i class="fas fa-gift"></i>
                        <span class="sidebar-text" data-ar="إدارة العروض" data-en="Manage Offers" data-fr="Gérer les Offres">إدارة العروض</span>
                    </a>
                </li>
                <li>
                    <a href="manage_pilgrims.php">
                        <i class="fas fa-users"></i>
                        <span class="sidebar-text" data-ar="إدارة الحجاج" data-en="Manage Pilgrims" data-fr="Gérer les Pèlerins">إدارة الحجاج</span>
                    </a>
                </li>
                <li>
                    <a href="manage_requests.php" class="active">
                        <i class="fas fa-clipboard-list"></i>
                        <span class="sidebar-text" data-ar="إدارة الطلبات" data-en="Manage Requests" data-fr="Gérer les Demandes">إدارة الطلبات</span>
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
                        <i class="fas fa-cog"></i>
                        <span class="sidebar-text" data-ar="الإعدادات" data-en="Settings" data-fr="Paramètres">الإعدادات</span>
                    </a>
                </li>
                <li>
                    <a href="../includes/logout.php">
                        <i class="fas fa-sign-out-alt"></i>
                        <span class="sidebar-text" data-ar="تسجيل الخروج" data-en="Logout" data-fr="Déconnexion">تسجيل الخروج</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="main-content">
            <!-- Header -->
            <div class="header">
                <div class="header-left">
                    <h1 data-ar="إدارة الطلبات" data-en="Manage Requests" data-fr="Gérer les Demandes">إدارة الطلبات</h1>
                    <p data-ar="عرض وتعديل طلبات العمرة" data-en="View and modify Umrah requests" data-fr="Afficher et modifier les demandes Omra">عرض وتعديل طلبات العمرة</p>
                </div>
                <div class="header-right">
                    <div class="language-switcher">
                        <button class="lang-btn active" data-lang="ar">العربية</button>
                        <button class="lang-btn" data-lang="en">English</button>
                        <button class="lang-btn" data-lang="fr">Français</button>
                    </div>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['total']; ?></div>
                            <div class="stat-label" data-ar="إجمالي الطلبات" data-en="Total Requests" data-fr="Demandes Totales">إجمالي الطلبات</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['pending']; ?></div>
                            <div class="stat-label" data-ar="في الانتظار" data-en="Pending" data-fr="En Attente">في الانتظار</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['approved']; ?></div>
                            <div class="stat-label" data-ar="مقبولة" data-en="Approved" data-fr="Approuvées">مقبولة</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-check"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['rejected']; ?></div>
                            <div class="stat-label" data-ar="مرفوضة" data-en="Rejected" data-fr="Rejetées">مرفوضة</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-times"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Requests Section -->
            <div class="requests-section">
                <div class="section-header">
                    <div class="section-title" data-ar="قائمة الطلبات" data-en="Requests List" data-fr="Liste des Demandes">قائمة الطلبات</div>
                </div>

                <div class="table-responsive">
                    <table class="requests-table">
                        <thead>
                            <tr>
                                <th data-ar="رقم الطلب" data-en="Request ID" data-fr="ID Demande">رقم الطلب</th>
                                <th data-ar="الحاج" data-en="Pilgrim" data-fr="Pèlerin">الحاج</th>
                                <th data-ar="العرض" data-en="Offer" data-fr="Offre">العرض</th>
                                <th data-ar="الوكالة" data-en="Agency" data-fr="Agence">الوكالة</th>
                                <th data-ar="تاريخ الطلب" data-en="Request Date" data-fr="Date Demande">تاريخ الطلب</th>
                                <th data-ar="إجراءات" data-en="Actions" data-fr="Actions">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($requests)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center;" data-ar="لا توجد طلبات متاحة" data-en="No requests available" data-fr="Aucune demande disponible">لا توجد طلبات متاحة</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($requests as $request): ?>
                                    <?php 
                                        $offer_badge_class = $request['offer_type'] ? 'offer-golden' : 'offer-standard';
                                        $offer_badge_text = $request['offer_type'] ? 'ذهبي' : 'عادي';
                                    ?>
                                    <tr>
                                        <td>#<?php echo $request['id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($request['nom']); ?></strong><br>
                                            <small><?php echo htmlspecialchars($request['telephone']); ?></small>
                                        </td>
                                        <td>
                                            <span class="offer-badge <?php echo $offer_badge_class; ?>">
                                            <?php echo htmlspecialchars($request['offer_title']); ?>
                                        </span><br>
                                        <?php echo number_format($request['price'], 2); ?> دج
                                        </td>
                                        <td><?php echo htmlspecialchars($request['agency_name']); ?></td>
                                        <td>
                                            <?php echo date('Y/m/d', strtotime($request['date_demande'])); ?><br>
                                            <small><?php echo time_elapsed_string($request['date_demande']); ?></small>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-btn btn-view" onclick="openViewModal(<?php echo $request['id']; ?>)">
                                                    <i class="fas fa-eye"></i> <span data-ar="عرض" data-en="View" data-fr="Voir">عرض</span>
                                                </button>
                                                <button class="action-btn btn-approve" onclick="openActionModal(<?php echo $request['id']; ?>, 'approved')">
                                                    <i class="fas fa-check"></i> <span data-ar="قبول" data-en="Approve" data-fr="Approuver">قبول</span>
                                                </button>
                                                <button class="action-btn btn-reject" onclick="openActionModal(<?php echo $request['id']; ?>, 'rejected')">
                                                    <i class="fas fa-times"></i> <span data-ar="رفض" data-en="Reject" data-fr="Rejeter">رفض</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" data-ar="الأولى" data-en="First" data-fr="Première">الأولى</a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" data-ar="السابقة" data-en="Previous" data-fr="Précédente">السابقة</a>
                        <?php endif; ?>

                        <?php 
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            
                            if ($start > 1) {
                                echo '<span>...</span>';
                            }
                            
                            for ($i = $start; $i <= $end; $i++): 
                        ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" <?php echo $i == $page ? 'class="current"' : ''; ?>><?php echo $i; ?></a>
                        <?php 
                            endfor; 
                            
                            if ($end < $total_pages) {
                                echo '<span>...</span>';
                            }
                        ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" data-ar="التالية" data-en="Next" data-fr="Suivante">التالية</a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" data-ar="الأخيرة" data-en="Last" data-fr="Dernière">الأخيرة</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- View Request Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content" style="max-width:900px; margin: 20px auto;">
            <div class="modal-header">
                <h3 class="modal-title" data-ar="تفاصيل الطلب" data-en="Request Details" data-fr="Détails de la Demande">تفاصيل الطلب</h3>
                <button class="close" onclick="closeModal('viewModal')">&times;</button>
            </div>
            <div id="requestDetails">
                <!-- Details will be loaded here via AJAX -->
            </div>
            <div class="modal-actions">
                <button class="modal-btn btn-cancel" onclick="closeModal('viewModal')" data-ar="إغلاق" data-en="Close" data-fr="Fermer">إغلاق</button>
            </div>
        </div>
    </div>

    <!-- Action Modal -->
    <div id="actionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="actionModalTitle">تحديث حالة الطلب</h3>
                <button class="close" onclick="closeModal('actionModal')">&times;</button>
            </div>
            <form id="actionForm" method="POST">
                <input type="hidden" name="action" id="actionType">
                <input type="hidden" name="request_id" id="actionRequestId">
                <div class="form-group" id="adminNoteGroup">
                    <label for="admin_note" class="form-label" data-ar="ملاحظة إدارية" data-en="Admin Note" data-fr="Note Administrative">ملاحظة إدارية</label>
                    <textarea name="admin_note" id="admin_note" class="form-input form-textarea" placeholder="أدخل ملاحظة إدارية (اختياري)..."></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="modal-btn btn-cancel" onclick="closeModal('actionModal')" data-ar="إلغاء" data-en="Cancel" data-fr="Annuler">إلغاء</button>
                    <button type="submit" class="modal-btn btn-submit" data-ar="تأكيد" data-en="Confirm" data-fr="Confirmer">تأكيد</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toggle Sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            // Update all sidebar text visibility
            const sidebarTexts = document.querySelectorAll('.sidebar-text');
            sidebarTexts.forEach(text => {
                text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline';
            });
            
            // Change icon when collapsed
            const toggleBtn = document.querySelector('.sidebar-toggle i');
            if (sidebar.classList.contains('collapsed')) {
                toggleBtn.classList.remove('fa-bars');
                toggleBtn.classList.add('fa-indent');
            } else {
                toggleBtn.classList.remove('fa-indent');
                toggleBtn.classList.add('fa-bars');
            }
        }

        // Language Switcher
        document.querySelectorAll('.lang-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const lang = this.getAttribute('data-lang');

                // Update active button
                document.querySelectorAll('.lang-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                // Update all translatable elements
                document.querySelectorAll('[data-ar]').forEach(el => {
                    el.textContent = el.getAttribute(`data-${lang}`);
                });

                // Update modal titles if open
                if (document.getElementById('viewModal').style.display === 'block') {
                    document.querySelector('#viewModal .modal-title').textContent = 
                        document.querySelector('#viewModal .modal-title').getAttribute(`data-${lang}`);
                }
                if (document.getElementById('actionModal').style.display === 'block') {
                    document.querySelector('#actionModal .modal-title').textContent = 
                        document.querySelector('#actionModal .modal-title').getAttribute(`data-${lang}`);
                }

                // Sidebar direction switch
                const sidebar = document.getElementById('sidebar');
                const mainContent = document.getElementById('main-content');
                if(lang === 'ar') {
                    sidebar.style.right = '0';
                    sidebar.style.left = '';
                    mainContent.style.marginRight = sidebar.classList.contains('collapsed') ? '70px' : '250px';
                    mainContent.style.marginLeft = '0';
                } else {
                    sidebar.style.left = '0';
                    sidebar.style.right = '';
                    mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '70px' : '250px';
                    mainContent.style.marginRight = '0';
                }
            });
        });

        // Modal Functions
        function openViewModal(requestId) {
            const detailsDiv = document.getElementById('requestDetails');
            detailsDiv.innerHTML = '<div style="text-align:center;padding:2rem;"><i class="fas fa-spinner fa-spin" style="font-size:2.5rem;color:#007bff;"></i><br><span style="display:block;margin-top:1rem;">جاري تحميل التفاصيل...</span></div>';
            document.getElementById('viewModal').style.display = 'block';
            fetch('manage_requests.php?ajax_request_details=' + encodeURIComponent(requestId))
                .then(res => res.json())
                .then(request => {
                    if (!request || request.error) {
                        detailsDiv.innerHTML = '<div style="color:#b00;text-align:center;padding:2rem;">لا يمكن عرض تفاصيل الطلب حالياً</div>';
                        return;
                    }
                    let passportImageHtml = '';
                    if (request.passport_image) {
                        passportImageHtml = `
                            <div style="flex:1 1 350px;display:flex;justify-content:center;align-items:flex-start;">
                                <img src="uploads/passports/${request.passport_image}" alt="صورة جواز السفر" style="max-width:350px;max-height:350px;border-radius:18px;border:2px solid #eee;box-shadow:0 2px 16px rgba(0,0,0,0.10);" />
                            </div>
                        `;
                    }
                    const detailsHtml = `
                        <div style="display:flex;flex-wrap:wrap;gap:2.5rem;align-items:flex-start;">
                            <div style="flex:1 1 320px;min-width:220px;">
                                <div style="display:flex;flex-wrap:wrap;gap:1.2rem;">
                                    <div class="form-group" style="flex:1 1 45%;margin-bottom:1rem;">
                                        <label class="form-label">الاسم:</label>
                                        <div>${request.nom || ''}</div>
                                    </div>
                                    <div class="form-group" style="flex:1 1 45%;margin-bottom:1rem;">
                                        <label class="form-label">رقم الهاتف:</label>
                                        <div>${request.telephone || ''}</div>
                                    </div>
                                    <div class="form-group" style="flex:1 1 45%;margin-bottom:1rem;">
                                        <label class="form-label">الولاية:</label>
                                        <div>${request.wilaya || ''}</div>
                                    </div>
                                    <div class="form-group" style="flex:1 1 45%;margin-bottom:1rem;">
                                        <label class="form-label">العرض:</label>
                                        <div>${request.offre_titre || ''}</div>
                                    </div>
                                    <div class="form-group" style="flex:1 1 45%;margin-bottom:1rem;">
                                        <label class="form-label">الوكالة:</label>
                                        <div>${request.agence_nom || ''}</div>
                                    </div>
                                    <div class="form-group" style="flex:1 1 45%;margin-bottom:1rem;">
                                        <label class="form-label">تاريخ الطلب:</label>
                                        <div>${request.date_demande ? request.date_demande.split('T')[0] : ''}</div>
                                    </div>
                                    <div class="form-group" style="flex:1 1 45%;margin-bottom:1rem;">
                                        <label class="form-label">الحالة:</label>
                                        <div>${request.statut || ''}</div>
                                    </div>
                                </div>
                            </div>
                            ${passportImageHtml}
                        </div>
                    `;
                    detailsDiv.innerHTML = detailsHtml;
                })
                .catch(() => {
                    detailsDiv.innerHTML = '<div style="color:#b00;text-align:center;padding:2rem;">لا يمكن عرض تفاصيل الطلب حالياً</div>';
                });
        }

        function openActionModal(requestId, actionType) {
            document.getElementById('actionRequestId').value = requestId;
            document.getElementById('actionType').value = actionType;

            // إخفاء حقل الملاحظة الإدارية عند القبول أو الرفض
            var adminNoteGroup = document.getElementById('adminNoteGroup');
            if (actionType === 'approved' || actionType === 'rejected') {
                adminNoteGroup.style.display = 'none';
            } else {
                adminNoteGroup.style.display = '';
            }

            // Update modal title based on action
            const actionTitles = {
                'approved': {ar: 'قبول الطلب', en: 'Approve Request', fr: 'Approuver la Demande'},
                'rejected': {ar: 'رفض الطلب', en: 'Reject Request', fr: 'Rejeter la Demande'},
                'sent_to_agency': {ar: 'إرسال الطلب للوكالة', en: 'Send Request to Agency', fr: 'Envoyer la Demande à l\'Agence'}
            };

            const currentLang = document.querySelector('.lang-btn.active').getAttribute('data-lang');
            document.getElementById('actionModalTitle').textContent = actionTitles[actionType][currentLang];

            document.getElementById('actionModal').style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        }

        // Initialize sidebar texts
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar.classList.contains('collapsed')) {
                const sidebarTexts = document.querySelectorAll('.sidebar-text');
                sidebarTexts.forEach(text => {
                    text.style.display = 'none';
                });
                
                // Set toggle button icon
                const toggleBtn = document.querySelector('.sidebar-toggle i');
                toggleBtn.classList.remove('fa-bars');
                toggleBtn.classList.add('fa-indent');
            }
        });
    </script>
</body>
</html>