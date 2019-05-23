<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

class Warning
{

	static $warnings = [];

	static $colors;

	protected $file;
	protected $nodeName;
	protected $nodeInfo;
	protected $message;

	public function __construct(string $fileName, string $nodeName, object $nodeInfo, string $message = '') {
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
		$ret = sprintf("%s: %s\n  node: %s\n  file: %s\n",
				$this->getColoredString("WARNING", 'red'), $this->message,
				$this->getColoredString($this->nodeName, 'cyan'),
				$this->getColoredString($this->file, 'yellow'));
		if ($verboseMode) {
			$ret .= "  " . var_export($this->nodeInfo->getNode(), true) . "\n";
		}
		return $ret;
	}

	public static function addWarning(string $fileName, string $nodeName, object $nodeInfo, string $message = '') {
		self::$warnings []= new Warning($fileName, $nodeName, $nodeInfo, $message);
	}

	public static function getWarnings() {
		return self::$warnings;
	}


}
