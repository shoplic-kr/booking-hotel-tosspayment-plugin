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
        parent::registerOptionsFields($subTab); // Registers enable, title, description

        $apiGroup = new Groups\SettingsGroup("mphb_payments_{$this->getId()}_api", __('API Settings', 'mphb-tosspayments-card'), $subTab->getOptionGroupName());

        $apiGroupFields = [
            FieldFactory::create("mphb_payment_gateway_{$this->id}_client_key", [
                'type' => 'text',
                'label' => __('Client Key', 'mphb-tosspayments-card'),
                'default' => $this->getDefaultOption('client_key'),
                'description' => __('Enter your Toss Payments Client Key (Test or Live).', 'mphb-tosspayments-card'),
            ]),
            FieldFactory::create("mphb_payment_gateway_{$this->id}_secret_key", [
                'type' => 'password', // Use password type for secret key
                'label' => __('Secret Key', 'mphb-tosspayments-card'),
                'default' => $this->getDefaultOption('secret_key'),
                'description' => __('Enter your Toss Payments Secret Key (Test or Live).', 'mphb-tosspayments-card'),
            ]),
             FieldFactory::create("mphb_payment_gateway_{$this->id}_debug", [
                'type' => 'checkbox',
                'label' => __('Debug Mode', 'mphb-tosspayments-card'),
                'inner_label' => __('Enable Debug Logging', 'mphb-tosspayments-card'),
                'default' => $this->getDefaultOption('debug'),
                'description' => __('Enable detailed logging for troubleshooting. Logs will be stored via MPHB logger.', 'mphb-tosspayments-card'),
            ]),
        ];

        $apiGroup->addFields($apiGroupFields);
        $subTab->addGroup($apiGroup);
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
     * Prepare data to be passed to the frontend JavaScript.
     * @return array
     */
    protected function getCheckoutLocalizationData(): array {
        return [
            'gateway_id' => $this->getId(),
            'client_key' => $this->clientKey,
            'ajax_url' => admin_url('admin-ajax.php'), // Or MPHB specific AJAX handler if available
            'nonce' => wp_create_nonce('mphb_tosspayments_card_nonce'), // Create a nonce for potential AJAX later
             'i18n' => [
                 'payment_error' => __('An error occurred during payment processing. Please try again.', 'mphb-tosspayments-card'),
                 'request_failed' => __('Failed to initiate payment request.', 'mphb-tosspayments-card'),
             ]
            // Add any other necessary data like i18n strings
        ];
    }

     /**
      * Process the payment.
      * In this flow, the actual payment initiation happens on the frontend via JS.
      * This method might be called by MPHB *after* the initial booking form submission
      * but *before* the redirect to Toss. We mainly use it to set the payment to pending.
      * The real completion/failure happens in the TossPaymentsListener.
      *
      * @param Booking $booking
      * @param Payment $payment
      */
     public function processPayment(Booking $booking, Payment $payment) {
         // The core logic happens in JS (requestPayment) and the Listener (callback handling).
         // Here, we might just ensure the payment status is initially pending.
         // MPHB typically creates the Payment post with 'pending' status before calling this.
         // If the status is already pending, we might not need to do anything here,
         // as the JS will take over the flow.

         // Add a log entry that Toss Payments process started
         $payment->addLog(sprintf(__('Payment process initiated with %s.', 'mphb-tosspayments-card'), $this->getTitle()));

         // Let MPHB handle the redirect/response. The JS should intercept the default form submission.
         // We don't redirect from here in this model.
         return true; // Indicate processing started, but JS/callback handles completion.
     }

     /**
     * Provide checkout data, mainly used by JS.
     * Overrides the parent method to add Toss-specific data.
     *
     * @param Booking $booking
     * @return array
     */
    public function getCheckoutData($booking): array {
        $parentData = parent::getCheckoutData($booking); // Gets amount, description

        // Generate a unique Order ID for Toss Payments, including the MPHB Payment ID
        // The Payment object might not be created yet when this is called.
        // We need a way to link back. Let's use the booking ID for now and refine if needed.
        // A better approach might involve an AJAX call from JS to create the MPHB Payment
        // *before* calling Toss, get the payment ID, and use that.
        // Simpler approach for now: use booking ID + timestamp. The Listener will need to find the payment via booking ID.
        $tossOrderId = TossPaymentsUtils::generateTossOrderId($booking->getId());

        // Get customer details
        $customer = $booking->getCustomer();
        $customerName = $customer ? $customer->getName() : __('Guest', 'mphb-tosspayments-card');
        $customerEmail = $customer ? $customer->getEmail() : ''; // Toss might require email

        // Ensure amount is correctly formatted (integer for KRW)
        $amount = round($parentData['amount']);

        return array_merge($parentData, [
            'gateway_id' => $this->getId(),
            'client_key' => $this->clientKey,
            'toss_order_id' => $tossOrderId,
            'toss_order_name' => $parentData['paymentDescription'], // Use description from parent
            'amount' => $amount, // Use calculated deposit amount, rounded for KRW
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'success_url' => $this->listener ? TossPaymentsUtils::generateCallbackUrl($this->getId(), 'success', $tossOrderId) : '',
            'fail_url' => $this->listener ? TossPaymentsUtils::generateCallbackUrl($this->getId(), 'fail', $tossOrderId) : '',
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
