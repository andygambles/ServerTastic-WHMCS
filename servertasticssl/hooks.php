<?php

function hook_servertasticssl_pre_send_email($vars) {
    /*
    * PLEASE CONFIGURE VARIABLES BELOW
    * 
    *
    * Choose what this hook has to do after failed attempt of getting the SSL Configuration Link.
    */  $_THE_ACTION = 2;
    /*
    *  Possible options:
    *   1: Hook will stop email sending.
    *   2: Hook will display error message in place of the link.
    *      Set the error message below.
    */  $_THE_ERROR_MESSAGE = '<span style="font-weight:bold;color:F00">There was a problem while getting the link.</span>';
    /*
     * Please note that this hook will do nothing when template of current email message doesn't include {$ssl_configuration_link} variable.
     * 
    */

    $merge_fields = array();
    $email_template_name = $vars['messagename'];
    $query='
        SELECT message
        FROM tblemailtemplates
        WHERE name="'.$email_template_name.'"
        ';
    $result=mysql_query($query);
    $data=mysql_fetch_array($result);
    if(!strpos($data[0],'{$ssl_configuration_link}'))
        # stop the hook when {$ssl_configuration_link} is not used in current email.
        return $merge_fields;
    $relid = $vars['relid'];
    $query='
        SELECT remoteid
        FROM tblsslorders
        WHERE serviceid='.$relid.' AND status <> "Cancelled"
        ';
    $result=mysql_query($query);
    $data=mysql_fetch_array($result);
    $reference=$data[0];
    if(!$reference){
        switch($_THE_ACTION){
            case 1:
                $merge_fields['abortsend'] = true;
                return $merge_fields;
            break;
            case 2:
                $merge_fields['ssl_configuration_link'] =$_THE_ERROR_MESSAGE;
                return $merge_fields;
            break;
        }
    }
    $query='
        SELECT tblproducts.configoption1, tblproducts.configoption3
        FROM tblproducts
        LEFT JOIN tblhosting ON tblproducts.id=tblhosting.packageid
        WHERE tblhosting.id='.$relid;
    $result=mysql_query($query);
    $data=mysql_fetch_array($result);
    $apikey=$data[0];
    $data[1]=='on' ? $testmode=true : $testmode=false;
    if($testmode){
        $url = "https://test-api.servertastic.com/ssl/order/review?api_key=$apikey&reseller_order_id=$reference";
    }else{
        $url = "https://api.servertastic.com/ssl/order/review?api_key=$apikey&reseller_order_id=$reference";
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 100);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $data = curl_exec($ch);
    if($data === false) {
        switch($_THE_ACTION){
            case 1:
                $merge_fields['abortsend'] = true;
                return $merge_fields;
            break;
            case 2:
                $merge_fields['ssl_configuration_link'] =$_THE_ERROR_MESSAGE;
                return $merge_fields;
            break;
        }
    }
    curl_close($ch);
    if(!$result = new SimpleXMLElement($data)){
        switch($_THE_ACTION){
            case 1:
                $merge_fields['abortsend'] = true;
                return $merge_fields;
            break;
            case 2:
                $merge_fields['ssl_configuration_link'] =$_THE_ERROR_MESSAGE;
                return $merge_fields;
            break;
        }
    }
    $configurationlink=$result->invite_url;
    $merge_fields['ssl_configuration_link'] ="<a href=\"$configurationlink\" target=\"_blank\">$configurationlink</a>";
    return $merge_fields;
}

add_hook("EmailPreSend",1,"hook_servertasticssl_pre_send_email");

?>