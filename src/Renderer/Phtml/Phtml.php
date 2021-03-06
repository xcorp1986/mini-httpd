<?php

/**
 * MIT License
 *
 * Copyright (c) 2018 Samuel CHEMLA
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace PhpBg\MiniHttpd\Renderer\Phtml;

use PhpBg\MiniHttpd\HttpException\HttpException;
use PhpBg\MiniHttpd\Middleware\ContextTrait;
use PhpBg\MiniHttpd\Renderer\RendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use function GuzzleHttp\Psr7\stream_for;

/**
 * HTML Renderer, based on phtml templates
 */
class Phtml implements RendererInterface
{
    use ContextTrait;

    protected $defaultLayout;

    protected $logger;

    public function __construct(string $defaultLayout = null, LoggerInterface $logger = null)
    {
        $this->defaultLayout = $defaultLayout;
        $this->logger = $logger;
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array $options
     *   Expected options are:
     *     - viewFilePath Path to the phtml template to render (optional)
     *     - layoutFilePath Path to the phtml layout file to render (optional)
     * @param mixed $data
     * @return ResponseInterface
     */
    public function render(ServerRequestInterface $request, ResponseInterface $response, array $options, $data): ResponseInterface
    {
        try {
            $viewFilePath = $options['viewFilePath'] ?? null;
            $layoutFilePath = $options['layoutFilePath'] ?? $this->defaultLayout ?? null;
            $content = $this->getContent($data, $options, $viewFilePath, $layoutFilePath);
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->warning('', ['exception' => $e]);
            }
            return $this->renderException($request, $response, $options, $e);
        }

        $response = $response->withHeader('Content-Type', 'text/html');
        $response = $response->withBody(stream_for($content));
        return $response;
    }

    public function renderException(ServerRequestInterface $request, ResponseInterface $response, array $options, \Exception $exception): ResponseInterface
    {
        $response = $response->withHeader('Content-Type', 'text/html');
        if ($exception instanceof HttpException) {
            $response = $response->withStatus($exception->httpStatus);
            $response = $response->withBody(stream_for($exception->getMessage()));
        } else {
            $response = $response->withStatus(500);
            $response = $response->withBody(stream_for("Internal server error"));
        }

        return $response;
    }

    protected function getContent($data, array $options, string $viewFilePath = null, string $layoutFilePath = null)
    {
        $content = '';

        //Configure view
        if ($viewFilePath) {
            $viewTemplate = new Template($viewFilePath, $data);
            $content .= $viewTemplate->getContent();
        }

        //Configure Layout
        if ($layoutFilePath) {
            if (! empty($options['bottomScripts'])) {
                $options['bottomScripts'] = array_unique($options['bottomScripts']);
            }
            if (! empty($options['headCss'])) {
                $options['headCss'] = array_unique($options['headCss']);
            }
            $layoutData = [
                'content' => $content,
                'inlineCss' => $options['inlineCss'] ?? null,
                'inlineScripts' => $options['inlineScripts'] ?? null,
                'bottomScripts' => $options['bottomScripts'] ?? null,
                'headCss' => $options['headCss'] ?? null
            ];
            $layoutTemplate = new Template($layoutFilePath, $layoutData);
            $content = $layoutTemplate->getContent();
        }

        return $content;
    }
}