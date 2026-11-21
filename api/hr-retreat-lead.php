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

function ev_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function ev_log_spam(string $reason, array $meta = []): void
{
    $mskNow = ev_msk_now();
    $record = array_merge([
        'ts' => $mskNow->format(DATE_ATOM),
        'reason' => $reason,
        'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
    ], $meta);
    ev_append_jsonl('hr_retreat_spam.jsonl', $record);
}

function ev_check_and_record_rate_limit(string $ip, int $nowTs): bool
{
    $key = $ip !== '' ? $ip : 'unknown';
    $windowSec = 3600;
    $cooldownSec = 15;
    $maxPerWindow = 5;

    foreach (ev_candidate_dirs() as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            continue;
        }
        $path = $dir . '/hr_retreat_ratelimit.json';
        $fp = @fopen($path, 'c+b');
        if (!$fp) {
            continue;
        }
        if (!@flock($fp, LOCK_EX)) {
            fclose($fp);
            continue;
        }
        $raw = stream_get_contents($fp);
        $map = json_decode($raw ?: '{}', true);
        if (!is_array($map)) {
            $map = [];
        }

        $cutoff = $nowTs - $windowSec;
        $cleaned = [];
        foreach ($map as $ipKey => $times) {
            if (!is_array($times)) {
                continue;
            }
            $recent = [];
            foreach ($times as $ts) {
                $tsInt = (int)$ts;
                if ($tsInt >= $cutoff && $tsInt <= $nowTs + 60) {
                    $recent[] = $tsInt;
                }
            }
            if ($recent !== []) {
                $cleaned[(string)$ipKey] = $recent;
            }
        }

        $ipTimes = $cleaned[$key] ?? [];
        if (count($ipTimes) >= $maxPerWindow || ($ipTimes !== [] && ($nowTs - max($ipTimes)) < $cooldownSec)) {
            @flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }

        $ipTimes[] = $nowTs;
        $cleaned[$key] = $ipTimes;
        $encoded = json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $encoded);
            fflush($fp);
        }
        @flock($fp, LOCK_UN);
        fclose($fp);
        @chmod($path, 0640);
        return true;
    }

    return true;
}

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

$clientIp = ev_clean_line($_SERVER['HTTP_X_REAL_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
$userAgentRaw = ev_clean_line($_SERVER['HTTP_USER_AGENT'] ?? '');
$userAgent = function_exists('mb_substr') ? mb_substr($userAgentRaw, 0, 240) : substr($userAgentRaw, 0, 240);

$name       = ev_clean_line($data['name'] ?? '');
$contact    = ev_clean_line($data['contact'] ?? '');
$emailRaw   = ev_clean_line($data['email'] ?? '');
$emailValid = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : '';
$payerType  = ($data['payerType'] ?? ($data['payer_type'] ?? 'b2c')) === 'b2b' ? 'b2b' : 'b2c';
$modeLabel  = $payerType === 'b2b' ? 'B2B (Счёт на организацию)' : 'B2C (Личная оплата / ссылка ИП)';
$company    = ev_clean_line($data['company'] ?? '—');
$comment    = trim((string)($data['comment'] ?? ''));

$spamMeta = [
    'clientIp' => $clientIp,
    'userAgent' => $userAgent,
    'payerType' => $payerType,
    'name' => $name,
    'contact' => $contact,
    'email' => $emailRaw,
    'company' => $company,
    'commentPreview' => function_exists('mb_substr') ? mb_substr(ev_clean_line($comment), 0, 180) : substr(ev_clean_line($comment), 0, 180),
];

// Honeypot-ловушка для спам-ботов (скрытое поле website)
if (!empty($data['website_hp']) || !empty($data['website'])) {
    ev_log_spam('honeypot', $spamMeta);
    echo json_encode(['ok' => true, 'filtered' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdnConsent   = !empty($data['pdnConsent']) || !empty($data['consentPd']) || !empty($data['consent_pd']);
$offerConsent = !empty($data['offerConsent']) || !empty($data['consentOffer']) || !empty($data['consent_offer']);
$promoConsent = !empty($data['marketingConsent']) || !empty($data['consentPromo']) || !empty($data['consent_promo']);

if ($name === '' || $contact === '' || !$pdnConsent || !$offerConsent) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Заполните обязательные поля и согласия'], JSON_UNESCAPED_UNICODE);
    exit;
}

$digitCount = preg_match_all('/\d/', $contact);
$isValidContact = ($digitCount !== false && $digitCount >= 7)
    || preg_match('/^@[a-zA-Z0-9_]{3,32}$/', $contact) === 1
    || preg_match('#(?:https?://)?(?:t\.me|telegram\.me)/[a-zA-Z0-9_]{3,32}#i', $contact) === 1
    || filter_var($contact, FILTER_VALIDATE_EMAIL) !== false;

if (!$isValidContact) {
    ev_log_spam('invalid_contact', $spamMeta);
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажите корректный номер телефона (от 7 цифр) или Telegram (@username).'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($emailRaw !== '' && $emailValid === '') {
    ev_log_spam('invalid_email', $spamMeta);
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажите корректный email (например, name@company.ru) или оставьте поле пустым.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Тихая отсечка ботов без отправки писем и записи в рабочий журнал
if ($payerType === 'b2c' && $company !== '' && $company !== '—' && ev_lower($company) === ev_lower($name)) {
    ev_log_spam('b2c_hidden_company_clone', $spamMeta);
    echo json_encode(['ok' => true, 'filtered' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$elapsedRaw = $data['formElapsedMs'] ?? ($data['form_elapsed_ms'] ?? null);
if (is_numeric($elapsedRaw)) {
    $elapsedMs = (int)$elapsedRaw;
    if ($elapsedMs > 0 && $elapsedMs < 1500) {
        ev_log_spam('too_fast', array_merge($spamMeta, ['formElapsedMs' => $elapsedMs]));
        echo json_encode(['ok' => true, 'filtered' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$spamText = $name . "\n" . $comment;
if (preg_match('/\b(seo\s+services?|seo\s+packages?|search\s+index|google\s+1st\s+page|first\s+page\s+of\s+google|website\s+traffic|boost\s+online\s+performance|online\s+seo\s+consultant|complete\s+seo\s+action\s+plan|indexregister|backlinks?)\b/i', $spamText) === 1) {
    ev_log_spam('seo_spam_phrase', $spamMeta);
    echo json_encode(['ok' => true, 'filtered' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$commentWithoutMessengers = preg_replace('#(?:https?://)?(?:t\.me|telegram\.me|wa\.me)/[^\s]*#i', ' ', $comment) ?? $comment;
if (preg_match('#https?://|www\.#i', $commentWithoutMessengers) === 1 && preg_match('/[А-Яа-яЁё]/u', $comment) !== 1) {
    ev_log_spam('foreign_url_spam', $spamMeta);
    echo json_encode(['ok' => true, 'filtered' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!ev_check_and_record_rate_limit($clientIp, time())) {
    ev_log_spam('rate_limit', $spamMeta);
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Слишком много отправок. Подождите немного или напишите напрямую в Telegram @alvitcha.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mskNow = ev_msk_now();
$activePrice = ev_active_price_rub($mskNow);
$leadId = 'HR-' . $mskNow->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
$consentVersion = '2026-11-17';

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
