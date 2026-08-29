<?php

declare(strict_types=1);

namespace Bhitti\Http;

use Bhitti\View\Twig;
use Bhitti\View\View;

class Response
{
    protected int $status = 200;
    protected array $headers = [];
    protected string $content = '';

    public function __construct(string $content = '', int $status = 200)
    {
        $this->content = $content;
        $this->status = $status;
    }

    public function header(string $key, string $value): static
    {
        $this->headers[$key] = $value;

        return $this;
    }

    public function headers(array $headers): static
    {
        foreach ($headers as $key => $value) {
            $this->header($key, $value);
        }

        return $this;
    }

    /**
     * Set the HTTP status code.
     *
     * When chained with json(), html(), view(), or twig(),
     * call status() after the renderer unless a status is passed
     * directly to the renderer. Renderer methods default to 200
     * and will overwrite a status set before them.
     */
    public function status(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function json(mixed $data, int $status = 200): static
    {
        $this->content = json_encode($data, JSON_THROW_ON_ERROR);
        $this->status = $status;
        $this->headers['Content-Type'] ??= 'application/json; charset=utf-8';

        return $this;
    }

    public function html(string $content, int $status = 200): static
    {
        $this->content = $content;
        $this->status = $status;
        $this->headers['Content-Type'] ??= 'text/html; charset=utf-8';

        return $this;
    }

    public function view(string $view, array $data = [], int $status = 200): static
    {
        return $this->html((new View())->render($view, $data), $status);
    }

    public function twig(string $view, array $data = [], int $status = 200): static
    {
        return $this->html((new Twig())->render($view, $data), $status);
    }

    public function redirect(string $url = '', int $status = 302, array $headers = []): ResponseRedirect
    {
        return new ResponseRedirect($url, $status, $headers);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $key => $value) {
            header($key . ': ' . $value);
        }

        echo $this->content;
    }
}
