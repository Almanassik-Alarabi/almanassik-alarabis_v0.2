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

    // جلب بيانات المعتمرين
    $pilgrims = $pdo->query("SELECT * FROM demande_umrah ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

    // ...يمكنك هنا جلب $stats و $nationalities إذا كنت تحتاجها...
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
                           background: linear-gradient(135deg,rgb(4, 128, 70) 0%,rgb(180, 163, 5) 100%);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

               /* Sidebar */
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
            margin-right: 250px;
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
            animation: fadeIn
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
                <!-- <li>
                    <a href="manage_sub_admins.php">
                        <i class="fas fa-user-cog"></i>
                        <span class="sidebar-text" data-ar="إدارة المدراء الثانويين" data-en="Manage Secondary Admins" data-fr="Gérer les Admins Secondaires">إدارة المدراء الثانويين</span>
                    </a>
                </li> -->
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
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $stats['nationalities']; ?></div>
                            <div class="stat-label" data-ar="جنسيات مختلفة" data-en="Different Nationalities" data-fr="Nationalités différentes">جنسيات مختلفة</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-globe-africa"></i>
                        </div>
                    </div>
                    <div class="stat-change text-info">
                        <i class="fas fa-arrow-up"></i>
                        <span data-ar="زيادة 3 جنسيات جديدة" data-en="3 new nationalities" data-fr="3 nouvelles nationalités">زيادة 3 جنسيات جديدة</span>
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
                                <input type="text" class="search-input" placeholder="ابحث عن معتمر..." data-ar-placeholder="ابحث عن معتمر..." data-en-placeholder="Search pilgrim..." data-fr-placeholder="Rechercher pèlerin..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <select class="filter-select" id="nationalityFilter">
                                <option value="" data-ar="كل الجنسيات" data-en="All Nationalities" data-fr="Toutes nationalités">كل الجنسيات</option>
                                <?php foreach ($nationalities as $nationality): ?>
                                    <option value="<?php echo htmlspecialchars($nationality); ?>" <?php echo $nationality_filter === $nationality ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($nationality); ?>
                                    </option>
                                <?php endforeach; ?>
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
                                <th data-ar="المعتمر" data-en="Pilgrim" data-fr="Pèlerin">المعتمر</th>
                                <th data-ar="صورة الجواز" data-en="Passport Image" data-fr="Image Passeport">صورة الجواز</th>
                                <th data-ar="رقم الجواز" data-en="Passport No." data-fr="Passeport No.">رقم الجواز</th>
                                <th data-ar="الهاتف" data-en="Phone" data-fr="Téléphone">الهاتف</th>
                                <th data-ar="البريد الإلكتروني" data-en="Email" data-fr="Email">البريد الإلكتروني</th>
                                <th data-ar="الجنسية" data-en="Nationality" data-fr="Nationalité">الجنسية</th>
                                <th data-ar="تاريخ التسجيل" data-en="Registration Date" data-fr="Date d'inscription">تاريخ التسجيل</th>
                                <th data-ar="الحالة" data-en="Status" data-fr="Statut">الحالة</th>
                                <th data-ar="إجراءات" data-en="Actions" data-fr="Actions">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pilgrims as $pilgrim): ?>
                                <tr>
                                    <td>
                                        <div class="pilgrim-info">
                                            <div class="pilgrim-avatar">
                                                <?php
                                                if (!empty($pilgrim['nom'])) {
                                                    echo mb_substr($pilgrim['nom'], 0, 1);
                                                } elseif (!empty($pilgrim['prenom'])) {
                                                    echo mb_substr($pilgrim['prenom'], 0, 1);
                                                } else {
                                                    echo '?';
                                                }
                                                ?>
                                            </div>
                                            <div class="pilgrim-details">
                                                <h4>
                                                    <?php
                                                    if (!empty($pilgrim['full_name'])) {
                                                        echo htmlspecialchars($pilgrim['full_name']);
                                                    } else {
                                                        echo htmlspecialchars(trim(($pilgrim['nom'] ?? '') . ' ' . ($pilgrim['prenom'] ?? '')));
                                                    }
                                                    ?>
                                                </h4>
                                                <p>
                                                    <?php
                                                    if (!empty($pilgrim['birth_date'])) {
                                                        echo htmlspecialchars($pilgrim['birth_date']);
                                                    } elseif (!empty($pilgrim['admin_commentaire']) && preg_match('/تاريخ الميلاد: ([^\n]+)/u', $pilgrim['admin_commentaire'], $m)) {
                                                        echo htmlspecialchars($m[1]);
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($pilgrim['passport_image'])): ?>
                                            <img src="uploads/passports/<?php echo htmlspecialchars($pilgrim['passport_image']); ?>" alt="صورة الجواز" style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid #eee;" />
                                        <?php else: ?>
                                            <span style="color:#bbb;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        if (!empty($pilgrim['passport_number'])) {
                                            echo htmlspecialchars($pilgrim['passport_number']);
                                        } elseif (!empty($pilgrim['admin_commentaire']) && preg_match('/رقم الجواز: ([^\n]+)/u', $pilgrim['admin_commentaire'], $m)) {
                                            echo htmlspecialchars($m[1]);
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        // phone من الحقل أو admin_commentaire
                                        if (!empty($pilgrim['telephone'])) {
                                            echo htmlspecialchars($pilgrim['telephone']);
                                        } elseif (!empty($pilgrim['phone'])) {
                                            echo htmlspecialchars($pilgrim['phone']);
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($pilgrim['email'] ?? '-'); ?></td>
                                    <td>
                                        <?php
                                        if (!empty($pilgrim['nationality'])) {
                                            echo htmlspecialchars($pilgrim['nationality']);
                                        } elseif (!empty($pilgrim['admin_commentaire']) && preg_match('/الجنسية: ([^\n]+)/u', $pilgrim['admin_commentaire'], $m)) {
                                            echo htmlspecialchars($m[1]);
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        // created_at أو date_demande
                                        if (!empty($pilgrim['created_at'])) {
                                            echo time_elapsed_string($pilgrim['created_at']);
                                        } elseif (!empty($pilgrim['date_demande'])) {
                                            echo time_elapsed_string($pilgrim['date_demande']);
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-active" data-ar="نشط" data-en="Active" data-fr="Actif">نشط</span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn btn-view" onclick="viewPilgrim(<?php echo $pilgrim['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="action-btn btn-edit" onclick="editPilgrim(<?php echo $pilgrim['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="action-btn btn-delete" onclick="confirmDelete(<?php echo $pilgrim['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
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
        <div class="modal-content">
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
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" data-ar="تعديل بيانات المعتمر" data-en="Edit Pilgrim" data-fr="Modifier pèlerin">تعديل بيانات المعتمر</h3>
                <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form id="editPilgrimForm">
                <div id="editPilgrimFormContent">
                    <!-- Form will be loaded here via AJAX -->
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')" data-ar="إلغاء" data-en="Cancel" data-fr="Annuler">إلغاء</button>
                    <button type="submit" class="btn btn-primary" data-ar="حفظ التغييرات" data-en="Save Changes" data-fr="Enregistrer">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" data-ar="تأكيد الحذف" data-en="Confirm Delete" data-fr="Confirmer suppression">تأكيد الحذف</h3>
                <button class="close-btn" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <div class="form-group">
                <p data-ar="هل أنت متأكد أنك تريد حذف هذا المعتمر؟ لا يمكن التراجع عن هذا الإجراء." data-en="Are you sure you want to delete this pilgrim? This action cannot be undone." data-fr="Êtes-vous sûr de vouloir supprimer ce pèlerin? Cette action est irréversible.">هل أنت متأكد أنك تريد حذف هذا المعتمر؟ لا يمكن التراجع عن هذا الإجراء.</p>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')" data-ar="إلغاء" data-en="Cancel" data-fr="Annuler">إلغاء</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn" data-ar="حذف" data-en="Delete" data-fr="Supprimer">حذف</button>
            </div>
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
            const url = new URL(window.location.href);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }

        // Search and filter
        const searchInput = document.querySelector('.search-input');
        if (searchInput) {
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchPilgrims();
                }
            });
        }
       
        if (nationalityFilter) {
            nationalityFilter.addEventListener('change', searchPilgrims);
        }
        function searchPilgrims() {
            const search = searchInput ? searchInput.value : '';
            const nationality = nationalityFilter ? nationalityFilter.value : '';
            const url = new URL(window.location.href);
            url.searchParams.set('search', search);
            url.searchParams.set('nationality', nationality);
            url.searchParams.set('page', 1); // Reset to first page
            window.location.href = url.toString();
        }

        // View pilgrim
        function viewPilgrim(id) {
            // في التطبيق الحقيقي، يجب جلب بيانات المعتمر من قاعدة البيانات عبر AJAX
            // هنا مثال توضيحي فقط، يجب تعديله لاحقاً ليجلب الصورة الحقيقية
            const pilgrim = {
                id: id,
                full_name: 'أحمد محمد الأحمد',
                passport_number: 'A123456789',
                phone: '+966501234567',
                email: 'ahmed@email.com',
                birth_date: '1985-05-15',
                nationality: 'السعودية',
                address: 'الرياض، حي الملز، شارع الملك فهد',
                medical_conditions: 'لا يوجد',
                emergency_contact: 'محمد الأحمد (أب) - +966501112233',
                created_at: '2024-01-15 10:30:00',
                status: 'نشط',
                passport_image: 'sample_passport.jpg' // مثال، يجب جلبه من قاعدة البيانات
            };

            let passportImageHtml = '';
            if (pilgrim.passport_image) {
                passportImageHtml = `<div class="form-group">
                    <label class="form-label">صورة جواز السفر</label>
                    <img src="uploads/passports/${pilgrim.passport_image}" alt="صورة جواز السفر" style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid #eee;" />
                </div>`;
            }

            const detailsHtml = `
                <div class="form-group">
                    <label class="form-label">الاسم الكامل</label>
                    <p class="form-static">${pilgrim.full_name}</p>
                </div>
                <div class="form-group">
                    <label class="form-label">رقم الجواز</label>
                    <p class="form-static">${pilgrim.passport_number}</p>
                </div>
                <div class="form-group">
                    <label class="form-label">تاريخ الميلاد</label>
                    <p class="form-static">${pilgrim.birth_date}</p>
                </div>
                <div class="form-group">
                    <label class="form-label">الجنسية</label>
                    <p class="form-static">${pilgrim.nationality}</p>
                </div>
                <div class="form-group">
                    <label class="form-label">الهاتف</label>
                    <p class="form-static">${pilgrim.phone}</p>
                </div>
                <div class="form-group">
                    <label class="form-label">البريد الإلكتروني</label>
                    <p class="form-static">${pilgrim.email}</p>
                </div>
                <div class="form-group">
                    <label class="form-label">العنوان</label>
                    <p class="form-static">${pilgrim.address}</p>
                </div>
                <div class="form-group">
                    <label class="form-label">الحالة الصحية</label>
                    <p class="form-static">${pilgrim.medical_conditions}</p>
                </div>
                <div class="form-group">
                    <label class="form-label">جهة الاتصال في حالات الطوارئ</label>
                    <p class="form-static">${pilgrim.emergency_contact}</p>
                </div>
                ${passportImageHtml}
                <div class="form-group">
                    <label class="form-label">تاريخ التسجيل</label>
                    <p class="form-static">${pilgrim.created_at}</p>
                </div>
                <div class="form-group">
                    <label class="form-label">الحالة</label>
                    <p class="form-static">${pilgrim.status}</p>
                </div>
            `;

            document.getElementById('pilgrimDetails').innerHTML = detailsHtml;
            document.getElementById('viewModal').style.display = 'block';
        }

        // Edit pilgrim
        function editPilgrim(id) {
            // In a real application, this would be an AJAX call to fetch pilgrim details
            // For demo, we'll use the sample data
            const pilgrim = {
                id: id,
                full_name: 'أحمد محمد الأحمد',
                passport_number: 'A123456789',
                phone: '+966501234567',
                email: 'ahmed@email.com',
                birth_date: '1985-05-15',
                nationality: 'السعودية',
                address: 'الرياض، حي الملز، شارع الملك فهد',
                medical_conditions: 'لا يوجد',
                emergency_contact: 'محمد الأحمد (أب) - +966501112233'
            };

            const formHtml = `
                <input type="hidden" name="id" value="${pilgrim.id}">
                <div class="form-group">
                    <label class="form-label">الاسم الكامل</label>
                    <input type="text" class="form-input" name="full_name" value="${pilgrim.full_name}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">رقم الجواز</label>
                    <input type="text" class="form-input" name="passport_number" value="${pilgrim.passport_number}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">تاريخ الميلاد</label>
                    <input type="date" class="form-input" name="birth_date" value="${pilgrim.birth_date}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">الجنسية</label>
                    <select class="form-input" name="nationality" required>
                        <option value="السعودية" ${pilgrim.nationality === 'السعودية' ? 'selected' : ''}>السعودية</option>
                        <option value="الكويت" ${pilgrim.nationality === 'الكويت' ? 'selected' : ''}>الكويت</option>
                        <option value="الإمارات" ${pilgrim.nationality === 'الإمارات' ? 'selected' : ''}>الإمارات</option>
                        <option value="مصر" ${pilgrim.nationality === 'مصر' ? 'selected' : ''}>مصر</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الهاتف</label>
                    <input type="tel" class="form-input" name="phone" value="${pilgrim.phone}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">البريد الإلكتروني</label>
                    <input type="email" class="form-input" name="email" value="${pilgrim.email}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">العنوان</label>
                    <textarea class="form-input" name="address" rows="3">${pilgrim.address}</textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">الحالة الصحية</label>
                    <textarea class="form-input" name="medical_conditions" rows="2">${pilgrim.medical_conditions}</textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">جهة الاتصال في حالات الطوارئ</label>
                    <textarea class="form-input" name="emergency_contact" rows="2">${pilgrim.emergency_contact}</textarea>
                </div>
            `;

            document.getElementById('editPilgrimFormContent').innerHTML = formHtml;
            document.getElementById('editModal').style.display = 'block';
        }

        // Submit edit form
        document.getElementById('editPilgrimForm').addEventListener('submit', function(e) {
            e.preventDefault();
            // In a real application, this would be an AJAX call to update the pilgrim
            alert('تم تحديث بيانات المعتمر بنجاح');
            closeModal('editModal');
        });

        // Delete pilgrim
        let pilgrimToDelete = null;

        function confirmDelete(id) {
            pilgrimToDelete = id;
            document.getElementById('deleteModal').style.display = 'block';
        }

        document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
            if (pilgrimToDelete) {
                // In a real application, this would be an AJAX call to delete the pilgrim
                alert('تم حذف المعتمر بنجاح');
                closeModal('deleteModal');
                // Reload the page to reflect changes
                window.location.reload();
            }
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
                    } else {
                        el.textContent = el.getAttribute(`data-${lang}`);
                    }
                }
            });
        }

        // Initialize stats variable
        if (!isset($stats) || !is_array($stats)) {
            $stats = [];
        }

        // Example of safe usage:
        $value = isset($stats['some_key']) ? $stats['some_key'] : 0;
    </script>
</body>
</html>

<?php
// جلب بيانات المعتمرين
try {
    $pilgrims = $pdo->query("SELECT * FROM demande_umrah ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pilgrims = [];
}