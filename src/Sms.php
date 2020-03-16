<?php

namespace jinyicheng\cloopen;

use BadFunctionCallException;
use jinyicheng\redis\Redis;

class Sms
{
    private static $instance = [];
    private $options;

    /**
     * Sms constructor.
     * @param array $options
     */
    private function __construct($options = [])
    {
        $this->options = $options;
        if (!extension_loaded('redis')) throw new BadFunctionCallException('Redis扩展不支持', 500);
    }

    /**
     * @param array $options
     * @return self
     * @throws CloopenException
     */
    public static function getInstance($options = [])
    {
        if ($options === []) $options = config('cloopen');
        if ($options === false || $options === []) throw new CloopenException('配置不存在', 510001);
        if (!isset($options['server_ip'])) throw new CloopenException('配置下没有找到server_ip设置', 510002);
        if (!isset($options['server_port'])) throw new CloopenException('配置下没有找到server_port设置', 510003);
        if (!isset($options['soft_version'])) throw new CloopenException('配置下没有找到soft_version设置', 510004);
        if (!isset($options['account_sid'])) throw new CloopenException('配置下没有找到account_sid设置', 510005);
        if (!isset($options['account_token'])) throw new CloopenException('配置下没有找到account_token设置', 510006);
        if (!isset($options['app_id'])) throw new CloopenException('配置下没有找到app_id设置', 510007);
        if (!isset($options['enable_log'])) throw new CloopenException('配置下没有找到enable_log设置', 510008);
        //if (!isset($options['app_token'])) throw new InvalidArgumentException('配置下没有找到app_token设置');
        if (!isset($options['app_redis_cache_db_number'])) throw new CloopenException('配置下没有找到app_redis_cache_db_number设置', 510008);
        if (!isset($options['app_redis_cache_key_prefix'])) throw new CloopenException('配置下没有找到app_redis_cache_key_prefix设置', 510009);
        $hash = md5(json_encode($options));
        if (!isset(self::$instance[$hash])) {
            self::$instance[$hash] = new self($options);
        }
        return self::$instance[$hash];
    }

    /**
     * 生成随机验证码
     * @return int
     */
    public static function createCaptcha()
    {
        /* 生成随机数 */
        return (int)mt_rand(100000, 999999);
    }

    /**
     * 发送短信验证码
     * @param $mobile
     * @param $captcha
     * @param string $scene_id
     * @param $captcha_template_id
     * @param null $captcha_expires_minutes
     * @return bool
     * @throws CloopenException
     */
    public function sendCaptcha($mobile, $captcha, $scene_id = 'captcha', $captcha_template_id = null, $captcha_expires_minutes = null, $captcha_send_interval_seconds = null)
    {
        if (is_null($captcha_template_id)) {
            if (!isset($options['captcha_template_id'])) {
                throw new CloopenException('配置下没有找到captcha_template_id设置', 510010);
            } else {
                $captcha_template_id = $this->options['captcha_template_id'];
            }
        }
        if (is_null($captcha_expires_minutes)) {
            if (!isset($options['captcha_expires_minutes'])) {
                throw new CloopenException('配置下没有找到captcha_expires_minutes设置', 510011);
            } else {
                $captcha_expires_minutes = $this->options['captcha_expires_minutes'];
            }
        }
        $this->send([$mobile], [$captcha, $captcha_expires_minutes . '分钟'], $captcha_template_id);
        Redis::db($this->options['app_redis_cache_db_number'])->setex(
            $this->getCaptchaKey($mobile, $scene_id),
            $captcha_expires_minutes * 60,
            $captcha
        );
        return true;
    }

    /**
     * 检查验证码是否正确
     * @param $mobile
     * @param $captcha
     * @param string $scene_id
     * @return bool
     */
    public function checkCaptcha($mobile, $captcha, $scene_id = 'captcha')
    {
        $redis = Redis::db($this->options['app_redis_cache_db_number']);
        $key = $this->getCaptchaKey($mobile, $scene_id);
        if ($redis->get($key) === $captcha) {
            $redis->del($key);
            return true;
        } else {
            return false;
        }
    }

    /**
     * 获取验证码redis存储的key
     * @param int $mobile
     * @param string $scene_id
     * @return string
     */
    public function getCaptchaKey($mobile, $scene_id = 'captcha')
    {
        return $this->options['app_redis_cache_key_prefix'] . $mobile . ':' . $scene_id;
    }

    /**
     * 发送模板消息
     * @param array $mobiles
     * @param array $datas
     * @param $template_id
     * @param null $request_id
     * @param null $sub_append
     * @return array
     * @throws CloopenException
     */
    public function send(array $mobiles, array $datas, $template_id, $request_id = null, $sub_append = null)
    {
        /**
         * 生成请求体
         */
        $data = [
            'to' => join(',', $mobiles),
            'appId' => $this->options['app_id'],
            'templateId' => $template_id,
            'datas' => $datas
        ];
        if (!is_null($request_id)) $data['reqId'] = $request_id;
        if (!is_null($sub_append)) $data['subAppend'] = $sub_append;
        $data = json_encode($data);
        /**
         * 打印请求体
         */
        if ($this->options['enable_log'] === true) $this->log($data);
        /**
         * 生成批次
         */
        $batch = date('YmdHis');
        /**
         * 生成sig
         */
        $sig = strtoupper(md5($this->options['account_sid'] . $this->options['account_token'] . $batch));
        /**
         * 生成请求url
         */
        $url = 'https://' . $this->options['ServerIP'] . ':' . $this->options['server_port'] . '/' . $this->options['soft_version'] . '/Accounts/' . $this->options['account_sid'] . '/SMS/TemplateSMS?sig=' . $sig;
        /**
         * 生成授权：主帐户Id + 英文冒号 + 时间戳。
         */
        $authorization = base64_encode($this->options['account_sid'] . ":" . $this->options['batch']);
        /**
         * 生成头
         */
        $headers = [
            'Accept:application/json',
            'Content-Type:application/json;charset=utf-8',
            'Authorization:' . $authorization
        ];
        /**
         * 发送请求
         */
        return Request::post($url, $data, $headers, $timeout = 2000, $errExplain = [
            '111100' => '【容云账号】请求URL账号格式不正确',
            '111101' => '【容云账号】请求包头Authorization参数为空',
            '111102' => '【容云账号】请求包头Authorization参数Base64解码失败',
            '111103' => '【容云账号】请求包头Authorization参数解码后格式有误',
            '111104' => '【容云账号】请求包头Authorization参数解码后账户ID为空',
            '111105' => '【容云账号】请求包头Authorization参数解码后时间戳为空',
            '111106' => '【容云账号】请求包头Authorization参数解码后时间戳过期',
            '111107' => '【容云账号】请求包头Authorization参数中账户ID跟请求地址中的账户ID不一致',
            '111108' => '【容云账号】请求地址Sig参数为空',
            '111109' => '【容云账号】请求地址Sig校验失败',
            '111110' => '【容云账号】请求地址SoftVersion参数有误',
            '111111' => '【容云账号】超过规定的并发数',
            '111113' => '【容云账号】请求包头Authorization参数中时间戳格式有误，请参考yyyyMMddHHmmss ',
            '111128' => '【容云账号】主账户ID为空',
            '111129' => '【容云账号】主账户ID存在非法字符',
            '111130' => '【容云账号】主账户ID长度有误',
            '111131' => '【容云账号】主账户授权令牌为空',
            '111132' => '【容云账号】主账户授权令牌存在非法字符',
            '111133' => '【容云账号】主账户授权令牌长度有误',
            '111134' => '【容云账号】主账户名称重复',
            '111135' => '【容云账号】主账户名称为空',
            '111136' => '【容云账号】主账户名称存在非法字符',
            '111137' => '【容云账号】主账户名称长度有误',
            '111138' => '【容云账号】主账户未激活',
            '111139' => '【容云账号】主账户已暂停',
            '111140' => '【容云账号】主账户已关闭',
            '111141' => '【容云账号】主账户不存在',
            '111142' => '【容云账号】主账户余额不足',
            '111143' => '【容云账号】主账号授权令牌无效',
            '111169' => '【容云账号】应用ID为空',
            '111170' => '【容云账号】应用ID存在非法字符',
            '111171' => '【容云账号】应用ID长度有误',
            '111172' => '【容云账号】应用名称重复',
            '111173' => '【容云账号】应用名称为空',
            '111174' => '【容云账号】应用名称存在非法字符',
            '111175' => '【容云账号】应用名称长度有误',
            '111176' => '【容云账号】应用被删除',
            '111177' => '【容云账号】应用被禁用',
            '111178' => '【容云账号】应用未发布',
            '111179' => '【容云账号】应用在审核中',
            '111180' => '【容云账号】应用被暂停',
            '111181' => '【容云账号】应用不存在',
            '111182' => '【容云账号】应用不属于主账户',
            '000000' => '【容云短信】发送成功',
            '113302' => '【容云短信】您正在使用云通讯测试模板且短信接收者不是注册的测试号码 ',
            '160000' => '【容云短信】系统错误',
            '160030' => '【容云短信】请求包体为空',
            '160031' => '【容云短信】参数解析失败',
            '160032' => '【容云短信】短信模板无效',
            '160033' => '【容云短信】短信存在黑词',
            '160034' => '【容云短信】号码黑名单',
            '160035' => '【容云短信】短信下发内容为空',
            '160036' => '【容云短信】短信模板类型未知',
            '160037' => '【容云短信】短信内容长度限制',
            '160038' => '【容云短信】短信验证码发送过频繁',
            '160039' => '【容云短信】发送数量超出同模板同号天发送次数上限',
            '160040' => '【容云短信】验证码超出同模板同号码天发送上限',
            '160041' => '【容云短信】通知超出同模板同号码天发送上限',
            '160042' => '【容云短信】号码格式有误',
            '160043' => '【容云短信】应用与模板id不匹配',
            '160044' => '【容云短信】发送号码为空',
            '160045' => '【容云短信】群发号码重复',
            '160046' => '【容云短信】营销短信发送内容审核未通过',
            '160047' => '【容云短信】询状态报告包体解析失败',
            '160048' => '【容云短信】号码数超200限制',
            '160049' => '【容云短信】短信内容含敏感词',
            '160050' => '【容云短信】短信发送失败',
            '160051' => '【容云短信】营销退订号码',
            '160052' => '【容云短信】模板变量格式有误',
            '160053' => '【容云短信】IP鉴权失败',
            '160054' => '【容云短信】请求重复',
            '160055' => '【容云短信】请求reqId超长',
            '160056' => '【容云短信】同号码请求内容重复',
            '160057' => '【容云短信】短信模板ID要求为数字',
            '160058' => '【容云短信】账户无国际短信权限',
            '160059' => '【容云短信】国际短信暂不支持群发',
            '160060' => '【容云短信】国际短信账户无营销短信权限',
            '160061' => '【容云短信】暂不支持的国家码号',
            '160062' => '【容云短信】未开通此国家码号',
            '160063' => '【容云短信】短信发送失败',
            '160064' => '【容云短信】短信发送失败',
            '160065' => '【容云短信】子扩展不符合要求',
            '160066' => '【容云短信】定时发送时间不符合平台规则',
            '160067' => '【容云短信】定时发送时间格式有误',
            '160068' => '【容云短信】平台全部退订号码黑名单',
            '160069' => '【容云短信】测试模板变量非数字',
            '160070' => '【容云短信】自定义短信模板不支持国际短信',
            '160071' => '【容云短信】营销短信不在允许发送时间段',
            '160072' => '【容云短信】关键号码黑名单',
            '160073' => '【容云短信】运营商投诉号码黑名单',
            '160074' => '【容云短信】高危投诉号码黑名单',
            '111000' => '容云未知错误',
            '111001' => '容云内部错误',
            '111002' => '容云内部错误',
            '111003' => '容云内部错误',
            '111004' => '容云内部错误',
            '111005' => '容云内部错误',
            '111006' => '容云内部错误',
            '111007' => '容云内部错误',
            '111008' => '容云内部错误',
            '111009' => '容云内部错误',
            '111010' => '容云内部错误',
            '111011' => '容云内部错误',
            '111012' => '容云内部错误',
            '111013' => '容云内部错误',
            '111014' => '容云内部错误',
            '111015' => '容云内部错误',
            '111016' => '容云内部错误',
            '111017' => '容云内部错误',
            '111018' => '容云内部错误',
            '111019' => '容云内部错误',
            '111020' => '容云内部错误',
            '111021' => '容云内部错误',
            '111022' => '容云内部错误',
            '111023' => '容云内部错误',
            '111024' => '容云内部错误',
            '111025' => '容云内部错误',
            '111026' => '容云内部错误',
            '111027' => '容云内部错误',
            '111028' => '容云内部错误',
            '111029' => '容云内部错误',
            '111030' => '容云内部错误',
            '111031' => '容云内部错误',
            '111032' => '容云内部错误',
            '111033' => '容云内部错误',
            '111034' => '容云内部错误',
            '111035' => '容云内部错误',
            '111036' => '容云内部错误',
            '111037' => '容云内部错误',
            '111038' => '容云内部错误',
            '111039' => '容云内部错误',
            '111040' => '容云内部错误',
            '111041' => '容云内部错误',
            '111042' => '容云内部错误',
            '111043' => '容云内部错误',
            '111044' => '容云内部错误',
            '111045' => '容云内部错误',
            '111046' => '容云内部错误',
            '111047' => '容云内部错误',
            '111048' => '容云内部错误',
            '111049' => '容云内部错误',
            '111050' => '容云内部错误',
            '111051' => '容云内部错误',
            '111052' => '容云内部错误',
            '111053' => '容云内部错误',
            '111054' => '容云内部错误',
            '111055' => '容云内部错误',
            '111056' => '容云内部错误',
            '111057' => '容云内部错误',
            '111058' => '容云内部错误',
            '111059' => '容云内部错误',
            '111060' => '容云内部错误',
            '111061' => '容云内部错误',
            '111062' => '容云内部错误',
            '111063' => '容云内部错误',
            '111064' => '容云内部错误',
            '111065' => '容云内部错误',
            '111066' => '容云内部错误',
            '111067' => '容云内部错误',
            '111068' => '容云内部错误',
            '111069' => '容云内部错误',
            '111070' => '容云内部错误',
            '111071' => '容云内部错误',
            '111072' => '容云内部错误',
            '111073' => '容云内部错误',
            '111074' => '容云内部错误',
            '111075' => '容云内部错误',
            '111076' => '容云内部错误',
            '111077' => '容云内部错误',
            '111078' => '容云内部错误',
            '111079' => '容云内部错误',
            '111080' => '容云内部错误',
            '111081' => '容云内部错误',
            '111082' => '容云内部错误',
            '111083' => '容云内部错误',
            '111084' => '容云内部错误',
            '111085' => '容云内部错误',
            '111086' => '容云内部错误',
            '111087' => '容云内部错误',
            '111088' => '容云内部错误',
            '111089' => '容云内部错误',
            '111090' => '容云内部错误',
            '111091' => '容云内部错误',
            '111092' => '容云内部错误',
            '111093' => '容云内部错误',
            '111094' => '容云内部错误',
            '111095' => '容云内部错误',
            '111096' => '容云内部错误',
            '111097' => '容云内部错误',
            '111098' => '容云内部错误',
            '111099' => '容云内部错误'
        ]);
    }

    /**
     * 打印日志
     * @param $data
     */
    private function log($data)
    {
        switch (true) {
            case class_exists(\think\facade\Log::class):
                \think\facade\Log::info($data);
                break;
            case class_exists(\Illuminate\Support\Facades\Log::class):
                \Illuminate\Support\Facades\Log::info($data);
                break;
        }
    }
}