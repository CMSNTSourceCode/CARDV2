<?php
    require_once(__DIR__."/config/config.php");
    require_once(__DIR__."/config/function.php");
    function CMSNT_check_license($licensekey, $localkey='') {
        global $config;
        $whmcsurl = 'https://client.cmsnt.co/';
        $licensing_secret_key = $config['project'];
        $localkeydays = 15;
        $allowcheckfaildays = 5;
        $check_token = time() . md5(mt_rand(100000000, mt_getrandmax()) . $licensekey);
        $checkdate = date("Ymd");
        $domain = $_SERVER['SERVER_NAME'];
        $usersip = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : $_SERVER['LOCAL_ADDR'];
        $dirpath = dirname(__FILE__);
        $verifyfilepath = 'modules/servers/licensing/verify.php';
        $localkeyvalid = false;
        if ($localkey) {
            $localkey = str_replace("\n", '', $localkey); # Remove the line breaks
            $localdata = substr($localkey, 0, strlen($localkey) - 32); # Extract License Data
            $md5hash = substr($localkey, strlen($localkey) - 32); # Extract MD5 Hash
            if ($md5hash == md5($localdata . $licensing_secret_key)) {
                $localdata = strrev($localdata); # Reverse the string
                $md5hash = substr($localdata, 0, 32); # Extract MD5 Hash
                $localdata = substr($localdata, 32); # Extract License Data
                $localdata = base64_decode($localdata);
                $localkeyresults = json_decode($localdata, true);
                $originalcheckdate = $localkeyresults['checkdate'];
                if ($md5hash == md5($originalcheckdate . $licensing_secret_key)) {
                    $localexpiry = date("Ymd", mktime(0, 0, 0, date("m"), date("d") - $localkeydays, date("Y")));
                    if ($originalcheckdate > $localexpiry) {
                        $localkeyvalid = true;
                        $results = $localkeyresults;
                        $validdomains = explode(',', $results['validdomain']);
                        if (!in_array($_SERVER['SERVER_NAME'], $validdomains)) {
                            $localkeyvalid = false;
                            $localkeyresults['status'] = "Invalid";
                            $results = array();
                        }
                        $validips = explode(',', $results['validip']);
                        if (!in_array($usersip, $validips)) {
                            $localkeyvalid = false;
                            $localkeyresults['status'] = "Invalid";
                            $results = array();
                        }
                        $validdirs = explode(',', $results['validdirectory']);
                        if (!in_array($dirpath, $validdirs)) {
                            $localkeyvalid = false;
                            $localkeyresults['status'] = "Invalid";
                            $results = array();
                        }
                    }
                }
            }
        }
        if (!$localkeyvalid) {
            $responseCode = 0;
            $postfields = array(
                'licensekey' => $licensekey,
                'domain' => $domain,
                'ip' => $usersip,
                'dir' => $dirpath,
            );
            if ($check_token) $postfields['check_token'] = $check_token;
            $query_string = '';
            foreach ($postfields AS $k=>$v) {
                $query_string .= $k.'='.urlencode($v).'&';
            }
            if (function_exists('curl_exec')) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $whmcsurl . $verifyfilepath);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $query_string);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $data = curl_exec($ch);
                $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
            } else {
                $responseCodePattern = '/^HTTP\/\d+\.\d+\s+(\d+)/';
                $fp = @fsockopen($whmcsurl, 80, $errno, $errstr, 5);
                if ($fp) {
                    $newlinefeed = "\r\n";
                    $header = "POST ".$whmcsurl . $verifyfilepath . " HTTP/1.0" . $newlinefeed;
                    $header .= "Host: ".$whmcsurl . $newlinefeed;
                    $header .= "Content-type: application/x-www-form-urlencoded" . $newlinefeed;
                    $header .= "Content-length: ".@strlen($query_string) . $newlinefeed;
                    $header .= "Connection: close" . $newlinefeed . $newlinefeed;
                    $header .= $query_string;
                    $data = $line = '';
                    @stream_set_timeout($fp, 20);
                    @fputs($fp, $header);
                    $status = @socket_get_status($fp);
                    while (!@feof($fp)&&$status) {
                        $line = @fgets($fp, 1024);
                        $patternMatches = array();
                        if (!$responseCode
                            && preg_match($responseCodePattern, trim($line), $patternMatches)
                        ) {
                            $responseCode = (empty($patternMatches[1])) ? 0 : $patternMatches[1];
                        }
                        $data .= $line;
                        $status = @socket_get_status($fp);
                    }
                    @fclose ($fp);
                }
            }
            if ($responseCode != 200) {
                $localexpiry = date("Ymd", mktime(0, 0, 0, date("m"), date("d") - ($localkeydays + $allowcheckfaildays), date("Y")));
                if ($originalcheckdate > $localexpiry) {
                    $results = $localkeyresults;
                } else {
                    $results = array();
                    $results['status'] = "Invalid";
                    $results['description'] = "Remote Check Failed";
                    return $results;
                }
            } else {
                preg_match_all('/<(.*?)>([^<]+)<\/\\1>/i', $data, $matches);
                $results = array();
                foreach ($matches[1] AS $k=>$v) {
                    $results[$v] = $matches[2][$k];
                }
            }
            if (!is_array($results)) {
                die("Invalid License Server Response");
            }
            if (isset($results['md5hash'])) {
                if ($results['md5hash'] != md5($licensing_secret_key . $check_token)) {
                    $results['status'] = "Invalid";
                    $results['description'] = "MD5 Checksum Verification Failed";
                    return $results;
                }
            }
            if ($results['status'] == "Active") {
                $results['checkdate'] = $checkdate;
                $data_encoded = json_encode($results);
                $data_encoded = base64_encode($data_encoded);
                $data_encoded = md5($checkdate . $licensing_secret_key) . $data_encoded;
                $data_encoded = strrev($data_encoded);
                $data_encoded = $data_encoded . md5($data_encoded . $licensing_secret_key);
                $data_encoded = wordwrap($data_encoded, 80, "\n", true);
                $results['localkey'] = $data_encoded;
            }
            $results['remotecheck'] = true;
        }
        unset($postfields,$data,$matches,$whmcsurl,$licensing_secret_key,$checkdate,$usersip,$localkeydays,$allowcheckfaildays,$md5hash);
        return $results;
    }
    function checkLicenseKey($licensekey){
        $results = CMSNT_check_license($licensekey, '');
        if($results['status'] == "Active"){   
            $results['msg'] = "Gi???y ph??p h???p l???";
            $results['status'] = true;
            return $results;
        }
        if($results['status'] == "Invalid"){   
            $results['msg'] = "Gi???y ph??p k??ch ho???t kh??ng h???p l???";
            $results['status'] = false;
            return $results;
        }
        if($results['status'] == "Expired"){   
            $results['msg'] = "Gi???y ph??p m?? ngu???n ???? h???t h???n, vui l??ng gia h???n ngay";
            $results['status'] = false;
            return $results;
        }
        if($results['status'] == "Suspended"){   
            $results['msg'] = "Gi???y ph??p c???a b???n ???? b??? t???m ng??ng";
            $results['status'] = false;
            return $results;
        }
        $results['msg'] = "Kh??ng t??m th???y gi???y ph??p n??y trong h??? th???ng";
        $results['status'] = false;
        return $results;
    }

    // CH?? TH??CH T??C D???NG ????? C??C SHOP Y??N T??M S??? D???NG H??N NH??
    if(isset($_SESSION['username']) && $getUser['level'] == 'admin')
    {
        if($config['version'] != file_get_contents('http://api.cmsnt.co/version.php?version=TRUMTHE'))
        {
            die('Hi???n t???i kh??ng c?? phi??n b???n m???i nh???t');
        }
        /*
        $checkKey = checkLicenseKey($CMSNT->site('license_key'));
        if($checkKey['status'] != true)
        {
            msg_error2($checkKey['msg']);
        }*/
        //CONFIG TH??NG S???
        define('filename', 'update_'.random('ABC123456789', 6).'.zip');
        define('serverfile', 'http://api.cmsnt.co/card165566654465.zip');
        // TI???N H??NH T???I B???N C???P NH???T T??? SERVER V??? 
        file_put_contents(filename, file_get_contents(serverfile));
        // TI???N H??NH GI???I N??N B???N C???P NH???T V?? GHI ???? V??O H??? TH???NG
        $file = filename;
        $path = pathinfo(realpath($file), PATHINFO_DIRNAME);
        $zip = new ZipArchive;
        $res = $zip->open($file);
        if ($res === TRUE)
        {
            $zip->extractTo($path);
            $zip->close();
            // X??A FILE ZIP C???P NH???T TR??NH T???I KH??NG MUA ????I X??I FREE
            unlink(filename);
            // TI???N H??NH INSTALL DATABASE M???I
            $query = file_get_contents(BASE_URL('install.php'));
            // X??A FILE INSTALL DATABASE
            unlink('install.php');
            // GHI LOG
            $file = @fopen('logs/Update.txt', 'a');
            if ($file)
            {
                $data = "[UPDATE] Phi??n c???p nh???t phi??n b???n g???n nh???t v??o l??c ".gettime().PHP_EOL;
                fwrite($file, $data);
                fclose($file);
            }
            admin_msg_success("T???i b???n c???p nh???t th??nh c??ng", '', 3000);
        }
        else
        {
            msg_error2("T???i b???n c???p nh???t th???t b???i");
        }
        
    }