<?php namespace Vsb\Pne\Classes;
class Log{
    public static function debug(){
        foreach(func_get_args() as $arg){
            $v = ((is_object($arg)))?json_encode($arg):$arg;

            error_log($v);
        }
    }
};
?>
