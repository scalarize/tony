<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

require 'vendor/autoload.php';

use PhpParser\Node;
use PhpParser\{Parser, ParserFactory};

final class SourceFile
{

	/** global cache of filename => SourceFile object */
	private static $sources = [];

	public static function registerSourceFile(string $fileOrDir)
	{
		if (!file_exists($fileOrDir)) return;

		if (is_dir($fileOrDir)) {
			foreach (scandir($fileOrDir) as $item) {
				if ($item === '.') continue;
				if ($item === '..') continue;
				self::registerSourceFile($fileOrDir . '/' . $item);
			}
		}

		if (is_file($fileOrDir) && strtolower(substr($fileOrDir, -4)) === '.php') {
			self::$sources[$fileOrDir] = new SourceFile($fileOrDir);
		}
	}

	public static function getSourceFile(string $filename, bool $registerIfNotExists = true)
	{
		if (!isset(self::$sources[$filename])) {
			if ($registerIfNotExists) {
				self::registerSourceFile($filename);
			} else {
				return null;
			}
		}
		return self::$sources[$filename];

	}

	public static function getRegisteredSourceFiles()
	{
		return self::$sources;
	}

	protected $filename;

	/** local in-memory cache of lines */
	private $lines = null;

	/** local in-memory cache of content */
	private $content = null;

	/** local in-memory cache of parsed nodes */
	private $nodes = null;

	public function __construct(string $filename)
	{
		$filename = realpath($filename);
		if (empty($filename) || !file_exists($filename) || !is_file($filename)) {
			throw new \Exception('file not exists: ' . $filename);
		}
		$this->filename = $filename;
	}

	public function getFilename()
	{
		return $this->filename;
	}

	public function getContent()
	{
		if ($this->content === null) {
			$this->content = file_get_contents($this->filename);
		}
		return $this->content;
	}

	public function getLines()
	{
		if ($this->lines === null) {
			$this->lines = explode("\n", $this->getContent());
		}
		return $this->lines;
	}

	/**
	 * from and to for human, start from 1
	 */
	public function getCode($lineFrom, $lineTo = -1, $separator = "\n")
	{
		$lines = $this->getLines();
		$index = $lineFrom - 1;
		if ($index < 0) {
			return '';
		}
		$total = count($lines);
		if ($index >= $total) {
			return '';
		}
		$count = 1;
		if ($lineTo > $from) {
			$count = $lineTo - $from;
		}

		return implode($separator, array_slice($lines, $index, $count));
	}

	/**
	 * get nodes by parsing source file
	 */
	public function getParsedNodes()
	{
		if ($this->nodes === null) {
			$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP5); # or PREFER_PHP7, if your code is pure php7
			$this->nodes = $parser->parse($this->getContent());
		}
		return $this->nodes;
	}

}

