<?php declare(strict_types=1);
/** vim: set number noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

require 'vendor/autoload.php';

require 'VarStackVisitor.php';
require 'MethodCallFinder.php';
require 'ClassLocator.php';
require 'DBCall.php';
require_once 'Logger.php';

use PhpParser\{Node, NodeVisitorAbstract, NodeTraverser};
use PhpParser\{Parser, ParserFactory};
use PHPSQLParser\PHPSQLParser;

class DBCallFinder extends VarStackVisitor
{

	private $nodesToCheck = [];

	public function getDBCalls()
	{
		if ($this->nodesToCheck) {
			foreach ($this->nodesToCheck as $filename => $nodes) {
				Logger::info('checking ' . count($nodes) . ' marked nodes, from ' . $filename);
				foreach ($nodes as list($node, $type)) {
					$checkFunc = 'checkNodeWith' . $type;
					Logger::info("checking node with $checkFunc, source: " . $node->getAttribute('file') . ", line: " . $node->getStartLine());
					$this->$checkFunc($node);
				}
				// try to reduce memory usage
				$sourceFile = SourceFile::getSourceFile($filename);
				if ($sourceFile) $sourceFile->clearCache();
				$this->clearCache($filename);
			}
			$this->nodesToCheck = [];
		}
		return DBCall::$dbCalls;
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
			$this->markNodeToCheck($node, 'Command');
		}
		if ($node instanceof Node\Expr\MethodCall
			&& $node->var instanceof Node\Expr\StaticCall
			&& strtolower($node->var->name->name) == 'model'
			) {

			switch (strtolower($node->name->name)) {
			case 'findall':
			case 'find':
				$this->markNodeToCheck($node, 'ModelFind');
				break;
			case 'findbypk':
				$this->markNodeToCheck($node, 'ModelPK');
				break;
			}
		}
	}

	protected function markNodeToCheck(Node $node, string $checkType)
	{
		$file = $node->getAttribute('file');
		if (!isset($this->nodesToCheck[$file])) $this->nodesToCheck[$file] = [];
		$this->nodesToCheck[$file] []= [$node, $checkType];
	}

	protected function checkNodeWithCommand(Node $node)
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
							DBCall::registerDBCallsFromNode($node, [
								'db' => $currentDBName,
								'table' => $tableName,
								]);
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

	protected function checkNodeWithModelFind(Node $node)
	{
		$this->resetTrace();
		$this->trace(sprintf('%s:(%s)  %s: %s', __METHOD__, __LINE__, $this->getNodeClosure($node), $node->getStartLine()));
		if ($node->var->class instanceof Node\Expr) {
			// FIXME, /data/jianghao/git/tony/sample/mis-rtb/protected/models/task/AddTaskModel.php
			$classNameVar = $this->getVariableExpr($node->var->class);
			if ($classNameVar instanceof Node\Scalar\String_) {
				$className = $classNameVar->value;
			}
		} elseif ($node->var->class instanceof Node\Name) {
			$className = implode('\\', $node->var->class->parts);
		}
		if (empty($className)) {
			$this->debug($node->var->class, 'unsupported class type');
		}
		$classInfo = ClassLocator::locateClass($className, $this->root);
		if (null == $classInfo) return;

		// TODO, check if is a record class
		if (empty($node->args)) {
			foreach ($classInfo->getYiiDBNames() as $dbName) {
				foreach ($classInfo->getYiiTableNames() as $tableName) {
					// find without args is safe, no sql checking required
					// just register db call
					// TODO, get the real id
					$sqlSample = 'SELECT * FROM ' . $tableName . ' WHERE __pk = __1';
					DBCall::registerDBCallsFromNode($node, [
						'db' => $dbName,
						'table' => $tableName,
						'sql' => $sqlSample,
						]);
				}
			}
		} else {
			$sqlVar = $this->getVariableExpr($node->args[0]->value);
			if ($sqlVar instanceof Node\Expr\Array_) {
				// find with criteria array
				foreach ($classInfo->getYiiDBNames() as $dbName) {
					foreach ($classInfo->getYiiTableNames() as $tableName) {
						$this->checkSQLCriteria($sqlVar, $dbName, $tableName);
					}
				}
			} else { // TODO, find with criteria object is not implemented
				foreach ($classInfo->getYiiDBNames() as $dbName) {
					foreach ($classInfo->getYiiTableNames() as $tableName) {
						$this->checkSQL($node, $dbName, $node->args[0]->value, 'SELECT * FROM ' . $tableName . ' ');
					}
				}
			}
		}
	}

	protected function checkNodeWithModelPK(Node $node)
	{
		$this->resetTrace();
		$this->trace(sprintf('%s:(%s)  %s: %s', __METHOD__, __LINE__, $this->getNodeClosure($node), $node->getStartLine()));
		$className = implode('\\', $node->var->class->parts);
		$classInfo = ClassLocator::locateClass($className, $this->root);
		if (null == $classInfo) return;

		// TODO, check if is a record class
		foreach ($classInfo->getYiiDBNames() as $dbName) {
			foreach ($classInfo->getYiiTableNames() as $tableName) {
				// query by primary key is safe, no sql checking required
				// just register db call
				// TODO, get the real id
				$sqlSample = 'SELECT * FROM ' . $tableName . ' WHERE __pk = __1';
				DBCall::registerDBCallsFromNode($node, [
					'db' => $dbName,
					'table' => $tableName,
					'sql' => $sqlSample,
					]);
			}
		}
	}

	protected function checkSQLCriteria(Node $criteria, string $dbName, string $tableName = null)
	{
		$this->trace(sprintf('%s:(%s)  db:%s, criteria:%s, line:%s', __METHOD__, __LINE__,
				$dbName, $this->getVarIdentifier($criteria), $criteria->getStartLine()));
		if ($criteria instanceof Node\Expr\Array_) {
			// TODO, currently just select implemented
			$select = '*';
			$where = null;
			$params = [];
			foreach ($criteria->items as $item) {
				if (!$item->key instanceof Node\Scalar\String_) {
					$this->debug($item->key, 'unknown criteria array key type: ' . $item->key->getType());
					continue;
				}
				// for all samples, just get the first to build and check
				switch (strtolower($item->key->value)) {
				case 'select':
					foreach ($this->buildSQLSamplesFromExpr($item->value) as $selectSample) {
						$select = $selectSample;
						break;
					}
					break;
				case 'condition':
					foreach ($this->buildSQLSamplesFromExpr($item->value) as $whereSample) {
						$where = $whereSample;
						break;
					}
					break;
				case 'params':
					// ignore the exact value, replace with sample, because params binding is safe
					if (!$item->value instanceof Node\Expr\Array_) {
						$this->debug($item->value, 'unknown item value found');
						break;
					}
					foreach ($item->value->items as $param) {
						if (!$param->key instanceof Node\Scalar\String_) {
							$this->debug($param->key, 'unknown criteria array key type: ' . $param->key->getType());
							continue;
						}
						$params[$param->key->value] = str_replace(':', '', $param->key->value);
					}
					break;
				case 'order': // ignore, not important
				case 'limit': // ignore, not important
					break;
				default: 
					$this->debug($item->key, 'unsupported criteria array key: ' . $item->key->value);
				}
			}
			$sqlSample = sprintf('SELECT %s FROM %s', $select, $tableName);
			if ($where) {
				$where = str_replace(array_keys($params), array_values($params), $where);
				$sqlSample .= ' WHERE ' . $where;
			}
			foreach ($this->checkSQLSample($criteria, $dbName, $sqlSample) as $checkResult) {
				list($sqlSample, $errorMessage) = $checkResult;
				Warning::addWarning($node->getAttribute('file'), $sqlSample, $sqlExpr, $errorMessage);
			}

		} else {
			$this->debug($criteria, 'unknown criteria type: ' . $criteria->getType());
		}
	}

	/**
	 * generator function
	 * yields [sql, errorMessage] as warning
	 */
	protected function checkSQLSample(Node $node, string $dbName, string $sqlSample)
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
			DBCall::registerDBCallsFromNode($node, [
				'db' => $dbName,
				'table' => $table,
				'sql' => $sqlSample,
				]);
		}
	}

	protected function checkSQL(Node $node, string $dbName, Node\Expr $sqlExpr, string $prefix = null)
	{
		$this->trace(sprintf('%s:(%s)  db:%s, sqlvar:%s, line:%s, prefix:%s', __METHOD__, __LINE__,
				$dbName, $this->getVarIdentifier($sqlExpr), $sqlExpr->getStartLine(), $prefix));
		$sqlSamples = $this->buildSQLSamplesFromExpr($sqlExpr);
		foreach ($sqlSamples as $sqlSample) {

			if ($prefix) $sqlSample = $prefix . $sqlSample;
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
		yield 'METHOD_PARAM::' . $expr->var->name;
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

	public function buildSQLSamplesFromExprType_Expr_ClassConstFetch(Node\Expr\ClassConstFetch $expr)
	{
		// TODO probe const value
		$sqlExpr = $this->getVariableExpr($expr);
		if (null == $sqlExpr) {
			yield 'CLASS_CONST::' . $this->getVarIdentifier($expr);
			return;
		}
		foreach ($this->buildSQLSamplesFromExpr($sqlExpr) as $sqlSample) {
			yield $sqlSample;
		}
	}

	public function buildSQLSamplesFromExprType_Expr_BinaryOp_Mul(Node\Expr\BinaryOp $expr)
	{
		// just return a sample
		return 2.34;
	}

	public function buildSQLSamplesFromExprType_Expr_BinaryOp_Div(Node\Expr\BinaryOp $expr)
	{
		// just return a sample
		return 3.45;
	}

	public function buildSQLSamplesFromExprType_Expr_BinaryOp_Minus(Node\Expr\BinaryOp $expr)
	{
		// just return a sample
		return 4.56;
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

	public function buildSQLSamplesFromExprType_Expr_New(Node\Expr\New_ $expr)
	{
		// TODO
		// cdbcriteria here, could be explored deep
		// debug this:
		// ./db_reference_finder -t  sample/mis-rtb/protected/models//DspBaseModel.php -r sample/mis-rtb/
		yield 'CLASS::' . implode('\\', $expr->class->parts);
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

		// } elseif ($expr instanceof \Node\Expr\Cast) {
		// 	// ignore all casts
		// 	foreach ($this->buildSQLSamplesFromExpr($expr->expr) as $sqlSample) {
		// 		yield $sqlSample;
		// 	}

		// 	/**

		//} elseif ($expr instanceof Node\Expr\Ternary) {
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

