<?php
    require_once("../config/config.php");
    require_once("../config/function.php");

    if(getSite('token_momo') == '')
    {
        die('Vui lòng nhập token ví momo');
    }
    if(getSite('password_momo') == '')
    {
        die('Vui lòng nhập mật khẩu ví momo');
    }
    if(time() - $CMSNT->site('check_time_cron_pay_momo') < 10)
    {
        die('Không thể cron vào lúc này!');
    }
    $CMSNT->update("options", [
        'value' => time()
    ], " `name` = 'check_time_cron_pay_momo' ");


    function payment_momo($token, $sdtnguoinhan, $password, $money, $noidung)
    {
        $result = curl_get("https://api.web2m.com/TRANSFERAPIMOMO/".$token."/".$sdtnguoinhan."/".$password."/".$money."/".$noidung);
        $result = json_decode($result, true);
        return $result;
    }


    // LẤY DANH SÁCH ĐƠN RÚT TIỀN VỀ VÍ MOMO ĐANG ĐỢI XỬ LÝ
    foreach($CMSNT->get_list("SELECT * FROM `ruttien` WHERE `trangthai` = 'xuly' AND `nganhang` = 'MOMO' ") as $ruttien)
    {
        if($ruttien['trangthai'] != 'xuly')
        {
            continue;
        }
        $CMSNT->update("ruttien", [
            'trangthai'  => 'hoantat'
        ], " `id` = '".$ruttien['id']."' ");
        $noidung = $ruttien['magd'];
        $result1 = payment_momo(getSite('token_momo'), $ruttien['sotaikhoan'], getSite('password_momo'), $ruttien['sotien'], $noidung);

        if(
        isset($result1['status']) && $result1['msg'] == "Tài khoản không đủ tiền" ||
        $result1['code'] == -83 || $result1['code'] == -2 ||  $result1['code'] == 1006 || $result1['code'] == 1001 ||
        strpos($result1['msg'],'tối đa') == true || 
        strpos($result1['msg'],'lỗi trong quá trình xử lý') == true || 
        strpos($result1['msg'],'Hệ thống đang bảo trì') == true ||
        strpos($result1['msg'],'Hết thời gian truy cập') == true ||
        strpos($result1['msg'],'Xảy ra lỗi') == true
        )
        {
            // chuyển lỗi set đơn về xử lý
            $CMSNT->update("ruttien", [
                'trangthai'  => 'xuly',
                'response'   => $result1['msg']
            ], " `id` = '".$ruttien['id']."' ");
            continue;
        }
        else
        {
            // chuyển thành công !
            $CMSNT->update("ruttien", [
                'trangthai'  => 'hoantat',
                'response'   => $result1['msg']
            ], " `id` = '".$ruttien['id']."' ");
            continue;
        }
    }