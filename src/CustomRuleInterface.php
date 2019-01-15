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

interface CustomRuleInterface
{
    public function addObj($o);
    public function addRules($o);
    public function addMessages($o);
    public function addSanitizers($o);
    public function removeRule($prop);
    public function removeMessage($prop);
    public function removeSanitize($prop);
    public function getJSON($makeJSON=false);
    public function validateRemote();
    public function validate(array $d, array $limit=[]);
    public function validateOnly(array $d, array $limit=[]);
    public function sanitize(array $d);
    public function nameValueisValid($name, $data);
    public function load($json,$extra=[],$elements=[],$adj=[]);
}