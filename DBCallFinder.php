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
				// TODO, get db name from nested calls
				$dbName = 'unknown';
			}
			if (empty($node->args)) {
				// vacant sql command, often comes with select / from methods
				$dbCallFound = false;
				$parent = $node->getAttribute('parent');
				while ($parent) {
					if (!$parent instanceof Node\Stmt\MethodCall) break;
					if (strtolower($parent->name->name) == 'from' && count($parent->args) == 1) {
						$this->dbCalls []= [$dbName, 'fromCall', $this->getValueExpression($parent->args[0]->value)];
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
		if (!$sql instanceof Node\Expr\Variable) {
			Warning::addWarning($this->currentTarget, $dbName, $sql, 'unknown arg for yii createCommand');
			return;
		}

		$varName = $this->getVarIdentifier($sql);
		if (!isset($this->vars[$varName])) {
			Warning::addWarning($this->currentTarget, $varName, $sql, 'var not found for sql');
			return;
		}

		$var = $this->vars[$varName];
		if ($var instanceof Node\Param) {
			Warning::addWarning($this->currentTarget, $varName, $sql, 'sql is a method param, potential injection');
			return;
		}

		$valExpr = $this->buildSQLSampleFromVariable($var);
		try {
			$sqlParser = new PHPSQLParser($valExpr, true);
		} catch (\Exception $e) {
			$this->debug($node, 'invalid sql: ' . $valExpr, false, false);
			throw $e;
		}
		$tables = $this->getTablesFromParsedSQL($sqlParser->parsed);
		foreach ($tables as $table) {
			$this->dbCalls []= [$dbName, $varName, $table];
		}
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

}

