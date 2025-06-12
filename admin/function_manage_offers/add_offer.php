<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/agency_errors.log');

require_once '../../includes/config.php';

// معالجة إرسال النموذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // معالجة الحقول النصية الفارغة
        $numero_chambre = isset($_POST['numero_chambre']) ? $_POST['numero_chambre'] : '';

        // استخدم agence_id المختارة من النموذج
        if (empty($_POST['agence_id'])) {
            throw new Exception("يرجى اختيار الوكالة.");
        }
        $agence_id = intval($_POST['agence_id']);

        // تحقق من وجود agence_id صالح في جدول agences
        $agenceCheck = executeQuery("SELECT id FROM agences WHERE id = :id", ['id' => $agence_id]);
        if (!$agenceCheck || !$agenceCheck->fetch()) {
            throw new Exception("الوكالة المختارة غير موجودة.");
        }

        $offerData = [
            'agence_id' => $agence_id,
            'titre' => $_POST['titre'],
            'description' => $_POST['description'],
            'prix_base' => floatval($_POST['prix_base']),
            'date_depart' => $_POST['date_depart'],
            'date_retour' => $_POST['date_retour'],
            'est_doree' => isset($_POST['est_doree']) ? 't' : 'f',
            'service_guide' => isset($_POST['service_guide']) ? 't' : 'f',
            'service_transport' => isset($_POST['service_transport']) ? 't' : 'f',
            'service_nourriture' => isset($_POST['service_nourriture']) ? 't' : 'f',
            'service_assurance' => isset($_POST['service_assurance']) ? 't' : 'f',
            'aeroport_depart' => $_POST['aeroport_depart'],
            'compagnie_aerienne' => $_POST['compagnie_aerienne'],
            'type_voyage' => $_POST['type_voyage'],
            'nom_hotel' => $_POST['nom_hotel'],
            'numero_chambre' => $numero_chambre,
            'distance_haram' => floatval($_POST['distance_haram']),
            'prix_2' => floatval($_POST['prix_2']),
            'prix_3' => floatval($_POST['prix_3']),
            'prix_4' => floatval($_POST['prix_4']),
            'cadeau_bag' => isset($_POST['cadeau_bag']) ? 't' : 'f',
            'cadeau_zamzam' => isset($_POST['cadeau_zamzam']) ? 't' : 'f',
            'cadeau_parapluie' => isset($_POST['cadeau_parapluie']) ? 't' : 'f',
            'cadeau_autre' => isset($_POST['cadeau_autre']) ? 't' : 'f'
        ];

        // معالجة الصورة الرئيسية
        if (isset($_FILES['image_offre_principale']) && $_FILES['image_offre_principale']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = dirname(dirname(dirname(__FILE__))) . '/uploads/offers/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $ext = pathinfo($_FILES['image_offre_principale']['name'], PATHINFO_EXTENSION);
            $fileName = 'main_' . time() . '.' . $ext;
            $uploadFile = $uploadDir . $fileName;

            if (move_uploaded_file($_FILES['image_offre_principale']['tmp_name'], $uploadFile)) {
                $offerData['image_offre_principale'] = $fileName;
            }
        }

        // إدراج العرض في قاعدة البيانات
        $columns = implode(", ", array_keys($offerData));
        $values = ":" . implode(", :", array_keys($offerData));
        $sql = "INSERT INTO offres ($columns) VALUES ($values) RETURNING id";
        
        $stmt = $GLOBALS['pdo']->prepare($sql);
        if ($stmt->execute($offerData)) {
            $newOfferId = $stmt->fetchColumn();
            
            // معالجة الصور الإضافية
            if (isset($_FILES['additional_images'])) {
                foreach ($_FILES['additional_images']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['additional_images']['error'][$key] === UPLOAD_ERR_OK) {
                        $ext = pathinfo($_FILES['additional_images']['name'][$key], PATHINFO_EXTENSION);
                        $imageName = 'offer_' . $newOfferId . '_' . time() . '_' . $key . '.' . $ext;
                        
                        if (move_uploaded_file($tmp_name, $uploadDir . $imageName)) {
                            executeQuery(
                                "INSERT INTO offer_images (offer_id, image) VALUES (:offer_id, :image)",
                                ['offer_id' => $newOfferId, 'image' => $imageName]
                            );
                        }
                    }
                }
            }
            
            $successMessage = "تمت إضافة العرض بنجاح!";
            header("Location: details.php?id=" . $newOfferId);
            exit();
        }
    } catch (Exception $e) {
        error_log($e->getMessage());
        $errorMessage = "حدث خطأ أثناء إضافة العرض. يرجى المحاولة مرة أخرى.";
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إضافة عرض جديد</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .form-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            margin-bottom: 15px;
        }
        .form-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 1rem;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .checkbox-item {
            display: flex;
            align-items: center;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            border: 2px solid transparent;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .checkbox-item input[type="checkbox"] {
            margin-left: 10px;
            transform: scale(1.2);
        }
        .checkbox-item label {
            margin: 0;
            cursor: pointer;
            font-weight: 500;
        }
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102,126,234,0.4);
        }
        .image-upload {
            border: 2px dashed #667eea;
            padding: 40px;
            text-align: center;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8f9fa;
            margin-bottom: 20px;
        }
        .image-upload i {
            font-size: 3rem;
            color: #667eea;
            margin-bottom: 15px;
        }
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-kaaba"></i> نظام إدارة عروض الحج والعمرة</h1>
            <p>منصة شاملة لإدارة وعرض عروض الحج والعمرة</p>
        </div>
        <!-- إضافة عرض جديد -->
        <div id="add-offer" class="tab-content active">
            <div class="form-container">
            <h2 style="margin-bottom: 30px; color: #2c3e50; font-size: 1.8rem;">
                <i class="fas fa-plus-circle"></i> إضافة عرض جديد
            </h2>
            <?php
            // جلب قائمة الوكالات من قاعدة البيانات
            $agences = [];
            try {
                $stmt = $GLOBALS['pdo']->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence ASC");
                $agences = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $agences = [];
            }
            ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert error"><?php echo $errorMessage; ?></div>
            <?php endif; ?>
            <?php if (!empty($successMessage)): ?>
                <div class="alert success"><?php echo $successMessage; ?></div>
            <?php endif; ?>
            <form method="POST" action="" enctype="multipart/form-data" id="offerForm">
                <div class="form-grid">
                <div class="form-group">
                    <label for="agence_id">الوكالة</label>
                    <select id="agence_id" name="agence_id" required>
                    <option value="">اختر الوكالة</option>
                    <?php foreach ($agences as $agence): ?>
                        <option value="<?php echo htmlspecialchars($agence['id']); ?>">
                        <?php echo htmlspecialchars($agence['nom_agence']); ?>
                        </option>
                    <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="titre">عنوان العرض</label>
                    <input type="text" id="titre" name="titre" required>
                </div>
                <div class="form-group">
                    <label for="type_voyage">نوع الرحلة</label>
                    <select id="type_voyage" name="type_voyage" required>
                    <option value="">اختر نوع الرحلة</option>
                    <option value="Hajj">حج</option>
                    <option value="Omra">عمرة</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="prix_base">السعر الأساسي (دج)</label>
                    <input type="number" id="prix_base" name="prix_base" required>
                </div>
                <div class="form-group">
                    <label for="date_depart">تاريخ المغادرة</label>
                    <input type="date" id="date_depart" name="date_depart" required>
                </div>
                <div class="form-group">
                    <label for="date_retour">تاريخ العودة</label>
                    <input type="date" id="date_retour" name="date_retour" required>
                </div>
                <div class="form-group">
                    <label for="aeroport_depart">مطار المغادرة</label>
                    <input type="text" id="aeroport_depart" name="aeroport_depart">
                </div>
                <div class="form-group">
                    <label for="compagnie_aerienne">شركة الطيران</label>
                    <input type="text" id="compagnie_aerienne" name="compagnie_aerienne">
                </div>
                <div class="form-group">
                    <label for="nom_hotel">اسم الفندق</label>
                    <input type="text" id="nom_hotel" name="nom_hotel">
                </div>
                <div class="form-group">
                    <label for="numero_chambre">رقم الغرفة</label>
                    <input type="text" id="numero_chambre" name="numero_chambre">
                </div>
                <div class="form-group">
                    <label for="distance_haram">المسافة من الحرم (كم)</label>
                    <input type="number" step="0.1" id="distance_haram" name="distance_haram">
                </div>
                <div class="form-group">
                    <label for="prix_2">سعر الغرفة المزدوجة</label>
                    <input type="number" id="prix_2" name="prix_2">
                </div>
                <div class="form-group">
                    <label for="prix_3">سعر الغرفة الثلاثية</label>
                    <input type="number" id="prix_3" name="prix_3">
                </div>
                <div class="form-group">
                    <label for="prix_4">سعر الغرفة الرباعية</label>
                    <input type="number" id="prix_4" name="prix_4">
                </div>
                </div>
                <div class="form-group">
                <label for="description">وصف العرض</label>
                <textarea id="description" name="description" placeholder="اكتب وصفاً مفصلاً للعرض..."></textarea>
                </div>
                <div class="form-group">
                <label>
                    <input type="checkbox" id="est_doree" name="est_doree">
                    عرض ذهبي
                </label>
                </div>
                <h3 style="margin: 30px 0 20px 0; color: #2c3e50;">الخدمات المتضمنة</h3>
                <div class="checkbox-group">
                <div class="checkbox-item">
                    <input type="checkbox" id="service_guide" name="service_guide">
                    <label for="service_guide">
                    <i class="fas fa-user-tie"></i> خدمة المرشد
                    </label>
                </div>
                <div class="checkbox-item">
                    <input type="checkbox" id="service_transport" name="service_transport">
                    <label for="service_transport">
                    <i class="fas fa-bus"></i> خدمة النقل
                    </label>
                </div>
                <div class="checkbox-item">
                    <input type="checkbox" id="service_nourriture" name="service_nourriture">
                    <label for="service_nourriture">
                    <i class="fas fa-utensils"></i> الوجبات
                    </label>
                </div>
                <div class="checkbox-item">
                    <input type="checkbox" id="service_assurance" name="service_assurance">
                    <label for="service_assurance">
                    <i class="fas fa-shield-alt"></i> التأمين
                    </label>
                </div>
                </div>
                <h3 style="margin: 30px 0 20px 0; color: #2c3e50;">الهدايا المجانية</h3>
                <div class="checkbox-group">
                <div class="checkbox-item">
                    <input type="checkbox" id="cadeau_bag" name="cadeau_bag">
                    <label for="cadeau_bag">
                    <i class="fas fa-shopping-bag"></i> حقيبة سفر
                    </label>
                </div>
                <div class="checkbox-item">
                    <input type="checkbox" id="cadeau_zamzam" name="cadeau_zamzam">
                    <label for="cadeau_zamzam">
                    <i class="fas fa-tint"></i> ماء زمزم
                    </label>
                </div>
                <div class="checkbox-item">
                    <input type="checkbox" id="cadeau_parapluie" name="cadeau_parapluie">
                    <label for="cadeau_parapluie">
                    <i class="fas fa-umbrella"></i> مظلة
                    </label>
                </div>
                <div class="checkbox-item">
                    <input type="checkbox" id="cadeau_autre" name="cadeau_autre">
                    <label for="cadeau_autre">
                    <i class="fas fa-gift"></i> هدايا أخرى
                    </label>
                </div>
                </div>
                <div class="form-group" style="margin-top: 30px;">
                <label>صورة العرض الرئيسية</label>
                <div class="image-upload" onclick="document.getElementById('image_upload').click()">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p>اضغط لرفع الصورة</p>
                    <input type="file" id="image_upload" name="image_offre_principale" accept="image/*" style="display: none;">
                </div>
                </div>
                <button type="submit" class="btn" style="margin-top: 30px;">
                <i class="fas fa-save"></i> حفظ العرض
                </button>
            </form>
            </div>
        </div>
    </div>
</body>
</html>