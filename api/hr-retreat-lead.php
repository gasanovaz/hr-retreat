<?php
/**
 * HR Ретрит · Школа Елены Витчак
 * Серверный обработчик заявок для размещения на российском хостинге Timeweb (152-ФЗ).
 * Отправляет уведомление на hr.retrit@yandex.ru через авторизованный SMTP Timeweb (ssl://smtp.timeweb.ru:465)
 * с fallback на mail(), и сохраняет резервную копию лида в защищённый JSONL-журнал.
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
$leadId = 'HR-' . $mskNow->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
$consentVersion = '2026-11-17';
$clientIp = $cleanSingleLine($_SERVER['HTTP_X_REAL_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
$userAgentRaw = $cleanSingleLine($_SERVER['HTTP_USER_AGENT'] ?? '');
$userAgent = function_exists('mb_substr') ? mb_substr($userAgentRaw, 0, 240) : substr($userAgentRaw, 0, 240);

$utm = is_array($data['utm'] ?? null) ? $data['utm'] : [];
$utmText = json_encode($utm, JSON_UNESCAPED_UNICODE);
$ymClientId = $cleanSingleLine($data['ymClientId'] ?? ($data['ym_client_id'] ?? ''));
$pageUrl = $cleanSingleLine($data['pageUrl'] ?? ($data['page_url'] ?? ''));

$subjectRaw = "Заявка {$leadId} · HR-ретрит ({$modeLabel}) — {$name}";
$subjectEncoded = '=?UTF-8?B?' . base64_encode($subjectRaw) . '?=';
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

// Читаем пароль SMTP из переменной окружения или закрытого файла .smtp_secret.php
$smtpConfig = [
    'host' => 'smtp.timeweb.ru',
    'port' => 465,
    'user' => 'no-reply@evitchak.ru',
    'pass' => getenv('TIMEWEB_SMTP_PASS') ?: '',
    'to'   => 'hr.retrit@yandex.ru',
];
$secretFile = __DIR__ . '/.smtp_secret.php';
if (is_file($secretFile)) {
    $loaded = @include $secretFile;
    if (is_array($loaded)) {
        $smtpConfig = array_merge($smtpConfig, $loaded);
    }
}

/**
 * Минимальный нативный SMTP over SSL (port 465) клиент без внешних зависимостей.
 */
$sendSmtpSsl = static function (
    string $host,
    int $port,
    string $user,
    string $pass,
    string $to,
    string $replyTo,
    string $subjectHeader,
    string $body
): bool {
    if ($pass === '') {
        return false;
    }
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
        ],
    ]);
    $sock = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 4, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        return false;
    }
    stream_set_timeout($sock, 4);

    $readResp = static function ($fp): array {
        $lines = '';
        $code = 0;
        while (!feof($fp)) {
            $line = fgets($fp, 512);
            if ($line === false) {
                break;
            }
            $lines .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                $code = (int)substr($line, 0, 3);
                break;
            }
        }
        return [$code, $lines];
    };
    $cmd = static function ($fp, string $command, int $expected) use ($readResp): bool {
        fwrite($fp, $command . "\r\n");
        [$code] = $readResp($fp);
        return $code === $expected;
    };

    try {
        [$greet] = $readResp($sock);
        if ($greet !== 220) {
            return false;
        }
        $ehloHost = preg_replace('/[^a-zA-Z0-9.-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'evitchak.ru')) ?: 'evitchak.ru';
        if (!$cmd($sock, "EHLO {$ehloHost}", 250)) {
            return false;
        }
        if (!$cmd($sock, 'AUTH LOGIN', 334)) {
            return false;
        }
        if (!$cmd($sock, base64_encode($user), 334)) {
            return false;
        }
        if (!$cmd($sock, base64_encode($pass), 235)) {
            return false;
        }
        if (!$cmd($sock, "MAIL FROM:<{$user}>", 250)) {
            return false;
        }
        if (!$cmd($sock, "RCPT TO:<{$to}>", 250)) {
            return false;
        }
        if (!$cmd($sock, 'DATA', 354)) {
            return false;
        }

        $fromName = '=?UTF-8?B?' . base64_encode('Школа Елены Витчак') . '?=';
        $userDomain = (strpos($user, '@') !== false) ? substr(strrchr($user, '@'), 1) : 'evitchak.ru';
        $msgId = '<' . bin2hex(random_bytes(8)) . '@' . $userDomain . '>';
        $headers = [
            "Date: " . date('r'),
            "From: {$fromName} <{$user}>",
            "To: <{$to}>",
            "Subject: {$subjectHeader}",
            "Message-ID: {$msgId}",
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=UTF-8",
            "Content-Transfer-Encoding: base64",
        ];
        if ($replyTo !== '') {
            $headers[] = "Reply-To: <{$replyTo}>";
        }
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body)) . "\r\n.";
        fwrite($sock, $payload . "\r\n");
        [$dataCode] = $readResp($sock);
        @fwrite($sock, "QUIT\r\n");
        return $dataCode === 250;
    } finally {
        @fclose($sock);
    }
};

$mailMethod = 'none';
$mailSent = $sendSmtpSsl(
    (string)$smtpConfig['host'],
    (int)$smtpConfig['port'],
    (string)$smtpConfig['user'],
    (string)$smtpConfig['pass'],
    (string)$smtpConfig['to'],
    $emailValid,
    $subjectEncoded,
    $bodyText
);
if ($mailSent) {
    $mailMethod = 'smtp';
} else {
    $fallbackFrom = !empty($smtpConfig['user']) ? (string)$smtpConfig['user'] : 'no-reply@evitchak.com';
    $fallbackHeaders = "From: =?UTF-8?B?" . base64_encode('Школа Елены Витчак') . "?= <{$fallbackFrom}>\r\n"
        . ($emailValid !== '' ? "Reply-To: {$emailValid}\r\n" : '')
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n";
    $mailSent = @mail((string)$smtpConfig['to'], $subjectEncoded, $bodyText, $fallbackHeaders, "-f{$fallbackFrom}");
    if ($mailSent) {
        $mailMethod = 'sendmail';
    }
}

// Надёжный резервный JSONL-журнал (сначала вне webroot, затем в закрытой api/.leads_storage)
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
$jsonLine = json_encode($record, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$candidateDirs = [
    dirname(__DIR__, 2) . '/private_leads',
    __DIR__ . '/.leads_storage',
    rtrim(sys_get_temp_dir(), '/\\') . '/evitchak_leads',
];
$logged = false;
$logLocation = '';
foreach ($candidateDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (is_dir($dir)) {
        $targetPath = $dir . '/hr_retreat_leads.jsonl';
        $written = @file_put_contents($targetPath, $jsonLine, FILE_APPEND | LOCK_EX);
        if ($written !== false && $written > 0) {
            @chmod($targetPath, 0640);
            $logged = true;
            $logLocation = basename($dir);
            break;
        }
    }
}

echo json_encode([
    'ok' => ($mailSent || $logged),
    'leadId' => $leadId,
    'priceRub' => $activePrice,
    'mailSent' => $mailSent,
    'mailMethod' => $mailMethod,
    'logged' => $logged,
    'logLocation' => $logLocation,
], JSON_UNESCAPED_UNICODE);
