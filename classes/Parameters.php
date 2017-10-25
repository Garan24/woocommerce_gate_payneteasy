<?php namespace Vsb\Pne\Classes;
class Parameters{
    protected $_params = [];
    protected $_avaliable = [];
    public function __construct($a,$p=[]){
        // $this->_avaliable = array_keys($a);
        $this->_avaliable = $a;
        $this->Data($p);
    }
    public function __set($k,$v){
        if(!isset($this->_avaliable["{$k}"])){
            throw new Exception("No parameter {$k} allowed.",403);
        }
        $this->_params["{$k}"] = $v;
    }
    public function __get($k){
        if(!in_array($k,$this->_avaliable)){
            throw new Exception("No parameter [{$k}] allowed. ".PNE::obj2str($this->_avaliable),403);
        }
        return (isset($this->_params["{$k}"])?$this->_params["{$k}"]:'');
    }
    public function __isset($k){
        return isset($this->_avaliable["{$k}"]);
    }
    public function __unset($k){
        return;
    }
    public function Data($p=[]){
        foreach ($p as $key => $value) {
            $k = $key;//strtolower(trim($key));
            if(in_array($k,$this->_avaliable)){
                $this->_params["{$k}"] = $p["{$k}"];
            }
        }
    }
    public function getAvaliableFields(){
        return $this->_avaliable;
    }
    public function toJSON(){
        return json_encode($this->_params,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    }
    public function toArray(){
        return $this->_params;
    }
}
?>
