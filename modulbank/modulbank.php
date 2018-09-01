<?php
/*
   Plugin Name: Оплата через Модульбанк
   Description: Платежный модуль WooCommerce для приема платежей с помощью Модульбанка.
   Version: 0.1
*/


use FPayments\AbstractCallbackHandler;
use FPayments\ModuleConfig;
use FPayments\PaymentForm;
use FPayments\ReceiptItem;

function init_modulbank() {
    if (!class_exists('PaymentForm')) {
        include(dirname(__FILE__) . '/inc/fpayments.php');
    }

    class ModulbankCallback extends AbstractCallbackHandler {
        private $plugin;
        function __construct(WC_Gateway_Modulbank $plugin)  {
            $this->plugin = $plugin;
        }
        protected function get_fpayments_form()             {
            return $this->plugin->get_fpayments_form();
        }
        protected function load_order($order_id) {
            return wc_get_order($order_id);
        }
        protected function get_order_currency($order) {
            return $order->get_currency();
        }
        protected function get_order_amount($order) {
            return $order->get_total();
        }
        protected function is_order_completed($order) {
            return $order->is_paid();
        }
        protected function mark_order_as_completed($order, array $data) {
            WC()->cart->empty_cart();
            wc_reduce_stock_levels($order->get_id());
            $order->payment_complete($data['transaction_id']);
            return true;
        }
        protected function mark_order_as_error($order, array $data) {
            $order->update_status('failed', $data['message']);
            return true;
        }
    }

    class WC_Gateway_Modulbank extends WC_Payment_Gateway {
        function __construct() {
            $this->id = ModuleConfig::PREFIX;
            $this->method_title = __("Модульбанк");
            $this->method_description = __("Оплата банковскими картами");


            $this->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();

            $this->title = __("Оплата банковской картой через Модульбанк");
            $this->description = '';

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action(
                    'woocommerce_update_options_payment_gateways_'.$this->id,
                    array($this, 'process_admin_options')
                );
            } else {
                add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
            }
            add_action('woocommerce_receipt_'.$this->id, array($this, 'receipt_page'));
        }

        function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Платежный метод активен', 'modulbank'),
                    'type' => 'checkbox',
                    'label' => ' ',
                    'default' => 'yes',
                    'description' => '',
                ),
                'merchant_id' => array(
                    'title' => 'Идентификатор магазина',
                    'type' => 'text',
                    'description' => __('merchant_id из личного кабинета Модульбанка', 'modulbank'),
                    'default' => '',
                ),
                'secret_key' => array(
                    'title' => 'Секретный ключ',
                    'type' => 'text',
                    'description' => __('secret_key из личного кабинета Модульбанка', 'modulbank'),
                    'default' => '',
                ),
                'test_mode' => array(
                    'title' => __('Тестовый режим', 'modulbank'),
                    'type' => 'checkbox',
                    'label' => __('Тестовый режим', 'modulbank'),
                    'default' => 'yes',
                    'description' => __('Тестовый режим используется для проверки работы интеграции. При выполнении тестовых транзакций реального зачисления среств на счет магазина не производится.',
                        'modulbank'
                    ),
                ),
            );
        }

        /**
         *  There are no payment fields, but we want to show the description if set.
         */
        public function payment_fields() {
            if ($this->description) {
                echo wpautop(wptexturize($this->description));
            }
        }

        public function get_fpayments_form() {
            return new PaymentForm(
                $this->settings['merchant_id'],
                $this->settings['secret_key'],
                boolval($this->settings['test_mode']),
                '',
                'WordPress ' . get_bloginfo('version')
            );
        }

        public function process_payment( $order_id ) {
            return array(
                'result'    => 'success',
                'redirect'  => get_home_url() . '/?modulbank=submit&order_id='. $order_id,
            );
        }

        function get_current_url() {
            return add_query_arg($_SERVER['QUERY_STRING'], '', get_home_url($_SERVER['REQUEST_URI']) . '/');
        }

        function get_transaction_url($order) {
            $transaction_id = $order->get_transaction_id();
            $url = ModuleConfig::HOST
                . '/account/merchants/'
                . $this->settings['merchant_id']
                . '/transactions/?q='.$transaction_id;

            return apply_filters('woocommerce_get_transaction_url', $url, $order, $this );
        }
    }

    function add_modulbank_gateway_class( $methods ) {
        $methods[] = 'WC_Gateway_Modulbank';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_modulbank_gateway_class');
    add_action('parse_request', 'parse_modulbank_request');

    /**
     * Возвращает код налога для класса налога WC
     * @param string класс налога
     * @return string код налога (напр. vat18)
     */
    function _tax_class_to_vat_code($class) {
        $vat_code = 'none';
        if (wc_tax_enabled()) {
            $tax_info = WC_Tax::get_rates($class);
            $first_item = reset($tax_info);  // the first element can have 0 or 1 index.
            $tax_rate = $first_item['rate'];
            if ($tax_rate) {
                $vat_code = ReceiptItem::guess_vat($tax_rate);
            }

            if (!$vat_code) {
                die("Неподдерживаемый тип налога со ставкой $tax_rate%");
            }
        }
        return $vat_code;
    }

    function parse_modulbank_request() {
        if (array_key_exists('modulbank', $_GET)) {
            $gw = new WC_Gateway_Modulbank();
            if ($_GET['modulbank'] == 'callback') {
                $callback_handler = new ModulbankCallback($gw);
                $callback_handler->show($_POST);
            } elseif ($_GET['modulbank'] == 'submit') {
                $order = wc_get_order($_GET['order_id']);
                $meta = '';
                $description = '';

                $receipt_contact = $order->get_billing_email() ?: $order->get_billing_phone() ?: '';
                $receipt_items = array();

                foreach($order->get_items() as $order_item) {
                    $product = $order_item->get_product();
                    $tax_class = $product->get_tax_class();
                    $vat_code = _tax_class_to_vat_code($tax_class);

                    $price = $order_item->get_total() + $order_item->get_total_tax();
                    $receipt_items[] = new ReceiptItem(
                        $order_item->get_name(),
                        $price / $order_item->get_quantity(),
                        $order_item->get_quantity(),
                        $vat_code
                    );
                }

                foreach ($order->get_shipping_methods() as $shipping_item) {
                    $tax_class = $shipping_item->get_tax_class();
                    $vat_code = _tax_class_to_vat_code($tax_class);

                    $receipt_items[] = new ReceiptItem(
                        $shipping_item->get_name(),
                        $shipping_item->get_total() + $shipping_item->get_total_tax(),
                        1,
                        $vat_code
                    );
                }

                $ff = $gw->get_fpayments_form();
                $ff->enable_callback_on_failure();

                $data = $ff->compose(
                    $order->get_total(),
                    $order->get_currency(),
                    $order->get_id(),
                    $order->get_billing_email(),
                    '',  # name
                    $order->get_billing_phone(),
                    $gw->get_return_url($order),
                    $order->get_cancel_order_url(),
                    $order->get_cancel_order_url(),
                    get_home_url() . '/?modulbank=callback',
                    $meta,
                    $description,
                    $receipt_contact,
                    $receipt_items
                );

                $order->update_status('pending', 'Начало оплаты...');
                $templates_dir = dirname(__FILE__) . '/templates/';
                include $templates_dir . 'submit.php';
            } else {
                echo('wrong action');
            }
            die();
        }
    }
}

add_action('plugins_loaded', 'init_modulbank');
