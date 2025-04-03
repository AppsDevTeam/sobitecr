<?php

declare(strict_types = 1);

use ADT\SobitEcr\SobitEcr;
use React\EventLoop\Loop;

require __DIR__ . '/../vendor/autoload.php';

$apiKey = $_ENV['API_KEY'] ?? $_SERVER['API_KEY'] ?? null;
if (!$apiKey) {
	echo "API key not set." . PHP_EOL;
	exit(1);
}

if (empty($argv[1])) {
	echo "Identifier not set." . PHP_EOL;
	exit(1);
}

$identifier = $argv[1];

$file = 'temp/' . $identifier . '.dat';
if (file_exists($file)) {
	$token = file_get_contents($file);
} else {
	$token = SobitEcr::generateToken();
	file_put_contents($file, $token);
}

SobitEcr::$debug = true;

$sobitEcr = new SobitEcr($apiKey, $identifier, $token);
$loop = Loop::get();

echo "Enter messages to send (ctrl+c to quit):\n";
$loop->addReadStream(STDIN, function () use ($sobitEcr, $loop) {
	$params = explode(' ', trim(fgets(STDIN)));
	$op = array_shift($params);
	if ($op === 'start_transaction') {
		$sobitEcr->startTransaction(
			json_encode([
				'Amount' => '59.0',
				'CurrencyCode' => '203',
				'Operation' => 'CP',
				'TransactionID' => $params[0],
				'InvNumber' => '2532000001'
			]),
			$params[0],
			function (string $message): void {
				onResponse($message);
			},
			function (int $code, string $message): void {
				onError($code, $message);
			},
			function (): void {
				echo "Connection established.\n";
			}
		);
	} elseif ($op === 'cancel_transaction') {
		$sobitEcr->cancelTransaction(
			SobitEcr::OP_CANCEL_TRANSACTION,
			$params[0],
			function(string $message): void {
				onResponse($message);
			},
			function(int $code, string $message): void {
				onError($code, $message);
			}
		);
	} elseif ($op === 'notify_group') {
		$sobitEcr->notifyGroup(
			'update',
			$params[0],
		);
	} else {
		echo "Unknown operation." . PHP_EOL;
	}
});

function onResponse(string $message): void
{
	try {
		$parsedMessage = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
	} catch (JsonException $e) {
		echo "Error parsing message.\n";
	}

	if ($parsedMessage['Result'] === "0") {
		echo "Result: Accepted.\n";
	} elseif ($parsedMessage['Result'] === "1") {
		echo "Result: Declined.\n";
	} elseif ($parsedMessage['Result'] === "9") {
		echo "Result: Aborted.\n";
	} else {
		echo "Unknown result: $parsedMessage[Result].\n";
	}
}

function onError(int $code, string $message): void
{
	echo "Error: $code $message\n";
}