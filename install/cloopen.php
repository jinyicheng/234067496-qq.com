<?php

return [
    // +----------------------------------------------------------------------
    // | 容云短信对接配置信息
    // +----------------------------------------------------------------------
    'captcha_template_id' => 1,//填写短信验证码模板id
    'captcha_expires_minutes' => 3,//填写短信验证码失效分钟数，如3分钟过期就填3
    'server_ip' => 'app.cloopen.com',
    'server_port' => '8883',
    'soft_version' => '2013-12-26',
    'account_sid' => 'aaf98f894esdfadfsdff26f93850f4f',
    'account_token' => '1ba86bb431dsfdfsdffw0aa13aabbabc',
    'app_id' => '8aaf07086f695e7075dd016f9972db',
    'app_redis_cache_db_number' => 1,//缓存到redis的DB编号
    'app_redis_cache_key_prefix' => 'cloopen:',//缓存到redis时所有key的前缀
    'enable_log' => true//日志
];