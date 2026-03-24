<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Services;

use App\PaymentsCore\Domain\ValueObjects\NormalizedWebhookData;

final class ProviderWebhookMapper
{
    public function normalizeCashIn(string $providerCode, array $payload): NormalizedWebhookData
    {
        return match (strtolower($providerCode)) {
            'firebank' => $this->firebank($payload),
            'woovi' => $this->woovi($payload),
            'xflowpayments' => $this->xflowpayments($payload),
            'liberpay' => $this->liberpay($payload),
            'medusa' => $this->medusa($payload),
            default => new NormalizedWebhookData(rawPayload: $payload),
        };
    }

    public function normalizeCashOut(string $providerCode, array $payload): NormalizedWebhookData
    {
        return match (strtolower($providerCode)) {
            'firebank' => new NormalizedWebhookData(
                providerTransactionId: isset($payload['transactionId']) ? (string) $payload['transactionId'] : null,
                endToEndId: $payload['endToEndId'] ?? null,
                errorCode: $payload['errorCode'] ?? null,
                errorMessage: $payload['errorMessage'] ?? null,
                rawPayload: $payload,
            ),
            'woovi' => new NormalizedWebhookData(
                endToEndId: ($payload['payment'] ?? [])['endToEndId'] ?? null,
                rawPayload: $payload,
            ),
            'xflowpayments' => new NormalizedWebhookData(
                endToEndId: $payload['endToEndId'] ?? null,
                errorCode: $payload['errorCode'] ?? null,
                errorMessage: $payload['errorMessage'] ?? null,
                rawPayload: $payload,
            ),
            'liberpay' => new NormalizedWebhookData(rawPayload: $payload),
            'medusa' => new NormalizedWebhookData(
                endToEndId: $payload['end2EndId'] ?? null,
                errorCode: $payload['status'] ?? null,
                errorMessage: $payload['message'] ?? null,
                rawPayload: $payload,
            ),
            default => new NormalizedWebhookData(rawPayload: $payload),
        };
    }

    private function firebank(array $payload): NormalizedWebhookData
    {
        $counterpart = $payload['counterpart'] ?? [];

        return new NormalizedWebhookData(
            providerTransactionId: $payload['transactionId'] ?? null,
            endToEndId: $payload['endToEndId'] ?? null,
            payerName: $counterpart['name'] ?? null,
            payerDocument: $counterpart['document'] ?? null,
            webhookAmountCents: isset($payload['amount'])
                ? (int) round((float) $payload['amount'] * 100)
                : null,
            rawPayload: $payload,
        );
    }

    private function woovi(array $payload): NormalizedWebhookData
    {
        $charge = $payload['charge'] ?? [];
        $pix = $payload['pix'] ?? [];
        $pixRaw = $pix['raw'] ?? [];

        return new NormalizedWebhookData(
            providerTransactionId: $charge['transactionID'] ?? $pix['transactionID'] ?? null,
            endToEndId: $pixRaw['endToEndId'] ?? null,
            payerName: ($pix['payer'] ?? [])['name'] ?? null,
            webhookAmountCents: isset($charge['value']) ? (int) $charge['value'] : null,
            rawPayload: $payload,
        );
    }

    private function xflowpayments(array $payload): NormalizedWebhookData
    {
        $payer = $payload['payer'] ?? [];

        return new NormalizedWebhookData(
            providerTransactionId: isset($payload['transactionId']) ? (string) $payload['transactionId'] : null,
            endToEndId: $payload['endToEndId'] ?? null,
            payerName: $payer['name'] ?? null,
            payerDocument: $payer['documentId'] ?? null,
            webhookAmountCents: isset($payload['amount']) ? (int) $payload['amount'] : null,
            rawPayload: $payload,
        );
    }

    private function liberpay(array $payload): NormalizedWebhookData
    {
        return new NormalizedWebhookData(
            webhookAmountCents: isset($payload['amount'])
                ? (int) round((float) $payload['amount'] * 100)
                : null,
            rawPayload: $payload,
        );
    }

    private function medusa(array $payload): NormalizedWebhookData
    {
        $pixData = $payload['pix'] ?? [];
        $customer = $payload['customer'] ?? [];
        $document = $customer['document'] ?? [];

        return new NormalizedWebhookData(
            providerTransactionId: isset($payload['id']) ? (string) $payload['id'] : null,
            endToEndId: $pixData['end2EndId'] ?? null,
            payerName: $customer['name'] ?? null,
            payerDocument: $document['number'] ?? null,
            webhookAmountCents: isset($payload['amount']) ? (int) $payload['amount'] : null,
            rawPayload: $payload,
        );
    }
}
