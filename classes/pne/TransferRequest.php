<?php namespace Vsb\Pne\Classes\Pne;
/*******************************************************************************
 ** GetCardRef Request Parameters
 * login	20/String	Merchant login name	Mandatory
 * cardrefid	20/String	Equals to card-ref-id obtained in Card Information Reference ID call during Card Registration stage	Mandatory
 * control	128/String	Checksum used to ensure that it is Merchant (and not a fraudster) that initiates the return request. This is SHA-1 checksum of the concatenation login + cardrefid + merchant_control.	Mandatory
 *******************************************************************************/

class TransferRequest extends Request{
    public function __construct($d,$v){
        $d["operation"] = "transfer-form";
        // $d["fields"] = ["client_orderid","order_desc","card_printed_name","first_name","last_name","ssn","birthday","address1","city","state","zip_code","country","phone","cell_phone","email","amount","currency","credit_card_number","expire_month","expire_year","cvv2","ipaddress","site_url","purpose","control","redirect_url","server_callback_url","cardref"];
        $d["control"] = ["endpoint","client_orderid","amount","email","merchant_control"];
        parent::__construct($d,$v);
    }
}
?>
