<?php
/**
 * Обработчик HTTP-уведомлений (Webhook) от ЮKassa.
 * Всегда перепроверяет статус платежа прямым запросом GET /v3/payments/{id} к API ЮKassa.
 */
declare(strict_types=1);

require_once __DIR__ . '/_storage_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payCfg = ev_load_payment_config();
$yk = $payCfg['yookassa'];
if (empty($yk['enabled'])) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'YooKassa not configured'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '{}', true);
$paymentId = is_array($payload) ? ev_clean_line($payload['object']['id'] ?? '') : '';

if (!preg_match('/^[A-Za-z0-9_-]{10,80}$/', $paymentId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payment id'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Серверная верификация платежа непосредственно через API ЮKassa
$verify = ev_http_json(
    'https://api.yookassa.ru/v3/payments/' . rawurlencode($paymentId),
    'GET',
    ['Accept: application/json'],
    null,
    $yk['shopId'] . ':' . $yk['secretKey']
);

if ($verify['status'] !== 200 || !is_array($verify['json']) || ($verify['json']['id'] ?? '') !== $paymentId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Verification failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$verified = $verify['json'];
$status = (string)($verified['status'] ?? '');
$paidFlag = !empty($verified['paid']);
$leadId = ev_clean_line($verified['metadata']['lead_id'] ?? '');
$amountRub = (float)($verified['amount']['value'] ?? 0);

$state = ev_payment_state_map();
$existing = $state['byYookassaId'][$paymentId] ?? null;
if (($existing['status'] ?? '') === 'paid') {
    echo json_encode(['ok' => true, 'duplicate' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$mskNow = ev_msk_now();
if ($status === 'succeeded' && $paidFlag) {
    $lead = $leadId !== '' ? ev_find_lead($leadId) : null;
    $expectedMin = ($lead !== null && (float)($lead['priceRub'] ?? 0) > 0) ? (float)$lead['priceRub'] : 50000.0;
    if ($amountRub + 0.01 < $expectedMin) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Amount mismatch'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    ev_append_jsonl('hr_retreat_payments.jsonl', [
        'ts' => $mskNow->format(DATE_ATOM),
        'leadId' => $leadId,
        'provider' => 'yookassa',
        'status' => 'paid',
        'yookassaPaymentId' => $paymentId,
        'amountRub' => $amountRub,
    ]);

    $leadName = $lead['name'] ?? 'участник';
    $leadContact = $lead['contact'] ?? '—';
    $leadEmail = $lead['email'] ?? '—';
    $subj = "Оплата получена (ЮKassa) · {$leadId} — {$leadName}";
    $body = implode("\r\n", [
        'Подтверждена онлайн-оплата через ЮKassa',
        "Номер заявки: {$leadId}",
        "ID платежа ЮKassa: {$paymentId}",
        "Сумма: {$amountRub} ₽",
        "Имя: {$leadName}",
        "Контакт: {$leadContact}",
        "Email: {$leadEmail}",
        'Дата (МСК): ' . $mskNow->format('Y-m-d H:i:s'),
    ]);
    ev_send_email($subj, $body, (string)$leadEmail);
} elseif ($status === 'canceled') {
    ev_append_jsonl('hr_retreat_payments.jsonl', [
        'ts' => $mskNow->format(DATE_ATOM),
        'leadId' => $leadId,
        'provider' => 'yookassa',
        'status' => 'canceled',
        'yookassaPaymentId' => $paymentId,
        'amountRub' => $amountRub,
    ]);
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
