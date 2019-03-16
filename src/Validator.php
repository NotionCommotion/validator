<?php
namespace Greenbean\Validator;

/**
* Creates jQueryValidate client side code and validates server side.
*
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
*
* Unlike jQuery.Validator, config JSON first specifies each property name and then lists the following:
* - rules.  Follows jQuery.Validator format.
* - messages.  Follows jQuery.Validator format.
* - sanitizers.  Similar to jQuery.Validator format, but can also be a sequencial array.  These are only used by the sanitize methods.
* - default.  An value or array.  Will replace if NULL is provided.
* - extend.  This is used to validate deep JSON.
* When encountered, it is interpreted as a sub-object or array of sub-objects if defined as [{"name":{...}}]
* Standard rules, etc apply to the extended object.
* As sub-objects do not pertain to jQuery.Validator, these are not returned for its use,
* however, it is possible to extract a subproperty and use that.
*
* Example:
* {
* "someProperty": {
* //Rules can be a string
* "rules": "singleRule",
* //or a sequential array
* "rules": [
* "singleRule",
* {"anotherRuleWith": "anotherParameter"}
* ],
* //or an object
* "rules": {
* "singleRule": true,
* "ruleWith": "parameter",
* "inArray": ["csv", "highchart", "json"],
* "require_from_group": [1, "{name=name1}, {name=name2}"],
* "ruleWithObject": {"propName": "propValue"}
* },
*
* //Sanitizers can be a string
* "sanitizers": "singleSanitizer",
* //or a sequential array
* "sanitizers": [
* "singleSanitizer",
* {"anotherSanitizer": "anotherParameter"}
* ],
* //or an object
* "sanitizers": {
* "max": 30,
* "anotherSanitizer": "parameter"
* },
*
* //Messages are defined on a per rule basis and are optional as the application will generate a standard message
* "message": {
* "rule1": "text message",
* "rule2": "text message"
* },
*
* //A single default value can be provided for undefined parameters
* "default": "singleOptionalValue"
* },
*
* " anotherProperty ": {}
* }
*
*
* Copyright Michael Reed, 2013
* Dual licensed under the MIT and GPL licenses.
* @version 1.1
*/
class Validator
{
    private
    /* $properties is an array which is similar to jQUery validator's JavaScript options
    except it is structured per property name with rules, messages, sanitizers, and default values under each name.
    */
    $properties,
    $throwExeptions,                //If false, return errors as an array
    $validatorConfig,               //Custom rules and sanitizers
    $sequencialArray=false,         //If true, validate/sanitize each item in the array per the rule set.
    $minimumArrayCount,             //If set, will require extended arrays to have at least that given count.
    $maximumArrayCount,             //If set, will require extended arrays to have at most that given count.
    $customRules=[],                //Internal.  array of custom rules supported by ValidatorConfig
    $customSanitizers=[],           //Internal.  array of custom sanitizers supported by ValidatorConfig
    $rules, $sanitizers;            //Internal.  standard rules and sanitizers

    public function __construct(array $properties, ValidatorConfigInterface $validatorConfig=null, bool $throwExeptions=true) {

        $this->setProperties($properties);

        if($validatorConfig) {
            $this->validatorConfig=$validatorConfig;
            $this->customRules=$validatorConfig->getRules();
            $this->customSanitizers=$validatorConfig->getSanitizers();
        }

        $this->throwExeptions=$throwExeptions;
        $this->rules=new Rules();
        $this->sanitizers=new Sanitizers();
    }

    static public function create(array $properties, ValidatorConfigInterface $validatorConfig=null, bool $throwExeptions=true) {
        return new self($properties, $validatorConfig, $throwExeptions);
    }

    static public function filesToArr($files):array {
        $files=(array)$files;
        $options=[];
        foreach($files as $file) {
            if(!$arr=json_decode(file_get_contents($file), true)) {
                throw new ValidatorErrorException ('Invalid JSON used for validation rules');
            }
            $options[]=$arr;
        }
        return count($options)>1?array_replace_recursive(...$options):$options[0];
    }

    public function getSubProperties(string $subpath, array $properties=[]) {
        $properties=$properties?array_replace_recursive($this->properties, $properties):$this->properties;
        $subpath=explode('.', $subpath);
        foreach($subpath as $key) {
            if(!isset($properties[$key])) {
                throw new ValidatorErrorException ('Undefined getSubProperties(path): '.implode('.', $subpath));
            }
            $properties=$key==='0'?$properties[$key]:$properties[$key]['extend'];
        }
        return $properties;    //Let caller deal with it should an array be returned.
    }

    private function setProperties(array $properties) {
        if($properties && $this->isSequentialArray($properties)) {
            if(!in_array(count($properties),[1,2,3])) {
                throw new ValidatorErrorException('Sequencial array validation must have only one value plus an optional minimum and maximum count value');
            }
            $this->properties=$properties[0];
            $this->sequencialArray=true;
            $this->minimumArrayCount=$properties[1]??null;
            $this->maximumArrayCount=$properties[2]??null;
        }
        else {
            $this->properties=$properties;
        }
    }

    public function replaceProperties(array $properties) {
        $this->setProperties($properties);
    }

    public function getJSON(array $properties=[], string $subpath=null, bool $asJson=false) {
        /**
        * Returns JSON to be used by jQuery.Validator.
        *
        * Does not support extended options.
        * Future.  Go through rules and make into JavaScript those that have the name "function"
        * Future.  insert values in the JSON using $this->parse($json, $parse);
        *
        * @param array $properties Added to existing properties
        * @param string $subpath If specified, returns a sub-object in the base properties
        * @param bool $asJson Whether to return as string or array.
        */
        $rules=[];
        $messages=[];
        $properties=$subpath?$this->getSubProperties($subpath, $properties)
        :($properties?array_replace_recursive($this->properties, $properties):$this->properties);

        foreach($properties as $name=>$options) {
            if(isset($options['rules']) && !(isset($options['rules'][0]) && $options['rules'][0]==='serverOnly')) {
                $rules[$name]=$options['rules'];
            }
            if(isset($options['message'])) {
                $messages[$name]=$options['message'];
            }
        }
        $rsp=[];
        if($rules) $rsp['rules']=$rules;
        if($messages) $rsp['messages']=$messages;
        return $asJson?json_encode($rsp):$rsp;
    }

    public function validateProperty(string $name, $value, string $path=null):?string {
        return $this->validate([$name=>$value], $path, [$name]);
    }

    public function sanitizeProperty(string $name, $value, string $path=null) {
        return $this->sanitize([$name=>$value], $path, [$name])[$name]??null;
    }

    public function validate(array $data, string $path=null, array $limit=[]):array {
        $properties=$this->getProperties($path, $limit);
        $errors=[];

        if($this->sequencialArray) {
            if(is_array($data)) {
                if($this->minimumArrayCount && count($data)<$this->minimumArrayCount) {
                    $errors[]="Data array must have at least $this->minimumArrayCount rows.";
                }
                if($this->maximumArrayCount && count($data)>$this->maximumArrayCount) {
                    $errors[]="Data array must have no more than $this->maximumArrayCount rows.";
                }
                foreach($data as $i=>$row) {
                    $this->validateRow($errors, $properties, $row);
                }
            }
            else {
                $errors[]="Data must be an array";
            }
        }
        else {
            $this->validateRow($errors, $properties, $data);
        }

        if($errors && $this->throwExeptions){
            throw new ValidatorException(implode(', ',$errors));
        }
        return $errors;
    }

    public function sanitize(array $data, string $path=null, array $limit=[]):array {
        $properties=$this->getProperties($path, $limit);

        if($this->sequencialArray) {
            if(is_array($data)) {
                foreach($data as &$row) {
                    $row=$this->sanitizeRow($properties, $row);
                }
            }
            else {
                $errors[]="Data must be an array";
            }
        }
        else {
            $data=$this->sanitizeRow($properties, $data);
        }
        return $data;
    }

    private function getProperties(?string $path, array $limit) {
        $properties=$path?$this->getSubProperties($this->properties, $path):$this->properties;
        if($limit) {
            $properties=array_intersect_key($properties, array_flip($limit));
        }
        return $properties;
    }

    private function validateRow(array &$errors, array $properties, array $data):void {
        foreach($properties as $name=>$item) {
            $value=$data[$name]??$item['default']??null;
            if(!empty($item['rules'])) {
                if(is_string($item['rules'])) {
                    $this->validateValue($errors, $item['rules'], $name, $value, true, $data);
                }
                elseif($this->isSequentialArray($item['rules'])) {
                    throw new ValidatorErrorException('Sequential array of rules is not supported');
                    foreach ($item['rules'] AS $method) {
                        if(is_string($method)) {
                            $this->validateValue($errors, $method, $name, $value, true, $data);
                        }
                        else {
                            $this->validateValue($errors, key($method), $name, $value, reset($method));
                        }
                    }
                }
                else {
                    //One or more compound rules
                    foreach ($item['rules'] AS $method=>$param) {
                        if($method==='serverOnly') {
                            $this->validateValue($errors, $param, $name, $value, true, $data);
                        }
                        else {
                            $this->validateValue($errors, $method, $name, $value, $param, $data);
                        }
                    }
                }
            }
            if(!empty($item['extend'])) {
                if(!$item['extend'] instanceof Validator) {
                    $item['extend']=new self($item['extend'], $this->validatorConfig, false);
                }
                $errs=$item['extend']->validate($value);
                foreach($errs as $err) {
                    $errors[]="$name $err";
                }
            }
        }
    }
    private function sanitizeRow(array $properties, array $data):array {
        foreach($properties as $name=>$item) {
            $sanitized[$name]=$data[$name]??$item['default']??null;
            if(!empty($item['sanitizers'])) {
                if(is_string($item['sanitizers'])) {
                    $sanitized[$name]=$this->sanitizeValue($item['sanitizers'], $name, $sanitized[$name], null);
                }
                elseif($this->isSequentialArray($item['sanitizers'])) {
                    //Multiple santitizers
                    foreach ($item['sanitizers'] AS $method) {
                        if(is_string($method)) {
                            $sanitized[$name]=$this->sanitizeValue($method, $name, $sanitized[$name], null);
                        }
                        else {
                            $sanitized[$name]=$this->sanitizeValue(key($method), $name, $sanitized[$name], reset($method));
                        }
                    }
                }
                else {
                    //Multiple compound santitizers
                    foreach ($item['sanitizers'] AS $method=>$param) {
                        $sanitized[$name]=$this->sanitizeValue($method, $name, $sanitized[$name], $param);
                    }
                }
            }
            if(!empty($item['default']) && !isset($data[$name])) {
                $data[$name]=$item['default'];
            }
            if(!empty($item['extend'])) {
                if(!$item['extend'] instanceof Validator) {
                    $item['extend']=new self($item['extend'], $this->validatorConfig, false);
                }
                $sanitized[$name]=$item['extend']->sanitize($sanitized[$name]??[]);
            }
        }
        return $sanitized;
    }

    private function validateValue(array &$errors, string $method, string $name, $value, $prop, array $data):void {
        if(in_array($method, $this->customRules)) {
            if($error=$this->validatorConfig->validate($method, $value, $prop, $name, $data)) {
                $errors[]=$error;
            }
            return;
        }
        if(method_exists($this->rules, $method)) {
            if($error=$this->rules->$method($value, $prop, $name)) {
                $errors[]=$this->options['messages'][$name]??$error;
            }
            return;
        }
        throw new ValidatorErrorException("Rule '$method' for '$name' does not exist");
    }

    private function sanitizeValue(string $method, $name, $value, $prop) {
        //if(is_null($value)) return null;
        if(in_array($method, $this->customSanitizers)) {
            //if(isset($this->customSanitizers[$method])) {
            return $this->validatorConfig->sanitize($method, $value, $prop);
        }
        if(method_exists($this->sanitizers, $method)) {
            return $this->sanitizers->$method($value, $prop);
        }
        throw new ValidatorErrorException("Sanitizer '$method' for '$name' does not exist");
    }

    private function isSequentialArray($array){
        return (array_values($array) === $array);
    }

    private function isAssocArray($arr) {
        //Not used
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

}
