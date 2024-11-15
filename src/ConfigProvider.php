<?php

declare(strict_types=1);

namespace ThemeManager;

use Laminas\I18n\Translator\Loader\PhpArray;
use Mezzio\LaminasView\LaminasViewRenderer;

/**
 * The configuration provider for the ThemeManager module
 *
 * @see https://docs.laminas.dev/laminas-component-installer/
 */
class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
            'translator'   => $this->getTranslatorConfig(),
            SettingsProvider::class => (new SettingsProvider)(),
        ];
    }

    public function getDependencies(): array
    {
        return [
            'factories' => [
                LaminasViewRenderer::class => RendererFactory::class,
            ],
        ];
    }

    public function getPipeConfig(): array
    {
        return [
            // Middleware\AjaxRequestMiddleware::class => [

            // ],
        ];
    }

    public function getTemplates(): array
    {
        return [
            'paths' => [
                'theme-manager' => [__DIR__ . '/../templates/'],
            ],
        ];
    }

    public function getTranslatorConfig(): array
    {
        return [
            'translation_file_patterns' => [ // This is the only config that is needed for 1 translation per file
                [
                    'type'     => PhpArray::class,
                    'filename' => 'en_US.php',
                    'base_dir' => __DIR__ . '/../language',
                    'pattern'  => '%s.php',
                ],
            ],
            'translation_files' => [
                [
                    'type'        => 'PhpArray',
                    'filename'    => __DIR__ . '/../language/en_US.php',
                    'locale'      => 'en_US',
                    'text_domain' => 'default',
                ],
            ],
        ];
    }
}
