<?php
namespace User\Controller;

use Common\Controller\UserController;
use Detection\MobileDetect;
use Niaoyun\UserCenter\NyData;

class PaymentController extends UserController {

    public function _initialize() {
        header("Content-type:text/html;charset=utf-8");
        parent::_initialize();
    }

    public function pay()
    {
        $total_fee = I('get.WIDtotal_fee', null);                // 付款金额
        $mobile = new MobileDetect();

        if ($mobile->isMobile()) {
            $total_fee = I('post.WIDtotal_fee', null);                // 付款金额
        }

        if (!preg_match_all('/^[1-9]\d{0,5}(\.0{1,2})?$/', $total_fee)) {
            $this->error(L('THE RECHARGE AMOUNT MUST BE AN INTEGER'));
        }

        if ($total_fee > 1000000) {
            $this->error(L('MAXIMUM RECHARGE AMOUNT', ['amount' => 1000000]));
        }

        $minRecharge = C('recharge.minRecharge') ? C('recharge.minRecharge') : 1;
        if ($total_fee < $minRecharge) {
            $this->error(L('MINIMUM RECHARGE AMOUNT', ['amount' => $minRecharge]));
        }

        $payType = I('post.payType');                       //支付方式 [alipay | weixin]
        $out_trade_no = makePaySn($this->userinfo['id']);       //商户订单号，商户网站订单系统中唯一订单号
        $subject = C('basic.site_name') . '充值款，会员ID：' . $this->userinfo['id'];  //订单名称

        // 生成充值订单
        $createOrderBool = createOrder($this->userinfo['id'], 'alipay', $out_trade_no, $total_fee);
        if (!$createOrderBool) {
            $this->error(L('RECHARGE ORDER GENERATION FAILED'));
        }

        /** 检测是否是手机网站 **/
        if ($mobile->isMobile()) {
            if (C('recharge.wapPaySwitch') != 1) {
                $this->error(L('MOBILE TERMINAL RECHARGE IS NOT OPEN'));
            }

            $this->alipay($out_trade_no, $subject, $total_fee,true);
            exit();
        }

        switch ($payType) {
            case 'alipay':
                $this->alipay($out_trade_no, $subject, $total_fee);
                break;
            default :
                $this->alipay($out_trade_no, $subject, $total_fee);
        }
    }

    /**
     * 支付宝 支付
     * @param int $out_trade_no 订单号
     * @param string $subject  订单名称
     * @param float $total_fee    是否手机
     * @param string $body     商品描述
     */
    private function alipay($out_trade_no, $subject, $total_fee,$isMobile=false) {
        $notifyDomain = C('interface.alipayNotifyDomain') ? C('interface.alipayNotifyDomain') : SITE_DOMAIN;

        $arr = explode('|', C('recharge.alipayPrivateKey'));

        if(count($arr)!=2){
            $this->error(L('RECHARGE ORDER GENERATION FAILED'));
        }


        vendor('f2fpay.AlipayService');
        $aliPay = new \AlipayService();
        $aliPay->setAppid(C('recharge.alipayPartner'));
        $aliPay->setNotifyUrl($notifyDomain. U('ApiNotify/QingNotify/Notify'));
        $aliPay->setRsaPrivateKey($arr[0]);
        $aliPay->setTotalFee($total_fee);
        $aliPay->setOutTradeNo($out_trade_no);
        $aliPay->setOrderName($subject);
        $aliPay->setBody($this->userinfo['id']);
        $result = $aliPay->doPay();
        $result = $result['alipay_trade_precreate_response'];
        if($result['code'] && $result['code']=='10000'){
            $qrcode = $result['qr_code'];
            if($isMobile){
                redirect($qrcode);
            } else {
                $payment = array(
                    'total_fee' => $total_fee,
                    'qrcodeUrl' => $qrcode,
                    'orderNo' => $out_trade_no,
                );
                $this->nydata = new NyData();
                $this->nydata->setField('payment', $payment);
                $this->assign('nydata', $this->nydata);
                $this->display('Finance/Payment/zfbQrcode');
            }
        }
    }

    /**
     * 网银 支付
     * @author King
     * @param $out_trade_no
     * @param $subject
     * @param $total_fee
     * @param $payType
     */
    private function eBank($out_trade_no, $subject, $total_fee, $payType)
    {
        vendor('Alipay.Corefunction');
        vendor('Alipay.Md5function');
        vendor('Alipay.Notify');
        vendor('Alipay.Submit');

        $ebank_config = [
            'partner'       => C('recharge.alipayPartner'),
            'key'           => C('recharge.alipayKey'),
            'sign_type'     => strtoupper('MD5'),
            'input_charset' => strtolower('utf-8'),
            'transport'     => 'https',
            'cacert'        => getcwd() . DIRECTORY_SEPARATOR . 'datas' . DIRECTORY_SEPARATOR . 'cacert.pem',
            'notifyDomain'  => C('interface.alipayNotifyDomain') ? C('interface.alipayNotifyDomain') : SITE_DOMAIN,
        ];

        //构造要请求的参数数组，无需改动
        $parameter = array(
            "service"            => "create_direct_pay_by_user",
            "payment_type"       => '1',
            "partner"            => $ebank_config['partner'],
            "seller_id"          => $ebank_config['partner'],
            "notify_url"         => $ebank_config['notifyDomain'] . U('ApiNotify/AlipayNotify/alipayNotify'),
            "return_url"         => U('ApiNotify/AlipayNotify/alipayReturn', '', true, true),
            "out_trade_no"       => $out_trade_no,
            "subject"            => $subject,
            "total_fee"          => $total_fee,
            "paymethod"          => "bankPay",
            "defaultbank"        => $payType,
            "_input_charset"     => strtolower('utf-8'),
            "extra_common_param" => $this->userinfo['id'], //如果用户请求时传递了该参数，则返回给商户时会回传该参数。
        );

        //建立请求
        $alipaySubmit = new \AlipaySubmit($ebank_config);
        $html_text = $alipaySubmit->buildRequestForm($parameter,"get", "确认");
        echo $html_text;
    }

    /**
     * 微信 支付
     */
    public function wxpay()
    {
        vendor('Wxpay.WxPayApi');
        vendor('Wxpay.WxPayData');
        vendor('Wxpay.WxPayException');
        vendor('Wxpay.WxPayNativePay');
        vendor('Wxpay.WxPayNotify');
        $wxpay_config = [
            'appid'      => C('recharge.wxpayAppID'),
            'mchid'      => C('recharge.wxpayMchid'),
            'key'        => C('recharge.wxpayKey'),
            'appsecret'  => C('recharge.wxpaySecret'),
            'notifyDomain'  => C('interface.wxpayNotifyDomain') ? C('interface.wxpayNotifyDomain') : SITE_DOMAIN,
        ];

        $total_fee = I('get.total_fee', null);                    // 付款金额
        // if (!isset($total_fee) || !is_numeric($total_fee)) {
        //     E('非法请求！');
        // }

        if (!preg_match_all('/^[1-9]\d{0,5}(\.0{1,2})?$/', $total_fee)) {
            $this->error(L('THE RECHARGE AMOUNT MUST BE AN INTEGER'));
        }

        if ($total_fee > 1000000) {
            $this->error(L('MAXIMUM RECHARGE AMOUNT', ['amount' => 1000000]));
        }

        $minRecharge = C('recharge.minRecharge') ? C('recharge.minRecharge') : 1;
        if ($total_fee < $minRecharge) {
            $this->error(L('MINIMUM RECHARGE AMOUNT', ['amount' => $minRecharge]));
        }

        $out_trade_no = makePaySn($this->userinfo['id']);       //商户订单号，商户网站订单系统中唯一订单号
        $body = C('basic.site_name') . '充值款，会员ID：' . $this->userinfo['id'];  //订单名称

        //生成充值订单
        if (!createOrder($this->userinfo['id'], 'weixin', $out_trade_no, $total_fee)) {
            $this->error(L('RECHARGE ORDER GENERATION FAILED'));
        }

        $input = new \WxPayUnifiedOrder();
        $input->SetBody($body);
        $input->SetAttach($this->userinfo['id']);
        $input->SetOut_trade_no($out_trade_no);
        $input->SetTotal_fee($total_fee * 100);
        $input->SetTime_start(date("YmdHis"));
        $input->SetTime_expire(date("YmdHis", time() + 600));
        $input->SetGoods_tag("云服务器");
        $input->SetNotify_url($wxpay_config['notifyDomain'] . U('ApiNotify/WxpayNotify/wxNotify'));
        $input->SetTrade_type("NATIVE");
        $input->SetProduct_id($wxpay_config['mchid']);
        $notify = new \NativePay();
        $result = $notify->GetPayUrl($input);
        $url = $result["code_url"];

        $payment = array(
            'total_fee' => $total_fee,
            'qrcodeUrl' => $url,
            'orderNo' => $out_trade_no,
        );

        $this->nydata = new NyData();
        $this->nydata->setField('payment', $payment);
        $this->assign('nydata', $this->nydata);
        $this->display('Finance/Payment/wxQrcode');
    }

    public function wxh5pay()
    {
        vendor('Wxpay.WxPayApi');
        vendor('Wxpay.WxPayData');
        vendor('Wxpay.WxPayException');
        vendor('Wxpay.WxPayH5Pay');
        vendor('Wxpay.WxPayNotify');
        $wxpay_config = [
            'appid'      => C('recharge.wxpayAppID'),
            'mchid'      => C('recharge.wxpayMchid'),
            'key'        => C('recharge.wxpayKey'),
            'appsecret'  => C('recharge.wxpaySecret'),
            'notifyDomain'  => C('interface.wxpayNotifyDomain') ? C('interface.wxpayNotifyDomain') : SITE_DOMAIN,
        ];

        $total_fee = I('get.total_fee', null);                    // 付款金额

        if (!preg_match_all('/^[1-9]\d{0,5}(\.0{1,2})?$/', $total_fee)) {
            $this->error(L('THE RECHARGE AMOUNT MUST BE AN INTEGER'));
        }

        if ($total_fee > 1000000) {
            $this->error(L('MAXIMUM RECHARGE AMOUNT', ['amount' => 1000000]));
        }

        $minRecharge = C('recharge.minRecharge') ? C('recharge.minRecharge') : 1;
        if ($total_fee < $minRecharge) {
            $this->error(L('MINIMUM RECHARGE AMOUNT', ['amount' => $minRecharge]));
        }

        $out_trade_no = makePaySn($this->userinfo['id']);       //商户订单号，商户网站订单系统中唯一订单号
        $body = C('basic.site_name') . '充值款，会员ID：' . $this->userinfo['id'];  //订单名称

        //生成充值订单
        if (!createOrder($this->userinfo['id'], 'weixin', $out_trade_no, $total_fee)) {
            $this->error(L('RECHARGE ORDER GENERATION FAILED'));
        }

        $input = new \WxPayUnifiedOrder();
        $input->SetBody($body);
        $input->SetAttach($this->userinfo['id']);
        $input->SetOut_trade_no($out_trade_no);
        $input->SetTotal_fee($total_fee * 100);
        $input->SetTime_start(date("YmdHis"));
        $input->SetTime_expire(date("YmdHis", time() + 600));
        $input->SetGoods_tag("云服务器");
        $input->SetNotify_url($wxpay_config['notifyDomain'] . U('ApiNotify/WxpayNotify/wxNotify'));
        $input->SetTrade_type("MWEB");
        $input->SetSpbill_create_ip(get_client_ip());
        $input->SetScene_info('{"h5_info": {"type":"Wap","wap_url": "' . SITE_DOMAIN . '","wap_name": "' . C('basic.site_name') . '"}}');
        $notify = new \H5Pay();
        $result = $notify->GetPayUrl($input);

        if ($result['return_code'] == 'FAIL') {
            $this->error(L('SYSTEM BUSY'));
        }

        $redirect_url = cookie('paymentReturnUrl');
        if ($redirect_url) {
            cookie('paymentReturnUrl', null);
        } else {
            $redirect_url = SITE_DOMAIN . '/user/payment/record.html';
        }

        $url = $result["mweb_url"] . '&redirect_url=' . urlencode($redirect_url);

        redirect($url);
    }

    /**
     * 检查微信是否支付完成
     * @param $orderNo
     */
    public function ajaxCheck($orderNo) {

        if (!IS_AJAX) {
            E(L('ILLEGAL TO OPERATE'));
        }

        if (checkOrderStatus($orderNo)) {
            $this->ajaxReturn(array('result' => true, 'status' => 1)); //已支付
        }
        $this->ajaxReturn(array('result' => true, 'status' => 0)); //等待支付
    }

    /**
     * 支付宝手机支付
     * @auth King
     * @param $out_trade_no
     * @param $subject
     * @param $total_fee
     */
    private function wap($out_trade_no, $subject, $total_fee)
    {
        $sign_type = C('recharge.signType') ? : 'MD5';

        vendor('Aliwap.Corefunction');

        if ($sign_type == 'RSA') {
            vendor('Aliwap.Rsafunction');
        } else {
            vendor('Aliwap.Md5function');
        }

        vendor('Aliwap.Notify');
        vendor('Aliwap.Submit');

        $notifyDomain  = C('interface.alipayNotifyDomain') ? C('interface.alipayNotifyDomain') : SITE_DOMAIN;

        $config = [
            'partner'       => C('recharge.alipayPartner'),
            'seller_id'     => C('recharge.alipayPartner'),
            'key'               => $sign_type == 'MD5' ? C('recharge.alipayKey') : null,
            'private_key'       => $sign_type == 'RSA' ? C('recharge.alipayPrivateKey') : null,
            'sign_type'         => strtoupper($sign_type),
            'return_url'    => U('ApiNotify/AliwapNotify/alipayReturn', '', true, true),
            'notify_url'    => $notifyDomain . U('ApiNotify/AliwapNotify/alipayNotify'),
            'input_charset' => 'utf-8',
            'transport'     => 'https',
            'cacert'        => getcwd() . DIRECTORY_SEPARATOR . 'datas' . DIRECTORY_SEPARATOR . 'cacert.pem',
            'service'       => 'alipay.wap.create.direct.pay.by.user',
        ];

        $parameter = [
            "service"            => 'alipay.wap.create.direct.pay.by.user',
            "_input_charset"     => $config['input_charset'],
            "payment_type"       => '1',
            "partner"            => $config['partner'],
            "seller_id"          => $config['seller_id'],
            "notify_url"         => $config['notify_url'],
            "return_url"         => $config['return_url'],
            "out_trade_no"       => $out_trade_no,
            "subject"            => $subject,
            "total_fee"          => $total_fee,
            "app_pay"            => "Y",
            "show_url"           => U('/user/Finance/payment', '', true, true),    //收银台页面上，商品展示的超链接，必填
            "extra_common_param" => $this->userinfo['id'],
        ];

        //建立请求
        $alipaySubmit = new \AlipaySubmit($config);
        $html_text = $alipaySubmit->buildRequestForm($parameter,"get", "确认");
        echo $html_text;
    }
}
