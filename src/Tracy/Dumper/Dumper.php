<?php

/**
 * This file is part of the Tracy (https://tracy.nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Tracy;

use Ds;
use Tracy\Dumper\Describer;
use Tracy\Dumper\Exposer;
use Tracy\Dumper\Renderer;


/**
 * Dumps a variable.
 */
class Dumper
{
	public const
		DEPTH = 'maxDepth', // how many nested levels of array/object properties display (defaults to 7)
		TRUNCATE = 'maxLength', // how truncate long strings? (defaults to 150)
		ITEMS = 'maxItems', // how many items in array/object display? (defaults to 100)
		COLLAPSE = 'collapseTop', // collapse top array/object or how big are collapsed? (defaults to 14)
		COLLAPSE_COUNT = 'collapseSub', // how big array/object are collapsed in non-lazy mode? (defaults to 7)
		LOCATION = 'location', // show location string? (defaults to 0)
		OBJECT_EXPORTERS = 'objectExposers', // custom exporters for objects (defaults to Dumper::$objectexporters)
		LAZY = 'lazy', // lazy-loading via JavaScript? true=full, false=none, null=collapsed parts (defaults to null/false)
		LIVE = 'live', // use static $liveSnapshot (used by Bar)
		SNAPSHOT = 'snapshot', // array used for shared snapshot for lazy-loading via JavaScript
		DEBUGINFO = 'debugInfo', // use magic method __debugInfo if exists (defaults to false)
		KEYS_TO_HIDE = 'keysToHide', // sensitive keys not displayed (defaults to [])
		SCRUBBER = 'scrubber', // detects sensitive keys not to be displayed
		THEME = 'theme', // color theme (defaults to light)
		HASH = 'hash'; // show object and reference hashes (defaults to true)

	public const
		LOCATION_CLASS = 0b0001, // shows where classes are defined
		LOCATION_SOURCE = 0b0011, // additionally shows where dump was called
		LOCATION_LINK = self::LOCATION_SOURCE; // deprecated

	public const
		HIDDEN_VALUE = Describer::HIDDEN_VALUE;

	/** @var Dumper\Value[] */
	public static array $liveSnapshot = [];

	public static ?array $terminalColors = [
		'bool' => '1;33',
		'null' => '1;33',
		'number' => '1;32',
		'string' => '1;36',
		'array' => '1;31',
		'public' => '1;37',
		'protected' => '1;37',
		'private' => '1;37',
		'dynamic' => '1;37',
		'virtual' => '1;37',
		'object' => '1;31',
		'resource' => '1;37',
		'indent' => '1;30',
	];

	public static array $resources = [
		'stream' => 'stream_get_meta_data',
		'stream-context' => 'stream_context_get_options',
		'curl' => 'curl_getinfo',
	];

	public static array $objectExporters = [
		\Closure::class => [Exposer::class, 'exposeClosure'],
		\UnitEnum::class => [Exposer::class, 'exposeEnum'],
		\ArrayObject::class => [Exposer::class, 'exposeArrayObject'],
		\SplFileInfo::class => [Exposer::class, 'exposeSplFileInfo'],
		\SplObjectStorage::class => [Exposer::class, 'exposeSplObjectStorage'],
		\__PHP_Incomplete_Class::class => [Exposer::class, 'exposePhpIncompleteClass'],
		\DOMNode::class => [Exposer::class, 'exposeDOMNode'],
		\DOMNodeList::class => [Exposer::class, 'exposeDOMNodeList'],
		\DOMNamedNodeMap::class => [Exposer::class, 'exposeDOMNodeList'],
		Ds\Collection::class => [Exposer::class, 'exposeDsCollection'],
		Ds\Map::class => [Exposer::class, 'exposeDsMap'],
	];

	private Describer $describer;
	private Renderer $renderer;


	/**
	 * Dumps variable to the output.
	 * @return mixed  variable
	 */
	public static function dump($var, array $options = []): mixed
	{
		if (Helpers::isCli()) {
			$useColors = self::$terminalColors && Helpers::detectColors();
			$dumper = new self($options);
			fwrite(STDOUT, $dumper->asTerminal($var, $useColors ? self::$terminalColors : []));

		} elseif (preg_match('#^Content-Type: (?!text/html)#im', implode("\n", headers_list()))) { // non-html
			echo self::toText($var, $options);

		} else { // html
			$options[self::LOCATION] ??= true;
			self::renderAssets();
			echo self::toHtml($var, $options);
		}

		return $var;
	}


	/**
	 * Dumps variable to HTML.
	 */
	public static function toHtml($var, array $options = [], $key = null): string
	{
		return (new self($options))->asHtml($var, $key);
	}


	/**
	 * Dumps variable to plain text.
	 */
	public static function toText($var, array $options = []): string
	{
		return (new self($options))->asTerminal($var);
	}


	/**
	 * Dumps variable to x-terminal.
	 */
	public static function toTerminal($var, array $options = []): string
	{
		return (new self($options))->asTerminal($var, self::$terminalColors);
	}


	/**
	 * Renders <script> & <style>
	 */
	public static function renderAssets(): void
	{
		static $sent;
		if (Debugger::$productionMode === true || $sent) {
			return;
		}

		$sent = true;

		$nonce = Helpers::getNonce();
		$nonceAttr = $nonce ? ' nonce="' . Helpers::escapeHtml($nonce) . '"' : '';
		$s = file_get_contents(__DIR__ . '/../assets/toggle.css')
			. file_get_contents(__DIR__ . '/assets/dumper-light.css')
			. file_get_contents(__DIR__ . '/assets/dumper-dark.css');
		echo "<style{$nonceAttr}>", str_replace('</', '<\/', Helpers::minifyCss($s)), "</style>\n";

		if (!Debugger::isEnabled()) {
			$s = '(function(){' . file_get_contents(__DIR__ . '/../assets/toggle.js') . '})();'
				. '(function(){' . file_get_contents(__DIR__ . '/../Dumper/assets/dumper.js') . '})();';
			echo "<script{$nonceAttr}>", str_replace(['<!--', '</s'], ['<\!--', '<\/s'], Helpers::minifyJs($s)), "</script>\n";
		}
	}


	private function __construct(
		array $options = [],
		int $maxDepth = 7,
		int $maxLength = 150,
		int $maxItems = 100,
		int|bool $collapseTop = 14,
		int $collapseSub = 7,
		bool $classLocation = false,
		bool $sourceLocation = false,
		array $objectExposers = [],
		array $resourceExposers = [],
		?bool $lazy = null,
		bool $live = false,
		?array &$snapshot = null,
		bool $debugInfo = false,
		array $keysToHide = [],
		?callable $scrubber = null,
		string|false|null $theme = 'light',
		bool $hash = true,
	) {
		if ($options) {
			$location = $options[self::LOCATION] ?? 0;
			unset($options['location']);
			$location = $location === true ? ~0 : (int) $location;
			$options['sourceLocation'] = !(~$location & self::LOCATION_SOURCE);
			$options['classLocation'] = !(~$location & self::LOCATION_CLASS);
			return $this->__construct(...$options);
		}

		$describer = $this->describer = new Describer;
		$describer->maxDepth = $maxDepth ?? $describer->maxDepth;
		$describer->maxLength = $maxLength ?? $describer->maxLength;
		$describer->maxItems = $maxItems ?? $describer->maxItems;
		$describer->debugInfo = $debugInfo ?? $describer->debugInfo;
		$describer->scrubber = $scrubber ?? $describer->scrubber;
		$describer->keysToHide = array_flip(array_map('strtolower', $keysToHide));
		$describer->resourceExposers = $resourceExposers + self::$resources;
		$describer->objectExposers = $objectExposers + self::$objectExporters;
		$describer->location = $classLocation || $sourceLocation; // asi neni ekvivalentni
		if ($live) {
			$tmp = &self::$liveSnapshot;
		} elseif (isset($snapshot)) {
			$tmp = &$snapshot;
		}

		if (isset($tmp)) {
			$tmp[0] ??= [];
			$tmp[1] ??= [];
			$describer->snapshot = &$tmp[0];
			$describer->references = &$tmp[1];
		}

		$renderer = $this->renderer = new Renderer;
		$renderer->collapseTop = $collapseTop ?? $renderer->collapseTop;
		$renderer->collapseSub = $collapseSub ?? $renderer->collapseSub;
		$renderer->collectingMode = isset($snapshot) || $live;
		$renderer->lazy = $renderer->collectingMode
			? true
			: ($lazy ?? $renderer->lazy);
		$renderer->sourceLocation = $sourceLocation;
		$renderer->classLocation = $classLocation;
		$renderer->theme = ($theme ?? $renderer->theme) ?: null;
		$renderer->hash = $hash;
	}


	/**
	 * Dumps variable to HTML.
	 */
	private function asHtml($var, $key = null): string
	{
		if ($key === null) {
			$model = $this->describer->describe($var);
		} else {
			$model = $this->describer->describe([$key => $var]);
			$model->value = $model->value[0][1];
		}

		return $this->renderer->renderAsHtml($model);
	}


	/**
	 * Dumps variable to x-terminal.
	 */
	private function asTerminal($var, array $colors = []): string
	{
		$model = $this->describer->describe($var);
		return $this->renderer->renderAsText($model, $colors);
	}


	public static function formatSnapshotAttribute(array &$snapshot): string
	{
		$res = "'" . Renderer::jsonEncode($snapshot[0] ?? []) . "'";
		$snapshot = [];
		return $res;
	}
}
