<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

require 'vendor/autoload.php';

require_once 'SourceFile.php';
require_once 'Logger.php';
require_once 'ClassInfo.php';

use PhpParser\{Node, NodeFinder};

/**
 * find a named class
 * by scan and parse source files under root
 */
final class ClassLocator
{

	/** global in-memory cache of classname => ClassInfo object */
	protected static $classInfoCache = [];

	/** global in-memory cache of filename => searched flag */
	protected static $searched = [];

	public static function getLocatedClasses()
	{
		return self::$classInfoCache;
	}

	public static function registerLocatedClass(ClassInfo $classInfo)
	{
		$className = strtolower($classInfo->getClassName());
		self::$classInfoCache[$className] = $classInfo;
	}

	public static function locateClass(string $className, string $root)
	{

		$classNameLower = strtolower($className);
		if (isset(self::$classInfoCache[$classNameLower])) {
			return self::$classInfoCache[$classNameLower];
		}

		SourceFile::registerSourceFile($root);

		foreach (SourceFile::getRegisteredSourceFiles() as $sourceFile) {
			$filename = $sourceFile->getFilename();
			if (isset(self::$searched[$filename])) continue;

			//Logger::info("parsing file $filename to locate class $className");

			$nodeFinder = new NodeFinder;

			$found = null;
			foreach ($nodeFinder->find($sourceFile->getParsedNodes(), function(Node $node) {
				return $node instanceof Node\Stmt\Class_;
				}) as $classNode) {

				//Logger::info("found class $classNode->name from file $filename");
				// whatever class found, fill class and source file cache, to avoid future re-searching
				$classInfo = new ClassInfo($classNode, $sourceFile);
				self::registerLocatedClass($classInfo);

				if ($classNameLower === strtolower($classInfo->getClassName())) {
					$found = $classInfo;
				}
			}
			self::$searched[$filename] = true;
			if ($found) return $found;
		}

		return null;

	}

}


