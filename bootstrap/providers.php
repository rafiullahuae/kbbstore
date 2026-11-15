<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\ViewServiceProvider::class,
    // Applies the operator's SMTP settings to the default mailer. See the
    // provider's own comment for why this cannot live in config/mail.php.
    App\Providers\MailServiceProvider::class,
];
