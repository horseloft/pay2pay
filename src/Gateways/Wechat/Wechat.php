<?php

namespace Yansongda\Pay\Gateways\Wechat;

use App\Http\Helper\Log;
use Yansongda\Pay\Contracts\GatewayInterface;
use Yansongda\Pay\Exceptions\GatewayException;
use Yansongda\Pay\Exceptions\InvalidArgumentException;
use Yansongda\Pay\Support\Config;
use Yansongda\Pay\Traits\HasHttpRequest;

abstract class Wechat implements GatewayInterface
{
    use HasHttpRequest;

    /**
     * @var string
     */
    protected $endpoint = 'https://api.mch.weixin.qq.com/';
    protected $endpoint2 = 'https://api2.mch.weixin.qq.com/';
    protected $timeout = 5;

    /**
     * @var string
     */
    protected $gateway_order = 'pay/unifiedorder';

    /**
     * @var string
     */
    protected $gateway_query = 'pay/orderquery';

    /**
     * @var string
     */
    protected $gateway_close = 'pay/closeorder';

    /**
     * @var string
     */
    protected $gateway_refund = 'secapi/pay/refund';

    /**
     * @var array
     */
    protected $config;

    /**
     * @var \Yansongda\Pay\Support\Config
     */
    protected $user_config;

    /**
     * [__construct description].
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->user_config = new Config($config);
        $this->config = [
            'appid'      => $this->user_config->get('app_id', ''),
            'mch_id'     => $this->user_config->get('mch_id', ''),
            'nonce_str'  => $this->createNonceStr(),
            'sign_type'  => 'MD5',
            'notify_url' => $this->user_config->get('notify_url', ''),
            'trade_type' => $this->getTradeType(),
        ];

        if ($endpoint = $this->user_config->get('endpoint_url')) {
            $this->endpoint = $endpoint;
        }
        if ($timeout = $this->user_config->get('timeout')) {
            $this->timeout = $timeout;
        }
    }

    /**
     * pay a order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array $config_biz
     *
     * @return mixed
     */
    abstract public function pay(array $config_biz = []);

    /**
     * refund.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @return string|bool
     */
    public function refund($config_biz = [])
    {
        if (isset($config_biz['miniapp'])) {
            $this->config['appid'] = $this->user_config->get('miniapp_id');
            unset($config_biz['miniapp']);
        }

        $this->config = array_merge($this->config, $config_biz);
        unset($this->config['trade_type']);
        // $this->unsetTradeTypeAndNotifyUrl();

        return $this->getResult($this->gateway_refund, true);
    }

    /**
     * close a order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @return array|bool
     */
    public function close($out_trade_no = '')
    {
        $this->config['out_trade_no'] = $out_trade_no;

        $this->unsetTradeTypeAndNotifyUrl();

        return $this->getResult($this->gateway_close);
    }

    /**
     * find a order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $out_trade_no
     *
     * @return array|bool
     */
    public function find($out_trade_no = '')
    {
        $this->config['out_trade_no'] = $out_trade_no;

        $this->unsetTradeTypeAndNotifyUrl();

        return $this->getResult($this->gateway_query);
    }

    /**
     * verify the notify.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $data
     * @param string $sign
     * @param bool   $sync
     *
     * @return array|bool
     */
    public function verify($data, $sign = null, $sync = false)
    {
        $data = $this->fromXml($data);

        $sign = is_null($sign) ? $data['sign'] : $sign;

        return $this->getSign($data) === $sign ? $data : false;
    }

    /**
     * get trade type config.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @return string
     */
    abstract protected function getTradeType();

    /**
     * pre order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array $config_biz
     *
     * @return array
     */
    protected function preOrder($config_biz = [])
    {
        $this->config = array_merge($this->config, $config_biz);
        return $this->getResult($this->gateway_order);
    }
    // 发起微信证书请求
    public function  ssl_post($url, $postData, $cert)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        // 要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        // 设置证书
        // 使用证书：cert 与 key 分别属于两个.pem文件
        curl_setopt($ch,CURLOPT_SSLCERTTYPE,'PEM');
        curl_setopt($ch,CURLOPT_SSLCERT, $cert['cert']);
        curl_setopt($ch,CURLOPT_SSLKEYTYPE,'PEM');
        curl_setopt($ch,CURLOPT_SSLKEY, $cert['ssl_key']);

        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        $ret = curl_exec($ch);
        if (false === $ret) {
            return $this->toXml(['return_code' => 'FAIL', 'return_msg' => '请求失败']);
        } else {
            return $ret;
        }
    }

    /**
     * get api result.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $path
     * @param bool   $cert
     *
     * @return array
     */
    protected function getResult($path, $cert = false)
    {
        $this->config['sign'] = $this->getSign($this->config);

        if ($cert) {
            $data = $this->fromXml($this->ssl_post(
                $this->endpoint.$path,
                $this->toXml($this->config),
                [
                    'cert'    => $this->user_config->get('cert_client', ''),
                    'ssl_key' => $this->user_config->get('cert_key', ''),
                ]
            ));
        } else {
            $httpCode = $this->getHttpCurl(1, $this->endpoint.$path, 1);
            if($httpCode && $httpCode['httpCode'] != 200){//如果请求失败，调用备用地址
                $data = $this->fromXml($this->post($this->endpoint2.$path, $this->toXml($this->config)));
                Log::debug('order/wechat', '请求'.$this->endpoint2.$path);
            }else{
                Log::debug('order/wechat', '请求'.$this->endpoint.$path);
                $data = $this->fromXml($this->post($this->endpoint.$path, $this->toXml($this->config)));
            }
        }

        if (!isset($data['return_code']) || $data['return_code'] !== 'SUCCESS' || $data['result_code'] !== 'SUCCESS') {
            $error = 'getResult error:'.$data['return_msg'];
            $error .= isset($data['err_code_des']) ? ' - '.$data['err_code_des'] : '';
        }

        if (!isset($error) && $this->getSign($data) !== $data['sign']) {
            $error = 'getResult error: return data sign error';
        }

        if (isset($error)) {
            Log::error('order/wechat', '异常信息: ' . $error );
            throw new GatewayException(
                $error,
                20000,
                $data);
        }

        return $data;
    }

    /**
     * sign.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array $data
     *
     * @return string
     */
    protected function getSign($data)
    {
        if (is_null($this->user_config->get('key'))) {
            throw new InvalidArgumentException('Missing Config -- [key]');
        }

        ksort($data);

        $string = md5($this->getSignContent($data).'&key='.$this->user_config->get('key'));

        return strtoupper($string);
    }

    /**
     * get sign content.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array $data
     *
     * @return string
     */
    protected function getSignContent($data)
    {
        $buff = '';

        foreach ($data as $k => $v) {
            $buff .= ($k != 'sign' && $v != '' && !is_array($v)) ? $k.'='.$v.'&' : '';
        }

        return trim($buff, '&');
    }

    /**
     * create random string.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param int $length
     *
     * @return string
     */
    protected function createNonceStr($length = 16)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }

        return $str;
    }

    /**
     * convert to xml.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array $data
     *
     * @return string
     */
    protected function toXml($data)
    {
        if (!is_array($data) || count($data) <= 0) {
            throw new InvalidArgumentException('convert to xml error!invalid array!');
        }

        $xml = '<xml>';
        foreach ($data as $key => $val) {
            $xml .= is_numeric($val) ? '<'.$key.'>'.$val.'</'.$key.'>' :
                '<'.$key.'><![CDATA['.$val.']]></'.$key.'>';
        }
        $xml .= '</xml>';

        return $xml;
    }

    /**
     * convert to array.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $xml
     *
     * @return array
     */
    protected function fromXml($xml)
    {
        if (!$xml) {
            throw new InvalidArgumentException('convert to array error !invalid xml');
        }

        libxml_disable_entity_loader(true);

        return json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA), JSON_UNESCAPED_UNICODE), true);
    }

    /**
     * delete trade_type and notify_url.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @return bool
     */
    protected function unsetTradeTypeAndNotifyUrl()
    {
        unset($this->config['notify_url']);
        unset($this->config['trade_type']);

        return true;
    }

    protected function getHttpCurl($type, $url, $timeOut, $postData = "", $header = "")
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);                    //设置访问的url地址
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);             //跟踪301
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);            //返回结果当作变量保存
        if ($type == 2) {
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeOut);     //设置超时时间，以毫秒为单位，CURL 7.16.2中被加入。从PHP 5.2.3起可使用
        } else {
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeOut);        //设置超时时间，以秒为单位
        }
        if ($header && is_array($header)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);      //设置CURL的header头
        }
        if ($postData) {
            curl_setopt($ch, CURLOPT_POST, 1);                  //设置为方式post发送
            if (is_array($postData)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));    //post的数据，使用http_build_query之后，可以提升速度。
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);    //post的变量，非数组类型的Post值
            }
        }
        if (preg_match("/^https/", $url)) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);    //SSL不验证证书
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);    //SSL不验证HOST
        }
        $httpData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);   //获取访问地址的状态码
        curl_close($ch);
        if ($httpCode == 200) {
            $returnData = array("httpCode" => $httpCode, "httpData" => $httpData);
        } else {
            $returnData = array("httpCode" => $httpCode, "httpData" => "");
        }
        return $returnData;
    }
}
