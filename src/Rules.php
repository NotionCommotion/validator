<?php
namespace Greenbean\Validator;
/**
* Standard rules.
*
* All rules will be passed $value, $prop, and $name, and should return error message string or false
*/
class Rules {

    public function required($value, $prop, $name){
        return ($prop && (is_null($value) || (is_string($value) && trim($value)==='')))?"$name is required":false;
    }
    public function remote($value, $prop, $name){
        //Validated via separate call.
        return false;
    }
    public function minlength($value, int $length, $name){
        return ( ($str=trim($value)) && strlen($str)<$length)?"$name requires $length characters":false;
    }
    public function maxlength($value, int $length, $name){
        return (strlen(trim($value))>$length)?"$name allows no more than $length characters":false;
    }
    public function rangelength($value, array $rangelength, $name){
        if(!isset($rangelength[0]) || !isset($rangelength[1])) throw new ValidatorException('rangelength must be a two dimentional array');
        if(!is_numeric($rangelength[0]) || !is_numeric($rangelength[1])) throw new ValidatorException('rangelength must contain numbers');
        return $value>=$r[0] && $value<=$r[1]?false:"$name must be between $r[0] and $r[1]";
    }
    public function min($value, $min, $name){
        return ($value && $value<$min)?"$name must be greater or equal to $min":false;
    }
    public function max($value, $max, $name){
        return ($value && $value>$max)?"$name must be less than or equal to $max":false;
    }
    public function range($value, array $range, $name){
        if(!isset($range[0]) || !isset($range[1])) throw new ValidatorException('range must be a two dimentional array');
        if(!is_numeric($range[0]) || !is_numeric($range[1])) throw new ValidatorException('range must contain numbers');
        return strlen($value)>=$r[0] && strlen($value)<=$r[1]?false:"{$name}'s length must be between $r[0] and $r[1] characters";
    }
    public function array_range($value, array $range, $name){
        if(!isset($range[0]) || !isset($range[1])) throw new ValidatorException('range must be a two dimentional array');
        if(!is_numeric($range[0]) || !is_numeric($range[1])) throw new ValidatorException('range must contain numbers');
        return count($value)>=$r[0] && count($value)<=$r[1]?false:"{$name}'s array length must be between $r[0] and $r[1] characters";
    }
    public function step($value, $step, $name){
        return $value % $step?"$name is not a step of $step":false;
    }
    public function email($value, $prop, $name){
        //return (!$prop || !trim($value) || preg_match('/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3})$/i', $value) )?false:'Invalid email';
        return (!$prop || !trim($value) || filter_var($value, FILTER_VALIDATE_EMAIL) )?false:'Invalid email';
    }
    public function url($value, $prop, $name){
        return (!$prop || !trim($value) || filter_var($value, FILTER_VALIDATE_URL) )?false:'Invalid URL';
    }
    public function date($value, $prop, $name){
        if (!$prop || (!is_null($value) && trim($value)!='')) return false;
        try {
            $date = new \DateTime($value);
            return false;
        } catch (\Exception $e) {
            return "Invalid date for $name";
        }
    }
    public function dateISO($value, $prop, $name){
        return !$prop || preg_match('/^([\+-]?\d{4}(?!\d{2}\b))((-?)((0[1-9]|1[0-2])(\3([12]\d|0[1-9]|3[01]))?|W([0-4]\d|5[0-2])(-?[1-7])?|(00[1-9]|0[1-9]\d|[12]\d{2}|3([0-5]\d|6[1-6])))([T\s]((([01]\d|2[0-3])((:?)[0-5]\d)?|24\:?00)([\.,]\d+(?!:))?)?(\17[0-5]\d([\.,]\d+)?)?([zZ]|([\+-])([01]\d|2[0-3]):?([0-5]\d)?)?)?)?$/', $dateStr) > 0
        ?false:$name.' is not a ISO date';
    }
    public function number($value, $prop, $name){
        return (!$prop || !trim($value) || is_numeric($value))?false:"$name is not a number";
    }
    public function digits($value, $prop, $name){
        return (!$prop || !trim($value) || ctype_digit($value) || $value===(int)$value)?false:"$name is not a digit";
    }

    public function equalTo($value, $value2, $name){
        return $value==$value2?false:"$name is not equal to $value2";
    }

    //additional-methods.js

    public function accept($value, $mime, $name){
        return 'accept validation is not yet complete.';
    }
    public function creditcard($value, $number, $name){
        return preg_match("/^4[0-9]{12}(?:[0-9]{3})?$/",$number)   //visa
        || preg_match("/^5[1-5][0-9]{14}$/",$number)   //mastercard
        || preg_match("/^3[47][0-9]{13}$/",$number)   //amex
        || preg_match("/^6(?:011|5[0-9]{2})[0-9]{12}$/",$number)   //discover
        ?false:"$name is not a valid credit card.";
    }
    public function extension($value, $mime, $name){
        return 'extension validation is not yet complete.';
    }
    public function phoneUS($value, $prop, $name){
        //return (!$prop || !trim($value) || preg_match("/^([1]-)?[0-9]{3}-[0-9]{3}-[0-9]{4}$/i",$value))?false:'Invalid phone number';
        //Only works if first sanitized with phoneUS
        return (!$prop || !trim($value) || strlen((int)$value)==10)?false:'Invalid phone number';
    }
    public function require_from_group($value, $prop, $name){
        //Return true if value provided for one of the given names which has the same ID
        //Supports by ID using "#seriesName, #seriesId" and by name using "[name=categoriesName],[name=categoriesId]" only
        //syslog(LOG_INFO,"require_from_group $value ".json_encode($prop)." $name");
        $selections=explode(',',str_replace(' ', '', $prop[1]));
        $names=[];
        $count=0;
        foreach($selections as $select) {
            $pattern='/^(?|#(.+)|\[name=(.+)\])$/';
            //$pattern='/(?<=#|name=)([^\[#\]]+)/';
            preg_match($pattern, $select, $matches);
            if(empty($matches) || count($matches)!=2) throw new ValidatorException('Invalid name target '.$select);
            $names[]=$matches[1];
            if(isset($this->params[$matches[1]])) $count++;
        }
        return ($count>=$prop[0])?false:"At least $prop[0] of ".implode(', ',$names).' are required';
    }

    //Custom rules

    public function string($value, $prop, $name){
        return (!$prop || !trim($value) || is_string($value))?false:"$name is not a string";
    }
    public function bool($value, $prop, $name){
        return (!$prop || is_bool($value))?false:"$name is not boolean";
    }
    public function exactlength($value, $prop, $name){
        return (strlen(trim($value))!=$prop)?"$name requires exactly $prop characters":false;
    }
    public function longitude($value, $prop, $name){
        return (!$prop || !trim($value) || ($value<=180))?false:'Invalid longitude';
    }
    public function latitude($value, $prop, $name){
        return (!$prop || !trim($value) || ($value<=90))?false:'Invalid latitude';
    }
    public function loginRegex($value, $prop, $name){
        return (!$prop || !trim($value) || preg_match("/^[a-z0-9._]+$/i",$value))?false:'Username must contain only letters, numbers, underscore, or period';
    }
    public function noInvalid($value, $prop, $name){
        return (!$prop || !trim($value) || preg_match("/^[a-z0-9.,-_()& ]+$/i",$value))?false:'Invalid characters';
    }
    public function domain($value, $prop, $name){
        return (!$prop || !trim($value) || preg_match("/^[a-z0-9_-]+$/i",$value))?false:'Alphanumerical, underscore, and hyphes only';
    }
    public function filename($value, $prop, $name){
        return (!$prop || !trim($value) || (strpbrk($value, "\\/%*:|\"<>") === FALSE))?false:'Invalid file name';
    }
    public function validIP($value, $prop, $name){
        return (!$prop || !trim($value) || filter_var($pi->$property, FILTER_VALIDATE_IP))?false:'IP Addresses must have format xxx.xxx.xxx.xxx';
    }
    public function isUSstate($value, $prop, $name){
        $states=['AA'=>1,'AE'=>1,'AL'=>1,'AK'=>1,'AS'=>1,'AP'=>1,'AZ'=>1,'AR'=>1,'CA'=>1,'CO'=>1,'CT'=>1,'DE'=>1,'DC'=>1,'FM'=>1,'FL'=>1,'GA'=>1,'GU'=>1,'HI'=>1,'ID'=>1,'IL'=>1,'IN'=>1,'IA'=>1,'KS'=>1,'KY'=>1,'LA'=>1,'ME'=>1,'MH'=>1,'MD'=>1,'MA'=>1,'MI'=>1,'MN'=>1,'MS'=>1,'MO'=>1,'MT'=>1,'NE'=>1,'NV'=>1,'NH'=>1,'NJ'=>1,'NM'=>1,'NY'=>1,'NC'=>1,'ND'=>1,'MP'=>1,'OH'=>1,'OK'=>1,'OR'=>1,'PW'=>1,'PA'=>1,'PR'=>1,'RI'=>1,'SC'=>1,'SD'=>1,'TN'=>1,'TX'=>1,'UT'=>1,'VT'=>1,'VI'=>1,'VA'=>1,'WA'=>1,'WV'=>1,'WI'=>1,'WY'=>1];
        return (!$prop || !trim($value) || isset($states[$value]))?false:'Must be a US State';
    }
    public function timezone($value, $prop, $name){
        //both timezone and timezoneId do the same thing. timezone is probably better.
        if(!$prop || !trim($value)) return false;
        try{
            new \DateTimeZone($value);
        }catch(\Exception $e){
            return "Invalid timezone ID '$value'";
        }
        return FALSE;
    }
    public function timezoneId($value, $prop, $name){
        return (!$prop || !trim($value) || (in_array($value, DateTimeZone::listIdentifiers())))?false:"Invalid timezone '$value'";
    }
    public function validIPList($value, $prop, $name){
        $valid=true;
        if($prop || trim($value)) {
            $ips=explode($value,',');
            foreach($ips as $ip) {
                if($this->validIP($ip)) {$valid=false; break;}
            }
        }
        return ($valid)?false:'IP Addresses must have format xxx.xxx.xxx.xxx';
    }
    public function inArray($value, $prop, $name){
        return (!$value || in_array($value, $prop))?false:"$name must be one of: ".implode(', ',$prop);
    }
    public function isObject($value, $prop, $name){
        return (!$prop || is_object($value))?false:"$name is not an object";
    }
    public function isArray($value, $prop, $name){
        return (!$prop || is_array($value))?false:"$name is not an array";
    }
    public function isSequntialArray($value, $prop, $name){
        return (!$prop || (is_array($value) && array_values($value) === $value))?false:"$name is not a sequential array";
    }
    public function isSequntialIntArray($value, $prop, $name){
        if($error=$this->isSequntialArray($value, $prop, $name)) {
            return $error;
        }
        if($error=array_filter($value, function($v){return !is_int(reset($v));})) {
            $index=key(reset($value));
            $error=implode(', ', array_keys($error));
            return "$name $index indexes $error must only contain integers";
        }
        return false;
    }
    public function isSequntialDigitArray($value, $prop, $name){
        if($error=$this->isSequntialArray($value, $prop, $name)) {
            return $error;
        }
        if($error=array_filter($value, function($v){return !ctype_digit(reset($v));})) {
            $index=key(reset($value));
            $error=implode(', ', array_keys($error));
            return "$name $index indexes $error must only contain integers";
        }
        return false;
    }
    public function noServer($value, $prop, $name){
        // Used for client side only validation
        return false;
    }
}