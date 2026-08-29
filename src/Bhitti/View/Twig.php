<?php

declare(strict_types=1);

namespace Bhitti\View;

use RuntimeException;

final class Twig
{
    private static ?object $engine = null;

    public function render(string $view, array $data = []): string
    {
        $view = trim($view);

        if ($view === '' || str_contains($view, '..')) {
            throw new RuntimeException("Invalid Twig view [{$view}].");
        }

        $path = str_ends_with($view, '.twig')
            ? $view
            : str_replace('.', '/', $view) . '.html.twig';

        return self::engine()->render($path, $data);
    }

    private static function engine(): object
    {
        if (self::$engine !== null) {
            return self::$engine;
        }

        if (!class_exists(\Twig\Environment::class)) {
            throw new RuntimeException(
                'Twig is not installed. Run: "composer require twig/twig"'
            );
        }

        $loader = new \Twig\Loader\FilesystemLoader(
            ROOT_PATH . '/resources/views'
        );

        $engine = new \Twig\Environment($loader, [
            'cache' => STORAGE_PATH . '/cache/twig',
            'debug' => (bool) config('app.debug', false),
        ]);

        $engine->addFunction(new \Twig\TwigFunction(
            'csrf_token',
            fn (): string => csrf_token()
        ));

        $engine->addFunction(new \Twig\TwigFunction(
            'csrf_field',
            fn (): string => '<input type="hidden" name="_csrf" value="'
            . e(csrf_token()) . '">',
            ['is_safe' => ['html']]
        ));

        return self::$engine = $engine;
    }
}
