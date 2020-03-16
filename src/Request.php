<?php

namespace jinyicheng\cloopen;

class Request
{
    /**
     * @param $url
     * @param $data
     * @param array $headers
     * @param int $timeout
     * @param array $errExplain
     * @return array
     * @throws CloopenException
     */
    public static function post($url, $data, $headers = [], $timeout = 2000, $errExplain = [])
    {
        return self::curl(true, $url, $data, $headers, $timeout, $errExplain);
    }

    /**
     * @param $url
     * @param $data
     * @param array $headers
     * @param int $timeout
     * @param array $errExplain
     * @return array
     * @throws CloopenException
     */
    public static function get($url, $data, $headers = [], $timeout = 2000, $errExplain = [])
    {
        return self::curl(false, $url, $data, $headers, $timeout, $errExplain);
    }

    /**
     * @param $isPost
     * @param $url
     * @param $data
     * @param array $headers
     * @param int $timeout
     * @param array $errExplain
     * @return array
     * @throws CloopenException
     */
    public static function curl($isPost, $url, $data, $headers = [], $timeout = 2000, $errExplain = [])
    {
        $ch = curl_init();
        if ($isPost) {
            curl_setopt($ch, CURLOPT_URL, $url);//抓取指定网页
            curl_setopt($ch, CURLOPT_POST, 1);//post提交方式
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($data));//抓取指定网页
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);    //注意，毫秒超时一定要设置这个
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout); //超时时间200毫秒
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);//运行curl
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return self::formatResponse($httpCode, $response, $errExplain);
    }

    /**
     * @param $httpCode
     * @param $response
     * @param $errExplain
     * @return mixed
     * @throws CloopenException
     */
    private static function formatResponse($httpCode, $response, $errExplain)
    {
        if ($httpCode != 200) {
            throw new CloopenException('请求出错，无法正常响应！', $httpCode);
        } else {
            $return_data = json_decode($response, true);
            $requestResult = (is_array($return_data)) ? $return_data : $response;
            if (isset($requestResult['statusCode'])) {
                if ($requestResult['statusCode'] == '000000') {
                    return $requestResult;
                } else {
                    throw new CloopenException((isset($errExplain[$requestResult['statusCode']])) ? $errExplain[$requestResult['statusCode']] : '', $requestResult['statusCode']);
                }
            } else {
                return $requestResult;
            }
        }
    }
}