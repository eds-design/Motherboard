<?php

final class EdsWarrantyCardAssetController {
    private const ASSETS = [
        'quill.js' => ['quill/quill.js', 'text/javascript; charset=UTF-8'],
        'quill.js.map' => ['quill/quill.js.map', 'application/json; charset=UTF-8'],
        'quill.snow.css' => ['quill/quill.snow.css', 'text/css; charset=UTF-8'],
        'quill.snow.css.map' => ['quill/quill.snow.css.map', 'application/json; charset=UTF-8'],
        'warranty-terms-editor.js' => ['warranty-terms-editor.js', 'text/javascript; charset=UTF-8'],
        'warranty-terms-editor.css' => ['warranty-terms-editor.css', 'text/css; charset=UTF-8'],
        'warranty-card-document.css' => ['warranty-card-document.css', 'text/css; charset=UTF-8'],
        'warranty-card-pagination.js' => ['warranty-card-pagination.js', 'text/javascript; charset=UTF-8'],
        'warranty-card-registry.css' => ['warranty-card-registry.css', 'text/css; charset=UTF-8'],
        'warranty-card-nav.js' => ['warranty-card-nav.js', 'text/javascript; charset=UTF-8'],
    ];

    public function show($file): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
            http_response_code(405);
            header('Allow: GET, HEAD');
            return;
        }

        if (!is_string($file) || !isset(self::ASSETS[$file])) {
            http_response_code(404);
            return;
        }

        [$relativePath, $contentType] = self::ASSETS[$file];
        $path = dirname(__DIR__) . '/assets/' . $relativePath;
        if (!is_file($path)) {
            http_response_code(404);
            return;
        }

        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: public, max-age=31536000, immutable');
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            readfile($path);
        }
    }
}
