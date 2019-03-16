<?php
/**
* Creates jQueryValidate client side code and validates server side
* Filename: /var/www/dashboard/application/classes/validate.php
* Copyright Michael Reed, 2013
* Dual licensed under the MIT and GPL licenses.
* @version 1.0
*/

/**
* Creates jQueryValidate client side code and validates server side
* Rules basically follow jquery.validate except for:
*   noClient which isn't used by client, and methods typically are not supported by this base class and this class should be extended
*   remote methods which look like the following:
*       {"rules": {"someName": {"remote": {"data": {"someName": {"_function": "return $( '#someID' ).val()"}}}}}}
*   Note that clientside remote rules must be converted to the following using $.validator.createRemoteFromJSON():
*       {"rules": {"someName": {"remote": {"data": {"someName": function() {return $( '#someID' ).val()}}}}}}
*/

namespace Greenbean\Validator;

interface ValidatorConfigInterface
{
    //Return a list of supported rules.
    public function getRules():array;
    //Return a list of supported sanitizers.
    public function getSanitizers():array;
    //Return the error message or NULL for no errors
    public function validate(string $method, $value, string $prop, string $name, array $data):?string;
    //Sanitize the data
    public function sanitize(string $method, $value, string $prop);
    //Process error (or throw an exception if desired)  $error will be ['property'=>['errors message', ...]...]
    //public function processError(array $error): string;
}