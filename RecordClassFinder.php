<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

require 'vendor/autoload.php';

use PhpParser\{Node, NodeFinder};
use PhpParser\{Parser, ParserFactory};

/**
 * find yii active record classes from target dir or file
 */
class RecordClassFinder
{

	protected $warnings = [];

	public function __construct($target) {
		if (!file_exists($target)) {
			throw new Exception('no such file: ' . $target);
		}
		$this->target = $target;
	}

	protected function findExtendingClass($target) {
		$ret = [];
		$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP5); # or PREFER_PHP7, if your code is pure php7
		if (is_dir($target)) {
			foreach (scandir($target) as $item) {
				if ($item === '.') continue;
				if ($item === '..') continue;
				$currentTarget = $target . '/' . $item;
				foreach (self::findExtendingClass($currentTarget) as $name => $classInfo) {
					if (isset($ret[$name])) {
						$this->addWarning($currentTarget, $name, $classInfo, 'duplicate class found');
						continue;
					}
					$ret[$name] = $classInfo;
				}
			}
			return $ret;
		}
		if (!is_file($target)) {
			return $ret;
		}
		if (substr($target, -4) !== '.php') {
			return $ret;
		}

		$nodes = $parser->parse(file_get_contents($target));

		$nodeFinder = new NodeFinder;

		foreach ($nodeFinder->find($nodes, function(Node $node) {
				return $node instanceof Node\Stmt\Class_
				&& $node->extends instanceof Node\Name;
				}) as $cls) {
			$ret[$cls->name->name] = new ClassInfo($cls, $target);
		}
		return $ret;
	}

	public function findRecordClasses() {
		$classes = $this->findExtendingClass($this->target);
		$recordClasses = [];
		foreach ($classes as $name => $classInfo) {
			if (isset($recordClasses[$name])) {
				// already traversed by one of its children
				continue;
			}
			$parent = $classInfo->getParentIdentifier();
			while (true) {
				$classInfo->addAncestor($parent);
				if ($parent == 'CActiveRecord') {
					break;
				}
				if (!isset($classes[$parent])) {
					break;
				}
				$parent = $classes[$parent]->getParentIdentifier();
			}
			$ancestors = $classInfo->getAncestors();
			$len = count($ancestors);
			if ($len >= 1 && $ancestors[$len - 1] == 'CActiveRecord') {
				$recordClasses[$name] = $classes[$name];
				for ($i = 0; $i < $len - 1; $i++) {
					$ancestor = $ancestors[$i];
					$classes[$ancestor]->addAncestors(array_slice($ancestors, $i + 1));
					$classes[$ancestor]->addChild($name);
				}
			}
		}
		return $recordClasses;
	}

	public function addWarning(string $fileName, string $className, ClassInfo $classInfo, string $message = '') {
		$this->warnings []= new Warning($fileName, $className, $classInfo, $message);
	}

	public function getWarnings() {
		return $this->warnings;
	}

}


