<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

use PhpParser\Node;

class Warning
{

	static $warnings = [];

	static $colors;

	protected $file;
	protected $lines;

	protected $nodeName;
	protected $nodeInfo;
	protected $message;

	public function __construct(string $fileName, string $nodeName, $nodeInfo, string $message = '') {
		$this->file = $fileName;
		$this->nodeName = $nodeName;
		$this->nodeInfo = $nodeInfo;
		$this->message = $message;
	}

	public function getColoredString(string $message, string $foreColor, string $bgColor = null) {
		if (null == self::$colors) {
			self::$colors = new Colors();
		}
		return self::$colors->getColoredString($message, $foreColor, $bgColor);
	}

	public function getShellExpr(bool $verboseMode = false) {
		$sourceInfo = $this->getSourceInfo();
		$ret = sprintf("%s\n  node: %s\n  file: %s\n  line: %d-%d\n  code:  [%s] ...\n",
				$this->getColoredString("WARNING: " . $this->message, 'red'),
				$this->getColoredString($this->nodeName, 'cyan'),
				$this->getColoredString($this->file, 'yellow'),
				$sourceInfo['from'], $sourceInfo['to'],
				$this->getColoredString($sourceInfo['code'], 'cyan')
			);
		if ($verboseMode) {
			$ret .= "  " . var_export($this->nodeInfo->getNode(), true) . "\n";
		}
		return $ret . "\n";
	}

	public function getSourceInfo()
	{
		$ret = ['from' => -1, 'to' => -1, 'code' => ''];
		if ($this->nodeInfo instanceof Node) {
			$ret['from'] = $this->nodeInfo->getAttribute('startLine');
			$ret['to'] = $this->nodeInfo->getAttribute('endLine');
			if (empty($this->lines)) {
				$this->lines = explode("\n", file_get_contents($this->file));
			}
			if (!empty($this->lines) && count($this->lines) >= $ret['from']) {
				$ret['code'] = trim($this->lines[$ret['from'] - 1]);
			}
		}
		return $ret;
	}

	public static function addWarning(string $fileName, string $nodeName, $nodeInfo, string $message = '') {
		self::$warnings []= new Warning($fileName, $nodeName, $nodeInfo, $message);
	}

	public static function getWarnings() {
		return self::$warnings;
	}


}
