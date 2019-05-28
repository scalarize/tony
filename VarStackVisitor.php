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
			if (!empty($this->vars['class'])) {
				$this->varStacks[$this->currentClass->name->name] = $this->vars['class'];
				$this->vars['class'] = []; 
			}
			$this->currentClass = null;
		} elseif ($node instanceof Node\Stmt\ClassMethod) {
			if (!empty($this->vars['method'])) {
				$this->varStacks[$this->currentClass->name->name . '::' . $this->currentMethod->name->name] = $this->vars['method'];
				$this->vars['method'] = []; 
			}
			$this->currentMethod = null;
		} elseif ($node instanceof Node\Stmt\Foreach_) {
			// remove vars introduced by foreach statement
			$this->vars['foreach'] = [];
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
			$this->registerClass($node);
		} elseif ($node instanceof Node\Stmt\ClassMethod) {
			$this->currentMethod = $node;
			$this->state = self::METHOD_INSIDE;
			// method params are local vars
			foreach ($node->params as $param) {
				$this->registerVar($param->var, $param, null, 'foreach');
			}
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
				$arrName = $this->getVarIdentifier($node->expr);
				$arr = $this->getVar($arrName, $node->getStartLine());
				if (null == $arr) {
					// FIXME, ignore
					// $this->debug($node->expr, 'cannot find foreach array for name: ' . $arrName);
					return;
				}
				$this->registerForeachVars($node, $arr);
				break;
			case 'Expr_Array':
				$this->registerForeachVars($node, $node->expr);
				break;
			case 'Expr_FuncCall':
			case 'Expr_StaticCall':
			case 'Expr_MethodCall':
				// FIXME, 暂时没办法拆解, 只好先原样注册
				$this->registerForeachVars($node, $node->expr);
				break;
			default:
				$this->debug($node->expr, 'unknown foreach array var type: ' . $node->expr->getType());
				break;
			}
		} elseif ($node instanceof Node\Stmt\ClassConst) {
			foreach ($node->consts as $const) {
				$this->registerVar($const, $const->value);
			}
		}
		return null;
	}

	protected function registerForeachVars($node, $arr)
	{
		if ($arr instanceof Node\Expr\Array_ && count($arr->items) > 0) {
			if ($node->keyVar !== null) {
				$this->registerVar($node->keyVar, $arr->items[0]->key);
			}
			$this->registerVar($node->valueVar, $arr->items[0]->value);
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

	protected function getVar($varName, $beforeLineNumber)
	{
		foreach (['foreach', 'method', 'class', 'global'] as $domain) {
			if (!isset($this->vars[$domain][$varName])) return null;
			for ($i = count($this->vars[$domain][$varName]) - 1; $i >= 0; $i--) {
				$var = $this->vars[$domain][$varName][$i];
				// TODO, ensure '<=' versus '<'
				if ($var->getStartLine() <= $beforeLineNumber) {
					return $var;
				}
			}
		}
		return null;
	}

	protected function getGlobalVar($varName, $classOrFile)
	{
		if (!isset($this->varStacks[$classOrFile])) return null;
		if (!isset($this->varStacks[$classOrFile][$varName])) return null;
		$vars = $this->varStacks[$classOrFile][$varName];
		return $vars[count($vars) - 1];
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
		} elseif ($var instanceof Node\Expr\StaticPropertyFetch) {
			return implode('\\', $var->class->parts) . '->' . $this->getVarIdentifier($var->name);
		} elseif ($var instanceof Node\Expr\Variable) {
			return '$' . $var->name;
		} elseif ($var instanceof Node\Identifier) {
			return $var->name;
		} elseif ($var instanceof Node\Expr\ArrayDimFetch) {
			return null;
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
		}
		$this->debug($var, 'unknown var type');
	}

	protected function removeTagsForDump(Node $node)
	{
		$node->setAttribute('parent', null);
		foreach ($node as $key => $val) {
			if ($val instanceof Node) {
				$this->removeTagsForDump($val);
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
			//die($message . "\n");
			throw new \Exception($message);
		} else {
			echo "$message\n\n";
		}
	}

	protected function registerClass(Node\Stmt\Class_ $node)
	{
		if (isset($this->classStack[$node->name->name])) {
			Warning::addWarning($this->currentTarget, $node->name->name, $node, 'duplicate class found');
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

}

