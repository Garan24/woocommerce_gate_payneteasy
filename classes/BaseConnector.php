<?php namespace Vsb\Pne\Classes;
class BaseConnector extends PNE{
    protected $_request;
    protected $_response;
    /*******************************************************************************
     * Производит перенаправление пользователя на заданный адрес
     *
     * @param string $url адрес
     ******************************************************************************/
    public function redirect($url){
        Header("HTTP 302 Found");
        Header("Location: ".$url);
        die();
    }
    /*******************************************************************************
     * Вызов метода REST.
     *
     * @param string $method вызываемый метод
     * @param array $data параметры вызова метода
     *
     * @return array
     ******************************************************************************/
    public function call(){
        $response_str = $this->query($this->_request->getUrl(),$this->_request->build(),$this->_request->headers());
        $this->_response = $this->_request->buildResponse($response_str);
        return $this->_response->toArray();
    }
    public function response($data){
        self::debug($data);
        $this->_response_data = $data;
        if(!isset($this->_response_data["type"])){
            throw new Exception(
                "Unknown message",
                500
            );
        }
        if(in_array(trim($this->_response_data["status"]),['declined'])){
            throw new Exception(
                isset($this->_response_data["error-message"])?$this->_response_data["error-message"]:"Unknown message",
                isset($this->_response_data["error-code"])?$this->_response_data["error-code"]:500
            );
        }
    }
    /*******************************************************************************
     * Вызов метода REST.
     *
     * @param string $method вызываемый метод
     * @param array $data параметры вызова метода
     *
     * @return array
     ******************************************************************************/
    public function call2(){
        $this->build();
        $this->_response_data = $this->query($this->_method,$this->_request_data);
        if(!isset($this->_response_data["type"])){
            throw new Exception("Error in response. Wrong format",500);
        }
        if(in_array(trim($this->_response_data["type"]),["validation-error","error"])){
            throw new Exception(
                isset($this->_response_data["error-message"])?$this->_response_data["error-message"]:"Unknown message",
                isset($this->_response_data["error-code"])?$this->_response_data["error-code"]:500
            );
        }
        if(isset($this->_response_data["redirect-url"])) $this->redirect($this->_response_data["redirect-url"]);
        return true;
    }
    /*******************************************************************************
     * Совершает запрос с заданными данными по заданному адресу. В ответ
     * ожидается JSON
     *
     * @param string $url
     * @param array|null $data POST-данные
     *
     * @return array
     ******************************************************************************/
    public function query($url,$data = null,$headers=[]){
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_VERBOSE => 0,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => $headers
        ];
        $curl = curl_init($url);
        curl_setopt_array($curl, $curlOptions);
        $response = curl_exec($curl);
        return $response;
    }
    public function getRequest(){
        return $this->_request;
    }
    public function getResponse(){
        return $this->_response;
    }
};
?>
