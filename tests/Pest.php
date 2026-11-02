<?php

use Anthropic\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Swap the Claude client for one that answers with the given Messages API
 * responses, in order. Returns the requests it received.
 *
 * @return ArrayObject<int, array{request: RequestInterface}>
 */
function fakeClaude(ResponseInterface ...$responses): ArrayObject
{
    $history = new ArrayObject;
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));

    app()->instance(Client::class, new Client(
        apiKey: 'test-key',
        requestOptions: ['transporter' => new GuzzleClient(['handler' => $handler]), 'maxRetries' => 0],
    ));

    return $history;
}

/**
 * Build a successful Messages API response with the given content blocks.
 *
 * @param  list<array<string, mixed>>  $content
 */
function claudeReply(array $content, string $stopReason = 'end_turn'): ResponseInterface
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'id' => 'msg_'.fake()->uuid(),
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-opus-5',
        'content' => $content,
        'stop_reason' => $stopReason,
        'stop_sequence' => null,
        'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
    ]));
}

/**
 * Decode the JSON body that was sent to the Messages API.
 *
 * @param  ArrayObject<int, array{request: RequestInterface}>  $history
 * @return array<string, mixed>
 */
function claudeRequest(ArrayObject $history, int $index = 0): array
{
    return json_decode((string) $history[$index]['request']->getBody(), true);
}
