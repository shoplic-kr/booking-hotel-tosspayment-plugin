<?php
namespace MPHB\TossPaymentsCard;

use WP_Error;
use MPHB\TossPaymentsCard\TossPaymentsException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles communication with the Toss Payments API.
 */
class TossPaymentsAPI {

    const API_BASE_URL = 'https://api.tosspayments.com/v1/';

    private string $secretKey;
    private bool $isDebug;

    public function __construct(string $secretKey, bool $isDebug = false) {
        $this->secretKey = $secretKey;
        $this->isDebug = $isDebug;
    }

    /**
     * Make a request to the Toss Payments API.
     *
     * @param string $endpoint API endpoint (e.g., 'payments/confirm').
     * @param array  $body Request body data.
     * @param string $method HTTP method (POST, GET, etc.).
     * @return object|null API response object or null on failure.
     * @throws TossPaymentsException On API communication errors or non-JSON response.
     */
    private function request(string $endpoint, array $body = [], string $method = 'POST'): ?object {
        $url = self::API_BASE_URL . $endpoint;
        $credentials = base64_encode($this->secretKey . ':');
        $headers = [
            'Authorization' => "Basic {$credentials}",
            'Content-Type'  => 'application/json',
            // Idempotency-Key might be needed for confirms/cancels if retries are possible
            // 'Idempotency-Key' => TossPaymentsUtils::generateIdempotencyKey(),
        ];

        $args = [
            'method'    => strtoupper($method),
            'headers'   => $headers,
            'timeout'   => 60,
            'sslverify' => true, // Should be true in production
            'body'      => ($method === 'POST' || $method === 'PUT') ? wp_json_encode($body) : null,
        ];

        if ($this->isDebug) {
            MPHB()->log()->debug(sprintf('[%s] API Request URL: %s', __CLASS__, $url));
            MPHB()->log()->debug(sprintf('[%s] API Request Args: %s', __CLASS__, print_r(array_merge($args, ['headers' => ['Authorization' => 'Basic ***']]), true))); // Mask key in log
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            MPHB()->log()->error(sprintf('[%s] API WP_Error: %s', __CLASS__, $error_message));
            throw new TossPaymentsException("API Request Failed: " . $error_message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($this->isDebug) {
            MPHB()->log()->debug(sprintf('[%s] API Response Code: %s', __CLASS__, $response_code));
            MPHB()->log()->debug(sprintf('[%s] API Response Body: %s', __CLASS__, $response_body));
        }

        $decoded = json_decode($response_body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            MPHB()->log()->error(sprintf('[%s] API JSON Decode Error: %s', __CLASS__, json_last_error_msg()));
            MPHB()->log()->error(sprintf('[%s] API Raw Response: %s', __CLASS__, $response_body));
            throw new TossPaymentsException("Failed to decode API response.");
        }

         // Check for Toss Payments specific error structure in the response body
        if ($response_code >= 400 || isset($decoded->code)) {
             $error_code = $decoded->code ?? 'UNKNOWN_ERROR';
             $error_message = $decoded->message ?? 'An unknown API error occurred.';
             MPHB()->log()->error(sprintf('[%s] API Error Response: Code=%s, Message=%s', __CLASS__, $error_code, $error_message));
             // Optionally throw, or return the decoded error object for handling
             // throw new TossPaymentsException("API Error [{$error_code}]: {$error_message}", $error_code);
             // Returning the object allows the caller to inspect the error details
        }


        return $decoded;
    }

    /**
     * Confirm a payment.
     *
     * @param string $paymentKey The payment key received from Toss frontend.
     * @param string $tossOrderId The unique order ID sent to Toss.
     * @param float  $amount The amount to confirm.
     * @return object|null The confirmation response object.
     * @throws TossPaymentsException
     */
    public function confirmPayment(string $paymentKey, string $tossOrderId, float $amount): ?object {
        $endpoint = 'payments/confirm';
        $body = [
            'paymentKey' => $paymentKey,
            'orderId'    => $tossOrderId,
            'amount'     => round($amount), // Ensure amount is integer for KRW
        ];
        return $this->request($endpoint, $body, 'POST');
    }

    /**
     * Cancel a payment (full or partial).
     *
     * @param string $paymentKey The original payment key.
     * @param string $reason Cancellation reason.
     * @param float|null $amount Amount to cancel (null for full cancellation).
     * @return object|null The cancellation response object.
     * @throws TossPaymentsException
     */
    public function cancelPayment(string $paymentKey, string $reason, ?float $amount = null): ?object {
        $endpoint = "payments/{$paymentKey}/cancel";
        $body = [
            'cancelReason' => mb_substr($reason, 0, 200), // Max 200 chars for reason
        ];
        if ($amount !== null && $amount > 0) {
            $body['cancelAmount'] = round($amount); // Partial cancellation
        }
        // If $amount is null or 0, Toss API treats it as full cancellation implicitly

        return $this->request($endpoint, $body, 'POST');
    }
}
