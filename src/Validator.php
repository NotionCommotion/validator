<?php
/**
* Creates jQueryValidate client side code and validates server side
* Filename: /var/www/dashboard/application/classes/validate.php
* Copyright Michael Reed, 2013
* Dual licensed under the MIT and GPL licenses.
* @version 1.0
*/

// Not yet implemented.  Rules on a global basis, not on a per input basis.

/**
* Creates jQueryValidate client side code and validates server side
* Rules basically follow jquery.validate except for:
*   serverOnly rules are not used by client, and methods are not supported by this base class and this class should be extended
*   remote methods which look like the following:
*       {"rules": {"someName": {"remote": {"data": {"someName": {"_function": "return $( '#someID' ).val()"}}}}}}
*   Note that clientside remote rules must be converted to the following using $.validator.createRemoteFromJSON():
*       {"rules": {"someName": {"remote": {"data": {"someName": function() {return $( '#someID' ).val()}}}}}}

Rules (and whether there is a JavaScrip equivilent):
required    jquery.validate
number    jquery.validate
digits    jquery.validate
string    Not yet implemented
minlength    jquery.validate
maxlength    jquery.validate
exactlength    Not yet implemented
requiredDelay    Not yet implemented
defaultInvalid    my-validation-methods
confirmPassword    my-validation-methods
email    jquery.validate
url    jquery.validate
phoneUS    additional-methods
min    jquery.validate
max    jquery.validate
longitude    Not yet implemented
latitude    Not yet implemented
loginRegex    my-validation-methods
noInvalid    my-validation-methods
domain    my-validation-methods
filename    Not yet implemented
validIP    Not yet implemented
isUSstate    my-validation-methods
timezone    Not yet implemented
validIPList    Not yet implemented
inArray    Not yet implemented
noServer    Not yet implemented

Sanitizers:
none
lowerCase
upperCase
trimit
int
bool
boolInt
yes_no
true_false
intNULL
arrayInt
arrayNum
trimNull
removePeriods
numbersOnly
USstate
url_sanitize
phoneNull
dollars
float
percent
maxValue
minValue
sphoneUS
dateStandard
dateStandard_w_time
dateUS
dateUS_w_time
numbersOnlyNull
arrayIntNotZero
arrayNotEmpty
arrayDeliminated

*/

// Later change to use new isValid and getErrors for all (5/11/2018)

namespace Greenbean\Validator;

class Validator implements ValidatorInterface
{
    protected   $rules, $messages, $sanitizers, $errors=[],$extra,
    $params,    //Used only for validating require_from_group
    $sanitizeDefaults=[   //Sanitizer will set to NULL if not given unless specified here
        'arrayInt'=>[],
        'arrayMult'=>[],
        'arrayNum'=>[],
        'array'=>[],
        //'bool'=>false,
        //'boolInt'=>0
    ];
    /**
    * Receives rules/message/sanitizer JSON.
    * $extra are extra variables which can be accessed by the custom methods
    * $elements is given is only required when validating one variable (as with inline editing), and gets rid of other rules
    * If $adj is given, parse $file and replace values
    *
    * @param string $json
    * @param array $extra
    * @param array $elements
    * @param array $adj
    */
    public function __construct($json=false,$extra=[],$elements=[],$adj=[])
    {
        if($json) $this->initialize($json,$extra,$elements,$adj);
    }

    public function load($json,$extra=[],$elements=[],$adj=[])
    {
        $this->initialize($json,$extra,$elements,$adj);
        return $this;
    }

    private function initialize($json,$extra,$elements,$adj)
    {
        $this->extra=$extra;
        if(!empty($adj)) {$json=$this->parse($json,$adj);}
        $jsonObj=json_decode($json) or $this->json_error();

        if(!empty($elements)) {
            //Used with inline editing only to select just one element to validate
            foreach($jsonObj->rules as $key=>$elem){
                if(!in_array($key,$elements)){
                    unset($jsonObj->rules->$key);
                }
            }
            if(isset($jsonObj->messages)) {
                //Small chance the validation file doesn't have any messages, so skip.
                //Don't do with rules and sanitizers as something is wrong if they don't
                foreach($jsonObj->messages as $key=>$elem){
                    if(!in_array($key,$elements)){
                        unset($jsonObj->messages->$key);
                    }
                }
            }
            foreach($jsonObj->sanitizers as $key=>$elem){
                if(!in_array($key,$elements)){
                    unset($jsonObj->sanitizers->$key);
                }
            }
        }

        $this->rules=(isset($jsonObj->rules))?$jsonObj->rules:new \stdClass;
        $this->messages=(isset($jsonObj->messages))?$jsonObj->messages:new \stdClass;
        $this->sanitizers=(isset($jsonObj->sanitizers))?$jsonObj->sanitizers:new \stdClass;
        $this->ignore=(isset($jsonObj->ignore))?$jsonObj->ignore:null;
    }

    final public function addObj($o)
    {
        $this->rules = (object) array_merge((array) $this->rules, (array) $o->rules);
        $this->messages = (object) array_merge((array) $this->messages, (array) $o->messages);
        $this->sanitizers = (object) array_merge((array) $this->sanitizers, (array) $o->sanitizers);
    }
    final public function addRules($o)
    {
        $this->rules = (object) array_merge((array) $this->rules, (array) $o->rules);
    }
    final public function addMessages($o)
    {
        $this->messages = (object) array_merge((array) $this->messages, (array) $o->messages);
    }
    final public function addSanitizers($o)
    {
        $this->sanitizers = (object) array_merge((array) $this->sanitizers, (array) $o->sanitizers);
    }
    final public function removeRule($prop)
    {
        unset($this->rules->$prop);
    }
    final public function removeMessage($prop)
    {
        unset($this->messages->$prop);
    }
    final public function removeSanitize($prop)
    {
        unset($this->sanitizers->$prop);
    }

    final public function getJSON($makeJSON=false)
    {
        $rules=new \stdClass();
        foreach($this->rules AS $key1=>$value1) {
            if(is_object($value1)) {
                foreach($value1 AS $key2=>$value2) {
                    if($key2!='serverOnly') {
                        if(!isset($rules->$key1)){$rules->$key1=new \stdClass();}
                        $rules->$key1->$key2=$value2;
                    }
                }
            }
            else {
                if($key1!='serverOnly'){
                    $rules->$key1=$value1;
                }
            }
        }

        $messages=new \stdClass();
        foreach($this->messages AS $key1=>$value1) {
            if(is_object($value1)) {
                foreach($value1 AS $key2=>$value2) {
                    if($key2!='serverOnly') {
                        if(!isset($messages->$key1)){$messages->$key1=new \stdClass();}
                        $messages->$key1->$key2=$value2;
                    }
                }
            }
            else {
                if($key1!='serverOnly'){$messages->$key1=$value1;}
            }
        }

        $o=new \stdClass();
        if(isset($this->ignore)) {$o->ignore=$this->ignore;}
        $o->rules=$rules;
        $o->messages=$messages;
        return $makeJSON?json_encode($o):$o;
    }

    final public function validateRemote()
    {
        //Use for AJAX remote calls.
        header('Content-Type: application/json;');
        if(isset($_GET['_method']) && method_exists($this,$_GET['_method'])) {
            $rs=$this->{$_GET['_method']}($_GET);
            echo(json_encode($rs?$rs:'true'));
        }
        else {throw new ValidatorException("Invalid remote validation method.");}
    }

    final public function validate(array $d, array $limit=[])
    {
        //$limit is used to only validate elements in this array
        return $this->validateOnly($this->sanitize($d), $limit);
    }

    final public function nameValueisValid($name, $data)
    {
        if(!isset($data[$name])) return false;
        return $this->isValid($data, [$name]);
    }

    final public function isValid(array $d, array $limit=[])
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

    final public function validateOnly(array $d, array $limit=[])
    {
        //$limit is used to only validate elements in this array.  Get rid of later on
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
                        /*
                        if($error=$this->$option($d[$name],true,$name)) {
                        $errors[]=isset($this->messages->$name)
                        ?(
                        is_object($this->messages->$name)
                        ?(isset($this->messages->$name->$method)?$this->messages->$name->$method:$error)
                        :$this->messages->$name
                        ):$error;
                        }
                        */
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
        if($errors){
            $errors=implode(', ',$errors);
            throw new ValidatorException($errors);
        }
        return $d;
    }

    final public function getErrors(array $d, array $limit=[])
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
                        /*
                        if($error=$this->$option($d[$name],true,$name)) {
                        $errors[]=isset($this->messages->$name)
                        ?(
                        is_object($this->messages->$name)
                        ?(isset($this->messages->$name->$method)?$this->messages->$name->$method:$error)
                        :$this->messages->$name
                        ):$error;
                        }
                        */
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

    final public function sanitize(array $d)
    {
        $new=[];
        foreach ($this->sanitizers AS $name=>$o) {
            if(isset($d[$name])) {
                if(is_object($o)) {
                    foreach ($o AS $sanitize=>$option) {
                        $new[$name]=$this->$sanitize($d[$name],$option);
                        $d[$name]=$new[$name];  //Required if element has multiple sanitizers
                    }
                }
                else {
                    //Used for shortcut format
                    $new[$name]=$this->$o($d[$name],true);
                    $d[$name]=$new[$name];  //Required if element has multiple sanitizers
                }
            } else {
                // Set the default value since none was given
                if(is_object($o)) {
                    foreach ($o AS $sanitize=>$option) {
                        if(isset($this->sanitizeDefaults[$sanitize])) {
                            $new[$name]=$this->sanitizeDefaults[$sanitize];
                            break;
                        }
                        $new[$name]=null;
                    }
                }
                else {
                    $new[$name]=isset($this->sanitizeDefaults[$o])?$this->sanitizeDefaults[$o]:null;
                }
            }
        }
        return $new;
    }

    final protected function getValue($name,$arr)
    {
        //Used for remote methods to allow variable to either be provided or dervived from post/get
        return array_merge($_GET,$_POST,$arr)[$name];
    }

    /**
    * Substitute occurrences of "{name}" and '{name}' with $values['name] if it exists in $values.
    * Booleon and numbers will be unquoted, and text will be quoted.
    * Note that I will need to modify and add a flag if there is a need to add unquoted text.
    * @param string $template
    * @param array $values
    */
    final private function parse($template, array $values)
    {
        //$re='/\{"{\ (\w+)\ \}\"/';
        //$re='/\{[\'"]{\ (\w+)\ \}[\'"]/';
        //$re='/\{("|\'){\ (\w+)\ \}\1/';
        //$re='/\{(["\']){\ (\w+)\ \}\1/';
        $re = <<<RE
/
"{ (\w+) }" | '{ (\w+) }'
/x
RE;
        return preg_replace_callback($re,function ($matches) use ($values) {
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
    }

    final private function json_error()
    {
        switch (json_last_error()) {
            case JSON_ERROR_NONE:echo ' - No errors';break;
            case JSON_ERROR_DEPTH:echo ' - Maximum stack depth exceeded';break;
            case JSON_ERROR_STATE_MISMATCH:echo ' - Underflow or the modes mismatch';break;
            case JSON_ERROR_CTRL_CHAR:echo ' - Unexpected control character found';break;
            case JSON_ERROR_SYNTAX:echo ' - Syntax error, malformed JSON';break;
            case JSON_ERROR_UTF8:echo ' - Malformed UTF-8 characters, possibly incorrectly encoded';break;
            default:echo ' - Unknown error';break;
        }
        echo PHP_EOL;
    }

    protected function showQuery($sql, $data, $keepLineBreaks=false)
    {
        $keys = array();
        $values = array();

        # build a regular expression for each parameter
        foreach ($data as $key=>$value)
        {
            if (is_string($key)) {$keys[] = '/:'.$key.'/';}
            else {$keys[] = '/[?]/';}

            //if(is_numeric($value)) {$values[] = intval($value);}
            if(is_numeric($value)) {$values[] = $value;}
            else{$values[] = '"'.$value .'"';}
        }
        $sql = preg_replace($keys, $values, $sql, 1, $count);
        return $keepLineBreaks?$sql:str_replace(array("\r", "\n"), ' ', $sql);
    }

    //Core methods.  Returns false if no errors, default error message if errors.  Shouldn't be overriden, but can if developer wants to
    protected function required($value,$prop,$name)
    {
        //Return true if value provided
        return (!$prop || (!is_null($value) && trim($value)!=''))?false:$name.' is required';
    }
    protected function require_from_group($value,$prop,$name)
    {
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
    protected function number($value,$prop,$name)
    {
        return (!$prop || !trim($value) || is_numeric($value))?false:$name.' is not a number';
    }
    protected function digits($value,$prop,$name)
    {
        return (!$prop || !trim($value) || ctype_digit($value) || $value===(int)$value)?false:$name.' is not a digit';
    }
    protected function string($value,$prop,$name)
    {
        return (!$prop || !trim($value) || is_string($value))?false:$name.' is not a string';
    }
    protected function minlength($value,$prop,$name)
    {
        return ( ($str=trim($value)) && strlen($str)<$prop)?$name.' requires '.$prop.' characters':false;
    }
    protected function maxlength($value,$prop,$name)
    {
        return (strlen(trim($value))>$prop)?$name.' allows no more than '.$prop.' characters':false;
    }
    protected function exactlength($value,$prop,$name)
    {
        return (strlen(trim($value))!=$prop)?$name.' requires exactly '.$prop.' characters':false;
    }
    protected function requiredDelay($value,$prop,$name)
    {
        //Same as required, however, client version has a slight delay to deal with Google Maps
        return $this->minlength($value,$prop,$name);
    }
    protected function defaultInvalid($value,$prop,$name)
    {
        return (!$prop || trim($value))?false:$name.' is required';   //only used client side to ensure that default value wasn't submitted.
    }

    //Confirms that the $value is equal to the value in $_GET[$password]
    protected function confirmPassword($value,$prop,$name)
    {
        $prop=trim(array_merge([$prop=>NULL],$_GET,$_POST)[$prop]);
        return (trim($value)==$prop)?false:'Passwords do not match';
    }

    protected function email($value,$prop,$name)
    {
        //return (!$prop || !trim($value) || preg_match('/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3})$/i', $value) )?false:'Invalid email';
        return (!$prop || !trim($value) || filter_var($value, FILTER_VALIDATE_EMAIL) )?false:'Invalid email';
    }
    protected function url($value,$prop,$name)
    {
        return (!$prop || !trim($value) || filter_var($value, FILTER_VALIDATE_URL) )?false:'Invalid URL';
    }
    protected function phoneUS($value,$prop,$name)
    {
        //return (!$prop || !trim($value) || preg_match("/^([1]-)?[0-9]{3}-[0-9]{3}-[0-9]{4}$/i",$value))?false:'Invalid phone number';
        //Only works if first sanitized with phoneUS
        return (!$prop || !trim($value) || strlen((int)$value)==10)?false:'Invalid phone number';
    }
    protected function min($x,$min,$name)
    {
        return ($x && $x<$min)?$name.' must be greater or equal to '.$min:false;
    }
    protected function max($x,$max,$name)
    {
        return ($x && $x>$max)?$name.' must be less than or equal to '.$max:false;
    }
    protected function date($value,$prop, $name)
    {
        if (!$prop || (!is_null($value) && trim($value)!='')) return false;
        try {
            $date = new \DateTime($value);
            return false;
        } catch (\Exception $e) {
            syslog(LOG_ERR,'Error validating date: '.$e->getMessage());
            return "Invalid date for $name";
        }
    }

    protected function longitude($value,$prop,$name)
    {
        return (!$prop || !trim($value) || ($value<=180))?false:'Invalid longitude';
    }
    protected function latitude($value,$prop,$name)
    {
        return (!$prop || !trim($value) || ($value<=90))?false:'Invalid latitude';
    }
    protected function loginRegex($value,$prop,$name)
    {
        return (!$prop || !trim($value) || preg_match("/^[a-z0-9._]+$/i",$value))?false:'Username must contain only letters, numbers, underscore, or period';
    }
    protected function noInvalid($value,$prop,$name)
    {
        return (!$prop || !trim($value) || preg_match("/^[a-z0-9.,-_()& ]+$/i",$value))?false:'Invalid characters';
    }
    protected function domain($value,$prop,$name)
    {
        return (!$prop || !trim($value) || preg_match("/^[a-z0-9_-]+$/i",$value))?false:'Alphanumerical, underscore, and hyphes only';
    }
    protected function filename($value,$prop,$name)
    {
        return (!$prop || !trim($value) || (strpbrk($value, "\\/%*:|\"<>") === FALSE))?false:'Invalid file name';
    }

    protected function validIP($value,$prop,$name)
    {
        return (!$prop || !trim($value) || filter_var($pi->$property, FILTER_VALIDATE_IP))?false:'IP Addresses must have format xxx.xxx.xxx.xxx';
    }

    protected function isUSstate($value,$prop,$name)
    {
        $states=['AA'=>1,'AE'=>1,'AL'=>1,'AK'=>1,'AS'=>1,'AP'=>1,'AZ'=>1,'AR'=>1,'CA'=>1,'CO'=>1,'CT'=>1,'DE'=>1,'DC'=>1,'FM'=>1,'FL'=>1,'GA'=>1,'GU'=>1,'HI'=>1,'ID'=>1,'IL'=>1,'IN'=>1,'IA'=>1,'KS'=>1,'KY'=>1,'LA'=>1,'ME'=>1,'MH'=>1,'MD'=>1,'MA'=>1,'MI'=>1,'MN'=>1,'MS'=>1,'MO'=>1,'MT'=>1,'NE'=>1,'NV'=>1,'NH'=>1,'NJ'=>1,'NM'=>1,'NY'=>1,'NC'=>1,'ND'=>1,'MP'=>1,'OH'=>1,'OK'=>1,'OR'=>1,'PW'=>1,'PA'=>1,'PR'=>1,'RI'=>1,'SC'=>1,'SD'=>1,'TN'=>1,'TX'=>1,'UT'=>1,'VT'=>1,'VI'=>1,'VA'=>1,'WA'=>1,'WV'=>1,'WI'=>1,'WY'=>1];
        return (!$prop || !trim($value) || isset($states[$value]))?false:'Must be a US State';
    }

    //both timezone and timezoneId do the same thing. timezone is probably better.
    protected function timezone($value,$prop,$name)
    {
        if(!$prop || !trim($value)) return false;
        try{
            new \DateTimeZone($value);
        }catch(\Exception $e){
            return "Invalid timezone ID '$value'";
        }
        return FALSE;
    }
    protected function timezoneId($value,$prop,$name)
    {
        return (!$prop || !trim($value) || (in_array($value, DateTimeZone::listIdentifiers())))?false:"Invalid timezone '$value'";
    }

    protected function validIPList($value,$prop,$name)
    {
        $valid=true;
        if($prop || trim($value)) {
            $ips=explode($value,',');
            foreach($ips as $ip) {
                if($this->validIP($ip)) {$valid=false; break;}
            }
        }
        return ($valid)?false:'IP Addresses must have format xxx.xxx.xxx.xxx';
    }

    protected function inArray($value,$prop,$name)
    {
        return (in_array($value, $prop))?false:$name.' must be one of: '.implode(', ',$prop);
    }

    // Used for client side only validation
    protected function noServer($value,$prop,$name)
    {
        return false;
    }

    //Core sanitizers.  Shouldn't be overriden, but can if developer wants to
    protected function none($value,$prop)
    {
        return $value;
    }
    protected function lowerCase($value,$prop)
    {
        return ($prop)?strtolower($value):$value;
    }
    protected function upperCase($value,$prop)
    {
        return ($prop)?strtoupper($value):$value;
    }
    protected function trimit($value,$prop)
    {
        return ($prop)?trim($value):$value;
    }
    protected function int($value,$prop)
    {
        return ($prop)?(int)$value:$value;
    }
    protected function bool($value,$prop)
    {
        return ($prop)?boolval($value):$value;
    }
    protected function boolInt($value,$prop)
    {
        //Instead of true/false, returns 1/2
        return ($prop)?$value?1:0:$value;
    }
    protected function yes_no($value,$prop)
    {
        return ($prop)?($value=='y'?1:0):$value;
    }
    protected function true_false($value,$prop)
    {
        return ($prop)?($value=='t'?1:0):$value;
    }
    protected function intNULL($value,$prop)
    {
        return ($prop)?((int)$value)?(int)$value:null:$value;
    }
    protected function array($value,$prop)
    {
        return $prop?(array)$value:$value;
    }
    protected function arrayInt($value,$prop)
    {
        if($prop){
            foreach($value as $key=>$val){
                $value[$key]=(int)$val;
            }
        }
        return $value;
    }
    protected function arrayMult($value, $prop)
    {
        //Future. Add similar validatin for arrayMult
        if(!$prop) throw new ValidatorException('arrayMulti sanitizer requires an array definition.  i.e. "id|sign"');
        $indexes=array_flip(explode('|',$prop));
        foreach($value as $key=>$val){
            $value[$key]=array_intersect_key($val, $indexes);
        }
        return $value;
    }
    protected function arrayNum($value,$prop)
    {
        if($prop){
            foreach($value as $key=>$val){
                $value[$key]=is_numeric($val)?$val:NULL;
            }
        }
        return $value;
    }
    protected function trimNull($value,$prop)
    {
        return ($prop || $prop=='0')?(($valuex=trim($value))?$valuex:null):$value;
    }
    protected function setNull($value,$prop)
    {
        //Used when the array needs to be set but there shouldn't be any values
        return ($prop)?(($valuex=null)?$valuex:null):$value;
    }
    protected function removePeriods($value,$prop)
    {
        return ($prop)?(($valuex=str_replace('.','',$value))?$valuex:null):$value;
    }
    protected function numbersOnly($value,$prop)
    {
        return ($prop)?(is_numeric($value)?$value:NULL):$value;
        //return ($prop)?(($valuex=preg_replace("/\D/","",$value))?$valuex:null):$value;
    }
    protected function USstate($value,$prop)
    {
        if($prop) {
            $value = strtoupper(preg_replace('/[^a-z]+/i', '', trim($value)));
            if(strlen($value)!=2)
            {
                $states=['ALABAMA'=>'AL','ALASKA'=>'AK','AMERICAN SAMOA'=>'AS','ARIZONA'=>'AZ','ARKANSAS'=>'AR','CALIFORNIA'=>'CA','COLORADO'=>'CO','CONNECTICUT'=>'CT','DELAWARE'=>'DE','DISTRICT OF COLUMBIA'=>'DC','FEDERATED STATES OF MICRONESIA'=>'FM','FLORIDA'=>'FL','GEORGIA'=>'GA','GUAM'=>'GU','HAWAII'=>'HI','IDAHO'=>'ID','ILLINOIS'=>'IL','INDIANA'=>'IN','IOWA'=>'IA','KANSAS'=>'KS','KENTUCKY'=>'KY','LOUISIANA'=>'LA','MAINE'=>'ME','MARSHALL ISLANDS'=>'MH','MARYLAND'=>'MD','MASSACHUSETTS'=>'MA','MICHIGAN'=>'MI','MINNESOTA'=>'MN','MISSISSIPPI'=>'MS','MISSOURI'=>'MO','MONTANA'=>'MT','NEBRASKA'=>'NE','NEVADA'=>'NV','NEW HAMPSHIRE'=>'NH','NEW JERSEY'=>'NJ','NEW MEXICO'=>'NM','NEW YORK'=>'NY','NORTH CAROLINA'=>'NC','NORTH DAKOTA'=>'ND','NORTHERN MARIANA ISLANDS'=>'MP','OHIO'=>'OH','OKLAHOMA'=>'OK','OREGON'=>'OR','PALAU'=>'PW','PENNSYLVANIA'=>'PA','PUERTO RICO'=>'PR','RHODE ISLAND'=>'RI','SOUTH CAROLINA'=>'SC','SOUTH DAKOTA'=>'SD','TENNESSEE'=>'TN','TEXAS'=>'TX','UTAH'=>'UT','VERMONT'=>'VT','VIRGIN ISLANDS'=>'VI','VIRGINIA'=>'VA','WASHINGTON'=>'WA','WEST VIRGINIA'=>'WV','WISCONSIN'=>'WI','WYOMING'=>'WY'];
                $value=isset($states[$value])?$states[$value]:NULL;
            }
            else {$value=null;}
        }
        return $value;

    }
    protected function url_sanitize($value,$prop)
    {
        $valuex=trim($value);
        if($prop && $valuex) {
            return (strtolower(substr($valuex,0,7))=='http://' || strtolower(substr($valuex,0,8))=='https://')?$valuex:'http://'.$valuex;
        }
        else {return $value;}
    }

    protected function phoneNull($value,$prop)
    {
        return ($prop)?(($valuex=preg_replace("/\D/","",$value))?$valuex:null):$value;
    }
    protected function dollars($value,$prop)
    {
        if($prop){
            $value=($value)?number_format(ltrim($value,'$'),2,'.',''):null;
            return (is_numeric($value))?$value:null;
        }
        else {return $value;}
    }
    protected function float($value,$digits)
    {
        //Return a decimal of given number of characters
        $value=number_format($value?$value:null,$digits,'.','');
        return (is_numeric($value))?$value:null;
    }
    protected function percent($value,$prop)
    {
        $value=rtrim($value,'%');   //I don't know why the percent is being sent to the server, but not dollar for other types?  Has to do with maskinput plugin
        //Convert to a 2 digit string so it matches database values
        return ($prop)?(is_numeric($value)?number_format(round($value/100,2), 2, '.', ''):NULL):$value;
        //return ($prop)?(($valuex=preg_replace("/\D/","",$value)/100)?$valuex:null):$value;
    }

    protected function maxValue($x,$max)
    {
        return ($x>$max)?$max:$x;
    }
    protected function minValue($x,$min)
    {
        return ($x<$min)?$min:$x;
    }
    protected function sphoneUS($value,$prop)
    {
        if($prop) {
            $value=preg_replace("/\D/","",$value);  //Numbers only
            return ((substr($value, 0, 1)==1)?substr( $value, 1 ):$value);  //Remove first charactor if 1
        }
        else {return $value;}
    }
    protected function dateUnix($date,$prop)
    {
        if($prop){
            $date=$date?(new \DateTime($date))->setTime(0,0)->getTimestamp():null;
        }
        return $date;
    }
    protected function dateTimeUnix($date,$prop)
    {
        if($prop){
            $date=$date?(new \DateTime($date))->getTimestamp():null;
        }
        return $date;
    }
    protected function dateStandard($date,$prop)
    {
        if($prop){
            $date=$date?(new \DateTime($date))->setTime(0,0)->format('Y-m-d'):null;
        }
        return $date;
    }
    protected function dateStandard_w_time($datetime,$prop)
    {
        if($prop){
            if($datetime) {
                $hide_time=false;
                $datetime = new \DateTime($datetime);
                $datetime = ($hide_time && $datetime->format( 'H')==0 && $datetime->format( 'i')==0 && $datetime->format( 's')==0)?$datetime->format('Y-m-d'):$datetime->format('Y-m-d H:i:s');
            }
            else $datetime=null;
        }
        return $datetime;
    }
    protected function dateUS($date,$prop)
    {
        if($prop){
            $date=$date?(new \DateTime($date))->setTime(0,0)->format('m/d/Y'):null;
        }
        return $date;
    }
    protected function dateUS_w_time($datetime,$prop)
    {
        if($prop){
            if($datetime) {
                $datetime = new \DateTime($datetime);
                $datetime = ($datetime->format( 'H')==0 && $datetime->format( 'i')==0)?$datetime->format('m/d/Y'):$datetime->format('m/d/Y H:i');
            }
            else $datetime=null;
        }
        return $datetime;
    }

    protected function numbersOnlyNull($value,$prop)
    {
        return ($prop)?(($valuex=preg_replace("/\D/","",$value))?$valuex:null):$value;
    }
    protected function arrayIntNotZero($value,$prop)
    {
        $value=(array)$value;
        if($prop) {
            $new=[];
            foreach($value AS $a) {
                if((int)$a){$new[]=(int)$a;}
            }
            $value=$new;
        }
        return $value;
    }

    protected function arrayNotEmpty($value,$prop)
    {
        $value=(array)$value;
        if($prop) {
            $new=[];
            foreach($value AS $a) {
                if($a){$new[]=$a;}
            }
            $value=$new;
        }
        return $value;
    }

    //Given string such as a,b,c returns [a,b,c]
    protected function arrayDeliminated($value,$deliminator=',')
    {
        return $value ? explode($deliminator, $value) : [];
    }
}

?>
