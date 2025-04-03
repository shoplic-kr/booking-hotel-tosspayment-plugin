<?php
namespace MPHB\TossPaymentsCard;

use MPHB\Payments\Gateways\AbstractNotificationListener;
use MPHB\Entities\Payment;
use MPHB\PostTypes\PaymentCPT\Statuses as PaymentStatuses;
use MPHB\TossPaymentsCard\TossPaymentsAPI;
use MPHB\TossPaymentsCard\TossPaymentsException;
use MPHB\TossPaymentsCard\TossPaymentsUtils;


if (!defined('ABSPATH')) {
    exit;
}

class TossPaymentsListener extends AbstractNotificationListener {

    /** @var TossPaymentsAPI */
    protected $api;
    /** @var bool */
    protected $isDebug;
    /** @var string */
    protected $tossOrderId = '';
    /** @var string */
    protected $paymentKey = '';
     /** @var float */
    protected $amount = 0;
    /** @var string */
    protected $callbackType = ''; // 'success' or 'fail'
    /** @var string */
    protected $errorCode = '';
     /** @var string */
    protected $errorMessage = '';


    public function __construct($atts) {
        $this->api = $atts['api'];
        $this->isDebug = $atts['debug'] ?? false;
        parent::__construct($atts); // Sets gatewayId, isSandbox, calls initUrlIdentificationValue
    }

    protected function initUrlIdentificationValue(): string {
        // This value must match the 'wc-api' or equivalent parameter in the callback URL
        return TossPaymentsCardGateway::GATEWAY_ID; // Use the gateway ID
    }

    /**
     * Parse input from the GET request from Toss Payments callback.
     * @return array Parsed input data. Empty array if invalid.
     */
    protected function parseInput(): array {
        // Toss Payments callback uses GET parameters
        $input = array_map('sanitize_text_field', wp_unslash($_GET));

        $this->tossOrderId = $input['orderId'] ?? '';
        $this->callbackType = $input['callback_type'] ?? ''; // Our custom param

        if ($this->callbackType === 'success') {
            $this->paymentKey = $input['paymentKey'] ?? '';
            $this->amount = isset($input['amount']) ? floatval($input['amount']) : 0;
        } else { // 'fail'
            $this->errorCode = $input['code'] ?? '';
            $this->errorMessage = $input['message'] ?? '';
        }

        if ($this->isDebug) {
             MPHB()->log()->debug(sprintf('[%s] Parsed Input: %s', __CLASS__, print_r($input, true)));
        }

        // Return the raw input array for validation
        return $input;
    }

    /**
     * Validate the incoming request parameters.
     * @param array $input Parsed GET parameters.
     * @return bool True if valid, false otherwise.
     */
    protected function validate($input): bool {
        if (empty($this->tossOrderId) || empty($this->callbackType)) {
             MPHB()->log()->error(sprintf('[%s] Validation Failed: Missing orderId or callback_type.', __CLASS__));
            return false;
        }

        if ($this->callbackType === 'success' && (empty($this->paymentKey) || $this->amount <= 0)) {
             MPHB()->log()->error(sprintf('[%s] Validation Failed (Success Callback): Missing paymentKey or invalid amount. OrderID: %s', __CLASS__, $this->tossOrderId));
            return false;
        }

        if ($this->callbackType === 'fail' && empty($this->errorCode)) {
             // Allow empty error code if message exists, but log it
             if(empty($this->errorMessage)){
                 MPHB()->log()->warning(sprintf('[%s] Validation Warning (Fail Callback): Missing error code and message. OrderID: %s', __CLASS__, $this->tossOrderId));
             } else {
                  MPHB()->log()->warning(sprintf('[%s] Validation Warning (Fail Callback): Missing error code, using message. OrderID: %s', __CLASS__, $this->tossOrderId));
             }
             // We still consider it valid enough to process as a failure
        }

         if ($this->isDebug) {
             MPHB()->log()->debug(sprintf('[%s] Validation Passed. OrderID: %s, Type: %s', __CLASS__, $this->tossOrderId, $this->callbackType));
        }

        return true;
    }

    /**
     * Retrieve the MPHB Payment associated with this callback.
     * We need to link the Toss Order ID back to an MPHB Payment.
     * Assumes Toss Order ID format: mphb_{payment_id}_{timestamp} or mphb_{booking_id}_{timestamp}
     *
     * @return Payment|null The found Payment object or null.
     */
    protected function retrievePayment(): ?Payment {
        $payment = null;
        // Try finding by Toss Order ID stored in meta (best approach if possible)
        $payment = MPHB()->getPaymentRepository()->findOneBy(['metaQuery' => [
            [
                'key' => '_mphb_toss_order_id',
                'value' => $this->tossOrderId,
                'compare' => '='
            ]
        ]]);

        if ($payment) {
            if ($this->isDebug) {
                 MPHB()->log()->debug(sprintf('[%s] Retrieved Payment ID %d via meta key _mphb_toss_order_id=%s', __CLASS__, $payment->getId(), $this->tossOrderId));
            }
            return $payment;
        }

         // Fallback: Try parsing MPHB Booking ID from Toss Order ID
        $bookingId = TossPaymentsUtils::extractBookingIdFromTossOrderId($this->tossOrderId);
        if ($bookingId > 0) {
             // Find the latest *pending* payment for this booking using this gateway
            $payments = MPHB()->getPaymentRepository()->findAllBy(
                 [
                     'bookingId' => $bookingId,
                     'gatewayId' => $this->gatewayId, // Ensure it's for this gateway
                     'status'    => PaymentStatuses::STATUS_PENDING // Look for pending payments
                 ],
                 ['dateCreated' => 'DESC'] // Get the latest one first
             );

             if (!empty($payments)) {
                 $payment = reset($payments); // Get the first (latest) pending payment
                 if ($this->isDebug) {
                      MPHB()->log()->debug(sprintf('[%s] Retrieved latest pending Payment ID %d for Booking ID %d (from Toss Order ID %s)', __CLASS__, $payment->getId(), $bookingId, $this->tossOrderId));
                 }
                  // Store the Toss Order ID now for future reference
                 update_post_meta($payment->getId(), '_mphb_toss_order_id', $this->tossOrderId);
                 return $payment;
             } else {
                 if ($this->isDebug) {
                      MPHB()->log()->warning(sprintf('[%s] Could not find pending Payment for Booking ID %d (from Toss Order ID %s)', __CLASS__, $bookingId, $this->tossOrderId));
                 }
             }
         } else {
              if ($this->isDebug) {
                  MPHB()->log()->error(sprintf('[%s] Could not extract Booking ID from Toss Order ID: %s', __CLASS__, $this->tossOrderId));
             }
         }


        if (!$payment) {
             MPHB()->log()->error(sprintf('[%s] Failed to retrieve MPHB Payment for Toss Order ID: %s', __CLASS__, $this->tossOrderId));
        }

        return $payment; // Returns null if not found
    }

    /**
     * Process the validated notification.
     */
    protected function process() {
        if (!$this->payment) {
            // Already logged in retrievePayment
            return; // Cannot proceed without a payment object
        }

         if ($this->isDebug) {
             MPHB()->log()->debug(sprintf('[%s] Processing callback for Payment ID %d. Type: %s', __CLASS__, $this->payment->getId(), $this->callbackType));
         }

        // Ensure we don't process already completed/failed payments again
        $currentStatus = $this->payment->getStatus();
        if (!in_array($currentStatus, [PaymentStatuses::STATUS_PENDING])) {
             MPHB()->log()->warning(sprintf('[%s] Payment ID %d already processed (Status: %s). Skipping callback. Toss Order ID: %s', __CLASS__, $this->payment->getId(), $currentStatus, $this->tossOrderId));
             // Redirect gracefully anyway
             $this->redirectUser();
             return;
        }


        if ($this->callbackType === 'success') {
            $this->processSuccess();
        } else {
            $this->processFailure();
        }

        // Redirect the user after processing
        $this->redirectUser();
    }

    /**
     * Process a successful payment callback.
     */
    private function processSuccess() {
        try {
            // Verify amount received matches the payment amount
            $paymentAmount = round(floatval($this->payment->getAmount()));
            $receivedAmount = round($this->amount);

            if ($paymentAmount !== $receivedAmount) {
                 $logMsg = sprintf(
                     __('Payment amount mismatch. Expected: %s, Received: %s.', 'mphb-tosspayments-card'),
                     $paymentAmount,
                     $receivedAmount
                 );
                 $this->paymentFailed($logMsg); // Mark as failed due to mismatch
                 MPHB()->log()->error(sprintf('[%s] Payment ID %d Amount Mismatch: Expected=%s, Received=%s. Toss Order ID: %s', __CLASS__, $this->payment->getId(), $paymentAmount, $receivedAmount, $this->tossOrderId));
                 return;
            }

            // Call Toss API to confirm the payment
             MPHB()->log()->info(sprintf('[%s] Payment ID %d: Confirming payment with Toss API. PaymentKey: %s, Amount: %s, TossOrderID: %s', __CLASS__, $this->payment->getId(), $this->paymentKey, $receivedAmount, $this->tossOrderId));
            $confirmation = $this->api->confirmPayment($this->paymentKey, $this->tossOrderId, $receivedAmount);

            if (!$confirmation || isset($confirmation->code) || $confirmation->status !== 'DONE') {
                 $errorCode = $confirmation->code ?? 'CONFIRM_FAILED';
                 $errorMsg = $confirmation->message ?? __('Payment confirmation failed.', 'mphb-tosspayments-card');
                 $logMsg = sprintf(__('Toss Payments confirmation failed. Code: %s, Message: %s', 'mphb-tosspayments-card'), $errorCode, $errorMsg);
                 $this->paymentFailed($logMsg);
                 MPHB()->log()->error(sprintf('[%s] Payment ID %d Toss Confirmation Failed: Code=%s, Message=%s. Toss Order ID: %s', __CLASS__, $this->payment->getId(), $errorCode, $errorMsg, $this->tossOrderId));
            } else {
                 // Payment confirmed successfully
                 $logMsg = sprintf(__('Payment successfully confirmed via Toss Payments. Payment Key: %s', 'mphb-tosspayments-card'), $this->paymentKey);
                 $this->paymentCompleted($logMsg);
                 $this->saveTossMetaData($confirmation); // Save additional details
                 MPHB()->log()->info(sprintf('[%s] Payment ID %d Completed Successfully. Toss Order ID: %s', __CLASS__, $this->payment->getId(), $this->tossOrderId));
            }

        } catch (TossPaymentsException $e) {
             $logMsg = sprintf(__('Error during Toss Payments confirmation: %s', 'mphb-tosspayments-card'), $e->getMessage());
             $this->paymentFailed($logMsg);
             MPHB()->log()->error(sprintf('[%s] Payment ID %d Toss Confirmation Exception: %s. Toss Order ID: %s', __CLASS__, $this->payment->getId(), $e->getMessage(), $this->tossOrderId));
        } catch (\Exception $e) {
             $logMsg = sprintf(__('An unexpected error occurred during payment confirmation: %s', 'mphb-tosspayments-card'), $e->getMessage());
             $this->paymentFailed($logMsg);
             MPHB()->log()->error(sprintf('[%s] Payment ID %d Unexpected Confirmation Exception: %s. Toss Order ID: %s', __CLASS__, $this->payment->getId(), $e->getMessage(), $this->tossOrderId));
        }
    }

    /**
     * Process a failed payment callback.
     */
    private function processFailure() {
        $logMsg = sprintf(
            __('Payment failed via Toss Payments callback. Code: %s, Message: %s', 'mphb-tosspayments-card'),
            esc_html($this->errorCode),
            esc_html($this->errorMessage)
        );
        $this->paymentFailed($logMsg);
        MPHB()->log()->warning(sprintf('[%s] Payment ID %d Failed via Callback: Code=%s, Message=%s. Toss Order ID: %s', __CLASS__, $this->payment->getId(), $this->errorCode, $this->errorMessage, $this->tossOrderId));

    }

    /**
     * Redirect user to the appropriate MPHB page after processing.
     */
    private function redirectUser() {
        if (!$this->payment) {
             // Redirect to a generic failure page if payment couldn't be retrieved
             $redirectUrl = MPHB()->settings()->pages()->getPaymentFailedPageUrl();
             MPHB()->log()->error(sprintf('[%s] Redirecting to generic failure page, payment object not found. Toss Order ID: %s', __CLASS__, $this->tossOrderId));
        } else {
             $status = $this->payment->getStatus();
             if ($status === PaymentStatuses::STATUS_COMPLETED) {
                 $redirectUrl = MPHB()->settings()->pages()->getReservationReceivedPageUrl($this->payment);
             } else {
                 // Includes FAILED, PENDING (if processing failed before status update), etc.
                 $redirectUrl = MPHB()->settings()->pages()->getPaymentFailedPageUrl($this->payment);
             }
             if ($this->isDebug) {
                 MPHB()->log()->debug(sprintf('[%s] Redirecting Payment ID %d (Status: %s) to: %s', __CLASS__, $this->payment->getId(), $status, $redirectUrl));
             }
        }

        wp_safe_redirect($redirectUrl);
        exit;
    }

     /**
     * Save additional metadata from Toss confirmation response.
     * @param object $response Decoded Toss API confirmation response.
     */
    private function saveTossMetaData(object $response) {
        if (!$this->payment) return;

        update_post_meta($this->payment->getId(), '_mphb_toss_payment_key', sanitize_text_field($response->paymentKey ?? ''));
        update_post_meta($this->payment->getId(), '_mphb_toss_transaction_key', sanitize_text_field($response->lastTransactionKey ?? ''));
        update_post_meta($this->payment->getId(), '_mphb_toss_payment_type', sanitize_text_field($response->method ?? '')); // e.g., 카드
        update_post_meta($this->payment->getId(), '_mphb_toss_approved_at', sanitize_text_field($response->approvedAt ?? ''));

        if (!empty($response->card)) {
            update_post_meta($this->payment->getId(), '_mphb_toss_card_company', sanitize_text_field($response->card->company ?? ''));
            update_post_meta($this->payment->getId(), '_mphb_toss_card_number', sanitize_text_field($response->card->number ?? '')); // Masked number
            update_post_meta($this->payment->getId(), '_mphb_toss_card_approve_no', sanitize_text_field($response->card->approveNo ?? ''));
            update_post_meta($this->payment->getId(), '_mphb_toss_card_type', sanitize_text_field($response->card->cardType ?? '')); // e.g., 신용, 체크
            update_post_meta($this->payment->getId(), '_mphb_toss_card_installment', sanitize_text_field($response->card->installmentPlanMonths ?? 0));
        }
        if (!empty($response->receipt)) {
             update_post_meta($this->payment->getId(), '_mphb_toss_receipt_url', esc_url_raw($response->receipt->url ?? ''));
        }
    }


    /**
     * Override fireExit to prevent premature exit before redirection.
     * The parent::fireExit() is not suitable for redirect-based flows.
     *
     * @param bool $succeed
     */
    public function fireExit($succeed) {
        // Do nothing here, redirection is handled in process()
        if ($this->isDebug) {
             MPHB()->log()->debug(sprintf('[%s] fireExit called (succeed=%s), but overridden. Redirection handled in process().', __CLASS__, $succeed ? 'true' : 'false'));
        }
    }

    // ==================================================
    // START: Implementation of Abstract Methods
    // ==================================================

    /**
     * Mark the payment as completed.
     * Wraps the PaymentManager method.
     *
     * @param string $log Optional log message.
     * @return bool Success status.
     */
    protected function paymentCompleted($log = '') {
        if (!$this->payment) {
             error_log('[' . __CLASS__ . '] Attempted paymentCompleted without a valid payment object.');
             return false;
        }
        // Add gateway-specific prefix to log
        $logMsg = sprintf('[%s] %s', $this->gatewayId, $log);
        if ($this->isDebug) {
             MPHB()->log()->debug(sprintf('[%s] Calling paymentManager()->completePayment for Payment ID %d. Log: %s', __CLASS__, $this->payment->getId(), $logMsg));
        }
        return MPHB()->paymentManager()->completePayment($this->payment, $logMsg);
    }

    /**
     * Mark the payment as failed.
     * Wraps the PaymentManager method.
     *
     * @param string $log Optional log message.
     * @return bool Success status.
     */
    protected function paymentFailed($log = '') {
        if (!$this->payment) {
             error_log('[' . __CLASS__ . '] Attempted paymentFailed without a valid payment object.');
             return false;
        }
        $logMsg = sprintf('[%s] %s', $this->gatewayId, $log);
         if ($this->isDebug) {
             MPHB()->log()->debug(sprintf('[%s] Calling paymentManager()->failPayment for Payment ID %d. Log: %s', __CLASS__, $this->payment->getId(), $logMsg));
        }
        return MPHB()->paymentManager()->failPayment($this->payment, $logMsg);
    }

    /**
     * Mark the payment as refunded.
     * NOTE: Actual Toss cancellation happens via API calls initiated elsewhere (e.g., admin).
     * This method is primarily for updating the MPHB status when triggered by the listener
     * (less common for card payments) or potentially other flows.
     *
     * @param string $log Optional log message.
     * @return bool Success status.
     */
    protected function paymentRefunded($log = '') {
        if (!$this->payment) {
             error_log('[' . __CLASS__ . '] Attempted paymentRefunded without a valid payment object.');
             return false;
        }
        $logMsg = sprintf('[%s] %s', $this->gatewayId, $log);
         if ($this->isDebug) {
             MPHB()->log()->debug(sprintf('[%s] Calling paymentManager()->refundPayment for Payment ID %d. Log: %s', __CLASS__, $this->payment->getId(), $logMsg));
        }
        return MPHB()->paymentManager()->refundPayment($this->payment, $logMsg);
    }

    /**
     * Mark the payment as on-hold.
     * NOTE: Not typically used for direct card payments, but required by the abstract class.
     *
     * @param string $log Optional log message.
     * @return bool Success status.
     */
    protected function paymentOnHold($log = '') {
        if (!$this->payment) {
             error_log('[' . __CLASS__ . '] Attempted paymentOnHold without a valid payment object.');
             return false;
        }
        $logMsg = sprintf('[%s] %s', $this->gatewayId, $log);
        if ($this->isDebug) {
             MPHB()->log()->debug(sprintf('[%s] Calling paymentManager()->holdPayment for Payment ID %d. Log: %s', __CLASS__, $this->payment->getId(), $logMsg));
        }
        return MPHB()->paymentManager()->holdPayment($this->payment, $logMsg);
    }
}

