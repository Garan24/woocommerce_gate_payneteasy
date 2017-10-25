<?php namespace Vsb\Pne\Classes\Pne;
/*******************************************************************************
 ** GetCardRef Request Parameters
 * login	20/String	Merchant login name	Mandatory
 * cardrefid	20/String	Equals to card-ref-id obtained in Card Information Reference ID call during Card Registration stage	Mandatory
 * control	128/String	Checksum used to ensure that it is Merchant (and not a fraudster) that initiates the return request. This is SHA-1 checksum of the concatenation login + cardrefid + merchant_control.	Mandatory
 *******************************************************************************/
use Vsb\Pne\Classes\Pne\Request;
class ReturnRequest extends Request{
    public function __construct($d){
        $d["operation"] = "return";
        $d["fields"] = ["client_orderid","orderid","login","amount","currency","comment","control"];
        $d["control"] = ["login","client_orderid","orderid","amount","currency","merchant_control"];
        parent::__construct($d);
    }
}
?>
