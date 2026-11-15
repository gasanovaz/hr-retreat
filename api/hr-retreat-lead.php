<?php
/**
 * HR Ретрит · Школа Елены Витчак
 * Серверный обработчик заявок для размещения на российском хостинге vitchakschool.ru (152-ФЗ).
 * Активируется при переносе сайта на боевой домен (ANALYTICS_CONFIG.webhookEndpoint = '/api/hr-retreat-lead.php').
 */
declare(strict_types=1);

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
    // Поддержка стандартного form POST fallback
    $data = $_POST;
}

// Honeypot-ловушка для спам-ботов (скрытое поле website)
if (!empty($data['website_hp']) || !empty($data['website'])) {
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$cleanSingleLine = static function ($val): string {
    return trim(preg_replace('/[\r\n]+/', ' ', (string)$val) ?? '');
};

$name       = $cleanSingleLine($data['name'] ?? '');
$contact    = $cleanSingleLine($data['contact'] ?? '');
$emailRaw   = $cleanSingleLine($data['email'] ?? '');
$emailValid = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : '';
$payerType  = ($data['payerType'] ?? ($data['payer_type'] ?? 'b2c')) === 'b2b' ? 'b2b' : 'b2c';
$modeLabel  = $payerType === 'b2b' ? 'B2B (Счёт на организацию)' : 'B2C (Личная оплата / ссылка ИП)';
$company    = $cleanSingleLine($data['company'] ?? '—');
$comment    = trim((string)($data['comment'] ?? ''));

$pdnConsent   = !empty($data['pdnConsent']) || !empty($data['consentPd']) || !empty($data['consent_pd']);
$offerConsent = !empty($data['offerConsent']) || !empty($data['consentOffer']) || !empty($data['consent_offer']);
$promoConsent = !empty($data['marketingConsent']) || !empty($data['consentPromo']) || !empty($data['consent_promo']);

if ($name === '' || $contact === '' || !$pdnConsent || !$offerConsent) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Заполните обязательные поля и согласия'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mskNow = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
$earlyDeadline = new DateTimeImmutable('2026-12-23 23:59:59', new DateTimeZone('Europe/Moscow'));
$activePrice = ($mskNow <= $earlyDeadline) ? 50000 : 59000;

$utm = is_array($data['utm'] ?? null) ? $data['utm'] : [];
$utmText = json_encode($utm, JSON_UNESCAPED_UNICODE);
$ymClientId = $cleanSingleLine($data['ymClientId'] ?? ($data['ym_client_id'] ?? ''));
$pageUrl = $cleanSingleLine($data['pageUrl'] ?? ($data['page_url'] ?? ''));

$subject = '=?UTF-8?B?' . base64_encode("Заявка HR-ретрит ({$modeLabel}) — {$name}") . '?=';
$bodyLines = [
    'Новая заявка с лендинга «HR-ретрит: Следующий уровень»',
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
$bodyLines[] = 'Согласие ПДн и Оферта: подтверждены';
$bodyLines[] = 'Согласие на рассылку (необязат.): ' . ($promoConsent ? 'Да' : 'Нет');
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

$headers = "From: Школа Елены Витчак <no-reply@vitchakschool.ru>\r\n"
    . ($emailValid !== '' ? "Reply-To: {$emailValid}\r\n" : '')
    . "MIME-Version: 1.0\r\n"
    . "Content-Type: text/plain; charset=utf-8\r\n";

$mailSent = @mail('hr.retrit@yandex.ru', $subject, implode("\n", $bodyLines), $headers);

// Локальный резервный журнал вне публичной корневой директории webroot
$logDir = dirname(__DIR__, 2) . '/private_leads';
$logged = false;
if (is_dir($logDir) && is_writable($logDir)) {
    $record = [
        'ts' => $mskNow->format(DATE_ATOM),
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
        'ymClientId' => $ymClientId,
        'utm' => $utm,
    ];
    $logged = (bool)file_put_contents(
        $logDir . '/hr_retreat_leads.jsonl',
        json_encode($record, JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

echo json_encode([
    'ok' => ($mailSent || $logged),
    'priceRub' => $activePrice,
    'mailSent' => $mailSent,
], JSON_UNESCAPED_UNICODE);
