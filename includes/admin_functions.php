<?php
// دوال إدارة المدراء (admins/sub_admins)
require_once __DIR__ . '/config.php';

function getAllAdmins() {
    try {
        $admins = [];
        // جلب المدراء العامين
        $mainAdmins = executeQuery("SELECT id, nom as full_name, email, est_super_admin, created_at, last_activity FROM admins");
        if ($mainAdmins) {
            foreach ($mainAdmins->fetchAll() as $admin) {
                $admin['is_super_admin'] = (bool)$admin['est_super_admin'];
                unset($admin['est_super_admin']);
                $admin['permissions'] = [
                    'agencies' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
                    'pilgrims' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
                    'offers' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
                    'chat' => ['view' => true, 'send' => true, 'delete' => true],
                    'reports' => ['view' => true, 'export' => true]
                ];
                $admins[] = $admin;
            }
        }

        // جلب المدراء الفرعيين
        $subAdminsSql = "
            SELECT 
                sa.id,
                sa.nom as full_name,
                sa.email,
                sa.created_at,
                sa.last_activity,
                false as is_super_admin
            FROM sub_admins sa
            ORDER BY sa.id DESC";

        $subAdmins = executeQuery($subAdminsSql);
        if ($subAdmins) {
            foreach ($subAdmins->fetchAll() as $admin) {
                // جلب الصلاحيات لكل مدير فرعي
                $permsSql = "
                    SELECT 
                        permission_key,
                        allow_view,
                        allow_add,
                        allow_edit,
                        allow_delete
                    FROM sub_admin_permissions 
                    WHERE sub_admin_id = ?";
                
                $permsStmt = executeQuery($permsSql, [$admin['id']]);
                $permissions = [
                    'agencies' => ['view' => false, 'add' => false, 'edit' => false, 'delete' => false],
                    'pilgrims' => ['view' => false, 'add' => false, 'edit' => false, 'delete' => false],
                    'offers' => ['view' => false, 'add' => false, 'edit' => false, 'delete' => false],
                    'chat' => ['view' => false, 'send' => false, 'delete' => false],
                    'reports' => ['view' => false, 'export' => false]
                ];

                if ($permsStmt) {
                    foreach ($permsStmt->fetchAll() as $perm) {
                        $key = $perm['permission_key'];
                        if (isset($permissions[$key])) {
                            $permissions[$key]['view'] = (bool)$perm['allow_view'];
                            $permissions[$key]['add'] = (bool)$perm['allow_add'];
                            $permissions[$key]['edit'] = (bool)$perm['allow_edit'];
                            $permissions[$key]['delete'] = (bool)$perm['allow_delete'];
                            
                            // خاص بالمحادثة والتقارير
                            if ($key === 'chat') {
                                $permissions[$key]['send'] = (bool)$perm['allow_add'];
                                unset($permissions[$key]['add']);
                            } elseif ($key === 'reports') {
                                $permissions[$key]['export'] = (bool)$perm['allow_add'];
                                unset($permissions[$key]['add'], $permissions[$key]['edit'], $permissions[$key]['delete']);
                            }
                        }
                    }
                }
                
                $admin['permissions'] = $permissions;
                $admins[] = $admin;
            }
        }

        return $admins;
    } catch (Exception $e) {
        error_log('Error in getAllAdmins: ' . $e->getMessage());
        return [];
    }
}

function searchAdmins($query) {
    $sql = "SELECT 
                a.id, 
                a.nom as full_name, 
                a.email, 
                a.est_super_admin as is_super_admin,
                a.created_at,
                a.last_activity,
                '[]'::jsonb as permissions
            FROM admins a
            WHERE a.nom ILIKE ? OR a.email ILIKE ?
            UNION ALL
            SELECT 
                sa.id,
                sa.nom as full_name,
                sa.email,
                false as is_super_admin,
                sa.created_at,
                sa.last_activity,
                (SELECT json_agg(permission_key) FROM sub_admin_permissions WHERE sub_admin_id = sa.id) as permissions
            FROM sub_admins sa
            WHERE sa.nom ILIKE ? OR sa.email ILIKE ?
            ORDER BY id DESC";
    $params = ["%$query%", "%$query%", "%$query%", "%$query%"];
    $stmt = executeQuery($sql, $params);
    return $stmt ? $stmt->fetchAll() : [];
}

function addAdmin($data) {
    try {
        // Log start of admin creation
        error_log('Starting admin creation process');
        
        // Validate required data
        if (empty($data['full_name']) || empty($data['email']) || empty($data['password'])) {
            error_log('Missing required data for admin creation');
            return false;
        }
        
        // Email validation
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            error_log('Invalid email format: ' . $data['email']);
            return false;
        }

        // Check for duplicate email
        $checkSql = "SELECT id FROM admins WHERE email = ? UNION SELECT id FROM sub_admins WHERE email = ?";
        $checkStmt = executeQuery($checkSql, [$data['email'], $data['email']]);
        if ($checkStmt && $checkStmt->fetch()) {
            error_log('Duplicate email found: ' . $data['email']);
            return false;
        }

        // Start transaction
        $GLOBALS['pdo']->beginTransaction();
        error_log('Transaction started');

        // Insert new admin
        $sql = "INSERT INTO sub_admins (nom, email, mot_de_passe, cree_par_admin_id, created_at, last_activity) 
                VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) RETURNING id";
        $params = [
            $data['full_name'],
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $_SESSION['admin_id'] ?? 1
        ];
        
        error_log('Executing admin insert with SQL: ' . $sql);
        $stmt = executeQuery($sql, $params);
        
        if (!$stmt) {
            error_log('Failed to execute admin insert query');
            throw new Exception("فشل في إضافة المدير: Query execution failed");
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            error_log('Failed to get new admin ID');
            throw new Exception("فشل في الحصول على معرف المدير الجديد");
        }

        $adminId = $result['id'];
        error_log('New admin created with ID: ' . $adminId);

        // Add permissions
        if (!empty($data['permissions'])) {
            $permissions = $data['permissions'];
            if (is_string($permissions)) {
                $permissions = json_decode($permissions, true);
            }

            foreach ($permissions as $section => $actions) {
                // Validate section name
                if (!preg_match('/^[a-z_]+$/', $section)) {
                    continue;
                }

                foreach ($actions as $action => $value) {
                    if ($value) {
                        $permissionSql = "INSERT INTO sub_admin_permissions 
                            (sub_admin_id, permission_key, allow_view, allow_add, allow_edit, allow_delete) 
                            VALUES (?, ?, ?, ?, ?, ?)";
                        
                        $allowView = ($action === 'view');
                        $allowAdd = ($action === 'add' || ($section === 'chat' && $action === 'send') || ($section === 'reports' && $action === 'export'));
                        $allowEdit = ($action === 'edit');
                        $allowDelete = ($action === 'delete');
                        
                        $permissionParams = [
                            $adminId,
                            $section,
                            $allowView,
                            $allowAdd,
                            $allowEdit,
                            $allowDelete
                        ];

                        error_log('Adding permission: ' . $section . ' - ' . $action);
                        $permResult = executeQuery($permissionSql, $permissionParams);
                        
                        if (!$permResult) {
                            error_log('Failed to add permission: ' . $section . ' - ' . $action);
                            throw new Exception("فشل في إضافة الصلاحيات");
                        }
                    }
                }
            }
        }

        $GLOBALS['pdo']->commit();
        error_log('Successfully added admin and permissions');
        return true;

    } catch (Exception $e) {
        if ($GLOBALS['pdo']->inTransaction()) {
            $GLOBALS['pdo']->rollBack();
        }
        error_log('Error in addAdmin: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        return false;
    }
}

function updateAdmin($id, $data) {
    try {
        // Log start of update process
        error_log('Starting admin update process for ID: ' . $id);
        error_log('Update data: ' . print_r($data, true));

        // Validate admin exists
        $checkSql = "SELECT id FROM sub_admins WHERE id = ?";
        $checkStmt = executeQuery($checkSql, [$id]);
        if (!$checkStmt || !$checkStmt->fetch()) {
            throw new Exception("المدير غير موجود");
        }

        // Start transaction
        $GLOBALS['pdo']->beginTransaction();

        // Update basic information
        $sql = "UPDATE sub_admins SET 
                nom = :nom, 
                email = :email, 
                last_activity = CURRENT_TIMESTAMP";
        $params = [
            ':nom' => $data['full_name'],
            ':email' => $data['email']
        ];

        // Add password if provided
        if (!empty($data['password'])) {
            $sql .= ", mot_de_passe = :password";
            $params[':password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        $sql .= " WHERE id = :id";
        $params[':id'] = $id;

        $stmt = executeQuery($sql, $params);
        if (!$stmt) {
            throw new Exception("فشل في تحديث بيانات المدير");
        }

        // Update permissions
        if (isset($data['permissions'])) {
            // Delete existing permissions
            $deleteStmt = executeQuery("DELETE FROM sub_admin_permissions WHERE sub_admin_id = ?", [$id]);
            if (!$deleteStmt) {
                throw new Exception("فشل في حذف الصلاحيات القديمة");
            }

            // Add new permissions
            foreach ($data['permissions'] as $section => $actions) {
                // Validate section name
                if (!preg_match('/^[a-z_]+$/', $section)) {
                    error_log("Invalid section name: " . $section);
                    continue;
                }

                // Initialize permission values
                $permValues = [
                    'allow_view' => false,
                    'allow_add' => false,
                    'allow_edit' => false,
                    'allow_delete' => false
                ];

                // Set permission values based on actions
                foreach ($actions as $action => $value) {
                    if ($value == '1' || $value === true) {
                        switch ($action) {
                            case 'view':
                                $permValues['allow_view'] = true;
                                break;
                            case 'add':
                            case 'send':
                            case 'export':
                                $permValues['allow_add'] = true;
                                break;
                            case 'edit':
                                $permValues['allow_edit'] = true;
                                break;
                            case 'delete':
                                $permValues['allow_delete'] = true;
                                break;
                        }
                    }
                }

                // Insert permission with explicit boolean casting
                $permSql = "INSERT INTO sub_admin_permissions 
                    (sub_admin_id, permission_key, allow_view, allow_add, allow_edit, allow_delete)
                    VALUES 
                    (:admin_id, :key, :view::boolean, :add::boolean, :edit::boolean, :delete::boolean)";

                $permParams = [
                    ':admin_id' => $id,
                    ':key' => $section,
                    ':view' => $permValues['allow_view'] ? 't' : 'f',
                    ':add' => $permValues['allow_add'] ? 't' : 'f',
                    ':edit' => $permValues['allow_edit'] ? 't' : 'f',
                    ':delete' => $permValues['allow_delete'] ? 't' : 'f'
                ];

                error_log("Adding permissions for section {$section}: " . json_encode($permValues));
                
                $permStmt = executeQuery($permSql, $permParams);
                if (!$permStmt) {
                    $error = error_get_last();
                    throw new Exception("فشل في إضافة الصلاحيات الجديدة للقسم {$section}: " . ($error['message'] ?? 'unknown error'));
                }
            }
        }

        $GLOBALS['pdo']->commit();
        error_log('Successfully updated admin and permissions');
        return true;

    } catch (Exception $e) {
        if ($GLOBALS['pdo']->inTransaction()) {
            $GLOBALS['pdo']->rollBack();
        }
        error_log('Error in updateAdmin: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        throw $e; // Re-throw to handle in the controller
    }
}

function deleteAdmin($id) {
    try {
        $GLOBALS['pdo']->beginTransaction();
        executeQuery("DELETE FROM sub_admin_permissions WHERE sub_admin_id = ?", [$id]);
        $stmt = executeQuery("DELETE FROM sub_admins WHERE id = ?", [$id]);
        if (!$stmt) throw new Exception("فشل في حذف المدير");
        $GLOBALS['pdo']->commit();
        return true;
    } catch (Exception $e) {
        if ($GLOBALS['pdo']->inTransaction()) $GLOBALS['pdo']->rollBack();
        error_log('خطأ في حذف المدير: ' . $e->getMessage());
        return false;
    }
}

function updateUserActivity($userId, $userType = 'admin') {
    try {
        $table = ($userType === 'admin') ? 'admins' : 'sub_admins';
        $sql = "UPDATE $table SET last_activity = CURRENT_TIMESTAMP WHERE id = ?";
        $result = executeQuery($sql, [$userId]);
        return $result !== false;
    } catch (Exception $e) {
        error_log('Error updating user activity: ' . $e->getMessage());
        return false;
    }
}

function getUserActivityStatus($userId, $userType = 'admin') {
    try {
        $table = ($userType === 'admin') ? 'admins' : 'sub_admins';
        $sql = "SELECT 
                    CASE 
                        WHEN last_activity IS NULL THEN false
                        WHEN last_activity > CURRENT_TIMESTAMP - INTERVAL '2 minutes' THEN true 
                        ELSE false 
                    END as is_active,
                    last_activity
                FROM $table 
                WHERE id = ?";
        $stmt = executeQuery($sql, [$userId]);
        if ($stmt === false) return ['is_active' => false, 'last_activity' => null];
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: ['is_active' => false, 'last_activity' => null];
    } catch (Exception $e) {
        error_log('Error getting user activity status: ' . $e->getMessage());
        return ['is_active' => false, 'last_activity' => null];
    }
}

function getActiveUsers($userType = 'admin') {
    try {
        $table = ($userType === 'admin') ? 'admins' : 'sub_admins';
        $sql = "SELECT id, nom, email, last_activity 
                FROM $table 
                WHERE last_activity > CURRENT_TIMESTAMP - INTERVAL '2 minutes'
                ORDER BY last_activity DESC";
        $stmt = executeQuery($sql);
        if ($stmt === false) return [];
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Error getting active users: ' . $e->getMessage());
        return [];
    }
}

function updatePermissions($adminId, $permissions) {
    try {
        $GLOBALS['pdo']->beginTransaction();
        
        // حذف الصلاحيات الحالية
        executeQuery("DELETE FROM sub_admin_permissions WHERE sub_admin_id = ?", [$adminId]);
        
        // إضافة الصلاحيات الجديدة
        foreach ($permissions as $section => $actions) {
            foreach ($actions as $action => $value) {
                if ($value) {
                    $sql = "INSERT INTO sub_admin_permissions 
                           (sub_admin_id, permission_key, allow_view, allow_add, allow_edit, allow_delete) 
                           VALUES (?, ?, ?, ?, ?, ?)";
                    
                    // تحديد القيم بناءً على نوع القسم
                    $params = [$adminId, $section];
                    
                    if ($section === 'chat') {
                        $params[] = (bool)$value; // view
                        $params[] = false; // add (not used)
                        $params[] = false; // edit (not used)
                        $params[] = $action === 'delete' ? true : false;
                    } elseif ($section === 'reports') {
                        $params[] = (bool)$value; // view
                        $params[] = $action === 'export' ? true : false;
                        $params[] = false; // edit (not used)
                        $params[] = false; // delete (not used)
                    } else {
                        $params[] = $action === 'view' ? true : false;
                        $params[] = $action === 'add' ? true : false;
                        $params[] = $action === 'edit' ? true : false;
                        $params[] = $action === 'delete' ? true : false;
                    }
                    
                    executeQuery($sql, $params);
                }
            }
        }
        
        $GLOBALS['pdo']->commit();
        return true;
    } catch (Exception $e) {
        if ($GLOBALS['pdo']->inTransaction()) $GLOBALS['pdo']->rollBack();
        error_log('خطأ في تحديث الصلاحيات: ' . $e->getMessage());
        return false;
    }
}

function handleError($error, $context = '') {
    // تسجيل الخطأ في ملف السجل
    error_log(sprintf("[%s] %s - Context: %s", 
        date('Y-m-d H:i:s'),
        $error,
        $context
    ));
    
    // إرجاع رسالة خطأ منسقة
    return [
        'status' => 'error',
        'message' => $error,
        'context' => $context,
        'timestamp' => time()
    ];
}

function validateAdminData($data, $isUpdate = false) {
    $errors = [];
    
    // التحقق من الاسم
    if (empty($data['full_name']) || strlen($data['full_name']) < 3) {
        $errors['name'] = 'يجب أن يكون الاسم 3 أحرف على الأقل';
    }
    
    // التحقق من البريد الإلكتروني
    if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'البريد الإلكتروني غير صالح';
    }
    
    // التحقق من كلمة المرور في حالة الإضافة
    if (!$isUpdate && (empty($data['password']) || strlen($data['password']) < 8)) {
        $errors['password'] = 'يجب أن تكون كلمة المرور 8 أحرف على الأقل';
    }
    
    // التحقق من الصلاحيات
    if (empty($data['permissions'])) {
        $errors['permissions'] = 'يجب اختيار صلاحية واحدة على الأقل';
    }
    
    return $errors;
}

