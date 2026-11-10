<?php

namespace Tests\Feature\Notion;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * laravel/mcp's OAuth client hardcodes connectTimeout(2) on every OAuth
 * request (vendor InteractsWithOAuthEndpoints::oAuthRequest) and the Connect /
 * Callback routes have no try/catch — so a slow TLS handshake to
 * mcp.notion.com escaped as an unhandled cURL error 28 (live 2026-11-10).
 * Two guards: a scoped Guzzle global middleware that raises the connect phase
 * for every request to the Notion MCP host, and an exception renderer that
 * turns any remaining connection failure on the OAuth routes into a redirect
 * back to NotionSettings (the same shape the OAuthException renderer already
 * gives denied-consent / expired-state failures).
 */
class McpOauthConnectFailureTest extends TestCase
{
    public function test_requests_to_the_notion_mcp_host_connect_within_ten_seconds(): void
    {
        $captured = [];
        $handler = function ($request, array $options) use (&$captured) {
            $captured = $options;

            return new Response(200);
        };
        foreach (array_reverse(Http::getGlobalMiddleware()) as $middleware) {
            $handler = $middleware($handler);
        }

        // The vendored OAuth client sends connect_timeout(2) — the layer must
        // hand the wire ten seconds instead.
        $handler(new Request('POST', 'https://mcp.notion.com/token'), ['connect_timeout' => 2, 'timeout' => 5]);
        $this->assertSame(10, $captured['connect_timeout']);
        // The request's own total timeout passes through untouched.
        $this->assertSame(5, $captured['timeout']);

        // No blast radius: other hosts keep the options they were given.
        $captured = [];
        $handler(new Request('POST', 'https://api.openai.com/v1/chat'), ['connect_timeout' => 2]);
        $this->assertSame(2, $captured['connect_timeout']);
    }

    public function test_a_connect_failure_on_the_oauth_routes_renders_a_redirect_back_to_settings(): void
    {
        $request = HttpRequest::create('https://techrisk.test/mcp/oauth/notion/callback?code=x&state=y');

        $response = app(ExceptionHandler::class)->render(
            $request,
            new ConnectionException('cURL error 28: Connection timed out after 2002 milliseconds for https://mcp.notion.com/token'),
        );

        // A friendly redirect to the settings page (notification included) —
        // not the raw 500 the operator saw on 2026-11-10.
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(url('/admin/notion-settings'), $response->headers->get('Location'));
    }
}
