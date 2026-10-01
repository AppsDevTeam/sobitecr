<?php

declare(strict_types=1);

use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Dlouhobezici monitor spojeni na produkcni connect server.
 *
 * Napodobuje PUVODNI chovani pokladny (Flutter, `ws.pingInterval = 5s`):
 * protokolovy WS ping kazdych 5 s a jediny zmeskany pong znamena mrtve
 * spojeni -> zavrit a po 5 s reconnect. Ucel: overit, jestli hromadne
 * vypadky videne v `session_connect` ("Server: No response to ping.",
 * desitky spojeni ve stejnou vterinu) postihuji i klienta na stabilni
 * kancelarske siti, nebo jen pokladny na mobilnich datech.
 *
 * Vsechny udalosti se loguji s ms presnosti do temp/monitor.log i na
 * stdout, aby sly casove korelovat s incidenty na serveru (dashboard
 * "Posledni hromadne vypadky connect serveru").
 *
 * Pouziti:
 *   API_KEY=... php bin/monitor.php <identifier>
 *
 * Token se dr??i v temp/<identifier>.dat (stejna konvence jako client.php).
 * Na prichozi serverove pingy odpovida pongem automaticky Pawl
 * (vendor/ratchet/pawl/src/WebSocket.php), serverovy 15s watchdog nas
 * tedy nezabiji a pripadne odpojeni je opravdu vypadek, ne artefakt.
 */
final class ConnectionMonitor
{
	private const URL = 'wss://connect.sobitecr.com';
	private const PING_INTERVAL = 5;
	private const RECONNECT_DELAY = 5; // stejne jako reconnect() v puvodni appce
	private const SUMMARY_INTERVAL = 60;

	private LoopInterface $loop;
	private string $apiKey;
	private string $identifier;
	private string $token;
	private string $logFile;

	private ?WebSocket $ws = null;
	private ?TimerInterface $pingTimer = null;
	private bool $pongReceived = true;
	private ?float $pingSentAt = null;
	private ?float $connectedAt = null;

	// statistiky pro minutovy souhrn, at kazdy pong nespamuje log
	private int $statPings = 0;
	private int $statPongs = 0;
	private float $statRttMaxMs = 0.0;
	private float $statRttSumMs = 0.0;
	private int $totalReconnects = 0;

	public function __construct(string $apiKey, string $identifier, string $token)
	{
		$this->loop = Loop::get();
		$this->apiKey = $apiKey;
		$this->identifier = $identifier;
		$this->token = $token;
		$this->logFile = __DIR__ . '/../temp/monitor.log';
	}

	public function run(): void
	{
		$this->log('MONITOR START (identifier=' . $this->identifier . ', ping interval ' . self::PING_INTERVAL . ' s, 1 missed pong = reconnect)');

		$this->loop->addPeriodicTimer(self::SUMMARY_INTERVAL, function (): void {
			$avg = $this->statPongs > 0 ? round($this->statRttSumMs / $this->statPongs, 1) : 0;
			$this->log(sprintf(
				'SUMMARY pings=%d pongs=%d rtt_avg=%sms rtt_max=%sms reconnects_total=%d connected=%s',
				$this->statPings,
				$this->statPongs,
				$avg,
				round($this->statRttMaxMs, 1),
				$this->totalReconnects,
				$this->ws !== null ? 'yes' : 'NO',
			));
			$this->statPings = $this->statPongs = 0;
			$this->statRttMaxMs = $this->statRttSumMs = 0.0;
		});

		$this->connect();
		$this->loop->run();
	}

	private function connect(): void
	{
		$this->log('CONNECTING');
		$start = microtime(true);

		$headers = [
			'X-Api-Key' => $this->apiKey,
			'Authorization' => 'Bearer ' . base64_encode($this->identifier . ' ' . $this->token),
		];

		\Ratchet\Client\connect(self::URL, [], $headers, $this->loop)->then(
			function (WebSocket $conn) use ($start): void {
				$this->ws = $conn;
				$this->pongReceived = true;
				$this->pingSentAt = null;
				$this->connectedAt = microtime(true);
				$this->log('CONNECTED (handshake ' . round((microtime(true) - $start) * 1000) . ' ms)');

				$conn->on('message', function ($message): void {
					$this->onMessage((string) $message);
				});

				$conn->on('pong', function (): void {
					$this->pongReceived = true;
					$this->statPongs++;
					if ($this->pingSentAt !== null) {
						$rtt = (microtime(true) - $this->pingSentAt) * 1000;
						$this->statRttSumMs += $rtt;
						$this->statRttMaxMs = max($this->statRttMaxMs, $rtt);
						// bezne RTT patri jen do souhrnu; vyrazne zpozdeni je udalost
						if ($rtt > 2000) {
							$this->log('SLOW PONG rtt=' . round($rtt) . ' ms');
						}
					}
				});

				$conn->on('error', function ($e): void {
					$this->log('WS ERROR: ' . $e->getMessage());
				});

				$conn->on('close', function ($code = null, $reason = null): void {
					$uptime = $this->connectedAt !== null ? round(microtime(true) - $this->connectedAt) : 0;
					$this->log(sprintf(
						'CLOSED code=%s reason="%s" uptime=%d s',
						$code === null ? 'null' : (string) $code,
						(string) $reason,
						$uptime,
					));
					$this->cleanupConnection();
					$this->scheduleReconnect();
				});

				$this->pingTimer = $this->loop->addPeriodicTimer(self::PING_INTERVAL, function (): void {
					if ($this->ws === null) {
						return;
					}

					// puvodni chovani pokladny: pong nedorazil do dalsiho ticku
					// -> spojeni je povazovane za mrtve, zadny druhy pokus
					if (!$this->pongReceived) {
						$silent = $this->pingSentAt !== null ? round(microtime(true) - $this->pingSentAt, 1) : 0;
						$this->log('MISSED PONG (silent ' . $silent . ' s) -> closing');
						$this->ws->close();
						return;
					}

					$this->pongReceived = false;
					$this->pingSentAt = microtime(true);
					$this->statPings++;
					$this->ws->send(new Frame(uniqid(), true, Frame::OP_PING));
				});
			},
			function (Exception $e): void {
				$this->log('CONNECT FAILED: ' . $e->getMessage());
				$this->scheduleReconnect();
			},
		);
	}

	private function onMessage(string $raw): void
	{
		$message = json_decode($raw, true);
		if (!is_array($message)) {
			$this->log('MESSAGE (unparsable): ' . $raw);
			return;
		}

		if (isset($message['error'])) {
			$this->log('SERVER ERROR code=' . ($message['error']['code'] ?? '?') . ' message="' . ($message['error']['message'] ?? '') . '"');
			return;
		}

		$op = $message['data']['op'] ?? null;

		if ($op === 'update_connection_state') {
			$this->log('STATE ' . ($message['data']['state'] ?? '?'));
			return;
		}

		// bezna appova zprava - potvrdit ack, aby ji server neopakoval donekonecna
		if (isset($message['data']['uuid']) && $this->ws !== null) {
			$this->ws->send(json_encode(['data' => ['op' => 'ack', 'message' => $message['data']['uuid']]]));
		}

		$this->log('MESSAGE op=' . ($op ?? '-') . ': ' . $raw);
	}

	private function cleanupConnection(): void
	{
		if ($this->pingTimer !== null) {
			$this->loop->cancelTimer($this->pingTimer);
			$this->pingTimer = null;
		}
		$this->ws = null;
		$this->connectedAt = null;
	}

	private function scheduleReconnect(): void
	{
		$this->totalReconnects++;
		$this->log('RECONNECT in ' . self::RECONNECT_DELAY . ' s');
		$this->loop->addTimer(self::RECONNECT_DELAY, function (): void {
			$this->connect();
		});
	}

	private function log(string $message): void
	{
		$line = (new DateTimeImmutable())->format('Y-m-d H:i:s.v') . ' ' . $message;
		echo $line . PHP_EOL;
		file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
	}
}

$apiKey = $_ENV['API_KEY'] ?? $_SERVER['API_KEY'] ?? null;
if (!$apiKey) {
	echo 'API key not set (env API_KEY).' . PHP_EOL;
	exit(1);
}

if (empty($argv[1])) {
	echo 'Identifier not set (usage: API_KEY=... php bin/monitor.php <identifier>).' . PHP_EOL;
	exit(1);
}

$identifier = $argv[1];

// stejna konvence ukladani tokenu jako bin/client.php
$tokenFile = __DIR__ . '/../temp/' . $identifier . '.dat';
if (file_exists($tokenFile)) {
	$token = file_get_contents($tokenFile);
} else {
	$token = \ADT\SobitEcr\SobitEcr::generateToken();
	file_put_contents($tokenFile, $token);
}

(new ConnectionMonitor($apiKey, $identifier, $token))->run();
