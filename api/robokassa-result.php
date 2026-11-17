<?php
/**
 * Обработчик ResultURL для Робокассы.
 * Проверяет криптографическую подпись по Password2 (с Shp_*) и сумму счёта, возвращает OK{InvId}.
 */
declare(strict_types=1);

require_once __DIR__ . '/_storage_lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$payCfg = ev_load_payment_config();
$rk = $payCfg['robokassa'];
if (empty($rk['enabled'])) {
    http_response_code(503);
    echo 'ERR: Robokassa not configured';
    exit;
}

$outSumRaw = (string)($_REQUEST['OutSum'] ?? ($_REQUEST['out_summ'] ?? ''));
$invIdRaw = (string)($_REQUEST['InvId'] ?? ($_REQUEST['inv_id'] ?? ''));
$sigRaw = (string)($_REQUEST['SignatureValue'] ?? ($_REQUEST['crc'] ?? ''));

$invId = ctype_digit($invIdRaw) ? (int)$invIdRaw : 0;
if ($outSumRaw === '' || $invId <= 0 || $sigRaw === '') {
    http_response_code(400);
    echo 'ERR: Missing parameters';
    exit;
}

// Собираем и сортируем все пользовательские параметры Shp_* по протоколу Робокассы
$shpParams = [];
foreach ($_REQUEST as $k => $v) {
    if (strncmp((string)$k, 'Shp_', 4) === 0 && !is_array($v)) {
        $shpParams[(string)$k] = (string)$v;
    }
}
ksort($shpParams, SORT_STRING);

$algo = strtolower((string)($rk['hashAlgo'] ?? 'sha256'));
if (!in_array($algo, ['sha256', 'md5', 'sha512'], true)) {
    $algo = 'sha256';
}

$parts = [$outSumRaw, $invIdRaw, (string)$rk['password2']];
foreach ($shpParams as $k => $v) {
    $parts[] = "{$k}={$v}";
}
$expectedSig = hash($algo, implode(':', $parts));

if (!hash_equals(strtolower($expectedSig), strtolower($sigRaw))) {
    http_response_code(403);
    echo 'ERR: Invalid signature';
    exit;
}

$state = ev_payment_state_map();
$invRecord = $state['byRobokassaInv'][$invId] ?? null;
if ($invRecord === null) {
    http_response_code(404);
    echo 'ERR: Unknown InvId';
    exit;
}

$expectedAmount = (float)($invRecord['amountRub'] ?? 0);
$actualAmount = (float)$outSumRaw;
if (abs($actualAmount - $expectedAmount) > 0.01) {
    http_response_code(400);
    echo 'ERR: Amount mismatch';
    exit;
}

if (($invRecord['status'] ?? '') === 'paid') {
    echo "OK{$invId}";
    exit;
}

$leadId = (string)($invRecord['leadId'] ?? ($shpParams['Shp_lead'] ?? ''));
$mskNow = ev_msk_now();
ev_append_jsonl('hr_retreat_payments.jsonl', [
    'ts' => $mskNow->format(DATE_ATOM),
    'leadId' => $leadId,
    'provider' => 'robokassa',
    'status' => 'paid',
    'robokassaInvId' => $invId,
    'amountRub' => $actualAmount,
]);

$lead = $leadId !== '' ? ev_find_lead($leadId) : null;
$leadName = $lead['name'] ?? 'участник';
$leadContact = $lead['contact'] ?? '—';
$leadEmail = $lead['email'] ?? '—';
$subj = "Оплата получена (Робокасса) · {$leadId} — {$leadName}";
$body = implode("\r\n", [
    'Подтверждена онлайн-оплата через Робокассу',
    "Номер заявки: {$leadId}",
    "InvId Робокассы: {$invId}",
    "Сумма: {$actualAmount} ₽",
    "Имя: {$leadName}",
    "Контакт: {$leadContact}",
    "Email: {$leadEmail}",
    'Дата (МСК): ' . $mskNow->format('Y-m-d H:i:s'),
]);
ev_send_email($subj, $body, (string)$leadEmail);

echo "OK{$invId}";
