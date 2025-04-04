/* global jQuery, mphbTossPaymentsCardParams, TossPayments */
jQuery(($) => {
    'use strict';

    const tossHandler = {
      $checkoutForm: null,
      gatewayId: mphbTossPaymentsCardParams.gateway_id || 'tosspayments_card',
      clientKey: mphbTossPaymentsCardParams.client_key || '',
      isProcessing: false,
  
      init() {
        this.$checkoutForm = $('form.mphb_sc_checkout-form'); // Adjust selector if needed
  
        if (!this.$checkoutForm.length || !this.clientKey) {
          console.log('TossPayments Card: Checkout form or Client Key not found.');
          return;
        }
  
        // Initialize TossPayments SDK
        try {
          this.tossPayments = TossPayments(this.clientKey);
        } catch (error) {
          console.error('TossPayments Card: Failed to initialize Toss SDK.', error);
          this.showError('Failed to initialize payment SDK.'); // Show generic error
          return;
        }
  
        this.bindEvents();
        console.log('TossPayments Card: Initialized.');
      },
  
      bindEvents() {
        // MPHB uses specific events/triggers for payment processing.
        // We need to hook into the moment *before* the form is submitted for this gateway.
        // 'mphb_before_submit_checkout_form' might be a candidate if it exists,
        // or we might intercept the submit button click for this specific gateway.
  
        // Let's try intercepting the form submission directly when Toss is selected.
        this.$checkoutForm.on('submit', this.handleFormSubmit.bind(this));
  
        // Alternative: MPHB might trigger a specific JS event per gateway. Check MPHB docs/code.
        // Example: $(document.body).on('mphb_checkout_process_payment_' + this.gatewayId, this.processPayment.bind(this));
      },
  
      isTossSelected() {
        // Check if the Toss Payments Card radio button is selected
        const $selectedGateway = $('input[name="mphb_payment_gateway"]:checked');
        return $selectedGateway.length && $selectedGateway.val() === this.gatewayId;
      },
  
      handleFormSubmit(event) {
        // Only intercept if Toss Payments Card is selected
        if (!this.isTossSelected()) {
          console.log('TossPayments Card: Not selected, allowing default submission.');
          return true; // Allow default submission for other gateways
        }
  
        // Prevent default form submission
        event.preventDefault();
  
        if (this.isProcessing) {
          console.log('TossPayments Card: Already processing.');
          return false;
        }
        this.isProcessing = true;
        this.blockForm();
        console.log('TossPayments Card: Form submission intercepted.');
  
        // Get payment data prepared by the PHP `getCheckoutData` method
        // This data is usually attached to the payment gateway radio button or a hidden div
        const $gatewayInput = $(`input[name="mphb_payment_gateway"][value="${this.gatewayId}"]`);
        const paymentData = $gatewayInput.data('checkout_data');
  
        if (!paymentData) {
           console.error('TossPayments Card: Could not find checkout data.');
           this.showError(mphbTossPaymentsCardParams.i18n.payment_error);
           this.unblockForm();
           this.isProcessing = false;
           return false;
        }
  
         // Double check required fields from localized data
         if (!paymentData.client_key || !paymentData.toss_order_id || !paymentData.toss_order_name || !paymentData.amount || !paymentData.success_url || !paymentData.fail_url) {
              console.error('TossPayments Card: Missing essential payment data.', paymentData);
              this.showError(mphbTossPaymentsCardParams.i18n.payment_error); // Show generic error
              this.unblockForm();
              this.isProcessing = false;
              return false;
         }

        this.requestTossPayment(paymentData);
  
        return false; // Prevent default form submission
      },
  
      async requestTossPayment(data) {
        console.log('TossPayments Card: Requesting payment with data:', data);
  
         const customerKey = 'cust_' + data.toss_order_id; // Simple unique customer key per attempt
  
         try {
           // Use the V2 SDK method 'payment'
           const payment = this.tossPayments.payment({
             customerKey: customerKey
           });
  
           await payment.requestPayment({
              method: "CARD", // Fixed to Card for this gateway
              amount: {
                  currency: "KRW", // Assuming KRW from PHP check
                  value: data.amount
              },
              orderId: data.toss_order_id,
              orderName: data.toss_order_name,
              successUrl: data.success_url,
              failUrl: data.fail_url,
              customerEmail: data.customer_email || undefined, // Pass if available
              customerName: data.customer_name || undefined,  // Pass if available
              card: { // Optional card settings
                  useEscrow: false,
                  flowMode: "DEFAULT", // Use default flow (redirect)
                  useCardPoint: false,
                  useAppCardOnly: false,
              },
           });
           // If requestPayment is successful, Toss SDK handles the redirect automatically.
           // No further action needed here until the user returns to the callback URL.
           console.log('TossPayments Card: Redirecting to Toss Payments...');
           // Don't unblock the form here, redirect is happening.
  
         } catch (error) {
              // Handle errors from requestPayment (e.g., invalid config, network issues before redirect)
              console.error('TossPayments Card: requestPayment failed.', error);
              const errorMessage = error.message || mphbTossPaymentsCardParams.i18n.request_failed;
              this.showError(errorMessage);
              this.unblockForm();
              this.isProcessing = false;
         }
      },
  
      blockForm() {
        // Use MPHB's blocking mechanism if available, otherwise use a simple overlay
        if ($.fn.mphbBlock) {
           this.$checkoutForm.mphbBlock();
        } else {
           this.$checkoutForm.block({
              message: null,
              overlayCSS: { background: '#fff', opacity: 0.6 }
           });
        }
      },
  
      unblockForm() {
        if ($.fn.mphbUnblock) {
           this.$checkoutForm.mphbUnblock();
        } else {
            this.$checkoutForm.unblock();
        }
      },
  
      showError(message) {
         // Try to use MPHB's error display mechanism
         const $errorsDiv = $('.mphb-errors-wrap', this.$checkoutForm); // Adjust selector if needed
         if ($errorsDiv.length) {
             $errorsDiv.empty().append(`<div class="mphb-error-message">${message}</div>`).show();
             // Scroll to errors
             $('html, body').animate({
                 scrollTop: $errorsDiv.offset().top - 100
             }, 500);
         } else {
             // Fallback to alert
             alert(message);
         }
      }
  
    };
  
    // Initialize the handler on document ready
    $(document).ready(function() {
        tossHandler.init();
    });
  
  });
  