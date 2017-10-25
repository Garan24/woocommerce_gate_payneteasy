<?php namespace Vsb\Pne\Classes;
class PNE{
    protected static $_debug = true;
    const PROTOCOL_VERSION_2 = 2;
    const PROTOCOL_VERSION_3 = 3;
    public static $_log_dir = "storage/logs";
    public static $DB = [
        "host"=>"localhost",
        "user"=>"u0173919_vbu01",
        "pass"=>"0aS8cj2G",
        "schema"=>"u0173919_grn01",
        "prefix"=>"gr1_"
    ];
    public static function setDebugMode($b = true){
        self::$_debug=$b;
    }
    public static function getRemoteIp(){
        $ip="";
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip;
    }
    public static function debug($mix){
        if(!self::$_debug)return;
        $str=self::obj2str($mix);
        // (class_exists("Log",false))?call_user_func("Log::debug",$str."\n"):file_put_contents(self::$_log_dir."/garan24-".date("Y-m-d").'.log',$str."\n",FILE_APPEND);
    }
    public static function obj2str($mix){
        $str="";
        if(is_array($mix)){
            foreach($mix as $k=>$v){
                $str.="\t{$k} = ".self::obj2str($v)."\n";
            }
            $str="array [\n".$str."]";
        }
        elseif (is_object($mix)) {
            $mix = json_decode(json_encode($mix),true);
            foreach($mix as $k=>$v){
                $str.="\t{$k} = ".self::obj2str($v)."\n";

            }
            $str="object [\n".$str."]";
        }
        else $str=$mix;
        return $str;
    }
    public static function obj2xml($dd,&$parent=null){
        $x = $parent;
        $d = $dd;
        if(is_null($x)){
            foreach ($d as $root => $v) {
                $x = simplexml_load_string(stripslashes("<?xml version='1.0' encoding='utf-8'?><{$root}></{$root}>"));
                $d=$v;
                break;
            }
        }
        foreach($d as $k=>$v){
            if($k=="@"){
                foreach($v as $ak=>$av){
                    $x->addAttribute($ak,$av);
                }
            }
            else if(is_array($v)){
                $node = $x->addChild($k);
                Garan24::obj2xml($v,$node);
            }else{
                $x->addChild($k,$v);
            }
        }
        return $x;
    }
    /**
    * The main function for converting to an XML document.
    * Pass in a multi dimensional array and this recrusively loops through and builds up an XML document.
    * Based on: http://snipplr.com/view/3491/convert-php-array-to-xml-or-simple-xml-object-if-you-wish/
    *
    * @param array $data
    * @param string $rootNodeName - what you want the root node to be - defaultsto data.
    * @param SimpleXMLElement $xml - should only be used recursively
    * @return string XML
    */
    public static function toXml($data, $rootNodeName = 'data', &$xml=null) {
       // turn off compatibility mode as simple xml throws a wobbly if you don't.
       if ( ini_get('zend.ze1_compatibility_mode') == 1 ) ini_set ( 'zend.ze1_compatibility_mode', 0 );
       if ( is_null( $xml ) ) {
           $xml = simplexml_load_string(stripslashes("<?xml version='1.0' encoding='utf-8'?><root xmlns:example='http://example.namespace.com' version='1.0'></root>"));
       }
       // loop through the data passed in.
       foreach( $data as $key => $value ) {
           // no numeric keys in our xml please!
           $numeric = false;
           if ( is_numeric( $key ) ) {
               $numeric = 1;
               $key = $rootNodeName;
           }

           // delete any char not allowed in XML element names
           $key = preg_replace('/[^a-z0-9\-\_\.\:]/i', '', $key);

           //check to see if there should be an attribute added (expecting to see _id_)
           $attrs = false;

           //if there are attributes in the array (denoted by attr_**) then add as XML attributes
           if ( is_array( $value ) ) {
               foreach($value as $i => $v ) {
                   $attr_start = false;
                   $attr_start = stripos($i, 'attr_');
                   if ($attr_start === 0) {
                       $attrs[substr($i, 5)] = $v; unset($value[$i]);
                   }
               }
           }


           // if there is another array found recursively call this function
           if ( is_array( $value ) ) {

               if ( Garan24::is_assoc( $value ) || $numeric ) {

                   // older SimpleXMLElement Libraries do not have the addChild Method
                   if (method_exists('SimpleXMLElement','addChild'))
                   {
                       $node = $xml->addChild( $key, null, 'http://www.lcc.arts.ac.uk/' );
                       if ($attrs) {
                           foreach($attrs as $key => $attribute) {
                               $node->addAttribute($key, $attribute);
                           }
                       }
                   }

               }else{
                   $node =$xml;
               }

               // recrusive call.
               if ( $numeric ) $key = 'anon';
               Garan24::toXml( $value, $key, $node );
           } else {

                   // older SimplXMLElement Libraries do not have the addChild Method
                   if (method_exists('SimpleXMLElement','addChild'))
                   {
                       $childnode = $xml->addChild( $key, $value, 'http://www.lcc.arts.ac.uk/' );
                       if ($attrs) {
                           foreach($attrs as $key => $attribute) {
                               $childnode->addAttribute($key, $attribute);
                           }
                       }
                   }
           }
       }

       // pass back as unformatted XML
       //return $xml->asXML('data.xml');

       // if you want the XML to be formatted, use the below instead to return the XML
       $doc = new DOMDocument('1.0');
       $doc->preserveWhiteSpace = false;
       @$doc->loadXML( Garan24::fixCDATA($xml->asXML()) );
       $doc->formatOutput = true;
       //return $doc->saveXML();
       return $doc->save('data.xml');
   }

   public static function fixCDATA($string) {
       //fix CDATA tags
       $find[]     = '&lt;![CDATA[';
       $replace[] = '<![CDATA[';
       $find[]     = ']]&gt;';
       $replace[] = ']]>';

       $string = str_ireplace($find, $replace, $string);
       return $string;
   }

    /**
    * Convert an XML document to a multi dimensional array
    * Pass in an XML document (or SimpleXMLElement object) and this recrusively loops through and builds a representative array
    *
    * @param string $xml - XML document - can optionally be a SimpleXMLElement object
    * @return array ARRAY
    */
    public static function toArray( $xml ) {
       if ( is_string( $xml ) ) $xml = new SimpleXMLElement( $xml );
       $children = $xml->children();
       if ( !$children ) return (string) $xml;
       $arr = array();
       foreach ( $children as $key => $node ) {
           $node = Garan24::toArray( $node );

           // support for 'anon' non-associative arrays
           if ( $key == 'anon' ) $key = count( $arr );

           // if the node is already set, put it into an array
           if ( isset( $arr[$key] ) ) {
               if ( !is_array( $arr[$key] ) || $arr[$key][0] == null ) $arr[$key] = array( $arr[$key] );
               $arr[$key][] = $node;
           } else {
               $arr[$key] = $node;
           }
       }
       return $arr;
   }
   // determine if a variable is an associative array
    public static function is_assoc( $array ) {
       return (is_array($array) && 0 !== count(array_diff_key($array, array_keys(array_keys($array)))));
    }
}
?>
