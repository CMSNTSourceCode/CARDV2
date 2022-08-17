<?php  
    require_once(__DIR__."/../../config/config.php");
    require_once(__DIR__."/../../config/function.php");


    if(empty($_SESSION['username']))
    {
        echo msg_error3("Vui lòng đăng nhập");
        die();
    }
    foreach($_POST['data'] as $data)
    {
        $token = check_string($_POST['token']);
        $loaithe = check_string($data['loaithe']);
        $menhgia = check_string($data['menhgia']);
        $seri = check_string($data['serial']);
        $pin = check_string($data['pin']);
        $code = random('123456789', 4).time();

        if(empty($loaithe))
        {
            echo msg_error3("Vui lòng chọn loại thẻ");
            continue;
        }
        if(empty($menhgia))
        {
            echo msg_error3("Vui lòng chọn mệnh giá");
            continue;
        }
        if(empty($seri))
        {
            echo msg_error3("Vui lòng nhập seri thẻ");
            continue;
        }
        if(empty($pin))
        {
            echo msg_error3("Vui lòng nhập mã thẻ");
            continue;
        }
        if (strlen($seri) < 5 || strlen($pin) < 5)
        {
            echo msg_error3("Mã thẻ hoặc seri không đúng định dạng!");
            continue;
        }
        $getUser = $CMSNT->get_row("SELECT * FROM `users` WHERE `username` = '".$getUser['username']."' ");
        if(!$getUser)
        {
            echo msg_error3("Vui lòng đăng nhập để sử dụng chức năng này");
            continue;
        }
        if($CMSNT->get_row("SELECT * FROM `card_auto` WHERE `seri` = '$seri' AND `pin` = '$pin' AND `loaithe` = '$loaithe' "))
        {
            echo msg_error3("Thẻ này đã tồn tại trong hệ thống của chúng tôi");
            continue;
        }
        if($CMSNT->num_rows("SELECT * FROM `card_auto` WHERE `trangthai` = 'xuly' AND `username` = '".$getUser['username']."'  ") >= 4)
        {
            echo msg_error3("Bạn đang có nhiều thẻ đang chờ xử lý, vui lòng đợi 1 lát rồi thử lại");
            continue;
        }
        if(
        $CMSNT->num_rows("SELECT * FROM `card_auto` WHERE `trangthai` = 'thatbai' AND `username` = '".$getUser['username']."' AND `thoigian` >= DATE(NOW()) AND `thoigian` < DATE(NOW()) + INTERVAL 1 DAY  ") - 
        $CMSNT->num_rows("SELECT * FROM `card_auto` WHERE `trangthai` = 'hoantat' AND `username` = '".$getUser['username']."' AND `thoigian` >= DATE(NOW()) AND `thoigian` < DATE(NOW()) + INTERVAL 1 DAY  ") >= 3)
        {
            echo msg_error3("Bạn đã bị chặn sử dụng chức năng này");
            continue;
        }
        $ck = $CMSNT->get_row("SELECT * FROM `".myGroupExCard($getUser['username'])."` WHERE `loaithe` = '$loaithe' AND `menhgia` = '$menhgia' ");
        $ck = is_array($ck) ? $ck['ck'] : false;
        if($ck === false)
        {
            echo msg_error3("Loại thẻ không tồn tại trong hê thống");
            continue;
        }
        if($ck == 0)
        {
            echo msg_error3("Thẻ này đang bảo trì, vui lòng đợi !");
            continue;
        }
        $thucnhan = $menhgia - $menhgia * $ck / 100;
        // CARD365.VN
        if($CMSNT->site('status_cardv3') == 'ON'){
            $result = cardv3($loaithe, $pin, $seri, $menhgia, $code);
            if($result['status'] == 100)
            {
                echo msg_error3($result['message']);
                continue;
            }
            if($result['status'] == 1)
            {
                $CMSNT->cong("users", "money", $thucnhan, " `token` = '$token' ");
                $CMSNT->cong("users", "total_money", $thucnhan, " `token` = '$token' ");
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
                    'amount'    => $result['amount'],
                    'loaithe'   => $loaithe,
                    'menhgia'   => $menhgia,
                    'thucnhan'  => $thucnhan,
                    'username'  => $getUser['username'],
                    'trangthai' => 'hoantat',
                    'ghichu'    => '',
                    'thoigian'  => gettime(),
                    'capnhat'   => gettime(),
                    'server'    => 'card365.vn'
                ]);
                /**
                 * XỬ LÝ HOA HỒNG CHO BẠN BÈ
                 */
                if($getUser['ref'] != NULL)
                {
                    if($CMSNT->site('status_ref') == 'ON')
                    {
                        $hoahong = $menhgia * $CMSNT->site('ck_ref') / 100;
                        $getUser_ref = $CMSNT->get_row("SELECT * FROM `users` WHERE `id` = '".$getUser['ref']."' ");
                        /**
                         * CỘNG TIỀN CHO REFERRAL
                         */
                        $CMSNT->cong("users", "money", $hoahong, " `username` = '".$getUser_ref['username']."' ");
                        $CMSNT->cong("users", "total_money", $hoahong, " `username` = '".$getUser_ref['username']."' ");
                        $CMSNT->insert("dongtien", array(
                            'sotientruoc'   => $getUser_ref['money'],
                            'sotienthaydoi' => $hoahong,
                            'sotiensau'     => $getUser_ref['money'] + $hoahong,
                            'thoigian'      => gettime(),
                            'noidung'       => 'Hoa hồng bạn bè ('.$getUser['username'].')',
                            'username'      => $getUser_ref['username']
                        ));
                    }
                }
                echo msg_success3('Nạp thẻ thành công!');
                continue;
            }
            if($result['status'] == 2)
            {
                $CMSNT->insert("card_auto", [
                    'code'      => $code,
                    'seri'      => $seri,
                    'pin'       => $pin,
                    'loaithe'   => $loaithe,
                    'menhgia'   => $menhgia,
                    'thucnhan'  => '0',
                    'username'  => $getUser['username'],
                    'trangthai' => 'thatbai',
                    'ghichu'    => 'Sai mệnh giá',
                    'thoigian'  => gettime(),
                    'capnhat'   => gettime(),
                    'server'    => 'card365.vn'
                ]);
                echo msg_error3('Sai mệnh giá thẻ, vui lòng liên hệ Admin');
                continue;
            }
            if($result['status'] == 3)
            {
                $CMSNT->insert("card_auto", [
                    'code'      => $code,
                    'seri'      => $seri,
                    'pin'       => $pin,
                    'loaithe'   => $loaithe,
                    'menhgia'   => $menhgia,
                    'thucnhan'  => '0',
                    'username'  => $getUser['username'],
                    'trangthai' => 'thatbai',
                    'ghichu'    => 'Thẻ không hợp lệ hoặc đã được sử dụng',
                    'thoigian'  => gettime(),
                    'capnhat'   => gettime(),
                    'server'    => 'card365.vn'
                ]);
                echo msg_error3('Vui lòng kiểm tra lại thẻ, nạp thẻ thất bại');
                continue;
            }
            if($result['status'] == 4)
            {
                echo msg_error3('Chức năng này đang bảo trì, vui lòng quay lại sau');
                continue;
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
                    'username'  => $getUser['username'],
                    'trangthai' => 'xuly',
                    'ghichu'    => '',
                    'thoigian'  => gettime(),
                    'server'    => 'card365.vn'
                ]);
                if($isInsert)
                {
                    echo msg_success3("Gửi thẻ thành công");
                    continue;
                }
                else
                {
                    echo msg_error3('Thao tác thất bại');
                    continue;
                }
            }
        }

    






        echo msg_error3("Hệ thống đang bảo trì vui lòng quay lại sau!");
        continue;
    }
 
    