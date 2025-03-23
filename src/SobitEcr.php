<?php

namespace ADT\SobitEcr;

use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Nette\Utils\Random;
use Ratchet\Client\WebSocket;

final class SobitEcr
{
	const OP_START_TRANSACTION = 'start_transaction';
	const OP_CANCEL_TRANSACTION = 'cancel_transaction';
	const OP_COMPLETE_TRANSACTION = 'complete_transaction';
	const OP_NOTIFY_GROUP = 'notify_group';

	private string $apiKey;
	private string $identifier;
	private string $token;
	private ?WebSocket $ws = null;
	private array $pendingMessages = [];

	public function __construct(string $apiKey, string $identifier, string $token)
	{
		$this->apiKey = $apiKey;
		$this->identifier = $identifier;
		$this->token = $token;
	}

	public static function generateToken(): string
	{
		return Random::generate(64);
	}

	private function connect(?callable $onResponse, ?callable $onError, ?callable $onConnect): void
	{
		if ($this->ws !== null) {
			$this->sendPendingMessages();
			return;
		}

		$authHeader = base64_encode($this->identifier . ' ' . $this->token);

		\Ratchet\Client\connect('wss://connect.sobitecr.com', [], [
			'X-Api-Key' => $this->apiKey,
			'Authorization' => 'Bearer ' . $authHeader,
		])->then(
			function (WebSocket $conn) use ($onResponse, $onError, $onConnect) {
				$this->ws = $conn;

				$this->ws->on('message', function ($message) use ($onResponse, $onError, $onConnect) {
					try {
						$message = Json::decode($message, forceArrays: true);
					} catch (JsonException $e) {
						if ($onError) {
							$onError(-1, 'Error parsing message');
						}
						$this->close();
						return;
					}

					if (isset($message['error'])) {
						if ($onError) {
							$onError($message['error']['code'], $message['error']['message']);
						}
						$this->close();
						return;
					}

					if (isset($message['data']['op']) && $message['data']['op'] === 'connection_established') {
						if ($onConnect) {
							$onConnect();
						}
						$this->sendPendingMessages();
						return;
					}

					if ($onResponse) {
						$onResponse($message['data']['message'], $message['data']['op'] ?? null);
					}

					$this->close();
				});

				$this->ws->on('error', function ($e) use ($onError) {
					if ($onError) {
						$onError(-1, "WebSocket error: " . $e->getMessage());
					}
					$this->ws->close();
					$this->ws = null;
				});
			},
			function (Exception $e) use ($onError) {
				if ($onError) {
					$onError(-1, "Connection unsuccessful ({$e->getMessage()})");
				}
			}
		);
	}

	public function send(string $op, ?string $message = null,  ?callable $onResponse = null, ?callable $onError = null, ?callable $onConnect = null): void
	{
		$this->pendingMessages[] = ['data' => ['op' => $op, 'message' => $message]];
		$this->connect($onResponse, $onError, $onConnect);
	}

	private function sendPendingMessages(): void
	{
		if ($this->ws !== null) {
			while ($message = array_shift($this->pendingMessages)) {
				$this->ws->send(Json::encode($message));
			}
		}
	}

	private function close(): void
	{
		$this->ws->close();
		$this->ws = null;
	}
}
