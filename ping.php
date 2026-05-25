<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

function getServerIPv4() {
    // Try SERVER_ADDR first, skip if it's IPv6
    $addr = $_SERVER['SERVER_ADDR'] ?? '';
    if ($addr && !str_contains($addr, ':')) {
        return $addr;
    }

    // Fall back: get the machine's actual IPv4 from hostname
    $hostname = gethostname();
    $ips = gethostbynamel($hostname);
    if ($ips) {
        foreach ($ips as $ip) {
            // Return the first non-loopback IPv4
            if ($ip !== '127.0.0.1' && !str_contains($ip, ':')) {
                return $ip;
            }
        }
    }

    return '127.0.0.1'; // last resort
}

$ip = getServerIPv4();

echo json_encode([
    "status"   => "ok",
    "app"      => "roboventure",
    "server_ip" => $ip,
    "base_url" => "http://" . $ip . "/roboventure_api"
]);
?>