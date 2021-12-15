<?php

/**
 * This file is part of the Tracy (https://tracy.nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Tracy;


/**
 * @internal
 */
final class DeferredContent
{
	/** @var string */
	private $requestId;

	/** @var bool */
	private $useSession = false;


	public function __construct()
	{
		$this->requestId = $_SERVER['HTTP_X_TRACY_AJAX'] ?? Helpers::createId();
	}


	public function isAvailable(): bool
	{
		return $this->useSession && session_status() === PHP_SESSION_ACTIVE;
	}


	public function getRequestId(): string
	{
		return $this->requestId;
	}


	public function &getItems(string $key): array
	{
		$items = &$_SESSION['_tracy'][$key];
		$items = (array) $items;
		return $items;
	}


	public function sendAssets(): bool
	{
		$asset = $_GET['_tracy_bar'] ?? null;
		if ($asset === 'js') {
			header('Content-Type: application/javascript; charset=UTF-8');
			header('Cache-Control: max-age=864000');
			header_remove('Pragma');
			header_remove('Set-Cookie');
			$this->sendJsCss();
			return true;
		}

		$this->useSession = session_status() === PHP_SESSION_ACTIVE;
		if (!$this->useSession) {
			return false;
		}

		$this->clean();

		if (is_string($asset) && preg_match('#^content(-ajax)?\.(\w+)$#', $asset, $m)) {
			[, $ajax, $requestId] = $m;
			header('Content-Type: application/javascript; charset=UTF-8');
			header('Cache-Control: max-age=60');
			header_remove('Set-Cookie');
			if (!$ajax) {
				$this->sendJsCss();
			}

			$session = &$_SESSION['_tracy']['bar'][$requestId];
			if ($session) {
				$method = $ajax ? 'loadAjax' : 'init';
				echo "Tracy.Debug.$method(", json_encode($session['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), ');';
				$session = null;
			}

			$session = &$_SESSION['_tracy']['bluescreen'][$requestId];
			if ($session) {
				echo 'Tracy.BlueScreen.loadAjax(', json_encode($session['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), ');';
				$session = null;
			}

			return true;
		}

		if (Helpers::isAjax()) {
			header('X-Tracy-Ajax: 1'); // session must be already locked
		}

		return false;
	}


	private function sendJsCss(): void
	{
		$css = array_map('file_get_contents', array_merge([
			__DIR__ . '/../Bar/assets/bar.css',
			__DIR__ . '/../assets/toggle.css',
			__DIR__ . '/../assets/table-sort.css',
			__DIR__ . '/../assets/tabs.css',
			__DIR__ . '/../Dumper/assets/dumper-light.css',
			__DIR__ . '/../Dumper/assets/dumper-dark.css',
			__DIR__ . '/../BlueScreen/assets/bluescreen.css',
		], Debugger::$customCssFiles));

		echo "'use strict';
(function(){
	var el = document.createElement('style');
	el.setAttribute('nonce', document.currentScript.getAttribute('nonce') || document.currentScript.nonce);
	el.className='tracy-debug';
	el.textContent=" . json_encode(Helpers::minifyCss(implode('', $css))) . ";
	document.head.appendChild(el);})
();\n";

		array_map(function ($file) { echo '(function() {', file_get_contents($file), '})();'; }, [
			__DIR__ . '/../Bar/assets/bar.js',
			__DIR__ . '/../assets/toggle.js',
			__DIR__ . '/../assets/table-sort.js',
			__DIR__ . '/../assets/tabs.js',
			__DIR__ . '/../Dumper/assets/dumper.js',
			__DIR__ . '/../BlueScreen/assets/bluescreen.js',
		]);
		array_map('readfile', Debugger::$customJsFiles);
	}


	public function clean(): void
	{
		if (isset($_SESSION['_tracy'])) {
			foreach ($_SESSION['_tracy'] as &$items) {
				$items = array_slice((array) $items, -10, null, true);
				$items = array_filter($items, function ($item) {
					return isset($item['time']) && $item['time'] > time() - 60;
				});
			}
		}
	}
}
