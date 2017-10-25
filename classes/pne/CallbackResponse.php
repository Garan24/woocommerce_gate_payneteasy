<?php namespace Vsb\Pne\Classes\Pne;

use Log;
use Vsb\Pne\Classes\PNE;
use Vsb\Pne\Classes\Pne\Response;
use Vsb\Pne\Classes\Pne\Request;

 //[error_message] => [processor-tx-id] => PNTEST-314201 [merchant_order] => 905 [orderid] => 314201 [client_orderid] => 905 [bin] => 444455 [control] => 10b4c5f9e64b52c0d0aa0fac0bf618aa2921083c [gate-partial-reversal] => enabled [descriptor] => GARAN24 - 3ds [gate-partial-capture] => enabled [type] => preauth [card-type] => VISA [phone] => +12063582043 [last-four-digits] => 1111 [card-holder-name] => garan24 [status] => approved
class CallbackResponse extends Response{
    protected $_callback=false;
    public function __construct($d=[
            "url" => "https://sandbox.ariuspay.ru/paynet/api/v2/",
            "request" => "",
            "endpoint" => "1144",
            "merchant_key" => "99347351-273F-4D88-84B4-89793AE62D94",
            "merchant_login" => "GARAN24",
            "operation" => "sale-form",
            "fields" => [
                "error-message","error-code","type","status","serial-number",
                "card-printed-name","bin","last-four-digits",
                "expire-year","expire-month"
            ],
            "control" => ["endpoint","client_orderid","amount","email","merchant_control"],
            "data" => []
        ],$callback=false,$version=PNE::PROTOCOL_VERSION_2){
        $arr = [];
        switch($version){
            case PNE::PROTOCOL_VERSION_2:
                parse_str($d["data"],$arr);
                break;
            case PNE::PROTOCOL_VERSION_3:
                $arr = json_decode($d["data"],true);
                break;
            }
        Log::debug("Version:".$version, $arr);
        parent::__construct([
            "url" => $d["url"],
            "request" => false,
            "fields" => [
                "error_message","processor-tx-id","merchant_order","orderid","client_orderid",
                "bin","control","gate-partial-reversal","descriptor","gate-partial-capture",
                "type","card-type","phone","last-four-digits","card-holder-name","status"
            ],
            "control" => [],
            "data" => $arr
        ],$version);
        $this->_callback =$callback;
    }
    public function accept(){
        switch(trim($this->_params["status"])){
            case "error":
                throw new Exception("Transaction is declined but something went wrong, please inform your account manager.",500);
            case "declined":
                throw new Exception("Transaction is declined.",500);
            case "filtered":
                throw new Exception("Transaction is declined by fraud internal or external control systems.",500);
            case "unknown":
            case "processing":
                return false;
            case "approved":
                $this->callback();
                return true;
        }
    }
    protected function callback(){
        $call = $this->_callback;
        return ($call!==false)?$call($this->_params):false;

    }
};
?>
