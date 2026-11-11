<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * OVOO
 *
 * OVOO-Movie & Video Streaming CMS with Unlimited TV-Series
 *
 * @package     OVOO
 * @author      Abdul Mannan
 * @copyright   Copyright (c) 2014 - 2016 SpaGreen,
 * @license     http://codecanyon.net/wiki/support/legal-terms/licensing-terms/ 
 * @link        http://www.spagreen.net
 * @link        support@spagreen.net
 *
 **/ 

class Subscription_model extends CI_Model {
	
	function __construct()
    {
        parent::__construct();
    }
	function create_trial_subscription($user_id=''){
        $data['user_id']        = $user_id;
        $data['plan_id']        = '0';
        $data['timestamp_from'] = time();
        $day                    = $this->db->get_where('config' , array('title'=>'trial_period'))->row()->value;
        $day_str                = $day." days";
        $data['timestamp_to']   = strtotime($day_str, $data['timestamp_from']);
        $data['status']         = '1';
        $this->db->insert('subscription', $data);
        return TRUE;
    }

    function create_subscription($user_id='',$plan_id=''){
        $data['user_id']        = $user_id;
        $data['plan_id']        = $plan_id;
        $data['timestamp_from'] = time();
        $day                    = $this->get_plan_day_by_id($plan_id);
        $day_str                = $day." days";
        $data['timestamp_to']   = strtotime($day_str, $data['timestamp_from']);
        $data['status']         = '1';
        $this->db->insert('subscription', $data);
        return TRUE;
    }

    function get_active_plan_title($user_id=''){
        $title =    'Free';
        $query =    $this->db->get_where('subscription',array('user_id' => $user_id,'status'=>'1'),1);
        if($query->num_rows() > 0):
            $plan_id = $query->row()->plan_id;
            if($plan_id == 0):
                $title = 'Trial';
            else:
                $title = $this->get_plan_name_by_id($plan_id);
            endif;
        endif;
      return $title;
    }

    function get_plan_name_by_id($plan_id){
        $name = "Not Found";
        if($plan_id == 0):
            $name = "Trial";
        endif;
        $query = $this->db->get_where('plan',array('plan_id'=>$plan_id));
        if($query->num_rows() >0):
            $name = $this->db->get_where('plan',array('plan_id'=>$plan_id))->row()->name;
        endif;
        return $name;
    }

    function get_plan_day_by_id($plan_id){
        return $this->db->get_where('plan',array('plan_id'=>$plan_id))->row()->day;
    }


    function get_active_plan_validity($user_id=''){
        $validity =    'Lifetime';
        $query =    $this->db->get_where('subscription',array('user_id' => $user_id,'status'=>'1'),1);
        if($query->num_rows() > 0):
            $date = time();
            if($date > $query->row()->timestamp_to):
                $validity = "Expired";
            else:
                $validity = date("d-m-Y",$query->row()->timestamp_to);
            endif;
        endif;
      return $validity;
    }

    function check_video_availability($slug=''){
        $error = FALSE;
        if ($slug == '' || $slug==NULL)
            $error = TRUE;

        $videos_exist = $this->common_model->videos_exist_by_slug($slug);
        if (!$videos_exist )
            $error = TRUE;
        return $error;
    }

    function check_live_tv_availability($slug=''){
        $error = FALSE;
        if ($slug == '' || $slug==NULL)
            $error = TRUE;

        $videos_exist = $this->common_model->live_tv_exist_by_slug($slug);
        if (!$videos_exist )
            $error = TRUE;
        return $error;
    }

    function videos_exist_by_slug($slug='') {
        $rows = $this->db->get_where('videos', array('slug' => $slug,'publication'=>'1'))->num_rows();
        if($rows >0){
          return TRUE;
        }
        else{
          return FALSE;
        }    
    }

    function live_tv_exist_by_slug($slug='') {
        $rows = $this->db->get_where('live_tv', array('slug' => $slug,'publish'=>'1'))->num_rows();
        if($rows >0){
          return TRUE;
        }
        else{
          return FALSE;
        }    
    }


    function check_video_accessibility($videos_id=''){
        $accessibility = "denied";        
        // free content can access by all
        $is_paid = $this->db->get_where('videos',array('videos_id'=>$videos_id))->row()->is_paid;
        if($is_paid =='0')
            $accessibility = "allowed";        
        if($is_paid =='1'):
            $subscription = $this->check_validated_subscription_plan();
            //var_dump($subscription);
            if($subscription == "login_required"):
                $accessibility = "login_required";
            elseif($subscription === "TRUE"):
                $accessibility = "allowed";
            endif;
        endif;
        // admin can access all movie
        if ($this->session->userdata('admin_is_login') == 1)
            $accessibility = "allowed";
        return $accessibility;
    }

    function check_live_tv_accessibility($live_tv_id=''){
        $accessibility = "denied";
        
        // free content can access by all
        $is_paid = $this->db->get_where('live_tv',array('live_tv_id'=>$live_tv_id))->row()->is_paid;
        if($is_paid =='0')
            $accessibility = "allowed";        
        if($is_paid =='1'):
            $subscription = $this->check_validated_subscription_plan();
            //var_dump($subscription);
            if($subscription == "login_required"):
                $accessibility = "login_required";
            elseif($subscription === "TRUE"):
                $accessibility = "allowed";
            endif;
        endif;
        // admin can access all movie
        if ($this->session->userdata('admin_is_login') == 1)
            $accessibility = "allowed";
        return $accessibility;
    }

    function check_validated_subscription_plan(){
        $validity = "FALSE";
        $user_id  = $this->session->userdata('user_id');
        if(!empty($user_id)):
            $this->db->where('status','1');
            $this->db->where('timestamp_to >',time());
            $this->db->where('user_id',$this->session->userdata('user_id'));
            $query = $this->db->get('subscription');
            if($query->num_rows() >0):
                $validity = $query->row()->timestamp_to;
                if($validity > time())
                    $validity = "TRUE";
            endif;
        endif;
        if(empty($user_id)):
            $validity = "login_required";
        endif;
        return $validity;
    }
    function get_active_subscription(){
        $this->db->where('status','1');
        $this->db->where('timestamp_to >',time());
        $this->db->where('user_id',$this->session->userdata('user_id'));
        return $this->db->get('subscription');
    }

    function get_inactive_subscription(){
        $this->db->group_start();
        $this->db->where('status','0');
        $this->db->or_where('timestamp_to <',time());
        $this->db->group_end();
        $this->db->where('user_id',$this->session->userdata('user_id'));
        return $this->db->get('subscription');
    }
    function get_total_income(){
        $currency_symbol  = $this->db->get_where('config' , array('title'=>'currency_symbol'))->row()->value;
        $this->db->select_sum('paid_amount');
        //$this->db->where('payment_timestamp >=',strtotime(date("Y-m-d 00:00:00")));
        //$this->db->where('payment_timestamp <=',strtotime(date("Y-m-d 23:59:59")));
        $amount = $this->db->get('subscription')->row()->paid_amount;
        return $currency_symbol.' '.number_format($amount, 2);
    }

    function get_today_income(){
        $currency_symbol  = $this->db->get_where('config' , array('title'=>'currency_symbol'))->row()->value;
        $this->db->select_sum('paid_amount');
        $this->db->where('payment_timestamp >=',strtotime(date("Y-m-d 00:00:00")));
        $this->db->where('payment_timestamp <=',time());
        $amount = $this->db->get('subscription')->row()->paid_amount;
        return $currency_symbol.' '.number_format($amount, 2);
    }

    function get_weekly_income(){
        $currency_symbol  = $this->db->get_where('config' , array('title'=>'currency_symbol'))->row()->value;
        $this->db->select_sum('paid_amount');
        $this->db->where('payment_timestamp >=',strtotime('last friday'));
        $this->db->where('payment_timestamp <=',time());
        $amount = $this->db->get('subscription')->row()->paid_amount;
        //var_dump($this->db->last_query());
        return $currency_symbol.' '.number_format($amount, 2);

    }

    function get_monthly_income(){
        $currency_symbol  = $this->db->get_where('config' , array('title'=>'currency_symbol'))->row()->value;
        $this->db->select_sum('paid_amount');
        $this->db->where('payment_timestamp >=',strtotime('first day of this month'));
        $this->db->where('payment_timestamp <=',time());
        $amount = $this->db->get('subscription')->row()->paid_amount;
        return $currency_symbol.' '.number_format($amount, 2);
    }

    function get_active_subscriber(){
        $this->db->distinct('user_id');
        $this->db->where('timestamp_to >',time());
        $this->db->where('status','1');
        $query = $this->db->get('subscription');
        return $query->num_rows();
    }

    function get_inactive_subscriber(){
        $this->db->distinct('user_id');
        $this->db->where('timestamp_to <',time());
        $this->db->where('status','1');
        $query = $this->db->get('subscription');
        //var_dump($this->db->last_query());
        return $query->num_rows(); 
    }

    // -------------------------------------------------------------------------
    // Payment verification
    // -------------------------------------------------------------------------

    /**
     * Verify a payment with the gateway *before* granting a subscription.
     *
     * The caller must pass the authoritative plan price from the `plan` table,
     * never a value supplied by the client.
     *
     * @param string $method          stripe | paypal | razorpay
     * @param array  $payload         Request values (payment_info, razorpay_*, IPN fields)
     * @param float  $expectedAmount  Plan price in the site currency
     * @return bool
     */
    function verify_payment($method, $payload, $expectedAmount){
        $method         = strtolower(trim((string) $method));
        $expectedAmount = (float) $expectedAmount;

        if ($expectedAmount <= 0):
            log_message('error', 'verify_payment: refusing non-positive expected amount');
            return FALSE;
        endif;

        switch ($method):
            case 'stripe':
                return $this->_verify_stripe_payment($payload, $expectedAmount);
            case 'paypal':
                return $this->_verify_paypal_payment($payload, $expectedAmount);
            case 'razorpay':
                return $this->_verify_razorpay_payment($payload, $expectedAmount);
            default:
                log_message('error', 'verify_payment: unsupported payment method "'.$method.'"');
                return FALSE;
        endswitch;
    }

    /**
     * Gateway transaction id, used by callers to reject replayed payments.
     *
     * @return string Empty string when the gateway did not expose one.
     */
    function payment_transaction_id($method, $payload){
        $method = strtolower(trim((string) $method));

        if ($method === 'razorpay'):
            return (string) ($payload['razorpay_payment_id'] ?? '');
        endif;

        if ($method === 'paypal'):
            return (string) ($payload['txn_id'] ?? ($_POST['txn_id'] ?? ''));
        endif;

        if ($method === 'stripe'):
            $info = $payload['payment_info'] ?? '';
            if (is_string($info) && $info !== '' && $info[0] === '{'):
                $decoded = json_decode($info, TRUE);
                if (is_array($decoded) && !empty($decoded['id'])):
                    return (string) $decoded['id'];
                endif;
            endif;
            if (is_array($info) && !empty($info['id'])):
                return (string) $info['id'];
            endif;
            return is_string($info) ? $info : '';
        endif;

        return '';
    }

    /**
     * Stripe: retrieve the PaymentIntent and require a successful charge for
     * exactly the expected amount and currency.
     */
    private function _verify_stripe_payment($payload, $expectedAmount){
        $info = $payload['payment_info'] ?? '';

        if (is_string($info) && $info !== '' && $info[0] === '{'):
            $decoded = json_decode($info, TRUE);
            if (is_array($decoded) && !empty($decoded['id'])):
                $info = $decoded['id'];
            endif;
        endif;

        $intent_id = is_array($info) ? ($info['id'] ?? '') : $info;
        $intent_id = is_string($intent_id) ? trim($intent_id) : '';

        if ($intent_id === ''):
            log_message('error', 'verify_payment(stripe): missing charge/PaymentIntent id');
            return FALSE;
        endif;

        // Legacy checkout creates a Charge; the current flow creates a PaymentIntent.
        if (strpos($intent_id, 'ch_') === 0):
            return $this->_verify_stripe_charge($intent_id, $expectedAmount);
        endif;

        if (strpos($intent_id, 'pi_') !== 0):
            log_message('error', 'verify_payment(stripe): invalid charge/PaymentIntent id');
            return FALSE;
        endif;

        $secret = ovoo_config('stripe_secret_key');
        if ($secret === '' || $secret === NULL):
            log_message('error', 'verify_payment(stripe): gateway secret not configured');
            return FALSE;
        endif;

        require_once(APPPATH . 'libraries/stripe/init.php');

        try {
            \Stripe\Stripe::setApiKey($secret);
            $intent = \Stripe\PaymentIntent::retrieve($intent_id);
        } catch (\Exception $e) {
            log_message('error', 'verify_payment(stripe): retrieve failed: '.$e->getMessage());
            return FALSE;
        }

        if (!isset($intent->status) || $intent->status !== 'succeeded'):
            log_message('error', 'verify_payment(stripe): status is not succeeded');
            return FALSE;
        endif;

        $expected_cents = (int) round($expectedAmount * 100);
        if ((int) $intent->amount_received !== $expected_cents):
            log_message('error', 'verify_payment(stripe): amount mismatch (got '.(int)$intent->amount_received.', expected '.$expected_cents.')');
            return FALSE;
        endif;

        $currency = $this->db->get_where('config', array('title' => 'currency'))->row()->value;
        if (!empty($currency) && !empty($intent->currency)
            && strcasecmp((string) $intent->currency, (string) $currency) !== 0):
            log_message('error', 'verify_payment(stripe): currency mismatch');
            return FALSE;
        endif;

        return TRUE;
    }

    /**
     * Legacy Stripe Charge: retrieve it from Stripe and require a captured,
     * fully paid charge for exactly the expected amount and currency.
     */
    private function _verify_stripe_charge($charge_id, $expectedAmount){
        $secret = ovoo_config('stripe_secret_key');
        if ($secret === '' || $secret === NULL):
            log_message('error', 'verify_payment(stripe-charge): gateway secret not configured');
            return FALSE;
        endif;

        require_once(APPPATH . 'libraries/stripe/init.php');

        try {
            \Stripe\Stripe::setApiKey($secret);
            $charge = \Stripe\Charge::retrieve($charge_id);
        } catch (\Exception $e) {
            log_message('error', 'verify_payment(stripe-charge): retrieve failed: '.$e->getMessage());
            return FALSE;
        }

        if (!isset($charge->paid) || $charge->paid !== TRUE):
            log_message('error', 'verify_payment(stripe-charge): charge is not paid');
            return FALSE;
        endif;

        $expected_cents = (int) round($expectedAmount * 100);
        if ((int) $charge->amount !== $expected_cents):
            log_message('error', 'verify_payment(stripe-charge): amount mismatch');
            return FALSE;
        endif;

        $currency = $this->db->get_where('config', array('title' => 'currency'))->row()->value;
        if (!empty($currency) && !empty($charge->currency)
            && strcasecmp((string) $charge->currency, (string) $currency) !== 0):
            log_message('error', 'verify_payment(stripe-charge): currency mismatch');
            return FALSE;
        endif;

        return TRUE;
    }

    /**
     * PayPal: require a PayPal-verified IPN, payment_status=Completed, a
     * matching gross amount, currency and (when configured) receiver email.
     */
    private function _verify_paypal_payment($payload, $expectedAmount){
        $CI =& get_instance();
        if (!isset($CI->paypal)):
            $CI->load->library('paypal');
        endif;

        if ($CI->paypal->validate_ipn() !== TRUE):
            log_message('error', 'verify_payment(paypal): IPN validation failed');
            return FALSE;
        endif;

        $status = (string) ($payload['payment_status'] ?? ($_POST['payment_status'] ?? ''));
        if (strcasecmp($status, 'Completed') !== 0):
            log_message('error', 'verify_payment(paypal): payment_status is not Completed');
            return FALSE;
        endif;

        $gross = $payload['mc_gross'] ?? ($_POST['mc_gross'] ?? NULL);
        if ($gross === NULL || abs((float) $gross - $expectedAmount) > 0.01):
            log_message('error', 'verify_payment(paypal): amount mismatch');
            return FALSE;
        endif;

        $currency         = $payload['mc_currency'] ?? ($_POST['mc_currency'] ?? NULL);
        $site_currency    = $this->db->get_where('config', array('title' => 'currency'))->row()->value;
        if ($currency !== NULL && !empty($site_currency)
            && strcasecmp((string) $currency, (string) $site_currency) !== 0):
            log_message('error', 'verify_payment(paypal): currency mismatch');
            return FALSE;
        endif;

        $receiver         = (string) ($payload['receiver_email'] ?? ($_POST['receiver_email'] ?? ''));
        $expected_receiver = ovoo_config('paypal_email');
        if ($receiver !== '' && !empty($expected_receiver)
            && strcasecmp($receiver, (string) $expected_receiver) !== 0):
            log_message('error', 'verify_payment(paypal): receiver mismatch');
            return FALSE;
        endif;

        return TRUE;
    }

    /**
     * Razorpay: verify the signature, then confirm the captured amount matches
     * the plan price converted with the configured INR exchange rate.
     */
    private function _verify_razorpay_payment($payload, $expectedAmount){
        $order_id   = trim((string) ($payload['razorpay_order_id'] ?? ''));
        $payment_id = trim((string) ($payload['razorpay_payment_id'] ?? ''));
        $signature  = trim((string) ($payload['razorpay_signature'] ?? ''));

        if ($order_id === '' || $payment_id === '' || $signature === ''
            || strpos($payment_id, 'pay_') !== 0):
            log_message('error', 'verify_payment(razorpay): missing payment fields');
            return FALSE;
        endif;

        $key    = ovoo_config('razorpay_key_id');
        $secret = ovoo_config('razorpay_key_secret');
        if (empty($key) || empty($secret)):
            log_message('error', 'verify_payment(razorpay): gateway keys not configured');
            return FALSE;
        endif;

        require_once(APPPATH . 'third_party/razorpay/Razorpay.php');

        try {
            $api = new \Razorpay\Api\Api($key, $secret);
            $api->utility->verifyPaymentSignature(array(
                'razorpay_order_id'   => $order_id,
                'razorpay_payment_id' => $payment_id,
                'razorpay_signature'  => $signature,
            ));
            $payment = $api->payment->fetch($payment_id);
        } catch (\Exception $e) {
            log_message('error', 'verify_payment(razorpay): '.$e->getMessage());
            return FALSE;
        }

        if (!isset($payment->order_id) || (string) $payment->order_id !== $order_id):
            log_message('error', 'verify_payment(razorpay): order mismatch');
            return FALSE;
        endif;

        $status = (string) ($payment->status ?? '');
        if (!in_array($status, array('captured', 'authorized'), TRUE)):
            log_message('error', 'verify_payment(razorpay): payment not captured');
            return FALSE;
        endif;

        $rate = (float) ovoo_config('razorpay_inr_exchange_rate');
        if ($rate <= 0):
            $rate = 1;
        endif;

        $expected_paise = (int) round($expectedAmount * $rate * 100);
        if ((int) ($payment->amount ?? 0) !== $expected_paise):
            log_message('error', 'verify_payment(razorpay): amount mismatch');
            return FALSE;
        endif;

        return TRUE;
    }

}


