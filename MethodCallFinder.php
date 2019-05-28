<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

require 'vendor/autoload.php';

use PhpParser\{Node, NodeVisitorAbstract, NodeTraverser};
use PhpParser\{Parser, ParserFactory};

class MethodCallFinder extends VarStackVisitor
{

	protected $methodTarget;
	protected $methodCalls = [];

	public function __construct($fileTarget, $methodTarget)
	{
		parent::__construct($fileTarget);
		$this->methodTarget = $methodTarget;
		$this->methodNameLower = strtolower($this->methodTarget->name->name);
		$this->static = ($methodTarget->flags & Node\Stmt\Class_::MODIFIER_STATIC) ? true : false;
	}

	public function enterNode(Node $node)
	{
		parent::enterNode($node);

		if ($this->static) {
			if ($node instanceof Node\Expr\StaticCall && $this->methodNameLower == strtolower($node->name->name)) {
				$this->methodCalls []= $node;
			}
		} else {
			if ($node instanceof Node\Expr\MethodCall && $this->methodNameLower == strtolower($node->name->name)) {
				$this->methodCalls []= $node;
			}
		}
	}

	public function beforeTraverse(array $nodes)
	{
		parent::beforeTraverse($nodes);

		$this->methodCalls = [];
	}

	public function getMethodCalls()
	{
		$this->traverse();
		return $this->methodCalls;
	}

}

