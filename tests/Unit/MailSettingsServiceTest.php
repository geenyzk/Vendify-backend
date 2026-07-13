<?php

use App\Services\MailSettingsService;

test('smtp encryption labels are normalized to Laravel smtp schemes', function () {
    expect(MailSettingsService::normalizeSmtpScheme('tls'))->toBe('smtp')
        ->and(MailSettingsService::normalizeSmtpScheme('starttls'))->toBe('smtp')
        ->and(MailSettingsService::normalizeSmtpScheme('smtp'))->toBe('smtp')
        ->and(MailSettingsService::normalizeSmtpScheme('ssl'))->toBe('smtps')
        ->and(MailSettingsService::normalizeSmtpScheme('smtps'))->toBe('smtps')
        ->and(MailSettingsService::normalizeSmtpScheme('none'))->toBeNull()
        ->and(MailSettingsService::normalizeSmtpScheme(null))->toBeNull();
});

test('sender domain can be used for smtp identity', function () {
    putenv('MAIL_FROM_ADDRESS=no-reply@vendify.com.ng');
    putenv('MAIL_EHLO_DOMAIN');

    MailSettingsService::clearCache();

    expect(MailSettingsService::getSenderDomain())->toBe('vendify.com.ng')
        ->and(MailSettingsService::getLocalDomain())->toBe('vendify.com.ng');
});
