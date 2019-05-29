<?php declare(strict_types=1);
/** vim: set number noet ts=4 sw=4 fdm=indent: */

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

	// to track node relationship
	protected $nodeStack = [];

	// to decide where we are
	protected $state = null;

	// store all variables
	protected $varStacks = [];

	protected $currentClass;
	protected $currentMethod;
	protected $classStack;

	public function __construct($target)
	{
		if (!file_exists($target)) {
			throw new \Exception('no such file: ' . $target);
		}
		$this->target = $target;
		$this->state = self::NOT_STARTED;
		$this->classStack = [];
	}

	/** @override */
	public function beforeTraverse(array $nodes)
	{
		$this->vars = [];
		$this->nodeStack = [];
		$this->state = self::GLOBAL_SCOPE;
		$this->varStacks[$this->currentTarget] = [];
	}

	/** @override */
	public function enterNode(Node $node)
	{
		// FIXME, this may be unneccessary and could be opt out
		$node->setAttribute('file', $this->currentTarget);
		$node->setAttribute('closure', $this->getCurrentClosure());
        if (!empty($this->nodeStack)) {
            $node->setAttribute('parent', $this->nodeStack[count($this->nodeStack)-1]);
        }
		$this->nodeStack []= $node;
		if ($node instanceof Node\Stmt\Class_) {
			$this->currentClass = $node;
			$this->state = self::CLASS_INSIDE;
			$this->registerClass($node);
		} elseif ($node instanceof Node\Stmt\ClassMethod) {
			$this->currentMethod = $node;
			$this->state = self::METHOD_INSIDE;
		}

		return null;
	}

	/** @override */
	public function leaveNode(Node $node)
	{
		$node = array_pop($this->nodeStack);
		$closure = $this->getCurrentClosure();
		if ($node instanceof Node\Stmt\Class_) {
			if (!empty($this->vars['class'])) {
				$this->varStacks[$closure] = $this->vars['class'];
				$this->vars['class'] = []; 
			}
			$this->state = self::GLOBAL_SCOPE;
			$this->currentClass = null;
		} elseif ($node instanceof Node\Stmt\ClassMethod) {

			// method params are local vars
			foreach ($node->params as $param) {
				$this->registerVar($param->var, $param);
			}

			if (!empty($this->vars['method'])) {
				$this->varStacks[$closure] = $this->vars['method'];
				$this->vars['method'] = []; 
			}
			$this->state = self::CLASS_INSIDE;
			$this->currentMethod = null;
		} elseif ($node instanceof Node\Expr\Assign) {
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
		} elseif ($node instanceof Node\Stmt\Foreach_) {
			switch ($node->expr->getType()) {
			case 'Expr_Variable':
			case 'Expr_PropertyFetch':
			case 'Expr_StaticPropertyFetch':
			case 'Expr_ArrayDimFetch':
				$arr = $this->getVariableExpr($node->expr);
				if (null == $arr) {
					// FIXME, ignore
					// $this->debug($node->expr, 'cannot find foreach array for name: ' . $arrName);
					break;
				}
				$this->registerForeachVars($node, $arr, null, 'foreach');
				break;
			case 'Expr_Array':
				$this->registerForeachVars($node, $node->expr, null, 'foreach');
				break;
			case 'Expr_FuncCall':
			case 'Expr_StaticCall':
			case 'Expr_MethodCall':
				// FIXME, 暂时没办法拆解, 只好先原样注册
				$this->registerForeachVars($node, $node->expr, null, 'foreach');
				break;
			default:
				$this->debug($node->expr, 'unknown foreach array var type: ' . $node->expr->getType());
				break;
			}
			
			if (!empty($this->vars['foreach'])) {
				$this->varStacks[$closure . '::FOREACH_' . $node->getStartLine()] = $this->vars['foreach'];
				$this->vars['foreach'] = []; 
			}
		} elseif ($node instanceof Node\Stmt\ClassConst) {
			foreach ($node->consts as $const) {
				$this->registerVar($const, $const->value);
			}
		}
	}

	protected function getCurrentClosure()
	{
		switch ($this->state) {
		case self::CLASS_INSIDE:
			return $this->currentClass->name->name;
		case self::METHOD_INSIDE:
			return $this->currentClass->name->name . '::' . $this->currentMethod->name->name;
		default:
			return '';
		}
	}

	protected function registerForeachVars($node, $arr)
	{
		if ($arr instanceof Node\Expr\Array_ && count($arr->items) > 0) {
			foreach ($arr->items as $item) {
				if ($node->keyVar !== null) {
					$this->registerVar($node->keyVar, $item->key);
				}
				$this->registerVar($node->valueVar, $item->value);
			}
		} else {
			// FIXME, 临时方案, 把整个数组作为 var 对象, 先用一下
			if ($node->keyVar !== null) {
				$this->registerVar($node->keyVar, $arr, 'FOREACH_KEY');
			}
			$this->registerVar($node->valueVar, $arr, 'FOREACH_VALUE');
		}
	}

	protected function registerVar($var, $expr, $tag = null, $domain = null)
	{
		$varName = $this->getVarIdentifier($var);
		if ($varName) {
			$prefix = '';
			switch ($this->state) {
			case self::CLASS_INSIDE:
				if (!$domain) $domain = 'class';
				$prefix = $this->currentClass->name->name . '::';
				break;
			case self::METHOD_INSIDE:
				if (!$domain) $domain = 'method';
				break;
			default:
				if (!$domain) $domain = 'global';
				break;
			}
			$varName = $prefix . $varName;
			if (!isset($this->vars[$domain])) $this->vars[$domain] = [];
			if (!isset($this->vars[$domain][$varName])) $this->vars[$domain][$varName] = [];
			if ($tag !== null) {
				// TODO, ensure tag is not with conflict with other attribute usages
				$expr->setAttribute('tag', $tag);
			}
			$this->vars[$domain][$varName] []= $expr;
		}
	}

	protected function getVariableExpr(Node $node)
	{
		$varName = $this->getVarIdentifier($node);
		$closure = $this->getNodeClosure($node);
		if ($node instanceof Node\Expr\PropertyFetch && strtolower($node->var->name) == 'this') {
			$varName = '$' . $node->name->name;
			$closure = explode('::', $closure)[0];
		}
		if (isset($this->varStacks[$closure][$varName])) {
			$vars = $this->varStacks[$closure][$varName];
			for ($i = count($vars) - 1; $i >= 0; $i--) {
				if ($vars[$i]->getStartLine() <= $node->getStartLine()) {
					return $vars[$i];
				}
			}
		}
		return null;
	}

	public function afterTraverse(array $nodes)
	{
		if (!empty($this->vars['global'])) {
			$this->varStacks[$this->currentTarget] = $this->vars['global'];
			$this->vars = [];
		}
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
		} elseif ($var instanceof Node\Expr\ConstFetch) {
			return implode('', $var->name->parts);
		} elseif ($var instanceof Node\Expr\StaticPropertyFetch) {
			return implode('\\', $var->class->parts) . '->' . $this->getVarIdentifier($var->name);
		} elseif ($var instanceof Node\Expr\Variable) {
			return '$' . $var->name;
		} elseif ($var instanceof Node\Identifier) {
			return $var->name;
		} elseif ($var instanceof Node\Expr\ArrayDimFetch) {
			return sprintf('%s[%s]', $this->getVarIdentifier($var->var), null == $var->dim ? '' : $this->getVarIdentifier($var->dim));
		} elseif ($var instanceof Node\Expr\StaticCall) {
			$className = implode('\\', $var->class->parts);
			$methodName = $var->name->name;
			$args = [];
			foreach ($var->args as $arg) {
				$args []= $this->getVarIdentifier($arg->value);
			}
			return sprintf('%s::%s(%s)', $className, $methodName, implode(',', $args));
		} elseif ($var instanceof Node\Expr\MethodCall) {
			$varName = $this->getVarIdentifier($var->var);
			$methodName = $var->name->name;
			$args = [];
			foreach ($var->args as $arg) {
				$args []= $this->getVarIdentifier($arg->value);
			}
			return sprintf('%s->%s(%s)', $varName, $methodName, implode(',', $args));
		} elseif ($var instanceof Node\Const_) {
			return $var->name->name;
		} elseif ($var instanceof Node\Scalar) {
			if ($var instanceof Node\Scalar\Encapsed) {
				$ret = '';
				foreach ($var->parts as $part) {
					$ret .= $this->getVarIdentifier($part);
				}
				return $ret;
			} else {
				return $var->value;
			}
		} elseif ($var instanceof Node\Expr\FuncCall) {
			$args = [];
			foreach ($var->args as $arg) {
				$args []= $this->getVarIdentifier($arg->value);
			}
			return sprintf('%s(%s)', implode('.', $var->name->parts), implode(',', $args));
		}
		$this->debug($var, 'unknown var type');
	}

	protected function removeTagsForDump(Node $node)
	{
		$node->setAttribute('parent', null);
		foreach ($node as $key => $val) {
			if ($val instanceof Node) {
				$this->removeTagsForDump($val);
			} elseif (is_array($val)) {
				foreach ($val as $subval) {
					if ($subval instanceof Node) {
						$this->removeTagsForDump($subval);
					}
				}
			}
		}
	}

	protected function debug($node, $message = '', $full = true, $stop = true)
	{
		if ($full) {
			$dumpNode = clone $node;
			$this->removeTagsForDump($dumpNode);
			var_dump($dumpNode);
		} else {
			echo get_class($node) . ", depth: " . count($this->nodeStack) . "\n";
		}
		$from = $node->getStartLine();
		$to = $node->getEndLine();
		$lines = array_slice($this->currentLines, $from-1, $to-$from+1);
		printf("file:\n%s\nline:%s-%s\ncode:%s\n",
				$this->currentTarget, $from, $to,
				implode("\n", $lines)
				);
		if ($stop) {
			throw new \Exception($message);
		} else {
			echo "$message\n\n";
		}
	}

	protected function registerClass(Node\Stmt\Class_ $node)
	{
		if (isset($this->classStack[$node->name->name])) {
			Warning::addWarning($this->currentTarget, $node->name->name, $node, 'duplicate class found: ' . $node->name->name);
			// ignore
			return;
		}
		$this->classStack[$node->name->name] = $node;
	}

	public function getAncestorName($className)
	{
		if (!isset($this->classStack[$className])) {
			return null;
		}
		$classNode = $this->classStack[$className];
		if (!$classNode->extends instanceof Node\Name) {
			return null;
		}
		return implode('\\', $classNode->extends->parts);
	}

	public function dumpVars($expectedClosure = null)
	{
		echo "====== DUMPING VARS {{{ ======\n";
		foreach ($this->varStacks as $closure => $vars) {
			if ($expectedClosure && $closure != $expectedClosure) continue;
			echo "=== $closure ===\n";
			foreach ($vars as $name => $varArr) {
				echo "  $name => " . $varArr[count($varArr) - 1]->getType() . "\n";
			}
		}
		echo "====== }}} ======\n";
	}

	public function getNodeClosure(Node $node)
	{
		// TODO, ensure 'closure' attribute has no conflict with other usages
		return $node->getAttribute('closure');
	}

}

