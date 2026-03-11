<?php

return [
    'driver' => getenv('MAIL_DRIVER') ?: 'smtp',
    'host' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
    'username' => getenv('SMTP_USERNAME') ?: 'kenshin.enteroruiz@gmail.com',
    'password' => getenv('SMTP_PASSWORD') ?: 'omqefcszgnekacpo',
    'port' => (int) (getenv('SMTP_PORT') ?: 587),
    'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
    'from_email' => getenv('MAIL_FROM_EMAIL') ?: 'kenshin.enteroruiz@gmail.com',
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'NBI Clearance Portal',
    'log_dir' => __DIR__ . '/mail_logs'
];
