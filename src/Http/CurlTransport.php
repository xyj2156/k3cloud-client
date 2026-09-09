<?php

declare(strict_types=1);

namespace K3Cloud\Http;

use K3Cloud\Exception\TransportException;

/**
 * cURL-backed transport.
 *
 * Response headers are captured through a header callback rather than by
 * slicing the body off a single buffer, so chunked responses and empty bodies
 * are parsed correctly regardless of Content-Length.
 */
final class CurlTransport implements Transport
{
    public function __construct(
        private readonly int $connectTimeout = 15,
        private readonly int $requestTimeout = 120,
        private readonly bool $verifyTls = true,
    ) {
        if (!function_exists('curl_init')) {
            throw new TransportException('The cURL extension (ext-curl) is required but not installed.');
        }
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('Unable to initialise a cURL handle.');
        }

        $headerLines = [];
        $buffer = '';

        curl_setopt_array($handle, [
            CURLOPT_URL            => $request->url,
            CURLOPT_CUSTOMREQUEST  => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->requestTimeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($_ch, $line) use (&$headerLines): int {
                $headerLines[] = $line;

                return strlen($line);
            },
        ]);

        if ($request->body !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
        }

        $headers = $request->headers();
        if ($request->cookies() !== []) {
            $pairs = [];
            foreach ($request->cookies() as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers['Cookie'] = implode('; ', $pairs);
        }

        $headerList = [];
        foreach ($headers as $name => $value) {
            $headerList[] = $name . ': ' . $value;
        }
        if ($headerList !== []) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headerList);
        }

        $result = curl_exec($handle);
        if ($result === false) {
            $message = curl_error($handle);
            $errno = curl_errno($handle);
            curl_close($handle);
            throw new TransportException(sprintf('HTTP request failed (%s): %s', $errno, $message), $errno);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResponse($status, (string) $result, self::parseHeaders($headerLines));
    }

    /**
     * @param list<string> $lines
     *
     * @return array<string,list<string>>
     */
    private static function parseHeaders(array $lines): array
    {
        $parsed = [];
        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            if ($line === '' || !str_contains($line, ':')) {
                continue; // status line / folded headers are ignored
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $parsed[$name][] = trim($value);
        }

        return $parsed;
    }
}
