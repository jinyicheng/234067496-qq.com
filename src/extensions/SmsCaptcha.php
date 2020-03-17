<?php

namespace jinyicheng\cloopen\extensions;

use BadFunctionCallException;
use jinyicheng\cloopen\exceptions\CloopenException;
use jinyicheng\cloopen\exceptions\SmsCaptchaException;
use jinyicheng\cloopen\exceptions\SmsCaptchaValidateException;
use jinyicheng\redis\Redis;
use jinyicheng\cloopen\Sms;
use jinyicheng\toolbox\Time;


class SmsCaptcha
{
    /**
     * @var array 配置
     */
    private $options;

    /**
     * @var string|int 场景ID
     */
    private $scene_id = null;

    /**
     * @var string|int 模板ID
     */
    private $template_id = null;

    /**
     * @var string|int 验证码
     */
    private $captcha = null;

    /**
     * @var int 有效时长（分钟）
     */
    private $expires = 30;

    /**
     * @var int 间隔时常（秒）
     */
    private $interval = 60;

    /**
     * @var int 单一手机号每日发送上限
     */
    private $single_mobile_daily_send_maximum = 10;

    /**
     * @var string|int 手机号码
     */
    private $mobile = null;

    /**
     * 设置场景ID
     * @param string|int $scene_id
     * @return $this
     */
    public function setSceneId($scene_id)
    {
        $this->scene_id = $scene_id;
        return $this;
    }

    /**
     * 设置模板ID
     * @param string|int $template_id
     * @return $this
     */
    public function setTemplateId($template_id)
    {
        $this->template_id = $template_id;
        return $this;
    }

    /**
     * 设置验证码
     * @param string|int $captcha
     * @return $this
     */
    public function setCaptcha($captcha)
    {
        $this->captcha = $captcha;
        return $this;
    }

    /**
     * 生成随机验证码
     * @param int $length 验证码长度
     * @return $this
     */
    public function createCaptcha(int $length = 6)
    {
        for ($captcha = '', $i = 0; $i < $length; $i++) {
            $captcha .= mt_rand(0, 9);
        }
        return $this->setCaptcha($captcha);
    }

    /**
     * 设置有效时长（分钟）
     * @param int $minutes
     * @return $this
     */
    public function setExpires(int $minutes)
    {
        $this->expires = $minutes;
        return $this;
    }

    /**
     * 设置间隔时长（秒）
     * @param int $seconds
     * @return $this
     */
    public function setInterval(int $seconds)
    {
        $this->interval = $seconds;
        return $this;
    }

    /**
     * 设置手机号码
     * @param string|int $mobile
     * @return $this
     */
    public function setMobile($mobile)
    {
        $this->mobile = $mobile;
        return $this;
    }

    /**
     * 单一手机号每日发送上限
     * @param int $single_mobile_daily_send_maximum
     * @return $this
     */
    public function setSingleMobileDailySendMaximum($single_mobile_daily_send_maximum)
    {
        $this->single_mobile_daily_send_maximum = $single_mobile_daily_send_maximum;
        return $this;
    }

    /**
     * SmsCaptcha constructor.
     * @param array $options
     * @throws CloopenException
     */
    public function __construct($options = [])
    {
        if ($options === []) $options = config('cloopen');
        if ($options === false || $options === []) throw new CloopenException('配置不存在', 510001);
        if (!isset($options['app_redis_cache_db_number'])) throw new CloopenException('配置下没有找到app_redis_cache_db_number设置', 510008);
        if (!isset($options['app_redis_cache_key_prefix'])) throw new CloopenException('配置下没有找到app_redis_cache_key_prefix设置', 510009);
        if (!extension_loaded('redis')) throw new BadFunctionCallException('Redis扩展不支持', 500);
        $this->options = $options;
    }

    /**
     * 发送短信验证码
     * @return void
     * @throws CloopenException
     * @throws SmsCaptchaException
     * @throws SmsCaptchaValidateException
     */
    public function send()
    {
        /**
         * 检测参数是否可用
         */
        if (is_null($this->scene_id)) {
            throw new SmsCaptchaException('请设置场景ID', 510012);
        }
        if (is_null($this->template_id)) {
            throw new SmsCaptchaException('请设置模板ID', 510013);
        }
        if (is_null($this->captcha)) {
            throw new SmsCaptchaException('请设置验证码', 510014);
        }
        if (is_null($this->mobile)) {
            throw new SmsCaptchaException('请设置接收手机号码', 510015);
        }
        /**
         * 根据手机号码的参数进行形态转换
         */
        $key = $this->getKey();
        $Redis = Redis::db($this->options['app_redis_cache_db_number']);
        /**
         * 验证间隔时长
         */
        if ($this->interval > 0) {
            $ttl = $Redis->ttl($key . ':interval_lock');
            if ($ttl !== -2) {
                throw new SmsCaptchaValidateException('短信验证码获取过于频繁，请' . $ttl . '秒后再试', 410016);
            }
        }
        /**
         * 单一手机号每日发送上限
         */
        if ($this->single_mobile_daily_send_maximum > 0) {
            $mobile_daily_send_count_key = $key . ':' . date('Ymd') . ':send';
            $mobile_daily_send_count = $Redis->incr($mobile_daily_send_count_key);
            if ($mobile_daily_send_count == 1) {
                $Redis->expire($mobile_daily_send_count_key,Time::nowTillTomorrowMorningLeftSenconds());
            }
            if ($mobile_daily_send_count > $this->single_mobile_daily_send_maximum) {
                throw new SmsCaptchaValidateException('短信验证码获取次数已到达上限', 410017);
            }
        }
        /**
         * 发送短信（发送失败则自动异常抛出并终止）
         */
        Sms::getInstance($this->options)->send([$this->mobile], [$this->captcha, $this->expires . '分钟'], $this->template_id);
        /**
         * 设置间隔时长
         */
        if ($this->interval > 0) $Redis->setex($key . ':interval_lock', $this->interval, $this->captcha);
        /**
         * 缓存验证码（用于效验）
         */
        $Redis->setex(
            $key,
            $this->expires * 60,
            $this->captcha
        );
    }

    /**
     * 检查验证码是否正确
     * @throws SmsCaptchaException
     * @throws SmsCaptchaValidateException
     */
    public function check()
    {
        /**
         * 检测参数是否可用
         */
        if (is_null($this->scene_id)) {
            throw new SmsCaptchaException('请设置场景ID', 510012);
        }
        if (is_null($this->captcha)) {
            throw new SmsCaptchaException('请设置验证码', 510014);
        }
        if (is_null($this->mobile)) {
            throw new SmsCaptchaException('请设置接收手机号码', 510015);
        }
        /**
         * 通过redis对验证码进行校对
         */
        $redis = Redis::db($this->options['app_redis_cache_db_number']);
        $key = $this->getKey($this->mobile, $this->scene_id);
        if ($redis->get($key) === $this->captcha) {
            $redis->del($key);
        } else {
            throw new SmsCaptchaValidateException('短信验证码错误', 410018);
        }
    }

    /**
     * 获取验证码redis存储的key
     * @param int $mobile
     * @param string $scene_id
     * @return string
     */
    private function getKey()
    {
        return $this->options['app_redis_cache_key_prefix'] . $this->mobile . ':' . $this->scene_id . ':captcha';
    }
}