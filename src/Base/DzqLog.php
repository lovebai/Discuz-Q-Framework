<?php

namespace Discuz\Base;

use Illuminate\Http\Request;

class DzqLog
{
    const APP_DZQLOG = 'APP_DZQLOG';//容器全局变量
    const LOG_WECHAT    = 'wechatLog';
    const LOG_PAY       = 'payLog';
    const LOG_QCLOUND   = 'qcloudLog';
    const LOG_WECHAT_OFFIACCOUNT    = 'wechatOffiaccount';
    const LOG_PERFORMANCE           = 'performancelog';
    const LOG_LOGIN     = 'loginLog';
    const LOG_ADMIN     = 'adminLog';
    const LOG_API       = 'apiLog';
    const LOG_ERROR     = 'errorLog';
    const LOG_INFO      = 'log';

    private static function getAppLog()
    {
        $dzqLog = null;
        $hasLOG = app()->has(DzqLog::APP_DZQLOG);
        if ($hasLOG) {
            $dzqLog = app()->get(DzqLog::APP_DZQLOG);
        }
        return $dzqLog;
    }

    private static function baseData(){
        $appLog         = self::getAppLog();
        $request        = Request::capture();
        $requestURL     = $request->getUri();
        $requestMethod  = $request->getMethod();
        $remoteAddress  = !empty($request->getClientIps()[0]) ? $request->getClientIps()[0] : '0.0.0.0';
        $requestId      = $appLog['requestId'] ?: '0';
        $userId         = $appLog['userId'] ?: 0;
        $requestPayload = $request->post();

        return [
            'IO'             => '',
            'requestURL'     => $requestURL,
            'requestMethod'  => $requestMethod,
            'remoteAddress'  => $remoteAddress,
            'requestId'      => $requestId,
            'userId'         => $userId,
            'requestPayload' => $requestPayload,
        ];
    }

    //接口入参日志
    public static function inPut($logType = DzqLog::LOG_INFO){
        $baseData           = self::baseData();
        $baseData['IO']     = 'input';
        app($logType)->info(json_encode($baseData, 320));
    }

    //接口出参日志
    public static function outPut($data = [], $logType = DzqLog::LOG_INFO){
        $baseData                   = self::baseData();
        $baseData['IO']             = 'output';
        $baseData['outPutData']     = $data;
        app($logType)->info(json_encode($baseData, 320));
    }

    //异常日志
    public static function error($tag = 'tag', $data = [], $errorMessage = '', $logType = DzqLog::LOG_ERROR){
        $baseData = self::baseData();
        $baseData['IO']             = 'errorOutput';
        $baseData['messageData']    = $data;
        $baseData['errorMessage']   = $errorMessage;
        app($logType)->info($tag.'::'.json_encode($baseData, 320));
    }

    //普通日志
    public static function info($tag = 'tag', $data = [], $logType = DzqLog::LOG_INFO){
        $baseData = self::baseData();
        $baseData['IO']             = 'processOutput';
        $baseData['processData']    = $data;
        app($logType)->info($tag.'::'.json_encode($baseData, 320));
    }
}
