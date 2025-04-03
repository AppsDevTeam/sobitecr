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
	private const OP_COMPLETE_TRANSACTION = 'complete_transaction';
	private const OP_NOTIFY_GROUP = 'notify_group';
	private const OP_NOTIFY = 'notify';

	const ERROR_BAD_REQUEST = -1;
	const ERROR_TARGET_DEVICE_NOT_CONNECTED = 1;
	const ERROR_SERVER_ERROR = 2;
	const ERROR_TARGET_DEVICE_NOT_SET = 3;
	const ERROR_WRONG_CREDENTIALS = 4;
	const ERROR_WRONG_API_KEY = 5;
	const ERROR_DUPLICATE_CONNECTION = 6;

	public static bool $debug = false;

	private string $apiKey;
	private ?string $identifier;
	private ?string $token;

	private ?LoopInterface $loop = null;
	private ?WebSocket $ws = null;

	private ?TimerInterface $ackTimer;
	private bool $async;
	private bool $closeAfterAck;
	private ?string $op;
	private array $opParams;
	private array $pendingMessages;
	private ?TimerInterface $pingTimer;
	private bool $pongReceived;
	private bool $reconnect;

	public function __construct(string $apiKey, ?string $identifier = null, ?string $token = null, bool $async = false)
	{
		$this->apiKey = $apiKey;
		$this->identifier = $identifier;
		$this->token = $token;
		$this->async = $async;
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

		$headers = [
			'X-Api-Key' => $this->apiKey,
		];
		if ($this->identifier && $this->token) {
			$headers['Authorization'] = 'Bearer ' . base64_encode($this->identifier . ' ' . $this->token);
		}

		\Ratchet\Client\connect('wss://connect.sobitecr.com', [], $headers, $this->loop)->then(
			function (WebSocket $conn) use ($onResponse, $onError, $onConnect) {
				$this->log('onConnect');

				$this->ws = $conn;
				$this->pongReceived = true;

				$this->ws->on('message', function ($message) use ($onResponse, $onError, $onConnect) {
					$this->log('onMessage');

					$this->log('Message: ' . $message);

					try {
						$message = Json::decode($message, true);
					} catch (JsonException $e) {
						$this->error($onError, -1, 'Error parsing message');
						return;
					}

					if (isset($message['error'])) {
						$this->error($onError, $message['error']['code'], $message['error']['message']);
						return;
					}

					if (isset($message['data']['op']) && $message['data']['op'] === 'update_connection_state') {
						$onConnect && $onConnect();
						$this->sendPendingMessages();
						return;
					}

					if (isset($message['data']['op']) && $message['data']['op'] === 'ack') {
						$onResponse && $onResponse();
						$this->loop->cancelTimer($this->ackTimer);
						if ($this->closeAfterAck) {
							$this->close();
							return;
						}
					}

					// ack
					if (isset($message['data']['uuid'])) {
						$this->ws->send(Json::encode(['data' => ['op' => 'ack', 'message' => $message['data']['uuid']]]));
					}

					if (
						$message['data']['op'] === self::OP_COMPLETE_TRANSACTION
						&& in_array($this->op, [self::OP_START_TRANSACTION, self::OP_CANCEL_TRANSACTION])
						&& $this->opParams['transaction_id'] === $message['data']['transaction_id']
					) {
						$onResponse && $onResponse($message['data']['message']);
						// we need this because otherwise "ack" wouldn't be sent
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

				$this->ws->on('close', function () use ($onResponse, $onError, $onConnect) {
					$this->log('onClose');
					if ($this->loop) {
						if ($this->reconnect) {
							$this->loop->cancelTimer($this->pingTimer);
							$this->ws = null;
							sleep(1);
							$this->connect($onResponse, $onError, $onConnect);
						} else {
							$this->close();
						}
					}
				});
			},
			function (Exception $e) use ($onError) {
				$this->error($onError, -1, 'Connection unsuccessful (' . $e->getMessage() . ')');
			}
		);

		if (!$this->async) {
			$this->loop->run();
		}
	}

	public function send(string $op, ?string $message = null, ?callable $onResponse = null, ?callable $onError = null, ?callable $onConnect = null): void
	{
		$this->pendingMessages[] = ['data' => ['op' => $op, 'message' => $message]];
		$this->connect($onResponse, $onError, $onConnect);
	}

	public function startTransaction(string $message, string $transactionId, ?callable $onResponse = null, ?callable $onError = null, ?callable $onConnect = null): void
	{
		$this->op = self::OP_START_TRANSACTION;
		$this->opParams['transaction_id'] = $transactionId;
		$this->pendingMessages[] = ['data' => ['op' => $this->op, 'transaction_id' => $transactionId, 'message' => $message]];
		$this->connect($onResponse, $onError, $onConnect);
	}

	public function cancelTransaction(string $message, string $transactionId, ?callable $onResponse = null, ?callable $onError = null, ?callable $onConnect = null): void
	{
		$this->op = self::OP_CANCEL_TRANSACTION;
		$this->opParams['transaction_id'] = $transactionId;
		$this->pendingMessages[] = ['data' => ['op' => $this->op, 'transaction_id' => $transactionId, 'message' => $message]];
		$this->connect($onResponse, $onError, $onConnect);
	}

	public function notify(string $message, string $deviceId, ?callable $onResponse = null, ?callable $onError = null, ?callable $onConnect = null): void
	{
		$this->op = self::OP_NOTIFY;
		$this->pendingMessages[] = ['data' => ['op' => $this->op, 'device_id' => $deviceId, 'message' => $message]];
		$this->connect($onResponse, $onError, $onConnect);
	}

	public function notifyGroup(string $message, string $group, ?string $senderId = null, ?callable $onResponse = null, ?callable $onError = null, ?callable $onConnect = null): void
	{
		$this->op = self::OP_NOTIFY_GROUP;
		$this->pendingMessages[] = ['data' => ['op' => $this->op, 'group' => $group, 'device_id' => $senderId, 'message' => $message]];
		$this->connect($onResponse, $onError, $onConnect);
	}

	private function sendPendingMessages(): void
	{
		if ($this->ws !== null) {
			while ($message = array_shift($this->pendingMessages)) {
				if ($message['data']['op'] === SobitEcr::OP_NOTIFY_GROUP || $message['data']['op'] === self::OP_NOTIFY) {
					$message['data']['uuid'] = Uuid::uuid4()->toString();
					$this->closeAfterAck = true;
				}
				if ($this->closeAfterAck) {
					$this->ackTimer = $this->loop->addPeriodicTimer(1, function () use ($message) {
						$this->ws->send(Json::encode($message));
					});
				}
				$this->ws->send(Json::encode($message));
			}
		}
	}

	private function error(?callable $onError, int $code, string $message): void
	{
		$onError && $onError($code, $message);
		$this->reconnect = false;
		$this->close();
	}

	private function close(): void
	{
		$this->log('close');
		if ($this->loop) {
			if ($this->pingTimer) {
				$this->loop->cancelTimer($this->pingTimer);
			}
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
		$this->op = null;
		$this->opParams = [];
		$this->pendingMessages = [];
		$this->pingTimer = null;
		$this->pongReceived = false;
		$this->reconnect = true;
	}
}
