<?php declare(strict_types=1);
/** vim: set noet ts=4 sw=4 fdm=indent: */

namespace TonyParser;

require 'vendor/autoload.php';

require 'VarStackVisitor.php';

use PhpParser\{Node, NodeVisitorAbstract, NodeTraverser};
use PhpParser\{Parser, ParserFactory};
use PHPSQLParser\PHPSQLParser;

class DBCallFinder extends VarStackVisitor
{

	protected $dbCalls;

	public function getDBCalls()
	{
		return $this->dbCalls;
	}

	public function beforeTraverse(array $nodes)
	{
		parent::beforeTraverse($nodes);
		$this->dbCalls = [];
	}

	public function enterNode(Node $node)
	{
		parent::enterNode($node);
		if ($node instanceof Node\Expr\MethodCall
			&& strtolower($node->name->name) == 'createcommand') {
			if ($node->var->name instanceof object) {
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
				$dbCallFound = false;
				$parent = $node->getAttribute('parent');
				while ($parent) {
					if (!$parent instanceof Node\Stmt\MethodCall) break;
					if (strtolower($parent->name->name) == 'from' && count($parent->args) == 1) {
						$this->registerDBCalls('fromCall', $dbName, 'unknown', $this->buildSQLSampleFromVariable($parent->args[0]->value));
						$dbCallFound = true;
						break;
					}
					$parent = $parent->getAttribute('parent');
				}
				if (!$dbCallFound) {
					Warning::addWarning($this->currentTarget, $dbName, $node, 'createCommand but no call found');
				}
			} else {
				$sql = $node->args[0]->value;
				$this->checkSQL($node, $dbName, $sql);
			}
		}
	}

	protected function checkSQL($node, $dbName, $sql)
	{
		if ($sql instanceof Node\Expr\BinaryOp\Concat) {
			// ->createCommand( $a . $b )
			// pretend there is a var concat things up, tricky, FIXME
			$var = new Node\Expr\Variable('tmpsql');
			$var->setAttribute('startLine', $sql->getStartLine() - 2);
			$var->setAttribute('endLine', $sql->getStartLine() - 2);
			$var->expr = $sql;
			$this->registerVar($var, $sql);
			return $this->checkSQL($node, $dbName, $var);
		}

		if (!$sql instanceof Node\Expr\Variable) {
			Warning::addWarning($this->currentTarget, $dbName, $sql, 'unknown arg for yii createCommand');
			return;
		}

		$varName = $this->getVarIdentifier($sql);
		$var = $this->getVar($varName, $node->getStartLine());
		if (null == $var) {
			Warning::addWarning($this->currentTarget, $varName, $sql, 'var not found for sql: ' . $varName);
			return;
		}

		if ($var instanceof Node\Param) {
			Warning::addWarning($this->currentTarget, $varName, $sql, 'sql is a method param, potential injection');
			return;
		}

		$sqlSample = $this->buildSQLSampleFromVariable($var);
		if (strpos($sqlSample, 'METHOD_CALL::') !== false) {
			Warning::addWarning($this->currentTarget, $sqlSample, $var, 'potential injection. sql contains method call return value as string');
			return;
		}
		if (strpos($sqlSample, 'METHOD_PARAM::') !== false) {
			Warning::addWarning($this->currentTarget, $sqlSample, $var, 'potential injection. sql using method param as string');
			return;
		}
		if (strpos($sqlSample, 'OBJECT_PROPERTY::') !== false) {
			Warning::addWarning($this->currentTarget, $sqlSample, $var, 'potential injection. sql using object property as string');
			return;
		}
		if (strpos($sqlSample, 'FUNCTION_CALL::') !== false) {
			Warning::addWarning($this->currentTarget, $sqlSample, $var, 'potential injection. sql using function call as string');
			return;
		}
		if (strpos($sqlSample, 'ARRAY_FETCH::') !== false) {
			Warning::addWarning($this->currentTarget, $sqlSample, $var, 'potential injection. sql contains array dim fetch value as string');
			return;
		}
		if (strpos($sqlSample, 'ARRAY::') !== false) {
			Warning::addWarning($this->currentTarget, $sqlSample, $var, 'potential injection. sql contains array dim fetch value as string');
			return;
		}
		if (strpos($sqlSample, 'CONST::') !== false) {
			Warning::addWarning($this->currentTarget, $sqlSample, $var, 'potential injection. sql contains const value as string');
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
			$this->registerDBCalls($varName, $dbName, $table, $sqlSample);
		}
	}

	protected function buildSQLSampleFromVariable($expr)
	{
		if ($expr instanceof Node\Expr\BinaryOp) {
			if ($expr instanceof Node\Expr\BinaryOp\Concat) {
				// $a . $b
				return $this->buildSQLSampleFromVariable($expr->left) . $this->buildSQLSampleFromVariable($expr->right);
			} elseif ($expr instanceof Node\Expr\BinaryOp\Div) {
				// $a / $b, so this part got a number, just give a sample number
				return 1.23;
			} elseif ($expr instanceof Node\Expr\BinaryOp\Mul) {
				return 2.34;
			} elseif ($expr instanceof Node\Expr\BinaryOp\Minus) {
				return 3.45;
			} else {
				$this->debug($expr, 'unsupported binary op type');
			}

		} elseif ($expr instanceof \Node\Expr\Cast) {
			// ignore all casts
			return $this->buildSQLSampleFromVariable($expr->expr);

		} elseif ($expr instanceof Node\Scalar) {
			/**
			 * a scalar is ether a encapsed string or scalar value
			 * for encapsed string, just concat samples of every part
			 * for scalar, just return its value
			 */
			if ($expr instanceof Node\Scalar\Encapsed) {
				$ret = '';
				foreach ($expr->parts as $part) {
					$ret .= $this->buildSQLSampleFromVariable($part);
				}
				return $ret;
			} else {
				return $expr->value;
			}

		} elseif ($expr instanceof Node\Expr\FuncCall && count($expr->name->parts) == 1) {
			/**
			 * current piece is a simple func call
			 * cant enumerate all simple functions, but a few ones, with callback member
			 * 
			 */
			$func = 'buildSQLSampleFromFunction_' . $expr->name->parts[0];
			if (method_exists($this, $func)) {
				return $this->$func($expr->args);
			} else {
				return 'FUNCTION_CALL::' . $expr->name->parts[0] . '::';
			}

		} elseif ($expr instanceof Node\Expr\StaticCall) {
			$className = implode('\\', $expr->class->parts);
			$methodName = $expr->name->name;
			return sprintf('STATIC_CALL::%s::%s(...)::', $className, $methodName);

		} elseif ($expr instanceof Node\Expr\ArrayDimFetch) {
			$varName = $this->buildSQLSampleFromVariable($expr->var);
			$dimName = $this->buildSQLSampleFromVariable($expr->dim);
			return sprintf('ARRAY_FETCH::%s[%s]', $varName, $dimName);

		} elseif ($expr instanceof Node\Expr\Array_) {
			$items = [];
			foreach ($expr->items as $item) {
				$items []= $this->buildSQLSampleFromVariable($item->value);
			}
			return sprintf('ARRAY::[%s]', implode(',', $items));

		} elseif ($expr instanceof Node\Expr\PropertyFetch
					|| $expr instanceof Node\Expr\Variable) {

			$varName = $this->getVarIdentifier($expr);

			if ($varName == '$this') {
				// TODO, check for method call validity?
				return '$this';

			} elseif (($referredVar = $this->getVar($varName, $expr->getStartLine())) !== null) {
				if ($referredVar instanceof Node\Param) {
					return 'METHOD_PARAM::$' . $varName . '::';
				} else {
					$tag = $referredVar->getAttribute('tag');
					$ret = $this->buildSQLSampleFromVariable($referredVar);
					if ($tag) $ret = $ret . '_' . $tag;
					return $ret;
				}

			} else {
				if ($expr instanceof Node\Expr\PropertyFetch && is_string($expr->var->name) && strtolower($expr->var->name) == 'this') {
					return 'OBJECT_PROPERTY::' . $varName . '::';
				} else {
					$this->debug($expr, 'unknown var name, may be dangerous or not properly extracted from locals/objects: ' . $varName);
				}

			}

		} elseif ($expr instanceof Node\Expr\ClassConstFetch) {
			$className = implode($expr->class->parts);
			if (strtolower($className) == 'self') {
				$className = $this->currentClass->name->name;
			}
			do {
				$constName = $className . '::' . $this->getVarIdentifier($expr->name);
				$const = $this->getGlobalVar($constName, $className);
			} while (null == $const && ($className = $this->getAncestorName($className)) != null);
			if (null == $const) {
				return sprintf('CONST::$%s::%s', $class, $expr->name->name);
			} else {
				return $this->buildSQLSampleFromVariable($const);
			}

		} elseif ($expr instanceof Node\Expr\ConstFetch) {
			// TODO probe const value
			return 'CONST::' . implode('.', $expr->name->parts);

		} elseif ($expr instanceof Node\Expr\MethodCall) {
			// return sprintf('METHOD_CALL::%s->%s()::', $this->buildSQLSampleFromVariable($expr->var), $expr->name->name);
			/**
			 * sampling method caller object is not neccesary
			 * except there is some special case, like buildQuery call ( but a lot TODO)
			 */
			/**
			if (strtolower($expr->name->name) == 'buildquery' && count($expr->args) == 1) {
				$querySettings = $expr->args[0]->value;
				if (!$querySettings instanceof Node\Expr\Variable) {
					$this->debug($querySettings, 'unknown value for query settings');
				}
				$querySettings = $this->getVar($this->getVarIdentifier($querySettings), $expr->getStartLine());
				$this->debug($querySettings);
			}
			*/
			return sprintf('METHOD_CALL::%s->%s()::', $expr->var->name, $expr->name->name);

		}
		$this->debug($expr, 'unknown value expr type');
	}

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

	public function buildSQLSampleFromFunction_intval($args)
	{
		// just return a sample number instead
		return 123;
	}

	public function buildSQLSampleFromFunction_sprintf($args)
	{
		$format = $args[0]->value;
		$format = $this->getSQLPrintfFormat($format);
		$f = new \ReflectionFunction('sprintf');
		$fargs = [$format];
		for ($i = 1, $cnt = count($args); $i < $cnt; ++$i) {
			$fargs []= $this->buildSQLSampleFromVariable($args[$i]->value);
		}
		$ret = $f->invokeArgs($fargs);
		return $ret;
	}

	protected function getSQLPrintfFormat($format)
	{
		if ($format instanceof Node\Scalar\String_) {
			return $format->value;
		} elseif ($format instanceof Node\Expr\BinaryOp\Concat) {
			return $this->getSQLPrintfFormat($format->left) . $this->getSQLPrintfFormat($format->right);
		} else {
			return $this->buildSQLSampleFromVariable($format);
		}
	}

	protected function registerDBCalls($keyword, $dbName, $tableName, $sqlSample)
	{
		$this->dbCalls []= [$keyword, $dbName, $tableName, $sqlSample];
	}

}

