<?php
require_once '../includes/config.php';
require_once '../includes/admin_functions.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session and check permissions
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php'); 
    exit;
}

$admin = executeQuery("SELECT est_super_admin FROM admins WHERE id = ?", [$_SESSION['admin_id']])->fetch();
if (!$admin || !$admin['est_super_admin']) {
    header('Location: dashboard.php');
    exit;
}

updateUserActivity($_SESSION['admin_id']);

// Handle POST requests
$error = $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    switch ($action) {
        case 'add':
            if (!isset($_POST['nom'], $_POST['email'], $_POST['password'], $_POST['confirm_password'])) {
                $error = 'جميع الحقول مطلوبة';
                break;
            }

            $nom = trim($_POST['nom']);
            $email = trim($_POST['email']);
            $password = $_POST['password'];
            $confirmPassword = $_POST['confirm_password'];
            $permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];

            // Validate inputs
            if (empty($nom) || empty($email) || empty($password)) {
                $error = 'جميع الحقول مطلوبة';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'البريد الإلكتروني غير صالح';
            } elseif (strlen($password) < 8) {
                $error = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل';
            } elseif ($password !== $confirmPassword) {
                $error = 'كلمة المرور غير متطابقة';
            } elseif (empty($permissions)) {
                $error = 'يجب اختيار صلاحية واحدة على الأقل';
            } else {
                // Attempt to add admin
                $result = addAdmin([
                    'full_name' => $nom,
                    'email' => $email,
                    'password' => $password,
                    'permissions' => $permissions
                ]);

                if ($result) {
                    $success = 'تم إضافة المدير بنجاح';
                } else {
                    $error = 'حدث خطأ أثناء إضافة المدير. قد يكون البريد الإلكتروني مستخدم بالفعل.';
                }
            }
            break;

        case 'edit':
            if (!isset($_POST['id'])) {
                $error = 'معرف المدير غير صالح';
                break;
            }
            $id = $_POST['id'];
            $nom = isset($_POST['nom']) ? trim($_POST['nom']) : '';
            $email = isset($_POST['email']) ? trim($_POST['email']) : '';
            $password = isset($_POST['password']) ? $_POST['password'] : '';
            $permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];

            if (empty($nom) || empty($email)) {
                $error = 'الاسم والبريد الإلكتروني مطلوبان';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'البريد الإلكتروني غير صالح';
            } elseif (!empty($password) && strlen($password) < 8) {
                $error = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل';
            } elseif (empty($permissions)) {
                $error = 'يجب اختيار صلاحية واحدة على الأقل';
            } else {
                $updateData = [
                    'full_name' => $nom,
                    'email' => $email,
                    'permissions' => $permissions
                ];
                if (!empty($password)) {
                    $updateData['password'] = $password;
                }
                if (updateAdmin($id, $updateData)) {
                    $success = 'تم تحديث بيانات المدير بنجاح';
                } else {
                    $error = 'حدث خطأ أثناء تحديث بيانات المدير';
                }
            }
            break;

        case 'delete':
            if (!isset($_POST['id'])) {
                $error = 'معرف المدير غير صالح';
                break;
            }
            $id = $_POST['id'];
            if ($id == $_SESSION['admin_id']) {
                $error = 'لا يمكنك حذف حسابك الخاص';
            } else {
                if (deleteAdmin($id)) {
                    $success = 'تم حذف المدير بنجاح';
                } else {
                    $error = 'حدث خطأ أثناء حذف المدير';
                }
            }
            break;
    }
    
    // Return response for AJAX requests
    if (!empty($error)) {
        echo '<div class="error-message">' . htmlspecialchars($error) . '</div>';
        exit;
    } elseif (!empty($success)) {
        echo '<div class="success-message">' . htmlspecialchars($success) . '</div>';
        exit;
    }
}

// Fetch admins and sub-admins with permissions from the new schema

$admins = [];

// Fetch super admins
$stmt = $pdo->prepare("SELECT id, nom AS full_name, email, est_super_admin AS is_super_admin, last_activity FROM admins ORDER BY id DESC");
$stmt->execute();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($row['is_super_admin']) {
        $row['permissions'] = []; // Super admin: all permissions
        $admins[] = $row;
    }
}

// Fetch sub-admins and their permissions
$stmt = $pdo->prepare("SELECT sa.id, sa.nom AS full_name, sa.email, sa.last_activity, sa.created_at, sa.cree_par_admin_id, 
    FALSE AS is_super_admin
    FROM sub_admins sa
    ORDER BY sa.id DESC");
$stmt->execute();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Fetch permissions for this sub-admin
    $permStmt = $pdo->prepare("SELECT permission_key, allow_view, allow_add, allow_edit, allow_delete FROM sub_admin_permissions WHERE sub_admin_id = ?");
    $permStmt->execute([$row['id']]);
    $permissions = [];
    while ($perm = $permStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = $perm['permission_key'];
        $permissions[$key] = [
            'view' => (bool)$perm['allow_view'],
            'add' => (bool)$perm['allow_add'],
            'edit' => (bool)$perm['allow_edit'],
            'delete' => (bool)$perm['allow_delete'],
        ];
    }
    $row['permissions'] = $permissions;
    $admins[] = $row;
}

if (empty($admins)) {
    $info = 'لا يوجد مدراء لعرضهم';
} 

$admin_profil = null;
if (isset($_SESSION['admin_id'])) {
    // جلب بيانات المدير الحالي مباشرة من قاعدة البيانات
    $stmt = $pdo->prepare("SELECT id, nom AS full_name, email FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    $admin_profil = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (isset($_SESSION['admin_id'])) updateUserActivity($_SESSION['admin_id']);
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, user-scalable=no">
    <title>إدارة المدراء</title>
      <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    :root {
        /* Primary Islamic Green Theme */
        --primary: linear-gradient(135deg, #00823b 0%, #1f4c2c 100%);
        --secondary: linear-gradient(135deg, #ffd700 0%, #daa520 100%);
        --success: linear-gradient(135deg, #00a86b 0%, #006400 100%);
        --warning: linear-gradient(135deg, #ffd700 0%, #ffb700 100%);
        --danger: linear-gradient(135deg, #8b0000 0%, #800000 100%);
        --dark: #1a472a;
        --light: #f8f9fa;

        /* Islamic Design Elements */
        --border-radius: 20px;
        --shadow: 0 10px 30px rgba(0,0,0,0.1);
        --shadow-hover: 0 20px 60px rgba(0,0,0,0.15);
        --card-bg: rgba(255, 255, 255, 0.98);

        /* Text Colors */
        --text-primary: #1a472a;
        --text-secondary: #6c757d;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
/* Improved notification container */
#notificationContainer {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1000000;
    pointer-events: none; /* Allow clicking through when no notifications */
}
/* Enhanced SweetAlert2 styling */
.swal2-container {
    padding: 0 !important;
}
.swal2-popup.swal2-toast {
    padding: 1em !important;
    margin: 0.5em;
    background: rgba(255, 255, 255, 0.98) !important;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1) !important;
}

    body {
        font-family: 'Cairo', 'Amiri', sans-serif;
        background: linear-gradient(135deg,rgb(4, 128, 70) 0%,rgb(180, 163, 5) 100%);
        min-height: 100vh;
        margin: 0;
        padding-right: 0 !important;
        overflow-y: scroll !important;
    }

    .dashboard-container {
        display: flex;
        min-height: 100vh;
        position: relative;
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
        margin-right: 280px;
        padding: 2rem;
        transition: all 0.3s ease;
        min-height: 100vh;
        width: 100%;
        transition: none; /* Remove transitions that cause layout shift */
    }

    .main-content.expanded {
        margin-right: 80px;
    }

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
    z-index: 100;
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

    /* Actions Bar */
    .actions-bar {
        background: var(--card-bg);
        backdrop-filter: blur(20px);
        border-radius: var(--border-radius);
        padding: 1.5rem 2rem;
        margin-bottom: 2rem;
        box-shadow: var(--shadow);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .search-box {
        display: flex;
        align-items: center;
        background: rgba(231, 231, 231, 0.8);
        border-radius: 25px;
        padding: 0.75rem 1.5rem;
        flex: 1;
        max-width: 400px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
    }

    .search-box input {
        border: none;
        outline: none;
        background: transparent;
        width: 100%;
        font-family: inherit;
        font-size: 0.95rem;
        color: var(--text-primary);
    }

    .search-box i {
        color: var(--text-secondary);
        margin-left: 1rem;
    }

    .action-buttons {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .btn {
        padding: 0.75rem 1.5rem;
        border: none;
        border-radius: 25px;
        cursor: pointer;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        transition: all 0.3s ease;
        text-decoration: none;
        font-size: 0.9rem;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.25);
    }

    .btn-success {
        background: var(--success);
        color: white;
        box-shadow: 0 4px 15px rgba(79, 172, 254, 0.25);
    }

    .btn-warning {
        background: var(--warning);
        color: white;
        box-shadow: 0 4px 15px rgba(67, 233, 123, 0.25);
    }

    .btn-danger {
        background: var(--danger);
        color: white;
        box-shadow: 0 4px 15px rgba(250, 112, 154, 0.25);
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-hover);
        filter: brightness(1.1);
    }

    /* Admin Cards Grid */
    .admins-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1.5rem;
        margin: 1.5rem 0;
        contain: layout; /* Improve performance */
    }

    .admin-card {
    background: var(--card-bg);
    backdrop-filter: blur(20px);
    border-radius: var(--border-radius);
    padding: 2rem;
    box-shadow: var(--shadow);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    opacity: 0;
    transform: translateY(30px);
    animation: fadeIn 0.6s ease forwards;
    border: 1px solid rgba(0, 130, 59, 0.1);
    background-image: 
        linear-gradient(45deg, rgba(0, 130, 59, 0.03) 25%, transparent 25%),
        linear-gradient(-45deg, rgba(0, 130, 59, 0.03) 25%, transparent 25%),
        linear-gradient(45deg, transparent 75%, rgba(0, 130, 59, 0.03) 75%),
        linear-gradient(-45deg, transparent 75%, rgba(0, 130, 59, 0.03) 75%);
    background-size: 20px 20px;
    background-position: 0 0, 0 10px, 10px -10px, -10px 0px;
    }

    .admin-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: var(--primary);
    }
    .admin-card:hover::before {
        background: var(--secondary);
    }
    
    .admin-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
    }

    .admin-header {
        display: block;
        align-items: center;
        margin-bottom: 1.5rem;
        gap: 1rem;
    }

    .admin-avatar1 {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    .admin-avatar {
        min-width: 60px;
        min-height: 60px;
        border-radius: 50%;
        background: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.5rem;
        margin-left: 1rem;
    }

    .admin-info h3 {
        color: var(--text-primary);
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .admin-info p {
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    .admin-details {
        margin-bottom: 1.5rem;
    }

    .detail-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }

    .detail-item:last-child {
        border-bottom: none;
    }

    .detail-label {
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    .detail-value {
        color: var(--text-primary);
        font-weight: 500;
    }

    .permissions-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.5rem;
    }

    .permission-tag {
        padding: 0.25rem 0.75rem;
        border-radius: 15px;
        font-size: 0.8rem;
        font-weight: 500;
    }

    .permission-tag.agencies {
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
    }

    .permission-tag.pilgrims {
        background: rgba(240, 147, 251, 0.1);
        color: #f093fb;
    }

    .permission-tag.both {
        background: rgba(67, 233, 123, 0.1);
        color: #43e97b;
    }

    .status {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-top: 8px;
        padding: 8px 12px;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(5px);
    }

    .status-indicator {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 15px;
        font-size: 0.85rem;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .status-indicator.active {
        background: rgba(40, 167, 69, 0.1);
        background: rgba(220, 53, 69, 0.1);
        color: #dc3545;
        color: #28a745;
}
.status-indicator i {
    font-size: 0.8rem;
}
.status-indicator.inactive {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}
.status small {
    color: #6c757d;
}
.last-activity {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #6c757d;
    font-size: 0.8rem;
    margin-right: 8px;
    margin-top: 5px;
    padding-right: 12px;
}
.last-activity i {
    font-size: 0.9rem;
}
.admin-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
}
.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.8rem;
    border-radius: 20px;
}
/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(5px);
    z-index: 999999;
    padding: 1rem;
    overflow-y: auto;
    overscroll-behavior: contain; /* Prevent body scroll */
}
.modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
    animation: none; /* Remove animation to prevent layout shift */
}
.modal-content {
    margin: auto;
    width: 100%;
    max-width: 600px;
    background: rgba(255, 255, 255, 0.98);
    border-radius: 20px;
    padding: 2rem;
    position: relative;
    opacity: 1;
    transform: none;
}
/* Fix layout shift issues */

/* Improve form controls */
.form-control,
.btn {
    position: relative;
    z-index: 1;
    transform: translateZ(0); /* Force GPU acceleration */
}
/* Prevent checkbox jumps */
.checkbox-item {
    display: grid;
    grid-template-columns: auto 1fr;
    align-items: center;
    gap: 0.5rem;
    margin: 0;
}
/* Stabilize permission grid */
.permissions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin: 1rem 0;
}
/* Animation for admin cards */
@keyframes fadeIn {
    to {
        opacity: 1;
        transform: none;
    }
}


/* Edit Admin Modal Styles */
#editAdminModal.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(5px);
    z-index: 999999;
    padding: 1rem;
    overflow-y: auto;
    overscroll-behavior: contain;
}
#editAdminModal.modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
    animation: none;
}
#editAdminModal .modal-content {
    margin: auto;
    width: 100%;
    max-width: 600px;
    background: rgba(255, 255, 255, 0.98);
    border-radius: 20px;
    padding: 2rem;
    position: relative;
    opacity: 1;
    transform: none;
}
#editAdminModal .modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
}
#editAdminModal .modal-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
}
#editAdminModal .close-modal {
    background: none;
    border: none;
    font-size: 1.3rem;
    color: #888;
    cursor: pointer;
    transition: color 0.2s;
}
#editAdminModal .close-modal:hover {
    color: #dc3545;
}
#editAdminModal .form-group {
    margin-bottom: 1.2rem;
}
#editAdminModal .form-label {
    display: block;
    margin-bottom: 0.5rem;
    color: var(--text-primary);
    font-weight: 600;
}
#editAdminModal .form-control {
    width: 100%;
    padding: 0.7rem 1rem;
    border-radius: 15px;
    border: 1px solid #e0e0e0;
    font-size: 1rem;
    background: #fafbfc;
    color: var(--text-primary);
    transition: border-color 0.2s;
}
#editAdminModal .form-control:focus {
    border-color: #00823b;
    outline: none;
}
#editAdminModal .permissions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin: 1rem 0;
}
#editAdminModal .permission-section h4 {
    font-size: 1.05rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: var(--text-primary);
}
#editAdminModal .permission-actions {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}
#editAdminModal .checkbox-item {
    display: grid;
    grid-template-columns: auto 1fr;
    align-items: center;
    gap: 0.5rem;
    margin: 0;
}
#editAdminModal .btn.btn-primary {
    width: 100%;
    margin-top: 1rem;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: 25px;
    padding: 0.75rem 1.5rem;
    font-weight: 600;
    font-size: 1rem;
    transition: background 0.2s, box-shadow 0.2s;
}
#editAdminModal .btn.btn-primary:hover {
    filter: brightness(1.1);
    box-shadow: var(--shadow-hover);
}
#editAdminModal .invalid-feedback {
    color: #dc3545;
    font-size: 0.9rem;
    margin-top: 0.2rem;
    display: block;
}
#editAdminModal .alert-danger {
    color: #dc3545;
    background: #f8d7da;
    border-radius: 10px;
    padding: 0.7rem 1rem;
    margin-bottom: 1rem;
    font-size: 0.95rem;
}


/* Add Admin Modal Styles */
#addAdminModal.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(5px);
    z-index: 999999;
    padding: 1rem;
    overflow-y: auto;
    overscroll-behavior: contain; /* Prevent body scroll */
}
#addAdminModal.modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
    animation: none; /* Remove animation to prevent layout shift */
}
#addAdminModal .modal-content {
    margin: auto;
    width: 100%;
    max-width: 600px;
    background: rgba(255, 255, 255, 0.98);
    border-radius: 20px;
    padding: 2rem;
    position: relative;
    opacity: 1;
    transform: none;
}
#addAdminModal .modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
}
#addAdminModal .modal-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
}
#addAdminModal .close-modal {
    background: none;
    border: none;
    font-size: 1.3rem;
    color: #888;
    cursor: pointer;
    transition: color 0.2s;
}
#addAdminModal .close-modal:hover {
    color: #dc3545;
}
#addAdminModal .form-group {
    margin-bottom: 1.2rem;
}
#addAdminModal .form-label {
    display: block;
    margin-bottom: 0.5rem;
    color: var(--text-primary);
    font-weight: 600;
}
#addAdminModal .form-control {
    width: 100%;
    padding: 0.7rem 1rem;
    border-radius: 15px;
    border: 1px solid #e0e0e0;
    font-size: 1rem;
    background: #fafbfc;
    color: var(--text-primary);
    transition: border-color 0.2s;
}
#addAdminModal .form-control:focus {
    border-color: #00823b;
    outline: none;
}
#addAdminModal .permissions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin: 1rem 0;
}
#addAdminModal .permission-section h4 {
    font-size: 1.05rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: var(--text-primary);
}
#addAdminModal .permission-actions {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}
#addAdminModal .checkbox-item {
    display: grid;
    grid-template-columns: auto 1fr;
    align-items: center;
    gap: 0.5rem;
    margin: 0;
}
#addAdminModal .btn.btn-primary {
    width: 100%;
    margin-top: 1rem;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: 25px;
    padding: 0.75rem 1.5rem;
    font-weight: 600;
    font-size: 1rem;
    transition: background 0.2s, box-shadow 0.2s;
}
#addAdminModal .btn.btn-primary:hover {
    filter: brightness(1.1);
    box-shadow: var(--shadow-hover);
}
#addAdminModal .invalid-feedback {
    color: #dc3545;
    font-size: 0.9rem;
    margin-top: 0.2rem;
    display: block;
}
#addAdminModal .alert-danger {
    color: #dc3545;
    background: #f8d7da;
    border-radius: 10px;
    padding: 0.7rem 1rem;
    margin-bottom: 1rem;
    font-size: 0.95rem;
}
</style>
    </head>
<body>
<div id="notificationContainer"></div>
    <div class="container-fluid mt-4">
    <!-- Sidebar Toggle -->
    <!-- <button class="sidebar-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button> -->

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
                    <a href="#" class="active">
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
                    <h1 data-ar="مرحباً بك في لوحة التحكم" data-en="Welcome to Dashboard" data-fr="Bienvenue au Tableau de bord">مرحباً بك في لوحة التحكم</h1>
                    <p data-ar="إدارة شاملة لمنصة العمرة" data-en="Comprehensive Umrah Platform Management" data-fr="Gestion complète de la plateforme Omra">إدارة شاملة لمنصة العمرة</p>
                </div>
                <div class="header-right">
                    <div class="language-switcher">
                        <button id="lang-ar" class="lang-btn active" data-lang="ar">العربية</button>
                        <button id="lang-en" class="lang-btn" data-lang="en">English</button>
                        <button id="lang-fr" class="lang-btn" data-lang="fr">Français</button>
                    </div>
                    <div class="user-info">
                        <div class="user-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <div style="font-weight: 600;">
                                <?php echo isset($admin_profil['full_name']) && $admin_profil['full_name'] !== null ? htmlspecialchars($admin_profil['full_name']) : ''; ?>
                            </div>
                            <div style="font-size: 0.9rem; color: var(--text-secondary);">
                                <?php echo isset($admin_profil['email']) && $admin_profil['email'] !== null ? htmlspecialchars($admin_profil['email']) : ''; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions Bar -->
            <div class="actions-bar">
                <div class="search-box">
  <i class="fas fa-search"></i>
  <input type="text" id="adminSearchInput" 
         data-ar="البحث عن مدير..." 
         data-en="Search for admin..." 
         data-fr="Rechercher un admin..."  
     
         onkeyup="searchAdmins(this.value)">
</div>

                <script>
                function searchAdmins(query) {
                    query = query.trim();
                    const cards = document.querySelectorAll('.admins-grid .admin-card');
                    if (!cards.length) return;
                    if (!query) {
                        cards.forEach(card => card.style.display = '');
                        return;
                    }
                    const q = query.toLowerCase();
                    cards.forEach(card => {
                        const name = card.querySelector('h3')?.textContent?.toLowerCase() || '';
                        const email = card.querySelector('.email')?.textContent?.toLowerCase() || '';
                        if (name.includes(q) || email.includes(q)) {
                            card.style.display = '';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                }
                </script>
                <div class="action-buttons">
                    <button class="btn btn-primary" onclick="openAddAdminModal()">
                        <i class="fas fa-plus"></i>
                        <span data-ar="إضافة مدير جديد" 
                              data-en="Add New Admin" 
                              data-fr="Ajouter un Nouvel Admin">إضافة مدير جديد</span>
                    </button>
                    <form action="export_admins.php" method="post" style="display:inline;">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-file-export"></i>
                            <span data-ar="تصدير القائمة"
                                  data-en="Export List"
                                  data-fr="Exporter la Liste">تصدير القائمة</span>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Admins Grid -->
            <div class="admins-grid">
                <?php foreach ($admins as $admin): ?>
                <div class="admin-card" data-admin-id="<?php echo $admin['id']; ?>">
                    <div class="admin-header">
                        <div class="admin-avatar1">
                        <div class="admin-avatar">
                            <i class="fas <?php echo !empty($admin['is_super_admin']) ? 'fa-user-tie' : 'fa-user'; ?>"></i>
                        </div>
                        <h3><?php echo htmlspecialchars($admin['full_name']); ?></h3>
                        </div>
                        <div class="admin-info">
                            
                            <p class="email"><?php echo htmlspecialchars($admin['email']); ?></p>
                            <p class="account-type">
                                <?php echo '<span data-ar="مدير عام" data-en="General Admin" data-fr="Admin Général">' . 
                                (!empty($admin['is_super_admin']) ? 
                                'مدير عام' : 
                                '<span data-ar="مدير فرعي" data-en="Sub Admin" data-fr="Sous Admin">مدير فرعي</span>'
                                ) . '</span>'; ?>
                            </p>
                            <p class="status">
                                <?php
                                $activityStatus = getUserActivityStatus($admin['id'], !empty($admin['is_super_admin']) ? 'admin' : 'sub_admin');
                                $isActive = $activityStatus['is_active'];
                                $lastActivity = $activityStatus['last_activity'];
                                ?>
                                <span class="status-indicator <?php echo $isActive ? 'active' : 'inactive'; ?>">
                                    <i class="fas fa-circle"></i>
                                    <?php echo '<span data-ar="نشط الآن" data-en="Active Now" data-fr="Actif Maintenant">' . ($isActive ? 'نشط الآن' : '<span data-ar="غير نشط" data-en="Inactive" data-fr="Inactif">غير نشط</span>') . '</span>'; ?>
                                </span>
                                <?php if (!$isActive && $lastActivity): ?>
                                <div class="last-activity">
                                    <i class="far fa-clock"></i>
                                    <span>آخر نشاط: <?php echo date('Y-m-d H:i', strtotime($lastActivity)); ?></span>
                                </div>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <div class="admin-details">
                        <div class="detail-item">
                            <span class="detail-label" data-ar="الصلاحيات" data-en="Permissions" data-fr="Permissions">الصلاحيات</span>
                            <div class="permissions-tags">
                                <?php if (!empty($admin['is_super_admin'])): ?>
                                    <span class="permission-tag both" data-ar="جميع الصلاحيات" data-en="All Permissions" data-fr="Toutes les Permissions">جميع الصلاحيات</span>
                                <?php else: ?>
                                    <?php 
                                    $sections = [
                                        'agencies' => [
                                            'ar' => 'الوكالات',
                                            'en' => 'Agencies', 
                                            'fr' => 'Agences'
                                        ],
                                        'pilgrims' => [
                                            'ar' => 'المعتمرين',
                                            'en' => 'Pilgrims',
                                            'fr' => 'Pèlerins'  
                                        ],
                                        'offers' => [
                                            'ar' => 'العروض',
                                            'en' => 'Offers',
                                            'fr' => 'Offres'
                                        ],
                                        'chat' => [
                                            'ar' => 'المحادثات',
                                            'en' => 'Chat',
                                            'fr' => 'Chat'
                                        ],
                                        'reports' => [
                                            'ar' => 'التقارير',
                                            'en' => 'Reports',
                                            'fr' => 'Rapports'
                                        ]
                                    ];

                                    foreach ($sections as $key => $labels):
                                        $perms = isset($admin['permissions'][$key]) ? $admin['permissions'][$key] : [];
                                        if (is_array($perms) && array_filter($perms)):
                                        ?>
                                        <span class="permission-tag <?php echo $key; ?>" 
                                              data-ar="<?php echo $labels['ar']; ?>"
                                              data-en="<?php echo $labels['en']; ?>"
                                              data-fr="<?php echo $labels['fr']; ?>">
                                            <?php echo $labels['ar']; ?>
                                            <small>
                                                <?php
                                                $actions = [];
                                                if (isset($perms['view']) && $perms['view']) {
                                                    $actions[] = [
                                                        'ar' => 'عرض',
                                                        'en' => 'View',
                                                        'fr' => 'Voir'
                                                    ];
                                                }
                                                if (isset($perms['add']) && $perms['add']) {
                                                    $actions[] = [
                                                        'ar' => 'إضافة',
                                                        'en' => 'Add',
                                                        'fr' => 'Ajouter'
                                                    ];
                                                }
                                                if (isset($perms['edit']) && $perms['edit']) {
                                                    $actions[] = [
                                                        'ar' => 'تعديل',
                                                        'en' => 'Edit',
                                                        'fr' => 'Modifier'
                                                    ];
                                                }
                                                if (isset($perms['delete']) && $perms['delete']) {
                                                    $actions[] = [
                                                        'ar' => 'حذف',
                                                        'en' => 'Delete',
                                                        'fr' => 'Supprimer'
                                                    ];
                                                }
                                                if (isset($perms['send']) && $perms['send']) {
                                                    $actions[] = [
                                                        'ar' => 'إرسال',
                                                        'en' => 'Send',
                                                        'fr' => 'Envoyer'
                                                    ];
                                                }
                                                if (isset($perms['export']) && $perms['export']) {
                                                    $actions[] = [
                                                        'ar' => 'تصدير',
                                                        'en' => 'Export',
                                                        'fr' => 'Exporter'
                                                    ];
                                                }

                                                // Build translated strings for each language
                                                $ar_actions = array_map(function($a) { return $a['ar']; }, $actions);
                                                $en_actions = array_map(function($a) { return $a['en']; }, $actions);
                                                $fr_actions = array_map(function($a) { return $a['fr']; }, $actions);

                                                echo '<span data-ar="' . implode('، ', $ar_actions) . '" ' .
                                                     'data-en="' . implode(', ', $en_actions) . '" ' .
                                                     'data-fr="' . implode(', ', $fr_actions) . '">' .
                                                     implode('، ', $ar_actions) . '</span>';
                                                ?>
                                            </small>
                                        </span>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="admin-actions">
                        <?php if (empty($admin['is_super_admin'])): ?>
                        <button class="btn btn-warning btn-icon" onclick='openEditAdminModal(<?php echo json_encode($admin); ?>)' >
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-danger btn-icon"  onclick="deleteAdmin(<?php echo $admin['id']; ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Add Admin Modal -->
        <div class="modal" id="addAdminModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" data-ar="إضافة مدير جديد" data-en="Add New Admin" data-fr="Ajouter un Nouvel Admin">إضافة مدير جديد</h3>
            <button class="close-modal" onclick="closeModal('addAdminModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="addAdminForm" method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label class="form-label" data-ar="الاسم الكامل" data-en="Full Name" data-fr="Nom Complet">الاسم الكامل</label>
                <input type="text" name="nom" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label" data-ar="البريد الإلكتروني" data-en="Email" data-fr="E-mail">البريد الإلكتروني</label>

                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label" data-ar="كلمة المرور" data-en="Password" data-fr="Mot de passe">كلمة المرور</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="form-group"> 
                <label class="form-label" data-ar="تأكيد كلمة المرور" data-en="Confirm Password" data-fr="Confirmer le mot de passe">تأكيد كلمة المرور</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label"
                       data-ar="الصلاحيات"
                       data-en="Permissions" 
                       data-fr="Permissions">الصلاحيات</label>
                <div class="permissions-grid">
                    <!-- Agencies Section -->
                    <div class="permission-section">
                        <h4 data-ar="إدارة الوكالات"
                            data-en="Manage Agencies"
                            data-fr="Gérer les Agences">إدارة الوكالات</h4>
                        <div class="permission-actions">
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[agencies][view]" value="1">
                                <span data-ar="عرض"
                                      data-en="View"
                                      data-fr="Voir">عرض</span>
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[agencies][add]" value="1" disabled>
                                <span data-ar="إضافة"
                                      data-en="Add"
                                      data-fr="Ajouter">إضافة</span>
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[agencies][edit]" value="1" disabled>
                                <span data-ar="تعديل"
                                      data-en="Edit"
                                      data-fr="Modifier">تعديل</span>
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[agencies][delete]" value="1" disabled>
                                <span data-ar="حذف"
                                      data-en="Delete"
                                      data-fr="Supprimer">حذف</span>
                            </label>
                        </div>
                    </div>

                    <!-- المعتمرين -->
                    <div class="permission-section">
                        <h4 data-ar="إدارة المعتمرين"
                            data-en="Manage Pilgrims" 
                            data-fr="Gérer les Pèlerins">إدارة المعتمرين</h4>
                        <div class="permission-actions">
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[pilgrims][view]" value="1">
                                <span data-ar="عرض"
                                      data-en="View"
                                      data-fr="Voir">عرض</span>
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[pilgrims][add]" value="1" disabled>
                                <span data-ar="إضافة"
                                      data-en="Add"
                                      data-fr="Ajouter">إضافة</span>
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[pilgrims][edit]" value="1" disabled>
                                <span data-ar="تعديل"
                                      data-en="Edit"
                                      data-fr="Modifier">تعديل</span>
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[pilgrims][delete]" value="1" disabled>
                                <span data-ar="حذف"
                                      data-en="Delete"
                                      data-fr="Supprimer">حذف</span>
                            </label>
                        </div>
                    </div>

                    <!-- العروض -->
                    <div class="permission-section">
                        <h4 data-ar="إدارة العروض" 
                            data-en="Manage Offers" 
                            data-fr="Gérer les Offres">إدارة العروض</h4>
                        <div class="permission-actions">
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[offers][view]" value="1">
                                <span data-ar="عرض"
                                      data-en="View" 
                                      data-fr="Voir">عرض</span>
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[offers][add]" value="1" disabled>
                                <span data-ar="إضافة"
                                      data-en="Add"
                                      data-fr="Ajouter">إضافة</span>
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[offers][edit]" value="1" disabled>
                                <span data-ar="تعديل"
                                      data-en="Edit"
                                      data-fr="Modifier">تعديل</span>
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[offers][delete]" value="1" disabled>
                                <span data-ar="حذف"
                                      data-en="Delete"
                                      data-fr="Supprimer">حذف</span>
                            </label>
                        </div>
                    </div>

                    <!-- المحادثات -->
                    <div class="permission-section">
                        <h4 data-ar="إدارة المحادثات" 
                            data-en="Manage Chat" 
                            data-fr="Gérer le Chat">إدارة المحادثات</h4>
                        <div class="permission-actions">
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[chat][view]" value="1">
                                <span data-ar="عرض"
                                      data-en="View"
                                      data-fr="Voir">عرض</span>
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[chat][send]" value="1" disabled>
                                <span data-ar="إرسال"
                                      data-en="Send"
                                      data-fr="Envoyer">إرسال</span>
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[chat][delete]" value="1" disabled>
                                <span data-ar="حذف"
                                      data-en="Delete" 
                                      data-fr="Supprimer">حذف</span>
                            </label>
                        </div>
                    </div>

                    <!-- التقارير -->
                    <div class="permission-section">
                        <h4 data-ar="إدارة التقارير" 
                            data-en="Reports Management" 
                            data-fr="Gestion des Rapports">إدارة التقارير</h4>
                        <div class="permission-actions">
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[reports][view]" value="1">
                                <span data-ar="عرض" 
                                      data-en="View" 
                                      data-fr="Voir">عرض</span>
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="permissions[reports][export]" value="1">
                                <span data-ar="تصدير" 
                                      data-en="Export" 
                                      data-fr="Exporter">تصدير</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                <i class="fas fa-save"></i>
                <span data-ar="حفظ المدير" data-en="Save Admin" data-fr="Enregistrer Admin">حفظ المدير</span>
            </button>
        </form>
    </div>
</div>

        <!-- Edit Admin Modal -->
        <div class="modal" id="editAdminModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title" data-ar="تعديل المدير" data-en="Edit Admin" data-fr="Modifier l'Admin">تعديل المدير</h3>
                    <button class="close-modal" onclick="closeModal('editAdminModal')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <form id="editAdminForm" method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="form-group">
                    <label class="form-label" 
                           data-ar="الاسم الكامل"
                           data-en="Full Name" 
                           data-fr="Nom Complet">الاسم الكامل</label>
                    <input type="text" name="nom" id="edit_nom" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"
                           data-ar="البريد الإلكتروني"  
                           data-en="Email"
                           data-fr="Email">البريد الإلكتروني</label>
                    <input type="email" name="email" id="edit_email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"
                           data-ar="كلمة المرور"
                           data-en="Password"
                           data-fr="Mot de passe">كلمة المرور</label>
                    <input type="password" name="password" class="form-control" 
                           data-ar="اتركه فارغاً إذا كنت لا تريد التغيير"
                           data-en="Leave empty if you don't want to change"
                           data-fr="Laissez vide si vous ne voulez pas changer"
                           placeholder="اتركه فارغاً إذا كنت لا تريد التغيير">
                </div>
                <div class="form-group">
                    <label class="form-label"
                           data-ar="تأكيد كلمة المرور"  
                           data-en="Confirm Password"
                           data-fr="Confirmer le mot de passe">تأكيد كلمة المرور</label>
                    <input type="password" name="confirm_password" class="form-control"
                           data-ar="اتركه فارغاً إذا كنت لا تريد التغيير"
                           data-en="Leave empty if you don't want to change" 
                           data-fr="Laissez vide si vous ne voulez pas changer"
                           placeholder="اتركه فارغاً إذا كنت لا تريد التغيير">
                </div>
                <div class="form-group">
                    <label class="form-label"
                           data-ar="الصلاحيات"
                           data-en="Permissions"
                           data-fr="Permissions">الصلاحيات</label>
                    <div class="permissions-grid">
                        <!-- الوكالات -->
                        <div class="permission-section">
                            <h4 data-ar="إدارة الوكالات"
                                data-en="Manage Agencies"
                                data-fr="Gérer les Agences">إدارة الوكالات</h4>
                            <div class="permission-actions">
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[agencies][view]" value="1" id="edit_permission_agencies_view">
                                    <span data-ar="عرض" 
                                          data-en="View"
                                          data-fr="Voir">عرض</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[agencies][add]" value="1" id="edit_permission_agencies_add">
                                    <span data-ar="إضافة"
                                          data-en="Add"
                                          data-fr="Ajouter">إضافة</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[agencies][edit]" value="1" id="edit_permission_agencies_edit">
                                    <span data-ar="تعديل"
                                          data-en="Edit"
                                          data-fr="Modifier">تعديل</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[agencies][delete]" value="1" id="edit_permission_agencies_delete">
                                    <span data-ar="حذف"
                                          data-en="Delete"
                                          data-fr="Supprimer">حذف</span>
                                </label>
                            </div>
                        </div>

                        <!-- المعتمرين -->
                        <div class="permission-section">
                            <h4 data-ar="إدارة المعتمرين"
                                data-en="Manage Pilgrims"
                                data-fr="Gérer les Pèlerins">إدارة المعتمرين</h4>
                            <div class="permission-actions">
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[pilgrims][view]" value="1" id="edit_permission_pilgrims_view">
                                    <span data-ar="عرض"
                                          data-en="View"
                                          data-fr="Voir">عرض</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[pilgrims][add]" value="1" id="edit_permission_pilgrims_add">
                                    <span data-ar="إضافة"
                                          data-en="Add"
                                          data-fr="Ajouter">إضافة</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[pilgrims][edit]" value="1" id="edit_permission_pilgrims_edit">
                                    <span data-ar="تعديل"
                                          data-en="Edit"
                                          data-fr="Modifier">تعديل</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[pilgrims][delete]" value="1" id="edit_permission_pilgrims_delete">
                                    <span data-ar="حذف"
                                          data-en="Delete"
                                          data-fr="Supprimer">حذف</span>
                                </label>
                            </div>
                        </div>

                        <!-- العروض -->
                        <div class="permission-section">
                            <h4 data-ar="إدارة العروض"
                                data-en="Manage Offers"
                                data-fr="Gérer les Offres">إدارة العروض</h4>
                            <div class="permission-actions">
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[offers][view]" value="1" id="edit_permission_offers_view">
                                    <span data-ar="عرض"
                                          data-en="View"
                                          data-fr="Voir">عرض</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[offers][add]" value="1" id="edit_permission_offers_add">
                                    <span data-ar="إضافة"
                                          data-en="Add"
                                          data-fr="Ajouter">إضافة</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[offers][edit]" value="1" id="edit_permission_offers_edit">
                                    <span data-ar="تعديل"
                                          data-en="Edit"
                                          data-fr="Modifier">تعديل</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[offers][delete]" value="1" id="edit_permission_offers_delete">
                                    <span data-ar="حذف"
                                          data-en="Delete"
                                          data-fr="Supprimer">حذف</span>
                                </label>
                            </div>
                        </div>

                        <!-- المحادثات -->
                        <div class="permission-section">
                            <h4 data-ar="إدارة المحادثات"
                                data-en="Manage Chat"
                                data-fr="Gérer le Chat">إدارة المحادثات</h4>
                            <div class="permission-actions">
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[chat][view]" value="1" id="edit_permission_chat_view">
                                    <span data-ar="عرض"
                                          data-en="View"
                                          data-fr="Voir">عرض</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[chat][send]" value="1" id="edit_permission_chat_send">
                                    <span data-ar="إرسال"
                                          data-en="Send"
                                          data-fr="Envoyer">إرسال</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[chat][delete]" value="1" id="edit_permission_chat_delete">
                                    <span data-ar="حذف"
                                          data-en="Delete"
                                          data-fr="Supprimer">حذف</span>
                                </label>
                            </div>
                        </div>

                        <!-- التقارير -->
                        <div class="permission-section">
                            <h4 data-ar="إدارة التقارير"
                                data-en="Reports Management"
                                data-fr="Gestion des Rapports">إدارة التقارير</h4>
                            <div class="permission-actions">
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[reports][view]" value="1" id="edit_permission_reports_view">
                                    <span data-ar="عرض"
                                          data-en="View"
                                          data-fr="Voir">عرض</span>
                                </label>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[reports][export]" value="1" id="edit_permission_reports_export">
                                    <span data-ar="تصدير"
                                          data-en="Export"
                                          data-fr="Exporter">تصدير</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                        <i class="fas fa-save"></i>
                        <span data-ar="حفظ التغييرات"
                              data-en="Save Changes"
                              data-fr="Enregistrer les modifications">حفظ التغييرات</span>
                    </button>
                </form>
            </div>
</div>
    </div>
</div>

    <script>
// Notification Functions
function showNotification(message, type = 'success') {
    Swal.fire({
        title: type === 'success' ? 'نجاح' : 'خطأ',
        text: message,
        icon: type,
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer);
            toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
    });
}

function showSuccessMessage(message) {
    showNotification(message, 'success');
}

function showErrorMessage(message) {
    showNotification(message, 'error');
}

function clearValidationErrors() {
    // Clear all error states and messages
    document.querySelectorAll('.is-invalid').forEach(el => {
        el.classList.remove('is-invalid');
    });
    document.querySelectorAll('.invalid-feedback, .alert-danger').forEach(el => {
        el.remove();
    });
}

// (Removed duplicate showValidationErrors function to avoid conflicts)

function setupPermissionLogic(form) {
    if (!form) return;
    
    const viewCheckboxes = form.querySelectorAll('input[name^="permissions["][name$="[view]"]');
    viewCheckboxes.forEach(checkbox => {
        const match = checkbox.name.match(/permissions\[(.*?)\]/);
        if (!match) return;
        
        const section = match[1];
        const relatedChecks = form.querySelectorAll(`input[name^="permissions[${section}]"]:not([name$="[view]"])`);
        
        // Initial state
        relatedChecks.forEach(check => {
            check.disabled = !checkbox.checked;
            if (!checkbox.checked) check.checked = false;
        });
        
        // Change handler
        checkbox.addEventListener('change', function() {
            relatedChecks.forEach(check => {
                check.disabled = !this.checked;
                if (!this.checked) check.checked = false;
            });
        });
    });
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    
    modal.classList.remove('show');
    
    // Clear validation errors
    const form = modal.querySelector('form');
    if (form) {
        form.reset();
        clearValidationErrors();
    }
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    if (!sidebar || !mainContent) return;
    
    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded');
    
    // Toggle sidebar text visibility
    const sidebarTexts = document.querySelectorAll('.sidebar-text');
    sidebarTexts.forEach(text => {
        text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline';
    });
    
    // Change icon when collapsed
    const toggleBtn = document.querySelector('.sidebar-toggle i');
    if (toggleBtn) {
        if (sidebar.classList.contains('collapsed')) {
            toggleBtn.classList.remove('fa-bars');
            toggleBtn.classList.add('fa-indent');
        } else {
            toggleBtn.classList.remove('fa-indent');
            toggleBtn.classList.add('fa-bars');
        }
    }
}

function toggleMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) sidebar.classList.toggle('open');
}

function openAddAdminModal() {
    const modal = document.getElementById('addAdminModal');
    const form = document.getElementById('addAdminForm');
    if (!modal || !form) return;
    
    modal.classList.add('show');
    form.reset();
    clearValidationErrors();
    setupPermissionLogic(form);

    // Remove any existing event listeners by cloning
    const newForm = form.cloneNode(true);
    form.parentNode.replaceChild(newForm, form);
    setupPermissionLogic(newForm);

    // Add new submit handler
    newForm.addEventListener('submit', handleAddAdminSubmit);
}

async function handleAddAdminSubmit(e) {
    e.preventDefault();
    const form = e.target;
    
    // Clear previous errors
    clearValidationErrors();
    
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalBtnContent = submitBtn.innerHTML;
    
    try {
        // Validate passwords match
        if (formData.get('password') !== formData.get('confirm_password')) {
            showValidationErrors({ confirm_password: 'كلمة المرور غير متطابقة' });
            return;
        }
        
        // Validate password length
        if (formData.get('password').length < 8) {
            showValidationErrors({ password: 'كلمة المرور يجب أن تكون 8 أحرف على الأقل' });
            return;
        }

        // Validate email
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(formData.get('email'))) {
            showValidationErrors({ email: 'البريد الإلكتروني غير صالح' });
            return;
        }

        // Check permissions
        let hasPermission = false;
        for (let [key, value] of formData.entries()) {
            if (key.startsWith('permissions[') && value === '1') {
                hasPermission = true;
                break;
            }
        }
        if (!hasPermission) {
            showNotification('يجب اختيار صلاحية واحدة على الأقل', 'error');
            return;
        }

        // Show loading state
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...';
        submitBtn.disabled = true;

        const response = await fetch('function-manage_admin/ajouter_admin.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const responseText = await response.text();
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (e) {
            console.error('Failed to parse JSON response:', responseText);
            throw new Error('Invalid JSON response from server');
        }

        if (result.success) {
            showNotification(result.message, 'success');
            closeModal('addAdminModal');
            await refreshAdminsGrid();
            form.reset();
        } else {
            if (Array.isArray(result.errors)) {
                // إذا كانت الأخطاء مصفوفة، اعرض كل خطأ
                result.errors.forEach(error => {
                    showNotification(error, 'error');
                });
            } else if (typeof result.errors === 'object') {
                // إذا كانت الأخطاء كائن، استخدم دالة showValidationErrors
                showValidationErrors(result.errors);
            }
            if (result.message) {
                showNotification(result.message, 'error');
            }
        }
    } catch (error) {
        console.error('Error:', error);
        
        // Only show error message if it's an actual error response
        if (!response || !response.ok) {
            let errorMessage = 'حدث خطأ أثناء الاتصال بالخادم';
            
            if (error instanceof TypeError && error.message.includes('Failed to fetch')) {
                errorMessage = 'تعذر الاتصال بالخادم. يرجى التحقق من اتصال الإنترنت الخاص بك';
            } else if (error.message.includes('Invalid JSON')) {
                errorMessage = 'استجابة غير صالحة من الخادم';
            }
            
            showNotification(errorMessage, 'error');
        }
    } finally {
        submitBtn.innerHTML = originalBtnContent;
        submitBtn.disabled = false;
    }
}

function showValidationErrors(errors) {
    const form = document.getElementById('addAdminForm');
    if (!form) return;

    // Clear previous errors
    clearValidationErrors();

    // Add new error messages
    if (typeof errors === 'object') {
        Object.entries(errors).forEach(([field, message]) => {
            if (Array.isArray(message)) {
                message = message.join(', ');
            }
            
            if (field === 'permissions') {
                // Show permissions error above the permissions section
                const permissionsSection = form.querySelector('.permissions-grid');
                if (permissionsSection) {
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'alert alert-danger mt-2 mb-3';
                    errorDiv.textContent = message;
                    permissionsSection.parentNode.insertBefore(errorDiv, permissionsSection);
                }
            } else {
                // Show field-specific errors under the fields
                const input = form.querySelector(`[name="${field}"], [name^="${field}["]`);
                if (input) {
                    input.classList.add('is-invalid');
                    const errorContainer = document.createElement('div');
                    errorContainer.className = 'invalid-feedback';
                    errorContainer.style.display = 'block'; // Ensure error is visible
                    errorContainer.textContent = message;
                    input.parentNode.appendChild(errorContainer);
                }
            }
        });
    } else if (typeof errors === 'string') {
        // Show general error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'alert alert-danger mt-3';
        errorDiv.textContent = errors;
        form.insertBefore(errorDiv, form.firstChild);
    }
}

function clearValidationErrors() {
    // Clear all error states and messages
    document.querySelectorAll('.is-invalid').forEach(el => {
        el.classList.remove('is-invalid');
    });
    document.querySelectorAll('.invalid-feedback, .alert-danger').forEach(el => {
        el.remove();
    });
}
    // Populate Edit Admin Modal with admin data
    function openEditAdminModal(admin) {
        const modal = document.getElementById('editAdminModal');
        const form = document.getElementById('editAdminForm');
        if (!modal || !form) return;

        // Remove previous submit event listeners by cloning
        const newForm = form.cloneNode(true);
        form.parentNode.replaceChild(newForm, form);

        // Fill basic fields
        newForm.reset();
        clearValidationErrors();
        newForm.querySelector('#edit_id').value = admin.id;
        newForm.querySelector('#edit_nom').value = admin.full_name;
        newForm.querySelector('#edit_email').value = admin.email;

        // Fill permissions
        const permissions = admin.permissions || {};
        const sections = ['agencies', 'pilgrims', 'offers', 'chat', 'reports'];
        const actions = {
            agencies: ['view', 'add', 'edit', 'delete'],
            pilgrims: ['view', 'add', 'edit', 'delete'],
            offers: ['view', 'add', 'edit', 'delete'],
            chat: ['view', 'send', 'delete'],
            reports: ['view', 'export']
        };
        sections.forEach(section => {
            actions[section].forEach(action => {
                const checkbox = newForm.querySelector(`#edit_permission_${section}_${action}`);
                if (checkbox) {
                    checkbox.checked = permissions[section] && permissions[section][action] ? true : false;
                }
            });
        });

        setupPermissionLogic(newForm);

        newForm.addEventListener('submit', handleEditAdminSubmit);

        modal.classList.add('show');
    }

    async function handleEditAdminSubmit(e) {
        e.preventDefault();
        const form = e.target;

        clearValidationErrors();

        const formData = new FormData(form);
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalBtnContent = submitBtn.innerHTML;

        try {
            // Password validation (if provided)
            const password = formData.get('password');
            const confirmPassword = formData.get('confirm_password');
            if (password || confirmPassword) {
                if (password !== confirmPassword) {
                    showValidationErrors({ confirm_password: 'كلمة المرور غير متطابقة' });
                    return;
                }
                if (password.length > 0 && password.length < 8) {
                    showValidationErrors({ password: 'كلمة المرور يجب أن تكون 8 أحرف على الأقل' });
                    return;
                }
            }

            // Validate email
            const email = formData.get('email');
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showValidationErrors({ email: 'البريد الإلكتروني غير صالح' });
                return;
            }

            // Check permissions
            let hasPermission = false;
            for (let [key, value] of formData.entries()) {
                if (key.startsWith('permissions[') && value === '1') {
                    hasPermission = true;
                    break;
                }
            }
            if (!hasPermission) {
                showNotification('يجب اختيار صلاحية واحدة على الأقل', 'error');
                return;
            }

            // Show loading state
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...';
            submitBtn.disabled = true;

            const response = await fetch('function-manage_admin/modifier_admin.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const responseText = await response.text();
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (e) {
                console.error('Failed to parse JSON response:', responseText);
                throw new Error('Invalid JSON response from server');
            }

            if (result.success) {
                showNotification(result.message, 'success');
                closeModal('editAdminModal');
                await refreshAdminsGrid();
            } else {
                if (Array.isArray(result.errors)) {
                    result.errors.forEach(error => {
                        showNotification(error, 'error');
                    });
                } else if (typeof result.errors === 'object') {
                    showValidationErrors(result.errors);
                }
                if (result.message) {
                    showNotification(result.message, 'error');
                }
            }
        } catch (error) {
            console.error('Error:', error);
            let errorMessage = 'حدث خطأ أثناء الاتصال بالخادم';
            if (error instanceof TypeError && error.message.includes('Failed to fetch')) {
                errorMessage = 'تعذر الاتصال بالخادم. يرجى التحقق من اتصال الإنترنت الخاص بك';
            } else if (error.message.includes('Invalid JSON')) {
                errorMessage = 'استجابة غير صالحة من الخادم';
            }
            showNotification(errorMessage, 'error');
        } finally {
            submitBtn.innerHTML = originalBtnContent;
            submitBtn.disabled = false;
        }
    }

    // Refresh admins grid after add/edit/delete
    async function refreshAdminsGrid() {
        try {
            // Reload the current page
            window.location.reload();
            return true;
        } catch (error) {
            console.error('Error:', error);
            showNotification('تعذر تحديث قائمة المدراء: ' + error.message, 'error');
            return false;
        }
    }

function initializeAdminCardHandlers() {
    // Re-attach event handlers for admin cards
    document.querySelectorAll('.admin-card').forEach(card => {
        const editBtn = card.querySelector('.btn-warning');
        const deleteBtn = card.querySelector('.btn-danger');
        
        if (editBtn) {
            editBtn.onclick = function() {
                const adminData = JSON.parse(this.getAttribute('onclick').match(/\{.*\}/)[0]);
                openEditAdminModal(adminData);
            };
        }
        
        if (deleteBtn) {
            deleteBtn.onclick = function() {
                const adminId = this.getAttribute('onclick').match(/\d+/)[0];
                deleteAdmin(adminId);
            };
        }
    });
}
    // Delete admin handler
    async function deleteAdmin(adminId) {
        if (!adminId) return;
        const confirmed = await Swal.fire({
            title: 'تأكيد الحذف',
            text: 'هل أنت متأكد أنك تريد حذف هذا المدير؟ لا يمكن التراجع عن هذا الإجراء.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، احذف',
            cancelButtonText: 'إلغاء'
        });
        if (confirmed.isConfirmed) {
            try {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('id', adminId);

                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const resultText = await response.text();
                // Try to parse as JSON, fallback to HTML
                let result;
                try {
                    result = JSON.parse(resultText);
                } catch {
                    // Not JSON, fallback to showing as HTML
                    if (resultText.includes('success-message')) {
                        showNotification('تم حذف المدير بنجاح', 'success');
                        await refreshAdminsGrid();
                        return;
                    } else if (resultText.includes('error-message')) {
                        showNotification('حدث خطأ أثناء حذف المدير', 'error');
                        return;
                    }
                }
                if (result && result.success) {
                    showNotification(result.message || 'تم حذف المدير بنجاح', 'success');
                    await refreshAdminsGrid();
                } else {
                    showNotification((result && result.message) || 'حدث خطأ أثناء حذف المدير', 'error');
                }
            } catch (error) {
                showNotification('حدث خطأ أثناء حذف المدير', 'error');
            }
        }
    }
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
    </script>
    <script> // Language data
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
</script>
</body>
</html>