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
