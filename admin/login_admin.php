<?php
// تمكين إعدادات التصحيح
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-errors.log');

// بدء الجلسة في البداية قبل أي إخراج
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// إعدادات اتصال Supabase
$DB_CONFIG = array(
    'host' => 'aws-0-eu-west-3.pooler.supabase.com',
    'port' => '6543',
    'dbname' => 'postgres',
    'user' => 'postgres.zrwtxvybdxphylsvjopi',
    'password' => 'Dj123456789.',
    'sslmode' => 'require',
    'schema' => 'public'
);

// متغيرات الرسائل
$error = '';
$success = '';

// إنشاء اتصال PDO مع Supabase PostgreSQL
try {
    $dsn = sprintf(
        "pgsql:host=%s;port=%s;dbname=%s;sslmode=%s",
        $DB_CONFIG['host'],
        $DB_CONFIG['port'],
        $DB_CONFIG['dbname'],
        $DB_CONFIG['sslmode']
    );
    
    $options = array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false
    );
    
    $pdo = new PDO($dsn, $DB_CONFIG['user'], $DB_CONFIG['password'], $options);
    
    if (isset($DB_CONFIG['schema'])) {
        $pdo->exec("SET search_path TO " . $DB_CONFIG['schema']);
    }
    
    // التحقق من إرسال النموذج
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email)) {
            $error = 'يرجى إدخال البريد الإلكتروني';
        } elseif (empty($password)) {
            $error = 'يرجى إدخال كلمة المرور';
        } else {
            // التحقق من وجود المستخدم في جدول admins
            $stmt = $pdo->prepare("SELECT id, nom, email, mot_de_passe FROM admins WHERE email = :email");
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['mot_de_passe'])) {
                // تسجيل الدخول كمدير عام
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_nom'] = $admin['nom'];
                $_SESSION['admin_email'] = $admin['email'];
                $_SESSION['admin_type'] = 'admin';
                $_SESSION['admin_permissions'] = 'all'; // المدير العام لديه جميع الصلاحيات
                
                header('Location: dashboard.php');
                exit();
            } else {
                // التحقق من وجود المستخدم في جدول sub_admins
                $stmt = $pdo->prepare("
                    SELECT sa.id, sa.nom, sa.email, sa.mot_de_passe, 
                           sap.permission_key, sap.allow_view, sap.allow_add, 
                           sap.allow_edit, sap.allow_delete
                    FROM sub_admins sa
                    LEFT JOIN sub_admin_permissions sap ON sa.id = sap.sub_admin_id
                    WHERE sa.email = :email
                ");
                $stmt->bindParam(':email', $email);
                $stmt->execute();
                $subAdmin = $stmt->fetchAll();

                if ($subAdmin && password_verify($password, $subAdmin[0]['mot_de_passe'])) {
                    // تسجيل الدخول كمدير ثانوي
                    $_SESSION['admin_id'] = $subAdmin[0]['id'];
                    $_SESSION['admin_nom'] = $subAdmin[0]['nom'];
                    $_SESSION['admin_email'] = $subAdmin[0]['email'];
                    $_SESSION['admin_type'] = 'sub_admin';
                    
                    // تجميع الصلاحيات
                    $permissions = [];
                    foreach ($subAdmin as $perm) {
                        if ($perm['permission_key']) {
                            $permissions[$perm['permission_key']] = [
                                'view' => $perm['allow_view'],
                                'add' => $perm['allow_add'],
                                'edit' => $perm['allow_edit'],
                                'delete' => $perm['allow_delete']
                            ];
                        }
                    }
                    $_SESSION['admin_permissions'] = $permissions;
                    
                    header('Location: dashboard.php');
                    exit();
                } else {
                    $error = 'البريد الإلكتروني أو كلمة المرور غير صحيحة';
                }
            }
        }
    }
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
    <title>تسجيل دخول المدراء | منصة العمرة</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --error-color: #dc3545;
            --success-color: #28a745;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Cairo', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            padding: 20px;
        }

        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }

        .logo {
            font-size: 3rem;
            color: #764ba2;
            margin-bottom: 1rem;
        }

        h1 {
            color: #2c3e50;
            margin-bottom: 2rem;
            font-size: 1.8rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
            text-align: right;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #2c3e50;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            font-family: 'Cairo', sans-serif;
        }

        .form-control:focus {
            border-color: #764ba2;
            outline: none;
            box-shadow: 0 0 0 3px rgba(118, 75, 162, 0.2);
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 10px;
            background: var(--primary-gradient);
            color: white;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Cairo', sans-serif;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .error-message {
            color: var(--error-color);
            background-color: rgba(220, 53, 69, 0.1);
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .success-message {
            color: var(--success-color);
            background-color: rgba(40, 167, 69, 0.1);
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .password-toggle {
            position: relative;
        }

        .password-toggle .toggle-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #718096;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <i class="fas fa-kaaba"></i>
        </div>
        <h1>تسجيل دخول المدراء</h1>
        
        <?php if (!empty($error)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" onsubmit="return validateForm()">
            <div class="form-group">
                <label for="email">البريد الإلكتروني</label>
                <input type="email" 
                       id="email" 
                       name="email" 
                       class="form-control" 
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                       required 
                       autocomplete="email">
            </div>

            <div class="form-group">
                <label for="password">كلمة المرور</label>
                <div class="password-toggle">
                    <input type="password" 
                           id="password" 
                           name="password" 
                           class="form-control" 
                           required 
                           autocomplete="current-password">
                    <i class="fas fa-eye toggle-icon" id="togglePassword"></i>
                </div>
            </div>

            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i>
                تسجيل الدخول
            </button>
        </form>
    </div>

    <script>
        // تبديل عرض/إخفاء كلمة المرور
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');

        togglePassword.addEventListener('click', function (e) {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });

        // التحقق من صحة النموذج
        function validateForm() {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;

            if (!email || !password) {
                alert('يرجى ملء جميع الحقول المطلوبة');
                return false;
            }

            // التحقق من صحة البريد الإلكتروني
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                alert('يرجى إدخال بريد إلكتروني صحيح');
                return false;
            }

            return true;
        }

        // منع تقديم النموذج عند الضغط على Enter في حقل كلمة المرور
        document.getElementById('password').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (validateForm()) {
                    document.querySelector('form').submit();
                }
            }
        });
    </script>
</body>
</html>