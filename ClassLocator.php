<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

require 'vendor/autoload.php';

require_once 'SourceFile.php';
require_once 'Logger.php';

use PhpParser\{Node, NodeFinder};
use PhpParser\{Parser, ParserFactory};

/**
 * find a model class from a referrer
 */
class ClassLocator
{

	protected static $classInfoCache = [];
	protected static $searched = [];

	protected $referrer;
	protected $class;
	protected $classLower;
	protected $root;

	/** null means not searched; false means not found */
	protected $classInfo = null;

	public function __construct($referrer, $class, $root)
	{
		$this->referrer = realpath($referrer);
		$this->class = $class;
		$this->classLower = strtolower($class);
		$this->root = realpath($root);
	}

	protected function locateClass(string $target)
	{
		SourceFile::registerSourceFile($target);
		foreach (SourceFile::getRegisteredSourceFiles() as $sourceFile) {
			$filename = $sourceFile->getFilename();
			if (isset(self::$searched[$filename])) continue;

			Logger::info('parsing file ' . $sourceFile->getFilename() . ' to locate class ' . $this->class);
			$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP5); # or PREFER_PHP7, if your code is pure php7
			$nodes = $parser->parse($sourceFile->getContent());

			$nodeFinder = new NodeFinder;

			foreach ($nodeFinder->find($nodes, function(Node $node) {
				return $node instanceof Node\Stmt\Class_;
				}) as $cls) {
				// whatever class found, fill class and source file cache, to avoid future re-searching
				$classInfo = new ClassInfo($cls, $target);
				$className = strtolower($classInfo->getClassName());
				self::$classInfoCache[$className] = $classInfo;
				self::$searched[$filename] = true;
				if ($className === $this->classLower) {
					return $classInfo;
				}
			}
		}

		// current target not matched, go up one level
		$upperTarget = realpath(dirname($target));
		if (strlen($upperTarget) < strlen($this->root)) {
			// up way to far, abort
			return false;
		}
		if (substr($upperTarget, 0, strlen($this->root)) !== $this->root) {
			// jumped out, may be symbol link, abort
			return false;
		}
		return $this->locateClass($upperTarget);
	}

	public function getClassInfo() {
		if (isset(self::$classInfoCache[$this->class])) {
			$this->classInfo = self::$classInfoCache[$this->class];
		}
		if ($this->classInfo === null) {
			$this->classInfo = $this->locateClass($this->referrer);
		}
		if ($this->classInfo === false) {
			return null;
		}
		return $this->classInfo;
	}

}


