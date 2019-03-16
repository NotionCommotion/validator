<?php
namespace Greenbean\Validator;
class Sanitizers {

    //All sanitized will be passed $value plus one optional property, and return the sanitized property

    public function none($value){
        return $value;
    }
    public function strtolower($value){
        $this->verifyAString($value);
        return strtolower($value);
    }
    public function strtoupper($value){
        $this->verifyAString($value);
        return strtoupper($value);
    }
    public function trim($value){
        $this->verifyAString($value);
        return trim($value);
    }
    public function string($value){
        return (string)$value;
    }
    public function int($value){
        return (int)$value;
    }
    public function bool($value){
        return boolval($value);
    }
    public function boolInt($value){
        //Instead of true/false, returns 1/2
        return $value?1:0;
    }
    public function yes_no($value){
        return $value=='y'?1:0;
    }
    public function true_false($value){
        return $value=='t'?1:0;
    }
    public function intNULL($value){
        return (int)$value?$value:null;
    }
    public function array($value){
        return (array)$value;
    }
    public function object($value){
        return (object)$value;
    }
    public function arrayInt($value){
        $this->verifyAnArray($value);
        foreach($value as &$val){
            $val=(int)$val;
        }
        return $value;
    }
    public function arrayMult($value, $prop=null){
        //Future. Add similar validatin for arrayMult
        $this->verifyAnArray($value);
        if(!$prop) throw new ValidatorException('arrayMulti sanitizer requires an array definition.  i.e. "id|sign"');
        $indexes=array_flip(explode('|',$prop));
        foreach($value as &$val){
            $val=array_intersect_key($val, $indexes);
        }
        return $value;
    }
    public function arrayNum($value){
        $this->verifyAnArray($value);
        foreach($value as &$val){
            $val=is_numeric($val)?$val:null;
        }
        return $value;
    }
    public function trimNull($value){
        $this->verifyAString($value);
        return ($val=trim($value))?$val:null;
    }
    public function setNull($value){
        //Used when the array needs to be set but there shouldn't be any values
        return null;
    }
    public function removePeriods($value){
        $this->verifyAString($value);
        return ($val=str_replace('.','',$value))?$val:null;
    }
    public function numbersOnly($value){
        return is_numeric($value)?$value:null;
        //return (($valuex=preg_replace("/\D/","",$value))?$valuex:null);
    }
    public function USstate($value){
        $this->verifyAString($value);
        $value = strtoupper(preg_replace('/[^a-z]+/i', '', trim($value)));
        if(strlen($value)!=2) {
            $states=['ALABAMA'=>'AL','ALASKA'=>'AK','AMERICAN SAMOA'=>'AS','ARIZONA'=>'AZ','ARKANSAS'=>'AR','CALIFORNIA'=>'CA','COLORADO'=>'CO','CONNECTICUT'=>'CT','DELAWARE'=>'DE','DISTRICT OF COLUMBIA'=>'DC','FEDERATED STATES OF MICRONESIA'=>'FM','FLORIDA'=>'FL','GEORGIA'=>'GA','GUAM'=>'GU','HAWAII'=>'HI','IDAHO'=>'ID','ILLINOIS'=>'IL','INDIANA'=>'IN','IOWA'=>'IA','KANSAS'=>'KS','KENTUCKY'=>'KY','LOUISIANA'=>'LA','MAINE'=>'ME','MARSHALL ISLANDS'=>'MH','MARYLAND'=>'MD','MASSACHUSETTS'=>'MA','MICHIGAN'=>'MI','MINNESOTA'=>'MN','MISSISSIPPI'=>'MS','MISSOURI'=>'MO','MONTANA'=>'MT','NEBRASKA'=>'NE','NEVADA'=>'NV','NEW HAMPSHIRE'=>'NH','NEW JERSEY'=>'NJ','NEW MEXICO'=>'NM','NEW YORK'=>'NY','NORTH CAROLINA'=>'NC','NORTH DAKOTA'=>'ND','NORTHERN MARIANA ISLANDS'=>'MP','OHIO'=>'OH','OKLAHOMA'=>'OK','OREGON'=>'OR','PALAU'=>'PW','PENNSYLVANIA'=>'PA','PUERTO RICO'=>'PR','RHODE ISLAND'=>'RI','SOUTH CAROLINA'=>'SC','SOUTH DAKOTA'=>'SD','TENNESSEE'=>'TN','TEXAS'=>'TX','UTAH'=>'UT','VERMONT'=>'VT','VIRGIN ISLANDS'=>'VI','VIRGINIA'=>'VA','WASHINGTON'=>'WA','WEST VIRGINIA'=>'WV','WISCONSIN'=>'WI','WYOMING'=>'WY'];
            $value=isset($states[$value])?$states[$value]:null;
        }
        else {$value=null;}
        return $value;
    }
    public function url($value){
        $this->verifyAString($value);
        $value=trim($value);
        if($value) {
            return (strtolower(substr($value,0,7))=='http://' || strtolower(substr($value,0,8))=='https://')?$value:'http://'.$value;
        }
        else return null;
    }
    public function phoneNull($value){
        $this->verifyAString($value);
        return ($val=preg_replace("/\D/","",$value))?$val:null;
    }
    public function dollars($value){
        $this->verifyAString($value);
        $value=$value?number_format(ltrim($value,'$'),2,'.',''):null;
        return is_numeric($value)?$value:null;
    }
    public function float($value, $digits){
        //Return a decimal of given number of characters
        $value=number_format($value?$value:null,$digits,'.','');
        return is_numeric($value)?$value:null;
    }
    public function percent($value){
        $value=rtrim($value,'%');   //I don't know why the percent is being sent to the server, but not dollar for other types?  Has to do with maskinput plugin
        //Convert to a 2 digit string so it matches database values
        return is_numeric($value)?number_format(round($value/100,2), 2, '.', ''):null;
        //return (($valuex=preg_replace("/\D/","",$value)/100)?$valuex:null);
    }
    public function max($value, $max){
        return min($value, $max);
    }
    public function min($value, $min){
        return max($value, $max);
    }
    public function phone($value){
        $this->verifyAString($value);
        $value=preg_replace("/\D/","",$value);  //Numbers only
        return (substr($value, 0, 1)==1)?substr( $value, 1 ):$value;  //Remove first charactor if 1
    }
    public function dateUnix($value){
        $this->verifyAString($value);
        return $value?(new \DateTime($value))->setTime(0,0)->getTimestamp():null;
    }
    public function dateTimeUnix($value){
        $this->verifyAString($value);
        return $value?(new \DateTime($value))->getTimestamp():null;
    }
    public function dateStandard($value){
        $this->verifyAString($value);
        return $value?(new \DateTime($value))->setTime(0,0)->format('Y-m-d'):null;
    }
    public function dateStandard_w_time($value){
        $this->verifyAString($value);
        $hide_time=false;
        $datetime = new \DateTime($value);
        return ($hide_time && $datetime->format( 'H')==0 && $datetime->format( 'i')==0 && $datetime->format( 's')==0)?$datetime->format('Y-m-d'):$datetime->format('Y-m-d H:i:s');
    }
    public function dateUS($value){
        $this->verifyAString($value);
        return $value?(new \DateTime($value))->setTime(0,0)->format('m/d/Y'):null;
    }
    public function dateUS_w_time($value){
        $this->verifyAString($value);
        if($value) {
            $datetime = new \DateTime($value);
            return ($datetime->format( 'H')==0 && $datetime->format( 'i')==0)?$datetime->format('m/d/Y'):$datetime->format('m/d/Y H:i');
        }
        else return null;
    }
    public function numbersOnlyNull($value){
        return ($value=preg_replace("/\D/","",$value))?$value:null;
    }
    public function arrayIntNotZero($value){
        $value=(array)$value;
        $new=[];
        foreach($value AS $val) {
            if((int)$val){$new[]=(int)$val;}
        }
        return $new;
    }
    public function arrayNotEmpty($value){
        $value=(array)$value;
        $new=[];
        foreach($value AS $val) {
            if($val){$new[]=$val;}
        }
        return $new;
    }
    public function arrayDeliminated($value, $deliminator){
        //Given string such as a,b,c returns [a,b,c]
        return $value ? explode($deliminator, $value) : [];
    }

    private function verifyNotAnArray($value){
        if(is_array($value)) {
            throw new ValidatorException('A string or number was expected but an array was received.');
        }
    }
    private function verifyAnArray($value){
        if(!is_array($value)) {
            throw new ValidatorException('An array was expected but a '.gettype($value).' was provided');
        }
    }
    private function verifyAString($value){
        if(!is_string($value)) {
            throw new ValidatorException('An string was expected but a '.gettype($value).' was provided');
        }
    }
}