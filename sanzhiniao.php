<?php

/*
本PHP首发自 https://t.me/iptvofficalgroup
转载或者转发请注明群组链接，否则生个儿子没屁眼
使用方法：xxx.xxx.xxx/sanzhiniao.php?id=list查看列表
27行PROXY变量需要改为本地HTTP代理，留空默认直连，消耗服务器流量
*/

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
set_time_limit(120);
date_default_timezone_set('Asia/Shanghai');
@ini_set('memory_limit', '512M');




define('LIVE_HOME_URL', 'https://m-epg.cmishow.com:1443?s=104&p=mLiveHome&k=1&v=1&c=1&a=852&i=3&catId=1436&l=zh_TW');
define('DETAIL_URL', 'https://m-epg.cmishow.com:1443?s=104&k=1&v=1&c=1&a=852&i=3&l=zh_TW&p=mLiveChannel&channelId=');
define('UA', 'okhttp/3.12.1');
define('BASE_KEY', '01234568');

$GLOBALS['UTV_GROUPS'] = getenv('UTV_GROUPS') !== false && getenv('UTV_GROUPS') !== ''
    ? getenv('UTV_GROUPS') : '本地,咪咕';
$GLOBALS['CHANNEL_CACHE'] = (int)(getenv('UTV_CHANNEL_CACHE') !== false ? getenv('UTV_CHANNEL_CACHE') : 600);
$GLOBALS['PLAYLIST_CACHE'] = (int)(getenv('UTV_PLAYLIST_CACHE') !== false ? getenv('UTV_PLAYLIST_CACHE') : 0);
$PROXY = 'http://192.168.1.1:7890'; //这里写你的HTTP代理，需要手动改
$GLOBALS['PROXY'] = $PROXY !== ''
    ? $PROXY
    : (getenv('UTV_PROXY') !== false && getenv('UTV_PROXY') !== '' ? getenv('UTV_PROXY') : '');
$GLOBALS['CACHE_DIR'] = rtrim(getenv('UTV_CACHE_DIR') !== false && getenv('UTV_CACHE_DIR') !== ''
    ? getenv('UTV_CACHE_DIR') : sys_get_temp_dir(), '/') . '/sanzhiniao_cache';




function cache_dir() {
    if (!is_dir($GLOBALS['CACHE_DIR'])) {
        @mkdir($GLOBALS['CACHE_DIR'], 0777, true);
    }
    return $GLOBALS['CACHE_DIR'];
}

function cache_get($key, $ttl) {
    if ($ttl <= 0) return null;
    $f = cache_dir() . '/' . md5($key) . '.cache';
    if (!is_file($f)) return null;
    if (time() - filemtime($f) >= $ttl) return null;
    $v = @file_get_contents($f);
    return $v === false ? null : $v;
}

function cache_set($key, $value) {
    $f = cache_dir() . '/' . md5($key) . '.cache';
    @file_put_contents($f, $value, LOCK_EX);
}

function segmap_load($channel_id) {
    $raw = cache_get('segmap_' . $channel_id, 86400);
    if ($raw === null) return array();
    $m = json_decode($raw, true);
    return is_array($m) ? $m : array();
}

function segmap_save($channel_id, $map) {
    cache_set('segmap_' . $channel_id, json_encode($map));
}




function http_get($url, $timeout = 20) {
    if (stripos($url, 'https://') === 0 && curl_ssl_is_old()) {
        return http_get_wget($url, $timeout);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => UA,
        CURLOPT_HTTPHEADER => array('Accept: application/json', 'Accept-Language: zh-CN,zh;q=0.9'),
    ));
    apply_curl_proxy($ch);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err !== '' || $code === 0) {
        if (stripos($url, 'https://') === 0 && function_exists('shell_exec')) {
            try {
                return http_get_wget($url, $timeout);
            } catch (Exception $e2) {
            }
        }
        throw new Exception('curl error: ' . $err . ' for ' . $url);
    }
    if ($code >= 400) {
        if (stripos($url, 'https://') === 0 && function_exists('shell_exec')) {
            try {
                return http_get_wget($url, $timeout);
            } catch (Exception $e2) {
            }
        }
        throw new Exception('HTTP ' . $code . ' for ' . $url);
    }
    return $body;
}

function curl_ssl_is_old() {
    $v = curl_version();
    $ssl = isset($v['ssl_version']) ? $v['ssl_version'] : '';
    if (preg_match('#^OpenSSL/(\d+)\.(\d+)#', $ssl, $m)) {
        return ((int)$m[1] < 1) || ((int)$m[1] === 1 && (int)$m[2] < 1);
    }
    return false;
}

function http_get_wget($url, $timeout) {
    if (!function_exists('shell_exec')) {
        throw new Exception('shell_exec unavailable for wget backend');
    }
    $env = '';
    if ($GLOBALS['PROXY'] !== '') {
        $scheme = strtolower((string)parse_url($GLOBALS['PROXY'], PHP_URL_SCHEME));
        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            throw new Exception('wget backend only supports http proxy');
        }
        $env = 'https_proxy=' . escapeshellarg($GLOBALS['PROXY']) . ' http_proxy=' . escapeshellarg($GLOBALS['PROXY']) . ' ';
    }
    $cmd = $env . 'wget -q -T ' . ((int)$timeout) . ' --no-check-certificate -O - ' . escapeshellarg($url) . ' 2>/dev/null';
    $out = array();
    $rc = 0;
    exec($cmd, $out, $rc);
    if ($rc !== 0) {
        throw new Exception('wget rc=' . $rc . ' for ' . $url);
    }
    return implode("\n", $out);
}

function apply_curl_proxy($ch) {
    if ($GLOBALS['PROXY'] === '') return;
    $p = parse_url($GLOBALS['PROXY']);
    $scheme = isset($p['scheme']) ? strtolower($p['scheme']) : 'http';
    $hostport = (isset($p['scheme']) ? $p['scheme'] . '://' : '') . $p['host'];
    if (isset($p['port'])) $hostport .= ':' . $p['port'];
    curl_setopt($ch, CURLOPT_PROXY, $hostport);
    if (isset($p['user'])) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $p['user'] . ':' . (isset($p['pass']) ? $p['pass'] : ''));
    }
    if ($scheme === 'socks5' || $scheme === 'socks5h') {
        curl_setopt($ch, CURLOPT_PROXYTYPE, defined('CURLPROXY_SOCKS5_HOSTNAME') ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_SOCKS5);
    } elseif ($scheme === 'socks4' || $scheme === 'socks4a') {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
    } else {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    }
}

function stream_http_to_output($url, $on_data = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
    $opts = array(
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => UA,
    );
    if ($on_data === null) {
        $opts[CURLOPT_WRITEFUNCTION] = function ($ch, $d) {
            echo $d;
            @ob_flush();
            @flush();
            return strlen($d);
        };
    } else {
        $opts[CURLOPT_WRITEFUNCTION] = $on_data;
    }
    curl_setopt_array($ch, $opts);
    apply_curl_proxy($ch);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err !== '' || $code === 0) {
        throw new Exception('curl error: ' . $err . ' for ' . $url);
    }
    if ($code >= 400) {
        throw new Exception('HTTP ' . $code . ' for ' . $url);
    }
}

function fetch_json($url) {
    $data = http_get($url, 20);
    $j = json_decode($data, true);
    if (!is_array($j)) throw new Exception('bad json from ' . $url);
    return $j;
}

function fetch_groups() {
    $d = fetch_json(LIVE_HOME_URL);
    return isset($d['data']['groups']) ? $d['data']['groups'] : array();
}

function fetch_channels($group_url) {
    $d = fetch_json($group_url);
    return isset($d['data']['channels']) ? $d['data']['channels'] : array();
}

function fetch_channel_detail($channel) {
    $json_url = isset($channel['jsonUrl']) ? $channel['jsonUrl'] : '';
    $channel_id = isset($channel['channelId']) ? $channel['channelId'] : '';
    $url = $json_url !== '' ? $json_url : DETAIL_URL . $channel_id;
    $d = fetch_json($url);
    $detail = isset($d['data']['detail']) ? $d['data']['detail'] : array();
    $play = isset($detail['livePlayurls'][0]) ? $detail['livePlayurls'][0] : array();
    return array(
        'channelId' => isset($channel['channelId']) ? $channel['channelId'] : '',
        'channelName' => isset($channel['channelName']) ? $channel['channelName'] : '',
        'channelNum' => isset($channel['channelNum']) ? $channel['channelNum'] : '',
        'channelLogo' => isset($channel['channelLogo']) ? $channel['channelLogo'] : '',
        'encryptType' => isset($detail['encryptType']) ? $detail['encryptType'] : '',
        'playurl' => isset($play['playurl']) ? $play['playurl'] : '',
        'customerId' => isset($play['customerId']) ? $play['customerId'] : '',
        'contentId' => isset($play['contentId']) ? $play['contentId'] : '',
    );
}

function refresh_channels($force = false) {
    $key = 'channels_' . implode(',', array_map('trim', explode(',', $GLOBALS['UTV_GROUPS'])));
    if (!$force) {
        $cached = cache_get($key, $GLOBALS['CHANNEL_CACHE']);
        if ($cached !== null) {
            $j = json_decode($cached, true);
            if (is_array($j)) return $j;
        }
    }
    $groups = fetch_groups();
    $wanted = array_map('trim', explode(',', $GLOBALS['UTV_GROUPS']));
    $selected = array();
    foreach ($groups as $g) {
        $name = isset($g['groupName']) ? $g['groupName'] : '';
        foreach ($wanted as $w) {
            if ($w !== '' && strpos($name, $w) !== false) { $selected[] = $g; break; }
        }
    }
    if (!$selected && $groups) $selected = array($groups[0]);
    if (!$selected) throw new Exception('no group found');
    $channels = array();
    foreach ($selected as $g) {
        $url = isset($g['jsonUrl']) ? $g['jsonUrl'] : '';
        $group_name = isset($g['groupName']) ? $g['groupName'] : '';
        foreach (fetch_channels($url) as $ch) {
            $ch['group'] = $group_name;
            $channels[] = $ch;
        }
    }
    cache_set($key, json_encode($channels));
    return $channels;
}

function get_detail($channel_id) {
    $cached = cache_get('detail_' . $channel_id, $GLOBALS['CHANNEL_CACHE']);
    if ($cached !== null) {
        $j = json_decode($cached, true);
        if (is_array($j)) return $j;
    }
    foreach (refresh_channels(false) as $ch) {
        if ((isset($ch['channelId']) ? $ch['channelId'] : '') === $channel_id) {
            $det = fetch_channel_detail($ch);
            cache_set('detail_' . $channel_id, json_encode($det));
            return $det;
        }
    }
    throw new Exception('channel ' . $channel_id . ' not found');
}

function get_playlist($channel_id) {
    $det = get_detail($channel_id);
    $playurl = isset($det['playurl']) ? $det['playurl'] : '';
    if ($playurl === '') throw new Exception('no playurl');
    $cached = cache_get('playlist_' . $channel_id, $GLOBALS['PLAYLIST_CACHE']);
    if ($cached !== null) return $cached;
    $text = http_get($playurl, 20);
    if (strpos($text, '#EXT-X-STREAM-INF') !== false) {
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $child = url_join($playurl, $line);
                $text = http_get($child, 20);
                break;
            }
        }
    }
    cache_set('playlist_' . $channel_id, $text);
    return $text;
}

function is_encrypted($det) {
    $v = isset($det['encryptType']) ? $det['encryptType'] : '';
    return $v === '2' || $v === 2;
}

function url_join($base, $rel) {
    if (preg_match('#^https?://#i', $rel)) return $rel;
    $p = parse_url($base);
    $scheme = isset($p['scheme']) ? $p['scheme'] : 'http';
    $host = isset($p['host']) ? $p['host'] : '';
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    $path = isset($p['path']) ? $p['path'] : '/';
    $dir = substr($path, 0, strrpos($path, '/') + 1);
    if ($rel !== '' && $rel[0] === '/') $dir = '';
    return $scheme . '://' . $host . $port . $dir . $rel;
}




$GLOBALS['AES_SBOX'] = array(
    0x63,0x7c,0x77,0x7b,0xf2,0x6b,0x6f,0xc5,0x30,0x01,0x67,0x2b,0xfe,0xd7,0xab,0x76,
    0xca,0x82,0xc9,0x7d,0xfa,0x59,0x47,0xf0,0xad,0xd4,0xa2,0xaf,0x9c,0xa4,0x72,0xc0,
    0xb7,0xfd,0x93,0x26,0x36,0x3f,0xf7,0xcc,0x34,0xa5,0xe5,0xf1,0x71,0xd8,0x31,0x15,
    0x04,0xc7,0x23,0xc3,0x18,0x96,0x05,0x9a,0x07,0x12,0x80,0xe2,0xeb,0x27,0xb2,0x75,
    0x09,0x83,0x2c,0x1a,0x1b,0x6e,0x5a,0xa0,0x52,0x3b,0xd6,0xb3,0x29,0xe3,0x2f,0x84,
    0x53,0xd1,0x00,0xed,0x20,0xfc,0xb1,0x5b,0x6a,0xcb,0xbe,0x39,0x4a,0x4c,0x58,0xcf,
    0xd0,0xef,0xaa,0xfb,0x43,0x4d,0x33,0x85,0x45,0xf9,0x02,0x7f,0x50,0x3c,0x9f,0xa8,
    0x51,0xa3,0x40,0x8f,0x92,0x9d,0x38,0xf5,0xbc,0xb6,0xda,0x21,0x10,0xff,0xf3,0xd2,
    0xcd,0x0c,0x13,0xec,0x5f,0x97,0x44,0x17,0xc4,0xa7,0x7e,0x3d,0x64,0x5d,0x19,0x73,
    0x60,0x81,0x4f,0xdc,0x22,0x2a,0x90,0x88,0x46,0xee,0xb8,0x14,0xde,0x5e,0x0b,0xdb,
    0xe0,0x32,0x3a,0x0a,0x49,0x06,0x24,0x5c,0xc2,0xd3,0xac,0x62,0x91,0x95,0xe4,0x79,
    0xe7,0xc8,0x37,0x6d,0x8d,0xd5,0x4e,0xa9,0x6c,0x56,0xf4,0xea,0x65,0x7a,0xae,0x08,
    0xba,0x78,0x25,0x2e,0x1c,0xa6,0xb4,0xc6,0xe8,0xdd,0x74,0x1f,0x4b,0xbd,0x8b,0x8a,
    0x70,0x3e,0xb5,0x66,0x48,0x03,0xf6,0x0e,0x61,0x35,0x57,0xb9,0x86,0xc1,0x1d,0x9e,
    0xe1,0xf8,0x98,0x11,0x69,0xd9,0x8e,0x94,0x9b,0x1e,0x87,0xe9,0xce,0x55,0x28,0xdf,
    0x8c,0xa1,0x89,0x0d,0xbf,0xe6,0x42,0x68,0x41,0x99,0x2d,0x0f,0xb0,0x54,0xbb,0x16,
);
$GLOBALS['AES_INV_SBOX'] = array_fill(0, 256, 0);
foreach ($GLOBALS['AES_SBOX'] as $_i => $_v) $GLOBALS['AES_INV_SBOX'][$_v] = $_i;
$GLOBALS['AES_RCON'] = array(0x01, 0x02, 0x04, 0x08, 0x10, 0x20, 0x40, 0x80, 0x1b, 0x36);


$GLOBALS['AES_TD'] = array(array(), array(), array(), array());
for ($_i = 0; $_i < 256; $_i++) {
    $_s = $GLOBALS['AES_INV_SBOX'][$_i];
    $GLOBALS['AES_TD'][0][$_i] = (aes_gmul($_s, 14) << 24) | (aes_gmul($_s, 9) << 16) | (aes_gmul($_s, 13) << 8) | aes_gmul($_s, 11);
    $GLOBALS['AES_TD'][1][$_i] = (aes_gmul($_s, 11) << 24) | (aes_gmul($_s, 14) << 16) | (aes_gmul($_s, 9) << 8) | aes_gmul($_s, 13);
    $GLOBALS['AES_TD'][2][$_i] = (aes_gmul($_s, 13) << 24) | (aes_gmul($_s, 11) << 16) | (aes_gmul($_s, 14) << 8) | aes_gmul($_s, 9);
    $GLOBALS['AES_TD'][3][$_i] = (aes_gmul($_s, 9) << 24) | (aes_gmul($_s, 13) << 16) | (aes_gmul($_s, 11) << 8) | aes_gmul($_s, 14);
}

function aes_xtime($x) {
    $x <<= 1;
    if ($x & 0x100) $x ^= 0x1B;
    return $x & 0xFF;
}

function aes_gmul($a, $b) {
    $r = 0;
    while ($b) {
        if ($b & 1) $r ^= $a;
        $a = aes_xtime($a);
        $b >>= 1;
    }
    return $r;
}

function aes_key_expand($key) {
    $w = array();
    for ($i = 0; $i < 4; $i++) {
        $w[$i] = (ord($key[$i * 4]) << 24) | (ord($key[$i * 4 + 1]) << 16)
               | (ord($key[$i * 4 + 2]) << 8) | ord($key[$i * 4 + 3]);
    }
    $sbox = $GLOBALS['AES_SBOX'];
    $rcon = $GLOBALS['AES_RCON'];
    for ($i = 4; $i < 44; $i++) {
        $t = $w[$i - 1];
        if ($i % 4 === 0) {
            $t = (($t << 8) | (($t >> 24) & 0xFF)) & 0xFFFFFFFF;
            $t = (($sbox[($t >> 24) & 0xFF] << 24)
                | ($sbox[($t >> 16) & 0xFF] << 16)
                | ($sbox[($t >> 8) & 0xFF] << 8)
                | $sbox[$t & 0xFF]) & 0xFFFFFFFF;
            $t ^= ($rcon[intdiv($i, 4) - 1] << 24);
        }
        $w[$i] = ($w[$i - 4] ^ $t) & 0xFFFFFFFF;
    }
    return $w;
}

function aes_decrypt_key_expand($key) {
    $w = aes_key_expand($key);
    for ($grp = 0; $grp < 5; $grp++) {
        $a = $grp * 4;
        $b = (10 - $grp) * 4;
        for ($k = 0; $k < 4; $k++) {
            $tmp = $w[$a + $k];
            $w[$a + $k] = $w[$b + $k];
            $w[$b + $k] = $tmp;
        }
    }
    for ($grp = 1; $grp < 10; $grp++) {
        $rk = $grp * 4;
        for ($k = 0; $k < 4; $k++) {
            $ww = $w[$rk + $k];
            $b0 = ($ww >> 24) & 0xFF;
            $b1 = ($ww >> 16) & 0xFF;
            $b2 = ($ww >> 8) & 0xFF;
            $b3 = $ww & 0xFF;
            $w[$rk + $k] = ((aes_gmul($b0, 14) ^ aes_gmul($b1, 11) ^ aes_gmul($b2, 13) ^ aes_gmul($b3, 9)) << 24)
                         | ((aes_gmul($b0, 9) ^ aes_gmul($b1, 14) ^ aes_gmul($b2, 11) ^ aes_gmul($b3, 13)) << 16)
                         | ((aes_gmul($b0, 13) ^ aes_gmul($b1, 9) ^ aes_gmul($b2, 14) ^ aes_gmul($b3, 11)) << 8)
                         | (aes_gmul($b0, 11) ^ aes_gmul($b1, 13) ^ aes_gmul($b2, 9) ^ aes_gmul($b3, 14));
        }
    }
    return $w;
}

function aes128_decrypt_block_w($w, $block) {
    $td0 = $GLOBALS['AES_TD'][0];
    $td1 = $GLOBALS['AES_TD'][1];
    $td2 = $GLOBALS['AES_TD'][2];
    $td3 = $GLOBALS['AES_TD'][3];
    $t0 = (ord($block[0]) << 24) | (ord($block[1]) << 16) | (ord($block[2]) << 8) | ord($block[3]);
    $t1 = (ord($block[4]) << 24) | (ord($block[5]) << 16) | (ord($block[6]) << 8) | ord($block[7]);
    $t2 = (ord($block[8]) << 24) | (ord($block[9]) << 16) | (ord($block[10]) << 8) | ord($block[11]);
    $t3 = (ord($block[12]) << 24) | (ord($block[13]) << 16) | (ord($block[14]) << 8) | ord($block[15]);
    $s0 = ($t0 ^ $w[0]) & 0xFFFFFFFF;
    $s1 = ($t1 ^ $w[1]) & 0xFFFFFFFF;
    $s2 = ($t2 ^ $w[2]) & 0xFFFFFFFF;
    $s3 = ($t3 ^ $w[3]) & 0xFFFFFFFF;
    for ($rnd = 1; $rnd < 10; $rnd++) {
        $rk = $rnd * 4;
        $t0 = ($td0[$s0 >> 24] ^ $td1[($s3 >> 16) & 0xFF] ^ $td2[($s2 >> 8) & 0xFF] ^ $td3[$s1 & 0xFF] ^ $w[$rk]) & 0xFFFFFFFF;
        $t1 = ($td0[$s1 >> 24] ^ $td1[($s0 >> 16) & 0xFF] ^ $td2[($s3 >> 8) & 0xFF] ^ $td3[$s2 & 0xFF] ^ $w[$rk + 1]) & 0xFFFFFFFF;
        $t2 = ($td0[$s2 >> 24] ^ $td1[($s1 >> 16) & 0xFF] ^ $td2[($s0 >> 8) & 0xFF] ^ $td3[$s3 & 0xFF] ^ $w[$rk + 2]) & 0xFFFFFFFF;
        $t3 = ($td0[$s3 >> 24] ^ $td1[($s2 >> 16) & 0xFF] ^ $td2[($s1 >> 8) & 0xFF] ^ $td3[$s0 & 0xFF] ^ $w[$rk + 3]) & 0xFFFFFFFF;
        $s0 = $t0;
        $s1 = $t1;
        $s2 = $t2;
        $s3 = $t3;
    }
    $inv = $GLOBALS['AES_INV_SBOX'];
    return chr($inv[$s0 >> 24] ^ (($w[40] >> 24) & 0xFF))
         . chr($inv[($s3 >> 16) & 0xFF] ^ (($w[40] >> 16) & 0xFF))
         . chr($inv[($s2 >> 8) & 0xFF] ^ (($w[40] >> 8) & 0xFF))
         . chr($inv[$s1 & 0xFF] ^ ($w[40] & 0xFF))
         . chr($inv[$s1 >> 24] ^ (($w[41] >> 24) & 0xFF))
         . chr($inv[($s0 >> 16) & 0xFF] ^ (($w[41] >> 16) & 0xFF))
         . chr($inv[($s3 >> 8) & 0xFF] ^ (($w[41] >> 8) & 0xFF))
         . chr($inv[$s2 & 0xFF] ^ ($w[41] & 0xFF))
         . chr($inv[$s2 >> 24] ^ (($w[42] >> 24) & 0xFF))
         . chr($inv[($s1 >> 16) & 0xFF] ^ (($w[42] >> 16) & 0xFF))
         . chr($inv[($s0 >> 8) & 0xFF] ^ (($w[42] >> 8) & 0xFF))
         . chr($inv[$s3 & 0xFF] ^ ($w[42] & 0xFF))
         . chr($inv[$s3 >> 24] ^ (($w[43] >> 24) & 0xFF))
         . chr($inv[($s2 >> 16) & 0xFF] ^ (($w[43] >> 16) & 0xFF))
         . chr($inv[($s1 >> 8) & 0xFF] ^ (($w[43] >> 8) & 0xFF))
         . chr($inv[$s0 & 0xFF] ^ ($w[43] & 0xFF));
}




function cal_hash($custom, $content) {
    $sum = 0;
    $l = strlen($custom);
    for ($i = 0; $i < $l; $i++) $sum += ord($custom[$i]);
    $l = strlen($content);
    for ($i = 0; $i < $l; $i++) $sum += ord($content[$i]);
    $v = $sum & 31;
    return $v <= 3 ? 4 : $v;
}

function arcv_key($customer) {
    return md5(BASE_KEY . $customer, true);
}

function data_restore_w($data, $nchunks, $w) {
    $len = strlen($data);
    $chunk = intdiv($len, $nchunks);
    $half = intdiv($chunk, 2);
    $target = (($len - 17 - $half) & ~0xF) + 16;
    if ($target < 0) $target = 0;
    $dec_len = $target;
    $body = substr($data, $half, $dec_len);
    $parts = array();
    for ($off = 0; $off + 16 <= strlen($body); $off += 16) {
        $parts[] = aes128_decrypt_block_w($w, substr($body, $off, 16));
    }
    if ($parts) {
        $dst = substr($data, 0, $half) . implode('', $parts) . substr($data, $half + $dec_len);
    } else {
        $dst = $data;
    }
    if ($chunk > 0 && $nchunks >= 2) {
        $buf = $dst;
        $n = $nchunks;
        $dst = substr($buf, 0, $chunk)
             . substr($buf, ($n - 1) * $chunk, $chunk)
             . substr($buf, $chunk, ($n - 2) * $chunk)
             . substr($buf, $n * $chunk);
    }
    return $dst;
}

function data_restore($data, $nchunks, $key) {
    return data_restore_w($data, $nchunks, aes_decrypt_key_expand($key));
}




function ts_pes_payloads($ts, $pid) {
    $len = strlen($ts);
    $pes = array();
    $cur = null;
    for ($off = 0; $off + 188 <= $len; $off += 188) {
        if ($ts[$off] !== "\x47") continue;
        $p = ((ord($ts[$off + 1]) & 0x1F) << 8) | ord($ts[$off + 2]);
        if ($p !== $pid) continue;
        $pusi = (ord($ts[$off + 1]) & 0x40) !== 0;
        $afc = (ord($ts[$off + 3]) >> 4) & 0x3;
        $poff = 4;
        if ($afc === 2 || $afc === 3) $poff = 5 + ord($ts[$off + 4]);
        $payload = substr($ts, $off + $poff, 188 - $poff);
        if ($pusi) {
            if (strlen($payload) >= 3 && substr($payload, 0, 3) === "\x00\x00\x01") {
                if ($cur !== null) $pes[] = $cur;
                $hdr_len = strlen($payload) >= 9 ? ord($payload[8]) + 9 : strlen($payload);
                $cur = substr($payload, $hdr_len);
            } else {
                if ($cur === null) $cur = '';
                $cur .= $payload;
            }
        } else {
            if ($cur === null) $cur = '';
            $cur .= $payload;
        }
    }
    if ($cur !== null) $pes[] = $cur;
    return $pes;
}

function splice_pes($ts, $pid, $new_pes) {
    $len = strlen($ts);
    $packets = array();
    for ($off = 0; $off + 188 <= $len; $off += 188) {
        $packets[] = substr($ts, $off, 188);
    }
    $n_pkts = count($packets);
    $pes_iter = 0;
    $cur = isset($new_pes[$pes_iter]) ? $new_pes[$pes_iter] : null;
    $pos = 0;
    for ($k = 0; $k < $n_pkts; $k++) {
        if ($cur === null) break;
        $pkt = $packets[$k];
        if ($pkt[0] !== "\x47") continue;
        $p = ((ord($pkt[1]) & 0x1F) << 8) | ord($pkt[2]);
        if ($p !== $pid) continue;
        $pusi = (ord($pkt[1]) & 0x40) !== 0;
        $afc = (ord($pkt[3]) >> 4) & 0x3;
        $poff = 4;
        if ($afc === 2 || $afc === 3) $poff = 5 + ord($pkt[4]);
        if ($pusi) {
            $hdr = substr($pkt, $poff, 9);
            if (strlen($hdr) >= 3 && substr($hdr, 0, 3) === "\x00\x00\x01") {
                $data_start = $poff + 9 + ord($pkt[$poff + 8]);
            } else {
                $data_start = $poff;
            }
        } else {
            $data_start = $poff;
        }
        if ($data_start >= 188) continue;
        $n = 188 - $data_start;
        $repl = '';
        while ($n > 0) {
            if ($cur === null) break 2;
            if (strlen($cur) <= $pos) {
                $pes_iter++;
                $cur = isset($new_pes[$pes_iter]) ? $new_pes[$pes_iter] : null;
                $pos = 0;
                continue;
            }
            $take = min($n, strlen($cur) - $pos);
            $repl .= substr($cur, $pos, $take);
            $n -= $take;
            $pos += $take;
            if ($pos >= strlen($cur)) {
                $pes_iter++;
                $cur = isset($new_pes[$pes_iter]) ? $new_pes[$pes_iter] : null;
                $pos = 0;
            }
        }
        if ($repl !== '') {
            $packets[$k] = substr($pkt, 0, $data_start) . $repl . substr($pkt, $data_start + strlen($repl));
        }
    }
    return implode('', $packets);
}

function find_media_pids($ts) {
    $len = strlen($ts);
    $pmt_pid = null;
    for ($off = 0; $off + 188 <= $len; $off += 188) {
        if ($ts[$off] !== "\x47") continue;
        $pid = ((ord($ts[$off + 1]) & 0x1F) << 8) | ord($ts[$off + 2]);
        if ($pid !== 0 || (ord($ts[$off + 1]) & 0x40) === 0) continue;
        $afc = (ord($ts[$off + 3]) >> 4) & 0x3;
        $payload = substr($ts, $off + 4, 184);
        if ($afc === 2 || $afc === 3) $payload = substr($ts, $off + 5 + ord($ts[$off + 4]), 188 - 5 - ord($ts[$off + 4]));
        if (strlen($payload) < 12 || $payload[0] !== "\x00") continue;
        $sec = substr($payload, 1 + ord($payload[0]));
        if (strlen($sec) < 12 || $sec[0] !== "\x00") continue;
        $pmt_pid = ((ord($sec[10]) & 0x1F) << 8) | ord($sec[11]);
        break;
    }
    if ($pmt_pid === null) return array(null, null);
    $video = null;
    $audio = null;
    for ($off = 0; $off + 188 <= $len; $off += 188) {
        if ($ts[$off] !== "\x47") continue;
        $pid = ((ord($ts[$off + 1]) & 0x1F) << 8) | ord($ts[$off + 2]);
        if ($pid !== $pmt_pid || (ord($ts[$off + 1]) & 0x40) === 0) continue;
        $afc = (ord($ts[$off + 3]) >> 4) & 0x3;
        $payload = substr($ts, $off + 4, 184);
        if ($afc === 2 || $afc === 3) $payload = substr($ts, $off + 5 + ord($ts[$off + 4]), 188 - 5 - ord($ts[$off + 4]));
        $sec = substr($payload, 1 + ord($payload[0]));
        if (strlen($sec) < 16 || $sec[0] !== "\x02") continue;
        $section_len = ((ord($sec[1]) & 0x0F) << 8) | ord($sec[2]);
        $body = substr($sec, 12, $section_len - 4);
        $i = 0;
        $bl = strlen($body);
        while ($i + 5 <= $bl) {
            $st = ord($body[$i]);
            $epid = ((ord($body[$i + 1]) & 0x1F) << 8) | ord($body[$i + 2]);
            $eslen = ((ord($body[$i + 3]) & 0x0F) << 8) | ord($body[$i + 4]);
            if ($st === 0x1B || $st === 0x24) $video = $epid;
            elseif ($st === 0x0F || $st === 0x11 || $st === 0x03 || $st === 0x04) $audio = $epid;
            $i += 5 + $eslen;
        }
        break;
    }
    return array($video, $audio);
}

function decrypt_ts($ts, $customer, $content) {
    if ($customer === '' || $content === '') return $ts;
    $key = arcv_key($customer);
    $nchunks = cal_hash($customer, $content);
    list($video_pid, $audio_pid) = find_media_pids($ts);
    if ($video_pid === null && $audio_pid === null) return $ts;
    $pairs = array(array($video_pid, 'video'), array($audio_pid, 'audio'));
    foreach ($pairs as $pair) {
        $pid = $pair[0];
        if ($pid === null) continue;
        $pes = ts_pes_payloads($ts, $pid);
        if (!$pes) continue;
        $new_pes = array();
        foreach ($pes as $p) {
            if (strlen($p) >= 4 && substr($p, 0, 4) === 'ARCV') {
                $body = substr($p, 16);
                $prefix = 'ARCV';
            } else {
                $body = $p;
                $prefix = '';
            }
            
            $new_pes[] = $prefix . data_restore($body, $nchunks, $key);
        }
        $ts = splice_pes($ts, $pid, $new_pes);
    }
    return $ts;
}




function ts_stream_emit($bytes) {
    echo $bytes;
    @ob_flush();
    @flush();
}

function ts_stream_finalize(&$st) {
    if (!$st['pending']) return;
    $body = $st['body'];
    $out_body = $body;
    if (strlen($body) >= 4 && substr($body, 0, 4) === 'ARCV') {
        $out_body = 'ARCV' . data_restore_w(substr($body, 16), $st['nchunks'], $st['w']);
    } elseif ($body !== '') {
        $out_body = data_restore_w($body, $st['nchunks'], $st['w']);
    }
    $olen = strlen($out_body);
    $pos = 0;
    for ($i = 0; $i < count($st['pending']); $i++) {
        $item = $st['pending'][$i];
        $pkt = $item['pkt'];
        if (!$item['media']) {
            ts_stream_emit($pkt);
            continue;
        }
        $ds = $item['ds'];
        if ($ds >= 188) {
            ts_stream_emit($pkt);
            continue;
        }
        $take = 188 - $ds;
        if ($take > $olen - $pos) $take = max(0, $olen - $pos);
        if ($take > 0) {
            $pkt = substr($pkt, 0, $ds) . substr($out_body, $pos, $take) . substr($pkt, $ds + $take);
            $pos += $take;
        }
        ts_stream_emit($pkt);
    }
    $st['pending'] = array();
    $st['body'] = '';
}

function ts_stream_packet(&$st, $pkt) {
    if ($pkt[0] !== "\x47") return;
    $pid = ((ord($pkt[1]) & 0x1F) << 8) | ord($pkt[2]);
    $pusi = (ord($pkt[1]) & 0x40) !== 0;
    $afc = (ord($pkt[3]) >> 4) & 0x3;
    $poff = 4;
    if ($afc === 2 || $afc === 3) $poff = 5 + ord($pkt[4]);
    if ($st['pids']['v'] === null && $st['pids']['a'] === null) {
        
        $st['pre'][] = $pkt;
        list($v, $a) = find_media_pids(implode('', $st['pre']));
        if ($v !== null || $a !== null) {
            $st['pids']['v'] = $v;
            $st['pids']['a'] = $a;
            $pre = $st['pre'];
            $st['pre'] = array();
            foreach ($pre as $pp) ts_stream_packet($st, $pp);
        }
        return;
    }
    if ($pid !== $st['pids']['v'] && $pid !== $st['pids']['a']) {
        if ($st['pending']) {
            
            $st['pending'][] = array('pkt' => $pkt, 'media' => false, 'ds' => 0);
            return;
        }
        ts_stream_emit($pkt);
        return;
    }
    $data_start = $poff;
    $is_pes_start = false;
    if ($pusi && strlen($pkt) >= $poff + 3 && substr($pkt, $poff, 3) === "\x00\x00\x01") {
        $is_pes_start = true;
        $data_start = strlen($pkt) >= $poff + 9 ? $poff + 9 + ord($pkt[$poff + 8]) : strlen($pkt);
    }
    if ($data_start > 188) $data_start = 188;
    if ($is_pes_start) {
        ts_stream_finalize($st);
        $st['pending'] = array(array('pkt' => $pkt, 'media' => true, 'ds' => $data_start));
        $st['body'] = $data_start < 188 ? substr($pkt, $data_start) : '';
    } else {
        if (!$st['pending']) {
            $st['pending'] = array(array('pkt' => $pkt, 'media' => true, 'ds' => $data_start));
            $st['body'] = $data_start < 188 ? substr($pkt, $data_start) : '';
        } else {
            $st['pending'][] = array('pkt' => $pkt, 'media' => true, 'ds' => $data_start);
            if ($data_start < 188) $st['body'] .= substr($pkt, $data_start);
        }
    }
}

function ts_stream_flush(&$st) {
    ts_stream_finalize($st);
    if ($st['pre']) {
        foreach ($st['pre'] as $pkt) ts_stream_emit($pkt);
        $st['pre'] = array();
    }
    if ($st['buf'] !== '') {
        ts_stream_emit($st['buf']);
        $st['buf'] = '';
    }
}

function stream_segment($url, $det) {
    $customer = isset($det['customerId']) ? $det['customerId'] : '';
    $content = isset($det['contentId']) ? $det['contentId'] : '';
    if (!is_encrypted($det) || $customer === '' || $content === '') {
        stream_http_to_output($url);
        return;
    }
    $st = array(
        'buf' => '',
        'pre' => array(),
        'pids' => array('v' => null, 'a' => null),
        'pending' => array(),
        'body' => '',
        'nchunks' => cal_hash($customer, $content),
        'w' => aes_decrypt_key_expand(arcv_key($customer)),
    );
    stream_http_to_output($url, function ($ch, $chunk) use (&$st) {
        $st['buf'] .= $chunk;
        while (strlen($st['buf']) >= 188) {
            $pkt = substr($st['buf'], 0, 188);
            $st['buf'] = substr($st['buf'], 188);
            ts_stream_packet($st, $pkt);
        }
        return strlen($chunk);
    });
    ts_stream_flush($st);
}




function script_url() {
    $scheme = 'http';
    $fwd = getenv('HTTP_X_FORWARDED_PROTO');
    if ($fwd !== false && $fwd !== '') {
        $scheme = $fwd;
    } elseif (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '' ? $_SERVER['HTTP_HOST'] : '127.0.0.1';
    $self = isset($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] !== '' ? $_SERVER['SCRIPT_NAME'] : '/sanzhiniao.php';
    return $scheme . '://' . $host . $self;
}

function m3u_attr($s) {
    return str_replace(array('"', "\n", "\r"), array("'", ' ', ' '), $s);
}

function build_local_playlist($channel_id) {
    $text = get_playlist($channel_id);
    $det = get_detail($channel_id);
    $base = isset($det['playurl']) ? $det['playurl'] : '';
    $base_seq = 0;
    foreach (explode("\n", $text) as $line) {
        if (strpos($line, '#EXT-X-MEDIA-SEQUENCE:') === 0) {
            $base_seq = (int)trim(substr($line, strlen('#EXT-X-MEDIA-SEQUENCE:')));
        }
    }
    $map = segmap_load($channel_id);
    $prune = $base_seq > 20 ? $base_seq - 20 : 0;
    foreach ($map as $seq => $u) {
        if ((int)$seq < $prune) unset($map[$seq]);
    }
    $out = array();
    $idx = 0;
    foreach (explode("\n", $text) as $line) {
        $line = rtrim($line, "\r");
        if ($line !== '' && $line[0] !== '#') {
            $url = url_join($base, $line);
            $seq = $base_seq + $idx;
            $map[$seq] = $url;
            $out[] = '?id=' . rawurlencode($channel_id) . '&seq=' . $seq;
            $idx++;
        } else {
            $out[] = $line;
        }
    }
    segmap_save($channel_id, $map);
    return implode("\n", $out) . "\n";
}

function build_playlist_file() {
    $chs = refresh_channels(false);
    $lines = array(
        '#EXTM3U x-tvg-url="https://epg.zsdc.eu.org/t.xml"',
        '#EXTINF:-1 tvg-name="注意事项" tvg-logo="https://cdn.jsdelivr.net/gh/jkwu5472/first/notice.jpg" group-title="注意事项",注意事项',
        'https://cdn.jsdelivr.net/gh/jkwu5472/first/media.m3u8',
    );
    $self = script_url();
    foreach ($chs as $c) {
        $name = m3u_attr(isset($c['channelName']) ? $c['channelName'] : '');
        $logo = m3u_attr(isset($c['channelLogo']) ? $c['channelLogo'] : '');
        $group = m3u_attr(isset($c['group']) ? $c['group'] : '');
        $cid = rawurlencode(isset($c['channelId']) ? $c['channelId'] : '');
        $lines[] = '#EXTINF:-1 tvg-name="' . $name . '" tvg-logo="' . $logo . '" group-title="' . $group . '",' . $name;
        $lines[] = $self . '?id=' . $cid;
    }
    return implode("\n", $lines) . "\n";
}




if (PHP_SAPI === 'cli') {
    $argv = isset($argv) ? $argv : array();
    if (isset($argv[1]) && $argv[1] === '--self-test') {
        $key = hex2bin('000102030405060708090a0b0c0d0e0f');
        $ct = hex2bin('69c4e0d86a7b0430d8cdb78070b4c55a');
        $pt = aes128_decrypt_block_w(aes_decrypt_key_expand($key), $ct);
        echo $pt === hex2bin('00112233445566778899aabbccddeeff') ? "AES self-test OK\n" : "AES self-test FAILED\n";
        exit(0);
    }
    if (isset($argv[1]) && $argv[1] === '--decrypt' && isset($argv[5])) {
        $raw = file_get_contents($argv[2]);
        $dec = decrypt_ts($raw, $argv[3], $argv[4]);
        file_put_contents($argv[5], $dec);
        echo 'decrypted ' . $argv[2] . ' -> ' . $argv[5] . ' (' . strlen($dec) . " bytes)\n";
        exit(0);
    }
    echo "usage: php sanzhiniao.php [--self-test] [--decrypt in.ts customerId contentId out.ts]\n";
    exit(0);
}




if (PHP_SAPI === 'cli') exit(0);

$action = isset($_GET['action']) ? $_GET['action'] : '';
$id = isset($_GET['id']) ? (string)$_GET['id'] : '';
$seq = isset($_GET['seq']) ? (string)$_GET['seq'] : '';

function send_json_error($code, $msg) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => $msg));
}

try {
    if ($action === 'playlist' || $id === 'list') {
        header('Content-Type: audio/x-mpegurl; charset=utf-8');
        header('Cache-Control: no-cache');
        echo build_playlist_file();
    } elseif ($id !== '' && $seq !== '') {
        if (!preg_match('/^\d+$/', $seq)) {
            send_json_error(404, 'bad seq');
            exit;
        }
        $seq = (int)$seq;
        $map = segmap_load($id);
        if (!isset($map[$seq])) {
            send_json_error(404, 'segment not found');
            exit;
        }
        $url = $map[$seq];
        $det = get_detail($id);
        header('Content-Type: video/mp2t');
        
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) @ob_end_clean();
        stream_segment($url, $det);
    } elseif ($id !== '') {
        header('Content-Type: application/vnd.apple.mpegurl');
        header('Cache-Control: no-cache');
        echo build_local_playlist($id);
    } else {
        send_json_error(404, 'not found');
    }
} catch (Exception $e) {
    send_json_error(500, $e->getMessage());
}
