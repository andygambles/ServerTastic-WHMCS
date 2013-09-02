<?php
/************************************************************************************************
	ServerTastic WHMCS module																	
	Module developed for WHMCS that enables the ServerTastic Reseller functionality
	to be integrated into the WHMCS software. Use of this code is at your own risk
	and can be modified to suit your needs but please leave this block in place.
	
	In order to use the module you must be a registered ServerTastic Reseller and purchase points,
 	to regsiter, receive your API key and to purchase points please register at
	https://reseller.servertastic.com.
		
	It is recommend that you consult the latest ServerTastic API docs prior to set-up of
	configurable options https://support.servertastic.com/entries/21194461-reseller-api-documentation
	
	CHANGELOG: https://servertastic.codebasehq.com/changelog/whmcs/ssl
	LATEST VERSION: https://servertastic.codebasehq.com/whmcs/ssl.svn
	
************************************************************************************************/
function servertasticssl_ConfigOptions() {
	$configarray = array(
		"API Key" => array( "Type" => "text", "Size" => "20", "Description" => "Servertastic Reseller API Key. For more information on becoming a reseller please contact resellers@servertastic.com.", ),
		"Certificate Type" => array( "Type" => "dropdown", "Options" => "RapidSSL|4,RapidSSLWildcard|4,QuickSSLPremium|4,TrueBizID|4,TrueBizIDWildcard|4,TrueBizIDEV|2,TrueBizIDMD|4,TrueBizIDEVMD|2,SecureSite|3,SecureSiteEV|2,SecureSitePro|3,SecureSiteProEV|2,SGCSuperCerts|3,SSLWebServer|3,SSLWebServerWildcard|2,SSLWebServerEV|2,SSL123|2", ),
		"Test Mode" => array( "Type" => "yesno", ),
    );
    return $configarray;
}

function servertasticssl_IsRecorded($params){
    $serviceid=$params["serviceid"];
    $result=mysql_query('SELECT remoteid FROM tblsslorders WHERE serviceid = "'.$serviceid.'" AND status <> "Cancelled"');
    $data = mysql_fetch_array($result);
    if ($data[0]) {
        return true;
    }
    return false;
}
function servertasticssl_CreateReference($params){
    $serviceid=$params["serviceid"];
    
    $result = select_query("tblsslorders","COUNT(*)",array("serviceid"=>$serviceid));
    $data = mysql_fetch_array($result);
    if (!$data[0]) {
        return $serviceid;
    }
    $result=mysql_query('SELECT remoteid FROM tblsslorders WHERE serviceid = "'.$serviceid.'" AND status <> "Cancelled"');
    $data = mysql_fetch_array($result);
    if ($data[0]) {
        return $data[0];
    }

    $result = select_query("tblsslorders","COUNT(*)",array("serviceid"=>$params["serviceid"],"status"=>"Cancelled"));
    $data = mysql_fetch_array($result);
    return $serviceid.'.'.$data[0]+1;    
}
function servertasticssl_CreateAccount($params) {

    if (servertasticssl_IsRecorded($params)) {
        return "An SSL Order already exists for this order";
    }
    
        $maxservercountarr = array();
	$maxservercountarr["SecureSiteProEV"] =499;
	$maxservercountarr["SecureSite"] = $maxservercountarr["SecureSiteEV"] = $maxservercountarr["SecureSitePro"] = $maxservercountarr["SGCSuperCerts"] = $maxservercountarr["SSLWebServer"] = $maxservercountarr["SSLWebServerWildcard"] = $maxservercountarr["SSLWebServerEV"] = $maxservercountarr["SSL123"] = 500;

    $certtype = ($params["configoptions"]["Certificate Type"]) ? $params["configoptions"]["Certificate Type"] : $params["configoption2"];
	$certproduct = current(explode("|",$certtype,2));	

	$maxyears = end(explode("|",$certtype,2));
    $certyears = ($params["configoptions"]["Years"]) ? ($params["configoptions"]["Years"] <= $maxyears) ? $params["configoptions"]["Years"] : $maxyears : 1;
	if(!$params["configoptions"]["Servers Count"]) { $params["configoptions"]["Servers Count"] = 1; }
	$servercount = ($maxservercountarr[$certproduct]) ? ($params["configoptions"]["Servers Count"] <= $maxservercountarr[$certproduct]) ? $params["configoptions"]["Servers Count"] : $maxservercountarr[$certproduct] : '1';
	$productcode = $certproduct.'-'.($certyears*12);
	
	//Deal with the SAN counts (min|max)
	$sancountarr=array();
	$sancountarr["SecureSite"]=$sancountarr["SecureSiteEV"]=$sancountarr["SecureSitePro"]=$sancountarr["SecureSiteProEV"]='0|24';
	$sancountarr["TrueBizIDMD"]=$sancountarr["TrueBizIDEVMD"]='4|24';
	
	if(array_key_exists($certproduct,$sancountarr)){
		$min_san_count = current(explode("|",$sancountarr[$certproduct],2));	
		$max_san_count = end(explode("|",$sancountarr[$certproduct],2));
	
		if(!isset($params["configoptions"]["SAN Count"])){
			return "A SAN count value is required for this product";
		}else{
			$san_count=$params["configoptions"]["SAN Count"];
			
			if(($san_count<$min_san_count)||($san_count>$max_san_count)){
				return "The SAN count falls outside the specified range";
			}
		}
	}else{
		$san_count=0;
	}
	$postfields = array();
	$postfields["st_product_code"] = $productcode;
	$postfields["api_key"] = $params["configoption1"];
	$postfields["end_customer_email"] = $params["clientsdetails"]["email"];
	$postfields["san_count"] = $san_count;
	//WHMCS integration source id is '3'
	$postfields["integration_source_id"] = 3;
	if($servercount) $postfields["server_count"] = $servercount;
        $postfields["reseller_unique_reference"] = servertasticssl_CreateReference($params);
	
	$result = servertasticssl_SendCommand("System","order.xml","place",$postfields,$params);

	if ($result["response"]["status"] == "ERROR") return $result["response"]["message"];
	
	if ($result["response"]["success"] == "Order placed") {
		$orderid = $result["response"]["reseller_order_id"];

		if(!$orderid) return "Unable to obtain Order-ID";
		
		$inviteurl = $result["response"]["invite_url"];
		$sslorderid = insert_query("tblsslorders",array("userid" => $params["clientsdetails"]["userid"],"serviceid" => $params["serviceid"],"remoteid" => $orderid,"module"=>"servertasticssl","certtype" => $certtype,"status" => "Awaiting Configuration"));
		
		sendMessage("SSL Certificate Configuration Required",$params["serviceid"],array("ssl_configuration_link"=>"<a href=\"$inviteurl\">$inviteurl</a>"));
    	return "success";
		
	}
    
	if(!$orderid) return "Unable to obtain Order-ID";

}

function servertasticssl_TerminateAccount($params) {
    $sslexists = get_query_val("tblsslorders","COUNT(*)",array("serviceid"=>$params["serviceid"],"status"=>"Awaiting Configuration"));
    if (!$sslexists) {
        return "SSL Either not Provisioned or Not Awaiting Configuration so unable to cancel";
    }

	$postfields = array();
	$postfields["api_key"] = $params["configoption1"];
	$postfields["reseller_order_id"] = servertasticssl_CreateReference($params);
        
	$result = servertasticssl_SendCommand("System","order","cancel",$postfields,$params);
	if ($result["response"]["status"] == "ERROR") return $result["response"]["message"];	
        update_query("tblsslorders",array("status"=>"Cancelled"),array("remoteid"=>servertasticssl_CreateReference($params)));

	return "success";
}

function servertasticssl_AdminCustomButtonArray() {
	$buttonarray = array(
     "Resend Configuration Email" => "resend",
	);
	return $buttonarray;
}

function servertasticssl_resend($params) {
    $id = get_query_val("tblsslorders","id",array("remoteid"=>servertasticssl_CreateReference($params)));
    if (!$id) {
        return "No SSL Order exists for this product";
    }

	$postfields = array();	
	$postfields["api_key"] = $params["configoption1"];
        $postfields["reseller_order_id"] = servertasticssl_CreateReference($params);
	$postfields["email_type"] = "Invite";
	
	$result = servertasticssl_SendCommand("Admin Area","order","resendemail",$postfields,$params);
	
}

function servertasticssl_ClientArea($params) {
    global $_LANG;
    $data = get_query_vals("tblsslorders","",array("remoteid"=>servertasticssl_CreateReference($params)));
    $id = $data["id"];
    $orderid = $data["orderid"];
    $serviceid = $data["serviceid"];
    $remoteid = $data["remoteid"];
    $module = $data["module"];
    $certtype = $data["certtype"];
    $domain = $data["domain"];
    $provisiondate = $data["provisiondate"];
    $completiondate = $data["completiondate"];
    $status = $data["status"];
    if ($id) {
		
		if($_POST["newapproveremail"] && $params['serviceid']) {
			$postfields = array();	
			$postfields["api_key"] = $params["configoption1"];
                        $postfields["reseller_order_id"] = servertasticssl_CreateReference($params);
			$postfields["email"] = $_POST["newapproveremail"];
			$result = servertasticssl_SendCommand("Client Area","order","changeapproveremail",$postfields,$params);
		}

		$postfields = array();	
		$postfields["api_key"] = $params["configoption1"];
                $postfields["reseller_order_id"] = servertasticssl_CreateReference($params);
		
		$result = servertasticssl_SendCommand("Client Area ","order","review",$postfields,$params);
		if ($result["response"]["status"] == "ERROR") return $result["response"]["message"];
		
		$remotestatus = $result["response"]["order_status"];
		$inviteurl = $result["response"]["invite_url"];

		if($remotestatus == "Order placed" || $remotestatus == "Invite Available") {
			$awaitingsslconfiguration = true;
		}
		
		if( ($status != "Awaiting Configuration" && $remotestatus == "Order placed") || ($status != "Awaiting Configuration" && $remotestatus == "Invite Available") ) {
                        update_query("tblsslorders",array("status"=>"Awaiting Configuration"),array("remoteid"=>servertasticssl_CreateReference($params)));
			$awaitingsslconfiguration = true;
		}
		
		if( ($status != "Completed" && $remotestatus == "Awaiting Customer Verification") || ($status != "Completed" && $remotestatus == "Awaiting Provider Approval") || ($status != "Completed" && $remotestatus == "Queued") || ($status != "Completed" && $remotestatus == "Completed") || ($status != "Completed" && $remotestatus == "ServerTastic Review") ) {
                        update_query("tblsslorders",array("status"=>"Completed"),array("remoteid"=>servertasticssl_CreateReference($params)));
		}
		
		if( ($status != "Cancelled" && $remotestatus == "Cancelled") || ($status != "Cancelled" && $remotestatus == "Roll Back") ) {
                        update_query("tblsslorders",array("status"=>"Cancelled"),array("remoteid"=>servertasticssl_CreateReference($params)));
		}

		
        if (!$provisiondate) {
            $provisiondate = get_query_val("tblhosting","regdate",array("id"=>$params['serviceid']));
        }
        $provisiondate = fromMySQLDate($provisiondate);
		if($awaitingsslconfiguration) { $remotestatus .= ' - <a href="'.$inviteurl.'" target="_blank">Configure Now</a>'; }
        $output = '<div align="left">
<table width="100%">
<tr><td width="150" class="fieldlabel">SSL Provisioning Date:</td><td>'.$provisiondate.'</td></tr>
<tr><td class="fieldlabel">'.$_LANG['sslstatus'].':</td><td>'.$remotestatus.'</td></tr>
<tr><td class="fieldlabel">'.$_LANG['cartqtyupdate'].' '.$_LANG['sslcertapproveremail'].':</td><td><form method="post"><input type="text" name="newapproveremail" /><input type="submit" value="'.$_LANG['clientareasavechanges'].'" /></form></td></tr>
</table>
</div>';
        return $output;
    }
}

function servertasticssl_AdminServicesTabFieldsSave($params) {
	if($_POST["newapproveremail"] && $params['serviceid']) {
		$postfields = array();	
		$postfields["api_key"] = $params["configoption1"];
                $postfields["reseller_order_id"] = servertasticssl_CreateReference($params);
		$postfields["email"] = $_POST["newapproveremail"];
		$result = servertasticssl_SendCommand("Admin Area","order","changeapproveremail",$postfields,$params);
	}
}

function servertasticssl_AdminServicesTabFields($params) {

    $data = get_query_vals("tblsslorders","",array("remoteid"=>servertasticssl_CreateReference($params)));
    $id = $data["id"];
    $orderid = $data["orderid"];
    $serviceid = $data["serviceid"];
    $remoteid = $data["remoteid"];
    $module = $data["module"];
    $certtype = $data["certtype"];
    $domain = $data["domain"];
    $provisiondate = $data["provisiondate"];
    $completiondate = $data["completiondate"];
    $status = $data["status"];

    if (!$id) {
        $remoteid = '-';
        $status = 'Not Yet Provisioned';
    } else {
		
		if(get_query_val("tblhosting","status",array("id"=>$params["serviceid"])) == "Active"){
			
			$postfields = array();
			$postfields["api_key"] = $params["configoption1"];
                        $postfields["reseller_order_id"] = servertasticssl_CreateReference($params);
			
			$result = servertasticssl_SendCommand("Admin Area","order","review",$postfields,$params);
			if ($result["response"]["status"] == "ERROR") return $result["response"]["message"];
			
			$remotestatus = $result["response"]["order-status"];
			$inviteurl = $result["response"]["invite_url"];
			
			if($remotestatus == "Order placed" || $remotestatus == "Invite Available") {
				$awaitingsslconfiguration = true;
			}
			
			if( ($status != "Awaiting Configuration" && $remotestatus == "Order placed") || ($status != "Awaiting Configuration" && $remotestatus == "Invite Available") ) {
                                update_query("tblsslorders",array("status"=>"Awaiting Configuration"),array("remoteid"=>servertasticssl_CreateReference($params)));
			}
			
			if( ($status != "Completed" && $remotestatus == "Awaiting Customer Verification") || ($status != "Completed" && $remotestatus == "Awaiting Provider Approval") || ($status != "Completed" && $remotestatus == "Queued") || ($status != "Completed" && $remotestatus == "Completed") || ($status != "Completed" && $remotestatus == "ServerTastic Review") ) {
                                update_query("tblsslorders",array("status"=>"Completed"),array("remoteid"=>servertasticssl_CreateReference($params)));
			}
			
			if( ($status != "Cancelled" && $remotestatus == "Cancelled") || ($status != "Cancelled" && $remotestatus == "Roll Back") ) {
                                update_query("tblsslorders",array("status"=>"Cancelled"),array("remoteid"=>servertasticssl_CreateReference($params)));
			}
	
		}
		if($awaitingsslconfiguration) { $remotestatus .= ' - <a href="'.$inviteurl.'" target="_blank">Configure Now</a>'; }
			
	}

    $fieldsarray = array(
     'Servertastic Order ID' => $remoteid,
     'SSL Configuration Status' => ($remotestatus) ? $remotestatus : $status,
     'Change Approver Email' => '<input type="text" name="newapproveremail" />',
    );
    return $fieldsarray;

}

function servertasticssl_SendCommand($interface,$type,$action,$postfields,$params) {
	
    if($params["configoption3"]){
    	$url = "https://test-api.servertastic.com/ssl/$type/$action";
    }else{
    	$url = "https://api.servertastic.com/ssl/$type/$action";
    }
    $ch = curl_init();
	$url .= "?";
	foreach($postfields as $field => $data){
		$url .= "$field=".rawurlencode($data)."&";
	}
	$url = substr($url,0,-1);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_TIMEOUT, 100);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	$data = curl_exec($ch);
	if (curl_errno($ch)) {
		$result["response"]["status"] = "ERROR";
		$result["response"]["message"] = "CURL Error: ".curl_errno($ch)." - ".curl_error($ch);
	} else {
        $result = servertasticssl_xml2array($data);
		if($result["response"]["error"]) {
			$result["response"]["status"] = "ERROR";
			$result["response"]["message"] = "API Error: ".$result["response"]["error"]["code"].' - '.$result["response"]["error"]["message"];
		}
	}
	curl_close($ch);

	logModuleCall('servertasticssl',$interface.' '.str_replace(".xml",'',$type).' '.$action,$postfields,$data,$result,array($params["configoption1"]));

    return $result;
}

function servertasticssl_xml2array($contents, $get_attributes = 1, $priority = 'tag') {

    $parser = xml_parser_create('');

    xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8");
    xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
    xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
    xml_parse_into_struct($parser, trim($contents), $xml_values);
    xml_parser_free($parser);
    if (!$xml_values)
        return; //Hmm...
    $xml_array = array ();
    $parents = array ();
    $opened_tags = array ();
    $arr = array ();
    $current = & $xml_array;
    $repeated_tag_index = array ();
    foreach ($xml_values as $data)
    {
        unset ($attributes, $value);
        extract($data);
        $result = array ();
        $attributes_data = array ();
        if (isset ($value))
        {
            if ($priority == 'tag')
                $result = $value;
            else
                $result['value'] = $value;
        }
        if (isset ($attributes) and $get_attributes)
        {
            foreach ($attributes as $attr => $val)
            {
                if ($priority == 'tag')
                    $attributes_data[$attr] = $val;
                else
                    $result['attr'][$attr] = $val; //Set all the attributes in a array called 'attr'
            }
        }
        if ($type == "open")
        {
            $parent[$level -1] = & $current;
            if (!is_array($current) or (!in_array($tag, array_keys($current))))
            {
                $current[$tag] = $result;
                if ($attributes_data)
                    $current[$tag . '_attr'] = $attributes_data;
                $repeated_tag_index[$tag . '_' . $level] = 1;
                $current = & $current[$tag];
            }
            else
            {
                if (isset ($current[$tag][0]))
                {
                    $current[$tag][$repeated_tag_index[$tag . '_' . $level]] = $result;
                    $repeated_tag_index[$tag . '_' . $level]++;
                }
                else
                {
                    $current[$tag] = array (
                        $current[$tag],
                        $result
                    );
                    $repeated_tag_index[$tag . '_' . $level] = 2;
                    if (isset ($current[$tag . '_attr']))
                    {
                        $current[$tag]['0_attr'] = $current[$tag . '_attr'];
                        unset ($current[$tag . '_attr']);
                    }
                }
                $last_item_index = $repeated_tag_index[$tag . '_' . $level] - 1;
                $current = & $current[$tag][$last_item_index];
            }
        }
        elseif ($type == "complete")
        {
            if (!isset ($current[$tag]))
            {
                $current[$tag] = $result;
                $repeated_tag_index[$tag . '_' . $level] = 1;
                if ($priority == 'tag' and $attributes_data)
                    $current[$tag . '_attr'] = $attributes_data;
            }
            else
            {
                if (isset ($current[$tag][0]) and is_array($current[$tag]))
                {
                    $current[$tag][$repeated_tag_index[$tag . '_' . $level]] = $result;
                    if ($priority == 'tag' and $get_attributes and $attributes_data)
                    {
                        $current[$tag][$repeated_tag_index[$tag . '_' . $level] . '_attr'] = $attributes_data;
                    }
                    $repeated_tag_index[$tag . '_' . $level]++;
                }
                else
                {
                    $current[$tag] = array (
                        $current[$tag],
                        $result
                    );
                    $repeated_tag_index[$tag . '_' . $level] = 1;
                    if ($priority == 'tag' and $get_attributes)
                    {
                        if (isset ($current[$tag . '_attr']))
                        {
                            $current[$tag]['0_attr'] = $current[$tag . '_attr'];
                            unset ($current[$tag . '_attr']);
                        }
                        if ($attributes_data)
                        {
                            $current[$tag][$repeated_tag_index[$tag . '_' . $level] . '_attr'] = $attributes_data;
                        }
                    }
                    $repeated_tag_index[$tag . '_' . $level]++; //0 and 1 index is already taken
                }
            }
        }
        elseif ($type == 'close')
        {
            $current = & $parent[$level -1];
        }
    }
    return ($xml_array);
}

?>