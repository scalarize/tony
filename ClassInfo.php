<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

use PhpParser\Node;

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

	public function getClass() {
		return $this->cls;
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

	public function getAncestors() {
		return $this->ancestors;
	}

	public function getAncestorNames() {
		$ret = [];
		foreach ($this->ancestors as $classInfo) {
			$ret []= $classInfo->getClassName();
		}
		return $ret;
	}

	protected function getReturnStatements($stmt) {
		$ret = [];
		if ($stmt instanceof \PhpParser\Node\Stmt\ClassMethod) {
			foreach ($stmt->stmts as $s) {
				foreach ($this->getReturnStatements($s) as $ss) {
					$ret []= $ss;
				}
			}
		}
		if ($stmt instanceof \PhpParser\Node\Stmt\If_) {
			// TODO elseif and else
			foreach ($stmt->stmts as $s) {
				foreach ($this->getReturnStatements($s) as $ss) {
					$ret []= $ss;
				}
			}
		}
		if ($stmt instanceof \PhpParser\Node\Stmt\Return_) {
			$ret []= $stmt;
		}
		return $ret;
	}


	public function getYiiTableNames() {
		$ret = [];
		foreach ($this->cls->stmts as $stmt) {
			if ($stmt instanceof \PhpParser\Node\Stmt\ClassMethod
				&& strtolower($stmt->name->name) == 'tablename') {
				foreach ($this->getReturnStatements($stmt) as $s) {
					$ret []= $this->getStringValue($s->expr);
				}
			}
		}
		if (empty($ret) && count($this->ancestors) > 0) {
			return $this->ancestors[0]->getYiiTableNames();
		}
		return $ret;
	}

	public function getYiiDBNames() {
		$ret = [];
		foreach ($this->cls->stmts as $stmt) {
			if ($stmt instanceof \PhpParser\Node\Stmt\ClassMethod
				&& strtolower($stmt->name->name) == 'getdbconnection') {
				foreach ($this->getReturnStatements($stmt) as $s) {
					if ($s->expr instanceof \PhpParser\Node\Expr\PropertyFetch
						&& $s->expr->var instanceof \PhpParser\Node\Expr\StaticCall) {
						$var = $s->expr->var;
						if (count($var->class->parts) ==1 && strtolower($var->class->parts[0]) == 'yii'
							&& strtolower($var->name->name) == 'app'
							&& count($var->args) == 0) {
							$ret []= $s->expr->name->name;
						}
					}
				}
			}
		}
		if (empty($ret) && count($this->ancestors) > 0) {
			return $this->ancestors[0]->getYiiDBNames();
		}
		return $ret;
	}

	protected function getStringValue(\PhpParser\Node\Expr $expr) {
		if ($expr instanceof \PhpParser\Node\Scalar\String_) {
			return $expr->value;
		}
		if ($expr instanceof \PhpParser\Node\Expr\BinaryOp\Concat) {
			return $this->getStringValue($expr->left) . $this->getStringValue($expr->right);
		}
		if ($expr instanceof \PhpParser\Node\Expr\FuncCall) {
			$args = [];
			foreach ($expr->args as $arg) {
				$args []= $this->getStringValue($arg->value);
			}
			$code = implode($expr->name->parts) . '("' . implode('", "', $args) . '")';
			eval('$funcRet = ' . $code . ';');
			if ($funcRet) {
				return $funcRet;
			} else {
				return '::f::' . implode('_', $expr->name->parts) . '("' . implode('", "', $args) . '")';
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
