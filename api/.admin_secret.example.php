<?php
/**
 * Шаблон закрытого файла настроек доступа в кабинет заявок (/admin/).
 * Рабочий файл api/.admin_secret.php добавлен в .gitignore и защищён api/.htaccess (403).
 * В поле passwordHash хранится bcrypt ($2y$...) либо pbkdf2_sha256$210000$salt$hash.
 */
return [
    'passwordHash' => '',
];
