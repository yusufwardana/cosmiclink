<?php

/**
 * Throwaway transport probe (read-only): checks which RouterOS API port
 * completes a TLS handshake. No credentials are sent.
 */

$host = '10.10.12.1';

foreach ([8728, 8729] as $port) {
    $start = microtime(true);
    $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => false]]);
    $socket = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 4, STREAM_CLIENT_CONNECT, $context);
    $elapsed = round((microtime(true) - $start) * 1000);

    if ($socket) {
        $meta = stream_get_meta_data($socket);
        echo "port {$port}: TLS handshake OK ({$elapsed} ms) crypto=" . ($meta['crypto']['protocol'] ?? 'unknown') . PHP_EOL;
        fclose($socket);
    } else {
        echo "port {$port}: TLS handshake FAILED ({$elapsed} ms) errno={$errno} err={$errstr}" . PHP_EOL;
    }
}

foreach ([8728, 8729] as $port) {
    $socket = @fsockopen($host, $port, $errno, $errstr, 4);
    if ($socket) {
        // RouterOS API greets nothing; send a length-prefixed empty sentence to see if the server answers.
        fwrite($socket, hex2bin('0000'));
        stream_set_timeout($socket, 2);
        $reply = fread($socket, 16);
        echo "port {$port}: plain API write ok, reply=" . ($reply === '' ? '(none)' : bin2hex($reply)) . PHP_EOL;
        fclose($socket);
    } else {
        echo "port {$port}: plain TCP failed errno={$errno} err={$errstr}" . PHP_EOL;
    }
}
