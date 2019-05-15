<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

use PhpParser\{Node, NodeFinder};

namespace TonyParser;

class ClassInfo
{

	protected $cls;
	protected $file;
	protected $ancestors = [];
	protected $children = [];

	public function __construct($cls, $file) {
		$this->cls = $cls;
		$this->file = $file;
	}

	public function getClassName() {
		return $this->cls->name->name;
	}

	public function getParentParts() {
		if ($this->cls->extends !== null) {
			return $this->cls->extends->parts;
		}
		return [];
	}

	public function getFile() {
		return $this->file;
	}

	public function getParentIdentifier() {
		return implode('\\', $this->getParentParts());
	}

	public function addAncestor($ancestor) {
		$this->ancestors []= $ancestor;
	}

	public function addAncestors($ancestors) {
		foreach ($ancestors as $ancestor) {
			$this->ancestors []= $ancestor;
		}
	}

	public function getAncestors() {
		return $this->ancestors;
	}

	public function getYiiTableName() {
		foreach ($this->cls->stmts as $stmt) {
			if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->name === 'tableName') {
				if (count($stmt->stmts) == 1
					&& $stmt->stmts[0] instanceof Node\Stmt\Return_
					&& $stmt->stmts[0]->expr instanceof Node\Scalar\String_) {
					return $stmt->stmts[0]->expr->value;
				}
			}
		}
		return null;
	}

	public function getFileName() {
		return $this->file;
	}

	public function addChild($child) {
		$this->children []= $child;
	}

	public function getChildren() {
		return $this->children;
	}
	
}
