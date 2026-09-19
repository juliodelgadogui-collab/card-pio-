<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;

final class DeliveryCustomerConsentService
{
    public function termsVersion(): string
    {
        return mb_substr(trim((string)env('DELIVERY_TERMS_VERSION','2026-09-19')),0,40) ?: '2026-09-19';
    }

    public function privacyVersion(): string
    {
        return mb_substr(trim((string)env('DELIVERY_PRIVACY_VERSION','2026-09-19')),0,40) ?: '2026-09-19';
    }

    public function assertAccepted(array $payload): void
    {
        $terms=filter_var($payload['terms_accepted']??false,FILTER_VALIDATE_BOOL);
        $privacy=filter_var($payload['privacy_accepted']??false,FILTER_VALIDATE_BOOL);
        if(!$terms||!$privacy)throw new RuntimeException('Aceite os Termos de Uso e a Política de Privacidade para criar sua conta.');
    }

    public function record(PDO $pdo,int $accountId): void
    {
        if($accountId<1)throw new RuntimeException('Conta inválida para registrar os aceites.');
        $pdo->prepare('UPDATE delivery_customer_accounts SET terms_accepted_at=CURRENT_TIMESTAMP,terms_version=?,privacy_accepted_at=CURRENT_TIMESTAMP,privacy_version=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$this->termsVersion(),$this->privacyVersion(),$accountId]);
    }
}
