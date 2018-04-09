<?php
/*
   Plugin Name: Модульбанк для WooCommerce
   Description: Платежный модуль для оплаты через Модульбанк через плагин WooCommerce
   Version: 2.0
*/

function init_modulbank() {
    if (!class_exists('FPaymentsForm')) {
        include(dirname(__FILE__) . '/inc/fpayments.php');
    }

    class ModulbankCallback extends AbstractFPaymentsCallbackHandler {
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
            $order->payment_complete();
        }
        protected function mark_order_as_error($order, array $data) {
            //
        }
    }

    class WC_Gateway_Modulbank extends WC_Payment_Gateway {
        private $callback_url;

        function __construct() {
            $this->id = FPaymentsConfig::PREFIX;
            $this->method_title = FPaymentsConfig::NAME;

            $this->callback_url = get_home_url() . '/?modulbank=callback';

            $this->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];

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
                    'title' => __('Включить/Выключить', 'modulbank'),
                    'type' => 'checkbox',
                    'label' => 'Включено',
                    'default' => 'yes',
                    'description' => 'ВАЖНО: необходимо прописать callback_url: "' . $this->callback_url . '"',
                ),
                'merchant_id' => array(
                    'title' => 'Merchant ID',
                    'type' => 'text',
                    'description' => __('ID магазина (из панели управления)', 'modulbank'),
                    'default' => '',
                ),
                'secret_key' => array(
                    'title' => 'Secret key',
                    'type' => 'text',
                    'description' => __('Секретный ключ магазина (из панели управления)', 'modulbank'),
                    'default' => '',
                ),
                'success_url' => array(
                    'title' => 'Страница «платёж прошёл»',
                    'type' => 'text',
                    'default' => FPaymentsForm::abs('/success'),
                ),
                'fail_url' => array(
                    'title' => 'Страница «платёж не удался»',
                    'type' => 'text',
                    'default' => FPaymentsForm::abs('/fail'),
                ),
                'test_mode' => array(
                    'title' => __('Тестовый режим', 'modulbank'),
                    'type' => 'checkbox',
                    'label' => __('Тестовый режим', 'modulbank'),
                    'default' => 'yes',
                ),
                'title' => array(
                    'title' => __('Заголовок', 'modulbank'),
                    'type' => 'text',
                    'description' => __('Название, которое пользователь видит во время оплаты', 'modulbank'),
                    'default' => FPaymentsConfig::NAME,
                ),
                'description' => array(
                    'title' => __('Описание', 'modulbank'),
                    'type' => 'textarea',
                    'description' => __('Описание, которое пользователь видит во время оплаты', 'modulbank'),
                    'default' => '',
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
            return new FPaymentsForm(
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
            return add_query_arg( $_SERVER['QUERY_STRING'], '', get_home_url($_SERVER['REQUEST_URI']) . '/');
        }
    }

    function add_modulbank_gateway_class( $methods ) {
        $methods[] = 'WC_Gateway_Modulbank';
        return $methods;
    }

    add_filter( 'woocommerce_payment_gateways', 'add_modulbank_gateway_class' );

    add_action('parse_request', 'parse_modulbank_request');

    function parse_modulbank_request() {
        if (array_key_exists('modulbank', $_GET)) {
            $gw = new WC_Gateway_Modulbank();
            if ($_GET['modulbank'] == 'callback') {
                $callback_handler = new ModulbankCallback($gw);
                $callback_handler->show($_POST);
            } elseif ($_GET['modulbank'] == 'submit') {
                $order = wc_get_order($_GET['order_id']);
                $ff = $gw->get_fpayments_form();
                $meta = '';
                $description = '';

                $receipt_contact = $order->get_billing_email() ?: $order->get_billing_phone() ?: '';
                $receipt_items = array();
                $order_items = $order->get_items();
                foreach( $order_items as $product ) {
                    $receipt_items[] = new FPaymentsRecieptItem(
                        $product->get_name(),
                        $product->get_total() / $product->get_quantity(),
                        $product->get_quantity()
                    );
                }
                $shipping_total = $order->get_shipping_total();
                if ($shipping_total) {
                    $receipt_items[] = new FPaymentsRecieptItem('Доставка', $shipping_total);
                }

                $data = $ff->compose(
                    $order->get_total(),
                    $order->get_currency(),
                    $order->get_id(),
                    $order->get_billing_email(),
                    '',  # name
                    $order->get_billing_phone(),
                    $gw->settings['success_url'],
                    $gw->settings['fail_url'],
                    $gw->get_return_url($order),
                    $meta,
                    $description,
                    $receipt_contact,
                    $receipt_items
                );

                $order->update_status( 'on-hold', 'Начало оплаты...');
                $order->reduce_order_stock();
                WC()->cart->empty_cart();

                $templates_dir = dirname(__FILE__) . '/templates/';
                include $templates_dir . 'submit.php';
            } else {
                echo("wrong action");
            }
            die();
        }
    }
}

add_action( 'plugins_loaded', 'init_modulbank');
