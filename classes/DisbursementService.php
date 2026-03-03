<?php
/**
 * Disbursement Service
 * Executes outbound loan disbursements to supported channels and logs every attempt.
 */
class DisbursementService {
    private $conn;
    private $txTable = 'disbursement_transactions';
    private $platformTable = 'platform_accounts';
    private $customerTable = 'customer_accounts';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function disburse($data) {
        $applicationId = (int)($data['application_id'] ?? 0);
        $loanId = isset($data['loan_id']) ? (int)$data['loan_id'] : null;
        $userId = (int)($data['user_id'] ?? 0);
        $platformAccountId = (int)($data['platform_account_id'] ?? 0);
        $customerAccountId = (int)($data['customer_account_id'] ?? 0);
        $amount = (float)($data['amount_mwk'] ?? 0);
        $currency = strtoupper(trim((string)($data['currency_code'] ?? 'MWK')));

        if ($applicationId <= 0 || $userId <= 0 || $platformAccountId <= 0 || $customerAccountId <= 0 || $amount <= 0) {
            return ['success' => false, 'message' => 'Invalid disbursement payload'];
        }

        $platform = $this->getPlatformAccount($platformAccountId);
        $customer = $this->getCustomerAccount($customerAccountId, $userId);
        if (!$platform || (int)$platform['is_active'] !== 1) {
            return ['success' => false, 'message' => 'Platform account unavailable'];
        }
        if (!$customer || (int)$customer['is_active'] !== 1) {
            return ['success' => false, 'message' => 'Customer payout account unavailable'];
        }
        if ((float)$platform['current_balance_mwk'] < $amount) {
            return ['success' => false, 'message' => 'Insufficient platform account balance'];
        }

        $channel = strtolower((string)$customer['account_type']);
        $externalReference = $this->generateExternalReference($applicationId, $userId);

        $requestPayload = [
            'amount' => $amount,
            'currency' => $currency,
            'beneficiary_account' => $customer['account_number'],
            'beneficiary_name' => $customer['account_name'],
            'beneficiary_provider' => $customer['account_provider'],
            'external_reference' => $externalReference,
            'channel' => $channel,
        ];

        $gatewayResult = $this->dispatchToGateway($channel, $requestPayload);
        $isSuccess = (bool)($gatewayResult['success'] ?? false);
        $gatewayReference = (string)($gatewayResult['transaction_reference'] ?? '');
        $responseCode = isset($gatewayResult['response_code']) ? (int)$gatewayResult['response_code'] : null;
        $errorMessage = $gatewayResult['message'] ?? null;

        $insert = $this->conn->prepare(
            "INSERT INTO " . $this->txTable . "
            (loan_id, application_id, user_id, platform_account_id, customer_account_id, gateway_channel,
             amount_mwk, currency_code, external_reference, gateway_transaction_reference, status,
             response_code, request_payload_json, response_payload_json, error_message, attempt_count, processed_at)
            VALUES (:loan_id, :application_id, :user_id, :platform_account_id, :customer_account_id, :gateway_channel,
                    :amount_mwk, :currency_code, :external_reference, :gateway_tx_ref, :status,
                    :response_code, :request_payload_json, :response_payload_json, :error_message, 1, NOW())"
        );
        $insert->execute([
            ':loan_id' => $loanId,
            ':application_id' => $applicationId,
            ':user_id' => $userId,
            ':platform_account_id' => $platformAccountId,
            ':customer_account_id' => $customerAccountId,
            ':gateway_channel' => $channel,
            ':amount_mwk' => $amount,
            ':currency_code' => $currency,
            ':external_reference' => $externalReference,
            ':gateway_tx_ref' => ($gatewayReference !== '' ? $gatewayReference : null),
            ':status' => $isSuccess ? 'success' : 'failed',
            ':response_code' => $responseCode,
            ':request_payload_json' => json_encode($requestPayload),
            ':response_payload_json' => json_encode($gatewayResult['raw'] ?? null),
            ':error_message' => (!$isSuccess ? $errorMessage : null),
        ]);

        $txId = (int)$this->conn->lastInsertId();

        if ($isSuccess) {
            $deduct = $this->conn->prepare(
                "UPDATE " . $this->platformTable . "
                 SET current_balance_mwk = current_balance_mwk - :amt
                 WHERE account_id = :id AND current_balance_mwk >= :amt"
            );
            $deduct->execute([
                ':amt' => $amount,
                ':id' => $platformAccountId,
            ]);
            if ($deduct->rowCount() < 1) {
                return ['success' => false, 'message' => 'Disbursement succeeded but balance update failed'];
            }
        }

        return [
            'success' => $isSuccess,
            'transaction_id' => $txId,
            'transaction_reference' => $gatewayReference !== '' ? $gatewayReference : $externalReference,
            'message' => $isSuccess ? 'Disbursement successful' : ($errorMessage ?: 'Disbursement failed'),
        ];
    }

    private function getPlatformAccount($accountId) {
        $stmt = $this->conn->prepare("SELECT * FROM " . $this->platformTable . " WHERE account_id = :id LIMIT 1");
        $stmt->execute([':id' => (int)$accountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getCustomerAccount($accountId, $userId) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM " . $this->customerTable . "
             WHERE account_id = :id AND user_id = :uid
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => (int)$accountId,
            ':uid' => (int)$userId,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function dispatchToGateway($channel, $payload) {
        $mode = strtolower((string)(getenv('DISBURSEMENT_MODE') ?: 'mock'));
        if ($mode !== 'live') {
            return [
                'success' => true,
                'transaction_reference' => 'MOCK-' . strtoupper(substr(sha1($payload['external_reference']), 0, 10)),
                'response_code' => 200,
                'raw' => ['mode' => 'mock', 'channel' => $channel],
                'message' => 'Mock disbursement success',
            ];
        }

        $endpointMap = [
            'airtel_money' => getenv('AIRTEL_MONEY_DISBURSE_URL') ?: '',
            'tnm_mpamba' => getenv('TNM_MPAMBA_DISBURSE_URL') ?: '',
            'sticpay' => getenv('STICPAY_DISBURSE_URL') ?: '',
            'mastercard' => getenv('MASTERCARD_DISBURSE_URL') ?: '',
            'visa' => getenv('VISA_DISBURSE_URL') ?: '',
            'binance' => getenv('BINANCE_DISBURSE_URL') ?: '',
            'bank_transfer' => getenv('BANK_TRANSFER_DISBURSE_URL') ?: '',
            'bank_account' => getenv('BANK_TRANSFER_DISBURSE_URL') ?: '',
            'mobile_money' => getenv('MOBILE_MONEY_DISBURSE_URL') ?: '',
            'wallet' => getenv('WALLET_DISBURSE_URL') ?: '',
            'escrow' => getenv('ESCROW_DISBURSE_URL') ?: '',
        ];
        $tokenMap = [
            'airtel_money' => getenv('AIRTEL_MONEY_API_KEY') ?: '',
            'tnm_mpamba' => getenv('TNM_MPAMBA_API_KEY') ?: '',
            'sticpay' => getenv('STICPAY_API_KEY') ?: '',
            'mastercard' => getenv('MASTERCARD_API_KEY') ?: '',
            'visa' => getenv('VISA_API_KEY') ?: '',
            'binance' => getenv('BINANCE_API_KEY') ?: '',
            'bank_transfer' => getenv('BANK_TRANSFER_API_KEY') ?: '',
            'bank_account' => getenv('BANK_TRANSFER_API_KEY') ?: '',
            'mobile_money' => getenv('MOBILE_MONEY_API_KEY') ?: '',
            'wallet' => getenv('WALLET_API_KEY') ?: '',
            'escrow' => getenv('ESCROW_API_KEY') ?: '',
        ];

        $url = trim((string)($endpointMap[$channel] ?? ''));
        $token = trim((string)($tokenMap[$channel] ?? ''));
        if ($url === '' || $token === '') {
            return ['success' => false, 'message' => 'Missing API endpoint or API key for ' . $channel];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $ch = null;

        if ($raw === false || $curlError) {
            return ['success' => false, 'response_code' => $httpCode ?: null, 'message' => $curlError ?: 'HTTP transport error'];
        }

        $decoded = json_decode((string)$raw, true);
        $ok = ($httpCode >= 200 && $httpCode < 300)
            && (
                ((bool)($decoded['success'] ?? false) === true)
                || in_array(strtolower((string)($decoded['status'] ?? '')), ['success', 'completed', 'ok'], true)
            );

        return [
            'success' => $ok,
            'transaction_reference' => (string)($decoded['transaction_reference'] ?? $decoded['reference'] ?? $decoded['id'] ?? ''),
            'response_code' => $httpCode,
            'raw' => $decoded ?: ['raw' => $raw],
            'message' => $ok ? 'Disbursement successful' : (string)($decoded['message'] ?? 'Gateway rejected disbursement'),
        ];
    }

    private function generateExternalReference($applicationId, $userId) {
        if (function_exists('generate_transaction_reference')) {
            return generate_transaction_reference() . '-D' . $applicationId;
        }
        return 'LML-DISB-' . date('YmdHis') . '-' . $applicationId . '-' . $userId;
    }
}
?>
