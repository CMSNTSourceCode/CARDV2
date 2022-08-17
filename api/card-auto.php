<?php 
    require_once("../config/config.php");
    require_once("../config/function.php");

    if (isset($_GET['type']) && isset($_GET['menhgia']) && isset($_GET['seri']) && isset($_GET['pin']) && isset($_GET['APIKey']) && isset($_GET['callback']) )
    {
        $type = check_string($_GET['type']);
        $loaithe = $type;
        $menhgia = check_string($_GET['menhgia']);
        $seri = check_string($_GET['seri']);
        $pin = check_string($_GET['pin']);
        $APIKey = check_string($_GET['APIKey']);
        $content = check_string($_GET['content']);
        $callback = check_string($_GET['callback']);
        $code = random('qwertyuiopasdfghklzxcvbnm1234567890',4).time();
        $getUser = $CMSNT->get_row("SELECT * FROM `users` WHERE `token` = '$APIKey' ");
        
        if($CMSNT->site('baotri') == 'OFF')
        {
            $data['data'] = [
                "status"    =>  'error',
                "msg"       =>  'API nạp thẻ đang bảo trì '
                ];
            die(json_encode($data, JSON_PRETTY_PRINT));
        }
        if(!$getUser)
        {
            $data['data'] = [
                "status"    =>  'error',
                "msg"       =>  'API Key nạp thẻ không hợp lệ, vui lòng báo Admin !'
                ];
                die(json_encode($data, JSON_PRETTY_PRINT));
        }
        if($CMSNT->num_rows("SELECT * FROM `card_auto` WHERE `trangthai` = 'xuly' AND `username` = '".$getUser['username']."'  ") >= 5)
        {
            $data['data'] = [
                "status"    =>  'error',
                "msg"       =>  'Hệ thống đang có nhiều thẻ đang chờ xử lý, vui lòng đợi 1 lát rồi thử lại'
                ];
            die(json_encode($data, JSON_PRETTY_PRINT));
        }
        if(
            $CMSNT->num_rows("SELECT * FROM `card_auto` WHERE `trangthai` = 'thatbai' AND `username` = '".$getUser['username']."' AND `thoigian` >= DATE(NOW()) AND `thoigian` < DATE(NOW()) + INTERVAL 1 DAY  ") - 
            $CMSNT->num_rows("SELECT * FROM `card_auto` WHERE `trangthai` = 'hoantat' AND `username` = '".$getUser['username']."' AND `thoigian` >= DATE(NOW()) AND `thoigian` < DATE(NOW()) + INTERVAL 1 DAY  ") >= 30)
        {
            $data['data'] = [
                "status"    =>  'error',
                "msg"       =>  'API bị chặn sử dụng chức năng này trong 24h!'
                ];
            die(json_encode($data, JSON_PRETTY_PRINT));
        }
        $ck = $CMSNT->get_row("SELECT * FROM `".myGroupExCard($getUser['username'])."` WHERE `loaithe` = '$loaithe' AND `menhgia` = '$menhgia'  ");
        $ck = is_array($ck) ? $ck['ck'] : false;
        if($ck === false)
        {
            $data['data'] = [
                "status"    =>  'error',
                "msg"       =>  'Dữ liệu không hợp lệ'
                ];
            die(json_encode($data, JSON_PRETTY_PRINT));
        }
        if($ck == 0)
        {
            $data['data'] = [
                "status"    =>  'error',
                "msg"       =>  'Thẻ đang bảo trì, vui lòng đợi'
                ];
            die(json_encode($data, JSON_PRETTY_PRINT));
        }
        $thucnhan = $menhgia - $menhgia * $ck / 100;
        // CARD365.VN
        if($CMSNT->site('status_cardv3') == 'ON')
        {
            $result = cardv3($loaithe, $pin, $seri, $menhgia, $code);
            if($result['status'] == 100)
            {
                $data['data'] = [
                    "status"    =>  'error',
                    "msg"       =>  $result['message']
                    ];
                    die(json_encode($data, JSON_PRETTY_PRINT));
            }
            if($result['status'] == 1)
            {
                $CMSNT->cong("users", "money", $thucnhan, " `username` = '".$getUser['username']."' ");
                $CMSNT->cong("users", "total_money", $thucnhan, " `username` = '".$getUser['username']."' ");
                /* CẬP NHẬT DÒNG TIỀN */
                $CMSNT->insert("dongtien", array(
                    'sotientruoc'   => $getUser['money'],
                    'sotienthaydoi' => $thucnhan,
                    'sotiensau'     => $getUser['money'] + $thucnhan,
                    'thoigian'      => gettime(),
                    'noidung'       => 'Đổi thẻ seri ('.$seri.')',
                    'username'      => $getUser['username']
                ));
                $CMSNT->insert("card_auto", [
                    'code'      => $code,
                    'seri'      => $seri,
                    'pin'       => $pin,
                    'loaithe'   => $loaithe,
                    'amount'    => $result['amount'],
                    'menhgia'   => $menhgia,
                    'thucnhan'  => $thucnhan,
                    'request_id' => $content,
                    'username'  => $getUser['username'],
                    'trangthai' => 'hoantat',
                    'ghichu'    => '',
                    'thoigian'  => gettime(),
                    'capnhat'   => gettime(),
                    'callback'  => $callback,
                    'server'    => 'card365.vn'
                ]);
                $data['data'] = [
                "status"    =>  'success',
                "msg"       =>  'Nạp thẻ thành công'
                ];
                echo json_encode($data, JSON_PRETTY_PRINT);
                if(isset($callback))
                {
                    curl_get($callback."?content=".$code."&status=hoantat&thucnhan=".$thucnhan."&menhgiathuc=".$menhgia);
                }
                die;
            }
            if($result['status'] == 2)
            {
                $CMSNT->insert("card_auto", [
                    'code'      => $code,
                    'seri'      => $seri,
                    'pin'       => $pin,
                    'loaithe'   => $loaithe,
                    'menhgia'   => $menhgia,
                    'request_id' => $content,
                    'thucnhan'  => '0',
                    'username'  => $getUser['username'],
                    'trangthai' => 'thatbai',
                    'ghichu'    => 'Sai mệnh giá',
                    'thoigian'  => gettime(),
                    'callback'  => $callback,
                    'server'    => 'card365.vn'
                ]);
                $data['data'] = [
                "status"    =>  'error',
                "msg"       =>  'Sai mệnh giá'
                ];
                echo json_encode($data, JSON_PRETTY_PRINT);
                if(isset($callback))
                {
                    curl_get($callback."?content=".$code."&status=thatbai&thucnhan=0&menhgiathuc=0");
                }
                die;
            }
            if($result['status'] == 3)
            {
                $CMSNT->insert("card_auto", [
                    'code'      => $code,
                    'seri'      => $seri,
                    'pin'       => $pin,
                    'loaithe'   => $loaithe,
                    'menhgia'   => $menhgia,
                    'request_id' => $content,
                    'thucnhan'  => '0',
                    'username'  => $getUser['username'],
                    'trangthai' => 'thatbai',
                    'ghichu'    => 'Thẻ cào không hợp lệ hoặc đã được sử dụng',
                    'thoigian'  => gettime(),
                    'callback'  => $callback,
                    'server'    => 'card365.vn'
                ]);
                $data['data'] = [
                "status"    =>  'error',
                "msg"       =>  'Thẻ cào không hợp lệ hoặc đã được sử dụng'
                ];
                echo json_encode($data, JSON_PRETTY_PRINT);
                if(isset($callback))
                {
                    curl_get($callback."?content=".$code."&status=thatbai&thucnhan=0&menhgiathuc=0");
                }
                die;
            }
            if($result['status'] == 4)
            {
                $data['data'] = [
                    "status"    =>  'error',
                    "msg"       =>  'Chức năng này đang bảo trì, vui lòng quay lại sau'
                    ];
                die(json_encode($data, JSON_PRETTY_PRINT));
            }
            if($result['status'] == 99)
            {
                $isInsert = $CMSNT->insert("card_auto", [
                    'code'      => $code,
                    'seri'      => $seri,
                    'pin'       => $pin,
                    'loaithe'   => $loaithe,
                    'menhgia'   => $menhgia,
                    'thucnhan'  => $thucnhan,
                    'request_id' => $content,
                    'username'  => $getUser['username'],
                    'trangthai' => 'xuly',
                    'ghichu'    => '',
                    'thoigian'  => gettime(),
                    'callback'  => $callback,
                    'server'    => 'card365.vn'
                ]);
                $data['data'] = [
                    "status"    =>  'success',
                    "msg"       =>  'Gửi thẻ thành công, vui lòng đợi duyệt!'
                    ];
                die(json_encode($data, JSON_PRETTY_PRINT));
            }
        }
        
        $data['data'] = [
            "status"    =>  'error',
            "msg"       =>  'Hệ thống đang bảo trì, vui lòng quay lại sau.'
            ];
        die(json_encode($data, JSON_PRETTY_PRINT));
    }