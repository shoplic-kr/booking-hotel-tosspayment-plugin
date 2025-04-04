<?php
namespace MPHB\TossPaymentsCard;

use MPHB\Payments\Gateways\Gateway;
use MPHB\Admin\Groups;
use MPHB\Admin\Fields\FieldFactory;
use MPHB\Entities\Booking;
use MPHB\Entities\Payment;
use MPHB\TossPaymentsCard\TossPaymentsAPI;
use MPHB\TossPaymentsCard\TossPaymentsListener;
use MPHB\TossPaymentsCard\TossPaymentsUtils;

if (!defined('ABSPATH')) {
    exit;
}

class TossPaymentsCardGateway extends Gateway {

    const GATEWAY_ID = 'tosspayments_card';

    /** @var string */
    protected $clientKey = '';
    /** @var string */
    protected $secretKey = '';
    /** @var bool */
    protected $isDebug = false;
    /** @var TossPaymentsAPI|null */
    protected $api = null;
    /** @var TossPaymentsListener|null */
    protected $listener = null;

    public function __construct() {
        // Adjust filters for this gateway
        add_filter('mphb_gateway_has_sandbox', [$this, 'disableSandboxOption'], 10, 2);
        add_filter('mphb_gateway_has_instructions', [$this, 'hideInstructions'], 10, 2);

        parent::__construct(); // Calls initId, initDefaultOptions, setupProperties, etc.

        // Initialize API after properties (like secretKey) are set by parent::__construct -> setupProperties
        $this->api = new TossPaymentsAPI($this->secretKey, $this->isDebug);

        // Initialize the listener only if the gateway is active
        if ($this->isActive()) {
            $this->setupListener(); // Ensure listener is setup before adding script hook
            add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        }
    }

    protected function initId(): string {
        return self::GATEWAY_ID;
    }

    /**
     * @return bool Hide sandbox option as Toss has Test/Live keys directly
     */
    public function disableSandboxOption(bool $show, string $gatewayId): bool {
        return ($gatewayId === $this->getId()) ? false : $show;
    }

    /**
     * @return bool Hide instructions field as it's not typically used for direct card payments
     */
    public function hideInstructions(bool $show, string $gatewayId): bool {
        return ($gatewayId === $this->getId()) ? false : $show;
    }

    protected function initDefaultOptions(): array {
        return array_merge(parent::initDefaultOptions(), [
            'title' => __('Toss Payments Credit Card', 'mphb-tosspayments-card'),
            'description' => __('Pay with your credit card via Toss Payments.', 'mphb-tosspayments-card'),
            'enabled' => false,
            'client_key' => '',
            'secret_key' => '',
            'debug' => false,
        ]);
    }

    protected function setupProperties() {
        parent::setupProperties(); // Loads title, description, enabled, gets options

        // Now set specific properties *after* options are loaded by parent
        $this->adminTitle = __('Toss Payments - Credit Card', 'mphb-tosspayments-card');
        $this->clientKey = $this->getOption('client_key');
        $this->secretKey = $this->getOption('secret_key');
        $this->isDebug = $this->getOption('debug') === '1' || $this->getOption('debug') === true; // Handle checkbox value

        // Basic validation check for activation
        if (empty($this->clientKey) || empty($this->secretKey)) {
            $this->enabled = false; // Disable if keys are missing
        }

         // Check currency - Toss Payments Card typically requires KRW
        $mphb_currency = MPHB()->settings()->currency()->getCurrencyCode();
        if ('KRW' !== strtoupper($mphb_currency)) {
             $this->enabled = false; // Disable if currency is not KRW
             $this->adminDescription = sprintf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                sprintf(
                    /* translators: 1: Gateway Name, 2: Currency Code (KRW), 3: Detected Currency Code */
                    esc_html__('%1$s requires the currency to be set to %2$s in MotoPress Hotel Booking settings. Current currency is %3$s. The gateway has been disabled.', 'mphb-tosspayments-card'),
                    $this->getAdminTitle(),
                    'KRW',
                    esc_html($mphb_currency)
                )
             );
        } else if ($this->isEnabled()) { // Use isEnabled() as isActive() might call setupListener prematurely
             // Setup listener only when active and currency is correct
             $this->setupListener(); // Listener is needed to generate admin description URL
             if($this->listener){
                 $this->adminDescription = sprintf(
                     __('Callback URL: %s', 'mphb-tosspayments-card'),
                     '<code>' . esc_url($this->listener->getNotifyUrl()) . '</code>'
                 );
             }
        }
    }

    // Override isActive to include listener setup check if needed,
    // but parent::isActive() should be sufficient if setupProperties handles disabling correctly.
    // public function isActive(){
    //     return parent::isActive();
    // }


    protected function setupListener() {
        // Initialize API first if not done already (in case isActive was called before constructor finished somehow)
        if (!$this->api) {
             $this->api = new TossPaymentsAPI($this->secretKey, $this->isDebug);
        }

        if (!$this->listener) {
            $this->listener = new TossPaymentsListener([
                'gatewayId' => $this->getId(),
                'sandbox' => false, // Sandbox controlled by keys
                'api' => $this->getApi(), // Use getter method
                'debug' => $this->isDebug,
            ]);
        }
    }

    /**
     * Register settings fields for the admin area.
     *
     * @param \MPHB\Admin\Tabs\SettingsSubTab $subTab
     */
    public function registerOptionsFields(&$subTab) {
        parent::registerOptionsFields($subTab); // Registers enable, title, description

        $apiGroup = new Groups\SettingsGroup("mphb_payments_{$this->getId()}_api", __('API Settings', 'mphb-tosspayments-card'), $subTab->getOptionGroupName());

        $apiGroupFields = [];

        $clientKeyField = FieldFactory::create("mphb_payment_gateway_{$this->id}_client_key", [
            'type' => 'text',
            'label' => __('Client Key', 'mphb-tosspayments-card'),
            'default' => $this->getDefaultOption('client_key'),
            'description' => __('Enter your Toss Payments Client Key (Test or Live).', 'mphb-tosspayments-card'),
        ]);
        if ($clientKeyField !== null) {
            $apiGroupFields[] = $clientKeyField;
        } else {
             error_log("MPHB TossPaymentsCard Error: FieldFactory::create returned null for client_key");
        }


        $secretKeyField = FieldFactory::create("mphb_payment_gateway_{$this->id}_secret_key", [
            'type' => 'text', // Use text type
            'label' => __('Secret Key', 'mphb-tosspayments-card'),
            'default' => $this->getDefaultOption('secret_key'),
            'description' => __('Enter your Toss Payments Secret Key (Test or Live).', 'mphb-tosspayments-card'),
        ]);
         if ($secretKeyField !== null) {
            $apiGroupFields[] = $secretKeyField;
        } else {
             error_log("MPHB TossPaymentsCard Error: FieldFactory::create returned null for secret_key");
        }

        $debugField = FieldFactory::create("mphb_payment_gateway_{$this->id}_debug", [
            'type' => 'checkbox',
            'label' => __('Debug Mode', 'mphb-tosspayments-card'),
            'inner_label' => __('Enable Debug Logging', 'mphb-tosspayments-card'),
            'default' => $this->getDefaultOption('debug'), // Default is false (boolean)
            'description' => __('Enable detailed logging for troubleshooting. Logs will be stored via MPHB logger.', 'mphb-tosspayments-card'),
        ]);
         if ($debugField !== null) {
            $apiGroupFields[] = $debugField;
        } else {
             error_log("MPHB TossPaymentsCard Error: FieldFactory::create returned null for debug");
        }

        // Only add fields if the array is not empty
        if (!empty($apiGroupFields)) {
            $apiGroup->addFields($apiGroupFields);
            $subTab->addGroup($apiGroup);
        } else {
             error_log("MPHB TossPaymentsCard Error: No API fields were successfully created.");
        }
    }

     /**
     * Enqueue necessary scripts for checkout.
     */
    public function enqueueScripts() {
        global $post;
        // More robust check for checkout page: use MPHB function if available, otherwise check shortcode
        $is_checkout = function_exists('mphb_is_checkout_page') ? mphb_is_checkout_page() : (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'mphb_checkout'));

        if (!$is_checkout) {
             return;
        }

        // Toss Payments SDK
        wp_enqueue_script(
            'tosspayments-sdk',
            'https://js.tosspayments.com/v2/standard',
            [],
            null,
            true
        );

        // Custom Gateway JS
        wp_enqueue_script(
            'mphb-tosspayments-card-js',
            MPHB_TOSSPAYMENTS_CARD_PLUGIN_URL . 'assets/js/mphb-tosspayments-card.js',
            // Ensure 'mphb-checkout-script' is the correct handle for MPHB's core checkout JS
            ['jquery', 'tosspayments-sdk'],
            MPHB_TOSSPAYMENTS_CARD_VERSION,
            true
        );

        // Localize data for the script
        wp_localize_script('mphb-tosspayments-card-js', 'mphbTossPaymentsCardParams', $this->getCheckoutLocalizationData());
    }

    /**
     * Prepare data to be passed to the frontend JavaScript initially.
     * Removed the problematic Ajax::getEndpoint() call.
     *
     * @return array
     */
    protected function getCheckoutLocalizationData(): array {
        // Nonce needs to be specific if we plan custom AJAX later, otherwise might not be needed here.
        // Keep it for now.
        $nonce = wp_create_nonce('mphb_tosspayments_card_action_nonce');

        return [
            'gateway_id' => $this->getId(),
            'client_key' => $this->clientKey,
            'ajax_url'   => admin_url('admin-ajax.php'), // Standard WP AJAX URL
            'nonce'      => $nonce, // Nonce for potential future custom AJAX
             'i18n' => [
                 'payment_error' => __('An error occurred during payment processing. Please try again.', 'mphb-tosspayments-card'),
                 'request_failed' => __('Failed to initiate payment request.', 'mphb-tosspayments-card'),
                 'booking_failed' => __('Failed to process booking. Please try again.', 'mphb-tosspayments-card'),
             ]
        ];
    }

     /**
     * Process the payment *after* MPHB creates the Booking and Payment posts.
     * Prepare the data needed for Toss and send it back to the JS.
     *
     * @param Booking $booking The created Booking object (should have an ID).
     * @param Payment $payment The created Payment object (should have an ID, status pending).
     * @return array|bool Response for MPHB's AJAX handler.
     */
    public function processPayment(Booking $booking, Payment $payment) {
        if ($this->isDebug) {
            MPHB()->log()->debug(sprintf('[%s] processPayment called for Booking ID: %s, Payment ID: %s', __CLASS__, $booking->getId(), $payment->getId()));
        }

        $bookingId = $booking->getId();
        $paymentId = $payment->getId();
        if (!$bookingId || !$paymentId) {
            MPHB()->log()->error(sprintf('[%s] processPayment Error: Missing Booking ID (%s) or Payment ID (%s).', __CLASS__, $bookingId, $paymentId));
            return ['result' => 'failure', 'message' => __('Failed to get booking/payment details.', 'mphb-tosspayments-card')];
        }

        // Generate the unique Toss Order ID using the Payment ID
        $tossOrderId = TossPaymentsUtils::generateTossOrderId($paymentId);

         // Store Toss Order ID in payment meta immediately for the listener
        $meta_updated = update_post_meta($paymentId, '_mphb_toss_order_id', $tossOrderId);
        if (!$meta_updated && $this->isDebug) {
            MPHB()->log()->warning(sprintf('[%s] Failed to store _mphb_toss_order_id meta for Payment ID %s.', __CLASS__, $paymentId));
        }

        // Generate callback URLs using the final Toss Order ID
        // Ensure listener is available before generating URLs
        $this->setupListener(); // Make sure listener is initialized
        if (!$this->listener) {
             MPHB()->log()->error(sprintf('[%s] processPayment Error: Listener not initialized. Cannot generate callback URLs for Payment ID %s.', __CLASS__, $paymentId));
             return ['result' => 'failure', 'message' => __('Payment listener configuration error.', 'mphb-tosspayments-card')];
        }
        $successUrl = TossPaymentsUtils::generateCallbackUrl($this->getId(), 'success', $tossOrderId);
        $failUrl = TossPaymentsUtils::generateCallbackUrl($this->getId(), 'fail', $tossOrderId);

        // Prepare data for Toss request
        $amount = round(floatval($payment->getAmount()));
        $customer = $booking->getCustomer();
        $customerName = $customer ? $customer->getName() : __('Guest', 'mphb-tosspayments-card');
        // Ensure name is not empty, provide a default if needed by Toss
        if(empty(trim($customerName))) {
            $customerName = 'Customer';
        }
        $customerEmail = $customer ? $customer->getEmail() : '';
        $orderName = $this->generateItemName($booking); // Use parent method for description

        $tossData = [
            'amount'         => $amount,
            'orderId'        => $tossOrderId,
            'orderName'      => mb_substr($orderName, 0, 100), // Max 100 chars for orderName
            'customerName'   => mb_substr($customerName, 0, 100), // Max 100 chars for customerName
            'customerEmail'  => $customerEmail ?: null, // Pass null if empty, check if Toss allows this
            'successUrl'     => $successUrl,
            'failUrl'        => $failUrl,
            'windowTarget'   => '_self',
        ];

        if ($this->isDebug) {
            MPHB()->log()->debug(sprintf('[%s] Prepared Toss Data for JS: %s', __CLASS__, print_r($tossData, true)));
        }

        // Send back success status and the data for the JS handler
        return [
            'result'   => 'success',
            'gateway'  => $this->getId(),
            'tossData' => $tossData
        ];
    }

     /**
     * Provide checkout data - less critical now, but kept for potential base usage.
     *
     * @param Booking $booking
     * @return array
     */
    public function getCheckoutData($booking): array {
        // If $booking is null (can happen during initial script enqueue), handle gracefully
        if (!$booking || !($booking instanceof Booking)) {
             if ($this->isDebug) {
                 MPHB()->log()->debug(sprintf('[%s] getCheckoutData called with invalid Booking object.', __CLASS__));
             }
            // Return minimal data or defaults if needed by parent class/templates
            return [
                'gateway_id' => $this->getId(),
                'amount' => 0,
                'paymentDescription' => __('Accommodation Reservation', 'mphb-tosspayments-card'),
            ];
        }

        $parentData = parent::getCheckoutData($booking);
        $amount = round($parentData['amount']);

        return array_merge($parentData, [
            'gateway_id' => $this->getId(),
            'amount' => $amount,
        ]);
    }

     /**
      * Get the API handler instance.
      * Ensures API is initialized.
      * @return TossPaymentsAPI|null
      */
     public function getApi(): ?TossPaymentsAPI {
         // Ensure API is initialized, especially if setupProperties hasn't run yet
         if (!$this->api && !empty($this->secretKey)) {
             $this->api = new TossPaymentsAPI($this->secretKey, $this->isDebug);
         }
         return $this->api;
     }

     /**
      * Is debug mode enabled?
      * @return bool
      */
     public function isDebug(): bool {
         // Ensure property is set, default to false
         return isset($this->isDebug) ? (bool) $this->isDebug : false;
     }
}
