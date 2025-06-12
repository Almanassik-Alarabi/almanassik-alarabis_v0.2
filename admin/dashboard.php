<?php
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

    // استرجاع إحصائيات الوكالات
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM agences");
    $totalAgencies = $stmt->fetch()['total'];

    // استرجاع إحصائيات المعتمرين
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM demande_umrah");
    $totalPilgrims = $stmt->fetch()['total'];

    // استرجاع إحصائيات العروض النشطة
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM offres");
    $totalOffers = $stmt->fetch()['total'];

    // استرجاع إحصائيات الطلبات المعلقة
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM demande_umrah WHERE statut = 'en_attente'");
    $pendingRequests = $stmt->fetch()['total'];

    // استرجاع النشاطات الأخيرة
    $stmt = $pdo->query("
        SELECT 
            'new_request' as type,
            CONCAT(d.nom, ' ', d.prenom) as title,
            d.date_demande as date
        FROM demande_umrah d
        WHERE d.date_demande >= NOW() - INTERVAL '24 hours'
        UNION ALL
        SELECT 
            'new_offer' as type,
            o.titre as title,
            o.date_depart as date
        FROM offres o
        WHERE o.date_depart >= NOW() - INTERVAL '24 hours'
        ORDER BY date DESC
        LIMIT 5
    ");
    $recentActivities = $stmt->fetchAll();

    // استرجاع المدراء الثانويين
    $stmt = $pdo->query("
        SELECT 
            sa.*,
            json_agg(
                json_build_object(
                    'permission_key', sap.permission_key,
                    'allow_view', sap.allow_view,
                    'allow_add', sap.allow_add,
                    'allow_edit', sap.allow_edit,
                    'allow_delete', sap.allow_delete
                )
            ) as permissions
        FROM sub_admins sa
        LEFT JOIN sub_admin_permissions sap ON sa.id = sap.sub_admin_id
        GROUP BY sa.id
        ORDER BY sa.id DESC
    ");
    $secondaryAdmins = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log('خطأ في الاتصال بقاعدة البيانات: ' . $e->getMessage());
    $error = 'حدث خطأ في النظام. يرجى المحاولة لاحقاً.';
}


$admin_id = $_SESSION['admin_id'];

// استعلام لاسترجاع اللغة المفضلة
$query = $pdo->prepare("SELECT langue_preferee FROM admins WHERE id = :id");
$query->execute(['id' => $admin_id]);
$admin = $query->fetch();

$langue_preferee = $admin && in_array($admin['langue_preferee'], ['ar','en','fr']) ? $admin['langue_preferee'] : 'ar';

// تحديد اتجاه الصفحة واللغة حسب اللغة المفضلة
$dir = $langue_preferee === 'ar' ? 'rtl' : 'ltr';
$translations = [
    'ar' => [
        'dashboard_title' => 'لوحة تحكم منصة العمرة',
        'welcome' => 'مرحباً بك في لوحة التحكم',
        'subtitle' => 'إدارة شاملة لمنصة العمرة'
    ],
    'en' => [
        'dashboard_title' => 'Umrah Platform Dashboard',
        'welcome' => 'Welcome to Dashboard',
        'subtitle' => 'Comprehensive Umrah Platform Management'
    ],
    'fr' => [
        'dashboard_title' => 'Tableau de Bord Plateforme Omra',
        'welcome' => 'Bienvenue au Tableau de bord',
        'subtitle' => 'Gestion complète de la plateforme Omra'
    ]
];

$current_translations = $translations[$langue_preferee];
?>
<!DOCTYPE html>
<html lang="<?php echo $langue_preferee; ?>" dir="<?php echo $dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $current_translations['dashboard_title']; ?></title>
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

            .charts-section {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
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

        /* Dark mode support */
        .dark-mode {
            --card-bg: rgba(45, 55, 72, 0.95);
            --text-primary: #f7fafc;
            --text-secondary: #cbd5e0;
        }

        /* Add this at the beginning of your CSS */
    body[dir="ltr"] .sidebar {
        left: 0;
        right: auto;
        border-right: 4px solid #198754;
        border-left: none;
    }
    
    body[dir="ltr"] .main-content {
        margin-left: 250px;
        margin-right: 0;
    }
    
    body[dir="rtl"] .sidebar {
        right: 0;
        left: auto;
        border-left: 4px solid #198754;
        border-right: none;
    }
    
    body[dir="rtl"] .main-content {
        margin-right: 250px;
        margin-left: 0;
    }
    </style>
</head>
<body dir="<?php echo $dir; ?>">
    <script>setupLanguageSwitcher();</script>
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
                    <a href="#" class="active">
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
                    <h1><?php echo $current_translations['welcome']; ?></h1>
                    <p><?php echo $current_translations['subtitle']; ?></p>
                </div>
                <div class="header-right">
                    <div class="language-switcher">
    <button class="lang-btn <?php echo ($langue_preferee === 'ar') ? 'active' : ''; ?>" data-lang="ar">العربية</button>
    <button class="lang-btn <?php echo ($langue_preferee === 'en') ? 'active' : ''; ?>" data-lang="en">English</button>
    <button class="lang-btn <?php echo ($langue_preferee === 'fr') ? 'active' : ''; ?>" data-lang="fr">Français</button>
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

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo number_format($totalAgencies); ?></div>
                            <div class="stat-label" data-ar="إجمالي الوكالات" data-en="Total Agencies" data-fr="Total des Agences">إجمالي الوكالات</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-building"></i>
                        </div>
                    </div>
                    <!-- <div class="stat-change">
                        <i class="fas fa-arrow-up"></i>
                        <span>+12% هذا الشهر</span>
                    </div> -->
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo number_format($totalPilgrims); ?></div>
                            <div class="stat-label" data-ar="إجمالي المعتمرين" data-en="Total Pilgrims" data-fr="Total des Pèlerins">إجمالي المعتمرين</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <!-- <div class="stat-change">
                        <i class="fas fa-arrow-up"></i>
                        <span>+25% هذا الشهر</span>
                    </div> -->
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo number_format($totalOffers); ?></div>
                            <div class="stat-label" data-ar="العروض النشطة" data-en="Active Offers" data-fr="Offres Actives">العروض النشطة</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-tags"></i>
                        </div>
                    </div>
                    <!-- <div class="stat-change">
                        <i class="fas fa-arrow-up"></i>
                        <span>+8% هذا الشهر</span>
                    </div> -->
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number" style="font-size: 1.5rem;"><?php echo number_format($pendingRequests); ?><div class="stat-change" style="color: #ffc107; margin-top: 1px; margin-bottom: 1px; font-size: 0.8rem;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>تحتاج مراجعة</span>
                    </div></div>
                            <div class="stat-label" data-ar="الطلبات المعلقة" data-en="Pending Requests" data-fr="Demandes en Attente">الطلبات المعلقة</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    
                </div>
            </div>

            <!-- Charts Section -->
            <?php
            // جلب بيانات الحجوزات الشهرية (آخر 12 شهر) فقط إذا كان لديه صلاحية الرؤية
            $monthlyBookings = [];
            $months = [];
            $agencyLabels = [];
            $agencyCounts = [];

            // مثال: تحقق من صلاحية المدير العام أو وجود صلاحية رؤية الإحصائيات
            $canViewStats = true; // اجعلها false إذا أردت تقييد الوصول بناءً على الصلاحيات

            if ($canViewStats) {
                try {
                    $stmt = $pdo->query("
                        SELECT 
                            TO_CHAR(date_trunc('month', date_demande), 'YYYY-MM') AS month,
                            COUNT(*) AS total
                        FROM demande_umrah
                        WHERE date_demande >= NOW() - INTERVAL '1 year'
                        GROUP BY date_trunc('month', date_demande), TO_CHAR(date_trunc('month', date_demande), 'YYYY-MM')
                        ORDER BY date_trunc('month', date_demande) ASC
                    ");
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($results as $row) {
                        $months[] = $row['month'];
                        $monthlyBookings[] = (int)$row['total'];
                    }
                } catch (Exception $e) {
                    error_log('Error querying monthly bookings: ' . $e->getMessage());
                    $months = [];
                    $monthlyBookings = [];
                }

                // جلب توزيع الوكالات حسب الولاية
                try {
                    $stmt = $pdo->query("
                        SELECT 
                            COALESCE(wilaya, 'غير محدد') as wilaya,
                            COUNT(*) AS total
                        FROM agences
                        GROUP BY wilaya
                        ORDER BY total DESC
                        LIMIT 6
                    ");
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($results as $row) {
                        $agencyLabels[] = $row['wilaya'];
                        $agencyCounts[] = (int)$row['total'];
                    }
                } catch (Exception $e) {
                    error_log('Error querying agency distribution: ' . $e->getMessage());
                    $agencyLabels = [];
                    $agencyCounts = [];
                }
            }
            ?>

            <div class="charts-section">
            <?php if ($canViewStats): ?>
                <!-- Monthly Booking Statistics Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title" data-ar="إحصائيات الحجوزات الشهرية" data-en="Monthly Booking Statistics" data-fr="Statistiques de Réservation Mensuelle">إحصائيات الحجوزات الشهرية</h3>
                    </div>
                    <canvas id="monthlyBookingsChart" height="200"></canvas>
                </div>

                <!-- Agency Distribution Pie Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title" data-ar="توزيع الوكالات" data-en="Agency Distribution" data-fr="Distribution des Agences">توزيع الوكالات</h3>
                    </div>
                    <canvas id="agencyDistributionChart" height="200"></canvas>
                </div>
            <?php else: ?>
                <div style="padding:2rem; background:#fff3cd; border-radius:20px; color:#856404; text-align:center;">
                    <i class="fas fa-exclamation-triangle" style="font-size:2rem;"></i><br>
                    <span data-ar="ليس لديك صلاحية لرؤية الإحصائيات" data-en="You do not have permission to view statistics" data-fr="Vous n'avez pas la permission de voir les statistiques">ليس لديك صلاحية لرؤية الإحصائيات</span>
                </div>
            <?php endif; ?>
            </div>

            <?php if ($canViewStats): ?>
            <!-- Chart.js -->
            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
            <script>
                // Monthly Bookings Chart
                const monthlyBookingsElem = document.getElementById('monthlyBookingsChart');
                if (monthlyBookingsElem) {
                    const monthlyBookingsCtx = monthlyBookingsElem.getContext('2d');
                    const monthlyBookingsChart = new Chart(monthlyBookingsCtx, {
                        type: 'line',
                        data: {
                            labels: <?php echo json_encode($months, JSON_UNESCAPED_UNICODE); ?>,
                            datasets: [{
                                label: 'الحجوزات',
                                data: <?php echo json_encode($monthlyBookings); ?>,
                                backgroundColor: 'rgba(25, 135, 84, 0.2)',
                                borderColor: '#198754',
                                borderWidth: 3,
                                pointBackgroundColor: '#ffd700',
                                pointBorderColor: '#14532d',
                                tension: 0.4,
                                fill: true
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return 'الحجوزات: ' + context.parsed.y;
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    title: { display: true, text: 'الشهر' }
                                },
                                y: {
                                    beginAtZero: true,
                                    title: { display: true, text: 'عدد الحجوزات' }
                                }
                            }
                        }
                    });
                }

                // Agency Distribution Pie Chart
                const agencyDistributionElem = document.getElementById('agencyDistributionChart');
                if (agencyDistributionElem) {
                    const agencyDistributionCtx = agencyDistributionElem.getContext('2d');
                    const agencyDistributionChart = new Chart(agencyDistributionCtx, {
                        type: 'pie',
                        data: {
                            labels: <?php echo json_encode($agencyLabels, JSON_UNESCAPED_UNICODE); ?>,
                            datasets: [{
                                data: <?php echo json_encode($agencyCounts); ?>,
                                backgroundColor: [
                                    '#198754', '#ffd700', '#43e97b', '#fa709a', '#fee140', '#14532d'
                                ],
                                borderColor: '#fff',
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: { position: 'bottom' },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return context.label + ': ' + context.parsed + ' وكالة';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            </script>
            <?php endif; ?>
            <!-- Recent Activity -->
              
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
        // اللغة الحالية من قاعدة البيانات
        let currentLanguage = '<?php echo $langue_preferee; ?>';

        // تفعيل زر اللغة النشط وتغيير النصوص والاتجاه
        function applyLanguage(lang) {
            document.documentElement.lang = lang;
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.querySelectorAll('.lang-btn').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-lang') === lang);
            });
            document.querySelectorAll('[data-ar],[data-en],[data-fr]').forEach(el => {
                const txt = el.getAttribute('data-' + lang);
                if (txt) el.textContent = txt;
            });
        }

        // عند الضغط على زر اللغة
        document.querySelectorAll('.lang-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const lang = this.getAttribute('data-lang');
                if (lang === currentLanguage) return;
                // حفظ اللغة في قاعدة البيانات
                fetch('update_language.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'language=' + lang
                })
                .then(() => {
                    // إعادة تحميل الصفحة لتطبيق اللغة المختارة من قاعدة البيانات
                    window.location.reload();
                });
            });
        });

        // تطبيق اللغة مباشرة عند التحميل
        applyLanguage(currentLanguage);
    });
    </script>
    <!-- ...existing code... -->
</body>
</html>