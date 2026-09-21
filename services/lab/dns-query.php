<?php

declare(strict_types=1);

/**
 * Ask one DNS server one question, and print what it said.
 *
 * `dig` is not installed everywhere and `host` gives up too easily on a
 * REFUSED, which is the answer this drill most needs to be able to see. This
 * prints the address for a successful answer and the response code by name
 * otherwise, so a scenario can assert on either.
 *
 *   php dns-query.php <server-ip> <name> [port]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$server = (string) ($argv[1] ?? '');
$name = strtolower(trim((string) ($argv[2] ?? ''), '.'));
$port = (int) ($argv[3] ?? 53);

if ($server === '' || $name === '') {
    fwrite(STDERR, "usage: dns-query.php <server-ip> <name> [port]\n");
    exit(1);
}

$id = random_int(0, 0xFFFF);
$query = pack('n6', $id, 0x0100, 1, 0, 0, 0);
foreach (explode('.', $name) as $label) {
    $query .= chr(strlen($label)) . $label;
}
$query .= "\x00" . pack('n2', 1, 1); // A, IN

$socket = @stream_socket_client("udp://{$server}:{$port}", $errno, $errstr, 3);
if ($socket === false) {
    fwrite(STDERR, "could not reach {$server}:{$port}: {$errstr}\n");
    exit(1);
}

stream_set_timeout($socket, 3);
@fwrite($socket, $query);

$reply = @fread($socket, 4096);
if ($reply === false || strlen($reply) < 12) {
    echo "TIMEOUT\n";
    exit(1);
}

$flags = unpack('n', substr($reply, 2, 2))[1];
$rcode = $flags & 0x0F;

$names = [0 => 'NOERROR', 1 => 'FORMERR', 2 => 'SERVFAIL', 3 => 'NXDOMAIN', 4 => 'NOTIMP', 5 => 'REFUSED'];

if ($rcode !== 0) {
    echo ($names[$rcode] ?? "RCODE{$rcode}"), "\n";
    exit(0);
}

$answers = unpack('n', substr($reply, 6, 2))[1];
if ($answers === 0) {
    echo "NOANSWER\n";
    exit(0);
}

// Skip the question section, then walk to the first A record's data.
$at = 12;
while ($at < strlen($reply) && ord($reply[$at]) !== 0) {
    $len = ord($reply[$at]);
    if (($len & 0xC0) !== 0) {
        break;
    }
    $at += $len + 1;
}
$at += 5;

for ($i = 0; $i < $answers && $at + 12 <= strlen($reply); $i++) {
    // The name, which is a pointer in anything we generate but may not be.
    if ((ord($reply[$at]) & 0xC0) === 0xC0) {
        $at += 2;
    } else {
        while ($at < strlen($reply) && ord($reply[$at]) !== 0) {
            $at += ord($reply[$at]) + 1;
        }
        $at++;
    }

    if ($at + 10 > strlen($reply)) {
        break;
    }

    $type = unpack('n', substr($reply, $at, 2))[1];
    $length = unpack('n', substr($reply, $at + 8, 2))[1];
    $at += 10;

    if ($type === 1 && $length === 4 && $at + 4 <= strlen($reply)) {
        echo inet_ntop(substr($reply, $at, 4)), "\n";
        exit(0);
    }

    $at += $length;
}

echo "NOANSWER\n";
