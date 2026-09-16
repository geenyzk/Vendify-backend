<?php

namespace App\Console\Commands;

use App\Services\AirtimeToCash\AirtimeToCashProviderConfiguration;
use App\Services\AirtimeToCash\ProviderTransport;
use Illuminate\Console\Command;

/**
 * Proves whether the credential Vendify resolves is the one the operator holds,
 * without either value being displayed, stored or logged. Compare the printed
 * fingerprint with `auth_credential_fingerprint` on the airtime_to_cash.provider_call
 * health log line: equal tags mean the resolved key is exactly what went on the wire.
 */
final class AirtimeToCashCredentialFingerprint extends Command
{
    protected $signature = 'airtime-to-cash:credential-fingerprint
        {provider : Provider key, e.g. 2fast}
        {--compare : Prompt (hidden) for a key to compare against the resolved credential}
        {--probe : Send both documented read-only endpoints and report status only (no transaction)}';

    protected $description = 'Show a safe one-way fingerprint of a stored Airtime-to-Cash provider credential.';

    public function handle(AirtimeToCashProviderConfiguration $configuration, ProviderTransport $transport): int
    {
        try {
            $resolved = $configuration->resolved($this->argument('provider'));
        } catch (\DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $raw = (string) ($resolved['token'] ?? '');
        $token = trim($raw);
        if ($token === '') {
            $this->error('No credential is configured for this provider.');

            return self::FAILURE;
        }
        $this->table(['field', 'value'], [
            ['provider', $this->argument('provider')],
            ['base_url', (string) $resolved['base_url']],
            ['credential_source', (string) $resolved['token_source']],
            ['credential_length', (string) strlen($token)],
            ['length_before_trim', (string) strlen($raw)],
            ['credential_fingerprint', ProviderTransport::fingerprint($token)],
            ['looks_like_ciphertext', str_starts_with($token, 'eyJpdiI6') ? 'YES — an encrypted value is being resolved' : 'no'],
            ['has_wrapping_quotes', preg_match('/^["\'].*["\']$/s', $token) ? 'YES — quotes were stored as part of the key' : 'no'],
            ['non_printable_characters', preg_match('/[^\x21-\x7E]/', $token) ? 'YES — contains a space, tab, CR/LF or invisible character' : 'no'],
        ]);
        if ($this->option('compare')) {
            $candidate = trim((string) $this->secret('Paste the API key from the 2FAST dashboard (input is hidden)'));
            if ($candidate === '') {
                $this->error('Nothing pasted; no comparison made.');

                return self::FAILURE;
            }
            $this->line('pasted_fingerprint: '.ProviderTransport::fingerprint($candidate));
            $match = hash_equals(ProviderTransport::fingerprint($token), ProviderTransport::fingerprint($candidate));
            unset($candidate);
            $match
                ? $this->info('MATCH — Vendify resolves exactly the key you pasted.')
                : $this->error('DIFFERENT — Vendify resolves a different credential than the key you pasted.');
        }

        if ($this->option('probe')) {
            $this->probe($transport, (string) $this->argument('provider'));
        }

        return self::SUCCESS;
    }

    /**
     * Both documented read-only endpoints, same credential, status only. Neither
     * starts a conversion nor sends an OTP. Differing statuses mean the credential
     * is fine and the connection test is probing the wrong endpoint for this account.
     */
    private function probe(ProviderTransport $transport, string $provider): void
    {
        $probes = ['/api/data-plans' => ['network' => 1, 'limit' => 1],
            '/api/transaction-history' => ['reference' => 'ATC-PROBE-'.bin2hex(random_bytes(8))]];
        $rows = [];
        foreach ($probes as $path => $payload) {
            try {
                [$status, $body, $request] = $transport->post($provider, $path, $payload, true, true);
            } catch (\DomainException $e) {
                $rows[] = [$path, 'not sent', $e->getMessage(), ''];

                continue;
            }
            $rows[] = [$path, $status === 0 ? 'transport failure' : (string) $status,
                is_string($body['status'] ?? null) ? $body['status'] : '—',
                (string) ($request['auth_credential_fingerprint'] ?? '')];
        }
        $this->newLine();
        $this->table(['endpoint', 'http status', 'body status', 'credential fingerprint'], $rows);
    }
}
