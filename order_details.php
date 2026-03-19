<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'db.php';
include 'header.php';

if (empty($_SESSION['user_id'])) {
    echo "<div class='container py-4'><div class='alert alert-danger'>يجب تسجيل الدخول.</div></div>";
    include 'footer.php';
    exit;
}

/* =========================
   Helpers
========================= */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function has_col($mysqli, $table, $col)
{
    $q = $mysqli->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    if (!$q) {
        return null;
    }
    $q->bind_param("ss", $table, $col);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    return $row ? $row['COLUMN_TYPE'] : null;
}

function table_exists($mysqli, $table): bool
{
    $q = $mysqli->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? LIMIT 1");
    if (!$q) {
        return false;
    }
    $q->bind_param("s", $table);
    $q->execute();
    $exists = (bool)$q->get_result()->fetch_assoc();
    $q->close();
    return $exists;
}


function record_order_log($mysqli, int $orderId, string $action): void
{
    if ($orderId <= 0 || $action === '' || !table_exists($mysqli, 'order_logs')) {
        return;
    }

    $cols = [];
    $vals = [];
    $types = '';
    $params = [];

    if (has_col($mysqli, 'order_logs', 'order_id')) {
        $cols[] = 'order_id';
        $vals[] = '?';
        $types .= 'i';
        $params[] = $orderId;
    }

    if (has_col($mysqli, 'order_logs', 'action')) {
        $cols[] = 'action';
        $vals[] = '?';
        $types .= 's';
        $params[] = $action;
    }

    if (has_col($mysqli, 'order_logs', 'created_at')) {
        $cols[] = 'created_at';
        $vals[] = 'NOW()';
    }

    if (!$cols) {
        return;
    }

    $sql = "INSERT INTO order_logs (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return;
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    @$stmt->execute();
    $stmt->close();
}

function fetch_existing_seller_rating($mysqli, int $orderId, int $raterId, int $ratedUserId): ?array
{
    if ($orderId <= 0 || $raterId <= 0 || $ratedUserId <= 0 || !table_exists($mysqli, 'ratings')) {
        return null;
    }

    $where = [];
    $types = '';
    $params = [];

    if (has_col($mysqli, 'ratings', 'order_id')) {
        $where[] = 'order_id=?';
        $types .= 'i';
        $params[] = $orderId;
    }
    if (has_col($mysqli, 'ratings', 'rater_id')) {
        $where[] = 'rater_id=?';
        $types .= 'i';
        $params[] = $raterId;
    }
    if (has_col($mysqli, 'ratings', 'rated_user_id')) {
        $where[] = 'rated_user_id=?';
        $types .= 'i';
        $params[] = $ratedUserId;
    }

    if (!$where) {
        return null;
    }

    $select = ['id'];
    foreach (['order_id', 'rater_id', 'rated_user_id', 'rating', 'comment', 'created_at'] as $col) {
        if ($col !== 'id' && has_col($mysqli, 'ratings', $col)) {
            $select[] = $col;
        }
    }

    $sql = "SELECT " . implode(',', $select) . " FROM ratings WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return null;
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}


function enum_values($column_type): array
{
    if (!$column_type) return [];
    if (stripos($column_type, "enum(") !== 0) return [];

    $inside = trim(substr($column_type, 5), "()");
    $vals = [];
    $cur = '';
    $in = false;

    for ($i = 0; $i < strlen($inside); $i++) {
        $ch = $inside[$i];
        if ($ch === "'" && ($i === 0 || $inside[$i - 1] !== '\\')) {
            $in = !$in;
            continue;
        }
        if ($ch === ',' && !$in) {
            $vals[] = stripcslashes($cur);
            $cur = '';
            continue;
        }
        $cur .= $ch;
    }

    if ($cur !== '') {
        $vals[] = stripcslashes($cur);
    }

    return array_map('trim', $vals);
}

function enum_has($mysqli, $table, $col, $value): bool
{
    $ct = has_col($mysqli, $table, $col);
    if (!$ct) return false;
    return in_array($value, enum_values($ct), true);
}


function displayOrderNumber(?string $orderNumber, int $orderId = 0): string
{
    $orderNumber = trim((string)$orderNumber);

    if ($orderNumber !== '') {
        if (preg_match('/^ORD-\d{14}-(\d+)$/', $orderNumber, $m)) {
            return 'ORD-' . (100000 + (int)$m[1]);
        }
        return $orderNumber;
    }

    return $orderId > 0 ? 'ORD-' . (100000 + $orderId) : '—';
}

function notify_user($mysqli, int $user_id, string $type, string $title, string $body, string $link): void
{
    $stmt = $mysqli->prepare("INSERT INTO notifications (user_id, type, title, body, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
    if ($stmt) {
        $stmt->bind_param("issss", $user_id, $type, $title, $body, $link);
        @$stmt->execute();
        $stmt->close();
    }
}

function set_flash_and_redirect(string $type, string $message, string $to = 'dashboard.php'): void
{
    $_SESSION[$type] = $message;
    header("Location: " . $to);
    exit;
}

function resolve_order_conversation_url($mysqli, array $order): string
{
    $conversationId = 0;
    $conversationPublic = '';
    $conversationNumber = '';

    foreach (['conversation_id', 'conversation_public_id', 'conversation_number'] as $col) {
        if (!has_col($mysqli, 'orders', $col) || empty($order[$col])) {
            continue;
        }
        if ($col === 'conversation_id') {
            $conversationId = (int)$order[$col];
        } elseif ($col === 'conversation_public_id') {
            $conversationPublic = trim((string)$order[$col]);
        } else {
            $conversationNumber = trim((string)$order[$col]);
        }
    }

    if ($conversationId <= 0 && $conversationPublic === '' && $conversationNumber === '' && has_col($mysqli, 'conversations', 'id')) {
        $conversationSelect = ['id'];
        foreach (['public_id', 'conversation_number'] as $col) {
            if (has_col($mysqli, 'conversations', $col)) {
                $conversationSelect[] = $col;
            }
        }

        $candidateQueries = [];

        if (has_col($mysqli, 'conversations', 'listing_id') && !empty($order['listing_id']) && has_col($mysqli, 'conversations', 'buyer_id') && has_col($mysqli, 'conversations', 'seller_id')) {
            $candidateQueries[] = [
                'sql' => "SELECT " . implode(',', $conversationSelect) . " FROM conversations WHERE listing_id=? AND buyer_id=? AND seller_id=? ORDER BY id DESC LIMIT 1",
                'types' => 'iii',
                'params' => [(int)($order['listing_id'] ?? 0), (int)($order['buyer_id'] ?? 0), (int)($order['seller_id'] ?? 0)],
            ];
        }

        if (has_col($mysqli, 'conversations', 'order_id')) {
            $candidateQueries[] = [
                'sql' => "SELECT " . implode(',', $conversationSelect) . " FROM conversations WHERE order_id=? ORDER BY id DESC LIMIT 1",
                'types' => 'i',
                'params' => [(int)($order['id'] ?? 0)],
            ];
        }

        if (!empty($order['order_number']) && has_col($mysqli, 'conversations', 'order_number')) {
            $candidateQueries[] = [
                'sql' => "SELECT " . implode(',', $conversationSelect) . " FROM conversations WHERE order_number=? ORDER BY id DESC LIMIT 1",
                'types' => 's',
                'params' => [(string)($order['order_number'] ?? '')],
            ];
        }

        if (has_col($mysqli, 'conversations', 'listing_id') && !empty($order['listing_id'])) {
            $candidateQueries[] = [
                'sql' => "SELECT " . implode(',', $conversationSelect) . " FROM conversations WHERE listing_id=? ORDER BY id DESC LIMIT 1",
                'types' => 'i',
                'params' => [(int)($order['listing_id'] ?? 0)],
            ];
        }

        foreach ($candidateQueries as $candidate) {
            $stmt = $mysqli->prepare($candidate['sql']);
            if (!$stmt) {
                continue;
            }
            if (($candidate['types'] ?? '') !== '') {
                $stmt->bind_param($candidate['types'], ...$candidate['params']);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();

            if (!empty($row['id'])) {
                $conversationId = (int)$row['id'];
                $conversationPublic = trim((string)($row['public_id'] ?? ''));
                $conversationNumber = trim((string)($row['conversation_number'] ?? ''));
                break;
            }
        }
    }

    if ($conversationId > 0) {
        return 'conversation.php?id=' . $conversationId;
    }
    if ($conversationPublic !== '') {
        return 'conversation.php?conversation=' . urlencode($conversationPublic);
    }
    if ($conversationNumber !== '') {
        return 'conversation.php?conversation=' . urlencode($conversationNumber);
    }
    if (!empty($order['listing_id'])) {
        return 'conversation.php?listing_id=' . (int)$order['listing_id'];
    }
    return 'conversation.php';
}

function fetch_user_phone($mysqli, int $userId): string
{
    if ($userId <= 0) {
        return '';
    }

    $phoneCols = ['phone', 'phone_number', 'mobile', 'mobile_number', 'whatsapp_number', 'contact_number'];
    $selectedCol = null;

    foreach ($phoneCols as $col) {
        if (has_col($mysqli, 'users', $col)) {
            $selectedCol = $col;
            break;
        }
    }

    if (!$selectedCol) {
        return '';
    }

    $sql = "SELECT `$selectedCol` AS phone_value FROM users WHERE id=? LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return trim((string)($row['phone_value'] ?? ''));
}

function fetch_order_address($mysqli, int $orderId): array
{
    if ($orderId <= 0 || !table_exists($mysqli, 'order_addresses') || !has_col($mysqli, 'order_addresses', 'order_id')) {
        return [];
    }

    $candidateCols = [
        'id', 'full_name', 'recipient_name', 'name', 'phone', 'phone_number', 'mobile',
        'address', 'address_line1', 'address_line2', 'street', 'district', 'city',
        'region', 'state', 'postal_code', 'zip_code', 'notes', 'created_at'
    ];

    $existing = [];
    foreach ($candidateCols as $col) {
        if (has_col($mysqli, 'order_addresses', $col)) {
            $existing[] = $col;
        }
    }

    if (!$existing) {
        return [];
    }

    $selectParts = [];
    foreach ($existing as $col) {
        $selectParts[] = "`$col`";
    }

    $orderBy = has_col($mysqli, 'order_addresses', 'id') ? " ORDER BY id DESC" : (has_col($mysqli, 'order_addresses', 'created_at') ? " ORDER BY created_at DESC" : "");
    $sql = "SELECT " . implode(', ', $selectParts) . " FROM order_addresses WHERE order_id=?{$orderBy} LIMIT 1";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    if (!$row) {
        return [];
    }

    $phone = trim((string)($row['phone'] ?? $row['phone_number'] ?? $row['mobile'] ?? ''));
    $recipient = trim((string)($row['full_name'] ?? $row['recipient_name'] ?? $row['name'] ?? ''));
    $line1 = trim((string)($row['address'] ?? $row['address_line1'] ?? $row['street'] ?? ''));
    $line2 = trim((string)($row['address_line2'] ?? ''));
    $district = trim((string)($row['district'] ?? ''));
    $city = trim((string)($row['city'] ?? ''));
    $region = trim((string)($row['region'] ?? $row['state'] ?? ''));
    $postal = trim((string)($row['postal_code'] ?? $row['zip_code'] ?? ''));
    $notes = trim((string)($row['notes'] ?? ''));

    $parts = array_values(array_filter([$recipient, $line1, $line2, $district, $city, $region, $postal, $notes], fn($v) => trim((string)$v) !== ''));

    return [
        'phone' => $phone,
        'formatted' => implode(' - ', $parts),
        'raw' => $row,
    ];
}

function fetch_order_proofs($mysqli, int $orderId): array
{
    if ($orderId <= 0 || !has_col($mysqli, 'order_proofs', 'order_id')) {
        return [];
    }

    $candidateCols = ['id', 'file_path', 'file_url', 'proof_file', 'file_name', 'mime_type', 'file_type', 'proof_type', 'notes', 'note', 'created_at'];
    $existing = [];

    foreach ($candidateCols as $col) {
        if (has_col($mysqli, 'order_proofs', $col)) {
            $existing[] = $col;
        }
    }

    if (!in_array('file_path', $existing, true) && !in_array('file_url', $existing, true) && !in_array('proof_file', $existing, true)) {
        return [];
    }

    $selectParts = [];
    foreach ($existing as $col) {
        $selectParts[] = "`$col`";
    }

    $orderBy = has_col($mysqli, 'order_proofs', 'id') ? " ORDER BY id DESC" : (has_col($mysqli, 'order_proofs', 'created_at') ? " ORDER BY created_at DESC" : "");
    $sql = "SELECT " . implode(', ', $selectParts) . " FROM order_proofs WHERE order_id=?{$orderBy}";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function store_order_proof($mysqli, int $orderId, int $userId, array $file, ?string &$error = null): bool
{
    $error = null;

    if ($orderId <= 0 || !has_col($mysqli, 'order_proofs', 'order_id')) {
        $error = 'جدول مرفقات الإثبات غير متوفر حاليًا.';
        return false;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        $error = 'يرجى اختيار ملف صالح.';
        return false;
    }

    $originalName = trim((string)($file['name'] ?? ''));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    if (!in_array($ext, $allowed, true)) {
        $error = 'يسمح فقط برفع صور أو ملفات PDF.';
        return false;
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)@finfo_file($finfo, $file['tmp_name']);
            @finfo_close($finfo);
        }
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'application/x-pdf', 'image/pjpeg'];
    if ($mime !== '' && !in_array($mime, $allowedMimes, true)) {
        $error = 'نوع الملف غير مدعوم.';
        return false;
    }

    $uploadDirAbs = __DIR__ . '/uploads/order_proofs';
    if (!is_dir($uploadDirAbs) && !@mkdir($uploadDirAbs, 0777, true) && !is_dir($uploadDirAbs)) {
        $error = 'تعذر إنشاء مجلد حفظ المرفقات.';
        return false;
    }

    try {
        $rand = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $rand = (string)mt_rand(1000, 9999);
    }

    $newName = 'proof_' . $orderId . '_' . date('Ymd_His') . '_' . $rand . '.' . $ext;
    $targetAbs = $uploadDirAbs . '/' . $newName;
    $storedPath = 'uploads/order_proofs/' . $newName;

    if (!@move_uploaded_file($file['tmp_name'], $targetAbs)) {
        $error = 'تعذر رفع الملف، حاول مرة أخرى.';
        return false;
    }

    $columns = [];
    $values = [];
    $types = '';
    $params = [];

    $addParam = function (string $column, string $type, $value) use (&$columns, &$values, &$types, &$params, $mysqli) {
        if (has_col($mysqli, 'order_proofs', $column)) {
            $columns[] = $column;
            $values[] = '?';
            $types .= $type;
            $params[] = $value;
        }
    };

    $addParam('order_id', 'i', $orderId);
    $addParam('user_id', 'i', $userId);
    $addParam('uploaded_by', 'i', $userId);
    $addParam('file_path', 's', $storedPath);
    $addParam('file_url', 's', $storedPath);
    $addParam('proof_file', 's', $storedPath);
    $addParam('file_name', 's', $originalName);
    $addParam('mime_type', 's', ($mime !== '' ? $mime : ($ext === 'pdf' ? 'application/pdf' : 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext))));

    if (has_col($mysqli, 'order_proofs', 'proof_type')) {
        $proofType = enum_has($mysqli, 'order_proofs', 'proof_type', 'seller_proof') ? 'seller_proof' : (enum_has($mysqli, 'order_proofs', 'proof_type', 'delivery') ? 'delivery' : '');
        if ($proofType !== '') {
            $columns[] = 'proof_type';
            $values[] = '?';
            $types .= 's';
            $params[] = $proofType;
        }
    }

    $addParam('note', 's', '');
    $addParam('notes', 's', '');

    if (has_col($mysqli, 'order_proofs', 'created_at')) {
        $columns[] = 'created_at';
        $values[] = 'NOW()';
    }

    if (has_col($mysqli, 'order_proofs', 'updated_at')) {
        $columns[] = 'updated_at';
        $values[] = 'NOW()';
    }

    if (!$columns) {
        @unlink($targetAbs);
        $error = 'تعذر حفظ بيانات المرفق في قاعدة البيانات.';
        return false;
    }

    $sql = "INSERT INTO order_proofs (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ")";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        @unlink($targetAbs);
        $error = 'تعذر حفظ بيانات المرفق.';
        return false;
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        @unlink($targetAbs);
        $error = 'تعذر حفظ المرفق في قاعدة البيانات.';
        return false;
    }

    return true;
}

function build_page_flash(): ?array
{
    $sessionMap = [
        'success_message' => 'success',
        'error_message'   => 'danger',
        'warning_message' => 'warning',
        'info_message'    => 'info',
        'success'         => 'success',
        'error'           => 'danger',
        'warning'         => 'warning',
        'info'            => 'info',
    ];

    foreach ($sessionMap as $key => $type) {
        if (!empty($_SESSION[$key]) && is_string($_SESSION[$key])) {
            $msg = trim($_SESSION[$key]);
            unset($_SESSION[$key]);

            if ($msg !== '') {
                return ['type' => $type, 'text' => $msg];
            }
        }
    }

    return null;
}

function can_request_cancellation(array $row): bool
{
    if (($row['payment_status'] ?? '') !== 'paid') {
        return false;
    }

    if (!empty($row['buyer_received_at']) || !empty($row['completed_at']) || !empty($row['cancelled_at'])) {
        return false;
    }

    if (($row['order_status'] ?? '') === 'disputed') {
        return false;
    }

    if (($row['cancellation_status'] ?? 'none') === 'pending') {
        return false;
    }

    $deliveryMode   = normalize_delivery_mode($row['delivery_type'] ?? '', $row['shipping_type'] ?? '', $row['shipping_fee'] ?? 0);
    $deliveryCode   = trim((string)($row['delivery_code'] ?? ''));
    $deliveryStatus = strtolower(trim((string)($row['delivery_status'] ?? '')));
    $orderStatus    = strtolower(trim((string)($row['order_status'] ?? '')));

    if ($deliveryMode === 'manual' && $deliveryCode !== '') {
        return false;
    }

    if (in_array($deliveryStatus, ['shipped', 'delivered', 'received'], true)) {
        return false;
    }

    if (in_array($orderStatus, ['shipped', 'delivered', 'completed', 'cancelled', 'refunded', 'disputed'], true)) {
        return false;
    }

    return true;
}

function can_open_dispute(array $row): bool
{
    if (($row['payment_status'] ?? '') !== 'paid') {
        return false;
    }

    if (!empty($row['buyer_received_at']) || !empty($row['completed_at']) || !empty($row['cancelled_at'])) {
        return false;
    }

    if (($row['order_status'] ?? '') === 'disputed') {
        return false;
    }

    $deliveryMode   = normalize_delivery_mode($row['delivery_type'] ?? '', $row['shipping_type'] ?? '', $row['shipping_fee'] ?? 0);
    $deliveryStatus = strtolower(trim((string)($row['delivery_status'] ?? '')));
    $orderStatus    = strtolower(trim((string)($row['order_status'] ?? '')));
    $listingType    = strtolower(trim((string)($row['listing_type'] ?? 'physical')));

    if ($deliveryMode === 'manual') {
        return !empty($row['delivery_code']);
    }

    if ($listingType === 'service') {
        return in_array($orderStatus, ['awaiting_shipping', 'shipped', 'delivered'], true);
    }

    if ($listingType === 'digital') {
        return in_array($orderStatus, ['shipped', 'delivered'], true);
    }

    return in_array($deliveryStatus, ['shipped', 'delivered'], true) || in_array($orderStatus, ['shipped', 'delivered'], true);
}

function normalize_delivery_mode(?string $deliveryType, ?string $shippingType, $shippingFee = 0): string
{
    $deliveryType = strtolower(trim((string)$deliveryType));
    $shippingType = strtolower(trim((string)$shippingType));
    $shippingFee  = (float)$shippingFee;

    $manualValues = ['manual', 'hand', 'hand_delivery', 'manual_delivery', 'pickup', 'meetup', 'face_to_face', 'manual_code'];
    $freeValues   = ['shipping_free', 'free_shipping', 'free'];
    $buyerValues  = ['shipping_buyer', 'buyer_shipping', 'shipping_paid', 'paid_shipping'];
    $shippingVals = ['shipping', 'ship', 'courier', 'carrier'];

    if (in_array($deliveryType, $manualValues, true) || in_array($shippingType, $manualValues, true)) {
        return 'manual';
    }

    if (in_array($deliveryType, $freeValues, true) || in_array($shippingType, $freeValues, true)) {
        return 'shipping_free';
    }

    if (in_array($deliveryType, $buyerValues, true) || in_array($shippingType, $buyerValues, true)) {
        return 'shipping_buyer';
    }

    if ($shippingFee > 0) {
        return 'shipping_buyer';
    }

    if (in_array($deliveryType, $shippingVals, true) || in_array($shippingType, $shippingVals, true)) {
        return 'shipping';
    }

    if ($deliveryType !== '') {
        if (str_contains($deliveryType, 'manual') || str_contains($deliveryType, 'hand')) return 'manual';
        if (str_contains($deliveryType, 'free')) return 'shipping_free';
        if (str_contains($deliveryType, 'buyer')) return 'shipping_buyer';
        if (str_contains($deliveryType, 'ship')) return 'shipping';
    }

    if ($shippingType !== '') {
        if (str_contains($shippingType, 'manual') || str_contains($shippingType, 'hand')) return 'manual';
        if (str_contains($shippingType, 'free')) return 'shipping_free';
        if (str_contains($shippingType, 'buyer')) return 'shipping_buyer';
        if (str_contains($shippingType, 'ship')) return 'shipping';
    }

    return 'shipping';
}

function delivery_mode_label(string $mode): string
{
    return match ($mode) {
        'manual'         => 'تسليم يدوي',
        'shipping_buyer' => 'شحن على المشتري',
        'shipping_free'  => 'شحن على البائع',
        default          => 'شحن على البائع',
    };
}

function primary_delivery_label(?string $deliveryType, ?string $shippingType = null, $shippingFee = 0): string
{
    $deliveryType = strtolower(trim((string)$deliveryType));

    return match ($deliveryType) {
        'digital' => 'منتج رقمي',
        'service' => 'خدمة',
        'manual'  => 'تسليم يدوي',
        default   => delivery_mode_label(normalize_delivery_mode($deliveryType, $shippingType, $shippingFee)),
    };
}

function money($amount, string $currency = 'SAR'): string
{
    $suffix = $currency === 'SAR' ? 'ر.س' : $currency;
    return number_format((float)$amount, 2) . ' ' . $suffix;
}

function calc_seller_net(array $order): float
{
    $subtotal    = (float)($order['subtotal'] ?? 0);
    $shippingFee = (float)($order['shipping_fee'] ?? 0);
    $platformFee = (float)($order['platform_fee'] ?? 0);
    $payer       = (string)($order['platform_fee_payer'] ?? 'seller');

    $net = $subtotal + $shippingFee;

    if ($payer === 'seller') {
        $net -= $platformFee;
    } elseif ($payer === 'split') {
        $net -= ($platformFee / 2);
    }

    return max($net, 0);
}

function fmt_datetime(?string $value): string
{
    if (!$value || $value === '0000-00-00 00:00:00') {
        return '—';
    }

    $ts = strtotime($value);
    return $ts ? date('Y-m-d h:i A', $ts) : '—';
}

function generate_delivery_code(): string
{
    return (string)random_int(1000, 9999);
}

function order_stage_label(array $order, bool $isSeller, bool $isBuyer): string
{
    $paymentStatus  = (string)($order['payment_status'] ?? '');
    $deliveryStatus = strtolower(trim((string)($order['delivery_status'] ?? '')));
    $orderStatus    = strtolower(trim((string)($order['order_status'] ?? '')));
    $mode           = normalize_delivery_mode($order['delivery_type'] ?? '', $order['shipping_type'] ?? '', $order['shipping_fee'] ?? 0);
    $listingType    = strtolower(trim((string)($order['listing_type'] ?? 'physical')));

    if ($orderStatus === 'disputed') {
        return 'عليه نزاع';
    }

    if ($orderStatus === 'cancelled' || !empty($order['cancelled_at'])) {
        return 'ملغي';
    }

    if (!empty($order['completed_at']) && $isSeller) {
        return 'تم التحويل';
    }

    if (!empty($order['buyer_received_at']) || !empty($order['completed_at'])) {
        return $isBuyer ? 'مكتمل' : 'بانتظار التحويل';
    }

    if ($paymentStatus !== 'paid') {
        return 'بانتظار الدفع';
    }

    if ($mode === 'manual') {
        return !empty($order['delivery_code']) ? 'بانتظار التسليم' : 'تم إنشاء الطلب';
    }

    if ($listingType === 'service') {
        if ($orderStatus === 'awaiting_shipping') {
            return 'قيد التنفيذ';
        }
        if ($orderStatus === 'shipped' || $deliveryStatus === 'delivered') {
            return 'تم التنفيذ';
        }
        return 'تم إنشاء الطلب';
    }

    if ($listingType === 'digital') {
        if ($orderStatus === 'shipped' || $deliveryStatus === 'delivered') {
            return 'تم التسليم';
        }
        return 'تم إنشاء الطلب';
    }

    if ($deliveryStatus === 'shipped' || $orderStatus === 'shipped') {
        return 'تم الشحن';
    }

    return 'تم إنشاء الطلب';
}

function stage_badge_class(string $label): string
{
    return match ($label) {
        'تم الدفع'         => 'text-bg-warning text-dark',
        'تم الشحن'         => 'text-bg-info text-dark',
        'بانتظار التسليم'  => 'text-bg-primary',
        'تم التسليم'       => 'text-bg-primary',
        'قيد التنفيذ'      => 'text-bg-secondary',
        'تم الاستلام'      => 'text-bg-success',
        'مكتمل'            => 'text-bg-success',
        'بانتظار التحويل'  => 'text-bg-success',
        'تم التحويل'       => 'text-bg-success',
        'عليه نزاع'        => 'text-bg-danger',
        'ملغي'             => 'text-bg-danger',
        default            => 'text-bg-light text-dark',
    };
}

/* =========================
   Resolve order id
========================= */
if (isset($_GET['order'])) {
    $orderValue = $_GET['order'];
    $where = "o.order_number = ?";
    $bindType = "s";
} elseif (isset($_GET['id'])) {
    $orderValue = (int)$_GET['id'];
    $where = "o.id = ?";
    $bindType = "i";
} elseif (isset($_GET['order_id'])) {
    $orderValue = (int)$_GET['order_id'];
    $where = "o.id = ?";
    $bindType = "i";
} else {
    echo "<div class='container py-4'><div class='alert alert-danger'>رقم الطلب غير موجود.</div></div>";
    include 'footer.php';
    exit;
}

/* =========================
   Fetch order
========================= */
$stmt = $mysqli->prepare("
    SELECT 
        o.*,
        l.id AS listing_exists_id,
        l.public_id,
        l.title AS product_title,
        l.status AS listing_status,
        COALESCE(l.listing_type, 'physical') AS listing_type,
        u1.name AS buyer_name,
        u2.name AS seller_name
    FROM orders o
    LEFT JOIN listings l ON o.listing_id = l.id
    INNER JOIN users u1 ON o.buyer_id = u1.id
    INNER JOIN users u2 ON o.seller_id = u2.id
    WHERE $where
    LIMIT 1
");
$stmt->bind_param($bindType, $orderValue);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    echo "<div class='container py-4'><div class='alert alert-danger'>الطلب غير موجود.</div></div>";
    include 'footer.php';
    exit;
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$isSeller = ($userId === (int)$order['seller_id']);
$isBuyer  = ($userId === (int)$order['buyer_id']);

if (!$isSeller && !$isBuyer) {
    echo "<div class='container py-4'><div class='alert alert-danger'>غير مصرح لك بعرض هذا الطلب.</div></div>";
    include 'footer.php';
    exit;
}

$oid = (int)$order['id'];

/* =========================
   Listing visibility alert (once)
========================= */
$listingExists = !empty($order['listing_exists_id']);
$listingStatus = strtolower(trim((string)($order['listing_status'] ?? '')));
$listingMissingOrUnavailable = (!$listingExists || in_array($listingStatus, ['deleted', 'deleted_by_user', 'blocked'], true));

$listingAlert = null;
$listingAlertKey = 'listing_missing_alert_seen_' . $oid;

if ($listingMissingOrUnavailable && empty($_SESSION[$listingAlertKey])) {
    $listingAlert = [
        'type' => 'warning',
        'text' => 'الإعلان لم يعد موجودًا.'
    ];
    $_SESSION[$listingAlertKey] = 1;
}

/* =========================
   POST Actions
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $mysqli->prepare("
        SELECT 
            o.*,
            l.id AS listing_exists_id,
            l.public_id,
            l.title AS product_title,
            l.status AS listing_status,
            COALESCE(l.listing_type, 'physical') AS listing_type,
            u1.name AS buyer_name,
            u2.name AS seller_name
        FROM orders o
        LEFT JOIN listings l ON o.listing_id = l.id
        INNER JOIN users u1 ON o.buyer_id = u1.id
        INNER JOIN users u2 ON o.seller_id = u2.id
        WHERE o.id=?
        LIMIT 1
    ");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order_now = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order_now) {
        set_flash_and_redirect('error_message', 'الطلب غير موجود.', 'dashboard.php');
    }

    $deliveryMode      = normalize_delivery_mode($order_now['delivery_type'] ?? '', $order_now['shipping_type'] ?? '', $order_now['shipping_fee'] ?? 0);
    $deliveryStatusNow = strtolower(trim((string)($order_now['delivery_status'] ?? '')));
    $orderStatusNow    = strtolower(trim((string)($order_now['order_status'] ?? '')));
    $listingTypeNow    = strtolower(trim((string)($order_now['listing_type'] ?? 'physical')));
    $dashboardLink     = 'order_details.php?id=' . $oid;
    $action            = trim((string)($_POST['action'] ?? ''));

    /* 1) Seller marks physical shipping */
    if ($action === 'seller_mark_shipped') {
        if (!$isSeller || ($order_now['payment_status'] ?? '') !== 'paid' || $deliveryMode === 'manual') {
            set_flash_and_redirect('error_message', 'لا يمكن تنفيذ هذا الإجراء على هذا الطلب.', $dashboardLink);
        }

        if (!empty($order_now['buyer_received_at']) || !empty($order_now['completed_at'])) {
            set_flash_and_redirect('error_message', 'الطلب مكتمل بالفعل.', $dashboardLink);
        }

        $carrier_name    = trim((string)($_POST['carrier_name'] ?? ''));
        $tracking_number = trim((string)($_POST['tracking_number'] ?? ''));
        $notes           = trim((string)($_POST['notes'] ?? ''));

        $updates = [];
        $types   = '';
        $params  = [];

        if (has_col($mysqli, 'orders', 'delivery_status') && enum_has($mysqli, 'orders', 'delivery_status', 'shipped')) {
            $updates[] = "delivery_status=?";
            $types .= 's';
            $params[] = 'shipped';
        }

        if (has_col($mysqli, 'orders', 'order_status') && enum_has($mysqli, 'orders', 'order_status', 'shipped')) {
            $updates[] = "order_status=?";
            $types .= 's';
            $params[] = 'shipped';
        }

        if (has_col($mysqli, 'orders', 'seller_shipped_at')) {
            $updates[] = "seller_shipped_at=NOW()";
        }

        if ($carrier_name !== '' && has_col($mysqli, 'orders', 'carrier_name')) {
            $updates[] = "carrier_name=?";
            $types .= 's';
            $params[] = $carrier_name;
        }

        if ($tracking_number !== '' && has_col($mysqli, 'orders', 'tracking_number')) {
            $updates[] = "tracking_number=?";
            $types .= 's';
            $params[] = $tracking_number;
        }

        if ($notes !== '' && has_col($mysqli, 'orders', 'notes')) {
            $updates[] = "notes=?";
            $types .= 's';
            $params[] = $notes;
        }

        if (!$updates) {
            set_flash_and_redirect('error_message', 'حدث خطأ أثناء تحديث الطلب.', $dashboardLink);
        }

        $sql = "UPDATE orders SET " . implode(',', $updates) . " WHERE id=?";
        $types .= 'i';
        $params[] = $oid;

        $st = $mysqli->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        $st->close();

        record_order_log($mysqli, $oid, 'cancellation_requested');

        notify_user(
            $mysqli,
            (int)$order_now['buyer_id'],
            'order_update',
            'تم شحن الطلب',
            'تم شحن الطلب رقم: ' . ($order_now['order_number'] ?? ''),
            'dashboard.php'
        );

        set_flash_and_redirect('success_message', 'تم تحديث حالة الطلب إلى تم الشحن بنجاح.', $dashboardLink);
    }

    /* 2) Seller creates manual delivery code */
    if ($action === 'seller_create_manual_code') {
        if (!$isSeller || ($order_now['payment_status'] ?? '') !== 'paid' || $deliveryMode !== 'manual') {
            set_flash_and_redirect('error_message', 'لا يمكن تنفيذ هذا الإجراء على هذا الطلب.', $dashboardLink);
        }

        if (!empty($order_now['delivery_code'])) {
            set_flash_and_redirect('warning_message', 'تم إنشاء رمز التسليم مسبقًا.', $dashboardLink);
        }

        $newCode = generate_delivery_code();

        $updates = [];
        $types   = '';
        $params  = [];

        if (has_col($mysqli, 'orders', 'delivery_code')) {
            $updates[] = "delivery_code=?";
            $types .= 's';
            $params[] = $newCode;
        }

        if (has_col($mysqli, 'orders', 'order_status') && enum_has($mysqli, 'orders', 'order_status', 'awaiting_shipping')) {
            $updates[] = "order_status=?";
            $types .= 's';
            $params[] = 'awaiting_shipping';
        }

        if (has_col($mysqli, 'orders', 'seller_shipped_at')) {
            $updates[] = "seller_shipped_at=NOW()";
        }

        if (!$updates) {
            set_flash_and_redirect('error_message', 'تعذر إنشاء رمز التسليم.', $dashboardLink);
        }

        $sql = "UPDATE orders SET " . implode(',', $updates) . " WHERE id=?";
        $types .= 'i';
        $params[] = $oid;

        $st = $mysqli->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        $st->close();

        notify_user(
            $mysqli,
            (int)$order_now['buyer_id'],
            'order_update',
            'تم إنشاء رمز التسليم',
            'قام البائع بإنشاء رمز التسليم للطلب رقم: ' . ($order_now['order_number'] ?? ''),
            'dashboard.php'
        );

        set_flash_and_redirect('success_message', 'تم إنشاء رمز التسليم بنجاح.', $dashboardLink);
    }

    /* 3) Seller confirms manual delivery by entering code */
    if ($action === 'seller_confirm_manual_code') {
        if (!$isSeller || ($order_now['payment_status'] ?? '') !== 'paid' || $deliveryMode !== 'manual') {
            set_flash_and_redirect('error_message', 'لا يمكن تنفيذ هذا الإجراء على هذا الطلب.', $dashboardLink);
        }

        $code_in  = trim((string)($_POST['delivery_code'] ?? ''));
        $realCode = trim((string)($order_now['delivery_code'] ?? ''));

        if ($code_in === '' || $realCode === '' || $code_in !== $realCode) {
            set_flash_and_redirect('error_message', 'رمز التسليم غير صحيح.', $dashboardLink);
        }

        $updates = [];

        if (has_col($mysqli, 'orders', 'delivery_status')) {
            if (enum_has($mysqli, 'orders', 'delivery_status', 'received')) {
                $updates[] = "delivery_status='received'";
            } elseif (enum_has($mysqli, 'orders', 'delivery_status', 'delivered')) {
                $updates[] = "delivery_status='delivered'";
            }
        }

        if (has_col($mysqli, 'orders', 'buyer_received_at')) {
            $updates[] = "buyer_received_at=NOW()";
        }

        if (has_col($mysqli, 'orders', 'delivered_at')) {
            $updates[] = "delivered_at=NOW()";
        }

        if ($updates) {
            $sql = "UPDATE orders SET " . implode(',', $updates) . " WHERE id=?";
            $st = $mysqli->prepare($sql);
            $st->bind_param("i", $oid);
            $st->execute();
            $st->close();
        }

        notify_user(
            $mysqli,
            (int)$order_now['buyer_id'],
            'order_update',
            'تم التسليم',
            'أكد البائع تسليم الطلب رقم: ' . ($order_now['order_number'] ?? ''),
            'dashboard.php'
        );

        set_flash_and_redirect('success_message', 'تم تأكيد التسليم ونقل الطلب إلى الطلبات المكتملة.', $dashboardLink);
    }

    /* 4) Seller uploads proof attachment */
    if ($action === 'upload_proof') {
        $allowProofUpload = ($order_now['payment_status'] ?? '') === 'paid'
            && $isSeller
            && empty($order_now['buyer_received_at'])
            && (
                ($listingTypeNow === 'physical' && $deliveryMode !== 'manual')
                || in_array($listingTypeNow, ['digital', 'service'], true)
            );

        if (!$allowProofUpload) {
            set_flash_and_redirect('error_message', 'لا يمكن رفع إثبات على هذا الطلب في هذه المرحلة.', $dashboardLink);
        }

        if (empty($_FILES['proof_file']['name'])) {
            set_flash_and_redirect('error_message', 'يرجى اختيار صورة أو ملف PDF قبل الرفع.', $dashboardLink);
        }

        $uploadError = null;
        if (!store_order_proof($mysqli, $oid, $userId, $_FILES['proof_file'], $uploadError)) {
            set_flash_and_redirect('error_message', $uploadError ?: 'تعذر رفع المرفق.', $dashboardLink);
        }

        notify_user(
            $mysqli,
            (int)$order_now['buyer_id'],
            'order_update',
            'تم رفع إثبات جديد',
            'قام البائع برفع مرفق إثبات للطلب رقم: ' . ($order_now['order_number'] ?? ''),
            'order_details.php?order_id=' . $oid
        );

        set_flash_and_redirect('success_message', 'تم رفع المرفق بنجاح.', 'order_details.php?order_id=' . $oid);
    }

    /* 5) Seller marks digital item delivered */
    if ($action === 'seller_mark_digital_delivered') {
        if (!$isSeller || ($order_now['payment_status'] ?? '') !== 'paid' || $listingTypeNow !== 'digital') {
            set_flash_and_redirect('error_message', 'لا يمكن تنفيذ هذا الإجراء على هذا الطلب.', $dashboardLink);
        }

        $notes = trim((string)($_POST['notes'] ?? ''));

        $updates = [];
        $types   = '';
        $params  = [];

        if (has_col($mysqli, 'orders', 'delivery_status') && enum_has($mysqli, 'orders', 'delivery_status', 'delivered')) {
            $updates[] = "delivery_status=?";
            $types .= 's';
            $params[] = 'delivered';
        }

        if (has_col($mysqli, 'orders', 'order_status') && enum_has($mysqli, 'orders', 'order_status', 'shipped')) {
            $updates[] = "order_status=?";
            $types .= 's';
            $params[] = 'shipped';
        }

        if ($notes !== '' && has_col($mysqli, 'orders', 'notes')) {
            $updates[] = "notes=?";
            $types .= 's';
            $params[] = $notes;
        }

        if (has_col($mysqli, 'orders', 'seller_shipped_at')) {
            $updates[] = "seller_shipped_at=NOW()";
        }

        if (!$updates) {
            set_flash_and_redirect('error_message', 'تعذر تحديث حالة الطلب.', $dashboardLink);
        }

        $sql = "UPDATE orders SET " . implode(',', $updates) . " WHERE id=?";
        $types .= 'i';
        $params[] = $oid;

        $st = $mysqli->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        $st->close();

        notify_user(
            $mysqli,
            (int)$order_now['buyer_id'],
            'order_update',
            'تم تسليم المنتج',
            'قام البائع بتسليم المنتج الرقمي للطلب رقم: ' . ($order_now['order_number'] ?? ''),
            'dashboard.php'
        );

        set_flash_and_redirect('success_message', 'تم تسجيل تسليم المنتج بنجاح.', $dashboardLink);
    }

    /* 6) Seller starts service */
    if ($action === 'seller_start_service') {
        if (!$isSeller || ($order_now['payment_status'] ?? '') !== 'paid' || $listingTypeNow !== 'service') {
            set_flash_and_redirect('error_message', 'لا يمكن تنفيذ هذا الإجراء على هذا الطلب.', $dashboardLink);
        }

        if ($orderStatusNow !== 'paid') {
            set_flash_and_redirect('warning_message', 'تم بدء تنفيذ الخدمة مسبقًا أو تم تحديث حالتها.', $dashboardLink);
        }

        $updates = [];

        if (has_col($mysqli, 'orders', 'order_status') && enum_has($mysqli, 'orders', 'order_status', 'awaiting_shipping')) {
            $updates[] = "order_status='awaiting_shipping'";
        }

        if (has_col($mysqli, 'orders', 'seller_confirmed_at')) {
            $updates[] = "seller_confirmed_at=COALESCE(seller_confirmed_at, NOW())";
        }

        if ($updates) {
            $sql = "UPDATE orders SET " . implode(',', $updates) . " WHERE id=?";
            $st = $mysqli->prepare($sql);
            $st->bind_param("i", $oid);
            $st->execute();
            $st->close();
        }

        notify_user(
            $mysqli,
            (int)$order_now['buyer_id'],
            'order_update',
            'بدأ تنفيذ الخدمة',
            'بدأ البائع تنفيذ الخدمة للطلب رقم: ' . ($order_now['order_number'] ?? ''),
            'dashboard.php'
        );

        set_flash_and_redirect('success_message', 'تم تسجيل بدء تنفيذ الخدمة بنجاح.', $dashboardLink);
    }

    /* 7) Seller marks service completed */
    if ($action === 'seller_finish_service') {
        if (!$isSeller || ($order_now['payment_status'] ?? '') !== 'paid' || $listingTypeNow !== 'service') {
            set_flash_and_redirect('error_message', 'لا يمكن تنفيذ هذا الإجراء على هذا الطلب.', $dashboardLink);
        }

        if (in_array($orderStatusNow, ['shipped', 'delivered', 'completed', 'cancelled', 'refunded', 'disputed'], true)) {
            set_flash_and_redirect('warning_message', 'تم تحديث حالة الخدمة مسبقًا.', $dashboardLink);
        }

        $notes = trim((string)($_POST['notes'] ?? ''));

        $updates = [];
        $types   = '';
        $params  = [];

        if (has_col($mysqli, 'orders', 'delivery_status') && enum_has($mysqli, 'orders', 'delivery_status', 'delivered')) {
            $updates[] = "delivery_status=?";
            $types .= 's';
            $params[] = 'delivered';
        }

        if (has_col($mysqli, 'orders', 'order_status') && enum_has($mysqli, 'orders', 'order_status', 'shipped')) {
            $updates[] = "order_status=?";
            $types .= 's';
            $params[] = 'shipped';
        }

        if ($notes !== '' && has_col($mysqli, 'orders', 'notes')) {
            $updates[] = "notes=?";
            $types .= 's';
            $params[] = $notes;
        }

        if (has_col($mysqli, 'orders', 'seller_shipped_at')) {
            $updates[] = "seller_shipped_at=NOW()";
        }

        if (!$updates) {
            set_flash_and_redirect('error_message', 'تعذر تحديث حالة الخدمة.', $dashboardLink);
        }

        $sql = "UPDATE orders SET " . implode(',', $updates) . " WHERE id=?";
        $types .= 'i';
        $params[] = $oid;

        $st = $mysqli->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        $st->close();

        notify_user(
            $mysqli,
            (int)$order_now['buyer_id'],
            'order_update',
            'تم تنفيذ الخدمة',
            'أكمل البائع تنفيذ الخدمة للطلب رقم: ' . ($order_now['order_number'] ?? ''),
            'dashboard.php'
        );

        set_flash_and_redirect('success_message', 'تم تسجيل تنفيذ الخدمة بنجاح.', $dashboardLink);
    }

    /* 8) Buyer confirms receipt for shipped / digital / service */
    if ($action === 'buyer_confirm_received') {
        if (!$isBuyer || ($order_now['payment_status'] ?? '') !== 'paid') {
            set_flash_and_redirect('error_message', 'لا يمكن تنفيذ هذا الإجراء على هذا الطلب.', $dashboardLink);
        }

        $allow = false;

        if (!in_array($orderStatusNow, ['completed', 'cancelled', 'refunded', 'disputed'], true) && $deliveryMode !== 'manual') {
            if ($listingTypeNow === 'physical') {
                $allow = ($deliveryStatusNow === 'shipped' || $orderStatusNow === 'shipped');
            } elseif (in_array($listingTypeNow, ['digital', 'service'], true)) {
                $allow = in_array($orderStatusNow, ['shipped', 'delivered'], true) || $deliveryStatusNow === 'delivered';
            }
        }

        if (!$allow) {
            set_flash_and_redirect('error_message', 'لا يمكن تأكيد الاستلام في هذه المرحلة.', $dashboardLink);
        }

        $updates = [];

        if (has_col($mysqli, 'orders', 'delivery_status')) {
            if (enum_has($mysqli, 'orders', 'delivery_status', 'received')) {
                $updates[] = "delivery_status='received'";
            } elseif (enum_has($mysqli, 'orders', 'delivery_status', 'delivered')) {
                $updates[] = "delivery_status='delivered'";
            }
        }

        if (has_col($mysqli, 'orders', 'buyer_received_at')) {
            $updates[] = "buyer_received_at=NOW()";
        }

        if (has_col($mysqli, 'orders', 'delivered_at')) {
            $updates[] = "delivered_at=NOW()";
        }

        if ($updates) {
            $sql = "UPDATE orders SET " . implode(',', $updates) . " WHERE id=?";
            $st = $mysqli->prepare($sql);
            $st->bind_param("i", $oid);
            $st->execute();
            $st->close();
        }

        notify_user(
            $mysqli,
            (int)$order_now['seller_id'],
            'order_update',
            'تم الاستلام',
            'أكد المشتري استلام الطلب رقم: ' . ($order_now['order_number'] ?? ''),
            'dashboard.php'
        );

        set_flash_and_redirect('success_message', 'تم تأكيد الاستلام ونقل الطلب إلى الطلبات المكتملة.', $dashboardLink);
    }

    /* 9) Buyer rates seller after completed order */
    if ($action === 'submit_seller_rating') {
        if (!$isBuyer || !table_exists($mysqli, 'ratings')) {
            set_flash_and_redirect('error_message', 'لا يمكن تنفيذ هذا الإجراء حاليًا.', $dashboardLink);
        }

        $isCompletedOrder = !empty($order_now['buyer_received_at']) || !empty($order_now['completed_at']) || in_array($orderStatusNow, ['completed'], true);
        if (!$isCompletedOrder || in_array($orderStatusNow, ['cancelled', 'refunded', 'disputed'], true)) {
            set_flash_and_redirect('error_message', 'يمكن تقييم البائع فقط بعد اكتمال الطلب.', $dashboardLink);
        }

        $ratingValue = (int)($_POST['seller_rating'] ?? 0);
        $comment = trim((string)($_POST['seller_rating_comment'] ?? ''));

        if ($ratingValue < 1 || $ratingValue > 5) {
            set_flash_and_redirect('error_message', 'اختر تقييمًا من 1 إلى 5 نجوم.', $dashboardLink);
        }

        $existingRating = fetch_existing_seller_rating($mysqli, $oid, (int)$order_now['buyer_id'], (int)$order_now['seller_id']);

        if ($existingRating && !empty($existingRating['id'])) {
            $updates = [];
            $types = '';
            $params = [];

            if (has_col($mysqli, 'ratings', 'rating')) {
                $updates[] = 'rating=?';
                $types .= 'i';
                $params[] = $ratingValue;
            }
            if (has_col($mysqli, 'ratings', 'comment')) {
                $updates[] = 'comment=?';
                $types .= 's';
                $params[] = $comment;
            }

            if (!$updates) {
                set_flash_and_redirect('error_message', 'تعذر حفظ التقييم حاليًا.', $dashboardLink);
            }

            $sql = 'UPDATE ratings SET ' . implode(',', $updates) . ' WHERE id=?';
            $types .= 'i';
            $params[] = (int)$existingRating['id'];
            $st = $mysqli->prepare($sql);
            if (!$st) {
                set_flash_and_redirect('error_message', 'تعذر حفظ التقييم حاليًا.', $dashboardLink);
            }
            $st->bind_param($types, ...$params);
            $st->execute();
            $st->close();
        } else {
            $cols = [];
            $vals = [];
            $types = '';
            $params = [];

            if (has_col($mysqli, 'ratings', 'order_id')) {
                $cols[] = 'order_id';
                $vals[] = '?';
                $types .= 'i';
                $params[] = $oid;
            }
            if (has_col($mysqli, 'ratings', 'rater_id')) {
                $cols[] = 'rater_id';
                $vals[] = '?';
                $types .= 'i';
                $params[] = (int)$order_now['buyer_id'];
            }
            if (has_col($mysqli, 'ratings', 'rated_user_id')) {
                $cols[] = 'rated_user_id';
                $vals[] = '?';
                $types .= 'i';
                $params[] = (int)$order_now['seller_id'];
            }
            if (has_col($mysqli, 'ratings', 'rating')) {
                $cols[] = 'rating';
                $vals[] = '?';
                $types .= 'i';
                $params[] = $ratingValue;
            }
            if (has_col($mysqli, 'ratings', 'comment')) {
                $cols[] = 'comment';
                $vals[] = '?';
                $types .= 's';
                $params[] = $comment;
            }
            if (has_col($mysqli, 'ratings', 'created_at')) {
                $cols[] = 'created_at';
                $vals[] = 'NOW()';
            }

            if (!$cols) {
                set_flash_and_redirect('error_message', 'تعذر حفظ التقييم حاليًا.', $dashboardLink);
            }

            $sql = 'INSERT INTO ratings (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
            $st = $mysqli->prepare($sql);
            if (!$st) {
                set_flash_and_redirect('error_message', 'تعذر حفظ التقييم حاليًا.', $dashboardLink);
            }
            if ($types !== '') {
                $st->bind_param($types, ...$params);
            }
            $st->execute();
            $st->close();
        }

        record_order_log($mysqli, $oid, 'seller_rated');

        notify_user(
            $mysqli,
            (int)$order_now['seller_id'],
            'order_update',
            'تقييم جديد',
            'أضاف المشتري تقييمًا جديدًا على الطلب رقم: ' . ($order_now['order_number'] ?? ''),
            'seller_profile.php?id=' . (int)$order_now['seller_id']
        );

        set_flash_and_redirect('success_message', 'تم حفظ تقييمك للبائع بنجاح.', $dashboardLink);
    }

    /* 10) Cancellation request from order details */
    if ($action === 'request_cancellation') {
        $returnLink = 'order_details.php?order_id=' . $oid;

        if (!can_request_cancellation($order_now)) {
            set_flash_and_redirect('error_message', 'لا يمكن طلب الإلغاء في هذه المرحلة.', $returnLink);
        }

        $reason = trim((string)($_POST['cancellation_reason'] ?? ''));
        $requestedBy = $isBuyer ? 'buyer' : 'seller';
        $otherUserId = $isBuyer ? (int)$order_now['seller_id'] : (int)$order_now['buyer_id'];

        $updates = [];
        $types = '';
        $params = [];

        if (has_col($mysqli, 'orders', 'cancellation_requested_by') && enum_has($mysqli, 'orders', 'cancellation_requested_by', $requestedBy)) {
            $updates[] = "cancellation_requested_by=?";
            $types .= 's';
            $params[] = $requestedBy;
        }

        if (has_col($mysqli, 'orders', 'cancellation_requested_at')) {
            $updates[] = "cancellation_requested_at=NOW()";
        }

        if (has_col($mysqli, 'orders', 'cancellation_status') && enum_has($mysqli, 'orders', 'cancellation_status', 'pending')) {
            $updates[] = "cancellation_status=?";
            $types .= 's';
            $params[] = 'pending';
        }

        if ($reason !== '' && has_col($mysqli, 'orders', 'cancellation_reason')) {
            $updates[] = "cancellation_reason=?";
            $types .= 's';
            $params[] = $reason;
        }

        if (!$updates) {
            set_flash_and_redirect('error_message', 'تعذر إرسال طلب الإلغاء حاليًا.', $returnLink);
        }

        $sql = "UPDATE orders SET " . implode(',', $updates) . " WHERE id=?";
        $types .= 'i';
        $params[] = $oid;

        $st = $mysqli->prepare($sql);
        if (!$st) {
            set_flash_and_redirect('error_message', 'تعذر إرسال طلب الإلغاء حاليًا.', $returnLink);
        }
        $st->bind_param($types, ...$params);
        $st->execute();
        $st->close();

        notify_user(
            $mysqli,
            $otherUserId,
            'order_update',
            'طلب إلغاء جديد',
            'تم إرسال طلب إلغاء للطلب رقم: ' . ($order_now['order_number'] ?? ''),
            $returnLink
        );

        set_flash_and_redirect('success_message', 'تم إرسال طلب الإلغاء بنجاح.', $returnLink);
    }

    /* 11) Open dispute from order details */
    if ($action === 'open_dispute') {
        $returnLink = 'order_details.php?order_id=' . $oid;

        if (!can_open_dispute($order_now)) {
            set_flash_and_redirect('error_message', 'لا يمكن فتح نزاع في هذه المرحلة.', $returnLink);
        }

        $reason = trim((string)($_POST['dispute_reason'] ?? ''));
        if ($reason === '') {
            $reason = 'تم فتح نزاع من صفحة تفاصيل الطلب';
        }

        $otherUserId = $isBuyer ? (int)$order_now['seller_id'] : (int)$order_now['buyer_id'];
        $updates = [];
        $types = '';
        $params = [];

        if (has_col($mysqli, 'orders', 'order_status') && enum_has($mysqli, 'orders', 'order_status', 'disputed')) {
            $updates[] = "order_status=?";
            $types .= 's';
            $params[] = 'disputed';
        }

        if ($updates) {
            $sql = "UPDATE orders SET " . implode(',', $updates) . " WHERE id=?";
            $types .= 'i';
            $params[] = $oid;
            $st = $mysqli->prepare($sql);
            if ($st) {
                $st->bind_param($types, ...$params);
                $st->execute();
                $st->close();
            }
        }

        $disputeCols = [];
        $disputeVals = [];
        $disputeTypes = '';
        $disputeParams = [];

        if (has_col($mysqli, 'order_disputes', 'order_id')) {
            $disputeCols[] = 'order_id';
            $disputeVals[] = '?';
            $disputeTypes .= 'i';
            $disputeParams[] = $oid;
        }
        if (has_col($mysqli, 'order_disputes', 'reason')) {
            $disputeCols[] = 'reason';
            $disputeVals[] = '?';
            $disputeTypes .= 's';
            $disputeParams[] = $reason;
        }
        if (has_col($mysqli, 'order_disputes', 'status')) {
            $statusValue = enum_has($mysqli, 'order_disputes', 'status', 'open') ? 'open' : 'pending';
            $disputeCols[] = 'status';
            $disputeVals[] = '?';
            $disputeTypes .= 's';
            $disputeParams[] = $statusValue;
        }
        if (has_col($mysqli, 'order_disputes', 'opened_by')) {
            $disputeCols[] = 'opened_by';
            $disputeVals[] = '?';
            $disputeTypes .= 's';
            $disputeParams[] = $isBuyer ? 'buyer' : 'seller';
        }
        if (has_col($mysqli, 'order_disputes', 'user_id')) {
            $disputeCols[] = 'user_id';
            $disputeVals[] = '?';
            $disputeTypes .= 'i';
            $disputeParams[] = $userId;
        }
        if (has_col($mysqli, 'order_disputes', 'created_by')) {
            $disputeCols[] = 'created_by';
            $disputeVals[] = '?';
            $disputeTypes .= 'i';
            $disputeParams[] = $userId;
        }
        if (has_col($mysqli, 'order_disputes', 'created_at')) {
            $disputeCols[] = 'created_at';
            $disputeVals[] = 'NOW()';
        }
        if (has_col($mysqli, 'order_disputes', 'updated_at')) {
            $disputeCols[] = 'updated_at';
            $disputeVals[] = 'NOW()';
        }

        if ($disputeCols) {
            $sql = "INSERT INTO order_disputes (" . implode(',', $disputeCols) . ") VALUES (" . implode(',', $disputeVals) . ")";
            $st = $mysqli->prepare($sql);
            if ($st) {
                if ($disputeTypes !== '') {
                    $st->bind_param($disputeTypes, ...$disputeParams);
                }
                $st->execute();
                $st->close();
            }
        }

        record_order_log($mysqli, $oid, 'dispute_opened');

        notify_user(
            $mysqli,
            $otherUserId,
            'order_update',
            'تم فتح نزاع',
            'تم فتح نزاع على الطلب رقم: ' . ($order_now['order_number'] ?? ''),
            'disputes.php?order_id=' . $oid
        );

        set_flash_and_redirect('success_message', 'تم فتح النزاع بنجاح.', $returnLink);
    }
}

/* =========================
   Refresh order after actions check
========================= */
$stmt = $mysqli->prepare("
    SELECT 
        o.*,
        l.id AS listing_exists_id,
        l.public_id,
        l.title AS product_title,
        l.status AS listing_status,
        COALESCE(l.listing_type, 'physical') AS listing_type,
        u1.name AS buyer_name,
        u2.name AS seller_name
    FROM orders o
    LEFT JOIN listings l ON o.listing_id = l.id
    INNER JOIN users u1 ON o.buyer_id = u1.id
    INNER JOIN users u2 ON o.seller_id = u2.id
    WHERE o.id=?
    LIMIT 1
");
$stmt->bind_param("i", $oid);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

$isSeller = ($userId === (int)$order['seller_id']);
$isBuyer  = ($userId === (int)$order['buyer_id']);

$deliveryMode   = normalize_delivery_mode($order['delivery_type'] ?? '', $order['shipping_type'] ?? '', $order['shipping_fee'] ?? 0);
$deliveryStatus = strtolower(trim((string)($order['delivery_status'] ?? '')));
$orderStatus    = strtolower(trim((string)($order['order_status'] ?? '')));
$listingType    = strtolower(trim((string)($order['listing_type'] ?? 'physical')));
$orderStage     = order_stage_label($order, $isSeller, $isBuyer);
$stageClass     = stage_badge_class($orderStage);
$sellerNet      = calc_seller_net($order);

$listingExists = !empty($order['listing_exists_id']);
$listingStatus = strtolower(trim((string)($order['listing_status'] ?? '')));
$listingMissingOrUnavailable = (!$listingExists || in_array($listingStatus, ['deleted', 'deleted_by_user', 'blocked'], true));

$listingUrl = (!$listingMissingOrUnavailable && !empty($order['listing_exists_id']))
    ? ('product.php?id=' . (int)$order['listing_exists_id'])
    : '';

$showSellerShippingForm = $isSeller
    && $deliveryMode !== 'manual'
    && $listingType === 'physical'
    && ($order['payment_status'] ?? '') === 'paid'
    && empty($order['buyer_received_at'])
    && empty($order['completed_at'])
    && !in_array($deliveryStatus, ['shipped', 'received', 'delivered'], true);

$showSellerCreateCode = $isSeller
    && $deliveryMode === 'manual'
    && ($order['payment_status'] ?? '') === 'paid'
    && empty($order['delivery_code'])
    && empty($order['buyer_received_at']);

$showBuyerManualCodeInfo = $isBuyer
    && $deliveryMode === 'manual'
    && !empty($order['delivery_code'])
    && empty($order['buyer_received_at']);

$showSellerManualInput = $isSeller
    && $deliveryMode === 'manual'
    && !empty($order['delivery_code'])
    && empty($order['buyer_received_at']);

$showSellerDigitalDelivered = $isSeller
    && $listingType === 'digital'
    && ($order['payment_status'] ?? '') === 'paid'
    && empty($order['buyer_received_at'])
    && !in_array($orderStatus, ['shipped', 'delivered'], true);

$showBuyerConfirmDigital = $isBuyer
    && $listingType === 'digital'
    && ($order['payment_status'] ?? '') === 'paid'
    && empty($order['buyer_received_at'])
    && !in_array($orderStatus, ['completed', 'cancelled', 'refunded', 'disputed'], true)
    && (in_array($orderStatus, ['shipped', 'delivered'], true) || $deliveryStatus === 'delivered');

$showSellerStartService = $isSeller
    && $listingType === 'service'
    && ($order['payment_status'] ?? '') === 'paid'
    && $orderStatus === 'paid'
    && empty($order['buyer_received_at']);

$showSellerFinishService = $isSeller
    && $listingType === 'service'
    && ($order['payment_status'] ?? '') === 'paid'
    && empty($order['buyer_received_at'])
    && !in_array($orderStatus, ['shipped', 'delivered', 'completed', 'cancelled', 'refunded', 'disputed'], true);

$showBuyerConfirmService = $isBuyer
    && $listingType === 'service'
    && ($order['payment_status'] ?? '') === 'paid'
    && empty($order['buyer_received_at'])
    && !in_array($orderStatus, ['completed', 'cancelled', 'refunded', 'disputed'], true)
    && (in_array($orderStatus, ['shipped', 'delivered'], true) || $deliveryStatus === 'delivered');

$showBuyerConfirmPhysical = $isBuyer
    && $listingType === 'physical'
    && $deliveryMode !== 'manual'
    && ($order['payment_status'] ?? '') === 'paid'
    && empty($order['buyer_received_at'])
    && !in_array($orderStatus, ['completed', 'cancelled', 'refunded', 'disputed'], true)
    && ($deliveryStatus === 'shipped' || $orderStatus === 'shipped');

$cancelPending = (($order['cancellation_status'] ?? 'none') === 'pending');
$canCancel     = can_request_cancellation($order);
$canDispute    = can_open_dispute($order);
$pageFlash     = build_page_flash();
$buyerPhone    = fetch_user_phone($mysqli, (int)($order['buyer_id'] ?? 0));
$sellerPhone   = fetch_user_phone($mysqli, (int)($order['seller_id'] ?? 0));
$orderAddress  = fetch_order_address($mysqli, $oid);
$shippingPhone = trim((string)($orderAddress['phone'] ?? ''));
if ($shippingPhone !== '' && $buyerPhone === '') {
    $buyerPhone = $shippingPhone;
}
$existingSellerRating = fetch_existing_seller_rating($mysqli, $oid, (int)($order['buyer_id'] ?? 0), (int)($order['seller_id'] ?? 0));
$buyerCanRateSeller = $isBuyer
    && table_exists($mysqli, 'ratings')
    && (!empty($order['buyer_received_at']) || !empty($order['completed_at']) || in_array($orderStatus, ['completed'], true))
    && !in_array($orderStatus, ['cancelled', 'refunded', 'disputed'], true);

$proofs = fetch_order_proofs($mysqli, $oid);
$canUploadProof = $isSeller
    && ($order['payment_status'] ?? '') === 'paid'
    && empty($order['buyer_received_at'])
    && (
        ($listingType === 'physical' && $deliveryMode !== 'manual')
        || in_array($listingType, ['digital', 'service'], true)
    );

$paid_at = null;
$stmtPaid = $mysqli->prepare("
    SELECT updated_at, created_at
    FROM payment_attempts
    WHERE order_id = ? AND status = 'paid'
    ORDER BY id DESC
    LIMIT 1
");
if ($stmtPaid) {
    $stmtPaid->bind_param("i", $oid);
    $stmtPaid->execute();
    $paidRow = $stmtPaid->get_result()->fetch_assoc();
    if ($paidRow) {
        $paid_at = !empty($paidRow['updated_at']) ? $paidRow['updated_at'] : ($paidRow['created_at'] ?? null);
    }
    $stmtPaid->close();
}

/* =========================
   Timeline
========================= */
$timeline = [
    ['label' => 'تم الدفع وإنشاء الطلب', 'time' => $order['created_at'] ?? ($paid_at ?: null)],
];

if ($listingType === 'service' && !empty($order['seller_confirmed_at'])) {
    $timeline[] = ['label' => 'تم بدء التنفيذ', 'time' => $order['seller_confirmed_at']];
}

if (!empty($order['seller_shipped_at'])) {
    $sellerActionLabel = 'تم الشحن';
    if ($deliveryMode === 'manual') {
        $sellerActionLabel = 'تم إنشاء رمز التسليم';
    } elseif ($listingType === 'service') {
        $sellerActionLabel = 'تم تنفيذ الخدمة';
    } elseif ($listingType === 'digital') {
        $sellerActionLabel = 'تم تسليم المنتج الرقمي';
    }

    $timeline[] = ['label' => $sellerActionLabel, 'time' => $order['seller_shipped_at']];
}

if (!empty($order['buyer_received_at']) || !empty($order['completed_at'])) {
    $timeline[] = ['label' => 'تم الاستلام واكتمال الطلب', 'time' => ($order['buyer_received_at'] ?? $order['completed_at'] ?? null)];
}

if (!empty($order['completed_at']) && (!$order['buyer_received_at'] || $order['completed_at'] !== $order['buyer_received_at'])) {
    $timeline[] = ['label' => 'تم تحويل المبلغ', 'time' => $order['completed_at']];
}

if (!empty($order['cancelled_at'])) {
    $timeline[] = ['label' => 'تم إلغاء الطلب', 'time' => $order['cancelled_at']];
}

if (($order['order_status'] ?? '') === 'disputed' && table_exists($mysqli, 'order_disputes')) {
    $disputeTime = null;
    if (has_col($mysqli, 'order_disputes', 'order_id') && has_col($mysqli, 'order_disputes', 'created_at')) {
        $disputeStmt = $mysqli->prepare("SELECT created_at FROM order_disputes WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        if ($disputeStmt) {
            $disputeStmt->bind_param('i', $oid);
            $disputeStmt->execute();
            $disputeRow = $disputeStmt->get_result()->fetch_assoc();
            $disputeStmt->close();
            if ($disputeRow && !empty($disputeRow['created_at'])) {
                $disputeTime = $disputeRow['created_at'];
            }
        }
    }

    $timeline[] = [
        'label' => 'تم فتح نزاع على الطلب',
        'time'  => $disputeTime ?: ($order['updated_at'] ?? null),
    ];
}

if (!empty($existingSellerRating['created_at'])) {
    $timeline[] = ['label' => 'تم تقييم البائع', 'time' => $existingSellerRating['created_at']];
}

$timelineUnique = [];
$timelineSeen = [];
foreach ($timeline as $item) {
    $key = trim((string)($item['label'] ?? '')) . '|' . trim((string)($item['time'] ?? ''));
    if (isset($timelineSeen[$key])) {
        continue;
    }
    $timelineSeen[$key] = true;
    $timelineUnique[] = $item;
}
$timeline = $timelineUnique;

usort($timeline, static function ($a, $b) {
    $ta = strtotime((string)($a['time'] ?? '')) ?: 0;
    $tb = strtotime((string)($b['time'] ?? '')) ?: 0;
    return $ta <=> $tb;
});

$conversationUrl = resolve_order_conversation_url($mysqli, $order);
?>

<div class="container my-4">
    <div class="card shadow-sm border-0 rounded-4 order-details-card">
        <div class="card-body p-4">

            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                <div>
                    <h3 class="mb-1 text-primary fw-bold">تفاصيل الطلب</h3>
                    <div class="text-muted small">مراجعة تفاصيل الصفقة وإكمال الإجراءات المتاحة.</div>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <a href="<?php echo h($conversationUrl); ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3">فتح المحادثة المرتبطة بالطلب</a>
                    <span class="badge <?= h($stageClass) ?> order-stage-badge"><?php echo h($orderStage); ?></span>
                </div>
            </div>

            <?php if (!empty($listingAlert)): ?>
                <div class="alert alert-<?php echo h($listingAlert['type']); ?> js-auto-dismiss-alert shadow-sm">
                    <?php echo h($listingAlert['text']); ?>
                </div>
            <?php endif; ?>

            <?php if (($order['order_status'] ?? '') === 'disputed'): ?>
                <div class="alert alert-danger rounded-4 mb-4">
                    هذا الطلب عليه نزاع حاليًا. يمكنك متابعته من صفحة النزاعات.
                </div>
            <?php endif; ?>

            <?php if (!empty($pageFlash)): ?>
                <div class="alert alert-<?php echo h($pageFlash['type']); ?> js-auto-dismiss-alert shadow-sm">
                    <?php echo h($pageFlash['text']); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($order['buyer_received_at']) && $isSeller && empty($order['completed_at'])): ?>
                <div class="alert alert-success rounded-4 mb-4">
                    تم تسليم الطلب للمشتري، وجارٍ تحويل المبلغ إلى حسابك من الإدارة.
                </div>
            <?php elseif (!empty($order['completed_at']) && $isSeller): ?>
                <div class="alert alert-success rounded-4 mb-4">
                    تم تحويل المبلغ بنجاح، وسيظهر الطلب ضمن طلباتك المكتملة.
                </div>
            <?php elseif ((!empty($order['buyer_received_at']) || !empty($order['completed_at'])) && $isBuyer): ?>
                <div class="alert alert-success rounded-4 mb-4">
                    الطلب مكتمل بنجاح. سيتم عرض الطلب ضمن طلباتك المكتملة.
                </div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-lg-3 col-md-6">
                    <div class="info-box">
                        <div class="info-label">رقم الطلب</div>
                        <div class="info-value"><?php echo h(displayOrderNumber($order['order_number'] ?? '', (int)($order['id'] ?? 0))); ?></div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="info-box">
                        <div class="info-label">رقم الإعلان</div>
                        <div class="info-value"><?php echo h($order['public_id'] ?? '—'); ?></div>
                    </div>
                </div>

                <div class="col-lg-6 col-md-12">
                    <div class="info-box">
                        <div class="info-label">عنوان الإعلان</div>
                        <div class="info-value">
                            <?php if (!$listingMissingOrUnavailable && $listingUrl !== ''): ?>
                                <a href="<?php echo h($listingUrl); ?>" class="text-decoration-none fw-bold">
                                    <?php echo h($order['product_title'] ?? 'عرض الإعلان'); ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">الإعلان لم يعد موجودًا</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="info-box">
                        <div class="info-label">المشتري</div>
                        <div class="info-value"><?php echo h($order['buyer_name']); ?></div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="info-box">
                        <div class="info-label">البائع</div>
                        <div class="info-value"><?php echo h($order['seller_name']); ?></div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6">
    <div class="info-box">
        <div class="info-label">دورك</div>
        <div class="info-value"><?php echo $isSeller ? 'بائع' : 'مشتري'; ?></div>
    </div>
</div>

                <div class="col-lg-4 col-md-6">
                    <div class="info-box">
                        <div class="info-label">تاريخ ووقت الطلب</div>
                        <div class="info-value"><?php echo h(fmt_datetime($order['created_at'] ?? null)); ?></div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="info-box">
                        <div class="info-label">آخر تحديث</div>
                        <div class="info-value"><?php echo h(fmt_datetime($order['updated_at'] ?? null)); ?></div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="info-box">
                        <div class="info-label">طريقة التسليم</div>
                        <div class="info-value"><?php echo h(primary_delivery_label($order['delivery_type'] ?? '', $order['shipping_type'] ?? '', $order['shipping_fee'] ?? 0)); ?></div>
                    </div>
                </div>
            </div>

            <div class="section-title">معلومات التواصل</div>
<div class="row g-3 mb-4">

    <?php
    $otherPhone = '';

    if ($isBuyer) {
        $otherPhone = $sellerPhone ?? '';
        $label = 'جوال البائع';
    } elseif ($isSeller) {
        $otherPhone = $buyerPhone ?? '';
        $label = 'جوال المشتري';
    }
    ?>

    <?php if (!empty($otherPhone)): ?>
        <div class="col-lg-4 col-md-6">
            <div class="info-box">
                <div class="info-label"><?php echo h($label); ?></div>
                <div class="info-value">
                    <span dir="ltr"><?php echo h($otherPhone); ?></span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (($order['delivery_type'] ?? '') === 'shipping'): ?>
        <div class="col-lg-4 col-md-12">
            <div class="info-box">
                <div class="info-label">عنوان الشحن</div>
                <div class="info-value">
                    <?php echo h(!empty($orderAddress['formatted']) ? $orderAddress['formatted'] : '—'); ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<div class="section-title">تفاصيل الفاتورة</div>
            <div class="row g-3 mb-4">
                <div class="col-lg-3 col-md-6">
                    <div class="info-box">
                        <div class="info-label">قيمة السلعة</div>
                        <div class="info-value"><?php echo h(money($order['subtotal'] ?? 0, $order['currency'] ?? 'SAR')); ?></div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="info-box">
                        <div class="info-label">الشحن</div>
                        <div class="info-value"><?php echo h(money($order['shipping_fee'] ?? 0, $order['currency'] ?? 'SAR')); ?></div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="info-box">
                        <div class="info-label">عمولة ضمان بلس</div>
                        <div class="info-value"><?php echo h(money($order['platform_fee'] ?? 0, $order['currency'] ?? 'SAR')); ?></div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="info-box">
                        <div class="info-label">الإجمالي</div>
                        <div class="info-value"><?php echo h(money($order['total_amount'] ?? 0, $order['currency'] ?? 'SAR')); ?></div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="info-box">
                        <div class="info-label">الصافي للبائع</div>
                        <div class="info-value text-success"><?php echo h(money($sellerNet, $order['currency'] ?? 'SAR')); ?></div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="info-box">
                        <div class="info-label">حالة الدفع</div>
                        <div class="info-value"><?php echo (($order['payment_status'] ?? '') === 'paid') ? 'مدفوع' : h($order['payment_status'] ?? '—'); ?></div>
                    </div>
                </div>

                <?php if (!empty($order['carrier_name']) && $deliveryMode !== 'manual'): ?>
                    <div class="col-lg-3 col-md-6">
                        <div class="info-box">
                            <div class="info-label">شركة الشحن</div>
                            <div class="info-value"><?php echo h($order['carrier_name']); ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($order['tracking_number']) && $deliveryMode !== 'manual'): ?>
                    <div class="col-lg-3 col-md-6">
                        <div class="info-box">
                            <div class="info-label">رقم التتبع</div>
                            <div class="info-value"><?php echo h($order['tracking_number']); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($order['notes'])): ?>
                <div class="section-title">ملاحظات</div>
                <div class="info-box mb-4">
                    <div class="info-value"><?php echo nl2br(h($order['notes'])); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($canUploadProof || !empty($proofs)): ?>
                <div class="section-title">مرفقات الإثبات</div>

                <?php if ($canUploadProof): ?>
                    <div class="action-panel mb-3">
                        <div class="action-title">رفع إثبات</div>
                        <div class="action-note">يمكنك رفع صورة أو ملف PDF لإثبات الشحن أو التنفيذ أو التسليم.</div>

                        <form action="order_details.php?order_id=<?php echo (int)$order['id']; ?>" method="post" enctype="multipart/form-data" class="mt-3">
                            <input type="hidden" name="action" value="upload_proof">

                            <div class="mb-3">
                                <label class="form-label">اختر المرفق</label>
                                <input type="file" name="proof_file" class="form-control" accept="image/*,.pdf" required>
                            </div>

                            <button type="submit" class="btn btn-outline-primary w-100">رفع المرفق</button>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if (!empty($proofs)): ?>
                    <div class="row g-3 mb-4 proof-grid">
                        <?php foreach ($proofs as $proof): ?>
                            <?php
                                $proofPath = trim((string)($proof['proof_file'] ?? $proof['file_path'] ?? $proof['file_url'] ?? ''));
                                $proofName = trim((string)($proof['file_name'] ?? ''));
                                $proofMime = strtolower(trim((string)($proof['mime_type'] ?? '')));
                                $proofTime = $proof['created_at'] ?? null;
                                $proofExt  = strtolower(pathinfo($proofPath, PATHINFO_EXTENSION));
                                $isPdfProof = ($proofExt === 'pdf') || str_contains($proofMime, 'pdf');
                            ?>
                            <div class="col-lg-4 col-md-6">
                                <div class="info-box proof-item h-100">
                                    <div class="info-label">مرفق إثبات</div>
                                    <div class="info-value small mb-2"><?php echo h($proofName !== '' ? $proofName : basename($proofPath)); ?></div>
                                    <?php if ($proofPath !== '' && !$isPdfProof): ?>
                                        <a href="<?php echo h($proofPath); ?>" target="_blank" class="d-block mb-2">
                                            <img src="<?php echo h($proofPath); ?>" alt="مرفق إثبات" class="img-fluid rounded-3 border proof-thumb">
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($proofPath !== ''): ?>
                                        <a href="<?php echo h($proofPath); ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill">فتح المرفق</a>
                                    <?php endif; ?>
                                    <div class="small text-muted mt-2"><?php echo $isPdfProof ? 'ملف PDF' : 'صورة'; ?></div>
                                    <?php if (!empty($proofTime)): ?>
                                        <div class="small text-muted mt-1"><?php echo h(fmt_datetime($proofTime)); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="section-title">الإجراءات المتاحة</div>

            <?php if (($order['payment_status'] ?? '') !== 'paid'): ?>
                <div class="alert alert-secondary rounded-4 mb-4">
                    هذا الطلب لم يُدفع بعد، لذلك لا توجد إجراءات متاحة حاليًا.
                </div>

            <?php elseif (!empty($order['buyer_received_at']) || !empty($order['completed_at'])): ?>
                <div class="alert alert-secondary rounded-4 mb-4">
                    الطلب مكتمل ولا توجد إجراءات متاحة حاليًا.
                </div>

            <?php elseif ($showSellerShippingForm): ?>
                <div class="action-panel mb-4">
                    <div class="action-title">إكمال إجراءات الشحن</div>
                    <div class="action-note">أدخل بيانات الشحن ثم اضغط تم الشحن.</div>

                    <form action="order_details.php?order_id=<?php echo (int)$order['id']; ?>" method="post" class="mt-3">
                        <input type="hidden" name="action" value="seller_mark_shipped">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">اسم شركة الشحن</label>
                                <input type="text" name="carrier_name" class="form-control" value="<?php echo h($order['carrier_name'] ?? ''); ?>" placeholder="مثال: سمسا / أرامكس">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">رقم التتبع</label>
                                <input type="text" name="tracking_number" class="form-control" value="<?php echo h($order['tracking_number'] ?? ''); ?>" placeholder="أدخل رقم التتبع">
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">ملاحظات (اختياري)</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="أدخل أي ملاحظات إضافية"><?php echo h($order['notes'] ?? ''); ?></textarea>
                            </div>

                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary w-100">تم الشحن</button>
                            </div>
                        </div>
                    </form>
                </div>

            <?php elseif ($showBuyerConfirmPhysical): ?>
                <div class="action-panel mb-4">
                    <div class="action-title">تأكيد الاستلام</div>
                    <div class="action-note">بعد استلام الشحنة فعليًا اضغط الزر التالي لتأكيد الاستلام.</div>

                    <form action="order_details.php?order_id=<?php echo (int)$order['id']; ?>" method="post" class="mt-3">
                        <input type="hidden" name="action" value="buyer_confirm_received">
                        <button type="submit" class="btn btn-success w-100">تم الاستلام</button>
                    </form>
                </div>

            <?php elseif ($showSellerCreateCode): ?>
                <div class="action-panel mb-4">
                    <div class="action-title">إنشاء رمز التسليم</div>
                    <div class="action-note">أنشئ رمز التسليم ليتم استخدامه مع المشتري عند اللقاء.</div>

                    <form action="order_details.php?order_id=<?php echo (int)$order['id']; ?>" method="post" class="mt-3">
                        <input type="hidden" name="action" value="seller_create_manual_code">
                        <button type="submit" class="btn btn-primary w-100">إنشاء رمز التسليم</button>
                    </form>
                </div>

            <?php elseif ($showBuyerManualCodeInfo): ?>
                <div class="alert alert-info rounded-4 mb-4 text-center">
                    <div class="fw-bold mb-2">رمز التسليم الحالي</div>
                    <div class="delivery-code-box"><?php echo h($order['delivery_code']); ?></div>
                    <div class="small text-muted mt-2">سيعرض لك هذا الرمز بعد إنشائه من البائع، ويقوم البائع بإدخاله عند إتمام التسليم.</div>
                </div>

            <?php elseif ($showSellerManualInput): ?>
                <div class="action-panel mb-4">
                    <div class="action-title">إدخال رمز التسليم</div>
                    <div class="action-note">أدخل رمز التسليم الظاهر للمشتري عند اللقاء لإتمام التسليم.</div>

                    <form action="order_details.php?order_id=<?php echo (int)$order['id']; ?>" method="post" class="mt-3">
                        <input type="hidden" name="action" value="seller_confirm_manual_code">

                        <div class="mb-3">
                            <label class="form-label">رمز التسليم</label>
                            <input type="text" name="delivery_code" class="form-control text-center" maxlength="4" placeholder="0000" required>
                        </div>

                        <button type="submit" class="btn btn-success w-100">تأكيد التسليم</button>
                    </form>
                </div>

            <?php elseif ($showSellerDigitalDelivered): ?>
                <div class="action-panel mb-4">
                    <div class="action-title">تسليم المنتج الرقمي</div>
                    <div class="action-note">بعد تسليم المنتج للمشتري سجل ذلك من هنا.</div>

                    <form action="order_details.php?order_id=<?php echo (int)$order['id']; ?>" method="post" class="mt-3">
                        <input type="hidden" name="action" value="seller_mark_digital_delivered">

                        <div class="mb-3">
                            <label class="form-label">ملاحظات (اختياري)</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="أدخل ملاحظات تتعلق بالتسليم"><?php echo h($order['notes'] ?? ''); ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">تم التسليم</button>
                    </form>
                </div>

            <?php elseif ($showBuyerConfirmDigital): ?>
                <div class="action-panel mb-4">
                    <div class="action-title">تأكيد الاستلام</div>
                    <div class="action-note">بعد استلام المنتج الرقمي بشكل صحيح اضغط الزر التالي.</div>

                    <form action="order_details.php?order_id=<?php echo (int)$order['id']; ?>" method="post" class="mt-3">
                        <input type="hidden" name="action" value="buyer_confirm_received">
                        <button type="submit" class="btn btn-success w-100">تم الاستلام</button>
                    </form>
                </div>

            <?php elseif ($showSellerFinishService): ?>
                <div class="action-panel mb-4">
                    <div class="action-title">إنهاء تنفيذ الخدمة</div>
                    <div class="action-note">بعد إكمال تنفيذ الخدمة للمشتري اضغط الزر التالي.</div>

                    <form action="order_details.php?order_id=<?php echo (int)$order['id']; ?>" method="post" class="mt-3">
                        <input type="hidden" name="action" value="seller_finish_service">

                        <div class="mb-3">
                            <label class="form-label">ملاحظات (اختياري)</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="أدخل ملاحظات تتعلق بتنفيذ الخدمة"><?php echo h($order['notes'] ?? ''); ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">تم التنفيذ</button>
                    </form>
                </div>

            <?php elseif ($showSellerStartService): ?>
                <div class="action-panel mb-4">
                    <div class="action-title">بدء تنفيذ الخدمة</div>
                    <div class="action-note">استخدم هذا الإجراء عند البدء الفعلي في تنفيذ الخدمة.</div>

                    <form action="order_details.php?order_id=<?php echo (int)$order['id']; ?>" method="post" class="mt-3">
                        <input type="hidden" name="action" value="seller_start_service">
                        <button type="submit" class="btn btn-outline-primary w-100">بدء التنفيذ</button>
                    </form>
                </div>

            <?php elseif ($showBuyerConfirmService): ?>
                <div class="action-panel mb-4">
                    <div class="action-title">تأكيد استلام الخدمة</div>
                    <div class="action-note">بعد استلام الخدمة والانتهاء منها بشكل صحيح اضغط الزر التالي.</div>

                    <form action="order_details.php?order_id=<?php echo (int)$order['id']; ?>" method="post" class="mt-3">
                        <input type="hidden" name="action" value="buyer_confirm_received">
                        <button type="submit" class="btn btn-success w-100">تم الاستلام</button>
                    </form>
                </div>

            <?php else: ?>
                <div class="alert alert-secondary rounded-4 mb-4">
                    لا توجد إجراءات متاحة حاليًا على هذا الطلب.
                </div>
            <?php endif; ?>

            <?php if ($cancelPending): ?>
                <div class="alert alert-warning rounded-4 mb-4">
                    يوجد طلب إلغاء معلق على هذا الطلب بانتظار المراجعة.
                </div>
            <?php elseif ($canCancel): ?>
                <div class="action-panel mb-4">
                    <div class="action-title">طلب إلغاء</div>
                    <div class="action-note">يمكنك إرسال طلب إلغاء قبل الشحن أو التسليم وفقًا لحالة الطلب الحالية.</div>

                    <form action="order_details.php?order_id=<?php echo (int)$order['id']; ?>" method="post" class="mt-3">
                        <input type="hidden" name="action" value="request_cancellation">

                        <div class="mb-3">
                            <label class="form-label">سبب الإلغاء (اختياري)</label>
                            <textarea name="cancellation_reason" class="form-control" rows="3" placeholder="اكتب سبب الإلغاء إن رغبت"></textarea>
                        </div>

                        <button type="submit" class="btn btn-outline-danger w-100">طلب إلغاء</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($canDispute): ?>
                <div class="action-panel mb-4">
                    <div class="action-title">فتح نزاع</div>
                    <div class="action-note">استخدم هذا الإجراء إذا وُجدت مشكلة فعلية في الطلب بعد الدفع أو أثناء التنفيذ.</div>

                    <form action="order_details.php?order_id=<?php echo (int)$order['id']; ?>" method="post" class="mt-3">
                        <input type="hidden" name="action" value="open_dispute">

                        <div class="mb-3">
                            <label class="form-label">سبب النزاع</label>
                            <textarea name="dispute_reason" class="form-control" rows="3" placeholder="اكتب سبب النزاع" required></textarea>
                        </div>

                        <button type="submit" class="btn btn-outline-danger w-100">فتح نزاع</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($buyerCanRateSeller): ?>
                <div class="section-title">تقييم البائع</div>
                <div class="action-panel mb-4">
                    <div class="action-title"><?php echo !empty($existingSellerRating) ? 'تقييمك الحالي للبائع' : 'أضف تقييمك للبائع'; ?></div>
                    <div class="action-note mb-3">يظهر هذا التقييم في صفحة البائع ويساعد المشترين الآخرين.</div>

                    <form action="order_details.php?order_id=<?php echo (int)$order['id']; ?>" method="post">
                        <input type="hidden" name="action" value="submit_seller_rating">

                        <div class="mb-3">
                            <label class="form-label">عدد النجوم</label>
                            <select name="seller_rating" class="form-select" required>
                                <option value="">اختر التقييم</option>
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ((int)($existingSellerRating['rating'] ?? 0) === $i) ? 'selected' : ''; ?>>
                                        <?php echo $i; ?> نجوم
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">تعليقك (اختياري)</label>
                            <textarea name="seller_rating_comment" class="form-control" rows="3" placeholder="اكتب رأيك في التعامل وجودة التنفيذ"><?php echo h($existingSellerRating['comment'] ?? ''); ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-warning w-100 text-dark fw-bold"><?php echo !empty($existingSellerRating) ? 'تحديث التقييم' : 'إرسال التقييم'; ?></button>
                    </form>
                </div>
            <?php elseif (!$isBuyer && !empty($existingSellerRating)): ?>
                <div class="section-title">تقييم البائع</div>
                <div class="info-box mb-4">
                    <div class="info-label">تقييم المشتري</div>
                    <div class="info-value mb-2"><?php echo h((string)($existingSellerRating['rating'] ?? '')); ?> / 5</div>
                    <?php if (!empty($existingSellerRating['comment'])): ?>
                        <div class="small text-muted"><?php echo nl2br(h($existingSellerRating['comment'])); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="section-title">السجل الزمني المختصر</div>
            <div class="timeline-list">
                <?php foreach ($timeline as $item): ?>
                    <?php if (!empty($item['time'])): ?>
                        <div class="timeline-item">
                            <div class="timeline-dot"></div>
                            <div class="timeline-content">
                                <div class="timeline-title"><?php echo h($item['label']); ?></div>
                                <div class="timeline-time"><?php echo h(fmt_datetime($item['time'])); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

        </div>
    </div>
</div>

<style>
.order-details-card {
    background: #fff;
}

.section-title {
    font-size: 18px;
    font-weight: 800;
    color: #1f2937;
    margin-bottom: 14px;
}

.info-box {
    background: #f8f9fa;
    border: 1px solid #eceff3;
    border-radius: 16px;
    padding: 14px 16px;
    height: 100%;
}

.info-label {
    font-size: 13px;
    color: #6c757d;
    margin-bottom: 6px;
}

.info-value {
    font-size: 15px;
    font-weight: 600;
    color: #212529;
    line-height: 1.7;
    word-break: break-word;
}

.order-stage-badge {
    font-size: 13px;
    font-weight: 700;
    padding: 10px 14px;
    border-radius: 999px;
}

.action-panel {
    background: #f8fbff;
    border: 1px solid #e4edf8;
    border-radius: 18px;
    padding: 18px;
}

.action-title {
    font-size: 17px;
    font-weight: 800;
    color: #1f2937;
    margin-bottom: 6px;
}

.action-note {
    font-size: 13px;
    color: #6b7280;
}

.delivery-code-box {
    display: inline-block;
    padding: 10px 18px;
    background: #fff;
    border: 1px dashed #0d6efd;
    border-radius: 14px;
    font-size: 30px;
    font-weight: 800;
    letter-spacing: 6px;
    color: #0d6efd;
}

.timeline-list {
    border-right: 2px solid #e9ecef;
    padding-right: 18px;
}

.timeline-item {
    position: relative;
    padding: 0 0 18px 0;
}

.timeline-dot {
    position: absolute;
    right: -25px;
    top: 4px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #0d6efd;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px #dbeafe;
}

.timeline-content {
    background: #fbfcfe;
    border: 1px solid #eef2f7;
    border-radius: 14px;
    padding: 12px 14px;
}

.timeline-title {
    font-size: 14px;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 4px;
}

.timeline-time {
    font-size: 12px;
    color: #6b7280;
}

.js-auto-dismiss-alert {
    transition: opacity .35s ease, transform .35s ease;
}

.js-auto-dismiss-alert.hide-alert {
    opacity: 0;
    transform: translateY(-8px);
}

@media (max-width: 768px) {
    .info-value {
        font-size: 14px;
    }

    .delivery-code-box {
        font-size: 24px;
        letter-spacing: 4px;
        padding: 8px 14px;
    }

    .timeline-list {
        padding-right: 14px;
    }

    .timeline-dot {
        right: -21px;
    }
}

.proof-thumb {
    max-height: 220px;
    width: 100%;
    object-fit: cover;
    background: #f8f9fa;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const alerts = document.querySelectorAll('.js-auto-dismiss-alert');
    alerts.forEach(function (alertBox) {
        setTimeout(function () {
            alertBox.classList.add('hide-alert');
            setTimeout(function () {
                alertBox.remove();
            }, 400);
        }, 3000);
    });
});
</script>

<?php include 'footer.php'; ?>