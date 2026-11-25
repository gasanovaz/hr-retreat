<?php
/**
 * Общая серверная библиотека HR-ретрита (evitchak.ru):
 * хранение заявок/платежей в РФ (152-ФЗ), прайс, SMTP-уведомления и загрузка секретов касс.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(403);
    exit;
}

function ev_clean_line($val): string
{
    return trim(preg_replace('/[\r\n]+/', ' ', (string)$val) ?? '');
}

function ev_msk_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
}

function ev_active_price_rub(?DateTimeImmutable $now = null): int
{
    $now = $now ?? ev_msk_now();
    $earlyDeadline = new DateTimeImmutable('2026-12-23 23:59:59', new DateTimeZone('Europe/Moscow'));
    return ($now <= $earlyDeadline) ? 50000 : 75000;
}

function ev_candidate_dirs(): array
{
    return [
        dirname(__DIR__, 2) . '/private_leads',
        __DIR__ . '/.leads_storage',
        rtrim(sys_get_temp_dir(), '/\\') . '/evitchak_leads',
    ];
}

function ev_append_jsonl(string $filename, array $record): array
{
    $jsonLine = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonLine === false) {
        return ['ok' => false, 'location' => '', 'path' => ''];
    }
    $jsonLine .= PHP_EOL;

    foreach (ev_candidate_dirs() as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            $targetPath = $dir . '/' . $filename;
            $written = @file_put_contents($targetPath, $jsonLine, FILE_APPEND | LOCK_EX);
            if ($written !== false && $written > 0) {
                @chmod($targetPath, 0640);
                return ['ok' => true, 'location' => basename($dir), 'path' => $targetPath];
            }
        }
    }
    return ['ok' => false, 'location' => '', 'path' => ''];
}

function ev_read_jsonl(string $filename): array
{
    $rows = [];
    foreach (ev_candidate_dirs() as $dir) {
        $path = $dir . '/' . $filename;
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            continue;
        }
        @flock($fp, LOCK_SH);
        while (($line = fgets($fp)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        @flock($fp, LOCK_UN);
        fclose($fp);
    }
    return $rows;
}

function ev_find_lead(string $leadId): ?array
{
    $leadId = trim($leadId);
    if ($leadId === '') {
        return null;
    }
    $leads = ev_read_jsonl('hr_retreat_leads.jsonl');
    for ($i = count($leads) - 1; $i >= 0; $i--) {
        if (($leads[$i]['leadId'] ?? '') === $leadId) {
            return $leads[$i];
        }
    }
    return null;
}

function ev_payment_state_map(): array
{
    $events = ev_read_jsonl('hr_retreat_payments.jsonl');
    $byLead = [];
    $byRobokassaInv = [];
    $byYookassaId = [];

    foreach ($events as $ev) {
        $leadId = (string)($ev['leadId'] ?? '');
        $status = (string)($ev['status'] ?? '');
        $provider = (string)($ev['provider'] ?? '');
        $invId = (int)($ev['robokassaInvId'] ?? 0);
        $ykId = (string)($ev['yookassaPaymentId'] ?? '');

        if ($invId > 0) {
            $prevInv = $byRobokassaInv[$invId] ?? [];
            $isAlreadyPaid = (($prevInv['status'] ?? '') === 'paid');
            $byRobokassaInv[$invId] = array_merge($prevInv, $ev);
            if ($isAlreadyPaid && $status !== 'paid') {
                $byRobokassaInv[$invId]['status'] = 'paid';
            }
        }
        if ($ykId !== '') {
            $prevYk = $byYookassaId[$ykId] ?? [];
            $isAlreadyPaid = (($prevYk['status'] ?? '') === 'paid');
            $byYookassaId[$ykId] = array_merge($prevYk, $ev);
            if ($isAlreadyPaid && $status !== 'paid') {
                $byYookassaId[$ykId]['status'] = 'paid';
            }
        }
        if ($leadId !== '') {
            $prev = $byLead[$leadId] ?? [];
            $isAlreadyPaid = (($prev['status'] ?? '') === 'paid');
            $merged = array_merge($prev, [
                'status' => $status ?: ($prev['status'] ?? 'pending'),
                'provider' => $provider ?: ($prev['provider'] ?? ''),
                'paymentId' => $ykId !== '' ? $ykId : ($invId > 0 ? (string)$invId : ($prev['paymentId'] ?? '')),
                'amountRub' => $ev['amountRub'] ?? ($prev['amountRub'] ?? 0),
                'updatedAt' => $ev['ts'] ?? ($prev['updatedAt'] ?? ''),
                'paidAt' => ($status === 'paid' ? ($ev['ts'] ?? '') : ($prev['paidAt'] ?? '')),
            ]);
            if ($isAlreadyPaid && $status !== 'paid') {
                $merged['status'] = 'paid';
            }
            $byLead[$leadId] = $merged;
        }
    }

    return [
        'byLead' => $byLead,
        'byRobokassaInv' => $byRobokassaInv,
        'byYookassaId' => $byYookassaId,
    ];
}

function ev_normalize_ru_phone(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) === 10 && $digits[0] === '9') {
        return '7' . $digits;
    }
    if (strlen($digits) === 11) {
        if ($digits[0] === '8') {
            return '7' . substr($digits, 1);
        }
        if ($digits[0] === '7') {
            return $digits;
        }
    }
    return '';
}

function ev_load_smtp_config(): array
{
    $cfg = [
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
            $cfg = array_merge($cfg, $loaded);
        }
    }
    return $cfg;
}

function ev_send_email(string $subjectRaw, string $bodyText, string $replyTo = ''): array
{
    $cfg = ev_load_smtp_config();
    $subjectEncoded = '=?UTF-8?B?' . base64_encode($subjectRaw) . '?=';
    $replyValid = filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : '';

    $sendSmtp = static function (array $smtp, string $subjHeader, string $body, string $reply): bool {
        $pass = (string)($smtp['pass'] ?? '');
        if ($pass === '') {
            return false;
        }
        $host = (string)$smtp['host'];
        $port = (int)$smtp['port'];
        $user = (string)$smtp['user'];
        $to   = (string)$smtp['to'];

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
            if (!$cmd($sock, "EHLO {$ehloHost}", 250)) return false;
            if (!$cmd($sock, 'AUTH LOGIN', 334)) return false;
            if (!$cmd($sock, base64_encode($user), 334)) return false;
            if (!$cmd($sock, base64_encode($pass), 235)) return false;
            if (!$cmd($sock, "MAIL FROM:<{$user}>", 250)) return false;
            if (!$cmd($sock, "RCPT TO:<{$to}>", 250)) return false;
            if (!$cmd($sock, 'DATA', 354)) return false;

            $fromName = '=?UTF-8?B?' . base64_encode('Школа Елены Витчак') . '?=';
            $userDomain = (strpos($user, '@') !== false) ? substr(strrchr($user, '@'), 1) : 'evitchak.ru';
            $msgId = '<' . bin2hex(random_bytes(8)) . '@' . $userDomain . '>';
            $headers = [
                'Date: ' . date('r'),
                "From: {$fromName} <{$user}>",
                "To: <{$to}>",
                "Subject: {$subjHeader}",
                "Message-ID: {$msgId}",
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];
            if ($reply !== '') {
                $headers[] = "Reply-To: <{$reply}>";
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

    if ($sendSmtp($cfg, $subjectEncoded, $bodyText, $replyValid)) {
        return ['sent' => true, 'method' => 'smtp'];
    }

    $fallbackFrom = !empty($cfg['user']) ? (string)$cfg['user'] : 'no-reply@evitchak.ru';
    $fallbackHeaders = 'From: =?UTF-8?B?' . base64_encode('Школа Елены Витчак') . "?= <{$fallbackFrom}>\r\n"
        . ($replyValid !== '' ? "Reply-To: {$replyValid}\r\n" : '')
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n";
    $mailSent = @mail((string)$cfg['to'], $subjectEncoded, $bodyText, $fallbackHeaders, "-f{$fallbackFrom}");
    return [
        'sent' => (bool)$mailSent,
        'method' => $mailSent ? 'sendmail' : 'none',
    ];
}

function ev_load_payment_config(): array
{
    $cfg = [
        'yookassa' => [
            'shopId' => '',
            'secretKey' => '',
            'taxSystemCode' => 2, // 2 = УСН Доходы
        ],
        'robokassa' => [
            'merchantLogin' => '',
            'password1' => '',
            'password2' => '',
            'hashAlgo' => 'sha256',
            'testMode' => false,
            'sno' => 'usn_income',
            'sendReceipt' => true,
        ],
    ];
    $secretFile = __DIR__ . '/.payment_secret.php';
    if (is_file($secretFile)) {
        $loaded = @include $secretFile;
        if (is_array($loaded)) {
            if (is_array($loaded['yookassa'] ?? null)) {
                $cfg['yookassa'] = array_merge($cfg['yookassa'], $loaded['yookassa']);
            }
            if (is_array($loaded['robokassa'] ?? null)) {
                $cfg['robokassa'] = array_merge($cfg['robokassa'], $loaded['robokassa']);
            }
        }
    }

    $cfg['yookassa']['enabled'] = (
        trim((string)$cfg['yookassa']['shopId']) !== '' &&
        trim((string)$cfg['yookassa']['secretKey']) !== ''
    );
    $cfg['robokassa']['enabled'] = (
        trim((string)$cfg['robokassa']['merchantLogin']) !== '' &&
        trim((string)$cfg['robokassa']['password1']) !== '' &&
        trim((string)$cfg['robokassa']['password2']) !== ''
    );
    return $cfg;
}

function ev_http_json(string $url, string $method, array $headers = [], ?string $body = null, ?string $basicAuth = null): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $hdrList = $headers;
        if ($basicAuth !== null && $basicAuth !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $hdrList,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $respBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [
            'status' => $httpCode,
            'raw' => is_string($respBody) ? $respBody : '',
            'json' => is_string($respBody) ? (json_decode($respBody, true) ?: null) : null,
            'error' => $err,
        ];
    }

    $hdrLines = $headers;
    if ($basicAuth !== null && $basicAuth !== '') {
        $hdrLines[] = 'Authorization: Basic ' . base64_encode($basicAuth);
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $hdrLines),
            'content' => $body ?? '',
            'timeout' => 12,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $respBody = @file_get_contents($url, false, $ctx);
    $httpCode = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $httpCode = (int)$m[1];
            break;
        }
    }
    return [
        'status' => $httpCode,
        'raw' => is_string($respBody) ? $respBody : '',
        'json' => is_string($respBody) ? (json_decode($respBody, true) ?: null) : null,
        'error' => $respBody === false ? 'HTTP stream request failed' : '',
    ];
}
