<?php declare(strict_types=1);
/** vim: set number noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

require 'vendor/autoload.php';

require_once 'Logger.php';
require_once 'SourceFile.php';
require_once 'Warning.php';

use PhpParser\{Node, NodeVisitorAbstract, NodeTraverser};
use PhpParser\{Parser, ParserFactory};

class VarStackVisitor extends NodeVisitorAbstract
{

	const NOT_STARTED = 0;
	const GLOBAL_SCOPE = 1;
	const CLASS_INSIDE = 2;
	const FUNC_INSIDE = 4;
	const METHOD_INSIDE = 8;

	private $traceInfoStack = [];

	protected $root;
	private $target;
	private $vars;
	private $currentTarget;

	// to track node relationship
	private $nodeStack = [];

	// to decide where we are
	private $state = null;

	// store all variables
	private $varStacks = [];

	private $currentClass;
	private $currentMethod;

	public function __construct($target, $root = null)
	{
		if (!file_exists($target)) {
			throw new \Exception('no such file: ' . $target);
		}
		$this->target = $target;
		$this->root = $root ? $root : $target;

		$this->state = self::NOT_STARTED;
	}

	/** @override */
	public function beforeTraverse(array $nodes)
	{
		//Logger::info('trying to traverse ' . $this->currentTarget);
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
					$this->registerForeachVars($node, $node->expr);
				} else {
					$this->registerForeachVars($node, $arr);
				}
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
			
			if (!empty($this->vars['foreach'])) {
				if (!isset($this->varStacks[$closure . '::FOREACH'])) {
					$this->varStacks[$closure . '::FOREACH'] = [];
				}
				$range = $node->getStartLine() . '-' . $node->getEndLine();
				$this->varStacks[$closure . '::FOREACH'][$range] = $this->vars['foreach'];
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
					$this->registerVar($node->keyVar, $item->key, 'FOREACH_KEY', 'foreach');
				}
				$this->registerVar($node->valueVar, $item->value, 'FOREACH_VALUE', 'foreach');
			}
		} elseif (is_array($arr)) {
			foreach ($arr as $key => $val) {
				if ($node->keyVar !== null) {
					$this->registerVar($node->keyVar, $key, null, 'foreach');
				}
				$this->registerVar($node->valueVar, $val, null, 'foreach');
			}
		} else {
			// FIXME, 临时方案, 把整个数组作为 var 对象, 先用一下
			if ($node->keyVar !== null) {
				$this->registerVar($node->keyVar, $arr, 'FOREACH_KEY', 'foreach');
			}
			$this->registerVar($node->valueVar, $arr, 'FOREACH_VALUE', 'foreach');
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
		if ($node instanceof Node\Expr\Array_) {
			return $node;
		}

		$varName = $this->getVarIdentifier($node);
		$closure = $this->getNodeClosure($node);
		if ($node instanceof Node\Expr\PropertyFetch && isset($node->var->name)
			&& is_string($node->var->name) && strtolower($node->var->name) == 'this') {
			$varName = '$' . $node->name->name;
			$closure = explode('::', $closure)[0];
		}

		$target = $node->getAttribute('file');
		if (!isset($this->varStacks[$closure])) {
			$this->varStacks = $this->loadVarsFromCache($target);
		}

		if (isset($this->varStacks[$closure][$varName])) {
			$vars = $this->varStacks[$closure][$varName];
			for ($i = count($vars) - 1; $i >= 0; $i--) {
				if ($vars[$i]->getStartLine() <= $node->getStartLine()) {
					return $vars[$i];
				}
			}
		} elseif (isset($this->varStacks[$closure . '::FOREACH'])) {
			// try to lookup foreach vars
			foreach ($this->varStacks[$closure. '::FOREACH'] as $range => $vars) {
				list($from, $to) = explode('-', $range);
				if (!isset($vars[$varName])) continue;
				if ($from <= $node->getStartLine() && $to >= $node->getEndLine()) {
					return $vars[$varName];
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

		// serialize to reduce memory usage
		$this->dumpVarsToCache($this->varStacks, $this->currentTarget);
		$this->varStacks = [];
		Logger::info('traverse done for ' . $this->currentTarget);
	}

	private function getCacheName($target)
	{
		return '.cache/' . str_replace('/', '_', $target) . '.cache';
	}

	private function loadVarsFromCache($target)
	{
		$cacheFile = $this->getCacheName($target);
		if (!file_exists($cacheFile)) {
			return [];
		}
		return unserialize(file_get_contents($cacheFile));
	}

	private function dumpVarsToCache($vars, $target)
	{
		file_put_contents($this->getCacheName($target), serialize($vars));
	}

	public function traverse()
	{
		SourceFile::registerSourceFile($this->target);
		foreach (SourceFile::getRegisteredSourceFiles() as $sourceFile) {
			$this->currentTarget = $sourceFile->getFilename();
			$nodes = $sourceFile->getParsedNodes();
			$traverser = new NodeTraverser;
			$traverser->addVisitor($this);
			$traverser->traverse($nodes);
		}
	}

	public function getVarStacks()
	{
		return $this->varStacks;
	}

	/** @return null means no identifier required */
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
		} elseif ($var instanceof Node\Expr\BinaryOp\Concat) {
			return $this->getVarIdentifier($var->left) . '.' . $this->getVarIdentifier($var->right);
		} elseif ($var instanceof Node\Expr\BinaryOp\Div) {
			return $this->getVarIdentifier($var->left) . '/' . $this->getVarIdentifier($var->right);
		} elseif ($var instanceof Node\Expr\BinaryOp\Minus) {
			return $this->getVarIdentifier($var->left) . '-' . $this->getVarIdentifier($var->right);
		} elseif ($var instanceof Node\Expr\BinaryOp\Mul) {
			return $this->getVarIdentifier($var->left) . '*' . $this->getVarIdentifier($var->right);
		} elseif ($var instanceof Node\Expr\BinaryOp\Plus) {
			return $this->getVarIdentifier($var->left) . '+' . $this->getVarIdentifier($var->right);
		} elseif ($var instanceof Node\Expr\Cast\Int_) {
			return '(int)' . $this->getVarIdentifier($var->expr);
		} elseif ($var instanceof Node\Expr\UnaryMinus) {
			return '-' . $this->getVarIdentifier($var->expr);
		} elseif ($var instanceof Node\Expr\New_) {
			// TODO
			// cdbcriteria here, could be explored deep
			// debug this:
			// ./db_reference_finder -t  sample/mis-rtb/protected/models//DspBaseModel.php -r sample/mis-rtb/
			return 'CLASS::' . implode('\\', $var->class->parts);
		} elseif ($var instanceof Node\Expr\Array_) {
			return null;
		} elseif ($var instanceof Node\Expr\ClassConstFetch) {
			$className = implode($var->class->parts);
			if (strtolower($className) == 'self') {
				$className = $this->getNodeClass($var);
			}
			return $className . '::' . $var->name->name;
		}
		$this->debug($var, 'unknown var type: ' . $var->getType());
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

	protected function debug($node = null, $message = '', $full = true, $stop = true)
	{
		if ($node instanceof Node) {
			if ($full) {
				$dumpNode = clone $node;
				$this->removeTagsForDump($dumpNode);
				var_dump($dumpNode);
			} else {
				echo get_class($node) . ", depth: " . count($this->nodeStack) . "\n";
			}
			$sourceInfo = $this->getSourceInfo($node);
			printf("file:\n%s\nline:%s-%s\ncode:%s\n",
					$sourceInfo['file'], $sourceInfo['from'], $sourceInfo['to'], $sourceInfo['code']
					);
		} else {
			var_dump($node);
		}
		if ($this->traceInfoStack) {
			printf("TRACE:\n");
			foreach ($this->traceInfoStack as $traceInfo) {
				if (is_string($traceInfo)) {
					printf("  - %s\n", $traceInfo);
				} else {
					printf("  - %s\n", var_export($traceInfo, true));
				}
			}
		}
		if ($stop) {
			throw new \Exception($message);
		} else {
			echo "$message\n\n";
		}
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

	public static function getNodeClosure(Node $node)
	{
		// TODO, ensure 'closure' attribute has no conflict with other usages
		return $node->getAttribute('closure');
	}

	public static function getNodeClass(Node $node)
	{
		$closure = self::getNodeClosure($node);
		if (!$closure) return null;
		if (strpos($closure, '::') === false) return $closure;
		return explode('::', $closure)[0];	
	}

	public static function getNodeMethod(Node $node)
	{
		$closure = self::getNodeClosure($node);
		if (!$closure) return null;
		if (strpos($closure, '::') === false) return null;
		return explode('::', $closure)[1];	
	}

	public function trace($message)
	{
		$this->traceInfoStack []= $message;
	}

	public function resetTrace()
	{
		$this->traceInfoStack = [];
	}

	public function getSourceInfo(Node $node)
	{
		$from = $node->getStartLine();
		$to = $node->getEndLine();
		$file = $node->getAttribute('file');
		$code = '';
		// TODO, cache lines
		$lines = explode("\n", file_get_contents($file));
		if (!empty($lines) && count($lines) >= $to) {
			$code = implode("\n", array_slice($lines, $from - 1, $to - $from + 1));
		}
		return ['from' => $from, 'to' => $to, 'code' => $code, 'file' => $file];
	}

}

