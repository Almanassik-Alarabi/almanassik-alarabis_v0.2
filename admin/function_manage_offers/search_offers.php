<?php
// بحث AJAX عن العروض بالاسم أو رقم العرض
header('Content-Type: text/html; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) exit('Unauthorized');

require_once '../../includes/config.php';

$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$whereArr = [];
$params = [];

if ($search !== '') {
    $whereArr[] = "(o.titre ILIKE :search OR CAST(o.id AS TEXT) ILIKE :search OR a.nom_agence ILIKE :search)";
    $params[':search'] = "%$search%";
}
if (!empty($_GET['type'])) {
    $whereArr[] = "o.type_voyage = :type";
    $params[':type'] = $_GET['type'];
}
if (!empty($_GET['agency'])) {
    $whereArr[] = "o.agence_id = :agency";
    $params[':agency'] = $_GET['agency'];
}

$status = isset($_GET['status']) ? $_GET['status'] : '';
if ($status === 'golden') {
    $whereArr[] = "o.est_doree IS TRUE";
} elseif ($status === 'regular') {
    $whereArr[] = "(o.est_doree IS FALSE OR o.est_doree IS NULL)";
}

$where = '';
if (count($whereArr) > 0) {
    $where = 'WHERE ' . implode(' AND ', $whereArr);
}
$sql = "SELECT o.*, a.nom_agence AS agency_name FROM offres o LEFT JOIN agences a ON o.agence_id = a.id $where ORDER BY o.est_doree DESC, o.id DESC LIMIT 50";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($offers)) {
    echo '<tr><td colspan="12" style="text-align:center;color:#b00;font-weight:bold;">لا توجد نتائج</td></tr>';
    exit;
}
foreach ($offers as $offer) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars($offer['id']) . '</td>';
    echo '<td>';
    if (!empty($offer['image_offre_principale'])) {
        echo '<img src="' . htmlspecialchars($offer['image_offre_principale']) . '" class="offer-thumbnail" alt="Offer Image">';
    } else {
        echo '<div class="no-image">لا صورة</div>';
    }
    echo '</td>';
    echo '<td>' . htmlspecialchars($offer['titre'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($offer['agency_name'] ?? 'N/A') . '</td>';
    echo '<td><span class="price">' . number_format($offer['prix_base'], 2) . '</span></td>';
    echo '<td><span class="offer-type ' . (isset($offer['type_voyage']) ? strtolower($offer['type_voyage']) : '') . '">' . htmlspecialchars($offer['type_voyage'] ?? '') . '</span></td>';
    echo '<td>';
    if (!empty($offer['est_doree'])) {
        echo '<span class="golden-badge" style="color:#ffd700;font-weight:bold;">ذهبي</span>';
    } else {
        echo '<span class="badge" style="color:#14532d;">عادي</span>';
    }
    echo '</td>';
    echo '<td>' . htmlspecialchars($offer['date_depart'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($offer['date_retour'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($offer['aeroport_depart'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($offer['compagnie_aerienne'] ?? '') . '</td>';
    echo '<td>';
    echo '<div class="action-buttons">';
    echo '<button type="button" class="btn-action btn-view" title="عرض التفاصيل" onclick="viewRoomDetails(' . $offer['id'] . ')">';
    echo '<i class="fas fa-eye"></i>';
    echo '</button>';
    echo '<form method="post" action="manage_offers.php" style="display:inline;" onsubmit="return confirm(\'هل أنت متأكد من حذف هذا العرض؟\');">';
    echo '<input type="hidden" name="action" value="delete_offer">';
    echo '<input type="hidden" name="offer_id" value="' . $offer['id'] . '">';
    echo '<button type="submit" class="btn-action btn-delete" title="حذف">';
    echo '<i class="fas fa-trash"></i>';
    echo '</button>';
    echo '</form>';
    echo '</div>';
    echo '</td>';
    echo '</tr>';
}
