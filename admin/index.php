<?php
/**
 * Закрытый мини-кабинет заявок и оплат HR-ретрита (152-ФЗ РФ, сервер Timeweb).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/_storage_lib.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$secretFile = dirname(__DIR__) . '/api/.admin_secret.php';
$secretCfg = is_file($secretFile) ? (@include $secretFile) : null;
$passwordHash = is_array($secretCfg) ? trim((string)($secretCfg['passwordHash'] ?? '')) : '';

if ($passwordHash === '') {
    http_response_code(503);
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Кабинет не настроен</title></head><body style="font-family:system-ui,sans-serif;padding:40px;"><h1>Кабинет временно недоступен</h1><p>Не задан хеш доступа в закрытом файле api/.admin_secret.php.</p></body></html>';
    exit;
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_HTTPS'] ?? '') === '1')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('ev_admin_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$clientIp = ev_clean_line($_SERVER['HTTP_X_REAL_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

$getRateFile = static function (): string {
    foreach (ev_candidate_dirs() as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            return $dir . '/.admin_ratelimit.json';
        }
    }
    return rtrim(sys_get_temp_dir(), '/\\') . '/ev_admin_ratelimit.json';
};

$checkRateLimit = static function (string $ip, ?bool $recordFail = null) use ($getRateFile): array {
    $file = $getRateFile();
    $now = time();
    $window = 600; // 10 минут
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

        // Очистка устаревших ключей
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

// Таймаут неактивности сессии (30 минут)
if (!empty($_SESSION['auth']) && (time() - (int)($_SESSION['last_seen'] ?? 0)) > 1800) {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
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
$flashMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $csrfOk = hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));

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
            if ($verifySecretPassword($passInput, $passwordHash)) {
                $checkRateLimit($clientIp, false);
                session_regenerate_id(true);
                $_SESSION['auth'] = true;
                $_SESSION['last_seen'] = time();
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                header('Location: ./');
                exit;
            }
            $rlAfter = $checkRateLimit($clientIp, true);
            $loginError = $rlAfter['blocked']
                ? 'Превышен лимит попыток. Вход заблокирован на 10 минут.'
                : 'Неверный пароль доступа.';
        }
    } elseif ($action === 'logout' && $csrfOk) {
        $_SESSION = [];
        session_destroy();
        header('Location: ./');
        exit;
    }
}

$isAuthed = !empty($_SESSION['auth']);
if ($isAuthed) {
    $_SESSION['last_seen'] = time();
}

$h = static fn ($s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if (!$isAuthed):
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#0e342e">
  <title>Вход в кабинет заявок · Школа Елены Витчак</title>
  <link rel="stylesheet" href="../styles.css?v=12">
  <link rel="stylesheet" href="../deep-theme.css?v=4">
  <style>
    .adm-auth-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 28px 16px; }
    .adm-auth-card { width: 100%; max-width: 420px; background: var(--surface); border: 1px solid var(--line); border-radius: 20px; padding: 34px 32px; box-shadow: 0 24px 52px -30px rgba(14, 56, 50, 0.35); }
    .adm-kicker { font-size: 12px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--gold-text); margin-bottom: 8px; }
    .adm-auth-card h1 { font-size: 26px; color: var(--teal-dark); margin-bottom: 8px; }
    .adm-auth-sub { font-size: 14.5px; color: var(--ink-muted); margin-bottom: 20px; line-height: 1.5; }
    .adm-err { background: #fdf1ef; border: 1px solid #e6b3ad; color: #8b261d; padding: 10px 14px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
  </style>
</head>
<body>
  <main class="adm-auth-wrap">
    <section class="adm-auth-card">
      <div class="adm-kicker">evitchak.ru · Сервер РФ (152-ФЗ)</div>
      <h1>Кабинет заявок HR-ретрита</h1>
      <p class="adm-auth-sub">Закрытая зона для просмотра заявок, статусов оплат ЮKassa / Робокассы / B2B и выгрузки отчётов.</p>
      <?php if ($loginError !== ''): ?>
        <div class="adm-err" role="alert"><?= $h($loginError) ?></div>
      <?php endif; ?>
      <form method="POST" action="./">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
        <div class="field" style="margin-bottom:16px;">
          <label for="admPassword">Пароль администратора</label>
          <input id="admPassword" name="password" type="password" required autocomplete="current-password" autofocus placeholder="Введите пароль кабинета">
        </div>
        <button class="btn btn-teal" type="submit" style="width:100%;">Войти в кабинет</button>
      </form>
      <p style="margin-top:16px;font-size:13px;color:var(--ink-muted);text-align:center;">
        <a href="../" style="text-decoration:underline;">← Вернуться на сайт evitchak.ru</a>
      </p>
    </section>
  </main>
</body>
</html>
<?php
    exit;
endif;

// Обработка действий внутри авторизованного кабинета
$leads = ev_read_jsonl('hr_retreat_leads.jsonl');
$payMap = ev_payment_state_map();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $csrfOk = hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));

    if ($csrfOk && $action === 'mark_b2b_paid') {
        $targetLeadId = ev_clean_line($_POST['lead_id'] ?? '');
        if (preg_match('/^HR-\d{8}-[A-F0-9]{6}$/', $targetLeadId)) {
            $foundLead = ev_find_lead($targetLeadId);
            $curState = $payMap['byLead'][$targetLeadId]['status'] ?? 'pending';
            if ($foundLead !== null && $curState !== 'paid') {
                ev_append_jsonl('hr_retreat_payments.jsonl', [
                    'ts' => ev_msk_now()->format(DATE_ATOM),
                    'leadId' => $targetLeadId,
                    'provider' => 'bank_invoice',
                    'status' => 'paid',
                    'amountRub' => (int)($foundLead['priceRub'] ?? 50000),
                ]);
                $payMap = ev_payment_state_map();
                $flashMsg = "Оплата по счёту/переводу для заявки {$targetLeadId} подтверждена.";
            }
        }
    } elseif ($csrfOk && $action === 'export_csv') {
        $safeCsvCell = static function ($val): string {
            $s = trim(str_replace(["\r", "\n"], ' ', (string)$val));
            if ($s !== '' && preg_match('/^[=@\t\r]/', $s)) {
                $s = "'" . $s;
            } elseif ($s !== '' && ($s[0] === '+' || $s[0] === '-') && !preg_match('/^\+\d[\d\s()-]{6,}$/', $s)) {
                $s = "'" . $s;
            }
            return $s;
        };

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="hr-retreat-leads-' . ev_msk_now()->format('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'wb');
        fputcsv($out, [
            'Номер заявки', 'Дата (МСК)', 'Формат', 'Тариф (₽)', 'Статус оплаты',
            'Платёжный шлюз', 'Имя', 'Контакт', 'Email', 'Компания / ИНН',
            'Согласие ПДн', 'Оферта', 'Рассылка 38-ФЗ', 'Ред. документов',
            'UTM source', 'UTM campaign', 'Комментарий',
        ], ';');

        for ($i = count($leads) - 1; $i >= 0; $i--) {
            $r = $leads[$i];
            $lid = (string)($r['leadId'] ?? '');
            $ps = $lid !== '' ? ($payMap['byLead'][$lid] ?? []) : [];
            $utm = is_array($r['utm'] ?? null) ? $r['utm'] : [];
            $rawSt = (string)($ps['status'] ?? ($r['paymentStatus'] ?? 'pending'));
            switch ($rawSt) {
                case 'paid': $stLabel = 'Оплачено'; break;
                case 'created': $stLabel = 'Ожидает оплаты'; break;
                case 'canceled': $stLabel = 'Отменено'; break;
                default: $stLabel = 'Заявка (ожидает)'; break;
            }
            fputcsv($out, [
                $safeCsvCell($lid),
                $safeCsvCell($r['ts'] ?? ''),
                ($r['mode'] ?? '') === 'b2b' ? 'B2B' : 'B2C',
                (string)($ps['amountRub'] ?: ($r['priceRub'] ?? '')),
                $stLabel,
                $safeCsvCell($ps['provider'] ?? ''),
                $safeCsvCell($r['name'] ?? ''),
                $safeCsvCell($r['contact'] ?? ''),
                $safeCsvCell($r['email'] ?? ''),
                $safeCsvCell($r['company'] ?? ''),
                !empty($r['pdnConsent']) ? 'Да' : 'Нет',
                !empty($r['offerConsent']) ? 'Да' : 'Нет',
                !empty($r['marketingConsent']) ? 'Да' : 'Нет',
                $safeCsvCell($r['consentVersion'] ?? ''),
                $safeCsvCell($utm['utm_source'] ?? ''),
                $safeCsvCell($utm['utm_campaign'] ?? ''),
                $safeCsvCell($r['comment'] ?? ''),
            ], ';');
        }
        fclose($out);
        exit;
    }
}

$filterMode = (string)($_GET['mode'] ?? 'all');
$filterPay = (string)($_GET['pay'] ?? 'all');
$searchQuery = trim((string)($_GET['q'] ?? ''));

$rows = [];
$stats = ['total' => 0, 'b2c' => 0, 'b2b' => 0, 'paidCount' => 0, 'paidSum' => 0, 'pendingCount' => 0];

for ($i = count($leads) - 1; $i >= 0; $i--) {
    $r = $leads[$i];
    $lid = (string)($r['leadId'] ?? '');
    $ps = $lid !== '' ? ($payMap['byLead'][$lid] ?? []) : [];
    $payStatus = (string)($ps['status'] ?? ($r['paymentStatus'] ?? 'pending'));
    $mode = ($r['mode'] ?? 'b2c') === 'b2b' ? 'b2b' : 'b2c';
    $amount = (int)(($ps['amountRub'] ?? 0) ?: ($r['priceRub'] ?? 0));

    $stats['total']++;
    if ($mode === 'b2b') $stats['b2b']++; else $stats['b2c']++;
    if ($payStatus === 'paid') {
        $stats['paidCount']++;
        $stats['paidSum'] += $amount;
    } else {
        $stats['pendingCount']++;
    }

    if ($filterMode !== 'all' && $mode !== $filterMode) continue;
    if ($filterPay === 'paid' && $payStatus !== 'paid') continue;
    if ($filterPay === 'pending' && $payStatus === 'paid') continue;

    if ($searchQuery !== '') {
        $haystack = mb_strtolower(implode(' ', [
            $lid,
            (string)($r['name'] ?? ''),
            (string)($r['contact'] ?? ''),
            (string)($r['email'] ?? ''),
            (string)($r['company'] ?? ''),
            (string)($r['comment'] ?? ''),
            json_encode($r['utm'] ?? [], JSON_UNESCAPED_UNICODE),
        ]));
        if (mb_strpos($haystack, mb_strtolower($searchQuery)) === false) {
            continue;
        }
    }

    $r['_payStatus'] = $payStatus;
    $r['_payProvider'] = (string)($ps['provider'] ?? '');
    $r['_payAmount'] = $amount;
    $rows[] = $r;
}

$payCfg = ev_load_payment_config();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#0e342e">
  <title>Кабинет заявок и оплат · evitchak.ru</title>
  <link rel="stylesheet" href="../styles.css?v=12">
  <link rel="stylesheet" href="../deep-theme.css?v=4">
  <style>
    .adm-shell { padding: 32px 0 64px; }
    .adm-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 24px; }
    .adm-title h1 { font-size: clamp(24px, 3vw, 32px); color: var(--teal-dark); margin: 0 0 4px; }
    .adm-title p { font-size: 14px; color: var(--ink-muted); margin: 0; }
    .adm-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .adm-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(175px, 1fr)); gap: 14px; margin-bottom: 22px; }
    .adm-kpi { background: var(--surface); border: 1px solid var(--line); border-radius: 14px; padding: 16px 18px; }
    .adm-kpi span { display: block; font-size: 12.5px; color: var(--ink-muted); margin-bottom: 6px; }
    .adm-kpi strong { font-size: 24px; color: var(--teal-dark); font-weight: 700; }
    .adm-gateways { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; font-size: 13px; }
    .adm-gw-pill { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); }
    .adm-gw-pill.is-on { border-color: #1d7a5f; background: #eef8f4; color: #125440; font-weight: 600; }
    .adm-filters { background: var(--surface); border: 1px solid var(--line); border-radius: 14px; padding: 14px 16px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 18px; }
    .adm-filters select, .adm-filters input[type="search"] { padding: 9px 12px; border: 1px solid var(--line); border-radius: 10px; font-size: 14px; background: #fff; }
    .adm-filters input[type="search"] { flex: 1 1 220px; }
    .adm-table-wrap { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; overflow-x: auto; }
    table.adm-table { width: 100%; border-collapse: collapse; min-width: 880px; font-size: 13.5px; }
    .adm-table th { text-align: left; padding: 12px 14px; background: var(--bg-soft); color: var(--teal-dark); font-weight: 700; border-bottom: 1px solid var(--line); white-space: nowrap; }
    .adm-table td { padding: 12px 14px; border-bottom: 1px solid var(--line); vertical-align: top; }
    .adm-table tr:last-child td { border-bottom: none; }
    .badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
    .badge-paid { background: #e3f5ee; color: #0f5c43; }
    .badge-wait { background: #fbf4e4; color: #7c5210; }
    .badge-cancel { background: #fdecea; color: #8c261c; }
    .badge-b2b { background: #e8eef5; color: #1b3b66; }
    .badge-b2c { background: #eef6f4; color: #144e46; }
    .adm-flash { background: #eef8f4; border: 1px solid #8ec9b5; color: #125440; padding: 12px 16px; border-radius: 12px; margin-bottom: 16px; font-size: 14px; font-weight: 600; }
    .adm-empty { padding: 38px 20px; text-align: center; color: var(--ink-muted); font-size: 15px; }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="wrap header-inner">
      <a class="brand" href="../">
        <span class="brand-title">Школа Елены Витчак</span>
        <span class="brand-sub">Кабинет заявок · evitchak.ru</span>
      </a>
      <div class="adm-actions">
        <a class="btn btn-outline btn-sm" href="../waitlist-table/">Лист ожидания</a>
        <a class="btn btn-teal btn-sm" href="../">Открыть сайт</a>
        <form method="POST" action="./" style="margin:0;">
          <input type="hidden" name="action" value="logout">
          <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
          <button class="btn btn-dark btn-sm" type="submit">Выйти</button>
        </form>
      </div>
    </div>
  </header>

  <main class="wrap adm-shell">
    <div class="adm-top">
      <div class="adm-title">
        <h1>Заявки и оплаты HR-ретрита</h1>
        <p>Хранение в РФ (Timeweb, 152-ФЗ) · Московское время (МСК) · Обновляется в реальном времени</p>
      </div>
      <div class="adm-actions">
        <form method="POST" action="./" style="margin:0;">
          <input type="hidden" name="action" value="export_csv">
          <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
          <button class="btn btn-teal btn-sm" type="submit">Скачать таблицу в Excel (CSV)</button>
        </form>
      </div>
    </div>

    <?php if ($flashMsg !== ''): ?>
      <div class="adm-flash"><?= $h($flashMsg) ?></div>
    <?php endif; ?>

    <div class="adm-gateways">
      <span class="adm-gw-pill <?= !empty($payCfg['yookassa']['enabled']) ? 'is-on' : '' ?>">
        ЮKassa API: <?= !empty($payCfg['yookassa']['enabled']) ? 'подключена ✓' : 'ожидает ключи (.payment_secret.php)' ?>
      </span>
      <span class="adm-gw-pill <?= !empty($payCfg['robokassa']['enabled']) ? 'is-on' : '' ?>">
        Робокасса: <?= !empty($payCfg['robokassa']['enabled']) ? 'подключена ✓' : 'ожидает ключи (.payment_secret.php)' ?>
      </span>
      <span class="adm-gw-pill is-on">Почта заявок: hr.retrit@yandex.ru (SMTP ✓)</span>
    </div>

    <div class="adm-kpi-grid">
      <div class="adm-kpi"><span>Всего заявок</span><strong><?= (int)$stats['total'] ?></strong></div>
      <div class="adm-kpi"><span>Физлица (B2C)</span><strong><?= (int)$stats['b2c'] ?></strong></div>
      <div class="adm-kpi"><span>Компании (B2B)</span><strong><?= (int)$stats['b2b'] ?></strong></div>
      <div class="adm-kpi"><span>Оплачено заявок</span><strong><?= (int)$stats['paidCount'] ?></strong></div>
      <div class="adm-kpi"><span>Оплачено на сумму</span><strong><?= number_format($stats['paidSum'], 0, ',', ' ') ?> ₽</strong></div>
    </div>

    <form class="adm-filters" method="GET" action="./">
      <input type="search" name="q" value="<?= $h($searchQuery) ?>" placeholder="Поиск по имени, номеру HR-..., телефону, почте, компании или UTM">
      <select name="mode">
        <option value="all" <?= $filterMode === 'all' ? 'selected' : '' ?>>Все форматы (B2C + B2B)</option>
        <option value="b2c" <?= $filterMode === 'b2c' ? 'selected' : '' ?>>Только физлица (B2C)</option>
        <option value="b2b" <?= $filterMode === 'b2b' ? 'selected' : '' ?>>Только компании (B2B)</option>
      </select>
      <select name="pay">
        <option value="all" <?= $filterPay === 'all' ? 'selected' : '' ?>>Все статусы оплаты</option>
        <option value="paid" <?= $filterPay === 'paid' ? 'selected' : '' ?>>Только оплаченные</option>
        <option value="pending" <?= $filterPay === 'pending' ? 'selected' : '' ?>>Ожидают оплаты</option>
      </select>
      <button class="btn btn-teal btn-sm" type="submit">Показать</button>
      <?php if ($searchQuery !== '' || $filterMode !== 'all' || $filterPay !== 'all'): ?>
        <a class="btn btn-outline btn-sm" href="./">Сбросить</a>
      <?php endif; ?>
    </form>

    <div class="adm-table-wrap">
      <?php if (empty($rows)): ?>
        <div class="adm-empty">Заявок по выбранным фильтрам пока нет.</div>
      <?php else: ?>
        <table class="adm-table">
          <thead>
            <tr>
              <th>Заявка / Дата</th>
              <th>Участник и контакты</th>
              <th>Формат / Компания</th>
              <th>Сумма и оплата</th>
              <th>Согласия и UTM</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r):
            $lid = (string)($r['leadId'] ?? '—');
            $st = $r['_payStatus'];
            $prov = $r['_payProvider'];
            $utm = is_array($r['utm'] ?? null) ? $r['utm'] : [];
            $tsFormatted = '';
            try {
                $dt = new DateTimeImmutable((string)($r['ts'] ?? 'now'));
                $tsFormatted = $dt->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');
            } catch (Throwable $e) {
                $tsFormatted = (string)($r['ts'] ?? '');
            }
          ?>
            <tr>
              <td>
                <strong><?= $h($lid) ?></strong><br>
                <span style="color:var(--ink-muted);font-size:12px;"><?= $h($tsFormatted) ?> МСК</span>
              </td>
              <td>
                <strong><?= $h($r['name'] ?? '') ?></strong><br>
                <span><?= $h($r['contact'] ?? '') ?></span>
                <?php if (!empty($r['email'])): ?><br><span style="color:var(--ink-muted);"><?= $h($r['email']) ?></span><?php endif; ?>
                <?php if (!empty($r['comment'])): ?><div style="margin-top:6px;font-size:12.5px;color:var(--ink-muted);">💬 <?= $h($r['comment']) ?></div><?php endif; ?>
              </td>
              <td>
                <span class="badge <?= ($r['mode'] ?? '') === 'b2b' ? 'badge-b2b' : 'badge-b2c' ?>">
                  <?= ($r['mode'] ?? '') === 'b2b' ? 'B2B (счёт)' : 'B2C (личный)' ?>
                </span>
                <?php if (($r['mode'] ?? '') === 'b2b' && !empty($r['company']) && $r['company'] !== '—'): ?>
                  <div style="margin-top:6px;font-size:12.5px;"><?= $h($r['company']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <strong><?= number_format((int)$r['_payAmount'], 0, ',', ' ') ?> ₽</strong><br>
                <?php if ($st === 'paid'): ?>
                  <span class="badge badge-paid">Оплачено<?= $prov !== '' ? ' · ' . $h($prov) : '' ?></span>
                <?php elseif ($st === 'canceled'): ?>
                  <span class="badge badge-cancel">Отменено</span>
                <?php else: ?>
                  <span class="badge badge-wait">Ожидает<?= $prov !== '' ? ' · ' . $h($prov) : '' ?></span>
                  <?php if (preg_match('/^HR-\d{8}-[A-F0-9]{6}$/', $lid)): ?>
                    <form method="POST" action="./" style="margin-top:6px;" onsubmit="return confirm('Подтвердить поступление оплаты для <?= $h($lid) ?>?');">
                      <input type="hidden" name="action" value="mark_b2b_paid">
                      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                      <input type="hidden" name="lead_id" value="<?= $h($lid) ?>">
                      <button type="submit" class="btn btn-outline btn-sm" style="padding:4px 9px;font-size:11.5px;">Отметить оплату</button>
                    </form>
                  <?php endif; ?>
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
  </main>
</body>
</html>
