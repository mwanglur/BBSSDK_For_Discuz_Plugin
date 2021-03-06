<?php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
require_once 'markdown.php';
require_once 'bbsjson.php';

$GLOBALS['BBSSDK_ERROR'] = array(
    109 => '请求非法',
    110 => '后台未配置',
    111 => '验证错误',
    30101 => '用户安全问题回答错误',
    30102 => '用户未激活',
    30103 => '用户不存在，或者被删除',
    30104 => '登录账号失败',
    30105 => '登录账号的密码错误',
    30106 => '密码错误次数过多，请稍后重新登录',
    403 => '参数错误',
    404 => '路由失败',
    500 => '服务器错误',
    501 => '推送失败'
);

function removeTags($cotnent)
{
    $content = preg_replace("%[[\w\.]*]%is", '', $cotnent);
    $content = preg_replace("%<[^>]*>%is", '', $content);
    return $content;
}

function return_status($code,$params=null)
{
    global $_G;$_ERROR = $GLOBALS['BBSSDK_ERROR'];
    $data = array();
    $code = intval($code);
    $data['code'] = $code;
    $data['message'] = isset($_ERROR[$code]) ? $_ERROR[$code] : '未知错误';
    if(!empty($params)){
        if(is_array($params)){
            $data = array_merge($data,$params);
        }else{
            $data['message'] = $params;
        }
    }
    write_log (
        'ERROR Method=>' . $_SERVER['REQUEST_METHOD']
        . "\t REQUEST=>".json_encode($_REQUEST)
        . "\t Response=>".json_encode($data)
    );
    header("Content-type:application/json;charset=utf8");
    echo json_encode($data);
    exit;
}

function message_filter($text)
{
    global $_G; 
    $result = $text;

    $result = preg_replace_callback("%(<img[^>]*src=['\"])([^'\"]*)(['\"][^>]*>)%is", 
        function($matches) use ($_G) {
            if(empty($matches[2]) || preg_match("%[\[\]]%is", $matches[2])){
                return '';
            }
            else if(!preg_match("%^http%is", $matches[2])){
                return $matches[1] . $_G['setting']['siteurl'] . $matches[2] . $matches[3];
            }
            return $matches[0];
        }, $result);

    $result = preg_replace(array(
            "%style=['\"][^\"]*['\"]%is",
            "%(onclick|onmouseover|onload|width)=['\"][^\"]*['\"]%is",
        ), '', $result);

    return $result;
}


function push_http_query($url, $data, $type='push' , $limit=0, $timeout=30)
{
    try{    
        global $_G;
        loadcache('plugin');
        
        if(empty($_G['cache']['plugin']['bbssdk']['appkey']))
            throw new Exception("Error Appkey is Empty", 1);

        $pushArray = array(
            'appkey' => $_G['cache']['plugin']['bbssdk']['appkey'],
            'data' => $data
        );

        if(function_exists('curl_init') && function_exists('curl_setopt'))
        {
            $return = '';

            $ch = curl_init();  
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HEADER, 1);    
            curl_setopt($ch, CURLOPT_POST, 1); 
            if($type == 'push')
            {
                $matches = parse_url($url);
                $scheme = $matches['scheme'];
                $host = $matches['host'];
                $path = $matches['path'] ? $matches['path'].($matches['query'] ? '?'.$matches['query'] : '') : '/';
                $port = !empty($matches['port']) ? $matches['port'] : ($scheme == 'http' ? '80' : ''); 
                curl_setopt($ch, CURLOPT_URL, $scheme.'://'.($ip ? $ip : $host).($port ? ':'.$port : '').$path);    
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($pushArray));
            }else{
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

            $data = curl_exec($ch);
            $status = curl_getinfo($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            write_log('http query:url=>'.$url.' data=>'.json_encode($pushArray).' Response=>'.$data,'debug');
            
            if($errno || $status['http_code'] != 200) {
                return;
            } else {
                $data = substr($data, $status['header_size']);
                return !$limit ? $data : substr($data, 0, $limit);
            }
        }else{
            throw new Exception("Error Curl Module Not Found", 1);
        }
    }catch(Exception $e){
        write_log($e);
        return_status(501);
    }
}

function check_url($url)
{
    global $_G;
    return preg_match('%^http%is', $url) ? $url : 
    ( 
        preg_match('%^http%is',$_G['setting']['attachurl']) ? 
            $_G['setting']['attachurl'].'forum/'.$url : 
            (
                (!empty($_G['setting']['siteurl']) ? 
                    trim($_G['setting']['siteurl'],'/') : 'http://'.$_SERVER['HTTP_HOST']
                ). '/' . $_G['setting']['attachurl'] . 'forum/'.$url
            )
    );
}

function write_log($message,$type='error') 
{
    $logTypes = BBSSDK_DEBUG ? array('error','warning','debug') : array('error');
    if( in_array($type,$logTypes) )
    {
        $message = discuz_error::clear($message);
        $time = time();
        $file =  DISCUZ_ROOT.'./data/log/'.date("Ym").'_bbssdk_log';
        $hash = md5($message);

        $ip = getglobal('clientip');

        $user = 'User => IP='.$ip.'; RIP:'.$_SERVER['REMOTE_ADDR'];
        $uri = 'Request: '.dhtmlspecialchars(discuz_error::clear($_SERVER['REQUEST_URI']));
        $message = "\t{$time}\t$message\t$hash\t$user $uri\n";
        
        $log = new Logger('bbssdk');
        $log->pushHandler(new StreamHandler($file, Logger::DEBUG));
        $log->{$type}($message);
    }
}

function flashdata_encode($r)
{
    $s = '';
    $l = strlen($r);
    $arr = array(0,1,2,3,4,5,6,7,8,9,'A','B','C','D','E','F');
    for($i=0;$i<$l;$i++)
    {
        $k = ord($r[$i]);
        $k1 = intval($k/16);
        $k2 = $k % 16;
        $s .= $arr[$k1].''.$arr[$k2];
    }
    return $s;
}
