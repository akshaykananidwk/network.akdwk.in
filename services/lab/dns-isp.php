<?php

declare(strict_types=1);

/**
 * A stand-in for the customer's own DNS server.
 *
 * The §18 requirement is that names under our zone resolve through the agent
 * and *every other name the machine looks up goes where it went before*. That
 * second half cannot be proved by looking at our resolver: it is proved by
 * watching the customer's, and seeing the public lookups still arrive there.
 *
 * So the drill runs this in the namespace that stands in for the ISP, points
 * the client at it, and reads its log afterwards. It answers anything with a
 * fixed address — what it resolves to does not matter, only that it was asked.
 *
 *   php dns-isp.php <port> <logfile>
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$port = (int) ($argv[1] ?? 0);
$logFile = (string) ($argv[2] ?? '');

if ($port <= 0 || $logFile === '') {
    fwrite(STDERR, "usage: dns-isp.php <port> <logfile>\n");
    exit(1);
}

$server = @stream_socket_server("udp://0.0.0.0:{$port}", $errno, $errstr, STREAM_SERVER_BIND);
if ($server === false) {
    fwrite(STDERR, "could not listen on {$port}: {$errstr}\n");
    exit(1);
}

/** Read a DNS name out of a query, for the log. */
function readName(string $msg): string
{
    $at = 12;
    $labels = [];
    $length = strlen($msg);

    while ($at < $length) {
        $len = ord($msg[$at]);
        if ($len === 0 || ($len & 0xC0) !== 0) {
            break;
        }
        $at++;
        if ($at + $len > $length) {
            break;
        }
        $labels[] = substr($msg, $at, $len);
        $at += $len;
    }

    return strtolower(implode('.', $labels));
}

while (true) {
    $from = '';
    $msg = @stream_socket_recvfrom($server, 512, 0, $from);
    if ($msg === false || strlen($msg) < 12) {
        continue;
    }

    $name = readName($msg);
    file_put_contents($logFile, $name . "\n", FILE_APPEND);

    // `.internal` is reserved for private use and is not delegated, so no real
    // resolver on the internet answers for it. Answering here would make the
    // drill's cleanup check pass for the wrong reason — a name that resolved
    // because the ISP said so, after the agent had correctly stopped saying
    // so itself.
    if (str_ends_with($name, '.internal')) {
        $nx = substr($msg, 0, 2) . "\x81\x83" . "\x00\x01\x00\x00\x00\x00\x00\x00"
            . substr($msg, 12);
        @stream_socket_sendto($server, $nx, 0, $from);
        continue;
    }

    // A minimal answer: echo the question, one A record pointing at an address
    // that exists only here. The drill cares that the query arrived, not what
    // came back.
    $questionEnd = 12;
    while ($questionEnd < strlen($msg) && ord($msg[$questionEnd]) !== 0) {
        $questionEnd += ord($msg[$questionEnd]) + 1;
    }
    $questionEnd += 5;

    if ($questionEnd > strlen($msg)) {
        continue;
    }

    $reply = substr($msg, 0, 2)
        . "\x81\x80"              // response, recursion available, no error
        . "\x00\x01\x00\x01\x00\x00\x00\x00"
        . substr($msg, 12, $questionEnd - 12)
        . "\xc0\x0c\x00\x01\x00\x01\x00\x00\x00\x1e\x00\x04"
        . chr(203) . chr(0) . chr(113) . chr(1);

    @stream_socket_sendto($server, $reply, 0, $from);
}
