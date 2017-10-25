<?php
/*
Plugin Name: WooCommerce PaynetEasy Gateway
Plugin URI: http://WooThemes.com/
Description: Extends WooCommerce with an PaynetEasy gateway.
Version: 1.0
Author: WooThemes
Author URI: http://WooThemes.com/
	Copyright: ? 20013-2014 WooThemes.
	License: GNU General Public License v3.0
	License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/


//load payment gateway class
require_once(dirname(__FILE__) . "/classes/_classloader.php");

use Vsb\Pne\Classes\Pne\Exception as PneException;
use Vsb\Pne\Classes\Pne\Connector;
use Vsb\Pne\Classes\Pne\SaleRequest;
use Vsb\Pne\Classes\Pne\ReturnRequest;
use Vsb\Pne\Classes\Pne\CallbackResponse;

add_filter( 'pre_comment_user_ip', 'auto_reverse_proxy_pre_comment_user_ip');

function auto_reverse_proxy_pre_comment_user_ip(){
	$REMOTE_ADDR = $_SERVER['REMOTE_ADDR'];
	if (!empty($_SERVER['X_FORWARDED_FOR'])) {
		$X_FORWARDED_FOR = explode(',', $_SERVER['X_FORWARDED_FOR']);
		if (!empty($X_FORWARDED_FOR)) {
			$REMOTE_ADDR = trim($X_FORWARDED_FOR[0]);
		}
	}
	/*
	* Some php environments will use the $_SERVER['HTTP_X_FORWARDED_FOR']
	* variable to capture visitor address information.
	*/
	elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		$HTTP_X_FORWARDED_FOR= explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
		if (!empty($HTTP_X_FORWARDED_FOR)) {
			$REMOTE_ADDR = trim($HTTP_X_FORWARDED_FOR[0]);
		}
	}
	return preg_replace('/[^0-9a-f:\., ]/si', '', $REMOTE_ADDR);
}

add_action('init','pne_redirect_page');
function pne_redirect_page() {
    if(isset($_GET['payneteasy_cb']) && isset($_GET['order'])) {
        $site_url = get_bloginfo('url');
        $param = array();
        foreach($_GET as $key=>$value) {
            if($key == 'order') {
                $param['order_id'] = $value;
            } else {
                $param[$key] = $value;
            }
        }
        $location = $site_url . '?' . http_build_query($param,'&amp;');
        wp_redirect( $location, $status );
        exit;
	}
}

register_activation_hook(__FILE__, 'paynetEasy');

function paynetEasy() {
    global $wpdb;
    $table_name = $wpdb->prefix . "payneteasy_transactions";
	$sql = "CREATE TABLE $table_name (`id` INT( 11 ) NOT NULL AUTO_INCREMENT ,`oid` INT( 11 ) NOT NULL DEFAULT '0',`type` ENUM( 'Captured', 'Void', 'Refunded', 'schedule', 'deschedule', 'nothing' ) NOT NULL DEFAULT 'nothing',`date` VARCHAR( 255 ) NOT NULL ,`amount` DECIMAL( 20, 2 ) NOT NULL ,PRIMARY KEY ( `id` ));";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

add_action('plugins_loaded', 'woocommerce_payneteasy_init', 0);
function woocommerce_payneteasy_init() {
	if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
	/**
 	 * Localisation
	 */
	load_plugin_textdomain('wc-gateway-payneteasy', false, dirname( plugin_basename( __FILE__ ) ) . '/languages');

	/**
 	 * Gateway class
 	 */
	class WC_Gateway_Payneteasy extends WC_Payment_Gateway {
	       var $notify_url;
    	/**
    	 * Constructor for the gateway.
    	 *
    	 * @access public
    	 * @return void
    	 */
    	public function __construct() {
    		$this->id                = 'payneteasy';
            $this->debug            = true;
    		$this->icon              = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/payneteasy.png';
    		$this->has_fields        = false;
    		$this->order_button_text = __( 'Proceed to PaynetEasy', 'woocommerce' );


    		// $this->signature_url     = "https://lk.payneteasy.eu/gates/signature";//$this->get_option( 'gateway_url' );
    		$this->method_title      = __( 'PaynetEasy', 'woocommerce' );
    		$this->notify_url        = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_Gateway_Payneteasy', home_url( '/' ) ) );
    		// Load the settings.
    		$this->init_form_fields();
    		$this->init_settings();
    		// Define user set variables
    		$this->title 			= $this->get_option( 'title' );
    		$this->description 		= $this->get_option( 'description' );

            $this->gateway_url       = $this->get_option( 'gateway_url' );
            $this->merchant_endpoint = $this->get_option( 'merchant_endpoint' );
            $this->merchant_code 	 = $this->get_option( 'merchant_code' );
            $this->merchant_login 	 = $this->get_option( 'merchant_login' );
            $this->protocol_version  = $this->get_option( 'protocol_version' );

            $this->currency         = $this->get_option( 'pne_currency' );
    		// Logs
    		if ( 'yes' == $this->debug ) {
    			$this->log = new WC_Logger();
    		}
    		// Actions
    		add_action( 'valid-payneteasy-standard-ipn-request', array( $this, 'successful_request' ) );
    	    if(!isset($_GET['order'])) {
    		          add_action( 'woocommerce_receipt_payneteasy', array( $this, 'receipt_page' ) );
                  }
    		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    		add_action( 'woocommerce_thankyou_payneteasy', array( $this, 'pdt_return_handler' ) );
    		// Payment listener/API hook
    		add_action( 'woocommerce_api_wc_gateway_payneteasy', array( $this, 'check_ipn_response' ) );
    		if ( ! $this->is_valid_for_use() ) {
    			$this->enabled = false;
    		}
    	}
	/**
	 * Check if this gateway is enabled and available in the user's country
	 *
	 * @access public
	 * @return bool
	 */
	function is_valid_for_use() {
		if ( ! in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_payneteasy_supported_currencies', array( 'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP', 'RMB', 'RUB' ) ) ) ) {
			return false;
		}
		return true;
	}
	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 */
	public function admin_options() {
		?>
		<h3><?php _e( 'PaynetEasy', 'woocommerce' ); ?></h3>
		<p><?php _e( 'PaynetEasy works by sending the user to PaynetEasy to enter their payment information.', 'woocommerce' ); ?></p>
		<?php if ( $this->is_valid_for_use() ) : ?>
			<table class="form-table">
			<?php
				// Generate the HTML For the settings form.
				$this->generate_settings_html();
			?>
			</table><!--/.form-table-->
		<?php else : ?>
			<div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woocommerce' ); ?></strong>: <?php _e( 'PaynetEasy does not support your store currency.', 'woocommerce' ); ?></p></div>
		<?php
			endif;
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @access public
	 * @return void
	 */
	function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable PaynetEasy', 'woocommerce' ),
				'default' => 'yes'
			),
			'title' => array(
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( 'PaynetEasy', 'woocommerce' ),
				'desc_tip'    => false,
			),
			'description' => array(
				'title'       => __( 'Description', 'woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( 'Pay via PaynetEasy with your bank card', 'woocommerce' )
			),
			'gateway_url' => array(
				'title'       => __( 'Gateway Url', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Your PaynetEasy URL.', 'woocommerce' ),
				'default'     => 'https://sandbox.libill.com/paynet/api/',
				'desc_tip'    => false,
				'placeholder' => 'Gateway URL'
			),
			'merchant_code' => array(
				'title'       => __( 'Merchant Key', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Your PaynetEasy merchant key.', 'woocommerce' ),
				'default'     => 'DB3C4FE7-1D1B-4106-8E36-1F5EAC807E34',
				'desc_tip'    => false,
				'placeholder' => 'Merchant Key'
			),
			'merchant_login' => array(
				'title'       => __( 'User name', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Your PaynetEasy userName.', 'woocommerce' ),
				'default'     => '',
				'desc_tip'    => false,
				'placeholder' => 'User name'
			),
			'merchant_password' => array(
				'title'       => __( 'Password', 'woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Your PaynetEasy Password.', 'woocommerce' ),
				'default'     => '2sgJUF7IHKb4ip7EkA27FEEtCDL4iKDg',
				'desc_tip'    => false,
				'placeholder' => 'Password'
			),
			'merchant_endpoint' => array(
				'title'       => __( 'Merchant endpoint', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Please Type merchantAccountCode.', 'woocommerce' ),

				'default'     => '204',
				'desc_tip'    => false,
				'placeholder' => 'Merchant Account Code'
			),
            'protocol_version' => array(
				'title'       => __( 'Protocol version', 'woocommerce' ),
				'type'        => 'select',
				'description' => __( 'API protocol version', 'woocommerce' ),
				'default'     => '2',
				'desc_tip'    => false,
				'options'     => array(
					'2'      => __( 'V2', 'woocommerce' ),
					'3'      => __( 'V3', 'woocommerce' )
				)
			),
            'pne_currency' => array(
				'title'       => __( 'Currency', 'woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Transaction currency', 'woocommerce' ),
				'default'     => 'RUB',
				'desc_tip'    => false,
				'options'     => array(
					'RUB'      => __( 'RUB', 'woocommerce' ),
					'EUR'      => __( 'EUR', 'woocommerce' ),
					'USD'      => __( 'USD', 'woocommerce' )
				)
			),
            'lang' => array(
				'title'       => __( 'Language', 'woocommerce' ),
				'type'        => 'select',
				'description' => __( 'The language that the PaynetEasy secure processing page will be displayed in.', 'woocommerce' ),
				'default'     => 'RU',
				'desc_tip'    => false,
				'options'     => array(
					'EN'      => __( 'EN', 'woocommerce' ),
					'RU'      => __( 'RU', 'woocommerce' )
				)
			),
			'error_url' => array(
				'title'       => __( 'Error URL', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Your Fail Page URL.', 'woocommerce' ),

				'default'     => '',
				'desc_tip'    => false,
				'placeholder' => 'Error URL'
			),

		);
	}
	/**
	 * Get payneteasy Args for passing to PP
	 *
	 * @access public
	 * @param mixed $order
	 * @return array
	 */
	function get_payneteasy_args( $order ) {
		$order_id = $order->id;
		if ( 'yes' == $this->debug ) {
			$this->log->add( 'payneteasy', 'Generating payment form for order ' . $order->get_order_number() . '. Notify URL: ' . $this->notify_url );
		}

        //Order items
        $orderItems="";
        $orderItemsCount = 0;
        // (code=167;itemNumber=167;description=Товар;quantity=1;price=54995;unitCostAmount=54995;totalAmount=54995)
        foreach ( $order->get_items() as $item_id => $item ) {
            $product                    = $item->get_product();
            $orderItems.="(";
            if(is_object( $product ) && $product->get_sku()!=="")$orderItems.="code=". $product->get_sku().';';
            $orderItems.="itemNumber=".$item_id.';';
            $orderItems.="description=".$item->get_name().';';
            $orderItems.="quantity=".$item->get_quantity().';';
            $orderItems.="price=".(intval(100*$order->get_line_total( $item ))).';';
            $orderItems.="unitCostAmount=".( intval($order->get_line_subtotal( $item )*100)/intval($item->get_quantity()) ).';';
            $orderItems.="totalAmount=".( intval($order->get_line_total( $item )*100) );
            $orderItems.=")";
            $orderItemsCount++;
        }
		// payneteasy Args
        $payneteasy_args = [
            "url" => $this->gateway_url,
            "endpoint" => $this->merchant_endpoint,
            "merchant_key" => $this->merchant_code,
            "merchant_login" => $this->merchant_login,
            "data"=>[
                "client_orderid" => $order_id,
                "order_desc" => 'Order #'.$order_id.' '.site_url(),//$orderItems,                                             // !!!!$description,
                "first_name" => "Payn",
                "last_name" => "Etyeasy",
                "birthday" => "",

                "address1" => $order->get_billing_address_1(),
                "address2" => $order->get_billing_address_2(),
                "city" => $order->get_billing_city(),
                "state" => "",
                "zip_code" => $order->get_billing_postcode(),
                "country" => "RU",
                "phone" => $order->get_billing_phone(),
                "cell_phone" => $order->get_billing_phone(),
                "amount" => $order->get_total(),
                "currency" => $this->currency,
                "email" => $order->get_billing_email(),
                "ipaddress" => auto_reverse_proxy_pre_comment_user_ip(),                                              // !!Request::server('REMOTE_ADDR'),
                "site_url" => site_url(),
                "redirect_url" => $order->get_checkout_order_received_url()//$host.Setting::get('transfer.0.response'),
                // "server_callback_url" =>  $host.Setting::get('transfer.0.callback')
            ]
        ];
        // print_r($payneteasy_args);
		/////////////////////////////////////////////////
		$payneteasy_args = apply_filters( 'woocommerce_payneteasy_args', $payneteasy_args );

		return $payneteasy_args;
	}
	/**
	 * Generate the payneteasy button link
	 *
	 * @access public
	 * @param mixed $order_id
	 * @return string
	 */
	function generate_payneteasy_form( $order_id ) {
		$order = new WC_Order( $order_id );

        /**/

        // Log::debug($data);
        $payneteasy_args = $this->get_payneteasy_args( $order );
        $request = new SaleRequest($payneteasy_args,$this->protocol_version);
        $connector = new Connector();
        $connector->setRequest($request);
        $connector->call();
        $response = $connector->getResponse();

        $retval = $response->toArray();


        if(!empty($retval["redirect-url"])){
            wc_enqueue_js( '
    			$.blockUI({
    					message: "' . esc_js( __( 'Thank you for your order. We are now redirecting you to PaynetEasy to make payment.', 'woocommerce' ) ) . '",
    					baseZ: 99999,
    					overlayCSS:
    					{
    						background: "#fff",
    						opacity: 0.6
    					},
    					css: {
    						padding:        "20px",
    						zindex:         "9999999",
    						textAlign:      "center",
    						color:          "#555",
    						border:         "3px solid #aaa",
    						backgroundColor:"#fff",
    						cursor:         "wait",
    						lineHeight:		"24px",
    					}
    				});
    		jQuery("#submit_payneteasy_payment_form").click();
    		' );
    		$s_form = '<form action="' . esc_url( $retval["redirect-url"] ) . '" method="get" id="payneteasy_payment_form" target="_top">
    				<!-- Button Fallback -->
    				<div class="payment_buttons">
    					<input type="submit" class="button alt" id="submit_payneteasy_payment_form" value="' . __( 'Pay via PaynetEasy', 'woocommerce' ) . '" /> <a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . __( 'Cancel order &amp; restore cart', 'woocommerce' ) . '</a>
    				</div>
    				<script type="text/javascript">
    					//jQuery(".payment_buttons").hide();
    				</script>
    			</form>';
        }
        else print_r($retval);
        return $s_form;
	}
	/**
	 * Process the payment and return the result
	 *
	 * @access public
	 * @param int $order_id
	 * @return array
	 */
	function process_payment( $order_id ) {
		$order = new WC_Order( $order_id );
		if (  $this->form_submission_method ) {
			$payneteasy_args = $this->get_payneteasy_args( $order );
			$payneteasy_args = http_build_query( $payneteasy_args, '', '&' );
			$payneteasy_adr = $this->gateway_url . '?';
			return array(
				'result' 	=> 'success',
				'redirect'	=> $payneteasy_adr . $payneteasy_args
			);
		}else {
			return array(
				'result' 	=> 'success',
				'redirect'	=> $order->get_checkout_payment_url( true )
			);
		}
	}
	/**
	 * Output for the order received page.
	 *
	 * @access public
	 * @return void
	 */
	function receipt_page( $order ) {
		echo '<p>' . __( 'Thank you - your order is now pending payment. You should be automatically redirected to PaynetEasy to make payment.', 'woocommerce' ) . '</p>';
		echo $this->generate_payneteasy_form( $order );
	}
	/**
	 * Check payneteasy IPN validity
	 **/
	function check_ipn_request_is_valid( $ipn_response ) {
		// Get url
		$payneteasy_adr = $this->gateway_url . '?';
        print_r($ipn_response);
		if ( 'yes' == $this->debug ) {
			$this->log->add( 'payneteasy', 'Checking IPN response is valid via ' . $payneteasy_adr . '...' );
		}
		// Get recieved values from post data
		$validate_ipn = array( 'cmd' => '_notify-validate' );
		$validate_ipn .= stripslashes_deep( $ipn_response );
		// Send back post vars to payneteasy
		$params = array(
			'body' 			=> $validate_ipn,
			'sslverify' 	=> false,
			'timeout' 		=> 60,
			'httpversion'   => '1.1',
			'compress'      => false,
			'decompress'    => false,
			'user-agent'	=> 'WooCommerce/' . WC()->version
		);
		if ( 'yes' == $this->debug ) {
			$this->log->add( 'payneteasy', 'IPN Request: ' . print_r( $params, true ) );
		}
		// Post back to get a response
		$response = wp_remote_post( $payneteasy_adr, $params );
		if ( 'yes' == $this->debug ) {
			$this->log->add( 'payneteasy', 'IPN Response: ' . print_r( $response, true ) );
		}
		// check to see if the request was valid
		if ( ! is_wp_error( $response ) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 && ( strcmp( $response['body'], "VERIFIED" ) == 0 ) ) {
			if ( 'yes' == $this->debug ) {
				$this->log->add( 'payneteasy', 'Received valid response from PaynetEasy' );
			}
			return true;
		}
		if ( 'yes' == $this->debug ) {
			$this->log->add( 'payneteasy', 'Received invalid response from PaynetEasy' );
			if ( is_wp_error( $response ) ) {
				$this->log->add( 'payneteasy', 'Error response: ' . $response->get_error_message() );
			}
		}
		return false;
	}

	/**
	 * Successful Payment!
	 *
	 * @access public
	 * @param array $posted
	 * @return void
	 */
	function check_ipn_response( $posted ) {
        error_log("posted:".json_encode($posted));
        print_r([__FILE__,__LINE__]);
        print_r($_REQUEST);
        $posted = stripslashes_deep( $posted );
		// Custom holds post ID
		if ( ! empty( $_REQUEST['transactionCode'] ) ) {
			//$order = $this->get_payneteasy_order( $_REQUEST['transactionCode'] );
			$order = new WC_Order( $_REQUEST['transactionCode'] );
            print_r($order);
			//$this->log->add( 'payneteasy', 'Found order #' . $order->id );
			// Lowercase returned variables
			if(in_array($_REQUEST['responseCode'],['A01'])){
                $order->payment_complete();
            }

		}
        die();
	}
	/**
	 * Successful Payment!
	 *
	 * @access public
	 * @param array $posted
	 * @return void
	 */
	function successful_request( $posted ) {
		$posted = stripslashes_deep( $posted );
		// Custom holds post ID
		if ( ! empty( $posted['transactionCode'] ) ) {
			$order = $this->get_payneteasy_order( $posted['transactionCode'], $posted['invoice'] );
			if ( 'yes' == $this->debug ) {
				$this->log->add( 'payneteasy', 'Found order #' . $order->id );
			}
			// Lowercase returned variables
			$posted['payment_status'] 	= strtolower( $posted['payment_status'] );
			$posted['txn_type'] 		= strtolower( $posted['txn_type'] );
			// Sandbox fix
			if ( 1 == $posted['test_ipn'] && 'pending' == $posted['payment_status'] ) {
				$posted['payment_status'] = 'completed';
			}
			if ( 'yes' == $this->debug ) {
				$this->log->add( 'payneteasy', 'Payment status: ' . $posted['payment_status'] );
			}
			// We are here so lets check status and do actions
			switch ( $posted['payment_status'] ) {
				case 'completed' :
				case 'pending' :
					// Check order not already completed
					if ( $order->status == 'completed' ) {
						if ( 'yes' == $this->debug ) {
							$this->log->add( 'payneteasy', 'Aborting, Order #' . $order->id . ' is already complete.' );
						}
						exit;
					}
					// Check valid txn_type
					$accepted_types = array( 'cart', 'instant', 'express_checkout', 'web_accept', 'masspay', 'send_money' );
					if ( ! in_array( $posted['txn_type'], $accepted_types ) ) {
						if ( 'yes' == $this->debug ) {
							$this->log->add( 'payneteasy', 'Aborting, Invalid type:' . $posted['txn_type'] );
						}
						exit;
					}
					// Validate currency
					if ( $order->get_order_currency() != $posted['mc_currency'] ) {
						if ( 'yes' == $this->debug ) {
							$this->log->add( 'payneteasy', 'Payment error: Currencies do not match (code ' . $posted['mc_currency'] . ')' );
						}
						// Put this order on-hold for manual checking
						$order->update_status( 'on-hold', sprintf( __( 'Validation error: PaynetEasy currencies do not match (code %s).', 'woocommerce' ), $posted['mc_currency'] ) );
						exit;
					}
					// Validate amount
					if ( $order->get_total() != $posted['mc_gross'] ) {
						if ( 'yes' == $this->debug ) {
							$this->log->add( 'payneteasy', 'Payment error: Amounts do not match (gross ' . $posted['mc_gross'] . ')' );
						}
						// Put this order on-hold for manual checking
						$order->update_status( 'on-hold', sprintf( __( 'Validation error: PaynetEasy amounts do not match (gross %s).', 'woocommerce' ), $posted['mc_gross'] ) );
						exit;
					}
					// Validate Email Address
					if ( strcasecmp( trim( $posted['receiver_email'] ), trim( $this->receiver_email ) ) != 0 ) {
						if ( 'yes' == $this->debug ) {
							$this->log->add( 'payneteasy', "IPN Response is for another one: {$posted['receiver_email']} our email is {$this->receiver_email}" );
						}
						// Put this order on-hold for manual checking
						$order->update_status( 'on-hold', sprintf( __( 'Validation error: PaynetEasy IPN response from a different email address (%s).', 'woocommerce' ), $posted['receiver_email'] ) );
						exit;
					}
					 // Store PP Details
					if ( ! empty( $posted['payer_email'] ) ) {
						update_post_meta( $order->id, 'Payer PaynetEasy address', wc_clean( $posted['payer_email'] ) );
					}
					if ( ! empty( $posted['txn_id'] ) ) {
						update_post_meta( $order->id, 'Transaction ID', wc_clean( $posted['txn_id'] ) );
					}
					if ( ! empty( $posted['first_name'] ) ) {
						update_post_meta( $order->id, 'Payer first name', wc_clean( $posted['first_name'] ) );
					}
					if ( ! empty( $posted['last_name'] ) ) {
						update_post_meta( $order->id, 'Payer last name', wc_clean( $posted['last_name'] ) );
					}
					if ( ! empty( $posted['payment_type'] ) ) {
						update_post_meta( $order->id, 'Payment type', wc_clean( $posted['payment_type'] ) );
					}
					if ( $posted['payment_status'] == 'completed' ) {
						$order->add_order_note( __( 'IPN payment completed', 'woocommerce' ) );
						$order->payment_complete();
					} else {
						$order->update_status( 'on-hold', sprintf( __( 'Payment pending: %s', 'woocommerce' ), $posted['pending_reason'] ) );
					}
					if ( 'yes' == $this->debug ) {
						$this->log->add( 'payneteasy', 'Payment complete.' );
					}
				break;
				case 'denied' :
				case 'expired' :
				case 'failed' :
				case 'voided' :
					// Order failed
					$order->update_status( 'failed', sprintf( __( 'Payment %s via IPN.', 'woocommerce' ), strtolower( $posted['payment_status'] ) ) );
				break;
				case 'refunded' :
					// Only handle full refunds, not partial
					if ( $order->get_total() == ( $posted['mc_gross'] * -1 ) ) {
						// Mark order as refunded
						$order->update_status( 'refunded', sprintf( __( 'Payment %s via IPN.', 'woocommerce' ), strtolower( $posted['payment_status'] ) ) );
						$mailer = WC()->mailer();
						$message = $mailer->wrap_message(
							__( 'Order refunded/reversed', 'woocommerce' ),
							sprintf( __( 'Order %s has been marked as refunded - PaynetEasy reason code: %s', 'woocommerce' ), $order->get_order_number(), $posted['reason_code'] )
						);
						$mailer->send( get_option( 'admin_email' ), sprintf( __( 'Payment for order %s refunded/reversed', 'woocommerce' ), $order->get_order_number() ), $message );
					}
				break;
				case 'reversed' :
					// Mark order as refunded
					$order->update_status( 'on-hold', sprintf( __( 'Payment %s via IPN.', 'woocommerce' ), strtolower( $posted['payment_status'] ) ) );
					$mailer = WC()->mailer();
					$message = $mailer->wrap_message(
						__( 'Order reversed', 'woocommerce' ),
						sprintf(__( 'Order %s has been marked on-hold due to a reversal - PaynetEasy reason code: %s', 'woocommerce' ), $order->get_order_number(), $posted['reason_code'] )
					);
					$mailer->send( get_option( 'admin_email' ), sprintf( __( 'Payment for order %s reversed', 'woocommerce' ), $order->get_order_number() ), $message );
				break;
				case 'canceled_reversal' :
					$mailer = WC()->mailer();
					$message = $mailer->wrap_message(
						__( 'Reversal Cancelled', 'woocommerce' ),
						sprintf( __( 'Order %s has had a reversal cancelled. Please check the status of payment and update the order status accordingly.', 'woocommerce' ), $order->get_order_number() )
					);
					$mailer->send( get_option( 'admin_email' ), sprintf( __( 'Reversal cancelled for order %s', 'woocommerce' ), $order->get_order_number() ), $message );
				break;
				default :
					// No action
				break;
			}
			exit;
		}
	}
	/**
	 * Return handler
	 *
	 * Alternative to IPN
	 */
	public function pdt_return_handler() {
        $posted = stripslashes_deep( $_REQUEST );
        // echo "<pre>";print_r($posted);echo "</pre>";
        /*
        [key] => wc_order_59f00fac7fa77
        [error_message] =>
        [processor-tx-id] => PNTEST-12023
        [merchant_order] => 25991
        [orderid] => 12023
        [client_orderid] => 25991
        [bin] => 444455
        [control] => ace0a3e586a98be9907a1f4bc62d3701d7725e35
        [gate-partial-reversal] => enabled
        [descriptor] => test-evro-eur 3D
        [gate-partial-capture] => enabled
        [type] => sale
        [card-type] => VISA
        [phone] => 79265766710
        [last-four-digits] => 1111
        [card-holder-name] => VLADIMIR BUSHUEV
        [status] => approved
        */
        $order = new WC_Order( $posted['client_orderid']);
        switch($posted['status']){
            case 'approved':{

                update_post_meta( $order->id, 'Transaction ID', wc_clean( $posted['orderid'] ) );
                $order->add_order_note( __( 'PaynetEasy payment completed', 'woocommerce' ) );
                $order->update_status( 'processing', sprintf( __( 'Payment success', 'woocommerce' ) ) );
                $order->payment_complete();

                return true;
            }break;
        }
		return false;
	}
	/**
	 * get_payneteasy_order function.
	 *
	 * @param  string $custom
	 * @param  string $invoice
	 * @return WC_Order object
	 */
	private function get_payneteasy_order( $custom, $invoice = '' ) {
		$custom = maybe_unserialize( $custom );
		// Backwards comp for IPN requests
		if ( is_numeric( $custom ) ) {
			$order_id  = (int) $custom;
			$order_key = $invoice;
		} elseif( is_string( $custom ) ) {
			$order_id  = (int) str_replace( $this->invoice_prefix, '', $custom );
			$order_key = $custom;
		} else {
			list( $order_id, $order_key ) = $custom;
		}
		$order = new WC_Order( $order_id );
		if ( ! isset( $order->id ) ) {
			// We have an invalid $order_id, probably because invoice_prefix has changed
			$order_id 	= wc_get_order_id_by_order_key( $order_key );
			$order 		= new WC_Order( $order_id );
		}
		// Validate key
		if ( $order->order_key !== $order_key ) {
			if ( 'yes' == $this->debug ) {
				$this->log->add( 'payneteasy', 'Error: Order Key does not match invoice.' );
			}
			exit;
		}
		return $order;
	}


	}

	/**
 	* Add the Gateway to WooCommerce
 	**/
	function woocommerce_add_payneteasy_gateway($methods) {
		$methods[] = 'WC_Gateway_Payneteasy';
		return $methods;
	}

	add_filter('woocommerce_payment_gateways', 'woocommerce_add_payneteasy_gateway' );
}
// Showing Data in Order Detail Form
function output_payneteasy($post) {
	echo "<pre>"; print_r($post);die();
		global $wpdb;
		$query = "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_data' AND post_id='".$post->id."'";
        if($wpdb->get_var($query) > 0) {
			//checking status from payneteasy
			  $query = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_data' AND post_id='".$post->id."'";
			  $serialize_data = $wpdb->get_var($query);
			//CHM Start
			if($serialize_data) {
			 $data = unserialize($serialize_data);
			 $test = $data;//
			 $card_data=explode("****",$data['card']);
			 $card_data=$card_data[0].$card_data[1];

			 //getting password and key from db
			 $query = "SELECT option_value FROM {$wpdb->options} WHERE option_name='woocommerce_payneteasy_settings'";
			 $serialize_data = $wpdb->get_var($query);
			 $unserialized = unserialize($serialize_data);
			 $client_key = $unserialized['client_key'];
			 $client_password = $unserialized['client_password'];

			 $postUrl = "https://secure.test.payneteasy.eu/api/";
			 $hash = md5(strtoupper(strrev($data['email']).$client_password.$data['id'].strrev($card_data)));
			 $data = "action=GET_TRANS_DETAILS&client_key=".$client_key."&trans_id=".$data['id']."&hash=".$hash;

			 //Get length of post
				 $postlength = strlen($data);
				 //open connection
				 $ch = curl_init();
				 //set the url, number of POST vars, POST data
				 curl_setopt($ch,CURLOPT_URL,$postUrl);
				 curl_setopt($ch, CURLOPT_POST, true);

				 curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
				 curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				 curl_setopt($ch, CURLOPT_SSLVERSION,3);

				 curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
				 curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

				$response = curl_exec($ch);

				$res = json_decode($response, true);

				//echo "<pre>";print_r( $res );echo "</pre>";
				for($i=0;$i<count($res['transactions']);$i++){
					if($res['transactions'][$i]['type']!='AUTH'){
			  			$query="SELECT * FROM payneteasy_transactions WHERE oid ='".$post->id."' AND date ='".$res['transactions'][$i]['date']."' AND type ='".$res['transactions'][$i]['type']."'";
							if ($wpdb->get_row($query)){
							}
							else{
								$wpdb->query( $wpdb->prepare(
								"INSERT INTO payneteasy_transactions (oid, type, date,amount) VALUES ( %d, %s, %s, %s )",
								array(
								$post->id,
								$res['transactions'][$i]['type'],
								$res['transactions'][$i]['date'],
								$res['transactions'][$i]['amount'])));
								if($res['transactions'][$i]['type']=='REFUND'){
									$query = "SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key='_order_total' AND post_id='".$post->id."'";
									if($wpdb->get_row($query)) {
					   					$meta_data = $wpdb->get_row($query);
									   	$new_total = $meta_data->meta_value - $res['transactions'][$i]['amount'];
									   	$new_total = number_format((float)$new_total, 2, '.', '');
										$wpdb->update($wpdb->postmeta,
											array( 'meta_value' => $new_total, ),
											array( 'meta_id' => $meta_data->meta_id ),
											array( '%s', '%d' ), array( '%d' )
										);
								 	  }
									if($new_total <=0){
										$status='cancelled';
										$order = new WC_Order( $post->id );
										// Order status
										$order->update_status( $status );									}
									else{
										$status='refunded';
										$order = new WC_Order( $post->id );
										// Order status
										$order->update_status( $status );
									}
								}

								if($res['transactions'][$i]['type']=='CAPTURE'){
										$query = "SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key='_order_total' AND post_id='".$post->id."'";
										if($wpdb->get_row($query)) {
					   						$meta_data = $wpdb->get_row($query);
									   		$new_total = number_format((float)$new_total, 2, '.', '');
											$wpdb->update($wpdb->postmeta,
												array( 'meta_value' => $res['transactions'][$i]['amount']),
												array( 'meta_id' => $meta_data->meta_id ),
												array( '%s', '%d' ), array( '%d' )
											);
										}
										$status='completed';
										$order = new WC_Order( $post->id );
										// Order status
										$order->update_status( $status );
								}
								if($res['transactions'][$i]['type']=='REVERSAL'){
										$status='cancelled';
										$order = new WC_Order( $post->id );
										// Order status
										$order->update_status( $status );
								}
						}
					}
				}
				 curl_close($ch);
			}
			//CHM End

			// end of checking status
		   $meta_value = '';
		   $query = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_order_status' AND post_id='".$post->id."'";
			   if($wpdb->get_var($query)) {
				    $meta_value = $wpdb->get_var($query);
			   }
			 ?><div style="width:500px;"><?php
				   if($meta_value!='CANCELLED') {
					   $query = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_error_message' AND post_id='".$post->id."'";
				   if($wpdb->get_var($query)) {
						$error_message = $wpdb->get_var($query);
				   }
				   //if error is already seen
				   $is_error = '';
				   $query = "SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_error_message_seen' AND post_id='".$post->id."'";
				   if($wpdb->get_row($query)) {
					   $meta_data = $wpdb->get_row($query);
					   $wpdb->update($wpdb->postmeta,
							array( 'meta_value' => 1, ),
							array( 'meta_id' => $meta_data->meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
					   $is_error = $meta_data->meta_value;
				   }
				   //
				   if($error_message != '' && $is_error==0) {
					  echo "<p style='color:#F00'><b>Last Attempt Error:</b> ".$error_message."</p>";
				  }
			   ?>

                        <p class="form-field form-field-wide" style="width:50%"><label for="order_status"><?php _e( 'Order Action:', 'woocommerce' ) ?></label>
						<select id="order_action" name="order_action" class="chosen_select" onchange="checkOption()">
							<option value="refund">Refund</option>
                            <?php if($captured==0) { ?><option value="capture">Capture</option><?php } ?>
                            <option value="void">Void</option>
						</select></p>
                        <p class="form-field form-field-wide" style="width:50%"><label for="order_status"><?php _e( 'Amount:', 'woocommerce' ) ?></label>
                      <script type="text/javascript">
					  function checkOption() {
						var myselect = document.getElementById("order_action");
  						if(myselect.options[myselect.selectedIndex].value=='void') {
							document.getElementById("myText").disabled=true;
						}else {
							document.getElementById("myText").disabled=false;
						}
					  }
					  </script>
                        <input type="text" id="myText" maxlength="15" name="refund_amount" />
                        </p>

                 <?php } //}

				$query = "SELECT * FROM payneteasy_transactions WHERE oid='".$post->id."'";
			   if($wpdb->get_results($query)) {
				    $row = $wpdb->get_results($query);?>


                   <h4 style="clear:both"><?php _e( 'PaynetEasy Payment Details', 'woocommerce' ); ?> </h4>
                   <table cellpadding="0" cellspacing="0" border="1" width="500px">
                     <tr>
                       <th style="width:165px">Action</th>
                       <th style="width:165px">Amount</th>
                       <th style="width:165px">Date</th>
                     </tr>
                  <?php foreach($row as $row) {
					     $style = '';
						 $str_start = '';
						 $str_end = '';
					     if($row->type=='Refunded') {$style = "color:red;"; $str_start = '('; $str_end = ')';}
						 elseif($row->type=='Captured') {$style = "color:green;"; }?>
                     <tr>
                       <td align="center" style="width:165px;<?=$style;?>"><?=$row->type?></td>
                       <td align="center" style="width:165px;<?=$style;?>"><?=$str_start.$row->amount.$str_end?></td>
                       <td align="center" style="width:165px;"><?=$row->date?></td>
                     </tr>
                   <?php } ?>
                   </table>

				<?php  }?>
                <textarea class="no-mce" wrap="off" readonly style="width:290px;height:100px;font-size:10px;"><? print_r($test);?></textarea>
                </div> <?php }

}
add_action('woocommerce_admin_order_data_after_billing_address','output_payneteasy');
// Saving Data in Db
function pne_saveData($post_id, $post=array()){
	global $wpdb;
		//chm
	if(isset($_POST['order_action']))
	{
	  // case refund
	  if($_POST['order_action']=='refund') {
		$refundAmount = $_POST['refund_amount'];
		$refund_amount = number_format((float)$refundAmount, 2, '.', '');
		if($refund_amount == ''){$refund_amount = '0';}
		$query = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_data' AND post_id='".$post_id."'";
		$serialize_data = $wpdb->get_var($query);
		if($serialize_data) {
		 $data = unserialize($serialize_data);
		 $card_data=explode("****",$data['card']);
		 $card_data=$card_data[0].$card_data[1];

		 //getting password and key from db
		 $query = "SELECT option_value FROM {$wpdb->options} WHERE option_name='woocommerce_payneteasy_settings'";
		 $serialize_data = $wpdb->get_var($query);
		 $unserialized = unserialize($serialize_data);
		 $client_key = $unserialized['client_key'];
		 $client_password = $unserialized['client_password'];
		 $postUrl = "https://secure.test.payneteasy.eu/api/";
		 $hash = md5(strtoupper(strrev($data['email']).$client_password.$data['id'].strrev($card_data)));
		 if($refund_amount > 0) {
		  $data = "action=CREDITVOID&client_key=".$client_key."&trans_id=".$data['id']."&amount=".$refund_amount."&hash=".$hash;
		 } else {
		  $data = "action=CREDITVOID&client_key=".$client_key."&trans_id=".$data['id']."&hash=".$hash;
		 }
		 //echo $hash."<br/>".$data."<br/>".$postUrl;die();
		 //Get length of post
			 $postlength = strlen($data);
			 //open connection
			 $ch = curl_init();
			 //set the url, number of POST vars, POST data
			 curl_setopt($ch,CURLOPT_URL,$postUrl);
			 curl_setopt($ch, CURLOPT_POST, true);

			 curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
			 //curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			 curl_setopt($ch, CURLOPT_SSLVERSION,3);

			 curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
			 curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			 curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			 //curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			 //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			 $response = curl_exec($ch);
			// echo $response;die();
			 $decoded = json_decode($response, true);
			 if($decoded['result'] == 'ACCEPTED') {
			   $_POST['order_status'] = 'refunded';
			   // Order data saved, now get it so we can manipulate status
		$order = new WC_Order( $post_id );
		// Order status
		$order->update_status( $_POST['order_status'] );
				   $wpdb->query( $wpdb->prepare(
					"INSERT INTO payneteasy_transactions (oid, type, date,amount) VALUES ( %d, %s, %s, %s )",
					array(
						$post_id,
						'REFUNDED',
						date("m/d/Y H:i:s"),
						$refund_amount
					)
				   ));
				   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_error_message' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
					   $meta_id = $wpdb->get_var($query);
						$wpdb->update($wpdb->postmeta,
							array( 'meta_value' => '', ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }
				   // updating order total
				   $query = "SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key='_order_total' AND post_id='".$post_id."'";
				   if($wpdb->get_row($query)) {
					   $meta_data = $wpdb->get_row($query);
					   $new_total = $meta_data->meta_value - $refund_amount;
					   $new_total = number_format((float)$new_total, 2, '.', '');
						$wpdb->update($wpdb->postmeta,
							array( 'meta_value' => $new_total, ),
							array( 'meta_id' => $meta_data->meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }

			 } else {
				   $meta_id = 0;
				   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_error_message' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
						$meta_id = $wpdb->get_var($query);
				   }
				   if($meta_id > 0) {
					   $wpdb->update($wpdb->postmeta,
							array( 'meta_value' => $decoded['error_message'], ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }else {
					   $wpdb->query( $wpdb->prepare(
						"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ( %d, %s, %s )",
						array(
							$post_id,
							'_payneteasy_error_message',
							$decoded['error_message']
						)
					   ));
				   }
				   //have to fix this later
					   $meta_id = 0;
				   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_error_message_seen' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
						$meta_id = $wpdb->get_var($query);
				   }
				   if($meta_id > 0) {
					   $wpdb->update($wpdb->postmeta,
							array( 'meta_value' => 0, ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }else {
					   $wpdb->query( $wpdb->prepare(
						"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ( %d, %s, %s )",
						array(
							$post_id,
							'_payneteasy_error_message_seen',
							0
						)
					   ));
				     }
			   }
			 //close connection
			 curl_close($ch);
			 //return false;
		}
	  }
	  // case capture
	  elseif($_POST['order_action']=='capture') {
		$refundAmount = $_POST['refund_amount'];
		$refund_amount = number_format((float)$refundAmount, 2, '.', '');
		if($refund_amount == ''){$refund_amount = '0';}
		$query = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_data' AND post_id='".$post_id."'";
		$serialize_data = $wpdb->get_var($query);
		if($serialize_data) {
		 $data = unserialize($serialize_data);
		 $card_data=explode("****",$data['card']);
		 $card_data=$card_data[0].$card_data[1];

		 //getting password and key from db
		 $query = "SELECT option_value FROM {$wpdb->options} WHERE option_name='woocommerce_payneteasy_settings'";
		 $serialize_data = $wpdb->get_var($query);
		 $unserialized = unserialize($serialize_data);
		 $client_key = $unserialized['client_key'];
		 $client_password = $unserialized['client_password'];

		 $postUrl = "https://secure.test.payneteasy.eu/api/";
		 $hash = md5(strtoupper(strrev($data['email']).$client_password.$data['id'].strrev($card_data)));
		 if($refund_amount > 0) {
		   $data = "action=CAPTURE&client_key=".$client_key."&trans_id=".$data['id']."&amount=".$refund_amount."&hash=".$hash;
		 } else {
		   $data = "action=CAPTURE&client_key=".$client_key."&trans_id=".$data['id']."&hash=".$hash;
		 }
		 //Get length of post
			 $postlength = strlen($data);
			 //open connection
			 $ch = curl_init();
			 //set the url, number of POST vars, POST data
			 curl_setopt($ch,CURLOPT_URL,$postUrl);
			 curl_setopt($ch, CURLOPT_POST, true);

			 curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
			 curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			 curl_setopt($ch, CURLOPT_SSLVERSION,3);

			 curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
			 curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			 //curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			 //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			 $response = curl_exec($ch);
			// echo $response;die();
			 $decoded = json_decode($response, true);
		if($decoded['result'] == 'SUCCESS') {
			   $_POST['order_status'] = 'refunded';
			     // Order data saved, now get it so we can manipulate status
		$order = new WC_Order( $post_id );
		// Order status
		$order->update_status( $_POST['order_status'] );
			   $wpdb->query( $wpdb->prepare(
					"INSERT INTO payneteasy_transactions (oid, type, date,amount) VALUES ( %d, %s, %s, %s )",
					array(
						$post_id,
						'CAPTURED',
						date("m/d/Y H:i:s"),
						$refund_amount
					)
				   ));
			   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_error_message' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
					   $meta_id = $wpdb->get_var($query);
						$wpdb->update($wpdb->postmeta,
							array( 'meta_value' => '', ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }
			 } else {
				   $meta_id = 0;
				   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_error_message' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
						$meta_id = $wpdb->get_var($query);
				   }
				   if($meta_id > 0) {
					   $wpdb->update($wpdb->postmeta,
							array( 'meta_value' => $decoded['error_message'], ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }else {
					   $wpdb->query( $wpdb->prepare(
						"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ( %d, %s, %s )",
						array(
							$post_id,
							'_payneteasy_error_message',
							$decoded['error_message']
						)
					   ));
				   }
				   //have to fix this later
					   $meta_id = 0;
				   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_error_message_seen' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
						$meta_id = $wpdb->get_var($query);
				   }
				   if($meta_id > 0) {
					   $wpdb->update($wpdb->postmeta,
							array( 'meta_value' => 0, ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }else {
					   $wpdb->query( $wpdb->prepare(
						"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ( %d, %s, %s )",
						array(
							$post_id,
							'_payneteasy_error_message_seen',
							0
						)
					   ));
				     }
			   }
			 //close connection
			 curl_close($ch);
			// return false;
		}
	  }
	  // case void
	  else{
		$refundAmount = $_POST['_order_total'];
		$refund_amount = number_format((float)$refundAmount, 2, '.', '');
		if($refund_amount == ''){$refund_amount = '0.00';}
		$query = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_data' AND post_id='".$post_id."'";
		$serialize_data = $wpdb->get_var($query);
		if($serialize_data) {
		 $data = unserialize($serialize_data);
		 $card_data=explode("****",$data['card']);
		 $card_data=$card_data[0].$card_data[1];

		 //getting password and key from db
		 $query = "SELECT option_value FROM {$wpdb->options} WHERE option_name='woocommerce_payneteasy_settings'";
		 $serialize_data = $wpdb->get_var($query);
		 $unserialized = unserialize($serialize_data);
		 $client_key = $unserialized['client_key'];
		 $client_password = $unserialized['client_password'];

		 $postUrl = "https://secure.test.payneteasy.eu/api/";
		 $hash = md5(strtoupper(strrev($data['email']).$client_password.$data['id'].strrev($card_data)));
		 $data = "action=CREDITVOID&client_key=".$client_key."&trans_id=".$data['id']."&hash=".$hash;

		 //Get length of post
			 $postlength = strlen($data);
			 //open connection
			 $ch = curl_init();
			 //set the url, number of POST vars, POST data
			 curl_setopt($ch,CURLOPT_URL,$postUrl);
			 curl_setopt($ch, CURLOPT_POST, true);

			 curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
			 curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			 curl_setopt($ch, CURLOPT_SSLVERSION,3);

			 curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
			 curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			 //curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			 //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			 $response = curl_exec($ch);
			 //echo $response;die();
			 //echo 'error='.curl_error($ch);
			 $decoded = json_decode($response, true);
			 if($decoded['result'] == 'ACCEPTED') {
			   $_POST['order_status'] = 'cancelled';
			     // Order data saved, now get it so we can manipulate status
		$order = new WC_Order( $post_id );
		// Order status
		$order->update_status( $_POST['order_status'] );
				   $wpdb->query( $wpdb->prepare(
					"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ( %d, %s, %s )",
					array(
						$post_id,
						'_payneteasy_order_status',
						'CANCELLED'
					)
				   ));

			   $wpdb->query( $wpdb->prepare(
					"INSERT INTO payneteasy_transactions (oid, type, date,amount) VALUES ( %d, %s, %s, %s )",
					array(
						$post_id,
						'VOID',
						date("m/d/Y H:i:s"),
						$refund_amount
					)
				   ));
			    $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_error_message' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
					   $meta_id = $wpdb->get_var($query);
						$wpdb->update($wpdb->postmeta,
							array( 'meta_value' => '', ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }
			 } else {
				   $meta_id = 0;
				   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_error_message' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
						$meta_id = $wpdb->get_var($query);
				   }
				   if($meta_id > 0) {
					   $wpdb->update($wpdb->postmeta,
							array( 'meta_value' => $decoded['error_message'], ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }else {
					   $wpdb->query( $wpdb->prepare(
						"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ( %d, %s, %s )",
						array(
							$post_id,
							'_payneteasy_error_message',
							$decoded['error_message']
						)
					   ));
				   }
				   //have to fix this later
					   $meta_id = 0;
				   $query = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_payneteasy_error_message_seen' AND post_id='".$post_id."'";
				   if($wpdb->get_var($query)) {
						$meta_id = $wpdb->get_var($query);
				   }
				   if($meta_id > 0) {
					   $wpdb->update($wpdb->postmeta,
							array( 'meta_value' => 0, ),
							array( 'meta_id' => $meta_id ),
							array( '%s', '%d' ), array( '%d' )
						);
				   }else {
					   $wpdb->query( $wpdb->prepare(
						"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ( %d, %s, %s )",
						array(
							$post_id,
							'_payneteasy_error_message_seen',
							0
						)
					   ));
				     }
			   }

			 //close connection
			 curl_close($ch);
			// return false;
		}
	  }
	}

}
add_action('save_post','pne_saveData');
