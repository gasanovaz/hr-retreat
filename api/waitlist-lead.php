<?php
/**
 * Серверный обработчик формы «Лист ожидания на HR-ретрит» (152-ФЗ РФ).
 * Фиксирует 5 полей анкеты и раздельные согласия, отсекает спам-ботов, сохраняет в JSONL на сервере в РФ и отправляет уведомление по SMTP.
 */
declare(strict_types=1);

require_once __DIR__ . '/_storage_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function ev_wl_log_spam(string $reason, array $meta = []): void
{
    $record = array_merge([
        'ts' => ev_msk_now()->format(DATE_ATOM),
        'reason' => $reason,
        'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
    ], $meta);
    ev_append_jsonl('hr_retreat_waitlist_spam.jsonl', $record);
}

function ev_wl_check_rate_limit(string $ip, int $nowTs): bool
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
        $path = $dir . '/hr_retreat_waitlist_ratelimit.json';
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

$name        = ev_clean_line($data['name'] ?? '');
$emailRaw    = ev_clean_line($data['email'] ?? '');
$emailValid  = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : '';
$phoneRaw    = ev_clean_line($data['phone'] ?? '');
$tgRaw       = ev_clean_line($data['telegram'] ?? '');
$motivation  = trim((string)($data['motivation'] ?? ''));

$tgClean = preg_replace('#^https?://(?:t\.me|telegram\.me)/#i', '', $tgRaw) ?? $tgRaw;
$tgClean = preg_replace('#^(?:t\.me|telegram\.me)/#i', '', $tgClean) ?? $tgClean;
$tgClean = preg_split('/[?#\/]/', trim($tgClean))[0] ?? '';
$tgNormalized = $tgClean !== '' ? ('@' . ltrim($tgClean, '@')) : '';

$spamMeta = [
    'clientIp' => $clientIp,
    'userAgent' => $userAgent,
    'name' => $name,
    'email' => $emailRaw,
    'phone' => $phoneRaw,
    'telegram' => $tgRaw,
    'motivationPreview' => function_exists('mb_substr') ? mb_substr(ev_clean_line($motivation), 0, 180) : substr(ev_clean_line($motivation), 0, 180),
];

// Honeypot
if (!empty($data['website_hp']) || !empty($data['website'])) {
    ev_wl_log_spam('honeypot', $spamMeta);
    echo json_encode(['ok' => true, 'filtered' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdnConsent   = !empty($data['pdnConsent']) || !empty($data['consentPd']) || !empty($data['consent_pd']);
$offerConsent = !empty($data['offerConsent']) || !empty($data['consentOffer']) || !empty($data['consent_offer']);
$promoConsent = !empty($data['marketingConsent']) || !empty($data['consentPromo']) || !empty($data['consent_promo']);

if ($name === '' || $emailRaw === '' || $phoneRaw === '' || $tgRaw === '' || $motivation === '' || !$pdnConsent || !$offerConsent) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Заполните все 5 обязательных полей и отметьте обязательные согласия.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($emailValid === '') {
    ev_wl_log_spam('invalid_email', $spamMeta);
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажите корректный адрес электронной почты (например, name@company.ru).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$phoneDigitsCount = preg_match_all('/\d/', $phoneRaw);
if ($phoneDigitsCount === false || $phoneDigitsCount < 7) {
    ev_wl_log_spam('invalid_phone', $spamMeta);
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажите корректный номер телефона (не менее 7 цифр).'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (preg_match('/^@[a-zA-Z0-9_]{3,32}$/', $tgNormalized) !== 1) {
    ev_wl_log_spam('invalid_telegram', $spamMeta);
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажите корректный ник в Telegram (например, @username, латиница и цифры от 3 символов).'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Тихая отсечка ботов без отправки писем и записи в рабочий журнал
$elapsedRaw = $data['formElapsedMs'] ?? ($data['form_elapsed_ms'] ?? null);
if (is_numeric($elapsedRaw)) {
    $elapsedMs = (int)$elapsedRaw;
    if ($elapsedMs > 0 && $elapsedMs < 1500) {
        ev_wl_log_spam('too_fast', array_merge($spamMeta, ['formElapsedMs' => $elapsedMs]));
        echo json_encode(['ok' => true, 'filtered' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$spamText = $name . "\n" . $motivation;
if (preg_match('/\b(seo\s+services?|seo\s+packages?|search\s+index|google\s+1st\s+page|first\s+page\s+of\s+google|website\s+traffic|boost\s+online\s+performance|online\s+seo\s+consultant|complete\s+seo\s+action\s+plan|indexregister|backlinks?)\b/i', $spamText) === 1) {
    ev_wl_log_spam('seo_spam_phrase', $spamMeta);
    echo json_encode(['ok' => true, 'filtered' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$motivationWithoutMessengers = preg_replace('#(?:https?://)?(?:t\.me|telegram\.me|wa\.me)/[^\s]*#i', ' ', $motivation) ?? $motivation;
if (preg_match('#https?://|www\.#i', $motivationWithoutMessengers) === 1 && preg_match('/[А-Яа-яЁё]/u', $motivation) !== 1) {
    ev_wl_log_spam('foreign_url_spam', $spamMeta);
    echo json_encode(['ok' => true, 'filtered' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!ev_wl_check_rate_limit($clientIp, time())) {
    ev_wl_log_spam('rate_limit', $spamMeta);
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Слишком много отправок. Подождите немного или напишите напрямую в Telegram @alvitcha.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mskNow = ev_msk_now();
$waitlistId = 'WL-' . $mskNow->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
$consentVersion = '2026-11-25';

$utm = is_array($data['utm'] ?? null) ? $data['utm'] : [];
$utmText = json_encode($utm, JSON_UNESCAPED_UNICODE);
$ymClientId = ev_clean_line($data['ymClientId'] ?? ($data['ym_client_id'] ?? ''));
$pageUrl = ev_clean_line($data['pageUrl'] ?? ($data['page_url'] ?? ''));

$subjectRaw = "Лист ожидания {$waitlistId} · HR-ретрит — {$name}";
$bodyLines = [
    'Новая запись в лист ожидания на «HR-ретрит: следующий уровень»',
    "Номер записи: {$waitlistId}",
    "ФИО: {$name}",
    "Почта: {$emailValid}",
    "Телефон: {$phoneRaw}",
    "Ник в Telegram: {$tgNormalized}",
    "Почему хотите на HR-ретрит:\n{$motivation}",
    "Согласие ПДн и Оферта (ред. {$consentVersion}): подтверждены",
    'Согласие на рассылку 38-ФЗ (необязат.): ' . ($promoConsent ? 'Да' : 'Нет'),
];
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
    'waitlistId' => $waitlistId,
    'ts' => $mskNow->format(DATE_ATOM),
    'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
    'name' => $name,
    'email' => $emailValid,
    'phone' => $phoneRaw,
    'telegram' => $tgNormalized,
    'motivation' => $motivation,
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
];

$logRes = ev_append_jsonl('hr_retreat_waitlist.jsonl', $record);

echo json_encode([
    'ok' => ($mailSent || $logRes['ok']),
    'waitlistId' => $waitlistId,
    'mailSent' => $mailSent,
    'logged' => $logRes['ok'],
], JSON_UNESCAPED_UNICODE);
