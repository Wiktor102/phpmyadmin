<?php

declare(strict_types=1);

namespace PhpMyAdmin\Controllers\Setup;

use Fig\Http\Message\StatusCodeInterface;
use PhpMyAdmin\Config;
use PhpMyAdmin\Console;
use PhpMyAdmin\Controllers\InvocableController;
use PhpMyAdmin\Header;
use PhpMyAdmin\Http\Factory\ResponseFactory;
use PhpMyAdmin\Http\Response;
use PhpMyAdmin\Http\ServerRequest;
use PhpMyAdmin\LanguageManager;
use PhpMyAdmin\Template;
use PhpMyAdmin\Url;

use function __;
use function file_exists;
use function in_array;

use const CONFIG_FILE;

final class MainController implements InvocableController
{
    public function __construct(
        private readonly ResponseFactory $responseFactory,
        private readonly Template $template,
        private readonly Console $console,
    ) {
    }

    public function __invoke(ServerRequest $request): Response
    {
        $config = Config::getInstance();
        if (@file_exists(CONFIG_FILE) && ! $config->config->debug->demo) {
            $response = $this->responseFactory->createResponse(StatusCodeInterface::STATUS_NOT_FOUND);

            return $response->write($this->template->render('error/generic', [
                'lang' => $GLOBALS['lang'] ?? 'en',
                'dir' => LanguageManager::$textDir,
                'error_message' => __('Configuration already exists, setup is disabled!'),
            ]));
        }

        /** @var mixed $pageParam */
        $pageParam = $request->getQueryParam('page');
        $page = in_array($pageParam, ['form', 'config', 'servers'], true) ? $pageParam : 'index';

        $response = $this->responseFactory->createResponse();
        $header = new Header($this->template, $this->console, $config);
        foreach ($header->getHttpHeaders() as $name => $value) {
            // Sent security-related headers
            $response = $response->withHeader($name, $value);
        }

        if ($page === 'form') {
            return $response->write((new FormController($this->template))($request));
        }

        if ($page === 'config') {
            return $response->write((new ConfigController($this->template))($request));
        }

        if ($page === 'servers') {
            if ($request->getQueryParam('mode') === 'remove' && $request->isPost()) {
                (new ServerDestroyController($this->template))($request);
                $response = $response->withStatus(StatusCodeInterface::STATUS_FOUND);

                return $response->withHeader(
                    'Location',
                    '../setup/index.php' . Url::getCommonRaw(['route' => '/setup']),
                );
            }

            return $response->write((new ServersController($this->template))($request));
        }

        return $response->write((new HomeController($this->template))($request));
    }
}
