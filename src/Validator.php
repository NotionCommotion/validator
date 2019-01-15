<?php
/**
* Creates jQueryValidate client side code and validates server side
* Copyright Michael Reed, 2013
* Dual licensed under the MIT and GPL licenses.
* @version 1.1
*/

/**
* Creates https://jqueryvalidation.org client side options and validates server side using a developer provided JSON string.
*
* All $.validate standard rules and additional-methods.js are supported as is except for:
*   - serverOnly rules are not provided to the client
*   - noServer rules are just used by the client
*   - The "remote" is not directly processed by this class, and it is expected that the developer will direct the call to an endpoint which calls the validateProperty() method.
*   - accept and extention is not complete.
*
* In addition, the following rules are provided:
*   - string
*   - exactlength
*   - longitude
*   - latitude
*   - loginRegex
*   - noInvalid
*   - domain
*   - filename
*   - validIP
*   - isUSstate
*   - timezone
*   - validIPList
*   - inArray
*
* Since JSON cannot contain JavaScript annonomous functions, the JSON string must be written as {"_function": "return $( '#someID' ).val()"}}}}
* and $.validator.createRemoteFromJSON be used client side to write.
*
* Sanitizers are described in a simalar format, and are used server-side only.  Similar to rules, they can be defined as simple or complex.
* Standard supported sanitizers are specified under $this->sanitizers.
*/

namespace Greenbean\Validator;

class Validator
{
    private
    //$this->options is an array which is very similar to jQUery validator's JavaScript options
    //In addition, it has a "sanitize" value which specifies which elements of the provided data are to be included, and if empty will include all.
    $options,
    //The exception to throw upon validation error.  If set, will throw this exception upon error, else will return array of errors.
    $exceptionPath,
    //$this->sanitizeDefaults will specify what to set the value to if not in the form, and if not given unless specified here
    $sanitizeDefaults=[
        'arrayInt'=>[],
        'arrayMult'=>[],
        'arrayNum'=>[],
        'array'=>[],
        //'object'=>new \stdClass(),
        //'bool'=>false,
        //'boolInt'=>0
    ],
    $rules, $sanitizers,    //Will be populated in the constructor with the default rules and sanitizers plus any developer provided ones.
    $config=[
        'setUndefinedValues'=>true,     //Used with sanitize to set undefined values in data to null
        'debug'=>true                   //syslog if requested validation or sanitization method doesn't exist
    ],
    $debugValidators, $debugSanitizers; //Internally used

    /**
    * Receives rules/message/sanitizer JSON string.
    * $exceptionPath is the exception that will be thrown
    * $rules and $sanitizers are both an associated array with closure as their values and will be added to the default rules and sanitizers
    */
    public function __construct(string $json, string $exceptionPath=null, array $customRules=[], array $customSanitizers=[], array $config=[]) {
        if($errors=array_diff_key($config, array_keys($this->config))) $errors=['Invalid config properties: '.implode(', ', $err)];
        $this->config=array_merge($this->config, $config);
        if(!$options=json_decode($json, true))$errors[]='Invalid JSON: '.$this->json_error();
        $this->options=array_merge(['rules'=>[],'sanitizers'=>[]],$options);
        //Can use ReflectionFunction to test number of arguements and maybe returned type, but I currently don't bother
        $used=[];
        foreach($customRules as $name=>$closure) {
            if(is_numeric($name)) $errors[]='All custom rules must have non-numerical strings as their name';
            elseif($name='function') $errors[]='Custom rules may not use the name "function"';
            elseif(isset($used[$name])) $errors[]="Custom rules '$name' has already been defined";
            if(!is_object($closure) || !($t instanceof \Closure))  $errors[]='All custom rules must be closure';
            $used[$name]=null;
        }
        $used=[];
        foreach($customSanitizers as $name=>$closure) {
            if(is_numeric($name)) $errors[]='All custom sanitizers must have non-numerical strings as their name';
            elseif(isset($used[$name])) $errors[]="Custom rules '$name' has already been defined";
            if(!is_object($closure) || !$closure instanceof \Closure)  $errors[]='All custom sanitizers must be closure';
            $used[$name]=null;
        }
        if ($errors) throw new ValidatorException(implode(', ', $errors));
        $this->exceptionPath=$exceptionPath;
        $this->rules=$customRules?array_merge($this->getRules(), $customRules):$this->getRules();
        $this->sanitizers=$customSanitizers?array_merge($this->getSanitizers(), $customSanitizers):$this->getSanitizers();
    }

    public function getJSON(bool $asJson=false) {
        //Future.  Go through rules and make into JavaScript those that have the name "function"
        //Future.  insert values in the JSON using $this->parse($json, $parse);
        $options=$this->options;
        $options['rules']=array_diff_key($this->options['rules'], ['serverOnly'=>null]);
        unset($options['sanitizers']);
        return $asJson?json_encode($options):$options;
    }

    public function validateProperty(string $name, $value, bool $suppressExceptions=null):?string {
        return $this->validate([$name=>$value], $suppressExceptions);
    }

    public function sanitizeProperty(string $name, $value, bool $setUndefinedValues = null):?string {
        return $this->sanitize([$name=>$value], $setUndefinedValues)[$name];
    }

    public function validate(array $data, bool $suppressExceptions=null):?array {
        $debug=[];
        $this->params=$data; //Used for require_from_group
        $errors=[];

        foreach($this->options['rules'] AS $name=>$rule) {
            if(is_array($rule)) {
                // compound rule
                foreach ($rule AS $method=>$option) {
                    if(is_int($method)) { //Sequencial array of methods
                        $this->_validate($errors, $option, $name, $data[$name]??null, true);
                    }
                    else { //Associated array method=>options
                        $this->_validate($errors, $method, $name, $data[$name]??null, $option);
                    }
                }
            }
            else {
                // simple rule, converted to {theRule:true}
                $this->_validate($errors, $method, $name, $data[$name]??null, true);
            }

        }
        if($this->config['debug'] && $this->debugValidators) syslog(LOG_ERR, 'Validator::validate() warning: '.implode(', ', $this->debugValidators));

        if($errors){
            //Format the errors and either throw and exception or return the errors based on whether $this->exceptionPath is defined
            if($this->exceptionPath && !$suppressExceptions) {
                foreach($errors as $name=>&$error) {
                    $error="$name ".implode(', ',$error);
                }
                throw new $this->exceptionPath(implode(' | ',$e));
            }
            foreach($errors as &$error) {
                $error=implode(', ',$error);
            }
        }
        return $errors?$errors:null;
    }

    public function sanitize(array $data, bool $setUndefinedValues = null):array {
        $this->debugSanitizers=[];
        if(empty($this->options['sanitizers'])) return $data;
        $sanitized=[];
        foreach ($this->options['sanitizers'] AS $name=>$sanitizer) {
            syslog(LOG_ERR, "name: $name");
            if(isset($data[$name])) {
                if(is_array($sanitizer)) {
                    // compound sanitizer
                    foreach ($sanitizer AS $key=>$value) {
                        $sanitized[$name]=is_int($key)
                        ?$this->_sanitize($value, $name, $data[$name]) //Sequencial array of methods
                        :$this->_sanitize($key, $name, $data[$name], $value);   //Associated array method=>options
                        $data[$name]=$sanitized[$name];                 //Required if element has multiple sanitizers
                    }
                }
                else {
                    // simple sanitizer, converted to {theSanitizer:true}
                    $sanitized[$name]=$this->_sanitize($sanitizer, $name, $data[$name]);
                }
            } elseif($setUndefinedValues??$this->config['setUndefinedValues']) {
                // Set the default value since none was given
                if(is_array($sanitizer)) {
                    foreach ($sanitizer AS $key=>$value) {
                        $method=is_int($key)?$value:$key;
                        if(isset($this->sanitizeDefaults[$method])) {
                            $sanitized[$name]=$this->sanitizeDefaults[$method];
                            break;
                        }
                        $sanitized[$name]=null;
                    }
                }
                else {
                    $sanitized[$name]=$this->sanitizeDefaults[$sanitizer]??null;
                }
            }
            //else do not include
        }
        if($this->config['debug'] && $this->debugSanitizers) syslog(LOG_ERR, 'Validator::sanitize() warning: '.implode(', ', $this->debugSanitizers));
        return $sanitized;
    }

    private function _validate(array &$errors, string $method, string $name, $value, $prop):void {
        if(isset($this->rules[$method])) {
            if($error=$this->rules[$method]($value, $prop, $name)) {
                $errors[$name][]=$this->options['messages'][$name]??$error;
            }
        }
        else {
            $this->debugValidation[]="Rule '$method' for '$name' does not exist";
        }
    }

    private function _sanitize(string $method, string $name, $value, $extra=null) {
        if(isset($this->sanitizers[$method])) return $this->sanitizers[$method]($value, $extra);
        else {
            $this->debugSanitizers[]="Sanitizer '$method' for '$name' does not exist";
            return $value;
        }
    }

    private function getRules():array {
        //$this->rules is an associated array where the keys are the name of the rule and the value is closure which accepts three arguements and returns the default error message if errors else false.
        return [

            //$.validate standard rules

            "required"=>function($value, $prop, $name){
                return (!$prop || (!is_null($value) && trim($value)!=''))?false:"$name is required";
            },
            "remote"=>function($value, $prop, $name){
                //Validated via separate call.
                return false;
            },
            "minlength"=>function($value, int $length, $name){
                return ( ($str=trim($value)) && strlen($str)<$length)?"$name requires $length characters":false;
            },
            "maxlength"=>function($value, int $length, $name){
                return (strlen(trim($value))>$length)?'$name allows no more than $length characters':false;
            },
            "rangelength"=>function($value, array $rangelength, $name){
                if(!isset($rangelength[0]) || !isset($rangelength[1])) throw new ValidatorException('rangelength must be a two dimentional array');
                if(!is_numeric($rangelength[0]) || !is_numeric($rangelength[1])) throw new ValidatorException('rangelength must contain numbers');
                return $value>=$r[0] && $value<=$r[1]?false:"$name must be between $r[0] and $r[1]";
            },
            "min"=>function($value, $min, $name){
                return ($value && $value<$min)?'$name must be greater or equal to $min':false;
            },
            "max"=>function($value, $max, $name){
                return ($value && $value>$max)?'$name must be less than or equal to $max':false;
            },
            "range"=>function($value, array $range, $name){
                if(!isset($range[0]) || !isset($range[1])) throw new ValidatorException('range must be a two dimentional array');
                if(!is_numeric($range[0]) || !is_numeric($range[1])) throw new ValidatorException('range must contain numbers');
                return strlen($value)>=$r[0] && strlen($value)<=$r[1]?false:"{$name}'s length must be between $r[0] and $r[1] characters";
            },
            "step"=>function($value, $step, $name){
                return $value % $step?"$name is not a step of $step":false;
            },
            "email"=>function($value, $prop, $name){
                //return (!$prop || !trim($value) || preg_match('/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3})$/i', $value) )?false:'Invalid email';
                return (!$prop || !trim($value) || filter_var($value, FILTER_VALIDATE_EMAIL) )?false:'Invalid email';
            },
            "url"=>function($value, $prop, $name){
                return (!$prop || !trim($value) || filter_var($value, FILTER_VALIDATE_URL) )?false:'Invalid URL';
            },
            "date"=>function($value, $prop, $name){
                if (!$prop || (!is_null($value) && trim($value)!='')) return false;
                try {
                    $date = new \DateTime($value);
                    return false;
                } catch (\Exception $e) {
                    return "Invalid date for $name";
                }
            },
            "dateISO"=>function($value, $prop, $name){
                return !$prop || preg_match('/^([\+-]?\d{4}(?!\d{2}\b))((-?)((0[1-9]|1[0-2])(\3([12]\d|0[1-9]|3[01]))?|W([0-4]\d|5[0-2])(-?[1-7])?|(00[1-9]|0[1-9]\d|[12]\d{2}|3([0-5]\d|6[1-6])))([T\s]((([01]\d|2[0-3])((:?)[0-5]\d)?|24\:?00)([\.,]\d+(?!:))?)?(\17[0-5]\d([\.,]\d+)?)?([zZ]|([\+-])([01]\d|2[0-3]):?([0-5]\d)?)?)?)?$/', $dateStr) > 0
                ?false:$name.' is not a ISO date';
            },
            "number"=>function($value, $prop, $name){
                return (!$prop || !trim($value) || is_numeric($value))?false:'$name is not a number';
            },
            "digits"=>function($value, $prop, $name){
                return (!$prop || !trim($value) || ctype_digit($value) || $value===(int)$value)?false:'$name is not a digit';
            },

            "equalTo"=>function($value, $value2, $name){
                return $value==$value2?false:"$name is not equal to $value2";
            },

            //additional-methods.js

            "accept"=>function($value, $mime, $name){
                return 'accept validation is not yet complete.';
            },
            "creditcard"=>function($value, $number, $name){
                return preg_match("/^4[0-9]{12}(?:[0-9]{3})?$/",$number)   //visa
                || preg_match("/^5[1-5][0-9]{14}$/",$number)   //mastercard
                || preg_match("/^3[47][0-9]{13}$/",$number)   //amex
                || preg_match("/^6(?:011|5[0-9]{2})[0-9]{12}$/",$number)   //discover
                ?false:"$name is not a valid credit card.";
            },
            "extension"=>function($value, $mime, $name){
                return 'extension validation is not yet complete.';
            },
            "phoneUS"=>function($value, $prop, $name){
                //return (!$prop || !trim($value) || preg_match("/^([1]-)?[0-9]{3}-[0-9]{3}-[0-9]{4}$/i",$value))?false:'Invalid phone number';
                //Only works if first sanitized with phoneUS
                return (!$prop || !trim($value) || strlen((int)$value)==10)?false:'Invalid phone number';
            },
            "require_from_group"=>function($value, $prop, $name){
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
            },

            //Custom rules

            "string"=>function($value, $prop, $name){
                return (!$prop || !trim($value) || is_string($value))?false:'$name is not a string';
            },
            "exactlength"=>function($value, $prop, $name){
                return (strlen(trim($value))!=$prop)?'$name requires exactly $prop characters':false;
            },
            "longitude"=>function($value, $prop, $name){
                return (!$prop || !trim($value) || ($value<=180))?false:'Invalid longitude';
            },
            "latitude"=>function($value, $prop, $name){
                return (!$prop || !trim($value) || ($value<=90))?false:'Invalid latitude';
            },
            "loginRegex"=>function($value, $prop, $name){
                return (!$prop || !trim($value) || preg_match("/^[a-z0-9._]+$/i",$value))?false:'Username must contain only letters, numbers, underscore, or period';
            },
            "noInvalid"=>function($value, $prop, $name){
                return (!$prop || !trim($value) || preg_match("/^[a-z0-9.,-_()& ]+$/i",$value))?false:'Invalid characters';
            },
            "domain"=>function($value, $prop, $name){
                return (!$prop || !trim($value) || preg_match("/^[a-z0-9_-]+$/i",$value))?false:'Alphanumerical, underscore, and hyphes only';
            },
            "filename"=>function($value, $prop, $name){
                return (!$prop || !trim($value) || (strpbrk($value, "\\/%*:|\"<>") === FALSE))?false:'Invalid file name';
            },
            "validIP"=>function($value, $prop, $name){
                return (!$prop || !trim($value) || filter_var($pi->$property, FILTER_VALIDATE_IP))?false:'IP Addresses must have format xxx.xxx.xxx.xxx';
            },
            "isUSstate"=>function($value, $prop, $name){
                $states=['AA'=>1,'AE'=>1,'AL'=>1,'AK'=>1,'AS'=>1,'AP'=>1,'AZ'=>1,'AR'=>1,'CA'=>1,'CO'=>1,'CT'=>1,'DE'=>1,'DC'=>1,'FM'=>1,'FL'=>1,'GA'=>1,'GU'=>1,'HI'=>1,'ID'=>1,'IL'=>1,'IN'=>1,'IA'=>1,'KS'=>1,'KY'=>1,'LA'=>1,'ME'=>1,'MH'=>1,'MD'=>1,'MA'=>1,'MI'=>1,'MN'=>1,'MS'=>1,'MO'=>1,'MT'=>1,'NE'=>1,'NV'=>1,'NH'=>1,'NJ'=>1,'NM'=>1,'NY'=>1,'NC'=>1,'ND'=>1,'MP'=>1,'OH'=>1,'OK'=>1,'OR'=>1,'PW'=>1,'PA'=>1,'PR'=>1,'RI'=>1,'SC'=>1,'SD'=>1,'TN'=>1,'TX'=>1,'UT'=>1,'VT'=>1,'VI'=>1,'VA'=>1,'WA'=>1,'WV'=>1,'WI'=>1,'WY'=>1];
                return (!$prop || !trim($value) || isset($states[$value]))?false:'Must be a US State';
            },
            "timezone"=>function($value, $prop, $name){
                //both timezone and timezoneId do the same thing. timezone is probably better.
                if(!$prop || !trim($value)) return false;
                try{
                    new \DateTimeZone($value);
                }catch(\Exception $e){
                    return "Invalid timezone ID '$value'";
                }
                return FALSE;
            },
            "timezoneId"=>function($value, $prop, $name){
                return (!$prop || !trim($value) || (in_array($value, DateTimeZone::listIdentifiers())))?false:"Invalid timezone '$value'";
            },
            "validIPList"=>function($value, $prop, $name){
                $valid=true;
                if($prop || trim($value)) {
                    $ips=explode($value,',');
                    foreach($ips as $ip) {
                        if($this->validIP($ip)) {$valid=false; break;}
                    }
                }
                return ($valid)?false:'IP Addresses must have format xxx.xxx.xxx.xxx';
            },
            "inArray"=>function($value, $prop, $name){
                return (in_array($value, $prop))?false:$name.' must be one of: '.implode(', ',$prop);
            },
            "isObject"=>function($value, $prop, $name){
                return (!$prop || is_object($value))?false:$name.' is not an object';
            },
            "isArray"=>function($value, $prop, $name){
                return (!$prop || is_array($value))?false:$name.' is not an array';
            },
            "isSequntialArray"=>function($value, $prop, $name){
                return (!$prop || (is_array($value) && array_values($value) === $value))?false:$name.' is not a sequential array';
            },
            "noServer"=>function($value, $prop, $name){
                // Used for client side only validation
                return false;
            },
        ];
    }
    private function getSanitizers() {
        //$this->sanitizers is an associated array where the keys are the name of the sanitizer and the value is closure which accepts two arguements and sanitizes the value.
        return [
            "none"=>function($value){
                return $value;
            },
            "strtolower"=>function($value){
                return strtolower($value);
            },
            "strtoupper"=>function($value){
                return strtoupper($value);
            },
            "trim"=>function($value){
                return trim($value);
            },
            "string"=>function($value){
                return (string)$value;
            },
            "int"=>function($value){
                return (int)$value;
            },
            "boolval"=>function($value){
                return boolval($value);
            },
            "boolInt"=>function($value){
                //Instead of true/false, returns 1/2
                return $value?1:0;
            },
            "yes_no"=>function($value){
                return $value=='y'?1:0;
            },
            "true_false"=>function($value){
                return $value=='t'?1:0;
            },
            "intNULL"=>function($value){
                return (int)$value?$value:null;
            },
            "array"=>function($value){
                return (array)$value;
            },
            "object"=>function($value){
                return (object)$value;
            },
            "arrayInt"=>function($value){
                foreach($value as &$val){
                    $val=(int)$val;
                }
                return $value;
            },
            "arrayMult"=>function($value, $prop=null){
                //Future. Add similar validatin for arrayMult
                if(!$prop) throw new ValidatorException('arrayMulti sanitizer requires an array definition.  i.e. "id|sign"');
                $indexes=array_flip(explode('|',$prop));
                foreach($value as &$val){
                    $val=array_intersect_key($val, $indexes);
                }
                return $value;
            },
            "arrayNum"=>function($value){
                foreach($value as &$val){
                    $val=is_numeric($val)?$val:null;
                }
                return $value;
            },
            "trimNull"=>function($value){
                return ($val=trim($value))?$val:null;
            },
            "setNull"=>function($value){
                //Used when the array needs to be set but there shouldn't be any values
                return null;
            },
            "removePeriods"=>function($value){
                return ($val=str_replace('.','',$value))?$val:null;
            },
            "numbersOnly"=>function($value){
                return is_numeric($value)?$value:null;
                //return (($valuex=preg_replace("/\D/","",$value))?$valuex:null);
            },
            "USstate"=>function($value){
                $value = strtoupper(preg_replace('/[^a-z]+/i', '', trim($value)));
                if(strlen($value)!=2) {
                    $states=['ALABAMA'=>'AL','ALASKA'=>'AK','AMERICAN SAMOA'=>'AS','ARIZONA'=>'AZ','ARKANSAS'=>'AR','CALIFORNIA'=>'CA','COLORADO'=>'CO','CONNECTICUT'=>'CT','DELAWARE'=>'DE','DISTRICT OF COLUMBIA'=>'DC','FEDERATED STATES OF MICRONESIA'=>'FM','FLORIDA'=>'FL','GEORGIA'=>'GA','GUAM'=>'GU','HAWAII'=>'HI','IDAHO'=>'ID','ILLINOIS'=>'IL','INDIANA'=>'IN','IOWA'=>'IA','KANSAS'=>'KS','KENTUCKY'=>'KY','LOUISIANA'=>'LA','MAINE'=>'ME','MARSHALL ISLANDS'=>'MH','MARYLAND'=>'MD','MASSACHUSETTS'=>'MA','MICHIGAN'=>'MI','MINNESOTA'=>'MN','MISSISSIPPI'=>'MS','MISSOURI'=>'MO','MONTANA'=>'MT','NEBRASKA'=>'NE','NEVADA'=>'NV','NEW HAMPSHIRE'=>'NH','NEW JERSEY'=>'NJ','NEW MEXICO'=>'NM','NEW YORK'=>'NY','NORTH CAROLINA'=>'NC','NORTH DAKOTA'=>'ND','NORTHERN MARIANA ISLANDS'=>'MP','OHIO'=>'OH','OKLAHOMA'=>'OK','OREGON'=>'OR','PALAU'=>'PW','PENNSYLVANIA'=>'PA','PUERTO RICO'=>'PR','RHODE ISLAND'=>'RI','SOUTH CAROLINA'=>'SC','SOUTH DAKOTA'=>'SD','TENNESSEE'=>'TN','TEXAS'=>'TX','UTAH'=>'UT','VERMONT'=>'VT','VIRGIN ISLANDS'=>'VI','VIRGINIA'=>'VA','WASHINGTON'=>'WA','WEST VIRGINIA'=>'WV','WISCONSIN'=>'WI','WYOMING'=>'WY'];
                    $value=isset($states[$value])?$states[$value]:null;
                }
                else {$value=null;}
                return $value;
            },
            "url"=>function($value){
                $value=trim($value);
                if($value) {
                    return (strtolower(substr($value,0,7))=='http://' || strtolower(substr($value,0,8))=='https://')?$value:'http://'.$value;
                }
                else return null;
            },
            "phoneNull"=>function($value){
                return ($val=preg_replace("/\D/","",$value))?$val:null;
            },
            "dollars"=>function($value){
                $value=$value?number_format(ltrim($value,'$'),2,'.',''):null;
                return is_numeric($value)?$value:null;
            },
            "float"=>function($value, $digits){
                //Return a decimal of given number of characters
                $value=number_format($value?$value:null,$digits,'.','');
                return is_numeric($value)?$value:null;
            },
            "percent"=>function($value){
                $value=rtrim($value,'%');   //I don't know why the percent is being sent to the server, but not dollar for other types?  Has to do with maskinput plugin
                //Convert to a 2 digit string so it matches database values
                return is_numeric($value)?number_format(round($value/100,2), 2, '.', ''):null;
                //return (($valuex=preg_replace("/\D/","",$value)/100)?$valuex:null);
            },
            "max"=>function($value, $max){
                return min($value, $max);
            },
            "min"=>function($value, $min){
                return max($value, $max);
            },
            "phone"=>function($value){
                $value=preg_replace("/\D/","",$value);  //Numbers only
                return (substr($value, 0, 1)==1)?substr( $value, 1 ):$value;  //Remove first charactor if 1
            },
            "dateUnix"=>function($value){
                return $value?(new \DateTime($value))->setTime(0,0)->getTimestamp():null;
            },
            "dateTimeUnix"=>function($value){
                return $value?(new \DateTime($value))->getTimestamp():null;
            },
            "dateStandard"=>function($value){
                return $value?(new \DateTime($value))->setTime(0,0)->format('Y-m-d'):null;
            },
            "dateStandard_w_time"=>function($value){
                $hide_time=false;
                $datetime = new \DateTime($value);
                return ($hide_time && $datetime->format( 'H')==0 && $datetime->format( 'i')==0 && $datetime->format( 's')==0)?$datetime->format('Y-m-d'):$datetime->format('Y-m-d H:i:s');
            },
            "dateUS"=>function($value){
                return $value?(new \DateTime($value))->setTime(0,0)->format('m/d/Y'):null;
            },
            "dateUS_w_time"=>function($value){
                if($value) {
                    $datetime = new \DateTime($value);
                    return ($datetime->format( 'H')==0 && $datetime->format( 'i')==0)?$datetime->format('m/d/Y'):$datetime->format('m/d/Y H:i');
                }
                else return null;
            },
            "numbersOnlyNull"=>function($value){
                return ($value=preg_replace("/\D/","",$value))?$value:null;
            },
            "arrayIntNotZero"=>function($value){
                $value=(array)$value;
                $new=[];
                foreach($value AS $val) {
                    if((int)$val){$new[]=(int)$val;}
                }
                return $new;
            },
            "arrayNotEmpty"=>function($value){
                $value=(array)$value;
                $new=[];
                foreach($value AS $val) {
                    if($val){$new[]=$val;}
                }
                return $new;
            },
            "arrayDeliminated"=>function($value, $deliminator){
                //Given string such as a,b,c returns [a,b,c]
                return $value ? explode($deliminator, $value) : [];
            },
        ];
    }

    private function json_error():?string {
        switch (json_last_error()) {
            case JSON_ERROR_NONE:return null;
            case JSON_ERROR_DEPTH:return 'Maximum stack depth exceeded';
            case JSON_ERROR_STATE_MISMATCH:return 'Underflow or the modes mismatch';
            case JSON_ERROR_CTRL_CHAR:return 'Unexpected control character found';
            case JSON_ERROR_SYNTAX:return 'Syntax error, malformed JSON';
            case JSON_ERROR_UTF8:return 'Malformed UTF-8 characters, possibly incorrectly encoded';
            default:return 'Unknown error';
        }
    }

    /**
    * Substitute occurrences of "{name}" and '{name}' with $values['name] if it exists in $values.
    * Booleon and numbers will be unquoted, and text will be quoted.
    * Note that I will need to modify and add a flag if there is a need to add unquoted text.
    * @param string $template
    * @param array $values
    */
    private function parse($rules, array $values) {
        //$re='/\{"{\ (\w+)\ \}\"/';
        //$re='/\{[\'"]{\ (\w+)\ \}[\'"]/';
        //$re='/\{("|\'){\ (\w+)\ \}\1/';
        //$re='/\{(["\']){\ (\w+)\ \}\1/';
        $re = <<<RE
/
"{ (\w+) }" | '{ (\w+) }'
/x
RE;
        $template=json_encode($rules);
        $template=preg_replace_callback($re,function ($matches) use ($values) {
            $match = end($matches);
            if(isset($values[$match])){
                if(is_bool($values[$match])){$new=($values[$match]?'true':'false');}
                elseif(is_numeric($values[$match])||$values[$match]=='true'||$values[$match]=='false'){$new=$values[$match];}
                else{$new='"'.$values[$match].'"';}
            }
            else{$new=$matches[0];}
            return $new;
            },
            $template);

        return json_decode($rules);
    }

    /*
    //Following need to be changed!
    public function addObj($o)
    {
    $this->rules = (object) array_merge((array) $this->rules, (array) $o->rules);
    $this->messages = (object) array_merge((array) $this->messages, (array) $o->messages);
    $this->sanitizers = (object) array_merge((array) $this->sanitizers, (array) $o->sanitizers);
    }
    public function addRules($o)
    {
    $this->rules = (object) array_merge((array) $this->rules, (array) $o->rules);
    }
    public function addMessages($o)
    {
    $this->messages = (object) array_merge((array) $this->messages, (array) $o->messages);
    }
    public function addSanitizers($o)
    {
    $this->sanitizers = (object) array_merge((array) $this->sanitizers, (array) $o->sanitizers);
    }
    public function removeRule($prop)
    {
    unset($this->rules->$prop);
    }
    public function removeMessage($prop)
    {
    unset($this->messages->$prop);
    }
    public function removeSanitize($prop)
    {
    unset($this->sanitizers->$prop);
    }

    public function validateRemote() {
    //Use for AJAX remote calls.
    header('Content-Type: application/json;');
    if(isset($_GET['_method']) && method_exists($this,$_GET['_method'])) {
    $rs=$this->{$_GET['_method']}($_GET);
    echo(json_encode($rs?$rs:'true'));
    }
    else {throw new ValidatorException("Invalid remote validation method.");}
    }

    public function isValid(array $d, array $limit=[])
    {
    //$limit is used to only validate elements in this array
    $this->params=$d; //Used for require_from_group
    $limit=array_flip($limit);
    foreach ($this->rules AS $name=>$o) {
    if($limit && !isset($limit[$name])) continue;
    $d[$name]=isset($d[$name])?$d[$name]:null;
    //if(isset($d[$name])) {
    if(is_object($o)) {
    foreach ($o AS $method=>$option) {
    if($method=='serverOnly') {
    $rs=$this->$option($d,$name);
    //$rs will be an array with elements 'error' (array) and 'data' (array)
    if($rs['errors']) return false;
    }
    elseif($method=='remote') {
    $validate=$option->data->_method;
    if($error=$this->$validate($d[$name],true,$name)) return false;
    }
    else if($error=$this->$method($d[$name],$option,$name)) return false;
    }
    }
    else {
    //Used for shortcut format (no remotes or serverOnly)
    if($this->$o($d[$name],true,$name)) return false;
    }
    }
    return true;
    }

    public function getErrors(array $d, array $limit=[])
    {
    //$limit is used to only validate elements in this array.
    $this->params=$d; //Used for require_from_group
    $limit=array_flip($limit);
    $errors=[];
    foreach ($this->rules AS $name=>$o) {
    if($limit && !isset($limit[$name])) continue;
    $d[$name]=isset($d[$name])?$d[$name]:null;
    //if(isset($d[$name])) {
    if(is_object($o)) {
    foreach ($o AS $method=>$option) {
    if($method=='serverOnly') {
    $rs=$this->$option($d,$name);
    //$rs will be an array with elements 'error' (array) and 'data' (array)
    if($rs['errors']) $errors=array_merge($errors,$rs['errors']);
    else $d=array_merge($d,$rs['data']);

    // if($error=$this->$option($d[$name],true,$name)) {
    // $errors[]=isset($this->messages->$name)
    // ?(
    // is_object($this->messages->$name)
    // ?(isset($this->messages->$name->$method)?$this->messages->$name->$method:$error)
    // :$this->messages->$name
    // ):$error;
    // }
    }
    elseif($method=='remote') {
    $validate=$option->data->_method;
    if($error=$this->$validate($d[$name],true,$name)) {
    $errors[]=isset($this->messages->$name)
    ?(
    is_object($this->messages->$name)
    ?(isset($this->messages->$name->$method)?$this->messages->$name->$method:$error)
    :$this->messages->$name
    ):$error;
    }
    }
    else if($error=$this->$method($d[$name],$option,$name)) {
    $errors[]=isset($this->messages->$name)
    ?(
    is_object($this->messages->$name)
    ?(isset($this->messages->$name->$method)?$this->messages->$name->$method:$error)
    :$this->messages->$name
    ):$error;
    }
    }
    }
    else {
    //Used for shortcut format (no remotes or serverOnly)
    if($error=$this->$o($d[$name],true,$name)) {
    $errors[]=isset($this->messages->$name)
    ?(
    is_object($this->messages->$name)
    ?(isset($this->messages->$name->$o)?$this->messages->$name->$o:$error)
    :$this->messages->$name
    ):$error;
    }
    }
    //} else{$d[$name]=null;}
    }
    return $errors;
    }

    private function getValue($name,$arr)
    {
    //Used for remote methods to allow variable to either be provided or dervived from post/get
    return array_merge($_GET,$_POST,$arr)[$name];
    }
    */

}

?>
