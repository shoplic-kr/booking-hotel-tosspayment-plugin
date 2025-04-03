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
use MPHB\Ajax;

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
        $this->api = new TossPaymentsAPI($this->secretKey, $this->isDebug);

        // Initialize the listener only if the gateway is active
        if ($this->isActive()) {
            $this->setupListener();
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
        function_exists('ray') && ray('TossPaymentsCardGateway setupProperties called');
        
        parent::setupProperties(); // Loads title, description, enabled

        $this->adminTitle = __('Toss Payments - Credit Card', 'mphb-tosspayments-card');
        $this->clientKey = $this->getOption('client_key');
        $this->secretKey = $this->getOption('secret_key');
        $this->isDebug = (bool) $this->getOption('debug');

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
        } else if ($this->isActive()) {
             // Setup listener only when active and currency is correct
             $this->setupListener();
             if($this->listener){
                 $this->adminDescription = sprintf(
                     __('Callback URL: %s', 'mphb-tosspayments-card'),
                     '<code>' . esc_url($this->listener->getNotifyUrl()) . '</code>'
                 );
             }
        }
    }

    protected function setupListener() {
        if (!$this->listener) {
            $this->listener = new TossPaymentsListener([
                'gatewayId' => $this->getId(),
                'sandbox' => false, // Sandbox controlled by keys
                'api' => $this->getApi(),
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
        // Inside TossPaymentsCardGateway.php -> registerOptionsFields() method
        parent::registerOptionsFields($subTab); // Registers enable, title, description

        $apiGroup = new Groups\SettingsGroup("mphb_payments_{$this->getId()}_api", __('API Settings', 'mphb-tosspayments-card'), $subTab->getOptionGroupName());

        $apiGroupFields = []; // Initialize as empty array

        // --- Start Debugging ---
        $clientKeyField = FieldFactory::create("mphb_payment_gateway_{$this->id}_client_key", [
            'type' => 'text',
            'label' => __('Client Key', 'mphb-tosspayments-card'),
            'default' => $this->getDefaultOption('client_key'),
            'description' => __('Enter your Toss Payments Client Key (Test or Live).', 'mphb-tosspayments-card'),
        ]);
        if ($clientKeyField === null) {
            error_log("MPHB TossPaymentsCard Debug: FieldFactory::create returned null for client_key");
        } else {
            $apiGroupFields[] = $clientKeyField;
        }

        $secretKeyField = FieldFactory::create("mphb_payment_gateway_{$this->id}_secret_key", [
            'type' => 'text', // Ensure this is 'text'
            'label' => __('Secret Key', 'mphb-tosspayments-card'),
            'default' => $this->getDefaultOption('secret_key'),
            'description' => __('Enter your Toss Payments Secret Key (Test or Live).', 'mphb-tosspayments-card'),
        ]);
        if ($secretKeyField === null) {
            error_log("MPHB TossPaymentsCard Debug: FieldFactory::create returned null for secret_key");
        } else {
            $apiGroupFields[] = $secretKeyField;
        }

        $debugField = FieldFactory::create("mphb_payment_gateway_{$this->id}_debug", [
            'type' => 'checkbox',
            'label' => __('Debug Mode', 'mphb-tosspayments-card'),
            'inner_label' => __('Enable Debug Logging', 'mphb-tosspayments-card'),
            'default' => $this->getDefaultOption('debug'),
            'description' => __('Enable detailed logging for troubleshooting. Logs will be stored via MPHB logger.', 'mphb-tosspayments-card'),
        ]);
        if ($debugField === null) {
            error_log("MPHB TossPaymentsCard Debug: FieldFactory::create returned null for debug");
        } else {
            $apiGroupFields[] = $debugField;
        }
        // --- End Debugging ---


        // Check if the array is still empty or contains nulls before adding
        if (empty($apiGroupFields) || count(array_filter($apiGroupFields)) !== count($apiGroupFields)) {
            error_log("MPHB TossPaymentsCard Error: One or more API fields failed to create. Fields array: " . print_r($apiGroupFields, true));
            // Optionally prevent adding fields if there was an error
        } else {
            // This line (previously line 159) might still error if $apiGroupFields contains null
            $apiGroup->addFields($apiGroupFields);
            $subTab->addGroup($apiGroup);
        }

    }

     /**
     * Enqueue necessary scripts for checkout.
     */
    public function enqueueScripts() {
        // Only enqueue on MPHB checkout page (assuming mphb_is_checkout_page() exists or similar check)
        // If MPHB doesn't have a specific conditional tag, you might need a broader check or rely on shortcode presence.
        // For now, let's assume a general check is okay, but refine if possible.
        global $post;
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'mphb_checkout')) {
             return;
        }

        // Toss Payments SDK
        wp_enqueue_script(
            'tosspayments-sdk',
            'https://js.tosspayments.com/v2/standard',
            [], // No dependencies
            null, // No version needed for external SDK
            true // Load in footer
        );

        // Custom Gateway JS
        wp_enqueue_script(
            'mphb-tosspayments-card-js',
            MPHB_TOSSPAYMENTS_CARD_PLUGIN_URL . 'assets/js/mphb-tosspayments-card.js',
            ['jquery', 'mphb-checkout-script', 'tosspayments-sdk'], // Depends on jQuery, MPHB checkout script, and Toss SDK
            MPHB_TOSSPAYMENTS_CARD_VERSION,
            true // Load in footer
        );

        // Localize data for the script
        wp_localize_script('mphb-tosspayments-card-js', 'mphbTossPaymentsCardParams', $this->getCheckoutLocalizationData());
    }

    /**
     * Prepare data to be passed to the frontend JavaScript initially.
     * We remove tossOrderId generation from here as Booking ID might be null.
     *
     * @return array
     */
    protected function getCheckoutLocalizationData(): array {
        return [
            'gateway_id' => $this->getId(),
            'client_key' => $this->clientKey,
            'ajax_url'   => admin_url('admin-ajax.php'), // Standard WP AJAX URL
            'nonce'      => wp_create_nonce('mphb_tosspayments_card_nonce'),
            'mphb_ajax_url' => Ajax::getEndpoint(), // Now PHP knows which Ajax class this is
            'mphb_checkout_nonce' => wp_create_nonce('mphb_checkout_nonce'), // Check if 'mphb_checkout_nonce' is correct
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

        // Ensure we have valid IDs now
        $bookingId = $booking->getId();
        $paymentId = $payment->getId(); // Use Payment ID for better uniqueness if needed
        if (!$bookingId || !$paymentId) {
            MPHB()->log()->error(sprintf('[%s] processPayment Error: Missing Booking or Payment ID.', __CLASS__));
            // Returning false might trigger MPHB's default failure handling
            return false;
        }

        // Generate the unique Toss Order ID using the *Payment* ID for reliability
        // Or stick to Booking ID if preferred, it should be valid now. Let's use Payment ID.
        $tossOrderId = TossPaymentsUtils::generateTossOrderId($paymentId); // <-- Use Payment ID

         // Store Toss Order ID in payment meta immediately for the listener
        update_post_meta($paymentId, '_mphb_toss_order_id', $tossOrderId);

        // Generate callback URLs using the final Toss Order ID
        $successUrl = TossPaymentsUtils::generateCallbackUrl($this->getId(), 'success', $tossOrderId);
        $failUrl = TossPaymentsUtils::generateCallbackUrl($this->getId(), 'fail', $tossOrderId);

        // Prepare data for Toss request
        $amount = round(floatval($payment->getAmount()));
        $customer = $booking->getCustomer();
        $customerName = $customer ? $customer->getName() : __('Guest', 'mphb-tosspayments-card');
        $customerEmail = $customer ? $customer->getEmail() : '';
        $orderName = $this->generateItemName($booking); // Use parent method for description

        $tossData = [
            'amount'         => $amount,
            'orderId'        => $tossOrderId,
            'orderName'      => $orderName,
            'customerName'   => $customerName,
            'customerEmail'  => $customerEmail,
            'successUrl'     => $successUrl,
            'failUrl'        => $failUrl,
            'windowTarget'   => '_self', // Or '_blank' if you want a new window/tab
            // Add any other necessary fixed parameters for requestPayment
        ];

        if ($this->isDebug) {
            MPHB()->log()->debug(sprintf('[%s] Prepared Toss Data for JS: %s', __CLASS__, print_r($tossData, true)));
        }


        // Signal MPHB's AJAX handler to proceed with our custom JS flow.
        // We need to send back 'success' and the data for the JS.
        // MPHB's Ajax::sendSuccess expects specific format, may need adjustment.
        // A common pattern is to return an array that the JS can use.
        return [
            'result'   => 'success', // Indicate MPHB processing was successful
            'gateway'  => $this->getId(), // Identify the gateway
            'tossData' => $tossData // The data needed by our JS
        ];

        // Note: We are NOT redirecting here. We are sending data back to the JS
        // which is handling the AJAX request initiated by the form submission.
    }

     /**
     * Provide checkout data - THIS IS NOW LESS IMPORTANT for Toss specific data
     * as the final data comes from processPayment response.
     * Keep it for potential parent class usage or basic rendering.
     *
     * @param Booking $booking
     * @return array
     */
    public function getCheckoutData($booking): array {
        $parentData = parent::getCheckoutData($booking); // Gets amount, description

        // We no longer generate Toss Order ID here
        $amount = round($parentData['amount']);

        // Add basic info if needed by templates, but the critical data for JS
        // will come from the processPayment response via AJAX.
        return array_merge($parentData, [
            'gateway_id' => $this->getId(),
            'amount' => $amount,
            // 'client_key' => $this->clientKey, // JS gets this from localized params now
        ]);
    }

     /**
      * Get the API handler instance.
      * @return TossPaymentsAPI|null
      */
     public function getApi(): ?TossPaymentsAPI {
         return $this->api;
     }

     /**
      * Is debug mode enabled?
      * @return bool
      */
     public function isDebug(): bool {
         return $this->isDebug;
     }
}
