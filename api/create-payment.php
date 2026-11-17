<?php
/**
 * Серверное создание платежа в ЮKassa (API v3) или Робокассе с формированием чека 54-ФЗ.
 * Сумма всегда вычисляется на сервере и привязывается к существующей заявке (leadId).
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

$leadId = ev_clean_line($data['leadId'] ?? '');
$provider = strtolower(ev_clean_line($data['provider'] ?? ''));

if (!preg_match('/^HR-\d{8}-[A-F0-9]{6}$/', $leadId)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Некорректный номер заявки'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($provider !== 'yookassa' && $provider !== 'robokassa') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Выберите платёжный сервис'], JSON_UNESCAPED_UNICODE);
    exit;
}

$lead = ev_find_lead($leadId);
if ($lead === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Заявка не найдена'], JSON_UNESCAPED_UNICODE);
    exit;
}

$state = ev_payment_state_map();
$leadPayState = $state['byLead'][$leadId] ?? null;
if (($leadPayState['status'] ?? '') === 'paid') {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Эта заявка уже оплачена'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payCfg = ev_load_payment_config();
if (empty($payCfg[$provider]['enabled'])) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Онлайн-оплата через выбранный шлюз пока не активирована'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mskNow = ev_msk_now();
$amountRub = ev_active_price_rub($mskNow);
$amountStr = number_format((float)$amountRub, 2, '.', '');

$emailOverride = ev_clean_line($data['email'] ?? '');
$emailValid = filter_var($emailOverride, FILTER_VALIDATE_EMAIL)
    ? $emailOverride
    : (filter_var((string)($lead['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string)$lead['email'] : '');
$phoneDigits = ev_normalize_ru_phone((string)($lead['contact'] ?? ''));

$itemDescription = 'Информационно-консультационные услуги: HR-ретрит (4-й запуск)';

if ($provider === 'yookassa') {
    if ($emailValid === '' && $phoneDigits === '') {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'needEmail' => true,
            'error' => 'Для отправки электронного чека по 54-ФЗ укажите корректный e-mail.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $yk = $payCfg['yookassa'];
    $customer = [];
    if ($emailValid !== '') {
        $customer['email'] = $emailValid;
    }
    if ($phoneDigits !== '') {
        $customer['phone'] = $phoneDigits;
    }

    $receipt = [
        'customer' => $customer,
        'items' => [[
            'description' => $itemDescription,
            'quantity' => '1.00',
            'amount' => [
                'value' => $amountStr,
                'currency' => 'RUB',
            ],
            'vat_code' => 1, // Без НДС (УСН)
            'payment_mode' => 'full_payment',
            'payment_subject' => 'service',
        ]],
    ];
    if ((int)($yk['taxSystemCode'] ?? 0) > 0) {
        $receipt['tax_system_code'] = (int)$yk['taxSystemCode'];
    }

    $payload = [
        'amount' => [
            'value' => $amountStr,
            'currency' => 'RUB',
        ],
        'capture' => true,
        'confirmation' => [
            'type' => 'redirect',
            'return_url' => 'https://evitchak.ru/thanks.html?lead=' . rawurlencode($leadId),
        ],
        'description' => "Участие в программе HR-ретрит ({$leadId})",
        'metadata' => [
            'lead_id' => $leadId,
            'payer_type' => (string)($lead['mode'] ?? 'b2c'),
        ],
        'receipt' => $receipt,
    ];

    $idempotenceKey = bin2hex(random_bytes(16));
    $resp = ev_http_json(
        'https://api.yookassa.ru/v3/payments',
        'POST',
        [
            'Content-Type: application/json',
            'Idempotence-Key: ' . $idempotenceKey,
        ],
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $yk['shopId'] . ':' . $yk['secretKey']
    );

    $confirmationUrl = (string)($resp['json']['confirmation']['confirmation_url'] ?? '');
    $ykPaymentId = (string)($resp['json']['id'] ?? '');
    if ($resp['status'] < 200 || $resp['status'] >= 300 || $confirmationUrl === '' || $ykPaymentId === '') {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'Не удалось сформировать платёж в ЮKassa. Попробуйте снова или свяжитесь с куратором.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    ev_append_jsonl('hr_retreat_payments.jsonl', [
        'ts' => $mskNow->format(DATE_ATOM),
        'leadId' => $leadId,
        'provider' => 'yookassa',
        'status' => 'created',
        'yookassaPaymentId' => $ykPaymentId,
        'amountRub' => $amountRub,
        'email' => $emailValid,
    ]);

    echo json_encode([
        'ok' => true,
        'provider' => 'yookassa',
        'leadId' => $leadId,
        'paymentId' => $ykPaymentId,
        'confirmationUrl' => $confirmationUrl,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Робокасса (MerchantLogin + 54-ФЗ Receipt / Робочеки + SHA256 подпись)
$rk = $payCfg['robokassa'];
$sendReceipt = !empty($rk['sendReceipt']);
if ($sendReceipt && $emailValid === '') {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'needEmail' => true,
        'error' => 'Для выдачи фискального чека Робокассой по 54-ФЗ укажите ваш e-mail.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$invId = 0;
for ($attempt = 0; $attempt < 15; $attempt++) {
    $candidate = random_int(100000, 2147483000);
    if (!isset($state['byRobokassaInv'][$candidate])) {
        $invId = $candidate;
        break;
    }
}
if ($invId <= 0) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось выделить номер счёта'], JSON_UNESCAPED_UNICODE);
    exit;
}

$algo = strtolower((string)($rk['hashAlgo'] ?? 'sha256'));
if (!in_array($algo, ['sha256', 'md5', 'sha512'], true)) {
    $algo = 'sha256';
}

$receiptJson = '';
if ($sendReceipt) {
    $receiptObj = [
        'sno' => (string)($rk['sno'] ?: 'usn_income'),
        'items' => [[
            'name' => $itemDescription,
            'quantity' => 1,
            'sum' => (float)$amountRub,
            'payment_method' => 'full_payment',
            'payment_object' => 'service',
            'tax' => 'none',
        ]],
    ];
    $receiptJson = json_encode($receiptObj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
}

$signParts = [
    (string)$rk['merchantLogin'],
    $amountStr,
    (string)$invId,
];
if ($receiptJson !== '') {
    $signParts[] = $receiptJson;
}
$signParts[] = (string)$rk['password1'];
$signParts[] = 'Shp_lead=' . $leadId;

$signature = hash($algo, implode(':', $signParts));

$queryParams = [
    'MerchantLogin' => (string)$rk['merchantLogin'],
    'OutSum' => $amountStr,
    'InvId' => $invId,
    'Description' => "Участие в программе HR-ретрит ({$leadId})",
    'SignatureValue' => $signature,
    'Culture' => 'ru',
    'Shp_lead' => $leadId,
];
if ($emailValid !== '') {
    $queryParams['Email'] = $emailValid;
}
if ($receiptJson !== '') {
    $queryParams['Receipt'] = $receiptJson;
}
if (!empty($rk['testMode'])) {
    $queryParams['IsTest'] = 1;
}

ev_append_jsonl('hr_retreat_payments.jsonl', [
    'ts' => $mskNow->format(DATE_ATOM),
    'leadId' => $leadId,
    'provider' => 'robokassa',
    'status' => 'created',
    'robokassaInvId' => $invId,
    'amountRub' => $amountRub,
    'email' => $emailValid,
]);

$payUrl = 'https://auth.robokassa.ru/Merchant/Index.aspx?' . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
echo json_encode([
    'ok' => true,
    'provider' => 'robokassa',
    'leadId' => $leadId,
    'invId' => $invId,
    'confirmationUrl' => $payUrl,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
