<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

class Warning
{

	static $warnings = [];

	static $colors;

	protected $file;
	protected $className;
	protected $classInfo;
	protected $message;

	public function __construct(string $fileName, string $className, ClassInfo $classInfo, string $message = '') {
		$this->file = $fileName;
		$this->className = $className;
		$this->classInfo = $classInfo;
		$this->message = $message;
	}

	public function getColoredString(string $message, string $foreColor, string $bgColor = null) {
		if (null == self::$colors) {
			self::$colors = new Colors();
		}
		return self::$colors->getColoredString($message, $foreColor, $bgColor);
	}

	public function getShellExpr(bool $verboseMode = false) {
		$ret = sprintf("%s: %s\n  class: %s\n  file: %s\n",
				$this->getColoredString("WARNING", 'red'), $this->message,
				$this->getColoredString($this->className, 'cyan'),
				$this->getColoredString($this->file, 'yellow'));
		if ($verboseMode) {
			$ret .= "  " . var_export($this->classInfo->getClass(), true) . "\n";
		}
		return $ret;
	}

	public static function addWarning(string $fileName, string $className, ClassInfo $classInfo, string $message = '') {
		self::$warnings []= new Warning($fileName, $className, $classInfo, $message);
	}

	public static function getWarnings() {
		return self::$warnings;
	}


}
