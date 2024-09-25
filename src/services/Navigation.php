<?php

namespace viget\partskit\services;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use viget\partskit\models\NavNode;
use yii\base\Component;
use yii\base\Exception;

/**
 * Navigation service
 */
class Navigation extends Component
{
    /**
     * @return NavNode[]
     * @throws Exception
     */
    public function getNav(): array
    {
        $templatesPath = Craft::$app->getPath()->getSiteTemplatesPath();
        $partsKitFolderName = 'parts-kit'; // self::getConfig('directory');
        $partsPath = $templatesPath . '/' . $partsKitFolderName . '/';

        // Combine and sort all files & directories in the parts kit
        $directories = FileHelper::findDirectories($partsPath);
        $files = FileHelper::findFiles($partsPath);

        $templates = [
            ...$directories,
            ...$files
        ];

        $skipPaths = [
            $partsPath . 'index.twig',
            $partsPath . 'index.html',
        ];

        sort($templates);

        /** @var NavNode[] $result */
        $result = [];
        // Loop through all the paths in the folder.
        // Creating a NavNode object and putting the path to each node in the map.
        foreach ($templates as $templatePath) {

            // Skip ignored paths
            if (in_array($templatePath, $skipPaths)) {
                continue;
            }

            $path = str_replace($partsPath, '', $templatePath);
            $pathParts = explode('/', $path);
            $title = self::_formatTitle(end($pathParts));
            $url = is_file($templatePath)
                ? '/' . $partsKitFolderName . '/' . self::_removeExtension($path)
                : null;

            $result[$path] = new NavNode(
                title: $title,
                path: $path,
                url: $url,
            );
        }

        // Reverse the array, so we can build up child nav nodes into their parents.
        // We remove child nodes as they're added.
        $result = array_reverse($result);

        foreach ($result as $node) {
            $pathParts = explode('/', $node->path);
            $parentPath = implode('/', array_slice($pathParts, 0, -1));
            $parentNode = $result[$parentPath] ?? null;

            if (!$parentNode) {
                continue;
            }

            $parentNode->children[] = $node;
            unset($result[$node->path]);
        }

        // Sort by keys so they're in alpha order
        ksort($result);

        return array_values($result);
    }

    private static function _formatTitle(string $str): string
    {
        $str = self::_removeExtension($str);
        $str = StringHelper::toKebabCase($str);
        $str = StringHelper::humanize($str);
        return str_replace('-', ' ', $str);
    }

    private static function _removeExtension(string $file): string
    {
        $extensions = array_map(function ($extension) {
            return '.' . $extension;
        }, Craft::$app->config->general->defaultTemplateExtensions);

        return str_replace($extensions, '', $file);
    }
}
