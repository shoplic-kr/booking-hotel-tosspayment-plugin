<?php
namespace MPHB\TossPaymentsCard;

if (!defined('ABSPATH')) {
    exit;
}

class TossPaymentsUtils {

    /**
     * Generates a unique order ID for Toss Payments, embedding MPHB Booking ID.
     * Format: mphb_{booking_id}_{timestamp}
     *
     * @param int $bookingId MPHB Booking ID.
     * @return string Unique Toss Order ID.
     */
    public static function generateTossOrderId(int $bookingId): string {
        // Use time() for uniqueness in case of multiple payment attempts for the same booking
        return sprintf('mphb_%d_%d', $bookingId, time());
    }

    /**
     * Extracts the MPHB Booking ID from a Toss Order ID.
     * Assumes format: mphb_{booking_id}_{timestamp}
     *
     * @param string $tossOrderId The Order ID from Toss.
     * @return int MPHB Booking ID, or 0 if format is wrong.
     */
    public static function extractBookingIdFromTossOrderId(string $tossOrderId): int {
        if (preg_match('/^mphb_(\d+)_(\d+)$/', $tossOrderId, $matches)) {
            return absint($matches[1]); // Return the booking ID part
        }
        return 0;
    }

    /**
     * Generates the callback URL for Toss Payments.
     *
     * @param string $gatewayId The gateway ID.
     * @param string $type 'success' or 'fail'.
     * @param string $tossOrderId The unique Toss Order ID.
     * @return string The full callback URL.
     */
    public static function generateCallbackUrl(string $gatewayId, string $type, string $tossOrderId): string {
        // Use home_url() with 'index.php' and add query args for broader compatibility
        // Similar to AbstractNotificationListener::getNotifyUrl but with custom params
        $baseUrl = home_url('index.php');

        $args = [
            // Use a unique query var that MPHB Listener hooks into (matches initUrlIdentificationValue)
            // MPHB's listener uses 'mphb-listener' by default, mapped to gateway ID internally?
            // Let's use a specific query var for clarity, assuming MPHB checks $_GET[$gatewayId] or similar.
            // Re-checking AbstractNotificationListener: It checks $_GET[$this->urlKey] === $this->urlValue.
            // $this->urlKey defaults to 'mphb-listener', $this->urlValue is from initUrlIdentificationValue().
            'mphb-listener' => $gatewayId, // Use the default key expected by MPHB
            'callback_type' => $type,
            // Pass orderId back for easier retrieval in the listener
            // Although Toss adds it automatically, being explicit doesn't hurt
            'orderId' => $tossOrderId,
        ];

        $notifyUrl = add_query_arg($args, $baseUrl);

        // Force HTTPS if enabled in MPHB or site uses SSL
        if (is_ssl() || (class_exists('\MPHB\Settings\PaymentSettings') && MPHB()->settings()->payment()->isForceCheckoutSSL())) {
            $notifyUrl = preg_replace('|^http://|', 'https://', $notifyUrl);
        }

        return $notifyUrl;
    }

    /**
     * Generate a simple idempotency key (optional, use if needed for API calls).
     * @return string
     */
    public static function generateIdempotencyKey(): string {
        return uniqid('toss_', true);
    }
}
