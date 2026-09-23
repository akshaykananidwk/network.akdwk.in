<?php

declare(strict_types=1);

/**
 * A listener that says which machine it is.
 *
 * The subnet-mapping drill puts two different machines at 192.168.1.50 — the
 * technician's own printer and the customer's recorder — because that is what
 * the feature exists to make workable. A connection succeeding proves nothing
 * there; what has to be proved is *which* of the two answered, and the failure
 * worth catching is reaching your own machine and believing you reached the
 * customer's.
 *
 * nc cannot do this. "-k" keeps the socket open but sends the banner once, and
 * a loop around a one-shot "nc -l" leaves a window between connections where a
 * drill gets a reset and blames the product.
 *
 *   php banner-server.php <port> <banner>
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$port = (int) ($argv[1] ?? 0);
$banner = (string) ($argv[2] ?? '');

if ($port <= 0 || $banner === '') {
    fwrite(STDERR, "usage: banner-server.php <port> <banner>\n");
    exit(1);
}

$server = @stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "could not listen on {$port}: {$errstr}\n");
    exit(1);
}

// No timeout on accept: the drill decides when this stops, by killing the
// process group it was started in.
while (true) {
    $client = @stream_socket_accept($server, -1);
    if ($client === false) {
        continue;
    }

    @fwrite($client, $banner . "\n");
    @fclose($client);
}
