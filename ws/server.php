<?php
declare(strict_types=1);

/**
 * Dependency-free WebSocket broadcast server (RFC 6455).
 * Usage: php ws/server.php   (listens on ws://127.0.0.1:8080)
 * Clients authenticate with ?token=<ws_token issued at login>.
 */
require __DIR__ . '/../config/database.php';

set_time_limit(0);
ob_implicit_flush();

const WS_HOST = '127.0.0.1';
const WS_PORT = 8080;
const GUID    = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

$server = stream_socket_server('tcp://' . WS_HOST . ':' . WS_PORT, $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "WebSocket server failed to start: {$errstr}\n");
    exit(1);
}
echo '[' . date('H:i:s') . "] WebSocket server listening on " . WS_HOST . ':' . WS_PORT . "\n";

/** @var array<int, array{socket: resource, user: int}> $clients */
$clients     = [];
$lastEventId = 0;
$nextPoll    = 0.0;
$nextPing    = time() + 30;
$nextCleanup = time() + 300;

function ws_encode(string $payload, int $opcode = 0x81): string
{
    $len = strlen($payload);
    if ($len <= 125) {
        $head = chr($opcode) . chr($len);
    } elseif ($len <= 65535) {
        $head = chr($opcode) . chr(126) . pack('n', $len);
    } else {
        $head = chr($opcode) . chr(127) . pack('J', $len);
    }
    return $head . $payload;
}

function ws_handshake($socket): ?int
{
    $data = '';
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline && strpos($data, "\r\n\r\n") === false) {
        $chunk = fread($socket, 4096);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }

    if (!preg_match('/token=([a-f0-9]{64})/i', $data, $m) || !preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $data, $k)) {
        return null;
    }

    $stmt = db()->prepare('SELECT id FROM users WHERE ws_token = ?');
    $stmt->execute([$m[1]]);
    $userId = $stmt->fetchColumn();
    if ($userId === false) {
        return null;
    }

    $accept = base64_encode(sha1($k[1] . GUID, true));
    fwrite($socket, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");

    return (int) $userId;
}

function ws_handle_client_frame($socket, string $data): bool
{
    if (strlen($data) < 2) {
        return true;
    }
    $opcode = ord($data[0]) & 0x0f;
    if ($opcode === 8) {           // close
        return false;
    }
    if ($opcode === 9) {           // ping -> pong
        @fwrite($socket, ws_encode('', 0x8a));
    }
    return true;                   // text/binary from clients is ignored (broadcast-only server)
}

while (true) {
    $read = [$server];
    foreach ($clients as $c) {
        $read[] = $c['socket'];
    }
    $write = $except = null;

    if (stream_select($read, $write, $except, 0, 200000) === false) {
        break;
    }

    foreach ($read as $ready) {
        if ($ready === $server) {
            while ($conn = @stream_socket_accept($server, 0)) {
                $userId = ws_handshake($conn);
                if ($userId === null) {
                    fclose($conn);
                    continue;
                }
                $clients[(int) $conn] = ['socket' => $conn, 'user' => $userId];
                echo '[' . date('H:i:s') . "] Client connected (user #{$userId})\n";
            }
            continue;
        }

        $data = @fread($ready, 8192);
        $key  = (int) $ready;
        if ($data === false || $data === '' || !ws_handle_client_frame($ready, $data)) {
            @fclose($ready);
            unset($clients[$key]);
            echo '[' . date('H:i:s') . "] Client disconnected\n";
        }
    }

    $now = microtime(true);
    if ($now >= $nextPoll) {
        $nextPoll = $now + 1.0;

        $stmt = db()->prepare('SELECT id, user_id, type, payload FROM events WHERE id > ? ORDER BY id ASC');
        $stmt->execute([$lastEventId]);
        foreach ($stmt->fetchAll() as $event) {
            $lastEventId = (int) $event['id'];
            $frame = ws_encode(json_encode([
                'type'    => $event['type'],
                'payload' => json_decode((string) $event['payload'], true),
            ]));
            foreach ($clients as $c) {
                if ($c['user'] === (int) $event['user_id']) {
                    @fwrite($c['socket'], $frame);
                }
            }
        }
    }

    if (time() >= $nextPing) {
        $nextPing = time() + 30;
        $ping = ws_encode('', 0x89);
        foreach ($clients as $key => $c) {
            if (@fwrite($c['socket'], $ping) === false) {
                @fclose($c['socket']);
                unset($clients[$key]);
            }
        }
    }

    if (time() >= $nextCleanup) {
        $nextCleanup = time() + 300;
        db()->exec('DELETE FROM events WHERE created_at < NOW() - INTERVAL 1 HOUR');
    }
}