<?php namespace Vsb\Pne\Classes\Pne;
use Vsb\Pne\Classes\BaseConnector as BaseConnector;
use Vsb\Pne\Classes\Pne\Request as AriuspayRequest;
use Vsb\Pne\Classes\Pne\Exception  as Exception;
class Connector extends BaseConnector{
    public function __construct($req=false){
        if($req!==false) $this->setRequest($req);
    }
    public function setRequest($req){
        if($req instanceof AriuspayRequest) $this->_request = $req;
        else throw new Exception("Object ".preg_replace("/\\\/",".",get_class($req))." is not instance of Ariuspay Request object.",500);
    }
}
?>
