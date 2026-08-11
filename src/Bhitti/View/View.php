<?php

declare(strict_types=1);

namespace Bhitti\View;

use RuntimeException;
use Throwable;

final class View
{
    private array $frames = [];

    public function render(string $view, array $data = []): string
    {
        $this->frames[] = [
            'layout' => null,
            'sections' => [],
            'activeSection' => null,
        ];

        $index = array_key_last($this->frames);

        try {
            $output = $this->evaluate(
                $this->path($view),
                $data
            );

            $layout = $this->frames[$index]['layout'];

            if ($layout === null) {
                return $output;
            }

            return $this->evaluate(
                $this->path($layout),
                $data
            );
        } finally {
            array_pop($this->frames);
        }
    }

    public function view(string $view, array $data = []): void
    {
        echo $this->render($view, $data);
    }

    public function layout(string $view): void
    {
        $this->frames[$this->currentFrame()]['layout'] = $view;
    }

    public function start(string $name): void
    {
        $index = $this->currentFrame();

        $this->frames[$index]['activeSection'] = $name;

        ob_start();
    }

    public function end(): void
    {
        $index = $this->currentFrame();
        $name = $this->frames[$index]['activeSection'];

        if ($name === null) {
            throw new RuntimeException('No section has been started.');
        }

        $this->frames[$index]['sections'][$name] = (string) ob_get_clean();

        $this->frames[$index]['activeSection'] = null;
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->frames[$this->currentFrame()]
            ['sections'][$name]
            ?? $default;
    }

    public function e(mixed $value): string
    {
        return e($value);
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->e(csrf_token()) . '">';
    }

    private function path(string $view): string
    {
        $view = trim($view);

        if ($view === '' || str_contains($view, '..')) {
            throw new RuntimeException("Invalid view [{$view}].");
        }

        $path = ROOT_PATH . '/resources/views/' . str_replace('.', '/', $view) . '.view.php';

        if (!is_file($path)) {
            throw new RuntimeException("View not found [{$view}].");
        }

        return $path;
    }

    private function evaluate(string $path, array $data): string
    {
        $level = ob_get_level();

        ob_start();

        try {
            extract($data, EXTR_SKIP);
            require $path;

            if (ob_get_level() !== $level + 1) {
                throw new RuntimeException(
                    "Unclosed section while rendering [{$path}]."
                );
            }

            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $exception;
        }
    }

    private function currentFrame(): int
    {
        return array_key_last($this->frames)
            ?? throw new RuntimeException(
                'No active view.'
            );
    }
}