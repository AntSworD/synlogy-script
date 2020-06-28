 #!/usr/bin/php -d open_basedir=/usr/local/share/ddns
<?php

if ($argc !== 5) {
    echo 'badparam';
    exit();
}

function curl_ip($type, $try_time = 0) {
  $req = curl_init();
  $curl_ip_url = "https://api6.ipify.org";
  $ip_filter_flag = FILTER_FLAG_IPV6;
  $ip_resolve = CURL_IPRESOLVE_V6;
  if ("ipv4" === $type) {
    $curl_ip_url = "https://api.ipify.org";
    $ip_filter_flag = FILTER_FLAG_IPV4;
    $ip_resolve = CURL_IPRESOLVE_V4;
  }

  $options = array(
    CURLOPT_URL=>$curl_ip_url,
    CURLOPT_HEADER=>0,
    CURLOPT_VERBOSE=>0,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_USERAGENT=>'Mozilla/4.0 (compatible;)',
    CURLOPT_IPRESOLVE=>$ip_resolve
  );

  curl_setopt_array($req, $options);
  $ip = curl_exec($req);
  curl_close($req);

  // $ip = file_get_contents($curl_ip_url);

  if (!filter_var($ip, FILTER_VALIDATE_IP, $ip_filter_flag) && $try_time < 10) {
    $ip = curl_ip($type, ++ $try_time);
  }
  return $ip;
}

function get_record($type) {
  $req = curl_init();
  $url = 'https://dnsapi.cn/Record.List';
  $post = array(
    'login_token'=>$GLOBALS['account'].','.$GLOBALS['pwd'],
      'domain_id'=>$GLOBALS['domainID'],
      'format'=>'json'
  );
  $options = array(
    CURLOPT_URL=>$url,
    CURLOPT_HEADER=>0,
    CURLOPT_VERBOSE=>0,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_USERAGENT=>'Mozilla/4.0 (compatible;)',
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>http_build_query($post),
  );
  curl_setopt_array($req, $options);
  $res = curl_exec($req);
  $json = json_decode($res, true);

  if (1 != $json['status']['code']) {
      echo 'Get Record List failed';
      curl_close($req);
      exit();
  }

  $recordID = -1;
  $record_total = $json['info']['record_total'];

  $filterType = "AAAA";
  if ("ipv4" === $type) {
    $filterType = "A";
  }
  for ($i = 0; $i < $record_total; $i++) {
      if (($json['records'][$i]['name'] === $GLOBALS['subDomain']) and ($json['records'][$i]['type'] === $filterType)) {
          $recordID = $json['records'][$i]['id'];
          break;
      }
  }

  return $recordID;
}

function modify_record($type) {
  $recordID = $GLOBALS['ipv6_recordID'];
  $ip = $GLOBALS['ipv6'];
  $recoreType = "AAAA";
  if ("ipv4" === $type) {
    $recordID = $GLOBALS['ipv4_recordID'];
    $ip = $GLOBALS['ipv4'];
    $recoreType = "A";
  }

  $req = curl_init();
  $url = 'https://dnsapi.cn/Record.Modify';
  $post = array(
    'login_token'=>$GLOBALS['account'].','.$GLOBALS['pwd'],
      'domain_id'=>$GLOBALS['domainID'],
      'record_id'=>$recordID,
      'sub_domain'=>$GLOBALS['subDomain'],
      'value'=>$ip,
      'record_type'=>$recoreType,
      'record_line'=>'默认',
      'format'=>'json'
  );
  $options = array(
    CURLOPT_URL=>$url,
    CURLOPT_HEADER=>0,
    CURLOPT_VERBOSE=>0,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_USERAGENT=>'Mozilla/4.0 (compatible;)',
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>http_build_query($post),
  );
  curl_setopt_array($req, $options);
  $res = curl_exec($req);
  curl_close($req);
  $json = json_decode($res, true);

  return $json['status']['code'];
}

$account = (string)$argv[1];
$pwd = (string)$argv[2];
$hostname = (string)$argv[3];
$ipv4 = (string)curl_ip("ipv4");
$ipv6 = (string)curl_ip("ipv6");

// check the hostname contains '.'
if (strpos($hostname, '.') === false) {
    echo "badparam";
    exit();
}

// only for IPv4 format
if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    echo "badparam";
    exit();
}
// only for IPv6 format
if (!filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
  echo "badparam";
  exit();
}

$url = 'https://dnsapi.cn/Domain.List';
$post = array(
	'login_token'=>$account.','.$pwd,
    'format'=>'json'
);
$req = curl_init();
$options = array(
  CURLOPT_URL=>$url,
  CURLOPT_HEADER=>0,
  CURLOPT_VERBOSE=>0,
  CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_USERAGENT=>'Mozilla/4.0 (compatible;)',
  CURLOPT_POST=>true,
  CURLOPT_POSTFIELDS=>http_build_query($post),
);
curl_setopt_array($req, $options);
$res = curl_exec($req);

$json = json_decode($res, true);

if (1 != $json['status']['code']) {
    if (-1 == $json['status']['code']) {
        echo 'badauth';
    } else if (9 == $json['status']['code']) {
        echo 'nohost';
    } else {
        echo 'Get Domain List failed['.$json['status']['code'].']';
    }
    //print_r($json['status']['code']);
    curl_close($req);
    exit();
}

$domain_total = $json['info']['domain_total'];

$domainID = -1;
for ($i = 0; $i < $domain_total; $i++) {
    $domain_tmp = $json['domains'][$i]['name'];
    if (strlen($domain_tmp) != 0 && $domain_tmp === substr($hostname, -strlen($domain_tmp))) {
        $domainID = $json['domains'][$i]['id'];
        $domain = $domain_tmp;
        if (strlen($hostname)>strlen($domain)) {
            $subDomain = substr($hostname, 0, strlen($hostname)-strlen($domain_tmp)-1);
        } else {
            $subDomain = '@';
        }
        break;
    }
}

if ($domainID === -1) {
    echo 'nohost';
    exit();
}

$ipv4_recordID = get_record("ipv4");
$ipv6_recordID = get_record("ipv6");

if ($ipv4_recordID === -1) {
    echo 'nohost';
    curl_close($req);
    exit();
}

if ($ipv6_recordID === -1) {
  echo 'nohost';
  curl_close($req);
  exit();
}

$ipv4_modify_code = modify_record("ipv4");
$ipv6_modify_code = modify_record("ipv6");

if (1 != $ipv4_modify_code || 1 != $ipv6_modify_code) {
    echo 'Update Record failed';
    exit();
}

echo 'good';
