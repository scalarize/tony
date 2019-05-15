<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

class Warning
{
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

	public function getShellExpr(bool $verboseMode = false) {
		$ret = "WARNING: " . $this->message . "\n  from file (" . $this->file . ")\n";
		if ($verboseMode) {
			$ret .= "  " . var_export($this->classInfo, true) . "\n";
		}
		return $ret;
	}

}
