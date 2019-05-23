<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

require 'vendor/autoload.php';

use PhpParser\{Node, NodeVisitorAbstract, NodeTraverser};
use PhpParser\{Parser, ParserFactory};

class VarStackVisitor extends NodeVisitorAbstract
{

	const NOT_STARTED = 0;
	const GLOBAL_SCOPE = 1;
	const CLASS_INSIDE = 2;
	const FUNC_INSIDE = 4;
	const METHOD_INSIDE = 8;

	protected $target;
	protected $vars;
	protected $currentTarget;
	protected $currentLines;
	protected $nodeStack = [];
	protected $state = null;
	protected $varStacks = [];
	protected $currentClass;
	protected $currentMethod;

	public function __construct($target)
	{
		if (!file_exists($target)) {
			throw new Exception('no such file: ' . $target);
		}
		$this->target = $target;
		$this->state = self::NOT_STARTED;
	}

	public function beforeTraverse(array $nodes)
	{
		$this->vars = [];
		$this->nodeStack = [];
		$this->state = self::GLOBAL_SCOPE;
		$this->varStacks[$this->currentTarget] = [];
	}

	public function leaveNode(Node $node)
	{
		$node = array_pop($this->nodeStack);
		if ($node instanceof Node\Stmt\Class_) {
			$this->varStacks[$this->currentClass->name->name] = $this->vars;
			$this->vars = []; 
			$this->currentClass = null;
		} elseif ($node instanceof Node\Stmt\ClassMethod) {
			$this->varStacks[$this->currentClass->name->name . '::' . $this->currentMethod->name->name] = $this->vars;
			$this->vars = []; 
			$this->currentMethod = null;
		}
	}

	public function enterNode(Node $node)
	{
        if (!empty($this->nodeStack)) {
            $node->setAttribute('parent', $this->nodeStack[count($this->nodeStack)-1]);
        }
		$this->nodeStack []= $node;
		if ($node instanceof Node\Stmt\Class_) {
			$this->currentClass = $node;
			$this->state = self::CLASS_INSIDE;
		} elseif ($node instanceof Node\Stmt\ClassMethod) {
			$this->currentMethod = $node;
			$this->state = self::METHOD_INSIDE;
			// method params are local vars
			foreach ($node->params as $param) {
				// TODO, mark as special type
				$this->registerVar($param->var, $param);
			}
		}
		if ($node instanceof Node\Expr\Assign) {
			if ($node->var instanceof Node\Expr\List_) {
				// list($vars) = ...
				for ($i = 0, $cnt = count($node->var->items); $i < $cnt; ++$i) {
					if ($node->expr instanceof Node\Expr\Array_) {
						$this->registerVar($node->var->items[$i]->value, $node->expr->items[$i]->value);
					} else {
						$this->registerVar($node->var->items[$i]->value, $node->expr);
					}
				}
			} else {
				$this->registerVar($node->var, $node->expr);
			}
		}
		return null;
	}

	protected function registerVar($var, $expr)
	{
		$varName = $this->getVarIdentifier($var);
		if ($varName) {
			$prefix = '';
			switch ($this->state) {
			case self::CLASS_INSIDE:
				$prefix = $this->currentClass->name->name . '::';
				break;
			}
			$this->vars[$prefix . $varName] = $expr;
		}
	}

	public function afterTraverse(array $nodes)
	{
		$this->varStacks[$this->currentTarget] = $this->vars;
		$this->state = self::NOT_STARTED;
	}

	public function traverse()
	{
		$this->traverseTarget($this->target);
	}

	protected function traverseTarget($target)
	{
		if (is_dir($target)) {
			foreach (scandir($target) as $item) {
				if ($item === '.') continue;
				if ($item === '..') continue;
				$currentTarget = $target . '/' . $item;
				$this->traverseTarget($currentTarget);
			}
		}
		if (!is_file($target)) {
			return;
		}
		if (substr($target, -4) !== '.php') {
			return;
		}

		$this->currentTarget = $target;
		$codes = file_get_contents($target);
		$this->currentLines = explode("\n", $codes);

		$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP5); # or PREFER_PHP7, if your code is pure php7
		$nodes = $parser->parse($codes);

		$traverser = new NodeTraverser;
		$traverser->addVisitor($this);
		$traverser->traverse($nodes);
	}

	public function getVarStacks()
	{
		return $this->varStacks;
	}

	protected function getVarIdentifier($var)
	{
		if ($var instanceof Node\Expr\PropertyFetch) {
			return $this->getVarIdentifier($var->var) . '->' . $this->getVarIdentifier($var->name);
		} elseif ($var instanceof Node\Expr\StaticPropertyFetch) {
			return implode('\\', $var->class->parts) . '->' . $this->getVarIdentifier($var->name);
		} elseif ($var instanceof Node\Expr\Variable) {
			return '$' . $var->name;
		} elseif ($var instanceof Node\Identifier) {
			return $var->name;
		} elseif ($var instanceof Node\Expr\ArrayDimFetch) {
			return null;
		}
		$this->debug($var, 'unknown var type');
	}

	protected function getValueExpression(Node\Expr $expr, string $varName = null)
	{
		if ($expr instanceof Node\Expr\BinaryOp) {
			if ($expr instanceof Node\Expr\BinaryOp\Concat) {
				// $a . $b
				return $this->getValueExpression($expr->left) . $this->getValueExpression($expr->right);
			} elseif ($expr instanceof Node\Expr\BinaryOp\Div) {
				// $a / $b
				return $this->getValueExpression($expr->left) . '/' . $this->getValueExpression($expr->right);
			} else {
				$this->debug($expr, 'unsupported binary op type');
			}

		} elseif ($expr instanceof \Node\Expr\Cast) {
			// ignore all casts
			return $this->getValueExpression($expr->expr);

		} elseif ($expr instanceof Node\Scalar) {
			if ($expr instanceof Node\Scalar\Encapsed) {
				$ret = '';
				foreach ($expr->parts as $part) {
					$ret .= $this->getValueExpression($part);
				}
				return $ret;
			} else {
				return $expr->value;
			}

		} elseif ($expr instanceof Node\Expr\FuncCall && count($expr->name->parts) == 1) {
			$func = $expr->name->parts[0];
			switch ($func) {
			case 'implode':
				$varName = $this->getVarIdentifier($expr->args[1]->value);
				return sprintf('"%s0"%s"%s1"', $varName, $this->getValueExpression($expr->args[0]->value), $varName);
			default:
				// TODO
				$args = [];
				foreach ($expr->args as $arg) {
					$args []= str_replace('"', '', $this->getValueExpression($arg->value));
				}
				return sprintf('"%s(%s)"', $func, implode(',', $args));
			}

		} elseif ($expr instanceof Node\Expr\PropertyFetch
					|| $expr instanceof Node\Expr\Variable) {
			$varName = $this->getVarIdentifier($expr);
			if (isset($this->vars[$varName])) {
				if ($this->vars[$varName] instanceof Node\Param) {
					return '$param::' . $varName;
				} else {
					return $this->getValueExpression($this->vars[$varName]);
				}
			} else {
				$this->debug($expr, 'unknown var name, may be dangerous');
			}
		} elseif ($expr instanceof Node\Expr\MethodCall) {
			$args = [];
			foreach ($expr->args as $arg) {
				$args []= str_replace('"', '', $this->getValueExpression($arg->value));
			}
			return sprintf('"%s->%s(%s)"', $this->getValueExpression($expr->var), $expr->name->name, implode(',', $args));
		}
		$this->debug($expr, 'unknown value expr type');
	}

	protected function debug($node, $message = '', $full = true, $stop = true)
	{
		if ($full) {
			var_dump($node);
		} else {
			echo get_class($node) . ", depth: " . count($this->nodeStack) . "\n";
		}
		$from = $node->getAttribute('startLine');
		$to = $node->getAttribute('endLine');
		$lines = array_slice($this->currentLines, $from-1, $to-$from+1);
		printf("file:\n%s\nline:%s-%s\ncode:%s\n",
				$this->currentTarget, $from, $to,
				implode("\n", $lines)
				);
		if ($stop) {
			die($message . "\n");
		} else {
			echo "$message\n\n";
		}
	}

}

