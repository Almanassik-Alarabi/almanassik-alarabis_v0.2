<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/agency_errors.log');

// الاتصال بقاعدة البيانات

require_once '../../includes/config.php';

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

// جلب بيانات الصور (جدول offer_images)
$images = [];
if ($offer) {
    $stmt = executeQuery("SELECT image FROM offer_images WHERE offer_id = :id", ['id' => $offer_id]);
    if ($stmt) {
        while ($row = $stmt->fetch()) {
            $images[] = $row['image'];
        }
    }
}

// تجهيز البيانات لجافاسكريبت
$offerData = [
    "id" => null,
    "agence_id" => null,
    "titre" => "",
    "description" => "",
    "prix_base" => 0,
    "date_depart" => "",
    "date_retour" => "",
    "est_doree" => false,
    "service_guide" => false,
    "service_transport" => false,
    "service_nourriture" => false,
    "service_assurance" => false,
    "aeroport_depart" => "",
    "compagnie_aerienne" => "",
    "type_voyage" => "",
    "nom_hotel" => "",
    "numero_chambre" => "",
    "distance_haram" => "",
    "prix_2" => 0,
    "prix_3" => 0,
    "prix_4" => 0,
    "cadeau_bag" => false,
    "cadeau_zamzam" => false,
    "cadeau_parapluie" => false,
    "cadeau_autre" => false,
    "image_offre_principale" => ""
];
if ($offer) {
    $offerData = [
        "id" => $offer['id'],
        "agence_id" => $offer['agence_id'],
        "titre" => $offer['titre'],
        "description" => $offer['description'],
        "prix_base" => $offer['prix_base'],
        "date_depart" => $offer['date_depart'],
        "date_retour" => $offer['date_retour'],
        "est_doree" => (bool)$offer['est_doree'],
        "service_guide" => (bool)$offer['service_guide'],
        "service_transport" => (bool)$offer['service_transport'],
        "service_nourriture" => (bool)$offer['service_nourriture'],
        "service_assurance" => (bool)$offer['service_assurance'],
        "aeroport_depart" => $offer['aeroport_depart'],
        "compagnie_aerienne" => $offer['compagnie_aerienne'],
        "type_voyage" => $offer['type_voyage'],
        "nom_hotel" => $offer['nom_hotel'],
        "numero_chambre" => $offer['numero_chambre'],
        "distance_haram" => $offer['distance_haram'],
        "prix_2" => $offer['prix_2'],
        "prix_3" => $offer['prix_3'],
        "prix_4" => $offer['prix_4'],
        "cadeau_bag" => (bool)$offer['cadeau_bag'],
        "cadeau_zamzam" => (bool)$offer['cadeau_zamzam'],
        "cadeau_parapluie" => (bool)$offer['cadeau_parapluie'],
        "cadeau_autre" => (bool)$offer['cadeau_autre'],
        "image_offre_principale" => $offer['image_offre_principale']
    ];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تفاصيل العرض</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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
            overflow: hidden;
            animation: slideIn 0.8s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .header {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            color: white;
            padding: 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 10s linear infinite;
        }

        @keyframes rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .header .subtitle {
            font-size: 1.2em;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .content {
            padding: 40px;
        }

        .offer-grid {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 40px;
            margin-bottom: 40px;
        }

        .main-details {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 30px;
            border: 1px solid #e9ecef;
        }

        .pricing-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }

        .detail-section {
            margin-bottom: 30px;
        }

        .detail-section h3 {
            color: #2c3e50;
            font-size: 1.4em;
            margin-bottom: 15px;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: #555;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-value {
            font-weight: 500;
            color: #333;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .service-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .service-item.active {
            background: #d1edff;
            color: #0066cc;
        }

        .service-item.inactive {
            background: #f8f9fa;
            color: #999;
        }

        .gifts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .gift-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .gift-item.active {
            background: #e8f5e8;
            color: #28a745;
        }

        .gift-item.inactive {
            background: #f8f9fa;
            color: #999;
        }

        .price-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 20px;
        }

        .price-item {
            background: rgba(255,255,255,0.2);
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .price-item h4 {
            margin-bottom: 5px;
            font-size: 1.1em;
        }

        .price-item .price {
            font-size: 1.5em;
            font-weight: bold;
        }

        .golden-badge {
            background: linear-gradient(45deg, #ffd700, #ffed4a);
            color: #333;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .image-gallery {
            margin-top: 40px;
        }

        .image-gallery h3 {
            color: #2c3e50;
            font-size: 1.4em;
            margin-bottom: 20px;
            text-align: center;
        }

        .images-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .image-placeholder {
            aspect-ratio: 16/9;
            background: linear-gradient(135deg, #f0f0f0 0%, #e0e0e0 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 1.2em;
            border: 2px dashed #ccc;
            transition: all 0.3s ease;
        }

        .image-placeholder:hover {
            background: linear-gradient(135deg, #e8f4f8 0%, #d1ecf1 100%);
            border-color: #3498db;
            color: #3498db;
        }

        .edit-btn {
            position: fixed;
            bottom: 30px;
            left: 30px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 50px;
            padding: 15px 25px;
            font-size: 1.1em;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .edit-btn:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4);
        }

        @media (max-width: 768px) {
            .offer-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .services-grid, .gifts-grid {
                grid-template-columns: 1fr;
            }
            
            .price-grid {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 2em;
            }
            
            .content {
                padding: 20px;
            }
        }

        .loading {
            text-align: center;
            padding: 50px;
            color: #666;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>تفاصيل العرض</h1>
            <p class="subtitle">عرض شامل لرحلات الحج والعمرة</p>
        </div>  

        <div class="content">
            <div id="loading" class="loading">
                <div class="spinner"></div>
                <p>جاري تحميل تفاصيل العرض...</p>
            </div>

            <div id="offer-content" style="display: none;">
                <div class="offer-grid">
                    <div class="main-details">
                        <div class="detail-section">
                            <h3>
                                <i class="fas fa-info-circle"></i>
                                المعلومات الأساسية
                            </h3>
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-tag"></i>
                                    العنوان
                                </span>
                                <span class="detail-value" id="titre"></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-align-left"></i>
                                    الوصف
                                </span>
                                <span class="detail-value" id="description"></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-route"></i>
                                    نوع الرحلة
                                </span>
                                <span class="detail-value" id="type_voyage"></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-star"></i>
                                    العرض الذهبي
                                </span>
                                <span class="detail-value" id="est_doree"></span>
                            </div>
                        </div>

                        <div class="detail-section">
                            <h3>
                                <i class="fas fa-calendar-alt"></i>
                                التواريخ
                            </h3>
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-plane-departure"></i>
                                    تاريخ المغادرة
                                </span>
                                <span class="detail-value" id="date_depart"></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-plane-arrival"></i>
                                    تاريخ العودة
                                </span>
                                <span class="detail-value" id="date_retour"></span>
                            </div>
                        </div>

                        <div class="detail-section">
                            <h3>
                                <i class="fas fa-plane"></i>
                                معلومات الطيران
                            </h3>
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-map-marker-alt"></i>
                                    مطار المغادرة
                                </span>
                                <span class="detail-value" id="aeroport_depart"></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-building"></i>
                                    شركة الطيران
                                </span>
                                <span class="detail-value" id="compagnie_aerienne"></span>
                            </div>
                        </div>

                        <div class="detail-section">
                            <h3>
                                <i class="fas fa-hotel"></i>
                                معلومات الإقامة
                            </h3>
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-bed"></i>
                                    اسم الفندق
                                </span>
                                <span class="detail-value" id="nom_hotel"></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-door-open"></i>
                                    رقم الغرفة
                                </span>
                                <span class="detail-value" id="numero_chambre"></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-walking"></i>
                                    المسافة من الحرم
                                </span>
                                <span class="detail-value" id="distance_haram"></span>
                            </div>
                        </div>
                    </div>

                    <div class="pricing-card">
                        <h3>
                            <i class="fas fa-money-bill-wave"></i>
                            الأسعار
                        </h3>
                        <div class="price-grid">
                            <div class="price-item">
                                <h4>سعر الأساس</h4>
                                <div class="price" id="prix_base"></div>
                            </div>
                            <div class="price-item">
                                <h4>سعر الشخصين</h4>
                                <div class="price" id="prix_2"></div>
                            </div>
                            <div class="price-item">
                                <h4>سعر الثلاثة</h4>
                                <div class="price" id="prix_3"></div>
                            </div>
                            <div class="price-item">
                                <h4>سعر الأربعة</h4>
                                <div class="price" id="prix_4"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="detail-section">
                    <h3>
                        <i class="fas fa-concierge-bell"></i>
                        الخدمات المشمولة
                    </h3>
                    <div class="services-grid">
                        <div class="service-item" id="service_guide">
                            <i class="fas fa-user-tie"></i>
                            <span>خدمة المرشد</span>
                        </div>
                        <div class="service-item" id="service_transport">
                            <i class="fas fa-bus"></i>
                            <span>خدمة النقل</span>
                        </div>
                        <div class="service-item" id="service_nourriture">
                            <i class="fas fa-utensils"></i>
                            <span>خدمة الطعام</span>
                        </div>
                        <div class="service-item" id="service_assurance">
                            <i class="fas fa-shield-alt"></i>
                            <span>خدمة التأمين</span>
                        </div>
                    </div>
                </div>

                <div class="detail-section">
                    <h3>
                        <i class="fas fa-gift"></i>
                        الهدايا المشمولة
                    </h3>
                    <div class="gifts-grid">
                        <div class="gift-item" id="cadeau_bag">
                            <i class="fas fa-shopping-bag"></i>
                            <span>حقيبة هدية</span>
                        </div>
                        <div class="gift-item" id="cadeau_zamzam">
                            <i class="fas fa-tint"></i>
                            <span>ماء زمزم</span>
                        </div>
                        <div class="gift-item" id="cadeau_parapluie">
                            <i class="fas fa-umbrella"></i>
                            <span>مظلة</span>
                        </div>
                        <div class="gift-item" id="cadeau_autre">
                            <i class="fas fa-surprise"></i>
                            <span>هدايا أخرى</span>
                        </div>
                    </div>
                </div>

                <div class="image-gallery">
                    <h3>
                        <i class="fas fa-images"></i>
                        معرض الصور
                    </h3>
                    <div class="images-container" id="imagesContainer">
                        <!-- الصور ستتم إضافتها هنا -->
                    </div>
                </div>
            </div>
        </div>
    </div>

            <button class="edit-btn" onclick="editOffer()">
                <i class="fas fa-edit"></i>
                تعديل العرض
            </button>

            <script>
        // بيانات العرض من PHP
        const offerData = <?php echo json_encode($offerData, JSON_UNESCAPED_UNICODE); ?>;

        function formatPrice(price) {
            return new Intl.NumberFormat('ar-DZ', {
                style: 'currency',
                currency: 'DZD',
                minimumFractionDigits: 0
            }).format(price);
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('ar-DZ', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }

        function displayOfferData(data) {
            // المعلومات الأساسية
            document.getElementById('titre').textContent = data.titre;
            document.getElementById('description').textContent = data.description;
            document.getElementById('type_voyage').textContent = data.type_voyage === 'Hajj' ? 'الحج' : 'العمرة';
            
            // العرض الذهبي
            const estDoreeElement = document.getElementById('est_doree');
            if (data.est_doree) {
                estDoreeElement.innerHTML = '<span class="golden-badge"><i class="fas fa-crown"></i> عرض ذهبي</span>';
            } else {
                estDoreeElement.textContent = 'عرض عادي';
            }

            // التواريخ
            document.getElementById('date_depart').textContent = formatDate(data.date_depart);
            document.getElementById('date_retour').textContent = formatDate(data.date_retour);

            // معلومات الطيران
            document.getElementById('aeroport_depart').textContent = data.aeroport_depart;
            document.getElementById('compagnie_aerienne').textContent = data.compagnie_aerienne;

            // معلومات الإقامة
            document.getElementById('nom_hotel').textContent = data.nom_hotel;
            document.getElementById('numero_chambre').textContent = data.numero_chambre;
            document.getElementById('distance_haram').textContent = data.distance_haram + ' متر';

            // الأسعار
            document.getElementById('prix_base').textContent = formatPrice(data.prix_base);
            document.getElementById('prix_2').textContent = formatPrice(data.prix_2);
            document.getElementById('prix_3').textContent = formatPrice(data.prix_3);
            document.getElementById('prix_4').textContent = formatPrice(data.prix_4);

            // الخدمات
            const services = ['service_guide', 'service_transport', 'service_nourriture', 'service_assurance'];
            services.forEach(service => {
                const element = document.getElementById(service);
                if (data[service]) {
                    element.classList.add('active');
                    element.classList.remove('inactive');
                } else {
                    element.classList.add('inactive');
                    element.classList.remove('active');
                }
            });

            // الهدايا
            const gifts = ['cadeau_bag', 'cadeau_zamzam', 'cadeau_parapluie', 'cadeau_autre'];
            gifts.forEach(gift => {
                const element = document.getElementById(gift);
                if (data[gift]) {
                    element.classList.add('active');
                    element.classList.remove('inactive');
                } else {
                    element.classList.add('inactive');
                    element.classList.remove('active');
                }
            });

            // معرض الصور
            const imagesContainer = document.getElementById('imagesContainer');
            imagesContainer.innerHTML = '';
            if (data.images && data.images.length > 0) {
                data.images.forEach((image, index) => {
                    const imageDiv = document.createElement('div');
                    imageDiv.className = 'image-placeholder';
                    // تعديل المسار حسب مكان تخزين الصور الفعلي
                    imageDiv.innerHTML = `<img src="../uploads/offers/${image}" alt="Offer Image" style="max-width:100%; max-height:100%; border-radius:8px; display:block; margin:0 auto 8px;" onerror="this.onerror=null;this.style.display='none';"><div style="font-size:0.9em;color:#888;"><i class="fas fa-image"></i> ${image}</div>`;
                    imagesContainer.appendChild(imageDiv);
                });
            } else {
                imagesContainer.innerHTML = '<div class="image-placeholder"><i class="fas fa-image"></i> لا توجد صور متاحة</div>';
            }
        }

        function editOffer() {
            // إعادة التوجيه إلى صفحة تعديل العرض مع تمرير معرف العرض
            if (offerData && offerData.id) {
                window.location.href = 'edit.php?id=' + offerData.id;
            } else {
                alert('لا يمكن تعديل العرض: معرف العرض غير متوفر.');
            }
        }

        // تحميل البيانات عند تحميل الصفحة
        window.addEventListener('load', function() {
            setTimeout(() => {
                if (offerData && Object.keys(offerData).length > 0) {
                    displayOfferData(offerData);
                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('offer-content').style.display = 'block';
                } else {
                    document.getElementById('loading').innerHTML = '<p>لم يتم العثور على تفاصيل العرض.</p>';
                }
            }, 800);
        });
    </script>
</body>
</html>