<?php
/**
 * HR Ретрит · Школа Елены Витчак
 * Серверный обработчик заявок (152-ФЗ РФ): фиксирует согласия, отправляет письмо через Timeweb SMTP
 * и сохраняет резервную копию лида в защищённый JSONL-журнал на сервере в РФ.
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

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) {
    $data = $_POST;
}

// Honeypot-ловушка для спам-ботов (скрытое поле website)
if (!empty($data['website_hp']) || !empty($data['website'])) {
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$name       = ev_clean_line($data['name'] ?? '');
$contact    = ev_clean_line($data['contact'] ?? '');
$emailRaw   = ev_clean_line($data['email'] ?? '');
$emailValid = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : '';
$payerType  = ($data['payerType'] ?? ($data['payer_type'] ?? 'b2c')) === 'b2b' ? 'b2b' : 'b2c';
$modeLabel  = $payerType === 'b2b' ? 'B2B (Счёт на организацию)' : 'B2C (Личная оплата / ссылка ИП)';
$company    = ev_clean_line($data['company'] ?? '—');
$comment    = trim((string)($data['comment'] ?? ''));

$pdnConsent   = !empty($data['pdnConsent']) || !empty($data['consentPd']) || !empty($data['consent_pd']);
$offerConsent = !empty($data['offerConsent']) || !empty($data['consentOffer']) || !empty($data['consent_offer']);
$promoConsent = !empty($data['marketingConsent']) || !empty($data['consentPromo']) || !empty($data['consent_promo']);

if ($name === '' || $contact === '' || !$pdnConsent || !$offerConsent) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Заполните обязательные поля и согласия'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mskNow = ev_msk_now();
$activePrice = ev_active_price_rub($mskNow);
$leadId = 'HR-' . $mskNow->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
$consentVersion = '2026-11-17';
$clientIp = ev_clean_line($_SERVER['HTTP_X_REAL_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
$userAgentRaw = ev_clean_line($_SERVER['HTTP_USER_AGENT'] ?? '');
$userAgent = function_exists('mb_substr') ? mb_substr($userAgentRaw, 0, 240) : substr($userAgentRaw, 0, 240);

$utm = is_array($data['utm'] ?? null) ? $data['utm'] : [];
$utmText = json_encode($utm, JSON_UNESCAPED_UNICODE);
$ymClientId = ev_clean_line($data['ymClientId'] ?? ($data['ym_client_id'] ?? ''));
$pageUrl = ev_clean_line($data['pageUrl'] ?? ($data['page_url'] ?? ''));

$subjectRaw = "Заявка {$leadId} · HR-ретрит ({$modeLabel}) — {$name}";
$bodyLines = [
    'Новая заявка с лендинга «HR-ретрит: следующий уровень»',
    "Номер заявки: {$leadId}",
    "Режим оплаты: {$modeLabel}",
    "Актуальная цена на момент заявки: {$activePrice} ₽",
    "Имя: {$name}",
    "Контакт (телефон/Telegram): {$contact}",
    'Email: ' . ($emailRaw !== '' ? $emailRaw : 'не указан'),
];
if ($payerType === 'b2b') {
    $bodyLines[] = 'Компания / ИНН: ' . ($company !== '' ? $company : 'не указаны');
}
if ($comment !== '') {
    $bodyLines[] = "Комментарий:\n{$comment}";
}
$bodyLines[] = "Согласие ПДн и Оферта (ред. {$consentVersion}): подтверждены";
$bodyLines[] = 'Согласие на рассылку 38-ФЗ (необязат.): ' . ($promoConsent ? 'Да' : 'Нет');
if ($ymClientId !== '') {
    $bodyLines[] = "Yandex ClientID: {$ymClientId}";
}
if ($utmText && $utmText !== '[]' && $utmText !== '{}') {
    $bodyLines[] = "UTM: {$utmText}";
}
if ($pageUrl !== '') {
    $bodyLines[] = "URL: {$pageUrl}";
}
$bodyLines[] = 'Дата (МСК): ' . $mskNow->format('Y-m-d H:i:s');
$bodyText = implode("\r\n", $bodyLines);

$mailResult = ev_send_email($subjectRaw, $bodyText, $emailValid);
$mailSent = $mailResult['sent'];
$mailMethod = $mailResult['method'];

$record = [
    'leadId' => $leadId,
    'ts' => $mskNow->format(DATE_ATOM),
    'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
    'mode' => $payerType,
    'priceRub' => $activePrice,
    'name' => $name,
    'contact' => $contact,
    'email' => $emailRaw,
    'company' => $company,
    'comment' => $comment,
    'pdnConsent' => $pdnConsent,
    'offerConsent' => $offerConsent,
    'marketingConsent' => $promoConsent,
    'consentVersion' => $consentVersion,
    'clientIp' => $clientIp,
    'userAgent' => $userAgent,
    'ymClientId' => $ymClientId,
    'utm' => $utm,
    'mailSent' => $mailSent,
    'mailMethod' => $mailMethod,
    'paymentStatus' => 'pending',
    'paymentProvider' => '',
    'paymentId' => '',
];
$logRes = ev_append_jsonl('hr_retreat_leads.jsonl', $record);

$payCfg = ev_load_payment_config();
echo json_encode([
    'ok' => ($mailSent || $logRes['ok']),
    'leadId' => $leadId,
    'priceRub' => $activePrice,
    'mailSent' => $mailSent,
    'mailMethod' => $mailMethod,
    'logged' => $logRes['ok'],
    'logLocation' => $logRes['location'],
    'payments' => [
        'yookassa' => (bool)$payCfg['yookassa']['enabled'],
        'robokassa' => (bool)$payCfg['robokassa']['enabled'],
    ],
], JSON_UNESCAPED_UNICODE);
