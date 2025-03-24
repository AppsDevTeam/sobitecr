<?php

namespace ADT\SobitEcr;

use Exception;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Nette\Utils\Random;
use Ramsey\Uuid\Uuid;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class SobitEcr
{
	const OP_START_TRANSACTION = 'start_transaction';
	const OP_CANCEL_TRANSACTION = 'cancel_transaction';
	const OP_COMPLETE_TRANSACTION = 'complete_transaction';
	const OP_NOTIFY_GROUP = 'notify_group';

	public static bool $debug = false;

	private string $apiKey;
	private string $identifier;
	private string $token;

	private ?LoopInterface $loop = null;
	private ?WebSocket $ws = null;

	private ?TimerInterface $ackTimer;
	private bool $closeAfterAck;
	private array $pendingMessages;
	private ?TimerInterface $pingTimer;
	private bool $pongReceived;

	public function __construct(string $apiKey, string $identifier, string $token)
	{
		$this->apiKey = $apiKey;
		$this->identifier = $identifier;
		$this->token = $token;
		$this->setDefaults();
	}

	public static function generateToken(): string
	{
		return Random::generate(64);
	}

	private function connect(?callable $onResponse, ?callable $onError, ?callable $onConnect): void
	{
		$this->log('connect');

		if ($this->ws !== null) {
			$this->sendPendingMessages();
			return;
		}

		$this->loop = Loop::get();

		\Ratchet\Client\connect('wss://connect.sobitecr.com', [], [
			'X-Api-Key' => $this->apiKey,
			'Authorization' => 'Bearer ' . base64_encode($this->identifier . ' ' . $this->token),
		], $this->loop)->then(
			function (WebSocket $conn) use ($onResponse, $onError, $onConnect) {
				$this->log('onConnect');

				$this->ws = $conn;
				$this->pongReceived = true;

				$this->ws->on('message', function ($message) use ($onResponse, $onError, $onConnect) {
					$this->log('onMessage');

					$this->log('Message: ' . $message);

					try {
						$message = Json::decode($message, forceArrays: true);
					} catch (JsonException) {
						$this->error($onError, -1, 'Error parsing message');
						return;
					}

					if (isset($message['error'])) {
						$this->error($onError, $message['error']['code'], $message['error']['message']);
						return;
					}

					if (isset($message['data']['op']) && $message['data']['op'] === 'connection_established') {
						if ($onConnect) {
							$onConnect();
						}
						$this->sendPendingMessages();
						return;
					}

					if (isset($message['data']['op']) && $message['data']['op'] === 'ack') {
						$this->loop->cancelTimer($this->ackTimer);
						if ($this->closeAfterAck) {
							$this->close();
							return;
						}
					}

					if (isset($message['data']['uuid'])) {
						$this->ws->send(Json::encode(['data' => ['op' => 'ack', 'message' => $message['data']['uuid']]]));
					}

					if ($onResponse) {
						if ($onResponse($message['data']['message'], $message['data']['op'] ?? null)) {
							$this->loop->addTimer(0.001, function () {
								$this->close();
							});
						}
					} else {
						$this->loop->addTimer(0.001, function () {
							$this->close();
						});
					}
				});

				$this->ws->on('error', function ($e) use ($onError) {
					$this->error($onError, -1, "WebSocket error: " . $e->getMessage());
				});

				$conn->on('pong', function() {
					$this->log('pong');
					$this->pongReceived = true;
				});

				$this->pingTimer = $this->loop->addPeriodicTimer(1, function () use ($onResponse, $onError, $onConnect) {
					if (!$this->pongReceived) {
						$this->log('pong not received');
						$this->ws->close();
						return;
					}
					$this->log('ping');
					$this->pongReceived = false;
					$this->ws->send(new Frame(uniqid(), true, Frame::OP_PING));
				});

				$this->ws->on('close', function (int $code, string $reason) use ($onResponse, $onError, $onConnect) {
					if ($this->loop) {
						$this->log('onClose');
						$this->loop->cancelTimer($this->pingTimer);
						$this->ws = null;
						sleep(10);
						$this->connect($onResponse, $onError, $onConnect);
					}
				});
			},
			function (Exception $e) use ($onError) {
				$this->error($onError, -1, 'Connection unsuccessful (' . $e->getMessage() . ')');
			}
		);
	}

	public function send(string $op, ?string $message = null, ?callable $onResponse = null, ?callable $onError = null, ?callable $onConnect = null): void
	{
		$this->pendingMessages[] = ['data' => ['op' => $op, 'message' => $message]];
		$this->connect($onResponse, $onError, $onConnect);
	}

	private function sendPendingMessages(): void
	{
		if ($this->ws !== null) {
			while ($message = array_shift($this->pendingMessages)) {
				if ($message['data']['op'] === SobitEcr::OP_NOTIFY_GROUP) {
					$message['data']['uuid'] = Uuid::uuid4()->toString();
					$this->closeAfterAck = true;
				}
				if ($this->closeAfterAck) {
					$this->ackTimer = $this->loop->addTimer(1, function () use ($message) {
						$this->ws->send(Json::encode($message));
					});
				}
				$this->ws->send(Json::encode($message));
			}
		}
	}

	private function error(?callable $onError, int $code, string $message): void
	{
		if ($onError) {
			if ($onError($code, $message)) {
				$this->close();
			}
		} else {
			$this->close();
		}
	}

	private function close(): void
	{
		$this->log('close');
		if ($this->loop) {
			$this->loop->cancelTimer($this->pingTimer);
			$this->loop->stop();
			$this->loop = null;
		}
		if ($this->ws) {
			$this->ws->close();
			$this->ws = null;
		}
		$this->setDefaults();
	}

	private function log(string $message): void
	{
		if (self::$debug) {
			echo $message . PHP_EOL;
		}
	}

	private function setDefaults(): void
	{
		$this->ackTimer = null;
		$this->closeAfterAck = false;
		$this->pendingMessages = [];
		$this->pingTimer = null;
		$this->pongReceived = false;
	}
}
