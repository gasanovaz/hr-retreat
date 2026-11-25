<?php
/**
 * Защищённая онлайн-таблица листа ожидания HR-ретрита (152-ФЗ РФ, сервер Timeweb).
 * Доступ открывается по личному паролю маркетолога (api/.waitlist_secret.php) или мастер-паролю администратора (api/.admin_secret.php).
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
  <title>Вход в таблицу листа ожидания · Школа Елены Витчак</title>
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
      <h1>Таблица листа ожидания</h1>
      <p class="wl-auth-sub">Закрытый доступ к анкетам участников листа ожидания HR-ретрита и выгрузке в Excel.</p>
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $csrfOk = hash_equals((string)$_SESSION['wl_csrf'], (string)($_POST['csrf'] ?? ''));

    if ($csrfOk && $action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: ./');
        exit;
    }

    if ($csrfOk && $action === 'export_csv') {
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
        header('Content-Disposition: attachment; filename="hr-retreat-waitlist-' . ev_msk_now()->format('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'wb');
        fputcsv($out, [
            'Номер записи', 'Дата (МСК)', 'ФИО', 'Почта', 'Телефон', 'Ник в Telegram',
            'Почему хотите на HR-ретрит?', 'Согласие ПДн (152-ФЗ)', 'Публичная оферта', 'Рассылка (38-ФЗ)',
            'Ред. документов', 'UTM / Метки'
        ], ';');

        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $r = $entries[$i];
            $tsFormatted = '';
            try {
                $dt = new DateTimeImmutable((string)($r['ts'] ?? 'now'));
                $tsFormatted = $dt->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');
            } catch (Throwable $e) {
                $tsFormatted = (string)($r['ts'] ?? '');
            }
            fputcsv($out, [
                $safeCsvCell($r['waitlistId'] ?? ''),
                $safeCsvCell($tsFormatted),
                $safeCsvCell($r['name'] ?? ''),
                $safeCsvCell($r['email'] ?? ''),
                $safeCsvCell($r['phone'] ?? ''),
                $safeCsvCell($r['telegram'] ?? ''),
                $safeCsvCell($r['motivation'] ?? ''),
                !empty($r['pdnConsent']) ? 'Да' : 'Нет',
                !empty($r['offerConsent']) ? 'Да' : 'Нет',
                !empty($r['marketingConsent']) ? 'Да' : 'Нет',
                $safeCsvCell($r['consentVersion'] ?? ''),
                $safeCsvCell(!empty($r['utm']) ? json_encode($r['utm'], JSON_UNESCAPED_UNICODE) : ''),
            ], ';');
        }
        fclose($out);
        exit;
    }
}

$filterPromo = (string)($_GET['promo'] ?? 'all');
$searchQuery = trim((string)($_GET['q'] ?? ''));

$todayMsk = ev_msk_now()->format('Y-m-d');
$stats = ['total' => 0, 'today' => 0, 'promoYes' => 0, 'withUtm' => 0];
$rows = [];

$lowerFn = static fn (string $s): string => function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
$strposFn = static fn (string $hay, string $needle): bool => function_exists('mb_strpos') ? (mb_strpos($hay, $needle) !== false) : (strpos($hay, $needle) !== false);

for ($i = count($entries) - 1; $i >= 0; $i--) {
    $r = $entries[$i];
    $stats['total']++;

    $promoYes = !empty($r['marketingConsent']);
    if ($promoYes) {
        $stats['promoYes']++;
    }
    $utm = is_array($r['utm'] ?? null) ? $r['utm'] : [];
    if ($utm !== []) {
        $stats['withUtm']++;
    }

    $tsFormatted = '';
    $rowDayMsk = '';
    try {
        $dt = new DateTimeImmutable((string)($r['ts'] ?? 'now'));
        $dtMsk = $dt->setTimezone(new DateTimeZone('Europe/Moscow'));
        $tsFormatted = $dtMsk->format('d.m.Y H:i');
        $rowDayMsk = $dtMsk->format('Y-m-d');
    } catch (Throwable $e) {
        $tsFormatted = (string)($r['ts'] ?? '');
    }
    if ($rowDayMsk === $todayMsk) {
        $stats['today']++;
    }

    if ($filterPromo === 'yes' && !$promoYes) continue;
    if ($filterPromo === 'no' && $promoYes) continue;

    if ($searchQuery !== '') {
        $haystack = $lowerFn(implode(' ', [
            (string)($r['waitlistId'] ?? ''),
            (string)($r['name'] ?? ''),
            (string)($r['email'] ?? ''),
            (string)($r['phone'] ?? ''),
            (string)($r['telegram'] ?? ''),
            (string)($r['motivation'] ?? ''),
            json_encode($utm, JSON_UNESCAPED_UNICODE),
        ]));
        if (!$strposFn($haystack, $lowerFn($searchQuery))) {
            continue;
        }
    }

    $r['_tsFormatted'] = $tsFormatted;
    $r['_utm'] = $utm;
    $rows[] = $r;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <meta name="theme-color" content="#0e342e">
  <title>Лист ожидания · Таблица заявок · evitchak.ru</title>
  <link rel="stylesheet" href="../styles.css?v=12">
  <link rel="stylesheet" href="../deep-theme.css?v=5">
  <style>
    .wl-shell { padding: 30px 0 64px; }
    .wl-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 22px; }
    .wl-title h1 { font-size: clamp(24px, 3vw, 31px); color: var(--teal-dark); margin: 0 0 4px; }
    .wl-title p { font-size: 14px; color: var(--ink-muted); margin: 0; }
    .wl-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .wl-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }
    .wl-kpi { background: var(--surface); border: 1px solid var(--line); border-radius: 14px; padding: 15px 18px; }
    .wl-kpi span { display: block; font-size: 12.5px; color: var(--ink-muted); margin-bottom: 5px; }
    .wl-kpi strong { font-size: 26px; color: var(--teal-dark); font-variant-numeric: tabular-nums; }
    .wl-filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; background: var(--surface); border: 1px solid var(--line); border-radius: 14px; padding: 14px 16px; margin-bottom: 18px; }
    .wl-filters input[type="search"] { flex: 1 1 260px; padding: 9px 12px; border: 1px solid var(--line-strong); border-radius: 10px; font-family: inherit; font-size: 14px; background: var(--bg-soft); }
    .wl-filters select { padding: 9px 12px; border: 1px solid var(--line-strong); border-radius: 10px; font-family: inherit; font-size: 14px; background: var(--bg-soft); }
    .wl-table-wrap { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; overflow-x: auto; }
    .wl-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .wl-table th { text-align: left; padding: 12px 14px; background: var(--bg-soft); color: var(--teal-dark); font-weight: 700; border-bottom: 1px solid var(--line); white-space: nowrap; }
    .wl-table td { padding: 12px 14px; border-bottom: 1px solid var(--line); vertical-align: top; }
    .wl-table tr:last-child td { border-bottom: none; }
    .wl-badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
    .wl-badge-yes { background: #e3f5ee; color: #0f5c43; }
    .wl-badge-no { background: #f2f1ed; color: #5f6966; }
    .wl-empty { padding: 40px 20px; text-align: center; color: var(--ink-muted); font-size: 15px; }
    .wl-motivation { max-width: 340px; white-space: pre-wrap; line-height: 1.45; color: #263431; }
    .wl-copy-msg { font-size: 13px; color: #0f5c43; font-weight: 600; display: none; }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="wrap header-inner">
      <a class="brand" href="../waitlist/">
        <span class="brand-title">Школа Елены Витчак</span>
        <span class="brand-sub">Лист ожидания · Таблица (152-ФЗ РФ)</span>
      </a>
      <div class="wl-actions">
        <a class="btn btn-outline btn-sm" href="../waitlist/" target="_blank" rel="noopener noreferrer" style="color:#ecd09b;border-color:rgba(236,208,155,0.45);">Открыть форму</a>
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
    <div class="wl-top">
      <div class="wl-title">
        <h1>Таблица листа ожидания HR-ретрита</h1>
        <p>Хранение ПДн на сервере в РФ (Timeweb, 152-ФЗ) · Время московское (МСК) · Обновляется при обновлении страницы</p>
      </div>
      <div class="wl-actions">
        <button class="btn btn-outline btn-sm" type="button" id="wlCopyTableBtn">Скопировать для Excel / Sheets</button>
        <span class="wl-copy-msg" id="wlCopyMsg">Скопировано в буфер ✓</span>
        <form method="POST" action="./" style="margin:0;">
          <input type="hidden" name="action" value="export_csv">
          <input type="hidden" name="csrf" value="<?= $h($_SESSION['wl_csrf']) ?>">
          <button class="btn btn-teal btn-sm" type="submit">Скачать Excel (CSV)</button>
        </form>
      </div>
    </div>

    <div class="wl-kpi-grid">
      <div class="wl-kpi"><span>Всего в листе ожидания</span><strong><?= (int)$stats['total'] ?></strong></div>
      <div class="wl-kpi"><span>Добавились сегодня</span><strong><?= (int)$stats['today'] ?></strong></div>
      <div class="wl-kpi"><span>Согласны на рассылку (38-ФЗ)</span><strong><?= (int)$stats['promoYes'] ?></strong></div>
      <div class="wl-kpi"><span>Переходы с UTM-метками</span><strong><?= (int)$stats['withUtm'] ?></strong></div>
    </div>

    <form class="wl-filters" method="GET" action="./">
      <input type="search" name="q" value="<?= $h($searchQuery) ?>" placeholder="Поиск по ФИО, почте, телефону, Telegram, запросу или UTM">
      <select name="promo">
        <option value="all" <?= $filterPromo === 'all' ? 'selected' : '' ?>>Все согласия на рассылку</option>
        <option value="yes" <?= $filterPromo === 'yes' ? 'selected' : '' ?>>Рассылка 38-ФЗ: Да</option>
        <option value="no" <?= $filterPromo === 'no' ? 'selected' : '' ?>>Рассылка 38-ФЗ: Нет</option>
      </select>
      <button class="btn btn-teal btn-sm" type="submit">Найти</button>
      <?php if ($searchQuery !== '' || $filterPromo !== 'all'): ?>
        <a class="btn btn-outline btn-sm" href="./">Сбросить</a>
      <?php endif; ?>
    </form>

    <div class="wl-table-wrap">
      <?php if (empty($rows)): ?>
        <div class="wl-empty">Записей в листе ожидания по выбранным параметрам пока нет.</div>
      <?php else: ?>
        <table class="wl-table" id="wlDataTable">
          <thead>
            <tr>
              <th>№ / Дата (МСК)</th>
              <th>ФИО</th>
              <th>Почта и телефон</th>
              <th>Telegram</th>
              <th>Почему хотите на HR-ретрит?</th>
              <th>Согласия (152-ФЗ / 38-ФЗ)</th>
              <th>UTM-метки</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r):
            $wlId = (string)($r['waitlistId'] ?? '—');
            $tg = (string)($r['telegram'] ?? '');
            $tgSlug = ltrim($tg, '@');
            $email = (string)($r['email'] ?? '');
            $phone = (string)($r['phone'] ?? '');
            $phoneHref = preg_replace('/[^\d+]/', '', $phone) ?? '';
            $utm = $r['_utm'];
          ?>
            <tr
              data-tsv-id="<?= $h($wlId) ?>"
              data-tsv-date="<?= $h($r['_tsFormatted']) ?>"
              data-tsv-name="<?= $h($r['name'] ?? '') ?>"
              data-tsv-email="<?= $h($email) ?>"
              data-tsv-phone="<?= $h($phone) ?>"
              data-tsv-tg="<?= $h($tg) ?>"
              data-tsv-mot="<?= $h(str_replace(["\r", "\n", "\t"], ' ', (string)($r['motivation'] ?? ''))) ?>"
              data-tsv-promo="<?= !empty($r['marketingConsent']) ? 'Да' : 'Нет' ?>"
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
  </main>

  <script>
    (() => {
      const btn = document.getElementById('wlCopyTableBtn');
      const msg = document.getElementById('wlCopyMsg');
      const tbl = document.getElementById('wlDataTable');
      if (!btn || !tbl) return;

      btn.addEventListener('click', async () => {
        const header = ['Номер', 'Дата (МСК)', 'ФИО', 'Почта', 'Телефон', 'Telegram', 'Почему хотите на HR-ретрит?', 'Рассылка 38-ФЗ'];
        const lines = [header.join('\t')];
        tbl.querySelectorAll('tbody tr').forEach((tr) => {
          lines.push([
            tr.dataset.tsvId || '',
            tr.dataset.tsvDate || '',
            tr.dataset.tsvName || '',
            tr.dataset.tsvEmail || '',
            tr.dataset.tsvPhone || '',
            tr.dataset.tsvTg || '',
            tr.dataset.tsvMot || '',
            tr.dataset.tsvPromo || '',
          ].join('\t'));
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
