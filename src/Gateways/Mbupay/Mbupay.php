<?php

namespace Yansongda\Pay\Gateways\Mbupay;

use Yansongda\Pay\Contracts\GatewayInterface;
use Yansongda\Pay\Exceptions\GatewayException;
use Yansongda\Pay\Exceptions\InvalidArgumentException;
use Yansongda\Pay\Support\Config;
use Yansongda\Pay\Traits\HasHttpRequest;
use App\Http\Helper\Log;

abstract class Mbupay implements GatewayInterface
{
    use HasHttpRequest;

    /**
     * @var string
     */
    protected $endpoint = 'https://api.mbupay.com/pay/gateway';

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

        //处理php7.0+ mt_rand第二个值超出mt_getrandmax()的情况
        $time = time();
        $max = $time + rand();
        if ($max > mt_getrandmax()) {
            $max = mt_getrandmax();
        }

        $this->config = [
            'mch_id'     => $this->user_config->get('mch_id', ''),
            'appid'      => $this->user_config->get('app_id', ''),
            'version' => $this->user_config->get('version', ''),
            'nonce_str'  => mt_rand($time, $max)
        ];

        if ($endpoint = $this->user_config->get('endpoint_url')) {
            $this->endpoint = $endpoint;
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

        return $this->getResult(true);
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

        return $this->getResult();
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

        return $this->getResult();
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

        return $this->getResult();
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
     * @param bool   $cert
     *
     * @return array
     */
    protected function getResult($cert = false)
    {
        $this->config['sign'] = $this->getSign($this->config);

        if ($cert) {
            $data = $this->fromXml($this->ssl_post(
                $this->endpoint,
                $this->toXml($this->config),
                [
                    'cert'    => $this->user_config->get('cert_client', ''),
                    'ssl_key' => $this->user_config->get('cert_key', ''),
                ]
            ));
            // $data = $this->fromXml($this->post(
            // $this->endpoint.$path,
            // $this->toXml($this->config),
            // [
            // 'cert'    => $this->user_config->get('cert_client', ''),
            // 'ssl_key' => $this->user_config->get('cert_key', ''),
            // ]
            // ));
        } else {
            $data = $this->fromXml($this->post($this->endpoint, $this->toXml($this->config)));
        }

        if (empty($data)){
            return [];
        }

        if (!isset($data['return_code']) || $data['return_code'] !== 'SUCCESS' || $data['result_code'] !== 'SUCCESS') {
            $error = 'getResult error:'.$data['return_msg'];
            $error .= isset($data['err_code_des']) ? ' - '.$data['err_code_des'] : '';
        }

        if (!isset($error) && $this->getSign($data) !== $data['sign']) {
            $error = 'getResult error: return data sign error';
        }

        if (isset($error)) {
            Log::error('order/mbupay', '异常信息: ' . $error );
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


    public function post($url, $data = array(), $headers = array())
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
        $content = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if($code === 0){
            throw new \Exception(curl_error($ch));
        }

        curl_close($ch);

        if ($code == 200) {
            return $content;
        } else {
            $logStr .= "postData:".$data.PHP_EOL;
            $logStr .= "httpCode:".$code.str_repeat(PHP_EOL,2);
            Log::error('order/mbupay', '异常信息: ' . $logStr );
            return '';
        }
    }
}
