<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/agency_errors.log');

require_once '../../includes/config.php';

// تعريف المسار المطلق لمجلد التحميل
$uploadDir = dirname(dirname(dirname(__FILE__))) . '/uploads/offers/';

// التأكد من وجود المجلد وصلاحياته
if (!file_exists($uploadDir)) {
    // إنشاء المجلد بصلاحيات كاملة
    if (!@mkdir($uploadDir, 0777, true)) {
        error_log("Failed to create directory: " . $uploadDir);
    } else {
        chmod($uploadDir, 0777);
    }
}

// الحصول على معرف العرض من الرابط
$offer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// جلب بيانات العرض
$offer = null;
if ($offer_id > 0) {
    $stmt = executeQuery("SELECT * FROM offres WHERE id = :id", ['id' => $offer_id]);
    if ($stmt) {
        $offer = $stmt->fetch();
    }
}

// معالجة تحديث البيانات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // معالجة الحقول البولينية
    $booleanFields = [
        'est_doree', 'service_guide', 'service_transport',
        'service_nourriture', 'service_assurance',
        'cadeau_bag', 'cadeau_zamzam', 'cadeau_parapluie', 'cadeau_autre'
    ];

    $updateData = [
        'titre' => $_POST['titre'],
        'description' => $_POST['description'],
        'prix_base' => floatval($_POST['prix_base']),
        'date_depart' => $_POST['date_depart'],
        'date_retour' => $_POST['date_retour'],
        'aeroport_depart' => $_POST['aeroport_depart'],
        'compagnie_aerienne' => $_POST['compagnie_aerienne'],
        'type_voyage' => $_POST['type_voyage'],
        'nom_hotel' => $_POST['nom_hotel'],
        'numero_chambre' => $_POST['numero_chambre'],
        'distance_haram' => floatval($_POST['distance_haram']),
        'prix_2' => floatval($_POST['prix_2']),
        'prix_3' => floatval($_POST['prix_3']),
        'prix_4' => floatval($_POST['prix_4']),
        'id' => $offer_id
    ];

    // إضافة الحقول البولينية مع معالجة القيم الفارغة
    foreach ($booleanFields as $field) {
        $updateData[$field] = isset($_POST[$field]) ? 't' : 'f';
    }

    // إضافة الصورة للتحديث إذا تم رفعها
    if (isset($_FILES['image_offre_principale']) && $_FILES['image_offre_principale']['error'] === UPLOAD_ERR_OK) {
        $fileName = 'main_' . $offer_id . '_' . time() . '.png';
        $uploadFile = $uploadDir . $fileName;
        
        if (@move_uploaded_file($_FILES['image_offre_principale']['tmp_name'], $uploadFile)) {
            chmod($uploadFile, 0644);
            $updateData['image_offre_principale'] = $fileName;
        }
    }

    // بناء استعلام التحديث
    $fields = array_keys($updateData);
    $sql = "UPDATE offres SET " . 
           implode(", ", array_map(fn($field) => "$field = :$field", 
           array_filter($fields, fn($f) => $f !== 'id'))) .
           " WHERE id = :id";

    $stmt = executeQuery($sql, $updateData);
    
    if ($stmt) {
        header("Location: details.php?id=" . $offer_id);
        exit();
    }
}

if (!$offer) {
    die("لم يتم العثور على العرض المطلوب");
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تعديل العرض</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* نسخ التنسيقات الأساسية من details.php */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            padding: 40px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="date"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        .form-group textarea {
            height: 100px;
            resize: vertical;
        }
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-group {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 30px;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background: #28a745;
            color: white;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 style="margin-bottom: 30px;">تعديل العرض</h1>
        <form method="POST" action="" enctype="multipart/form-data">
            <div class="form-group">
                <label for="titre">عنوان العرض</label>
                <input type="text" id="titre" name="titre" value="<?php echo htmlspecialchars($offer['titre']); ?>" required>
            </div>

            <div class="form-group">
                <label for="description">وصف العرض</label>
                <textarea id="description" name="description" required><?php echo htmlspecialchars($offer['description']); ?></textarea>
            </div>

            <div class="form-group">
                <label for="type_voyage">نوع الرحلة</label>
                <select id="type_voyage" name="type_voyage" required>
                    <option value="Hajj" <?php echo $offer['type_voyage'] === 'Hajj' ? 'selected' : ''; ?>>الحج</option>
                    <option value="Omra" <?php echo $offer['type_voyage'] === 'Omra' ? 'selected' : ''; ?>>العمرة</option>
                </select>
            </div>

            <!-- الأسعار -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div class="form-group">
                    <label for="prix_base">السعر الأساسي</label>
                    <input type="number" id="prix_base" name="prix_base" value="<?php echo $offer['prix_base']; ?>" required>
                </div>
                <div class="form-group">
                    <label for="prix_2">سعر شخصين</label>
                    <input type="number" id="prix_2" name="prix_2" value="<?php echo $offer['prix_2']; ?>" required>
                </div>
                <div class="form-group">
                    <label for="prix_3">سعر ثلاثة أشخاص</label>
                    <input type="number" id="prix_3" name="prix_3" value="<?php echo $offer['prix_3']; ?>" required>
                </div>
                <div class="form-group">
                    <label for="prix_4">سعر أربعة أشخاص</label>
                    <input type="number" id="prix_4" name="prix_4" value="<?php echo $offer['prix_4']; ?>" required>
                </div>
            </div>

            <!-- التواريخ -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="date_depart">تاريخ المغادرة</label>
                    <input type="date" id="date_depart" name="date_depart" value="<?php echo $offer['date_depart']; ?>" required>
                </div>
                <div class="form-group">
                    <label for="date_retour">تاريخ العودة</label>
                    <input type="date" id="date_retour" name="date_retour" value="<?php echo $offer['date_retour']; ?>" required>
                </div>
            </div>

            <!-- معلومات الطيران -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="aeroport_depart">مطار المغادرة</label>
                    <input type="text" id="aeroport_depart" name="aeroport_depart" value="<?php echo htmlspecialchars($offer['aeroport_depart']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="compagnie_aerienne">شركة الطيران</label>
                    <input type="text" id="compagnie_aerienne" name="compagnie_aerienne" value="<?php echo htmlspecialchars($offer['compagnie_aerienne']); ?>" required>
                </div>
            </div>

            <!-- معلومات الإقامة -->
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="nom_hotel">اسم الفندق</label>
                    <input type="text" id="nom_hotel" name="nom_hotel" value="<?php echo htmlspecialchars($offer['nom_hotel']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="numero_chambre">رقم الغرفة</label>
                    <input type="text" id="numero_chambre" name="numero_chambre" value="<?php echo htmlspecialchars($offer['numero_chambre']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="distance_haram">المسافة من الحرم (متر)</label>
                    <input type="number" id="distance_haram" name="distance_haram" value="<?php echo $offer['distance_haram']; ?>" required>
                </div>
            </div>

            <!-- الخدمات -->
            <h3 style="margin: 20px 0;">الخدمات المشمولة</h3>
            <div class="checkbox-group">
                <div class="checkbox-item">
                    <input type="checkbox" id="service_guide" name="service_guide" <?php echo $offer['service_guide'] ? 'checked' : ''; ?>>
                    <label for="service_guide">خدمة المرشد</label>
                </div>
                <div class="checkbox-item">
                    <input type="checkbox" id="service_transport" name="service_transport" <?php echo $offer['service_transport'] ? 'checked' : ''; ?>>
                    <label for="service_transport">خدمة النقل</label>
                </div>
                <div class="checkbox-item">
                    <input type="checkbox" id="service_nourriture" name="service_nourriture" <?php echo $offer['service_nourriture'] ? 'checked' : ''; ?>>
                    <label for="service_nourriture">خدمة الطعام</label>
                </div>
                <div class="checkbox-item">
                    <input type="checkbox" id="service_assurance" name="service_assurance" <?php echo $offer['service_assurance'] ? 'checked' : ''; ?>>
                    <label for="service_assurance">خدمة التأمين</label>
                </div>
            </div>

            <!-- الهدايا -->
            <h3 style="margin: 20px 0;">الهدايا المشمولة</h3>
            <div class="checkbox-group">
                <div class="checkbox-item">
                    <input type="checkbox" id="cadeau_bag" name="cadeau_bag" <?php echo $offer['cadeau_bag'] ? 'checked' : ''; ?>>
                    <label for="cadeau_bag">حقيبة هدية</label>
                </div>
                <div class="checkbox-item">
                    <input type="checkbox" id="cadeau_zamzam" name="cadeau_zamzam" <?php echo $offer['cadeau_zamzam'] ? 'checked' : ''; ?>>
                    <label for="cadeau_zamzam">ماء زمزم</label>
                </div>
                <div class="checkbox-item">
                    <input type="checkbox" id="cadeau_parapluie" name="cadeau_parapluie" <?php echo $offer['cadeau_parapluie'] ? 'checked' : ''; ?>>
                    <label for="cadeau_parapluie">مظلة</label>
                </div>
                <div class="checkbox-item">
                    <input type="checkbox" id="cadeau_autre" name="cadeau_autre" <?php echo $offer['cadeau_autre'] ? 'checked' : ''; ?>>
                    <label for="cadeau_autre">هدايا أخرى</label>
                </div>
            </div>

            <!-- صورة العرض الرئيسية -->
            <div class="form-group">
                <label>الصورة الرئيسية الحالية:</label><br>
                <?php if (!empty($offer['image_offre_principale']) && file_exists('../uploads/offers/' . $offer['image_offre_principale'])): ?>
                    <img src="../uploads/offers/<?php echo htmlspecialchars($offer['image_offre_principale']); ?>" alt="الصورة الرئيسية" style="max-width:200px; border-radius:10px; margin-bottom:10px;">
                <?php else: ?>
                    <span style="color:#888;">لا توجد صورة رئيسية</span>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="image_offre_principale">تغيير الصورة الرئيسية</label>
                <input type="file" id="image_offre_principale" name="image_offre_principale" accept="image/*">
                <?php
                // معالجة رفع الصورة الرئيسية مع باقي المعلومات
                $mainImageError = '';
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_offre_principale']) && $_FILES['image_offre_principale']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
                    if ($_FILES['image_offre_principale']['error'] === UPLOAD_ERR_OK && in_array($_FILES['image_offre_principale']['type'], $allowedTypes)) {
                        $ext = pathinfo($_FILES['image_offre_principale']['name'], PATHINFO_EXTENSION);
                        $newName = 'main_' . $offer_id . '_' . time() . '.' . $ext;
                        $uploadDir = '../uploads/offers/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }
                        $dest = $uploadDir . $newName;
                        if (move_uploaded_file($_FILES['image_offre_principale']['tmp_name'], $dest)) {
                            // حذف الصورة القديمة إذا وجدت
                            if (!empty($offer['image_offre_principale']) && file_exists($uploadDir . $offer['image_offre_principale'])) {
                                @unlink($uploadDir . $offer['image_offre_principale']);
                            }
                            // تحديث اسم الصورة في قاعدة البيانات
                            executeQuery("UPDATE offres SET image_offre_principale = :img WHERE id = :id", [
                                'img' => $newName,
                                'id' => $offer_id
                            ]);
                            $offer['image_offre_principale'] = $newName;
                        } else {
                            $mainImageError = 'حدث خطأ أثناء رفع الصورة الرئيسية.';
                        }
                    } else {
                        $mainImageError = 'نوع الصورة غير مدعوم. الرجاء رفع صورة jpg أو png أو webp.';
                    }
                }
                if (!empty($mainImageError)): ?>
                    <div style="color:red;"><?php echo $mainImageError; ?></div>
                <?php endif; ?>
            </div>

            <!-- عرض ذهبي -->
            <div class="checkbox-group" style="margin: 20px 0;">
                <div class="checkbox-item">
                    <input type="checkbox" id="est_doree" name="est_doree" <?php echo $offer['est_doree'] ? 'checked' : ''; ?>>
                    <label for="est_doree">عرض ذهبي</label>
                </div>
            </div>

            <div class="btn-group">
                <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                <a href="details.php?id=<?php echo $offer_id; ?>" class="btn btn-secondary">إلغاء</a>
            </div>
        </form>

        <!-- زر إضافة صور أخرى -->
        <div style="margin-top:40px;">
            <form method="get" action="edit_images.php" style="display:inline;">
                <input type="hidden" name="id" value="<?php echo $offer_id; ?>">
                <button type="submit" class="btn btn-primary">
                    هل تريد إضافة صور أخرى؟
                </button>
            </form>
        </div>
    </div>
</body>
</html>
