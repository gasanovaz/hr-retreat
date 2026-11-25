<?php
/**
 * Защищённая онлайн-таблица листа ожидания и статусов оплат HR-ретрита (152-ФЗ РФ, сервер Timeweb).
 * Доступ открывается по личному паролю маркетолога (api/.waitlist_secret.php) или мастер-паролю администратора (api/.admin_secret.php).
 * Вкладка оплат работает строго в режиме чтения (просмотр и выгрузка в Excel).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/_storage_lib.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$hashes = [];
foreach (['.waitlist_secret.php', '.admin_secret.php'] as $secName) {
    $secFile = dirname(__DIR__) . '/api/' . $secName;
    if (is_file($secFile)) {
        $cfg = @include $secFile;
        $hashVal = is_array($cfg) ? trim((string)($cfg['passwordHash'] ?? '')) : '';
        if ($hashVal !== '') {
            $hashes[] = $hashVal;
        }
    }
}

if ($hashes === []) {
    http_response_code(503);
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Таблица не настроена</title></head><body style="font-family:system-ui,sans-serif;padding:40px;"><h1>Таблица временно недоступна</h1><p>Не задан хеш доступа в закрытом файле api/.waitlist_secret.php.</p></body></html>';
    exit;
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_HTTPS'] ?? '') === '1')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('ev_wl_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/waitlist-table/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (empty($_SESSION['wl_csrf'])) {
    $_SESSION['wl_csrf'] = bin2hex(random_bytes(32));
}

$clientIp = ev_clean_line($_SERVER['HTTP_X_REAL_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

$getRateFile = static function (): string {
    foreach (ev_candidate_dirs() as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            return $dir . '/.waitlist_admin_ratelimit.json';
        }
    }
    return rtrim(sys_get_temp_dir(), '/\\') . '/ev_waitlist_admin_ratelimit.json';
};

$checkRateLimit = static function (string $ip, ?bool $recordFail = null) use ($getRateFile): array {
    $file = $getRateFile();
    $now = time();
    $window = 600;
    $maxFails = 5;

    $fp = @fopen($file, 'c+');
    $data = [];
    if ($fp) {
        @flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $data = $decoded;
        }
        $key = hash('sha256', $ip);
        $timestamps = array_values(array_filter(
            (array)($data[$key] ?? []),
            static fn ($ts): bool => is_int($ts) && ($now - $ts) < $window
        ));

        if ($recordFail === true) {
            $timestamps[] = $now;
            $data[$key] = $timestamps;
        } elseif ($recordFail === false) {
            unset($data[$key]);
            $timestamps = [];
        } else {
            $data[$key] = $timestamps;
        }

        foreach ($data as $k => $list) {
            $fresh = array_values(array_filter((array)$list, static fn ($ts): bool => is_int($ts) && ($now - $ts) < $window));
            if (empty($fresh)) {
                unset($data[$k]);
            } else {
                $data[$k] = $fresh;
            }
        }

        if ($recordFail !== null) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);
        }
        @flock($fp, LOCK_UN);
        fclose($fp);
        @chmod($file, 0640);

        $blocked = count($timestamps) >= $maxFails;
        $retrySec = $blocked ? max(1, $window - ($now - (int)$timestamps[0])) : 0;
        return ['blocked' => $blocked, 'retrySec' => $retrySec];
    }
    return ['blocked' => false, 'retrySec' => 0];
};

// Таймаут неактивности сессии (60 минут)
if (!empty($_SESSION['wl_auth']) && (time() - (int)($_SESSION['wl_last_seen'] ?? 0)) > 3600) {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['wl_csrf'] = bin2hex(random_bytes(32));
}

$verifySecretPassword = static function (string $input, string $storedHash): bool {
    if ($input === '' || $storedHash === '') {
        return false;
    }
    if (strncmp($storedHash, 'pbkdf2_sha256$', 14) === 0) {
        $parts = explode('$', $storedHash);
        if (count($parts) !== 4) {
            return false;
        }
        $iters = (int)$parts[1];
        $salt = base64_decode($parts[2], true);
        $expected = base64_decode($parts[3], true);
        if ($iters < 100000 || $salt === false || $expected === false) {
            return false;
        }
        $calc = hash_pbkdf2('sha256', $input, $salt, $iters, strlen($expected), true);
        return hash_equals($expected, $calc);
    }
    return password_verify($input, $storedHash);
};

$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SESSION['wl_auth'])) {
    $action = (string)($_POST['action'] ?? '');
    $csrfOk = hash_equals((string)$_SESSION['wl_csrf'], (string)($_POST['csrf'] ?? ''));

    if ($action === 'login') {
        $rl = $checkRateLimit($clientIp, null);
        if ($rl['blocked']) {
            http_response_code(429);
            $mins = (int)ceil($rl['retrySec'] / 60);
            $loginError = "Слишком много неверных попыток входа. Повторите через {$mins} мин.";
        } elseif (!$csrfOk) {
            $loginError = 'Сессия формы устарела, обновите страницу.';
        } else {
            $passInput = (string)($_POST['password'] ?? '');
            $matched = false;
            foreach ($hashes as $hashCandidate) {
                if ($verifySecretPassword($passInput, $hashCandidate)) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                $checkRateLimit($clientIp, false);
                session_regenerate_id(true);
                $_SESSION['wl_auth'] = true;
                $_SESSION['wl_last_seen'] = time();
                $_SESSION['wl_csrf'] = bin2hex(random_bytes(32));
                header('Location: ./');
                exit;
            }
            $rlAfter = $checkRateLimit($clientIp, true);
            $loginError = $rlAfter['blocked']
                ? 'Превышен лимит попыток. Вход заблокирован на 10 минут.'
                : 'Неверный пароль доступа.';
        }
    }
}

$isAuthed = !empty($_SESSION['wl_auth']);
if ($isAuthed) {
    $_SESSION['wl_last_seen'] = time();
}

$h = static fn ($s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if (!$isAuthed):
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <meta name="theme-color" content="#0e342e">
  <title>Вход в таблицу листа ожидания и оплат · Школа Елены Витчак</title>
  <link rel="stylesheet" href="../styles.css?v=12">
  <link rel="stylesheet" href="../deep-theme.css?v=5">
  <style>
    .wl-auth-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 28px 16px; background: linear-gradient(180deg, #faf7f0 0%, #f1ebdd 100%); }
    .wl-auth-card { width: 100%; max-width: 430px; background: var(--surface); border: 1px solid var(--line); border-radius: 20px; padding: 34px 32px; box-shadow: 0 24px 52px -30px rgba(14, 56, 50, 0.35); }
    .wl-kicker { font-size: 12px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--gold-text); margin-bottom: 8px; }
    .wl-auth-card h1 { font-size: 26px; color: var(--teal-dark); margin-bottom: 8px; }
    .wl-auth-sub { font-size: 14.5px; color: var(--ink-muted); margin-bottom: 20px; line-height: 1.5; }
    .wl-err { background: #fdf1ef; border: 1px solid #e6b3ad; color: #8b261d; padding: 10px 14px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
  </style>
</head>
<body>
  <main class="wl-auth-wrap">
    <section class="wl-auth-card">
      <div class="wl-kicker">evitchak.ru · Сервер РФ (152-ФЗ)</div>
      <h1>Лист ожидания и оплаты</h1>
      <p class="wl-auth-sub">Закрытый доступ к анкетам участников листа ожидания HR-ретрита, статусам оплат сайта и выгрузке в Excel.</p>
      <?php if ($loginError !== ''): ?>
        <div class="wl-err" role="alert"><?= $h($loginError) ?></div>
      <?php endif; ?>
      <form method="POST" action="./">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf" value="<?= $h($_SESSION['wl_csrf']) ?>">
        <div class="field" style="margin-bottom:16px;">
          <label for="wlAdmPassword">Пароль доступа</label>
          <input id="wlAdmPassword" name="password" type="password" required autocomplete="current-password" autofocus placeholder="Введите пароль к таблице">
        </div>
        <button class="btn btn-teal" type="submit" style="width:100%;">Открыть таблицу</button>
      </form>
      <p style="margin-top:16px;font-size:13px;color:var(--ink-muted);text-align:center;">
        <a href="../waitlist/" style="text-decoration:underline;">← Перейти к форме листа ожидания</a>
      </p>
    </section>
  </main>
</body>
</html>
<?php
    exit;
endif;

$entries = ev_read_jsonl('hr_retreat_waitlist.jsonl');
$siteLeads = ev_read_jsonl('hr_retreat_leads.jsonl');
$payMap = ev_payment_state_map();

$lowerFn = static fn (string $s): string => function_exists('mb_strtolower') ? mb_strtolower(trim($s), 'UTF-8') : strtolower(trim($s));
$strposFn = static fn (string $hay, string $needle): bool => function_exists('mb_strpos') ? (mb_strpos($hay, $needle) !== false) : (strpos($hay, $needle) !== false);

$phoneKeyFn = static function (string $raw): string {
    $norm = ev_normalize_ru_phone($raw);
    if ($norm !== '') {
        return $norm;
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    return strlen($digits) >= 10 ? ('7' . substr($digits, -10)) : '';
};

$tgKeyFn = static function (string $raw) use ($lowerFn): string {
    $s = trim($raw);
    if ($s === '') {
        return '';
    }
    if (preg_match('/@([a-zA-Z0-9_]{3,32})/', $s, $m)) {
        return '@' . $lowerFn($m[1]);
    }
    if (preg_match('#(?:https?://)?(?:t\.me|telegram\.me)/([a-zA-Z0-9_]{3,32})#i', $s, $m)) {
        return '@' . $lowerFn($m[1]);
    }
    return '';
};

$formatMskFn = static function (string $tsRaw): array {
    if (trim($tsRaw) === '') {
        return ['', ''];
    }
    try {
        $dt = new DateTimeImmutable($tsRaw);
        $dtMsk = $dt->setTimezone(new DateTimeZone('Europe/Moscow'));
        return [$dtMsk->format('d.m.Y H:i'), $dtMsk->format('Y-m-d')];
    } catch (Throwable $e) {
        return [$tsRaw, ''];
    }
};

$providerLabelFn = static function (string $prov): string {
    if ($prov === 'yookassa') return 'ЮKassa';
    if ($prov === 'robokassa') return 'Робокасса';
    if ($prov === 'bank_invoice') return 'Счёт B2B / перевод';
    return $prov;
};

$statusRank = ['paid' => 3, 'pending' => 2, 'canceled' => 1, 'none' => 0];
$matchIndex = [];
$siteRowsAll = [];

for ($i = count($siteLeads) - 1; $i >= 0; $i--) {
    $r = $siteLeads[$i];
    $lid = (string)($r['leadId'] ?? '');
    $ps = $lid !== '' ? ($payMap['byLead'][$lid] ?? []) : [];
    $rawStatus = (string)($ps['status'] ?? ($r['paymentStatus'] ?? 'pending'));
    $normStatus = ($rawStatus === 'paid') ? 'paid' : (($rawStatus === 'canceled') ? 'canceled' : 'pending');
    $amount = (int)(($ps['amountRub'] ?? 0) ?: ($r['priceRub'] ?? 0));
    $provider = (string)($ps['provider'] ?? '');
    $paidAtRaw = (string)($ps['paidAt'] ?? '');
    [$tsFormatted, $dayMsk] = $formatMskFn((string)($r['ts'] ?? ''));
    [$paidAtFormatted] = $formatMskFn($paidAtRaw);

    $r['_payStatus'] = $normStatus;
    $r['_payProvider'] = $provider;
    $r['_payAmount'] = $amount;
    $r['_paidAtFormatted'] = $paidAtFormatted;
    $r['_tsFormatted'] = $tsFormatted;
    $r['_dayMsk'] = $dayMsk;
    $r['_utm'] = is_array($r['utm'] ?? null) ? $r['utm'] : [];
    $siteRowsAll[] = $r;

    $keys = [];
    $emailStr = trim((string)($r['email'] ?? ''));
    if (filter_var($emailStr, FILTER_VALIDATE_EMAIL)) {
        $keys[] = 'email:' . $lowerFn($emailStr);
    }
    $contactStr = trim((string)($r['contact'] ?? ''));
    if (filter_var($contactStr, FILTER_VALIDATE_EMAIL)) {
        $keys[] = 'email:' . $lowerFn($contactStr);
    }
    $phKey = $phoneKeyFn($contactStr);
    if ($phKey !== '') {
        $keys[] = 'phone:' . $phKey;
    }
    $tgKey = $tgKeyFn($contactStr);
    if ($tgKey !== '') {
        $keys[] = 'tg:' . $tgKey;
    }

    $candidate = [
        'status' => $normStatus,
        'amount' => $amount,
        'provider' => $provider,
        'leadId' => $lid,
        'paidAt' => $paidAtFormatted,
    ];
    foreach ($keys as $k) {
        $existing = $matchIndex[$k] ?? null;
        if ($existing === null || ($statusRank[$normStatus] ?? 0) > ($statusRank[$existing['status']] ?? 0)) {
            $matchIndex[$k] = $candidate;
        }
    }
}

$wlRowsAll = [];
for ($i = count($entries) - 1; $i >= 0; $i--) {
    $r = $entries[$i];
    [$tsFormatted, $dayMsk] = $formatMskFn((string)($r['ts'] ?? ''));
    $r['_tsFormatted'] = $tsFormatted;
    $r['_dayMsk'] = $dayMsk;
    $r['_utm'] = is_array($r['utm'] ?? null) ? $r['utm'] : [];

    $wlKeys = [];
    $wlEmail = trim((string)($r['email'] ?? ''));
    if (filter_var($wlEmail, FILTER_VALIDATE_EMAIL)) {
        $wlKeys[] = 'email:' . $lowerFn($wlEmail);
    }
    $wlPhone = $phoneKeyFn((string)($r['phone'] ?? ''));
    if ($wlPhone !== '') {
        $wlKeys[] = 'phone:' . $wlPhone;
    }
    $wlTg = $tgKeyFn((string)($r['telegram'] ?? ''));
    if ($wlTg !== '') {
        $wlKeys[] = 'tg:' . $wlTg;
    }

    $bestMatch = null;
    foreach ($wlKeys as $wk) {
        if (!isset($matchIndex[$wk])) {
            continue;
        }
        $cand = $matchIndex[$wk];
        if ($bestMatch === null || ($statusRank[$cand['status']] ?? 0) > ($statusRank[$bestMatch['status']] ?? 0)) {
            $bestMatch = $cand;
        }
    }

    $r['_payStatus'] = $bestMatch['status'] ?? 'none';
    $r['_payAmount'] = (int)($bestMatch['amount'] ?? 0);
    $r['_payProvider'] = (string)($bestMatch['provider'] ?? '');
    $r['_payLeadId'] = (string)($bestMatch['leadId'] ?? '');
    $r['_paidAtFormatted'] = (string)($bestMatch['paidAt'] ?? '');
    $wlRowsAll[] = $r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $csrfOk = hash_equals((string)$_SESSION['wl_csrf'], (string)($_POST['csrf'] ?? ''));

    if ($csrfOk && $action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: ./');
        exit;
    }

    $safeCsvCell = static function ($val): string {
        $s = trim(str_replace(["\r", "\n"], ' ', (string)$val));
        if ($s !== '' && preg_match('/^[=@\t\r]/', $s)) {
            $s = "'" . $s;
        } elseif ($s !== '' && ($s[0] === '+' || $s[0] === '-') && !preg_match('/^\+\d[\d\s()-]{6,}$/', $s)) {
            $s = "'" . $s;
        }
        return $s;
    };

    if ($csrfOk && $action === 'export_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="hr-retreat-waitlist-' . ev_msk_now()->format('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'wb');
        fputcsv($out, [
            'Номер записи', 'Дата (МСК)', 'ФИО', 'Почта', 'Телефон', 'Ник в Telegram',
            'Статус оплаты', 'Сумма оплаты (₽)', 'Заявка на сайте',
            'Почему хотите на HR-ретрит?', 'Согласие ПДн (152-ФЗ)', 'Публичная оферта', 'Рассылка (38-ФЗ)',
            'Ред. документов', 'UTM / Метки'
        ], ';');

        foreach ($wlRowsAll as $r) {
            $st = $r['_payStatus'];
            $stLabel = $st === 'paid' ? 'Оплатил' : ($st === 'pending' ? 'Заявка (ожидает)' : ($st === 'canceled' ? 'Отменена' : 'В листе'));
            fputcsv($out, [
                $safeCsvCell($r['waitlistId'] ?? ''),
                $safeCsvCell($r['_tsFormatted']),
                $safeCsvCell($r['name'] ?? ''),
                $safeCsvCell($r['email'] ?? ''),
                $safeCsvCell($r['phone'] ?? ''),
                $safeCsvCell($r['telegram'] ?? ''),
                $safeCsvCell($stLabel),
                $r['_payAmount'] > 0 ? (string)$r['_payAmount'] : '',
                $safeCsvCell($r['_payLeadId']),
                $safeCsvCell($r['motivation'] ?? ''),
                !empty($r['pdnConsent']) ? 'Да' : 'Нет',
                !empty($r['offerConsent']) ? 'Да' : 'Нет',
                !empty($r['marketingConsent']) ? 'Да' : 'Нет',
                $safeCsvCell($r['consentVersion'] ?? ''),
                $safeCsvCell(!empty($r['_utm']) ? json_encode($r['_utm'], JSON_UNESCAPED_UNICODE) : ''),
            ], ';');
        }
        fclose($out);
        exit;
    }

    if ($csrfOk && $action === 'export_payments_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="hr-retreat-payments-' . ev_msk_now()->format('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'wb');
        fputcsv($out, [
            'Номер заявки', 'Дата (МСК)', 'Формат', 'Имя', 'Контакт', 'Email', 'Компания / ИНН',
            'Тариф (₽)', 'Статус оплаты', 'Платёжный шлюз', 'Дата оплаты (МСК)',
            'Согласие ПДн', 'Публичная оферта', 'Рассылка (38-ФЗ)', 'Комментарий', 'UTM / Метки'
        ], ';');

        foreach ($siteRowsAll as $r) {
            $st = $r['_payStatus'];
            $stLabel = $st === 'paid' ? 'Оплачено' : ($st === 'canceled' ? 'Отменено' : 'Ожидает оплаты');
            fputcsv($out, [
                $safeCsvCell($r['leadId'] ?? ''),
                $safeCsvCell($r['_tsFormatted']),
                ($r['mode'] ?? '') === 'b2b' ? 'B2B (счёт)' : 'B2C (личный)',
                $safeCsvCell($r['name'] ?? ''),
                $safeCsvCell($r['contact'] ?? ''),
                $safeCsvCell($r['email'] ?? ''),
                $safeCsvCell($r['company'] ?? ''),
                (string)$r['_payAmount'],
                $safeCsvCell($stLabel),
                $safeCsvCell($providerLabelFn($r['_payProvider'])),
                $safeCsvCell($r['_paidAtFormatted']),
                !empty($r['pdnConsent']) ? 'Да' : 'Нет',
                !empty($r['offerConsent']) ? 'Да' : 'Нет',
                !empty($r['marketingConsent']) ? 'Да' : 'Нет',
                $safeCsvCell($r['comment'] ?? ''),
                $safeCsvCell(!empty($r['_utm']) ? json_encode($r['_utm'], JSON_UNESCAPED_UNICODE) : ''),
            ], ';');
        }
        fclose($out);
        exit;
    }
}

$activeTab = (($_GET['tab'] ?? '') === 'payments') ? 'payments' : 'waitlist';
$searchQuery = trim((string)($_GET['q'] ?? ''));

$wlStats = ['total' => 0, 'paid' => 0, 'promoYes' => 0, 'withUtm' => 0];
$wlFilterPromo = (string)($_GET['promo'] ?? 'all');
$wlFilterPay = (string)($_GET['pay'] ?? 'all');
$wlFilteredRows = [];

foreach ($wlRowsAll as $r) {
    $wlStats['total']++;
    if ($r['_payStatus'] === 'paid') {
        $wlStats['paid']++;
    }
    $promoYes = !empty($r['marketingConsent']);
    if ($promoYes) {
        $wlStats['promoYes']++;
    }
    if (!empty($r['_utm'])) {
        $wlStats['withUtm']++;
    }

    if ($activeTab !== 'waitlist') {
        continue;
    }
    if ($wlFilterPromo === 'yes' && !$promoYes) continue;
    if ($wlFilterPromo === 'no' && $promoYes) continue;
    if ($wlFilterPay !== 'all' && $r['_payStatus'] !== $wlFilterPay) continue;

    if ($searchQuery !== '') {
        $hay = $lowerFn(implode(' ', [
            (string)($r['waitlistId'] ?? ''),
            (string)($r['name'] ?? ''),
            (string)($r['email'] ?? ''),
            (string)($r['phone'] ?? ''),
            (string)($r['telegram'] ?? ''),
            (string)($r['motivation'] ?? ''),
            (string)$r['_payLeadId'],
            json_encode($r['_utm'], JSON_UNESCAPED_UNICODE),
        ]));
        if (!$strposFn($hay, $lowerFn($searchQuery))) {
            continue;
        }
    }
    $wlFilteredRows[] = $r;
}

$wlConvPct = $wlStats['total'] > 0 ? round(($wlStats['paid'] / $wlStats['total']) * 100, 1) : 0.0;

$siteStats = ['total' => 0, 'paidCount' => 0, 'paidSum' => 0, 'pendingCount' => 0, 'b2c' => 0, 'b2b' => 0];
$payFilterMode = (string)($_GET['mode'] ?? 'all');
$payFilterStatus = (string)($_GET['pay'] ?? 'all');
$siteFilteredRows = [];

foreach ($siteRowsAll as $r) {
    $siteStats['total']++;
    $mode = ($r['mode'] ?? 'b2c') === 'b2b' ? 'b2b' : 'b2c';
    if ($mode === 'b2b') $siteStats['b2b']++; else $siteStats['b2c']++;

    $st = $r['_payStatus'];
    if ($st === 'paid') {
        $siteStats['paidCount']++;
        $siteStats['paidSum'] += $r['_payAmount'];
    } elseif ($st === 'pending') {
        $siteStats['pendingCount']++;
    }

    if ($activeTab !== 'payments') {
        continue;
    }
    if ($payFilterMode !== 'all' && $mode !== $payFilterMode) continue;
    if ($payFilterStatus !== 'all' && $st !== $payFilterStatus) continue;

    if ($searchQuery !== '') {
        $hay = $lowerFn(implode(' ', [
            (string)($r['leadId'] ?? ''),
            (string)($r['name'] ?? ''),
            (string)($r['contact'] ?? ''),
            (string)($r['email'] ?? ''),
            (string)($r['company'] ?? ''),
            (string)($r['comment'] ?? ''),
            json_encode($r['_utm'], JSON_UNESCAPED_UNICODE),
        ]));
        if (!$strposFn($hay, $lowerFn($searchQuery))) {
            continue;
        }
    }
    $siteFilteredRows[] = $r;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <meta name="theme-color" content="#0e342e">
  <title><?= $activeTab === 'payments' ? 'Заявки и оплаты сайта' : 'Лист ожидания' ?> · Таблица · evitchak.ru</title>
  <link rel="stylesheet" href="../styles.css?v=12">
  <link rel="stylesheet" href="../deep-theme.css?v=5">
  <style>
    .wl-shell { padding: 28px 0 64px; }
    .wl-tabs { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid var(--line); padding-bottom: 14px; }
    .wl-tab { display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; border-radius: 12px; font-size: 14px; font-weight: 600; text-decoration: none; color: var(--teal-dark); background: var(--surface); border: 1px solid var(--line-strong); transition: all 0.15s ease; }
    .wl-tab:hover { border-color: var(--teal); }
    .wl-tab.is-active { background: #144e46; color: #ffffff; border-color: #144e46; }
    .wl-tab-count { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 700; background: rgba(20, 78, 70, 0.1); color: #144e46; }
    .wl-tab.is-active .wl-tab-count { background: rgba(236, 208, 155, 0.22); color: #ecd09b; }
    .wl-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 20px; }
    .wl-title h1 { font-size: clamp(23px, 3vw, 30px); color: var(--teal-dark); margin: 0 0 4px; }
    .wl-title p { font-size: 14px; color: var(--ink-muted); margin: 0; }
    .wl-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .wl-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(175px, 1fr)); gap: 14px; margin-bottom: 18px; }
    .wl-kpi { background: var(--surface); border: 1px solid var(--line); border-radius: 14px; padding: 15px 18px; }
    .wl-kpi span { display: block; font-size: 12.5px; color: var(--ink-muted); margin-bottom: 5px; }
    .wl-kpi strong { font-size: 25px; color: var(--teal-dark); font-variant-numeric: tabular-nums; }
    .wl-kpi small { font-size: 13px; color: var(--ink-muted); font-weight: 500; margin-left: 4px; }
    .wl-filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; background: var(--surface); border: 1px solid var(--line); border-radius: 14px; padding: 14px 16px; margin-bottom: 18px; }
    .wl-filters input[type="search"] { flex: 1 1 250px; padding: 9px 12px; border: 1px solid var(--line-strong); border-radius: 10px; font-family: inherit; font-size: 14px; background: var(--bg-soft); }
    .wl-filters select { padding: 9px 12px; border: 1px solid var(--line-strong); border-radius: 10px; font-family: inherit; font-size: 14px; background: var(--bg-soft); }
    .wl-table-wrap { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; overflow-x: auto; }
    .wl-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .wl-table th { text-align: left; padding: 12px 14px; background: var(--bg-soft); color: var(--teal-dark); font-weight: 700; border-bottom: 1px solid var(--line); white-space: nowrap; }
    .wl-table td { padding: 12px 14px; border-bottom: 1px solid var(--line); vertical-align: top; }
    .wl-table tr:last-child td { border-bottom: none; }
    .wl-badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
    .wl-badge-yes { background: #e3f5ee; color: #0f5c43; }
    .wl-badge-wait { background: #fbf4e4; color: #7c5210; }
    .wl-badge-cancel { background: #fdecea; color: #8c261c; }
    .wl-badge-no { background: #f2f1ed; color: #5f6966; }
    .wl-badge-b2b { background: #e8eef5; color: #1b3b66; }
    .wl-badge-b2c { background: #eef6f4; color: #144e46; }
    .wl-empty { padding: 40px 20px; text-align: center; color: var(--ink-muted); font-size: 15px; }
    .wl-motivation { max-width: 320px; white-space: pre-wrap; line-height: 1.45; color: #263431; }
    .wl-copy-msg { font-size: 13px; color: #0f5c43; font-weight: 600; display: none; }
    .wl-readonly-note { background: #f2f7f5; border: 1px solid #c6dcd5; color: #144e46; padding: 10px 14px; border-radius: 11px; font-size: 13px; margin-bottom: 16px; }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="wrap header-inner">
      <a class="brand" href="../waitlist/">
        <span class="brand-title">Школа Елены Витчак</span>
        <span class="brand-sub">Лист ожидания и оплаты · evitchak.ru</span>
      </a>
      <div class="wl-actions">
        <a class="btn btn-outline btn-sm" href="../waitlist/" target="_blank" rel="noopener noreferrer" style="color:#ecd09b;border-color:rgba(236,208,155,0.45);">Анкета листа ожидания</a>
        <a class="btn btn-teal btn-sm" href="../" target="_blank" rel="noopener noreferrer">Основной сайт</a>
        <form method="POST" action="./" style="margin:0;">
          <input type="hidden" name="action" value="logout">
          <input type="hidden" name="csrf" value="<?= $h($_SESSION['wl_csrf']) ?>">
          <button class="btn btn-dark btn-sm" type="submit">Выйти</button>
        </form>
      </div>
    </div>
  </header>

  <main class="wrap wl-shell">
    <nav class="wl-tabs" aria-label="Разделы таблицы">
      <a class="wl-tab <?= $activeTab === 'waitlist' ? 'is-active' : '' ?>" href="./?tab=waitlist">
        <span>Лист ожидания</span>
        <span class="wl-tab-count"><?= (int)$wlStats['total'] ?></span>
      </a>
      <a class="wl-tab <?= $activeTab === 'payments' ? 'is-active' : '' ?>" href="./?tab=payments">
        <span>Заявки и оплаты сайта</span>
        <span class="wl-tab-count"><?= (int)$siteStats['total'] ?> · оплачено <?= (int)$siteStats['paidCount'] ?></span>
      </a>
    </nav>

    <?php if ($activeTab === 'waitlist'): ?>
      <div class="wl-top">
        <div class="wl-title">
          <h1>Таблица листа ожидания HR-ретрита</h1>
          <p>Хранение ПДн в РФ (152-ФЗ) · МСК · Статус оплаты подтягивается автоматически при совпадении почты, телефона или Telegram</p>
        </div>
        <div class="wl-actions">
          <button class="btn btn-outline btn-sm" type="button" id="wlCopyTableBtn">Скопировать для Excel / Sheets</button>
          <span class="wl-copy-msg" id="wlCopyMsg">Скопировано в буфер ✓</span>
          <form method="POST" action="./?tab=waitlist" style="margin:0;">
            <input type="hidden" name="action" value="export_csv">
            <input type="hidden" name="csrf" value="<?= $h($_SESSION['wl_csrf']) ?>">
            <button class="btn btn-teal btn-sm" type="submit">Скачать Excel (CSV)</button>
          </form>
        </div>
      </div>

      <div class="wl-kpi-grid">
        <div class="wl-kpi"><span>Всего в листе ожидания</span><strong><?= (int)$wlStats['total'] ?></strong></div>
        <div class="wl-kpi"><span>Оплатили из листа</span><strong><?= (int)$wlStats['paid'] ?><small>(<?= $wlConvPct ?>%)</small></strong></div>
        <div class="wl-kpi"><span>Согласны на рассылку (38-ФЗ)</span><strong><?= (int)$wlStats['promoYes'] ?></strong></div>
        <div class="wl-kpi"><span>Переходы с UTM-метками</span><strong><?= (int)$wlStats['withUtm'] ?></strong></div>
      </div>

      <form class="wl-filters" method="GET" action="./">
        <input type="hidden" name="tab" value="waitlist">
        <input type="search" name="q" value="<?= $h($searchQuery) ?>" placeholder="Поиск по ФИО, почте, телефону, Telegram, запросу или UTM">
        <select name="promo">
          <option value="all" <?= $wlFilterPromo === 'all' ? 'selected' : '' ?>>Все согласия на рассылку</option>
          <option value="yes" <?= $wlFilterPromo === 'yes' ? 'selected' : '' ?>>Рассылка 38-ФЗ: Да</option>
          <option value="no" <?= $wlFilterPromo === 'no' ? 'selected' : '' ?>>Рассылка 38-ФЗ: Нет</option>
        </select>
        <select name="pay">
          <option value="all" <?= $wlFilterPay === 'all' ? 'selected' : '' ?>>Все статусы оплаты</option>
          <option value="paid" <?= $wlFilterPay === 'paid' ? 'selected' : '' ?>>Оплатили на сайте</option>
          <option value="pending" <?= $wlFilterPay === 'pending' ? 'selected' : '' ?>>Оставили заявку (ожидают)</option>
          <option value="none" <?= $wlFilterPay === 'none' ? 'selected' : '' ?>>Пока только в листе</option>
        </select>
        <button class="btn btn-teal btn-sm" type="submit">Найти</button>
        <?php if ($searchQuery !== '' || $wlFilterPromo !== 'all' || $wlFilterPay !== 'all'): ?>
          <a class="btn btn-outline btn-sm" href="./?tab=waitlist">Сбросить</a>
        <?php endif; ?>
      </form>

      <div class="wl-table-wrap">
        <?php if (empty($wlFilteredRows)): ?>
          <div class="wl-empty">Записей в листе ожидания по выбранным параметрам пока нет.</div>
        <?php else: ?>
          <table class="wl-table" id="wlDataTable" data-copy-mode="waitlist">
            <thead>
              <tr>
                <th>№ / Дата (МСК)</th>
                <th>ФИО</th>
                <th>Почта и телефон</th>
                <th>Telegram</th>
                <th>Оплата на сайте</th>
                <th>Почему хотите на HR-ретрит?</th>
                <th>Согласия (152-ФЗ / 38-ФЗ)</th>
                <th>UTM-метки</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($wlFilteredRows as $r):
              $wlId = (string)($r['waitlistId'] ?? '—');
              $tg = (string)($r['telegram'] ?? '');
              $tgSlug = ltrim($tg, '@');
              $email = (string)($r['email'] ?? '');
              $phone = (string)($r['phone'] ?? '');
              $phoneHref = preg_replace('/[^\d+]/', '', $phone) ?? '';
              $utm = $r['_utm'];
              $st = $r['_payStatus'];
              $stText = $st === 'paid' ? 'Оплатил' : ($st === 'pending' ? 'Заявка (ожидает)' : ($st === 'canceled' ? 'Отменена' : 'В листе'));
            ?>
              <tr
                data-c0="<?= $h($wlId) ?>"
                data-c1="<?= $h($r['_tsFormatted']) ?>"
                data-c2="<?= $h($r['name'] ?? '') ?>"
                data-c3="<?= $h($email) ?>"
                data-c4="<?= $h($phone) ?>"
                data-c5="<?= $h($tg) ?>"
                data-c6="<?= $h($stText . ($r['_payAmount'] > 0 ? ' (' . $r['_payAmount'] . ' ₽)' : '')) ?>"
                data-c7="<?= $h(str_replace(["\r", "\n", "\t"], ' ', (string)($r['motivation'] ?? ''))) ?>"
                data-c8="<?= !empty($r['marketingConsent']) ? 'Да' : 'Нет' ?>"
              >
                <td style="white-space:nowrap;">
                  <strong><?= $h($wlId) ?></strong><br>
                  <span style="color:var(--ink-muted);font-size:12px;"><?= $h($r['_tsFormatted']) ?></span>
                </td>
                <td><strong><?= $h($r['name'] ?? '') ?></strong></td>
                <td>
                  <?php if ($email !== ''): ?>
                    <a href="mailto:<?= $h($email) ?>" style="text-decoration:underline;"><?= $h($email) ?></a><br>
                  <?php endif; ?>
                  <?php if ($phone !== ''): ?>
                    <a href="tel:<?= $h($phoneHref) ?>" style="white-space:nowrap;"><?= $h($phone) ?></a>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                  <?php if ($tgSlug !== ''): ?>
                    <a href="https://t.me/<?= $h($tgSlug) ?>" target="_blank" rel="noopener noreferrer" style="text-decoration:underline;font-weight:600;"><?= $h($tg) ?></a>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                  <?php if ($st === 'paid'): ?>
                    <span class="wl-badge wl-badge-yes">Оплатил · <?= number_format((int)$r['_payAmount'], 0, ',', ' ') ?> ₽</span><br>
                    <span style="color:var(--ink-muted);font-size:11.5px;"><?= $h($r['_payLeadId']) ?><?= $r['_payProvider'] !== '' ? ' · ' . $h($providerLabelFn($r['_payProvider'])) : '' ?></span>
                  <?php elseif ($st === 'pending'): ?>
                    <span class="wl-badge wl-badge-wait">Заявка · ожидает</span><br>
                    <span style="color:var(--ink-muted);font-size:11.5px;"><?= $h($r['_payLeadId']) ?></span>
                  <?php elseif ($st === 'canceled'): ?>
                    <span class="wl-badge wl-badge-cancel">Платёж отменён</span>
                  <?php else: ?>
                    <span class="wl-badge wl-badge-no">Пока в листе</span>
                  <?php endif; ?>
                </td>
                <td><div class="wl-motivation"><?= $h($r['motivation'] ?? '') ?></div></td>
                <td style="white-space:nowrap;font-size:12.5px;">
                  ПДн + Оферта: <strong><?= (!empty($r['pdnConsent']) && !empty($r['offerConsent'])) ? '✓' : '—' ?></strong><br>
                  <span class="wl-badge <?= !empty($r['marketingConsent']) ? 'wl-badge-yes' : 'wl-badge-no' ?>">
                    Рассылка: <?= !empty($r['marketingConsent']) ? 'Да' : 'Нет' ?>
                  </span>
                </td>
                <td style="font-size:12px;color:var(--ink-muted);">
                  <?= !empty($utm) ? $h(json_encode($utm, JSON_UNESCAPED_UNICODE)) : '—' ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    <?php else: ?>
      <div class="wl-top">
        <div class="wl-title">
          <h1>Заявки и оплаты основного сайта (evitchak.ru)</h1>
          <p>Статусы оплат ЮKassa и счетов B2B в реальном времени · МСК · Для отслеживания конверсии и UTM</p>
        </div>
        <div class="wl-actions">
          <button class="btn btn-outline btn-sm" type="button" id="wlCopyTableBtn">Скопировать для Excel / Sheets</button>
          <span class="wl-copy-msg" id="wlCopyMsg">Скопировано в буфер ✓</span>
          <form method="POST" action="./?tab=payments" style="margin:0;">
            <input type="hidden" name="action" value="export_payments_csv">
            <input type="hidden" name="csrf" value="<?= $h($_SESSION['wl_csrf']) ?>">
            <button class="btn btn-teal btn-sm" type="submit">Скачать оплаты в Excel (CSV)</button>
          </form>
        </div>
      </div>

      <div class="wl-readonly-note">
        <strong>Режим просмотра:</strong> статусы онлайн-оплат ЮKassa обновляются автоматически после подтверждения платёжного шлюза.
      </div>

      <div class="wl-kpi-grid">
        <div class="wl-kpi"><span>Всего заявок сайта</span><strong><?= (int)$siteStats['total'] ?></strong></div>
        <div class="wl-kpi"><span>Оплачено заявок</span><strong><?= (int)$siteStats['paidCount'] ?></strong></div>
        <div class="wl-kpi"><span>Оплачено на сумму</span><strong><?= number_format($siteStats['paidSum'], 0, ',', ' ') ?> ₽</strong></div>
        <div class="wl-kpi"><span>Ожидают оплаты</span><strong><?= (int)$siteStats['pendingCount'] ?></strong></div>
        <div class="wl-kpi"><span>B2C / B2B</span><strong><?= (int)$siteStats['b2c'] ?> / <?= (int)$siteStats['b2b'] ?></strong></div>
      </div>

      <form class="wl-filters" method="GET" action="./">
        <input type="hidden" name="tab" value="payments">
        <input type="search" name="q" value="<?= $h($searchQuery) ?>" placeholder="Поиск по номеру HR-..., имени, контакту, почте, компании или UTM">
        <select name="mode">
          <option value="all" <?= $payFilterMode === 'all' ? 'selected' : '' ?>>Все форматы (B2C + B2B)</option>
          <option value="b2c" <?= $payFilterMode === 'b2c' ? 'selected' : '' ?>>Только физлица (B2C)</option>
          <option value="b2b" <?= $payFilterMode === 'b2b' ? 'selected' : '' ?>>Только компании (B2B)</option>
        </select>
        <select name="pay">
          <option value="all" <?= $payFilterStatus === 'all' ? 'selected' : '' ?>>Все статусы оплаты</option>
          <option value="paid" <?= $payFilterStatus === 'paid' ? 'selected' : '' ?>>Только оплаченные</option>
          <option value="pending" <?= $payFilterStatus === 'pending' ? 'selected' : '' ?>>Ожидают оплаты</option>
          <option value="canceled" <?= $payFilterStatus === 'canceled' ? 'selected' : '' ?>>Отменённые</option>
        </select>
        <button class="btn btn-teal btn-sm" type="submit">Показать</button>
        <?php if ($searchQuery !== '' || $payFilterMode !== 'all' || $payFilterStatus !== 'all'): ?>
          <a class="btn btn-outline btn-sm" href="./?tab=payments">Сбросить</a>
        <?php endif; ?>
      </form>

      <div class="wl-table-wrap">
        <?php if (empty($siteFilteredRows)): ?>
          <div class="wl-empty">Заявок с основного сайта по выбранным фильтрам пока нет.</div>
        <?php else: ?>
          <table class="wl-table" id="wlDataTable" data-copy-mode="payments">
            <thead>
              <tr>
                <th>Заявка / Дата (МСК)</th>
                <th>Участник и контакты</th>
                <th>Формат / Компания</th>
                <th>Сумма и статус оплаты</th>
                <th>Согласия и UTM</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($siteFilteredRows as $r):
              $lid = (string)($r['leadId'] ?? '—');
              $st = $r['_payStatus'];
              $prov = $providerLabelFn($r['_payProvider']);
              $utm = $r['_utm'];
              $stText = $st === 'paid' ? 'Оплачено' : ($st === 'canceled' ? 'Отменено' : 'Ожидает');
            ?>
              <tr
                data-c0="<?= $h($lid) ?>"
                data-c1="<?= $h($r['_tsFormatted']) ?>"
                data-c2="<?= ($r['mode'] ?? '') === 'b2b' ? 'B2B' : 'B2C' ?>"
                data-c3="<?= $h($r['name'] ?? '') ?>"
                data-c4="<?= $h($r['contact'] ?? '') ?>"
                data-c5="<?= $h($r['email'] ?? '') ?>"
                data-c6="<?= (int)$r['_payAmount'] ?>"
                data-c7="<?= $h($stText) ?>"
                data-c8="<?= $h($prov) ?>"
              >
                <td style="white-space:nowrap;">
                  <strong><?= $h($lid) ?></strong><br>
                  <span style="color:var(--ink-muted);font-size:12px;"><?= $h($r['_tsFormatted']) ?> МСК</span>
                </td>
                <td>
                  <strong><?= $h($r['name'] ?? '') ?></strong><br>
                  <span><?= $h($r['contact'] ?? '') ?></span>
                  <?php if (!empty($r['email'])): ?><br><a href="mailto:<?= $h($r['email']) ?>" style="color:var(--ink-muted);text-decoration:underline;"><?= $h($r['email']) ?></a><?php endif; ?>
                  <?php if (!empty($r['comment'])): ?><div style="margin-top:6px;font-size:12.5px;color:var(--ink-muted);">💬 <?= $h($r['comment']) ?></div><?php endif; ?>
                </td>
                <td>
                  <span class="wl-badge <?= ($r['mode'] ?? '') === 'b2b' ? 'wl-badge-b2b' : 'wl-badge-b2c' ?>">
                    <?= ($r['mode'] ?? '') === 'b2b' ? 'B2B (счёт)' : 'B2C (личный)' ?>
                  </span>
                  <?php if (($r['mode'] ?? '') === 'b2b' && !empty($r['company']) && $r['company'] !== '—'): ?>
                    <div style="margin-top:6px;font-size:12.5px;"><?= $h($r['company']) ?></div>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                  <strong><?= number_format((int)$r['_payAmount'], 0, ',', ' ') ?> ₽</strong><br>
                  <?php if ($st === 'paid'): ?>
                    <span class="wl-badge wl-badge-yes">Оплачено<?= $prov !== '' ? ' · ' . $h($prov) : '' ?></span>
                    <?php if ($r['_paidAtFormatted'] !== ''): ?><div style="margin-top:4px;font-size:11.5px;color:var(--ink-muted);"><?= $h($r['_paidAtFormatted']) ?> МСК</div><?php endif; ?>
                  <?php elseif ($st === 'canceled'): ?>
                    <span class="wl-badge wl-badge-cancel">Отменено</span>
                  <?php else: ?>
                    <span class="wl-badge wl-badge-wait">Ожидает<?= $prov !== '' ? ' · ' . $h($prov) : '' ?></span>
                  <?php endif; ?>
                </td>
                <td style="font-size:12.5px;color:var(--ink-muted);">
                  ПДн / Оферта: <strong><?= (!empty($r['pdnConsent']) && !empty($r['offerConsent'])) ? '✓' : '—' ?></strong><br>
                  Рассылка 38-ФЗ: <strong><?= !empty($r['marketingConsent']) ? 'Да' : 'Нет' ?></strong>
                  <?php if (!empty($utm)): ?><br>UTM: <?= $h(json_encode($utm, JSON_UNESCAPED_UNICODE)) ?><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>

  <script>
    (() => {
      const btn = document.getElementById('wlCopyTableBtn');
      const msg = document.getElementById('wlCopyMsg');
      const tbl = document.getElementById('wlDataTable');
      if (!btn || !tbl) return;

      btn.addEventListener('click', async () => {
        const mode = tbl.getAttribute('data-copy-mode') || 'waitlist';
        const header = mode === 'payments'
          ? ['Номер', 'Дата (МСК)', 'Формат', 'Имя', 'Контакт', 'Email', 'Сумма (₽)', 'Статус оплаты', 'Шлюз']
          : ['Номер', 'Дата (МСК)', 'ФИО', 'Почта', 'Телефон', 'Telegram', 'Оплата на сайте', 'Почему хотите на HR-ретрит?', 'Рассылка 38-ФЗ'];
        const lines = [header.join('\t')];
        tbl.querySelectorAll('tbody tr').forEach((tr) => {
          const cols = [];
          for (let i = 0; i < header.length; i++) {
            cols.push(tr.getAttribute('data-c' + i) || '');
          }
          lines.push(cols.join('\t'));
        });
        try {
          await navigator.clipboard.writeText(lines.join('\n'));
          if (msg) {
            msg.style.display = 'inline';
            setTimeout(() => { msg.style.display = 'none'; }, 2600);
          }
        } catch (_) {}
      });
    })();
  </script>
</body>
</html>
