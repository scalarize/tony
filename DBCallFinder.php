<?php declare(strict_types=1);
/** vim: set number noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

require 'vendor/autoload.php';

require 'VarStackVisitor.php';
require 'MethodCallFinder.php';
require 'SQLException.php';

use PhpParser\{Node, NodeVisitorAbstract, NodeTraverser};
use PhpParser\{Parser, ParserFactory};
use PHPSQLParser\PHPSQLParser;

class DBCallFinder extends VarStackVisitor
{

	private $nodesToCheck = [];
	protected $dbCalls = null;

	public function getDBCalls()
	{
		if ($this->dbCalls === null) {
			$this->dbCalls = [];
			foreach ($this->nodesToCheck as $node) {
				$this->checkNode($node);
			}
		}
		return $this->dbCalls;
	}

	public function beforeTraverse(array $nodes)
	{
		parent::beforeTraverse($nodes);
	}

	public function enterNode(Node $node)
	{
		parent::enterNode($node);
		if ($node instanceof Node\Expr\MethodCall
			&& strtolower($node->name->name) == 'createcommand') {
			$this->nodesToCheck []= $node;
		}
	}

	protected function checkNode(Node $node)
	{
		$this->resetTrace();
		$this->trace(sprintf('%s:(%s)  %s: %s', __METHOD__, __LINE__, $this->getNodeClosure($node), $node->getStartLine()));
		if (isset($node->var->name->name)) {
			$dbName = $node->var->name->name;
		} else {
			if ($node->var instanceof Node\Expr\PropertyFetch) {
				// TODO, get real db name from config
				$dbName = $node->var->name->name;
			} else {
				// TODO, get db name from nested calls
				$dbName = 'unknown';
			}
		}
		if (empty($node->args)) {
			// vacant sql command, often comes with select / from methods
			// TODO, build complete sql
			$dbCallFound = false;
			$parent = $node->getAttribute('parent');
			while ($parent) {
				if (!$parent instanceof Node\Expr\MethodCall) break;
				$methodName = strtolower($parent->name->name);
				switch ($methodName) {
				case 'from':
				case 'join':
				case 'leftjoin':
				case 'rightjoin':
				case 'natualjoin':
					$currentDBName = $dbName;
					if (count($parent->args) >= 1) {
						foreach ($this->buildSQLSamplesFromExpr($parent->args[0]->value) as $tableSample) {
							$tableName = explode(' ', $tableSample)[0];
							if (strpos($tableName, '.') !== false) {
								list($currentDBName, $tableName) = explode('.', $tableName);
							}
							$this->registerDBCalls('commandCall_' . $methodName, $currentDBName, $tableName, '');
						}
					}
					$dbCallFound = true;
					break;
				}
				$parent = $parent->getAttribute('parent');
			}
			if (!$dbCallFound) {
				Warning::addWarning($node->getAttribute('file'), $dbName, $node, 'createCommand but no call found');
			}
		} else {
			$sqlVar = $node->args[0]->value;
			$this->checkSQL($node, $dbName, $sqlVar);
		}
	}

	/**
	 * generator function
	 * yields [sql, errorMessage] as warning
	 */
	protected function checkSQLSample($node, $dbName, $sqlSample)
	{
		$this->trace(sprintf('%s:(%s)  %s', __METHOD__, __LINE__, $sqlSample));

		if (strpos($sqlSample, 'METHOD_PARAM::') !== false) {
			yield [$sqlSample, 'POTENTIAL INJECTION. sql contains method param as string'];
			return;
		}
		if (strpos($sqlSample, 'METHOD_CALL::') !== false) {
			yield [$sqlSample, 'POTENTIAL INJECTION. sql contains method call return value as string'];
			return;
		}
		if (strpos($sqlSample, 'OBJECT_PROPERTY::') !== false) {
			yield [$sqlSample, 'POTENTIAL INJECTION. sql contains object property as string'];
			return;
		}
		if (strpos($sqlSample, 'FUNCTION_CALL::') !== false) {
			yield [$sqlSample, 'POTENTIAL INJECTION. sql contains function call as string'];
			return;
		}
		if (strpos($sqlSample, 'ARRAY_FETCH::') !== false) {
			yield [$sqlSample, 'POTENTIAL INJECTION. sql contains array dim fetch value as string'];
			return;
		}
		if (strpos($sqlSample, 'ARRAY::') !== false) {
			yield [$sqlSample, 'POTENTIAL INJECTION. sql contains array dim fetch value as string'];
			return;
		}
		if (strpos($sqlSample, 'CONST::') !== false) {
			yield [$sqlSample, 'POTENTIAL INJECTION. sql contains const value as string'];
			return;
		}
		if (strpos($sqlSample, 'PROPERTY_FETCH::') !== false) {
			yield [$sqlSample, 'POTENTIAL INJECTION. sql contains property fetch as string'];
			return;
		}

		try {
			$sqlParser = new PHPSQLParser($sqlSample, true);
		} catch (\Exception $e) {
			$this->debug($node, 'invalid sql: ' . $sqlSample, false, false);
			throw $e;
		}
		$tables = $this->getTablesFromParsedSQL($sqlParser->parsed);
		foreach ($tables as $table) {
			$this->registerDBCalls('fromSQLSample', $dbName, $table, $sqlSample);
		}
	}

	protected function checkSQL(Node $node, string $dbName, Node\Expr $sqlExpr)
	{
		$this->trace(sprintf('%s:(%s)  db:%s, sqlvar:%s, line:%s', __METHOD__, __LINE__,
				$dbName, $this->getVarIdentifier($sqlExpr), $sqlExpr->getStartLine()));
		$sqlSamples = $this->buildSQLSamplesFromExpr($sqlExpr);
		foreach ($sqlSamples as $sqlSample) {

			foreach ($this->checkSQLSample($node, $dbName, $sqlSample) as $checkResult) {
				list($sqlSample, $errorMessage) = $checkResult;
				Warning::addWarning($node->getAttribute('file'), $sqlSample, $sqlExpr, $errorMessage);
			}
		}

	}

	/**
	 * generator function
	 * yield sql samples to check
	 */
	protected function buildSQLSamplesFromExpr($expr)
	{
		if (!$expr instanceof Node\Expr) {
			$this->debug(null, 'unexpected sql expression encountered, may be yielded badly before, see trace info');
		}
		$exprType = $expr->getType();
		$exprName = $this->getVarIdentifier($expr);
		$this->trace(sprintf('%s:(%s)  %s: %s', __METHOD__, __LINE__, $exprName, $exprType));
		$func = 'buildSQLSamplesFromExprType_' . $exprType;
		if (method_exists($this, $func)) {
			foreach ($this->$func($expr) as $sqlSample) {
				yield $sqlSample;
			}
		} else {
			$this->debug($expr, 'unknown sql expr type: ' . $exprType);
		}
	}

	public function buildSQLSamplesFromExprType_Expr_Variable(Node\Expr\Variable $expr)
	{
		$varName = $this->getVarIdentifier($expr);
		$varExpr = $this->getVariableExpr($expr);
		if (!$varExpr instanceof Node\Expr) {
			yield gettype($varExpr) . '::' . $varName;
			return;
		}
		if ($varExpr !== null) {
			foreach ($this->buildSQLSamplesFromExpr($varExpr) as $sqlSample) {
				yield $sqlSample;
			}

		} else {
			$this->debug($expr, 'unknown var name, may be dangerous or not properly extracted from locals/objects: ' . $varName);
		}
	}

	public function buildSQLSamplesFromExprType_Expr_PropertyFetch(Node\Expr\PropertyFetch $expr)
	{
		$varExpr = $this->getVariableExpr($expr);
		if ($varExpr !== null) {
			foreach ($this->buildSQLSamplesFromExpr($varExpr) as $sqlSample) {
				yield $sqlSample;
			}

		} else {
			yield 'PROPERTY_FETCH::' . $this->getVarIdentifier($expr);
		}
	}

	public function buildSQLSamplesFromExprType_Scalar_String(Node\Scalar $expr)
	{
		yield $expr->value;
	}

	public function buildSQLSamplesFromExprType_Scalar_LNumber(Node\Scalar $expr)
	{
		yield $expr->value;
	}

	public function buildSQLSamplesFromExprType_Scalar_EncapsedStringPart(Node\Scalar $expr)
	{
		yield $expr->value;
	}

	public function buildSQLSamplesFromExprType_Scalar_Encapsed(Node\Scalar\Encapsed $expr)
	{
		$ret = [''];
		foreach ($expr->parts as $part) {
			foreach ($this->buildSQLSamplesFromExpr($part) as $sampleOfPart) {
				$newRet = [];
				foreach ($ret as $sampleOfRet) {
					$newRet []= $sampleOfRet . $sampleOfPart;
				}
				$ret = $newRet;
			}
		}
		foreach ($ret as $r) yield $r;
	}

	public function buildSQLSamplesFromExprType_Param(Node\Param $expr)
	{
		// TODO, impl
		yield 'PARAM::' . $expr->var->name;
	}

	public function buildSQLSamplesFromExprType_Expr_BinaryOp_Concat(Node\Expr\BinaryOp\Concat $expr)
	{
		$left = $this->buildSQLSamplesFromExpr($expr->left);
		$right = $this->buildSQLSamplesFromExpr($expr->right);
		foreach ($left as $l) {
			foreach ($right as $r) {
				yield $l . $r;
			}
		}
	}

	public function buildSQLSamplesFromExprType_Expr_ArrayDimFetch(Node\Expr\ArrayDimFetch $expr)
	{
		// FIXME
		// we dunno whether its a simple array referring
		//$arr = $this->getVariableExpr($expr->var);
		//$dim = $this->getVariableExpr($expr->dim);
		//if (is_array($arr) && !is_object($dim) && isset($arr[$dim])) {
		//	foreach ($this->buildSQLSamplesFromExpr($arr[$dim]) as $sqlSample) {
		//		yield $sqlSample;
		//	}
		//	return;
		//}

		foreach ($this->buildSQLSamplesFromExpr($expr->var) as $varSample) {
			foreach ($this->buildSQLSamplesFromExpr($expr->dim) as $dimSample) {
				yield sprintf('ARRAY_FETCH::%s[%s]', $varSample, $dimSample);
			}
		}

	}

	public function buildSQLSamplesFromExprType_Expr_MethodCall(Node\Expr\MethodCall $expr)
	{
		// TODO impl
		// sampling method caller object is not neccesary
		// except there is some special case, like buildQuery call ( but a lot TODO)
		//
		//if (strtolower($expr->name->name) == 'buildquery' && count($expr->args) == 1) {
		//	$querySettings = $expr->args[0]->value;
		//	if (!$querySettings instanceof Node\Expr\Variable) {
		//		$this->debug($querySettings, 'unknown value for query settings');
		//	}
		//	$querySettings = $this->getVar($this->getVarIdentifier($querySettings), $expr->getStartLine());
		//	$this->debug($querySettings);
		//}
		yield sprintf('METHOD_CALL::%s->%s()::', $expr->var->name, $expr->name->name);
	}

	public function buildSQLSamplesFromExprType_Expr_FuncCall(Node\Expr\FuncCall $expr)
	{
		/**
		 * current piece is a simple func call
		 * cant enumerate all simple functions, but a few ones, with callback member
		 * 
		 */
		$func = 'buildSQLSamplesFromFunction_' . $expr->name->parts[0];
		$ret = null;
		if (method_exists($this, $func)) {
			foreach ($this->$func($expr->args) as $sqlSample) {
				yield $sqlSample;
			}
		} else {
			yield 'FUNCTION_CALL::' . $expr->name->parts[0] . '::';
		}
	}

	public function buildSQLSamplesFromExprType_Expr_StaticCall(Node\Expr\StaticCall $expr)
	{
		//TODO args
		$className = implode('\\', $expr->class->parts);
		$methodName = $expr->name->name;
		yield sprintf('STATIC_CALL::%s::%s(...)::', $className, $methodName);
	}

	public function buildSQLSamplesFromExprType_Expr_ConstFetch(Node\Expr\ConstFetch $expr)
	{
		// TODO probe const value
		$const = strtolower(implode('', $expr->name->parts));
		if ($const == 'null') {
			yield '';
		}
		yield 'CONST::' . $const;
	}

	/**
		if ($expr instanceof Node\Param) {
			// if sql variable is a param of private method
			// we should check all calls inside the current class
			$method = $var->getAttribute('parent');
			$class = $method->getAttribute('parent');
			if ($method->flags & Node\Stmt\Class_::MODIFIER_PRIVATE) {

				$paramIndex = -1;
				for ($i = 0, $cnt = count($method->params); $i < $cnt; ++$i) {
					if ($method->params[$i] === $var) {
						$paramIndex = $i;
						break;
					}
				}
				if ($paramIndex < 0) {
					$this->debug($node, 'bad param index found: ' . $paramIndex);
				}
				foreach ((new MethodCallFinder($node->getAttribute('file'), $method))->getMethodCalls() as $call) {
					$sqlParam = $call->args[$paramIndex]->value;
					$this->checkSQL($call, $dbName, $sqlParam);
				}
				
				return;
			} else { //if ($method->flags & Node\Stmt\Class_::MODIFIER_PROTECTED || $method->flags & Node\Stmt\Class_::MODIFIER_PUBLIC) {
				Warning::addWarning($var->getAttribute('file'), $varName, $sql,
						sprintf('POTENTIAL INJECTION, sql is a public/protected method param: %s::%s', $class->name->name, $method->name));
				return;
			}
		}
		 */
		// if ($expr instanceof Node\Expr\BinaryOp) {
		// 	if ($expr instanceof Node\Expr\BinaryOp\Concat) {
		// 		// $a . $b
		// 	} elseif ($expr instanceof Node\Expr\BinaryOp\Div) {
		// 		// $a / $b, so this part got a number, just give a sample number
		// 		yield 1.23;
		// 	} elseif ($expr instanceof Node\Expr\BinaryOp\Mul) {
		// 		yield 2.34;
		// 	} elseif ($expr instanceof Node\Expr\BinaryOp\Minus) {
		// 		yield 3.45;
		// 	} else {
		// 		$this->debug($expr, 'unsupported binary op type');
		// 	}

		// } elseif ($expr instanceof \Node\Expr\Cast) {
		// 	// ignore all casts
		// 	foreach ($this->buildSQLSamplesFromExpr($expr->expr) as $sqlSample) {
		// 		yield $sqlSample;
		// 	}

		// 	/**
		// } elseif ($expr instanceof Node\Expr\ClassConstFetch) {
		// 	$className = implode($expr->class->parts);
		// 	if (strtolower($className) == 'self') {
		// 		$className = $this->currentClass->name->name;
		// 	}
		// 	do {
		// 		$constName = $className . '::' . $this->getVarIdentifier($expr->name);
		// 		$const = $this->getGlobalVar($constName, $className);
		// 	} while (null == $const && ($className = $this->getAncestorName($className)) != null);
		// 	if (null == $const) {
		// 		return sprintf('CONST::$%s::%s', $class, $expr->name->name);
		// 	} else {
		// 		return $this->buildSQLSampleFromVariable($const);
		// 	}



		// } elseif ($expr instanceof Node\Expr\Ternary) {
		// 	// TODO, also check else
		// 	return $this->buildSQLSampleFromVariable($expr->if);

		//  */

	protected function getTablesFromParsedSQL($parsed)
	{
		$ret = [];
		if (isset($parsed['SELECT']) && isset($parsed['FROM'])) {
			foreach ($parsed['FROM'] as $from) {
				if (isset($from['expr_type'])) {
					switch ($from['expr_type']) {
					case 'table':
						$ret []= $from['table'];
						break;
					case 'subquery':
						if (isset($from['sub_tree'])) {
							foreach ($this->getTablesFromParsedSQL($from['sub_tree']) as $t) {
								$ret []= $t;
							}
						} else {
							var_dump($from);die();
						}
						break;
					default:
						var_dump($from);die();
					}
				} else {
					var_dump($from);die();
				}
			}
		}
		return $ret;
	}

	public function buildSQLSamplesFromFunction_intval($args)
	{
		// just return a sample number instead
		yield 123;
	}

	public function buildSQLSamplesFromFunction_sprintf($args)
	{
		$format = $args[0]->value;
		foreach ($this->buildSQLSamplesFromExpr($format) as $formatSample) {
			$func = new \ReflectionFunction('sprintf');
			$invokeArgsSet = [[]];
			for ($i = 1, $cnt = count($args); $i < $cnt; ++$i) {
				$newInvokeArgsSet = [];
				foreach ($this->buildSQLSamplesForArg($invokeArgsSet, $args[$i]) as $builtArgs) {
					$newInvokeArgsSet []= $builtArgs;
				}
				$invokeArgsSet = $newInvokeArgsSet;
			}
			foreach ($invokeArgsSet as $invokeArgs) {
				array_unshift($invokeArgs, $formatSample);
				yield $func->invokeArgs($invokeArgs);	
			}
		}
	}

	public function buildSQLSamplesFromFunction_implode($args)
	{
		if (count($args) != 2) return;
		$delim = $this->buildSQLSamplesFromExpr($args[0]->value);
		$arr = $args[1]->value;
		if ($arr instanceof Node\Expr\FuncCall) {
			foreach ($this->buildSQLSamplesFromExpr($arr) as $arrSample) {
				if (is_array($arrSample)) {
					yield implode($delim, $arrSample);
				}
			}
		} elseif ($arr instanceof Node\Expr\Array_) {
			$sampledItemsSet = [[]];
			foreach ($arr->items as $item) {
				foreach ($this->buildSQLSamplesFromExpr($item->value) as $itemSample) {
					$newSampledItemsSet = [];
					foreach ($sampledItemsSet as $sampledItems) {
						$sampledItems []= $itemSample;
						$newSampledItemsSet []= $sampledItems;
					}
					$sampledItemsSet = $newInvokeArgsSet;
				}
			}
			foreach ($sampledItemsSet as $sampledItems) {
				yield implode($delim, $sampledItems);
			}
		}
	}

	public function buildSQLSamplesFromFunction_date($args)
	{
		if (count($args) != 2) return;
		foreach ($this->buildSQLSamplesFromExpr($args[0]->value) as $formatSample) {
			// who cares the exact value...
			yield date($formatSample, time());
		}
	}

	/**
	public function buildSQLSampleFromFunction_array_keys($args)
	{
		$arg = $args[0]->value;
		$var = $this->getVarReallyHard($arg);
		if ($var instanceof Node\Expr\Array_) {
			$ret = [];
			foreach ($var->items as $item) {
				$ret []= $this->buildSQLSampleFromVariable($item->key);
			}
			return $ret;
		}
		return null;
	}

	public function buildSQLSampleFromFunction_array_values($args)
	{
		$arg = $args[0]->value;
		$var = $this->getVarReallyHard($arg);
		if ($var instanceof Node\Expr\Array_) {
			$ret = [];
			foreach ($var->items as $item) {
				$ret []= $this->buildSQLSampleFromVariable($item->value);
			}
			return $ret;
		}
		return null;
	}
	 */

	public function buildSQLSamplesFromFunction_trim($args)
	{
		$str = $args[0]->value;
		if (count($args) == 2) {
			$options = $args[1]->value;
			foreach ($this->buildSQLSamplesFromExpr($str) as $strSample) {
				yield trim($strSample, $options);
			}
		} else {
			foreach ($this->buildSQLSamplesFromExpr($str) as $strSample) {
				yield trim($strSample);
			}
		}
	}

	protected function registerDBCalls($keyword, $dbName, $tableName, $sqlSample)
	{
		if (null === $this->dbCalls) {
			$this->dbCalls = [];
		}
		$this->dbCalls []= [$keyword, $dbName, $tableName, $sqlSample];
	}

	/**
	 * a generator function, yield all possible arg sets, expected to be called recursively for every arg
	 */
	protected function buildSQLSamplesForArg($argsSet, $arg)
	{
		foreach ($argsSet as $args) {
			foreach ($this->buildSQLSamplesFromExpr($arg->value) as $argSample) {
				$ret = $args;
				$ret []= $argSample;
				yield $ret;
			}
		}
	}

}

