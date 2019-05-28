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

	protected $target;

	public function __construct($target) {
		if (!file_exists($target)) {
			throw new Exception('no such file: ' . $target);
		}
		$this->target = $target;
	}

	protected function findExtendingClass($target) {
		$ret = [];
		if (is_dir($target)) {
			foreach (scandir($target) as $item) {
				if ($item === '.') continue;
				if ($item === '..') continue;
				$currentTarget = $target . '/' . $item;
				foreach ($this->findExtendingClass($currentTarget) as $name => $classInfo) {
					// class duplication warning is done by VarStackVisitor
					if (isset($ret[$name])) continue;
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

		$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP5); # or PREFER_PHP7, if your code is pure php7
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
			$parent = $classInfo->getParentIdentifier();
			$isRecordClass = false;
			while (true) {
				if (strtolower($parent) == 'cactiverecord') {
					$isRecordClass = true;
					break;
				}
				if (!isset($classes[$parent])) {
					break;
				}
				$classInfo->addAncestor($classes[$parent]);
				$classes[$parent]->addChild($classInfo);
				$parent = $classes[$parent]->getParentIdentifier();
			}
			if ($isRecordClass) {
				$recordClasses[$name] = $classInfo;
			}
		}
		return $recordClasses;
	}

}


