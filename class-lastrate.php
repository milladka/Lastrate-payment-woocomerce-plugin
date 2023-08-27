<?php
if (!defined('ABSPATH'))
    exit;
function Load_LastRate_Gateway()
{
    if (class_exists('WC_Payment_Gateway') && !class_exists('WC_LastRate') && !function_exists('Woocommerce_Add_ETE_Gateway')) {


        add_action('wp_head', 'custom_styles', 100);
        function custom_styles()
        {
            echo "<style>.woocommerce-input-wrapper .description{font-size: 12px;}.alert-price{text-align: center;
                background: #e4fbda;
                padding: 10px;
                border-radius: 3px;
                font-size: 14px;
                font-weight: bold;}</style>";
        }

        add_filter('woocommerce_payment_gateways', 'Woocommerce_Add_ETE_Gateway');
        function Woocommerce_Add_ETE_Gateway($methods)
        {
            $methods[] = 'WC_LastRate';
            return $methods;
        }

        add_filter('woocommerce_gateway_description', 'gateway_bacs_appended_custom_text_fields', 10, 2);
        function gateway_bacs_appended_custom_text_fields($description, $payment_id)
        {
            if ($payment_id === 'WC_LastRate') {

                global $woocommerce;
                $carttotal = $woocommerce->cart->total;

                $wc_gateways    = new WC_Payment_Gateways();

                $payment_gateways   = $wc_gateways::instance();
                
                $payment_gateway    = $payment_gateways->payment_gateways()['WC_LastRate'];

                $result = '';
                try {
                    $ch = curl_init('http://65.21.198.139:8130/api/getway/v1/price?merchantId=' . $payment_gateway->settings['merchantcode']);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json'
                    ));

                    $result = curl_exec($ch);
                } catch (Exception $ex) {
                    return false;
                }

                $result = json_decode($result, true);
                $rate = $result['result']['merchantBuyPrice'];

                $price = $carttotal * $rate;
                $price = ceil($price);

                ob_start(); // Start buffering

                echo '<div class="bacs-fields" style="padding:10px 0;">';

                echo '<div class="alert-price">مبلغ پرداختی: '. number_format($price, 0) .' تومان</div>';
                
                echo '<div>';

                echo '<div class="bacs-fields" style="padding:10px 0;">';

                woocommerce_form_field('cardnumber', array(
                    'type'          => 'text',
                    'maxlength'     => 16,
                    'description'   => 'شماره کارتی که قصد دارید با آن پرداخت کنید را وارد کنید',
                    'label'         => 'شماره کارت',
                    'class'         => array('form-row-wide'),
                    'required'      => true,
                ), '');

                echo '<div>';

                $description .= ob_get_clean(); // Append  buffered content
            }
            return $description;
        }

        add_action('woocommerce_checkout_process', 'bacs_option_validation');
        function bacs_option_validation()
        {
            if (
                isset($_POST['payment_method']) && $_POST['payment_method'] === 'WC_LastRate'
                && isset($_POST['cardnumber']) && empty($_POST['cardnumber'])
            ) {
                wc_add_notice('لطفا فیلد شماره کارت را با شماره کارتی که قصد دارید با آن پرداخت کنید، کامل کنید', 'error');
            }
        }

        // Checkout custom field save to order meta
        add_action('woocommerce_checkout_create_order', 'save_bacs_option_order_meta', 10, 2);
        function save_bacs_option_order_meta($order, $data)
        {
            if (isset($_POST['cardnumber']) && !empty($_POST['cardnumber'])) {
                $order->update_meta_data('_cardnumber', esc_attr($_POST['cardnumber']));
            }
        }

        add_action('woocommerce_get_order_item_totals', 'display_bacs_option_on_order_totals', 10, 3);
        function display_bacs_option_on_order_totals($total_rows, $order, $tax_display)
        {
            if ($order->get_payment_method() === 'bacs' && $cardnumber = $order->get_meta('_cardnumber')) {
                $sorted_total_rows = [];

                foreach ($total_rows as $key_row => $total_row) {
                    $sorted_total_rows[$key_row] = $total_row;
                    if ($key_row === 'payment_method') {
                        $sorted_total_rows['cardnumber'] = [
                            'label' => 'شماره کارت',
                            'value' => esc_html($cardnumber),
                        ];
                    }
                }
                $total_rows = $sorted_total_rows;
            }
            return $total_rows;
        }

        // Display custom field in Admin orders, below billing address block
        add_action('woocommerce_admin_order_data_after_billing_address', 'display_bacs_option_near_admin_order_billing_address', 10, 1);
        function display_bacs_option_near_admin_order_billing_address($order)
        {
            if ($cardnumber = $order->get_meta('_cardnumber')) {
                echo '<div class="bacs-option">
                        <p><strong>' . 'شماره کارت' . ':</strong> ' . $cardnumber . '</p>
                    </div>';
            }
        }


        class WC_LastRate extends WC_Payment_Gateway
        {
            public function __construct()
            {
                $this->id = 'WC_LastRate';
                $this->statusCode = '';
                $this->method_title = 'پرداخت لست ریت';
                $this->method_description = 'تنظیمات درگاه پرداخت لست ریت برای ووکامرس';
                $this->icon = apply_filters('WC_LastRate_logo', WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/assets/images/logo.png');
                $this->has_fields = false;
                $this->init_form_fields();
                $this->init_settings();
                $this->title = 'پرداخت لست ریت';
                $this->description = 'پرداخت از طریق درگاه لست ریت';
                $this->merchantCode = $this->settings['merchantcode'];
                $this->key = $this->settings['key'];
                $this->iv = $this->settings['iv'];
                $this->successMassage = $this->settings['success_massage'];
                $this->failedMassage = $this->settings['failed_massage'];


                $this->title = 'پرداخت با لست ریت';
                $this->description = 'پرداخت از طریق درگاه لست ریت';

                if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
                    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                else
                    add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));

                add_action('woocommerce_receipt_' . $this->id . '', array($this, 'Send_to_LastRate_Gateway'));
                add_action('woocommerce_api_' . strtolower(get_class($this)) . '', array($this, 'Return_from_LastRate_Gateway'));
            }
            public function admin_options()
            {
                parent::admin_options();
            }
            public function init_form_fields()
            {
                $this->form_fields = apply_filters(
                    'WC_LastRate_Config',
                    array(
                        'base_confing' => array(
                            'title' => 'تنظیمات پایه ای',
                            'type' => 'title',
                            'description' => '',
                        ),
                        'enabled' => array(
                            'title' => 'فعال سازی',
                            'type' => 'checkbox',
                            'label' => 'فعال سازی درگاه در سایت',
                            'description' => 'برای فعال سازی گزینه را تیک بزنید',
                            'default' => 'yes',
                            'desc_tip' => true,
                        ),
                        'account_confing' => array(
                            'title' => 'تنظیمات حساب',
                            'type' => 'title',
                            'description' => '',
                        ),
                        'merchantcode' => array(
                            'title' => 'مرچنت کد',
                            'type' => 'text',
                            'description' => 'کد مرچنت از پشتیبانی دریافت کنید',
                            'default' => '',
                            'desc_tip' => true
                        ),
                        'key' => array(
                            'title' => 'کلید اصلی',
                            'type' => 'text',
                            'description' => 'کلید اصلی از پشتیبانی دریافت کنید',
                            'default' => '',
                            'desc_tip' => true
                        ),
                        'iv' => array(
                            'title' => 'کلید iv',
                            'type' => 'text',
                            'description' => 'کلید iv از پشتیبانی دریافت کنید',
                            'default' => '',
                            'desc_tip' => true
                        ),
                        'payment_config' => array(
                            'title' => 'تنظیمات پیام ها',
                            'type' => 'title',
                            'description' => '',
                        ),
                        'success_massage' => array(
                            'title' => __('پیام پرداخت موفق', 'woocommerce'),
                            'type' => 'textarea',
                            'description' => __('متن پیامی که میخواهید بعد از پرداخت موفق به کاربر نمایش دهید را وارد نمایید . همچنین می توانید از شورت کد {transaction_id} برای نمایش کد رهگیری لست ریت استفاده نمایید .', 'woocommerce'),
                            'default' => __('با تشکر از شما . سفارش شما با موفقیت پرداخت شد .', 'woocommerce'),
                        ),
                        'failed_massage' => array(
                            'title' => __('پیام پرداخت ناموفق', 'woocommerce'),
                            'type' => 'textarea',
                            'description' => __('متن پیامی که میخواهید بعد از پرداخت ناموفق به کاربر نمایش دهید را وارد نمایید . همچنین می توانید از شورت کد {fault} برای نمایش دلیل خطای رخ داده استفاده نمایید . این دلیل خطا از سایت لست ریت ارسال میگردد .', 'woocommerce'),
                            'default' => __('پرداخت شما ناموفق بوده است . لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید .', 'woocommerce'),
                        ),
                    )
                );
            }
            public function process_payment($order_id)
            {
                $order = new WC_Order($order_id);
                return array(
                    'result' => 'success',
                    'redirect' => $order->get_checkout_payment_url(true)
                );
            }

            /**
             * @param $action (PaymentRequest, )
             * @param $params string
             *
             * @return mixed
             */
            public function SendRequestToLastRate($action, $params)
            {
                try {
                    $ch = curl_init('http://65.21.198.139:8130/' . $action);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($params)
                    ));

                    $result = curl_exec($ch);
                    $this->statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    return $result;
                } catch (Exception $ex) {
                    return false;
                }
            }

            /**
             *
             * @return mixed
             */
            public function GetPriceLastRate()
            {
                try {
                    $ch = curl_init('http://65.21.198.139:8130/api/getway/v1/price?merchantId=' . $this->merchantCode);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json'
                    ));

                    $result = curl_exec($ch);
                    $this->statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    return $result;
                } catch (Exception $ex) {
                    return false;
                }
            }

            public function Send_to_LastRate_Gateway($order_id)
            {
                global $woocommerce;

                $woocommerce->session->order_id_LastRate = $order_id;
                $order = new WC_Order($order_id);

                $currency = $order->get_currency();

                $currency = apply_filters('WC_LastRate_Currency', $currency, $order_id);
                $form = '<form action="" method="POST" class="LastRate-checkout-form" id="LastRate-checkout-form">
                            <input type="submit" name="LastRate_submit" class="button alt" id="LastRate-payment-button" value="' . __('پرداخت', 'woocommerce') . '"/>
                            <a class="button cancel" href="' . $woocommerce->cart->get_checkout_url() . '">' . __('بازگشت', 'woocommerce') . '</a>
                         </form><br/>';
                $form = apply_filters('WC_LastRate_Form', $form, $order_id, $woocommerce);
                do_action('WC_LastRate_Gateway_Before_Form', $order_id, $woocommerce);
                echo $form;
                do_action('WC_LastRate_Gateway_After_Form', $order_id, $woocommerce);

                $Amount = (int)$order->order_total;

                $CallbackUrl = add_query_arg('wc_order', $order_id, WC()->api_request_url('WC_LastRate'));

                $Description = 'خرید به شماره سفارش : ' . $order->get_order_number() . ' | خریدار : ' . $order->billing_first_name . ' ' . $order->billing_last_name;
                $Mobile = get_post_meta($order_id, '_billing_phone', true) ? get_post_meta($order_id, '_billing_phone', true) : '-';
                $Description = apply_filters('WC_LastRate_Description', $Description, $order_id);
                $Mobile = preg_match('/^09[0-9]{9}/i', $Mobile) ? $Mobile : '';
                $Mobile = apply_filters('WC_LastRate_Mobile', $Mobile, $order_id);
                do_action('WC_LastRate_Gateway_Payment', $order_id, $Description, $Mobile);

                $orders = wc_get_order($order_id);

                $cardnumber = get_post_meta( $order_id, '_cardnumber', true );

                $getPrice = $this->GetPriceLastRate();
                $getPrice = json_decode($getPrice, true);
                $rate = $getPrice['result']['merchantBuyPrice'];

                $price = $orders->total * $rate;
                $price = ceil($price);

                $order_id = (string)$order_id;

                $data = $this->merchantCode . '*' . $price . '*' . $order_id . '*IRT';

                $method = 'aes-256-cbc';

                $iv = base64_decode($this->iv);

                $encrypted = base64_encode(openssl_encrypt($data, $method, $this->key, OPENSSL_RAW_DATA, $iv));

                $data = array("merchantId" =>  $this->merchantCode, "invoiceNumber" => $order_id, "currencySymbol" => "IRT", "currencyName" => "Toman", "cardNumber" => $cardnumber, "amount" => $price, "signData" => $encrypted);

                $result = $this->SendRequestToLastRate('api/getway/v1/pay', json_encode($data));

                if ($this->statusCode == 400) {
                    $result = json_decode($result, true);
                    $Fault = '';
                    $Message = ' تراکنش ناموفق بود : ' . $result['error']['message'];
                    $Note = sprintf(__('خطا در هنگام ارسال : %s', 'woocommerce'), $Message);
                    $Note = apply_filters('WC_LastRate_Send_to_Gateway_Failed_Note', $Note, $order_id, $Fault);
                    $order->add_order_note($Note);
                    $Notice = sprintf(__('در هنگام اتصال خطای زیر رخ داده است : <br/>%s', 'woocommerce'), $Message);
                    $Notice = apply_filters('WC_LastRate_Send_to_Gateway_Failed_Notice', $Notice, $order_id, $Fault);
                    wc_add_notice($Message, 'error');

                    if ($Notice) {
                        wc_add_notice($Notice, 'error');
                    }

                    do_action('WC_LastRate_Send_to_Gateway_Failed', $order_id, $Fault);
                } else if ($this->statusCode == 200) {
                    $result = json_decode($result, true);
                    wp_redirect($result['result']['url']);
                    exit;
                }
            }

            public function Return_from_LastRate_Gateway()
            {

                $InvoiceNumber = $_GET['invoiceNumber'];
                global $woocommerce;
                if (isset($_GET['wc_order'])) {
                    $order_id = $_GET['wc_order'];
                } else if ($InvoiceNumber) {
                    $order_id = $InvoiceNumber;
                } else {
                    $order_id = $woocommerce->session->order_id_LastRate;
                    unset($woocommerce->session->order_id_LastRate);
                }
                if ($order_id) {
                    $order = new WC_Order($order_id);
                    $currency = $order->get_currency();
                    $currency = apply_filters('WC_LastRate_Currency', $currency, $order_id);

                    if ($order->status !== 'completed') {
                        if ($_GET['status'] == 1) {

                            $data = $this->merchantCode . '#' . $_GET['trackingCode'];

                            $method = 'aes-256-cbc';

                            $iv = base64_decode($this->iv);

                            $encrypted = base64_encode(openssl_encrypt($data, $method, $this->key, OPENSSL_RAW_DATA, $iv));

                            $data = array("merchant" => $this->merchantCode, "trackingCode" => $_GET['trackingCode'], "signData" => $encrypted);

                            $result = $this->SendRequestToLastRate('api/getway/v1/verify', json_encode($data));
                            if ($this->statusCode == 400) {
                                $Status = 'failed';
                                $Fault = $this->statusCode;
                                $Message = 'تراکنش ناموفق بود';
                            } else if ($this->statusCode == 200) {
                                $Status = 'completed';
                                $Fault = '';
                                $Message = '';
                                $Transaction_ID = $_GET['trackingCode'];
                            } else {
                                $Status = 'failed';
                                $Fault = $this->statusCode;
                                $Message = 'تراکنش ناموفق بود';
                            }
                        } else {
                            $Status = 'failed';
                            $Fault = '';
                            $Message = 'تراکنش انجام نشد .';
                        }
                        if ($Status === 'completed') {

                            update_post_meta($order_id, '_transaction_id', $Transaction_ID);
                            $order->payment_complete($Transaction_ID);
                            $woocommerce->cart->empty_cart();
                            $Note = sprintf(__('پرداخت موفقیت آمیز بود .<br/> کد رهگیری : %s', 'woocommerce'), $Transaction_ID);
                            $Note = apply_filters('WC_LastRate_Return_from_Gateway_Success_Note', $Note, $order_id, $Transaction_ID);
                            if ($Note)
                                $order->add_order_note($Note, 1);

                            $Notice = wpautop(wptexturize($this->successMassage));
                            $Notice = str_replace('{transaction_id}', $Transaction_ID, $Notice);
                            $Notice = apply_filters('WC_LastRate_Return_from_Gateway_Success_Notice', $Notice, $order_id, $Transaction_ID);
                            if ($Notice)
                                wc_add_notice($Notice, 'success');
                            do_action('WC_LastRate_Return_from_Gateway_Success', $order_id, $Transaction_ID);
                            wp_redirect(add_query_arg('wc_status', 'success', $this->get_return_url($order)));
                            exit;
                        }


                        if (($Transaction_ID && ($Transaction_ID != 0))) {
                            $tr_id = ('<br/>توکن : ' . $Transaction_ID);
                        } else {
                            $tr_id = '';
                        }
                        $Note = sprintf(__('خطا در هنگام بازگشت از بانک : %s %s', 'woocommerce'), $Message, $tr_id);
                        $Note = apply_filters('WC_LastRate_Return_from_Gateway_Failed_Note', $Note, $order_id, $Transaction_ID, $Fault);
                        if ($Note) {
                            $order->add_order_note($Note, 1);
                        }
                        $Notice = wpautop(wptexturize($this->failedMassage));
                        $Notice = str_replace(array('{transaction_id}', '{fault}'), array($Transaction_ID, $Message), $Notice);
                        $Notice = apply_filters('WC_LastRate_Return_from_Gateway_Failed_Notice', $Notice, $order_id, $Transaction_ID, $Fault);
                        if ($Notice) {
                            wc_add_notice($Notice, 'error');
                        }
                        do_action('WC_LastRate_Return_from_Gateway_Failed', $order_id, $Transaction_ID, $Fault);

                        $order->cancel_order();
                        wp_redirect($woocommerce->cart->get_checkout_url());
                        exit;
                    }
                    $Transaction_ID = get_post_meta($order_id, '_transaction_id', true);
                    $Notice = wpautop(wptexturize($this->successMassage));
                    $Notice = str_replace('{transaction_id}', $Transaction_ID, $Notice);
                    $Notice = apply_filters('WC_LastRate_Return_from_Gateway_ReSuccess_Notice', $Notice, $order_id, $Transaction_ID);
                    if ($Notice) {
                        wc_add_notice($Notice, 'success');
                    }
                    do_action('WC_LastRate_Return_from_Gateway_ReSuccess', $order_id, $Transaction_ID);
                    wp_redirect(add_query_arg('wc_status', 'success', $this->get_return_url($order)));
                    exit;
                }
                $Fault = __('شماره سفارش وجود ندارد .', 'woocommerce');
                $Notice = wpautop(wptexturize($this->failedMassage));
                $Notice = str_replace('{fault}', $Fault, $Notice);
                $Notice = apply_filters('WC_LastRate_Return_from_Gateway_No_Order_ID_Notice', $Notice, $order_id, $Fault);
                if ($Notice) {
                    wc_add_notice($Notice, 'error');
                }
                do_action('WC_LastRate_Return_from_Gateway_No_Order_ID', $order_id, '0', $Fault);
                wp_redirect($woocommerce->cart->get_checkout_url());
                exit;
            }
        }
    }
}
add_action('plugins_loaded', 'Load_LastRate_Gateway', 0);