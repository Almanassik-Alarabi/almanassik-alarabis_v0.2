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
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة تحكم منصة العمرة | Umrah Platform Dashboard</title>
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
                    <a href="#" class="active">
                        <i class="fas fa-tachometer-alt"></i>
                        <span class="sidebar-text" data-ar="لوحة التحكم" data-en="Dashboard" data-fr="Tableau de bord">لوحة التحكم</span>
                    </a>
                </li>
                <li>
                    <a href="#">
                        <i class="fas fa-building"></i>
                        <span class="sidebar-text" data-ar="إدارة الوكالات" data-en="Manage Agencies" data-fr="Gérer les Agences">إدارة الوكالات</span>
                    </a>
                </li>
                <li>
                    <a href="#">
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
                    <h1 data-ar="مرحباً بك في لوحة التحكم" data-en="Welcome to Dashboard" data-fr="Bienvenue au Tableau de bord">مرحباً بك في لوحة التحكم</h1>
                    <p data-ar="إدارة شاملة لمنصة العمرة" data-en="Comprehensive Umrah Platform Management" data-fr="Gestion complète de la plateforme Omra">إدارة شاملة لمنصة العمرة</p>
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
                    <div class="stat-change">
                        <i class="fas fa-arrow-up"></i>
                        <span>+12% هذا الشهر</span>
                    </div>
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
                    <div class="stat-change">
                        <i class="fas fa-arrow-up"></i>
                        <span>+25% هذا الشهر</span>
                    </div>
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
                    <div class="stat-change">
                        <i class="fas fa-arrow-up"></i>
                        <span>+8% هذا الشهر</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo number_format($pendingRequests); ?></div>
                            <div class="stat-label" data-ar="الطلبات المعلقة" data-en="Pending Requests" data-fr="Demandes en Attente">الطلبات المعلقة</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-change" style="color: #ffc107;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>تحتاج مراجعة</span>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-section">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title" data-ar="إحصائيات الحجوزات الشهرية" data-en="Monthly Booking Statistics" data-fr="Statistiques de Réservation Mensuelle">إحصائيات الحجوزات الشهرية</h3>
                    </div>
                    <div class="chart-placeholder">
                        <i class="fas fa-chart-line" style="font-size: 3rem; margin-left: 1rem;"></i>
                        <span data-ar="رسم بياني للحجوزات" data-en="Booking Chart" data-fr="Graphique des Réservations">رسم بياني للحجوزات</span>
                    </div>
                </div>

                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title" data-ar="توزيع الوكالات" data-en="Agency Distribution" data-fr="Distribution des Agences">توزيع الوكالات</h3>
                    </div>
                    <div class="chart-placeholder">
                        <i class="fas fa-chart-pie" style="font-size: 3rem; margin-left: 1rem;"></i>
                        <span data-ar="رسم دائري" data-en="Pie Chart" data-fr="Graphique Circulaire">رسم دائري</span>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="recent-activity">
                <div class="chart-header">
                    <h3 class="chart-title" data-ar="النشاطات الأخيرة" data-en="Recent Activities" data-fr="Activités Récentes">النشاطات الأخيرة</h3>
                </div>
                
                <?php foreach ($recentActivities as $activity): ?>
                <div class="activity-item">
                    <div class="activity-icon" style="background: <?php 
                        echo match($activity['type']) {
                            'new_agency' => 'var(--success)',
                            'new_request' => 'var(--primary)',
                            'new_offer' => 'var(--warning)',
                            default => 'var(--secondary)'
                        };
                    ?>;">
                        <i class="fas <?php 
                            echo match($activity['type']) {
                                'new_agency' => 'fa-building',
                                'new_request' => 'fa-user-plus',
                                'new_offer' => 'fa-tags',
                                default => 'fa-bell'
                            };
                        ?>"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-title"><?php echo htmlspecialchars($activity['title']); ?></div>
                        <div class="activity-time"><?php 
                            $date = new DateTime($activity['date']);
                            $now = new DateTime();
                            $diff = $date->diff($now);
                            
                            if ($diff->h == 0) {
                                echo "منذ " . $diff->i . " دقيقة";
                            } elseif ($diff->h < 24) {
                                echo "منذ " . $diff->h . " ساعة";
                            } else {
                                echo "منذ " . $diff->days . " يوم";
                            }
                        ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Secondary Admins Section -->
            <div class="secondary-admins-section" style="margin-top: 2rem;">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title" data-ar="إدارة المدراء الثانويين" data-en="Secondary Admins Management" data-fr="Gestion des Admins Secondaires">إدارة المدراء الثانويين</h3>
                        <button class="btn-add-admin" style="background: var(--primary); color: white; border: none; padding: 0.5rem 1rem; border-radius: 25px; cursor: pointer;">
                            <i class="fas fa-plus"></i>
                            <span data-ar="إضافة مدير جديد" data-en="Add New Admin" data-fr="Ajouter un Nouvel Admin">إضافة مدير جديد</span>
                        </button>
                    </div>
                    
                    <div class="admins-table" style="margin-top: 1rem;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: rgba(0,0,0,0.05);">
                                    <th style="padding: 1rem; text-align: right;">الاسم</th>
                                    <th style="padding: 1rem; text-align: right;">البريد الإلكتروني</th>
                                    <th style="padding: 1rem; text-align: right;">الصلاحيات</th>
                                    <th style="padding: 1rem; text-align: right;">الحالة</th>
                                    <th style="padding: 1rem; text-align: right;">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($secondaryAdmins as $admin): ?>
                                <tr>
                                    <td style="padding: 1rem; border-bottom: 1px solid rgba(0,0,0,0.1);"><?php echo htmlspecialchars($admin['nom']); ?></td>
                                    <td style="padding: 1rem; border-bottom: 1px solid rgba(0,0,0,0.1);"><?php echo htmlspecialchars($admin['email']); ?></td>
                                    <td style="padding: 1rem; border-bottom: 1px solid rgba(0,0,0,0.1);">
                                        <?php 
                                        $permissions = json_decode($admin['permissions'], true);
                                        foreach ($permissions as $perm):
                                            if ($perm['permission_key']):
                                        ?>
                                            <span class="permission-badge" style="background: var(--primary); color: white; padding: 0.25rem 0.5rem; border-radius: 15px; font-size: 0.9rem; margin-left: 0.5rem;">
                                                <?php echo htmlspecialchars($perm['permission_key']); ?>
                                            </span>
                                        <?php 
                                            endif;
                                        endforeach; 
                                        ?>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid rgba(0,0,0,0.1);">
                                        <span style="color: #28a745;">نشط</span>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid rgba(0,0,0,0.1);">
                                        <button class="btn-edit" style="background: var(--primary); color: white; border: none; padding: 0.25rem 0.5rem; border-radius: 15px; cursor: pointer; margin-left: 0.5rem;">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-delete" style="background: var(--danger); color: white; border: none; padding: 0.25rem 0.5rem; border-radius: 15px; cursor: pointer;">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Permissions Modal Template -->
            <div id="permissionsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
                <div style="background: white; width: 90%; max-width: 600px; margin: 2rem auto; padding: 2rem; border-radius: var(--border-radius);">
                    <h3 style="margin-bottom: 1.5rem;">إدارة الصلاحيات</h3>
                    <div class="permissions-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
                        <div class="permission-item">
                            <label style="display: flex; align-items: center; gap: 0.5rem;">
                                <input type="checkbox" name="permission" value="manage_pilgrims">
                                <span>إدارة المعتمرين</span>
                            </label>
                        </div>
                        <div class="permission-item">
                            <label style="display: flex; align-items: center; gap: 0.5rem;">
                                <input type="checkbox" name="permission" value="manage_agencies">
                                <span>إدارة الوكالات</span>
                            </label>
                        </div>
                        <div class="permission-item">
                            <label style="display: flex; align-items: center; gap: 0.5rem;">
                                <input type="checkbox" name="permission" value="manage_offers">
                                <span>إدارة العروض</span>
                            </label>
                        </div>
                        <div class="permission-item">
                            <label style="display: flex; align-items: center; gap: 0.5rem;">
                                <input type="checkbox" name="permission" value="manage_requests">
                                <span>إدارة الطلبات</span>
                            </label>
                        </div>
                    </div>
                    <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                        <button class="btn-cancel" style="background: var(--secondary); color: white; border: none; padding: 0.5rem 1rem; border-radius: 25px; cursor: pointer;">إلغاء</button>
                        <button class="btn-save" style="background: var(--primary); color: white; border: none; padding: 0.5rem 1rem; border-radius: 25px; cursor: pointer;">حفظ</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Language data
        const translations = {
            ar: {
                direction: 'rtl',
                welcome: 'مرحباً بك في لوحة التحكم',
                subtitle: 'إدارة شاملة لمنصة العمرة',
                dashboard: 'لوحة التحكم',
                agencies: 'إدارة الوكالات',
                pilgrims: 'إدارة المعتمرين',
                offers: 'إدارة العروض',
                requests: 'إدارة الطلبات',
                admins: 'إدارة المدراء',
                chat: 'الدردشة',
                reports: 'التقارير',
                settings: 'الإعدادات',
                secondary_admins: 'إدارة المدراء الثانويين',
                add_new_admin: 'إضافة مدير جديد',
                manage_permissions: 'إدارة الصلاحيات',
                cancel: 'إلغاء',
                save: 'حفظ',
                delete_confirm: 'هل أنت متأكد من حذف هذا المدير؟',
                success_save: 'تم حفظ الصلاحيات بنجاح'
            },
            en: {
                direction: 'ltr',
                welcome: 'Welcome to Dashboard',
                subtitle: 'Comprehensive Umrah Platform Management',
                dashboard: 'Dashboard',
                agencies: 'Manage Agencies',
                pilgrims: 'Manage Pilgrims',
                offers: 'Manage Offers',
                requests: 'Manage Requests',
                admins: 'Manage Admins',
                chat: 'Chat',
                reports: 'Reports',
                settings: 'Settings',
                secondary_admins: 'Secondary Admins Management',
                add_new_admin: 'Add New Admin',
                manage_permissions: 'Manage Permissions',
                cancel: 'Cancel',
                save: 'Save',
                delete_confirm: 'Are you sure you want to delete this admin?',
                success_save: 'Permissions saved successfully'
            },
            fr: {
                direction: 'ltr',
                welcome: 'Bienvenue au Tableau de bord',
                subtitle: 'Gestion complète de la plateforme Omra',
                dashboard: 'Tableau de bord',
                agencies: 'Gérer les Agences',
                pilgrims: 'Gérer les Pèlerins',
                offers: 'Gérer les Offres',
                requests: 'Gérer les Demandes',
                admins: 'Gérer les Admins',
                chat: 'Chat',
                reports: 'Rapports',
                settings: 'Paramètres',
                secondary_admins: 'Gestion des Admins Secondaires',
                add_new_admin: 'Ajouter un Nouvel Admin',
                manage_permissions: 'Gérer les Permissions',
                cancel: 'Annuler',
                save: 'Enregistrer',
                delete_confirm: 'Êtes-vous sûr de vouloir supprimer cet admin ?',
                success_save: 'Permissions enregistrées avec succès'
            }
        };

        let currentLanguage = 'ar';
        let sidebarCollapsed = false;

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            setupLanguageSwitcher();
            setupSidebarToggle();
            animateCards();
        });

        // Language switcher
        function setupLanguageSwitcher() {
            const langButtons = document.querySelectorAll('.lang-btn');
            
            langButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const lang = this.getAttribute('data-lang');
                    switchLanguage(lang);
                    
                    // Update active button
                    langButtons.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                });
            });
        }

        function switchLanguage(lang) {
            currentLanguage = lang;
            const html = document.documentElement;
            
            // Change direction
            html.setAttribute('dir', translations[lang].direction);
            html.setAttribute('lang', lang);
            
            // Update all translatable elements
            const elements = document.querySelectorAll('[data-ar], [data-en], [data-fr]');
            elements.forEach(element => {
                const text = element.getAttribute(`data-${lang}`);
                if (text) {
                    element.textContent = text;
                }
            });

            // Update CSS for RTL/LTR
            updateLayoutDirection(lang);
        }

        function updateLayoutDirection(lang) {
            const isRTL = lang === 'ar';
                       const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            
            if (isRTL) {
                // RTL layout adjustments
                sidebar.style.right = '0';
                sidebar.style.left = 'auto';
                mainContent.style.marginRight = sidebarCollapsed ? '80px' : '280px';
                mainContent.style.marginLeft = '0';
                
                // Adjust sidebar menu items
                document.querySelectorAll('.sidebar-menu a').forEach(a => {
                    a.style.borderRadius = '0 25px 25px 0';
                    a.style.marginRight = '1rem';
                    a.style.marginLeft = '0';
                });
            } else {
                // LTR layout adjustments
                sidebar.style.left = '0';
                sidebar.style.right = 'auto';
                mainContent.style.marginLeft = sidebarCollapsed ? '80px' : '280px';
                mainContent.style.marginRight = '0';
                
                // Adjust sidebar menu items
                document.querySelectorAll('.sidebar-menu a').forEach(a => {
                    a.style.borderRadius = '25px 0 0 25px';
                    a.style.marginLeft = '1rem';
                    a.style.marginRight = '0';
                });
            }
        }

        // Sidebar toggle functionality
        function setupSidebarToggle() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const toggleBtn = document.querySelector('.sidebar-toggle');
            
            // Toggle sidebar
            window.toggleSidebar = function() {
                sidebarCollapsed = !sidebarCollapsed;
                
                if (sidebarCollapsed) {
                    sidebar.classList.add('collapsed');
                    mainContent.classList.add('expanded');
                    
                    // Hide text spans in sidebar
                    document.querySelectorAll('.sidebar-text').forEach(el => {
                        el.style.display = 'none';
                    });
                    
                    // Update toggle button icon
                    toggleBtn.innerHTML = '<i class="fas fa-indent"></i>';
                } else {
                    sidebar.classList.remove('collapsed');
                    mainContent.classList.remove('expanded');
                    
                    // Show text spans in sidebar
                    document.querySelectorAll('.sidebar-text').forEach(el => {
                        el.style.display = 'inline';
                    });
                    
                    // Update toggle button icon
                    toggleBtn.innerHTML = '<i class="fas fa-outdent"></i>';
                }
                
                // Adjust main content margin based on language
                if (currentLanguage === 'ar') {
                    mainContent.style.marginRight = sidebarCollapsed ? '80px' : '280px';
                } else {
                    mainContent.style.marginLeft = sidebarCollapsed ? '80px' : '280px';
                }
            };
            
            // Responsive behavior for mobile
            window.addEventListener('resize', function() {
                if (window.innerWidth <= 768) {
                    sidebar.classList.add('collapsed');
                    mainContent.classList.add('expanded');
                    document.querySelectorAll('.sidebar-text').forEach(el => {
                        el.style.display = 'none';
                    });
                }
            });
        }

        // Animate cards on load
        function animateCards() {
            const cards = document.querySelectorAll('.stat-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        }

        // Mobile menu toggle
        document.querySelector('.sidebar-toggle').addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.toggle('open');
            }
        });

        // Secondary Admins Management
        const permissionsModal = document.getElementById('permissionsModal');
        const btnAddAdmin = document.querySelector('.btn-add-admin');
        const btnCancel = document.querySelector('.btn-cancel');
        const btnSave = document.querySelector('.btn-save');
        const editButtons = document.querySelectorAll('.btn-edit');
        const deleteButtons = document.querySelectorAll('.btn-delete');

        // Show modal for adding new admin
        btnAddAdmin.addEventListener('click', function() {
            permissionsModal.style.display = 'block';
            // Reset form
            document.querySelectorAll('input[name="permission"]').forEach(checkbox => {
                checkbox.checked = false;
            });
        });

        // Hide modal
        btnCancel.addEventListener('click', function() {
            permissionsModal.style.display = 'none';
        });

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === permissionsModal) {
                permissionsModal.style.display = 'none';
            }
        });

        // Save permissions
        btnSave.addEventListener('click', function() {
            const selectedPermissions = Array.from(document.querySelectorAll('input[name="permission"]:checked'))
                .map(checkbox => checkbox.value);
            
            // Here you would typically send this data to your backend
            console.log('Selected permissions:', selectedPermissions);
            
            // Close modal
            permissionsModal.style.display = 'none';
            
            // Show success message
            alert('تم حفظ الصلاحيات بنجاح');
        });

        // Edit admin permissions
        editButtons.forEach(button => {
            button.addEventListener('click', function() {
                const row = this.closest('tr');
                const adminName = row.querySelector('td:first-child').textContent;
                
                // Show modal
                permissionsModal.style.display = 'block';
                
                // Here you would typically load the admin's current permissions
                // For now, we'll just show the modal
                console.log('Editing permissions for:', adminName);
            });
        });

        // Delete admin
        deleteButtons.forEach(button => {
            button.addEventListener('click', function() {
                if (confirm('هل أنت متأكد من حذف هذا المدير؟')) {
                    const row = this.closest('tr');
                    // Here you would typically send a delete request to your backend
                    console.log('Deleting admin:', row.querySelector('td:first-child').textContent);
                    
                    // Remove row from table
                    row.remove();
                }
            });
        });
    </script>
</body>
</html>