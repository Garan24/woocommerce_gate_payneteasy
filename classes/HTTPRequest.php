<?php namespace Vsb\Pne\Classes;
class HTTPRequest extends Parameters{
    protected $_url;
    public function __construct($d=[
            "url" => "https://sandbox.ariuspay.ru/paynet/api/v2/",
            "fields" => ["client_orderid","orderid","order_desc","cardref",
                    "first_name","last_name","ssn","birthday","address1","city","state",
                    "zip_code","country","phone","cell_phone","amount","email",
                    "currency","ipaddress","site_url","credit_card_number",
                    "card_printed_name","expire_month","expire_year","cvv2","purpose",
                    "redirect_url","server_callback_url","merchant_data",
                    "merchant_control","control","login"],
            "data" => []
        ]){
        parent::__construct($d["fields"],$d["data"]);
        $this->_url = $d["url"];
    }
    public function getUrl(){
        return $this->_url;
    }
    public function __toString(){
        return "URL:".$this->getUrl().PNE::obj2str($this->_params);
    }
    public function buildResponse($res){
        $rs = new HTTPResponse([
            "url"=>$this->_url,
            "request"=>$this->build(),
            "data" => $res
        ]);
        return $rs;
    }
}
?>
