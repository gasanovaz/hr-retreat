<?php
/**
 * Шаблон закрытого файла секретов платёжных систем (api/.payment_secret.php).
 * Рабочий файл api/.payment_secret.php добавлен в .gitignore и защищён api/.htaccess (403).
 */
return [
    'yookassa' => [
        'shopId' => '',        // ID магазина из личного кабинета ЮKassa
        'secretKey' => '',     // Секретный ключ API ЮKassa (live_... или test_...)
        'taxSystemCode' => 2,  // 2 = УСН Доходы (3 = УСН Доходы минус расходы)
    ],
    'robokassa' => [
        'merchantLogin' => '', // Идентификатор магазина в Робокассе
        'password1' => '',     // Пароль #1 для создания платежа
        'password2' => '',     // Пароль #2 для проверки ResultURL
        'hashAlgo' => 'sha256',// Алгоритм расчёта хеша в настройках магазина (sha256 / md5 / sha512)
        'testMode' => false,   // true только для тестовых оплат Робокассы
        'sno' => 'usn_income', // Система налогообложения (usn_income / usn_income_outcome)
        'sendReceipt' => true, // Передача фискального чека по 54-ФЗ (в т.ч. для Робочеков)
    ],
];
