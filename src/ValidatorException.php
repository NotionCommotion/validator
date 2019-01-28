<?php
namespace Greenbean\Validator;
//Used for exceptions meant for the user
class ValidatorException extends \Exception {
/*
    private $errorObj;
    public function __construct(string $errors, int $code=null, \Exception $previous=null) {
        syslog(LOG_ERR, 'ValidatorException: '.$errors);
        $errors=json_decode($errors, true);
        $this->errorObj=$errors;
        $errors=$this->serializeError($errors);
        parent::__construct(implode(' | ', $errors), $code, $previous);
    }

    public function getErrObj() {
        return $this->errorObj;
    }

    private function serializeError($errors) {
        foreach($errors as $name=>&$error) {
            if(is_array($error)) {
                $error=$this->serializeError($error);
            }
            else {
                $error="$name $error";
            }
        }
        return $errors;
    }
    */
}