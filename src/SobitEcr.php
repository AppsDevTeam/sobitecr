<?php

namespace ADT\SobitEcr;

use Nette\Utils\Json;
use Nette\Utils\Random;
use Ratchet\Client\WebSocket;

final class SobitEcr
{
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

				$conn->on('message', function ($message) use ($conn, $onResponse, $onError, $onConnect) {
					$message = Json::decode($message, forceArrays: true);

					if (isset($message['error'])) {
						if ($onError) {
							$onError($message['error']['code'], $message['error']['message']);
						}
						return;
					}

					if (isset($message['data']['op'])) {
						if ($message['data']['op'] === 'connection_established') {
							if ($onConnect) {
								$onConnect();
							}
							$this->sendPendingMessages();
						} elseif ($message['data']['op'] === 'complete_transaction') {
							if ($onResponse) {
								$onResponse($message);
							}
							$conn->close();
						}
					}
				});

				$conn->on('error', function ($e) use ($conn, $onError) {
					if ($onError) {
						$onError(-1, "WebSocket error: " . $e->getMessage());
					}
					$conn->close();
				});
			},
			function (Exception $e) use ($onError) {
				if ($onError) {
					$onError(-1, "Connection unsuccessful ({$e->getMessage()})");
				}
			}
		);
	}

	public function send(string $message, ?callable $onResponse = null, ?callable $onError = null, ?callable $onConnect = null): void
	{
		$this->pendingMessages[] = $message;
		$this->connect($onResponse, $onError, $onConnect);
	}

	private function sendPendingMessages(): void
	{
		if ($this->ws !== null) {
			while ($message = array_shift($this->pendingMessages)) {
				$this->ws->send($message);
			}
		}
	}
}
